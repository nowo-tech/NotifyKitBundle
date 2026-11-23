# Usage

> **S1 (core inbox, 0.1.0) + S2 (access + UI).** `notify()` writes the in-app rows; S2 adds the read side
> (`InboxInterface` unread count, pages, mark read / unread / all, with the visibility re-check), the header bell, the
> inbox and operator pages and the realtime badge. Preferences come with S3, mail / push / Slack with S4. Each section
> says from which slice it works (contract 221-B §3–§4).

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [What notify() does](#what-notify-does)
- [Access: rows with a context](#access-rows-with-a-context)
- [Transactions, failures and events](#transactions-failures-and-events)
- [Inbox, bell and pages (S2)](#inbox-bell-and-pages-s2)
- [Reading the inbox from code (S2)](#reading-the-inbox-from-code-s2)
- [Realtime badge (S2)](#realtime-badge-s2)
  - [The Mercure subscriber cookie](#the-mercure-subscriber-cookie)
  - [What publishing costs](#what-publishing-costs)
- [Retention and schema check](#retention-and-schema-check)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [FrankenPHP worker mode](#frankenphp-worker-mode)
- [Translations and overrides](#translations-and-overrides)

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

4. Turn the pages on (S2): see [Inbox, bell and pages](#inbox-bell-and-pages-s2).

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
  rollback. The events wait: they are dropped on your rollback, or on a rollback to a savepoint that contains the call,
  so a notification never announces something that was rolled back. After your outermost commit they run at the next
  safe point: the bundle's next unit of work on a connection at level 0, or the request / command / message boundary
  **at the latest** (never inside the driver commit, so listeners may open transactions, flush or call `notify()`).
  Rows written inside your transaction keep their row locks until your commit.
- A deadlock, lock wait timeout or serialization failure surfaces as `Nowo\TransactionContract\TransientStorageFailure`
  with nothing written: answer **409 with `Retry-After` and code `CONFLICT_001`** (`$e->retryAfterSeconds()`; 503 stays a
  host option), or retry. With nowo-tech/transaction-doctrine's Symfony bundle, `conflict_response.map_transient_failures: true`
  does that for you ([CONCURRENCY.md](CONCURRENCY.md)). To retry inside the
  bundle, set `doctrine.transaction_runner` to a retrying adapter that works on the bundle connection and flushes no
  EntityManager (e.g. the connection-level adapter of `nowo-tech/doctrine-deadlock-retry-bundle` 3.x).
- Concurrency: coalescing never takes a gap lock (it reads the candidate row, then updates it by primary key with
  `repeats = repeats + 1`), so two concurrent repeats both count and two concurrent first notifications insert two rows
  at worst, never deadlock. A dedupe key is claimed in one upsert that only takes over an expired key, so no gap lock
  either.
- Identifier equality is exact before any `LIMIT`, `OFFSET` or `GROUP BY`: NO PAD binary collations on MySQL / MariaDB
  plus a byte comparison in every query by recipient or context; `RecipientRef` / `ContextRef` refuse ids with edge white
  space or invisible characters (never trimmed).
- Events (after commit, references only, never params, URLs or text):

  | Event | Payload |
  | --- | --- |
  | `Contract\Event\NotificationRecordedEvent` | `notificationId`, `recipient`, `type`, `context`, `coalesced` |
  | `Contract\Event\NotificationSkippedEvent` | `type`, `recipient`, `reason` (`actor`, `access`, `preference`, `dedupe`; `inactive` from S2) |

## Inbox, bell and pages (S2)

```yaml
# config/packages/nowo_notify_kit.yaml
nowo_notify_kit:
    enabled: true
    recipient: { class: App\Entity\User }        # or resolver: your RecipientResolverInterface service
    web_ui:
        enabled: true                              # /notifications, /notifications/menu, /count, POST read / unread / read-all
        # path_prefix: /notifications
        # security: { access_roles: [ROLE_USER], access_checker: null }
    admin_ui:
        enabled: true                              # /admin/notifications: counts only, "Run retention now"
        # security: { access_roles: [ROLE_ADMIN], access_checker: null, allow_unauthenticated: false }

# config/routes/nowo_notify_kit.yaml
nowo_notify_kit:
    resource: '@NowoNotifyKitBundle/Resources/config/routes.php'

# config/packages/security.yaml (layer 1 of REQ-UI-002; the bundle's access checkers are layer 2)
security:
    access_control:
        - { path: ^/admin/notifications, roles: ROLE_ADMIN }
        - { path: ^/notifications, roles: ROLE_USER }
```

Then render the bell in your header (it renders nothing for guests or people without inbox access) and run
`php bin/console assets:install`:

```twig
{# UiKit shell: the header actions slot #}
{% block nowo_ui_shell_header_actions %}{{ notify_kit_bell() }}{{ parent() }}{% endblock %}
```

- **Who sees what.** Every endpoint resolves the logged-in person (`recipient.resolver` / `recipient.class`) and
  works on that person's rows only. An id of someone else's row, or of a row they may no longer see, answers 404.
  Anonymous requests get your firewall's login answer (401 / redirect); a person without one of
  `web_ui.security.access_roles` (or refused by your `InboxAccessCheckerInterface`) gets 403.
- **CSRF.** The bell's `fetch` calls send the token `nowo_notify_kit_inbox` in `X-CSRF-TOKEN` and get JSON
  (`{ok, id, unread, unreadCount}`, `{ok, marked, unreadCount}`); the inbox page uses FormKit forms (`_token`,
  `_redirect`) and redirects to `_redirect` only when it is a local path (checked before and after URL-decoding).
  Without a valid token: 403.
- **Caching.** The inbox page, menu, count and every JSON answer send `Cache-Control: no-store, private`; the count has
  an `ETag` and answers `304` when it did not change.
- **Lock conflicts** answer **409** + `Retry-After` + `CONFLICT_001` (JSON for `fetch`, your error page for forms).
  Every row operation is idempotent: retrying is safe.
- **Without JavaScript** the bell is a `<details>` disclosure whose panel links to the inbox page; the inbox page
  works with plain forms.
- **Titles** are built at display time in the viewer's language: the type's `title_key` in its `title_domain`
  (default `messages`) with each param as `%<key>%`, then `%actor%` (the actor's name from
  `RecipientDirectoryInterface`; "Someone" when unknown or erased), `%target%` and `%repeats%`, which a param cannot
  override. Unknown types get the generic title. Context labels come from your `ContextProviderInterface`
  (`preferences.contexts.provider`).
- Templates and overrides: [TEMPLATES.md](TEMPLATES.md).

## Reading the inbox from code (S2)

```php
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\InboxStatus;

$unread = $inbox->unreadCount($me);
$page   = $inbox->page($me, new InboxFilter(status: InboxStatus::Unread), $after, 25); // $page->items, $page->next
$inbox->markRead($me, $page->items[0]->id);   // false = not my row, or hidden from me (answer 404)
$inbox->markAllRead($me);                     // visible rows only
```

Visibility is re-checked on every call: personal rows always; a row with a context only while your
`RecipientAccessCheckerInterface` still grants the type's permission there (asked once per permission with every
context of the person's rows). A lost permission hides the rows and lowers the count at once, before you call
`forgetContext()`. `NotificationReadEvent` (ids only) is dispatched after the update commits, and only when something
changed (`all: true` for *mark all*, `read: false` for *mark unread*).

## Realtime badge (S2)

| `realtime.mode` | What happens |
| --- | --- |
| `auto` (default) | `mercure` when a Mercure hub service exists (MercureBundle), else `poll` |
| `mercure` | After each commit, one **private** update per recipient on `notify-kit/r/<hmac>` (HMAC-SHA256 of the recipient key with `kernel.secret`), payload `{"event":"notify.updated","unread":n}` only. The bell sets the subscriber cookie for the person's own topic (MercureBundle `Authorization`, see [the subscriber cookie](#the-mercure-subscriber-cookie)). The bell falls back to polling when the cookie cannot be set while the page renders (a hub on another site, or the `__Secure-` cookie of symfony/mercure 0.10 on plain HTTP), and in the browser when the stream fails: closed by the hub, or 3 errors in a row (an expired or replaced cookie, a hub answering 401). It then polls `…/count` once at once and every `realtime.poll_seconds` after. Without a hub the container refuses to build |
| `poll` | The bell polls `…/count` every `realtime.poll_seconds` (≥ 10), paused while the tab is hidden |
| `off` | No updates; the count refreshes on the next page |

With `realtime.publisher` your own `RealtimePublisherInterface` service is called instead (Beacon keeps its topic,
Q-B3); dispatch `nowo-notify-kit:updated` on `document` with `detail: {unread: n}` to update the bell. A failing
publisher is logged and never fails the request.

### The Mercure subscriber cookie

A hub path has **one** subscriber cookie (`mercureAuthorization`). symfony/mercure 0.10 (required: the bundle conflicts
with older versions) merges the grants of every `setCookie()` in the same request, so the bell and your own
`mercure(…, {subscribe: …})` share one cookie on a page that renders both. **Across requests they replace each other**:
a page with the bell overwrites a cookie you set on an earlier request (for example a token endpoint of a co-editing
page), and your cookie overwrites the bell's. Whichever stream reconnects next loses its grant; the bell then polls (see
above). Two ways to avoid it:

- Grant the bell's topic yourself wherever you set the cookie: add `RealtimeTopics::subscribeClaimFor($recipient)` to
  your subscribe list.
- Or set `realtime.subscriber_cookie: false`: the bell sets no cookie and expects your JWT (cookie or
  `Authorization` header) to hold `subscribeClaimFor()`.

The bell's grant is always the person's own topic only.

### What publishing costs

Publishing is synchronous, after the commit: per written recipient one visibility check (one query plus one access
checker call per permission), one unread count and one HTTP call to the hub. `notify()` for 1,000 recipients makes
1,000 hub calls. The first failure (hub down, timeout) stops the badges for the rest of that call, so a dead hub costs
one timeout per call, not one per recipient; those bells catch up on the next change or page load. To keep the hub off
the request:

- Give the HTTP client that publishes to your hub a short `timeout` / `max_duration`.
- Or publish through Messenger: a `realtime.publisher` service that dispatches
  `new Update($topics->topicFor($recipient), json_encode(['event' => 'notify.updated', 'unread' => $unread]), true)`
  on the bus, with `Symfony\Component\Mercure\Update` routed to an async transport (symfony/mercure's `UpdateHandler`
  sends it).
- Or `realtime.mode: poll` / `off`.

## Retention and schema check

- `php bin/console nowo:notify-kit:prune` deletes read rows created more than `retention.read_days` (90) ago (unread
  rows are never deleted by age), trims every recipient to its newest `retention.keep_per_recipient` (500) rows and
  forgets expired dedupe keys, in batches of `retention.batch_size`, each batch its own transaction.
- `--async` only dispatches a `Contract\Message\PruneNotificationsMessage` (route it to your maintenance transport); the
  handler stops after `messenger.max_seconds` and dispatches the remainder with a keyset cursor. Schedule one of them
  daily (the bundle's scheduler recipe arrives with S4).
- Messenger policy (REQ-MSG-001): `PruneNotificationsHandler` is registered with `#[AsMessageHandler]`. Without a
  transport route the message is handled synchronously. The handler is idempotent: a redelivered message deletes only
  what is still past retention, and every batch is its own transaction, so a failed run leaves earlier batches in place.
  On an async transport use the host's `retry_strategy` (a failed run is safe to retry) and a `failure_transport` for
  messages that exhaust their retries; the log line carries counts only, never row content.
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

Events of a call made inside your transaction run after the outermost commit at the next safe point (the bundle's
next call at level 0, or the request / command / message boundary at the latest, audit QL-I5). A host that commits in
`kernel.terminate` after the main `finish_request` gets its events at `kernel.terminate`; long console batches hold
them until `console.terminate` unless the bundle is called again at level 0.


Every service is stateless except the after-commit queue, which holds the events of a transaction that joined a host
transaction and is cleared at every request, command and message boundary (and on `kernel.reset`). See
[FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).

## Translations and overrides

The bundle's strings (bundle name, the generic notification type title, the `general` category, …) live in the
translation domain `NowoNotifyKitBundle`, shipped in `src/Resources/translations/NowoNotifyKitBundle.{en,es,it,fr,pt,de,nl}.yaml`
with the same keys in every locale.

To change a string, or to add a locale, create the same domain file in your application; application translations win
over the bundle's:

```yaml
# translations/NowoNotifyKitBundle.en.yaml
notify:
    category:
        general: "Updates"
```

Clear the cache after adding a new file (`bin/console cache:clear`). The title of a notification type is the
translation key `title_key` of its registry entry (`notify.type.<key>.title` by default); the bundle ships only
`notify.type.generic.title`, so add the keys of the types you register to your application's
`NowoNotifyKitBundle.<locale>.yaml` files.
