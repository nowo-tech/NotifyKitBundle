# Usage

> **S0 scaffold.** Only the configuration exists today. This page describes the integration paths the slices build
> (contract 221-B §3–§4); each section says from which slice it works.

## Table of contents

- [In a standard Symfony application](#in-a-standard-symfony-application)
- [Using it from a DDD / hexagonal application](#using-it-from-a-ddd--hexagonal-application)
- [FrankenPHP worker mode](#frankenphp-worker-mode)

## In a standard Symfony application

1. Declare your types and categories (S1):

   ```yaml
   nowo_notify_kit:
       enabled: true
       recipient: { class: App\Entity\User }
       categories:
           account: { label: 'notify.category.account' }
       types:
           invitation_accepted: { category: account, icon: 'tabler:mail-check', channels: { in_app: true, mail: false } }
   ```

2. From a listener that runs **after your own commit**, call the notifier:

   ```php
   $notifier->notify(new NotificationRequest(
       type: 'invitation_accepted',
       recipients: [$recipients->recipientOf($inviter)],
       actor: $recipients->recipientOf($invitee),
       context: null,
       target: 'invitation:' . $invitation->getId(),
       url: '/team',
   ));
   ```

3. Turn the pages on (S2): `web_ui.enabled: true` gives the inbox page, the bell fragment and the count endpoint;
   include the bell in your layout. Preferences arrive in S3, channels in S4.

## Using it from a DDD / hexagonal application

| Concern | How a strict DDD host does it |
| --- | --- |
| Domain coupling | Your application layer depends only on `Nowo\NotifyKitBundle\Contract\*`: `NotifierInterface`, `InboxInterface`, `PreferencesInterface` and `final readonly` value objects (`RecipientRef`, `ContextRef`, `NotificationRequest`, `NotificationView`, …). No Doctrine, Symfony or HTTP type appears in them. |
| Recipients | Implement `RecipientResolverInterface` and `RecipientDirectoryInterface` (name, e-mail, locale, time zone, active); rows reference people by `RecipientRef` (type + id), never by a Doctrine relation. |
| Access | Implement `RecipientAccessCheckerInterface` (`isUnrestricted()`, `contextsGranting()`); without it only personal rows exist. |
| Mapping | Set `doctrine.auto_mapping: false` and register `vendor/nowo-tech/notify-kit-bundle/src/Resources/config/doctrine` (type `xml`, prefix `Nowo\NotifyKitBundle\Domain`) on your own entity manager. |
| Transactions | `notify()` inside your transaction on the same connection is buffered until your outermost commit and dropped on rollback; the bundle never flushes your EntityManager. |
| Deletion | No FK to your tables: call `InboxInterface::forgetContext()` when a project or board is deleted. |
| Configuration | `NotifySettingsProviderInterface` replaces config values (no hard dependency on InstanceSettings). |

## FrankenPHP worker mode

Per-request data (visibility, unread-count memo, after-commit buffer) lives in a holder cleared at every request
boundary and on `WorkerMessageHandledEvent`. See [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md).
