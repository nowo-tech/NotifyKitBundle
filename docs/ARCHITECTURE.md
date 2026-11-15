# Architecture

The design source of truth is [CONTRACT.md](CONTRACT.md) (contract 221-B). This page summarises the layout the slices
follow.

## Table of contents

- [Layers](#layers)
- [Two host kinds](#two-host-kinds)
- [S1 contents](#s1-contents)

## Layers

New bundles use layered namespaces (architecture bar rule 3):

| Namespace | Holds |
| --- | --- |
| `Nowo\NotifyKitBundle\Contract` | Public ports: interfaces and value objects. No Doctrine, Symfony or HTTP type |
| `Nowo\NotifyKitBundle\Domain` | Plain-PHP domain classes, mapped in XML (`src/Resources/config/doctrine/`) |
| `Nowo\NotifyKitBundle\Application` | Bundle-implemented services behind the ports |
| `Nowo\NotifyKitBundle\Infrastructure` | DBAL stores, Symfony integration (security, Messenger, console) |
| `Nowo\NotifyKitBundle\Presentation` | Controllers, forms, Twig (UiKit + FormKit) |
| `Nowo\NotifyKitBundle\Bridge` | Optional integrations, active when the other package is installed |

## Two host kinds

- **Standard Symfony host** (default, zero config): the bundle's XML mapping is prepended on the configured entity
  manager; the host references bundle rows by id and may read them through Doctrine.
- **DDD / hexagonal host** (opt-in): `doctrine.auto_mapping: false`, the XML mapping registered on the host's own
  entity manager, every host-side extension point implemented as an SPI interface, references by id only.

Both paths run the same functional suite from S1 on.

## S1 contents

| Layer | Classes |
| --- | --- |
| Contract | `NotifierInterface`, `InboxInterface` (S1: `forgetContext()`), `TypeRegistryInterface`, `TypeProviderInterface`, `RecipientAccessCheckerInterface`, `RecipientResolverInterface`; VOs `RecipientRef`, `ContextRef`, `NotificationId`, `NotificationRequest`, `TypeDefinition`, `CategoryDefinition`, `NotifyResult`, `Urgency`, `SkipReason`; events `NotificationRecordedEvent`, `NotificationSkippedEvent`; message `PruneNotificationsMessage` |
| Domain | `Notification`, `DedupeMarker` (XML-mapped plain classes), `NotificationStoreInterface`, `RecordOutcome` |
| Application | `Notifier`, `Inbox`, `TypeRegistry`, `Default\PersonalOnlyAccessChecker`, `Retention\Retention` |
| Infrastructure | `Persistence\DbalNotificationStore`, `Persistence\NotifyKitTransactions` (transaction-contract runner), `Persistence\AfterCommit*` (DBAL middleware holding events until the outermost commit), `Doctrine\NotifyKitMetadataListener` (prefix, binary collations), `Doctrine\Entity\*Record` (attribute twins), `Recipient\DoctrineRecipientResolver`, `Console\PruneNotificationsCommand`, `Console\SchemaCheckCommand`, `Messenger\PruneNotificationsHandler`, `Schema\SchemaChecker` |

Write path: `Notifier` → `NotifyKitTransactions::run()` (one DBAL transaction, or the host's when one is open) →
`DbalNotificationStore` (dedupe claim, coalesce or insert) → after the outermost commit: events.
