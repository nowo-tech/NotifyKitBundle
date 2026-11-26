# Code inventory — 100% traceability

**Baseline spec**: [`spec.md`](spec.md) (S0), [`../002-s1-core-inbox/spec.md`](../002-s1-core-inbox/spec.md) (S1), [`../003-s2-access-ui/spec.md`](../003-s2-access-ui/spec.md) (S2) and [`../004-s3-preferences/spec.md`](../004-s3-preferences/spec.md) (S3)
**Package**: `nowo-tech/notify-kit-bundle`
**Last audited**: 2026-11-26 (S1, S2 access + UI, S3 preferences; audit: `git ls-files src | wc -l` → 164)

Scope: every file under `src/`. Audit command: `find src -type f | wc -l` → **164**.

| Source file | Requirement IDs |
| --- | --- |
| `src/Application/Audit/AuditTrailInterface.php` | FR-AUDIT-001 |
| `src/Application/Audit/NullAuditTrail.php` | FR-AUDIT-001 |
| `src/Application/Audit/PreferencesAuditListener.php` | FR-AUDIT-001, FR-PREF-004 |
| `src/Application/Default/NoContextProvider.php` | FR-PREF-004 |
| `src/Application/Default/PersonalOnlyAccessChecker.php` | FR-ACCESS-001 |
| `src/Application/Default/RandomNotificationIdGenerator.php` | FR-ID-001 |
| `src/Application/Default/ResolverRecipientDirectory.php` | FR-TITLE-001 |
| `src/Application/Inbox.php` | FR-CTX-001 |
| `src/Application/Notifier.php` | FR-NOTIFY-001, FR-NOTIFY-003, FR-NOTIFY-004 |
| `src/Application/Preferences/PreferenceDefaults.php` | FR-PREF-002, FR-PREF-003 |
| `src/Application/Preferences/Preferences.php` | FR-PREF-001, FR-PREF-002, FR-PREF-003, FR-PREF-004 |
| `src/Application/Realtime/RealtimeSubscriptionInterface.php` | FR-RT-001 |
| `src/Application/Realtime/RealtimeTopics.php` | FR-RT-001 |
| `src/Application/Realtime/UnreadBadge.php` | FR-RT-001, FR-INBOX-002 |
| `src/Application/Retention/Retention.php` | FR-RET-001 |
| `src/Application/Retention/RetentionReport.php` | FR-RET-001 |
| `src/Application/TypeRegistry.php` | FR-TYPES-001 |
| `src/Application/UnitOfWorkInterface.php` | FR-QL-I6 |
| `src/Application/Visibility/VisibilityResolver.php` | FR-VIS-001 |
| `src/Bridge/AuditKit/AuditKitAuditTrail.php` | FR-AUDIT-001 |
| `src/Contract/CategoryDefinition.php` | FR-TYPES-001 |
| `src/Contract/ChannelPreference.php` | FR-PREF-002 |
| `src/Contract/ContextChoice.php` | FR-PREF-004 |
| `src/Contract/ContextProviderInterface.php` | FR-PREF-004 |
| `src/Contract/ContextRef.php` | FR-ACCESS-001 |
| `src/Contract/Event/NotificationReadEvent.php` | FR-INBOX-002, FR-EVENT-001 |
| `src/Contract/Event/NotificationRecordedEvent.php` | FR-EVENT-001 |
| `src/Contract/Event/NotificationSkippedEvent.php` | FR-EVENT-001 |
| `src/Contract/Event/NotifyKitEvents.php` | FR-EVENT-001 |
| `src/Contract/Event/PreferencesChangedEvent.php` | FR-PREF-004, FR-EVENT-001 |
| `src/Contract/Exception/InvalidNotificationArgumentException.php` | FR-NOTIFY-002 |
| `src/Contract/Exception/PreferenceConflictException.php` | FR-PREF-004, FR-CONC-003 |
| `src/Contract/Guard.php` | FR-NOTIFY-002 |
| `src/Contract/InboxFilter.php` | FR-INBOX-001 |
| `src/Contract/InboxInterface.php` | FR-CTX-001 |
| `src/Contract/InboxPage.php` | FR-INBOX-001 |
| `src/Contract/InboxStatus.php` | FR-INBOX-001 |
| `src/Contract/Message/PruneNotificationsMessage.php` | FR-RET-001 |
| `src/Contract/NotificationId.php` | FR-DB-002 |
| `src/Contract/NotificationRequest.php` | FR-NOTIFY-002 |
| `src/Contract/NotificationView.php` | FR-INBOX-001, FR-TITLE-001 |
| `src/Contract/NotifierInterface.php` | FR-NOTIFY-001, FR-NOTIFY-003, FR-NOTIFY-004 |
| `src/Contract/NotifyResult.php` | FR-NOTIFY-001 |
| `src/Contract/PreferenceChange.php` | FR-PREF-004 |
| `src/Contract/PreferenceChangeSet.php` | FR-PREF-004 |
| `src/Contract/PreferenceExpectation.php` | FR-PREF-004, FR-CONC-003 |
| `src/Contract/PreferenceKey.php` | FR-PREF-001 |
| `src/Contract/PreferenceMatrix.php` | FR-PREF-002 |
| `src/Contract/PreferenceRow.php` | FR-PREF-002 |
| `src/Contract/PreferencesInterface.php` | FR-PREF-004 |
| `src/Contract/RealtimePublisherInterface.php` | FR-RT-001 |
| `src/Contract/RecipientAccessCheckerInterface.php` | FR-ACCESS-001 |
| `src/Contract/RecipientDirectoryInterface.php` | FR-TITLE-001 |
| `src/Contract/RecipientRef.php` | FR-DB-002 |
| `src/Contract/RecipientResolverInterface.php` | FR-RECIPIENT-001 |
| `src/Contract/Scope.php` | FR-PREF-004 |
| `src/Contract/Security/InboxAccessCheckerInterface.php` | FR-SEC-UI-001, FR-VIS-001 |
| `src/Contract/Security/OperatorAccessCheckerInterface.php` | FR-SEC-UI-001 |
| `src/Contract/Security/PreferencesAccessCheckerInterface.php` | FR-SEC-UI-001, FR-HTTP-003 |
| `src/Contract/SkipReason.php` | FR-EVENT-001 |
| `src/Contract/TranslationDomain.php` | FR-I18N-001 |
| `src/Contract/TypeDefinition.php` | FR-TYPES-001 |
| `src/Contract/TypeProviderInterface.php` | FR-TYPES-001 |
| `src/Contract/TypeRegistryInterface.php` | FR-TYPES-001 |
| `src/Contract/Urgency.php` | FR-NOTIFY-002 |
| `src/DependencyInjection/Compiler/AfterCommitMiddlewarePass.php` | FR-QL-I7, FR-TX-002 |
| `src/DependencyInjection/Compiler/AuditKitBridgePass.php` | FR-AUDIT-001 |
| `src/DependencyInjection/Compiler/RealtimePass.php` | FR-RT-001 |
| `src/DependencyInjection/Compiler/RequireDoctrinePass.php` | FR-TX-001 |
| `src/DependencyInjection/Compiler/TwigPathsPass.php` | FR-UI-001 |
| `src/DependencyInjection/Compiler/WebUiPass.php` | FR-SEC-UI-001, FR-UI-002 |
| `src/DependencyInjection/ConfigNodes.php` | FR-CONFIG-001 |
| `src/DependencyInjection/Configuration.php` | FR-CONFIG-001 |
| `src/DependencyInjection/NowoNotifyKitExtension.php` | FR-CONFIG-001, FR-CONFIG-002 |
| `src/Domain/BatchResult.php` | FR-CTX-001, FR-DB-002 |
| `src/Domain/CellWrite.php` | FR-PREF-001, FR-CONC-003 |
| `src/Domain/DedupeMarker.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/InboxCursor.php` | FR-INBOX-001 |
| `src/Domain/InboxStatistics.php` | FR-UI-002 |
| `src/Domain/Notification.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/NotificationIdGeneratorInterface.php` | FR-ID-001 |
| `src/Domain/NotificationStoreInterface.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/PreferenceStoreInterface.php` | FR-PREF-001 |
| `src/Domain/RecordOutcome.php` | FR-NOTIFY-001, FR-DB-002 |
| `src/Domain/StoredPreference.php` | FR-PREF-001 |
| `src/Domain/Visibility.php` | FR-VIS-001 |
| `src/Domain/VisibleContext.php` | FR-VIS-001 |
| `src/Infrastructure/Console/PruneNotificationsCommand.php` | FR-RET-001 |
| `src/Infrastructure/Console/SchemaCheckCommand.php` | FR-DB-003 |
| `src/Infrastructure/Doctrine/Entity/DedupeRecord.php` | FR-DB-002 |
| `src/Infrastructure/Doctrine/Entity/NotificationRecord.php` | FR-DB-002 |
| `src/Infrastructure/Doctrine/Entity/PreferenceRecord.php` | FR-PREF-001, FR-DB-002 |
| `src/Infrastructure/Doctrine/NotifyKitMetadataListener.php` | FR-DB-002 |
| `src/Infrastructure/Identity/UuidV7NotificationIdGenerator.php` | FR-ID-001 |
| `src/Infrastructure/Messenger/PruneNotificationsHandler.php` | FR-RET-001 |
| `src/Infrastructure/Persistence/AfterCommitConnection.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitMiddleware.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitQueue.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/AfterCommitQueueResetListener.php` | FR-TX-002 |
| `src/Infrastructure/Persistence/DbalNotificationStore.php` | FR-TX-003, FR-DB-002 |
| `src/Infrastructure/Persistence/DbalPreferenceStore.php` | FR-PREF-001, FR-CONC-003 |
| `src/Infrastructure/Persistence/NotifyKitTransactions.php` | FR-TX-001 |
| `src/Infrastructure/Realtime/MercureRealtimePublisher.php` | FR-RT-001 |
| `src/Infrastructure/Realtime/MercureSubscription.php` | FR-RT-001 |
| `src/Infrastructure/Recipient/DoctrineRecipientResolver.php` | FR-RECIPIENT-001 |
| `src/Infrastructure/Schema/SchemaChecker.php` | FR-DB-003 |
| `src/NowoNotifyKitBundle.php` | FR-BUNDLE-001 |
| `src/Presentation/Controller/InboxController.php` | FR-INBOX-001, FR-INBOX-002, FR-UI-002, FR-HTTP-001 |
| `src/Presentation/Controller/OperatorController.php` | FR-UI-002, FR-HTTP-001 |
| `src/Presentation/Controller/PreferencesController.php` | FR-UI-003, FR-HTTP-003 |
| `src/Presentation/Form/ActionType.php` | FR-UI-002 |
| `src/Presentation/Form/InboxFilterType.php` | FR-UI-002 |
| `src/Presentation/Form/PreferencesType.php` | FR-UI-003 |
| `src/Presentation/Http/Conflicts.php` | FR-CONC-002, FR-CONC-003, FR-HTTP-003 |
| `src/Presentation/Http/CurrentRecipient.php` | FR-HTTP-001, FR-HTTP-003 |
| `src/Presentation/Http/Responses.php` | FR-HTTP-001 |
| `src/Presentation/Routing/ControllerAttributeRouteLoader.php` | FR-HTTP-001, FR-HTTP-003 |
| `src/Presentation/Routing/NotifyKitRouteLoader.php` | FR-HTTP-001, FR-HTTP-003 |
| `src/Presentation/Security/RoleAccessChecker.php` | FR-SEC-UI-001 |
| `src/Presentation/Title/NotificationTitles.php` | FR-TITLE-001 |
| `src/Presentation/Twig/BellRenderer.php` | FR-UI-001 |
| `src/Presentation/Twig/NotifyKitTwigExtension.php` | FR-UI-001 |
| `src/Presentation/View/GridCell.php` | FR-UI-003 |
| `src/Presentation/View/GridRow.php` | FR-UI-003 |
| `src/Presentation/View/GridSwitch.php` | FR-UI-003 |
| `src/Presentation/View/InboxPages.php` | FR-UI-002 |
| `src/Presentation/View/InboxRow.php` | FR-UI-002 |
| `src/Presentation/View/PreferenceGrid.php` | FR-UI-003 |
| `src/Presentation/View/PreferencePages.php` | FR-UI-003 |
| `src/Presentation/View/RelativeTime.php` | FR-UI-001, FR-UI-002 |
| `src/Resources/assets/src/bell.ts` | FR-UI-001 |
| `src/Resources/assets/src/iife.ts` | FR-UI-001, FR-ASSET-001 |
| `src/Resources/assets/src/logger.ts` | FR-ASSET-001 |
| `src/Resources/assets/src/preferences-iife.ts` | FR-UI-004, FR-ASSET-001 |
| `src/Resources/assets/src/preferences.ts` | FR-UI-004 |
| `src/Resources/assets/stimulus-peers/bell_controller.ts` | FR-UI-001 |
| `src/Resources/assets/tests/bell.test.ts` | FR-UI-001, FR-QA-001 |
| `src/Resources/assets/tests/iife.test.ts` | FR-UI-001, FR-ASSET-001, FR-QA-001 |
| `src/Resources/assets/tests/logger.test.ts` | FR-ASSET-001, FR-QA-001 |
| `src/Resources/assets/tests/peers.test.ts` | FR-UI-001, FR-QA-001 |
| `src/Resources/assets/tests/preferences.test.ts` | FR-UI-004, FR-QA-001 |
| `src/Resources/config/doctrine/DedupeMarker.orm.xml` | FR-DB-002 |
| `src/Resources/config/doctrine/Notification.orm.xml` | FR-DB-002 |
| `src/Resources/config/doctrine/README.md` | FR-DB-002 |
| `src/Resources/config/doctrine/StoredPreference.orm.xml` | FR-PREF-001, FR-DB-002 |
| `src/Resources/config/routes.php` | FR-HTTP-001, FR-HTTP-003 |
| `src/Resources/public/css/nowo-notify-kit.css` | FR-UI-001, FR-UI-002, FR-UI-003 |
| `src/Resources/public/js/nowo-notify-kit-bell.js` | FR-UI-001 |
| `src/Resources/public/js/nowo-notify-kit-preferences.js` | FR-UI-004 |
| `src/Resources/translations/NowoNotifyKitBundle.de.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.en.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.es.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.fr.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.it.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.nl.yaml` | FR-I18N-001 |
| `src/Resources/translations/NowoNotifyKitBundle.pt.yaml` | FR-I18N-001 |
| `src/Resources/views/bell/_bell.html.twig` | FR-UI-001 |
| `src/Resources/views/bell/_menu.html.twig` | FR-UI-001 |
| `src/Resources/views/inbox/index.html.twig` | FR-UI-002 |
| `src/Resources/views/operator/index.html.twig` | FR-UI-002 |
| `src/Resources/views/preferences/_fragment.html.twig` | FR-UI-003 |
| `src/Resources/views/preferences/_matrix.html.twig` | FR-UI-003 |
| `src/Resources/views/preferences/_notice.html.twig` | FR-UI-003 |
| `src/Resources/views/preferences/index.html.twig` | FR-UI-003 |

| Total production sources | 164 |
| --- | --- |
