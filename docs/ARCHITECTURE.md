# Architecture

The design source of truth is [CONTRACT.md](CONTRACT.md) (contract 221-B). This page summarises the layout the slices
follow.

## Table of contents

- [Layers](#layers)
- [Architecture checks](#architecture-checks)
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

## Architecture checks

[Deptrac](https://github.com/deptrac/deptrac) checks the dependencies between the layers on every CI run (REQ-ARCH-002;
`deptrac.yaml`, `composer deptrac` / `make deptrac`, part of `make release-check`). A violation, a dependency on an
unlisted vendor namespace or a class under `src/` that belongs to no layer fails the build.

| Deptrac layer | Namespaces | May depend on |
| --- | --- | --- |
| `Domain` | `Contract`, `Domain` | itself, `psr/clock`, `psr/log`, `psr/event-dispatcher`, `nowo-tech/transaction-contract` |
| `Application` | `Application` | `Domain` and the same contract packages |
| `Infrastructure` | `Infrastructure` | `Domain`, `Application`, Symfony, Doctrine, Twig, UiKit / FormKit / transaction-doctrine |
| `UI` | `Presentation` | `Domain`, `Application`, Symfony, Twig, UiKit / FormKit |
| `Bridge` | `Bridge` | `Domain`, `Application`, Symfony and the bridged package (AuditKit) |
| `DependencyInjection` | `DependencyInjection`, the bundle class | every layer (composition root) |

Domain and Application never import a Symfony component, Doctrine or Twig: what they need from the framework goes
through a port in `Domain` / `Application` with an adapter in `Infrastructure` (for example
`NotificationIdGeneratorInterface` → `UuidV7NotificationIdGenerator`, `RealtimeSubscriptionInterface` →
`MercureSubscription`). The ruleset has no skipped violations; a new one is fixed in code, not silenced.

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
