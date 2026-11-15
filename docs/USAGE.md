# Usage

> **S1 (core inbox, 0.1.0).** `notify()` writes the in-app rows; the bell, the inbox page and the read side
> (`InboxInterface` unread count, pages, mark read) arrive with S2, preferences with S3, mail / push / Slack with S4.
> Each section says from which slice it works (contract 221-B §3–§4).

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [What notify() does](#what-notify-does)
- [Access: rows with a context](#access-rows-with-a-context)
- [Transactions, failures and events](#transactions-failures-and-events)
- [Retention and schema check](#retention-and-schema-check)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

1. Declare your types and categories (S1):

   ```yaml
   nowo_notify_kit:
       enabled: true
       recipient: { class: App\Entity\User }       # the default resolver reads the entity's id field
       categories:
           account: { label: 'notify.category.account' }
       types:
           invitation_accepted: { category: account, icon: 'tabler:mail-check' }
   ```

   Types can also come from a service implementing `Nowo\NotifyKitBundle\Contract\TypeProviderInterface`
   (autoconfigured, tag `nowo_notify_kit.type_provider`). A key declared twice is refused.

2. Create the tables (see [INSTALLATION.md](INSTALLATION.md#database)).

3. From a listener that runs **after your own commit** (or inside your transaction, see below), call the notifier:

   ```php
   use Nowo\NotifyKitBundle\Contract\NotificationRequest;
   use Nowo\NotifyKitBundle\Contract\NotifierInterface;
   use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;

   $notifier->notify(new NotificationRequest(
       type: 'invitation_accepted',
       recipients: [$recipients->recipientOf($inviter)],
       actor: $recipients->recipientOf($invitee),
       context: null,                                  // personal
       target: 'invitation:' . $invitation->getId(),   // groups repeats
       url: '/team',                                   // a local path only
       params: ['team' => $team->getName()],           // rendered into the title at display time (S2)
   ));
   ```

4. Turn the pages on (S2): `web_ui.enabled: true` gives the inbox page, the bell fragment and the count endpoint.

## What notify() does

| Step | Rule |
| --- | --- |
| Validation | `NotificationRequest` refuses, where it is built: a type key that is not `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` (≤ 64), a target that is empty, > 128 characters or holds control characters, a `url` that is not a local path (must start with `/`, not `//`, no backslash or control characters, ≤ 500 bytes), more than 20 params, a param that is not a string. Values longer than 200 characters are cut. `notify()` refuses more than `fan_out.max_recipients` recipients (1,000). |
| Types | An unknown (open-set) key gets the generic definition: category `general`, title key `notify.type.generic.title`, in-app only, permission `access.default_permission`. |
| Recipients | Duplicates are merged; the actor is never notified; a type whose bell row is off (`in_app_optional` with `channels.in_app: false`) writes nothing. |
| Coalescing | A repeat of the same recipient, type and target within `inbox.repeat_window_seconds` (600) updates the latest **unread** row (actor, context, url, params, `repeats + 1`, moved to the top) instead of adding one. A read row is never revived. Types with `coalesce: false` always add a row. |
| Dedupe | `dedupeKey` drops a later request with the same key for 24 hours (once-per-state notices). Only the `sha256` of the key is stored. |
| Result | `NotifyResult`: `written`, `coalesced`, `skippedActor`, `skippedAccess`, `deduplicated`, `notificationIds`. |

Never put translated text or a third person's personal data in `params`: the actor is a reference resolved at display
time, so erasing the actor removes the name everywhere.

## Access: rows with a context

Deny by default (contract §6.1, Q-O6). A request **without** a context writes a personal row. A request **with** a
context (`new ContextRef('project', '42')`) writes a row for a recipient only when:

- the type has a permission (`types.<key>.permission`, or `access.default_permission` for unknown types), and
- the access checker (`access.checker`, your `RecipientAccessCheckerInterface` service) says the recipient is
  unrestricted, or returns that context from `contextsGranting($recipient, $permission, [$context])`.

Without `access.checker` the default grants nothing: only personal rows are written, and the container refuses a type
or a `default_permission` that names a permission. Refused recipients are skipped (`skippedAccess`), logged at info
level and announced with `NotificationSkippedEvent` (reason `access`).

When a project or board is deleted, call `InboxInterface::forgetContext(new ContextRef('project', $id))`: there is no
foreign key (Q-B6). From S2 the inbox also hides rows whose context the recipient can no longer open.

## Transactions, failures and events

- The bundle writes through **DBAL only**, on the connection of `doctrine.entity_manager`, through a
  `nowo-tech/transaction-contract` runner: it **never flushes, clears or resets an EntityManager**.
- **Outside a transaction** each `notify()` is one short transaction (recipients in key order).
- **Inside your transaction on the same connection** the rows join it: they are committed with it and disappear with a
  rollback. The events wait for your outermost commit and are dropped on rollback (a notification never announces
  something that was rolled back). Rows written inside your transaction keep their row locks until your commit.
- A deadlock, lock wait timeout or serialization failure surfaces as `Nowo\TransactionContract\TransientStorageFailure`
  with nothing written: answer **503 with `Retry-After`** (`$e->retryAfterSeconds()`), or retry. To retry inside the
  bundle, set `doctrine.transaction_runner` to a retrying adapter that works on the bundle connection and flushes no
  EntityManager (e.g. the connection-level adapter of `nowo-tech/doctrine-deadlock-retry-bundle` 3.x).
- Concurrency: coalescing never takes a gap lock (it reads the candidate row, then updates it by primary key with
  `repeats = repeats + 1`), so two concurrent repeats both count and two concurrent first notifications insert two rows
  at worst, never deadlock. A dedupe key is claimed with `INSERT IGNORE` / `ON CONFLICT DO NOTHING`.
- Events (after commit, references only, never params, URLs or text):

  | Event | Payload |
  | --- | --- |
  | `Contract\Event\NotificationRecordedEvent` | `notificationId`, `recipient`, `type`, `context`, `coalesced` |
  | `Contract\Event\NotificationSkippedEvent` | `type`, `recipient`, `reason` (`actor`, `access`, `preference`, `dedupe`; `inactive` from S2) |

## Retention and schema check

- `php bin/console nowo:notify-kit:prune` deletes read rows created more than `retention.read_days` (90) ago (unread
  rows are never deleted by age), trims every recipient to its newest `retention.keep_per_recipient` (500) rows and
  forgets expired dedupe keys, in batches of `retention.batch_size`, each batch its own transaction.
- `--async` only dispatches a `Contract\Message\PruneNotificationsMessage` (route it to your maintenance transport); the
  handler stops after `messenger.max_seconds` and dispatches the remainder with a keyset cursor. Schedule one of them
  daily (the bundle's scheduler recipe arrives with S4).
- `php bin/console nowo:notify-kit:schema-check` compares the live tables with the mapping (columns, binary collations,
  indexes, prefix): exit 0 when they match, 1 with the SQL that would align them.

## Using it from a DDD / hexagonal application

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your application layer depends only on `Nowo\NotifyKitBundle\Contract\*`: `NotifierInterface`, `InboxInterface` and `final readonly` value objects (`RecipientRef`, `ContextRef`, `NotificationRequest`, `NotifyResult`, …). No Doctrine, Symfony or HTTP type appears in them. |
| Recipients | Pass `RecipientRef` (type + id) values; implement `RecipientResolverInterface` (`recipient.resolver`) if you want the bundle to map your user objects. Rows never hold a Doctrine relation. From S2, `RecipientDirectoryInterface` (name, locale, active). |
| Access | Implement `RecipientAccessCheckerInterface` (`isUnrestricted()`, `contextsGranting()`), set `access.checker`; without it only personal rows exist. |
| Mapping | Set `doctrine.auto_mapping: false` and register `vendor/nowo-tech/notify-kit-bundle/src/Resources/config/doctrine` (type `xml`, prefix `Nowo\NotifyKitBundle\Domain`) on your own entity manager; set `doctrine.entity_manager` to it. |
| Transactions | `notify()` inside your transaction on the same connection joins it; events wait for your outermost commit. The bundle never flushes your EntityManager. |
| Deletion | No FK to your tables: call `InboxInterface::forgetContext()` when a project or board is deleted. |

Both host kinds run the same functional suite (`tests/Integration/Database/StandardHostTest.php`, `DddHostTest.php`).

## FrankenPHP worker mode

Every service is stateless except the after-commit queue, which holds the events of a transaction that joined a host
transaction and is cleared at every request, command and message boundary (and on `kernel.reset`). See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
