# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md) (S0) and [`../002-s1-core-inbox/spec.md`](../002-s1-core-inbox/spec.md) (S1)
**Package**: `nowo-tech/notify-kit-bundle`
**Last audited**: 2026-11-15 (S1)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **61**.

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Default/PersonalOnlyAccessChecker.php` | FR-ACCESS-001 |
| `src/Application/Inbox.php` | FR-CTX-001 |
| `src/Application/Notifier.php` | FR-NOTIFY-001, FR-NOTIFY-003, FR-NOTIFY-004 |
| `src/Application/Retention/Retention.php` | FR-RET-001 |
| `src/Application/Retention/RetentionReport.php` | FR-RET-001 |
| `src/Application/TypeRegistry.php` | FR-TYPES-001 |
| `src/Contract/CategoryDefinition.php` | FR-TYPES-001 |
| `src/Contract/ContextRef.php` | FR-ACCESS-001 |
| `src/Contract/Event/NotificationRecordedEvent.php` | FR-EVENT-001 |
| `src/Contract/Event/NotificationSkippedEvent.php` | FR-EVENT-001 |
| `src/Contract/Event/NotifyKitEvents.php` | FR-EVENT-001 |
| `src/Contract/Exception/InvalidNotificationArgumentException.php` | FR-NOTIFY-002 |
| `src/Contract/Guard.php` | FR-NOTIFY-002 |
| `src/Contract/InboxInterface.php` | FR-CTX-001 |
| `src/Contract/Message/PruneNotificationsMessage.php` | FR-RET-001 |
| `src/Contract/NotificationId.php` | FR-DB-002 |
| `src/Contract/NotificationRequest.php` | FR-NOTIFY-002 |
| `src/Contract/NotifierInterface.php` | FR-NOTIFY-001, FR-NOTIFY-003, FR-NOTIFY-004 |
| `src/Contract/NotifyResult.php` | FR-NOTIFY-001 |
| `src/Contract/RecipientAccessCheckerInterface.php` | FR-ACCESS-001 |
| `src/Contract/RecipientRef.php` | FR-DB-002 |
| `src/Contract/RecipientResolverInterface.php` | FR-RECIPIENT-001 |
| `src/Contract/SkipReason.php` | FR-EVENT-001 |
| `src/Contract/TypeDefinition.php` | FR-TYPES-001 |
| `src/Contract/TypeProviderInterface.php` | FR-TYPES-001 |
| `src/Contract/TypeRegistryInterface.php` | FR-TYPES-001 |
| `src/Contract/Urgency.php` | FR-NOTIFY-002 |
| `src/DependencyInjection/Compiler/RequireDoctrinePass.php` | FR-TX-001 |
| `src/DependencyInjection/ConfigNodes.php` | FR-CONFIG-001 |
| `src/DependencyInjection/Configuration.php` | FR-CONFIG-001 |
| `src/DependencyInjection/NowoNotifyKitExtension.php` | FR-CONFIG-001, FR-CONFIG-002 |
| `src/Domain/BatchResult.php` | FR-CTX-001, FR-DB-002 |
| `src/Domain/DedupeMarker.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/Notification.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/NotificationStoreInterface.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/RecordOutcome.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Infrastructure/Console/PruneNotificationsCommand.php` | FR-RET-001 |
| `src/Infrastructure/Console/SchemaCheckCommand.php` | FR-DB-003 |
| `src/Infrastructure/Doctrine/Entity/DedupeRecord.php` | FR-DB-002 |
| `src/Infrastructure/Doctrine/Entity/NotificationRecord.php` | FR-DB-002 |
| `src/Infrastructure/Doctrine/NotifyKitMetadataListener.php` | FR-DB-002 |
| `src/Infrastructure/Messenger/PruneNotificationsHandler.php` | FR-RET-001 |
| `src/Infrastructure/Persistence/AfterCommitConnection.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitMiddleware.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitQueue.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitQueueResetListener.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/DbalNotificationStore.php` | FR-TX-003, FR-DB-002 |
| `src/Infrastructure/Persistence/NotifyKitTransactions.php` | FR-TX-001 |
| `src/Infrastructure/Recipient/DoctrineRecipientResolver.php` | FR-RECIPIENT-001 |
| `src/Infrastructure/Schema/SchemaChecker.php` | FR-DB-003 |
| `src/NowoNotifyKitBundle.php` | FR-BUNDLE-001 |
| `src/Resources/config/doctrine/DedupeMarker.orm.xml` | FR-DB-002 |
| `src/Resources/config/doctrine/Notification.orm.xml` | FR-DB-002 |
| `src/Resources/config/doctrine/README.md` | FR-DB-002 |
| `src/Resources/translations/NowoNotifyKitBundle.de.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.en.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.es.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.fr.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.it.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.nl.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.pt.yaml` | FR-I18N-001 |

| Total production sources | 61 |
| --- | --- |
