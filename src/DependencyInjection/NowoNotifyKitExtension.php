<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection;

use LogicException;
use Nowo\NotifyKitBundle\Application\Audit\AuditTrailInterface;
use Nowo\NotifyKitBundle\Application\Audit\NullAuditTrail;
use Nowo\NotifyKitBundle\Application\Default\NoContextProvider;
use Nowo\NotifyKitBundle\Application\Default\PersonalOnlyAccessChecker;
use Nowo\NotifyKitBundle\Application\Default\ResolverRecipientDirectory;
use Nowo\NotifyKitBundle\Application\Inbox;
use Nowo\NotifyKitBundle\Application\Notifier;
use Nowo\NotifyKitBundle\Application\Preferences\Preferences;
use Nowo\NotifyKitBundle\Application\Realtime\RealtimeTopics;
use Nowo\NotifyKitBundle\Application\Realtime\UnreadBadge;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Application\UnitOfWorkInterface;
use Nowo\NotifyKitBundle\Application\Visibility\VisibilityResolver;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientDirectoryInterface;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\Contract\Security\InboxAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\Security\OperatorAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\Security\PreferencesAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Domain\PreferenceStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Console\PruneNotificationsCommand;
use Nowo\NotifyKitBundle\Infrastructure\Console\SchemaCheckCommand;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;
use Nowo\NotifyKitBundle\Infrastructure\Messenger\PruneNotificationsHandler;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalPreferenceStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureRealtimePublisher;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureSubscription;
use Nowo\NotifyKitBundle\Infrastructure\Recipient\DoctrineRecipientResolver;
use Nowo\NotifyKitBundle\Infrastructure\Schema\SchemaChecker;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Nowo\NotifyKitBundle\Presentation\Controller\PreferencesController;
use Nowo\NotifyKitBundle\Presentation\Form\ActionType;
use Nowo\NotifyKitBundle\Presentation\Form\InboxFilterType;
use Nowo\NotifyKitBundle\Presentation\Form\PreferencesType;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Routing\NotifyKitRouteLoader;
use Nowo\NotifyKitBundle\Presentation\Security\RoleAccessChecker;
use Nowo\NotifyKitBundle\Presentation\Title\NotificationTitles;
use Nowo\NotifyKitBundle\Presentation\Twig\BellRenderer;
use Nowo\NotifyKitBundle\Presentation\Twig\NotifyKitTwigExtension;
use Nowo\NotifyKitBundle\Presentation\View\PreferencePages;
use Nowo\NotifyKitBundle\Presentation\View\RelativeTime;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function array_key_exists;
use function class_exists;
use function interface_exists;
use function is_array;
use function sprintf;

/**
 * Loads `nowo_notify_kit`: validates the strict configuration tree, publishes it as container parameters and, while
 * `enabled` is true, registers the S1 core (contract 221-B §4–§5, §6.1, §6.6, §9): the type registry, the DBAL store
 * with its after-commit middleware and transaction runner, the notifier, the inbox maintenance service, the access and
 * recipient defaults, the Doctrine metadata listener, retention (command + message handler) and the schema check; the
 * S2 read side and pages; the S3 preferences (store, service, page, audit trail port).
 *
 * `enabled: false` registers no service, listener, mapping or command. The parameters always exist, so the host's
 * `security.yaml` patterns can follow `%nowo_notify_kit.api_prefix%` (one-value prefix rule).
 */
final class NowoNotifyKitExtension extends Extension implements PrependExtensionInterface
{
    /** Configuration root and extension alias. */
    public const ALIAS = 'nowo_notify_kit';

    /** Tag of {@see TypeProviderInterface} services (autoconfigured). */
    public const TAG_TYPE_PROVIDER = 'nowo_notify_kit.type_provider';

    /** Doctrine mapping name of the bundle tables. */
    public const MAPPING_NAME = 'NowoNotifyKitBundle';

    /** Service id of the bundle's transaction runner (`doctrine.transaction_runner`, or the default). */
    public const TRANSACTIONS = 'nowo_notify_kit.transactions';

    /** Container parameter of the effective realtime mode (`mercure`, `host`, `poll`, `off`; set by the realtime pass). */
    public const REALTIME_MODE = 'nowo_notify_kit.realtime_mode';

    /** Container parameter of the configured `realtime.mode` (`auto`, `mercure`, `poll`, `off`). */
    public const REALTIME_REQUESTED = 'nowo_notify_kit.realtime_requested';

    /** Asset package of the bell script and stylesheet (REQ-ASSETS-004). */
    public const ASSET_PACKAGE = 'nowo_notify_kit';

    /** Public path of `assets:install` (`bundles/nowonotifykit`). */
    public const ASSET_BASE_PATH = '/bundles/nowonotifykit';

    /** FormKit profile of the bundle forms (`nowo_form_kit.profiles.notify_kit`). */
    public const FORM_PROFILE = 'notify_kit';

    /**
     * The extension alias, `nowo_notify_kit`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Standard hosts (`doctrine.auto_mapping: true`, the default): registers the XML mapping (or the attribute twins)
     * on `doctrine.entity_manager` when the host configures the ORM and the bundle is enabled. Always: the
     * `nowo_notify_kit` asset package and the FormKit profile `notify_kit` (a host profile of that name wins).
     */
    public function prepend(ContainerBuilder $container): void
    {
        $this->prependWebUi($container);
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(self::ALIAS));
        if (!$config['enabled'] || !$config['doctrine']['auto_mapping']) {
            return;
        }
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            $hasOrm = $hasOrm || (is_array($doctrine) && array_key_exists('orm', $doctrine));
        }
        if (!$hasOrm) {
            return;
        }
        $mapping = $config['doctrine']['mapping'] === 'attribute'
            ? ['type' => 'attribute', 'dir' => __DIR__ . '/../Infrastructure/Doctrine/Entity', 'prefix' => 'Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity']
            : ['type' => 'xml', 'dir' => __DIR__ . '/../Resources/config/doctrine', 'prefix' => 'Nowo\NotifyKitBundle\Domain'];
        $container->prependExtensionConfig('doctrine', [
            'orm' => ['entity_managers' => [$config['doctrine']['entity_manager'] => ['mappings' => [self::MAPPING_NAME => $mapping + ['is_bundle' => false]]]]],
        ]);
    }

    /**
     * Processes the configuration, sets the `nowo_notify_kit.*` parameters and, when enabled, registers the services.
     *
     * @param array<array-key, mixed> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $container->setParameter(self::ALIAS . '.enabled', $config['enabled']);
        $container->setParameter(self::ALIAS . '.api_prefix', $config['api']['prefix']);
        $container->setParameter(self::ALIAS . '.table_prefix', $config['doctrine']['table_prefix']);
        $container->setParameter(self::ALIAS . '.config', $config);
        $webUi   = $config['enabled'] && $config['web_ui']['enabled'];
        $adminUi = $config['enabled'] && $config['admin_ui']['enabled'];
        // The loader always exists (an import of the routes file never fails); it adds routes only for enabled UIs.
        $container->register(NotifyKitRouteLoader::class, NotifyKitRouteLoader::class)
            ->setArguments([$webUi, $config['web_ui']['path_prefix'], $adminUi, $config['admin_ui']['path_prefix'], '%kernel.environment%', $config['web_ui']['preferences_path']])
            ->addTag('routing.loader');
        if (!$config['enabled']) {
            return;
        }

        $this->registerCore($container, $config);
        if ($webUi || $adminUi) {
            $this->registerWebUi($container, $config, $webUi, $adminUi);
        }
    }

    /**
     * The `nowo_notify_kit` asset package and the FormKit profile `notify_kit` (bundle translation domain, UiKit input
     * class); a host profile of that name wins.
     */
    private function prependWebUi(ContainerBuilder $container): void
    {
        if ($container->hasExtension('framework')) {
            $container->prependExtensionConfig('framework', ['assets' => ['packages' => [self::ASSET_PACKAGE => ['base_path' => self::ASSET_BASE_PATH]]]]);
        }
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }
        foreach ($container->getExtensionConfig('nowo_form_kit') as $formKit) {
            if (is_array($formKit) && is_array($formKit['profiles'] ?? null) && array_key_exists(self::FORM_PROFILE, $formKit['profiles'])) {
                return;
            }
        }
        $container->prependExtensionConfig('nowo_form_kit', ['profiles' => [self::FORM_PROFILE => [
            'alias'                           => self::FORM_PROFILE,
            'translation_domain'              => NowoNotifyKitBundle::TRANSLATION_DOMAIN,
            'convention_requires_translation' => true,
            'defaults'                        => ['attr' => ['class' => 'nowo-ui-input'], 'row_attr' => []],
        ]]]);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerCore(ContainerBuilder $container, array $config): void
    {
        /** @var array{entity_manager: string, table_prefix: string, transaction_runner: string|null} $doctrine */
        $doctrine = $config['doctrine'];
        /** @var array{type: string, class: string|null, id_field: string, resolver: string|null} $recipient */
        $recipient = $config['recipient'];
        /** @var array{checker: string|null, default_permission: string|null} $access */
        $access = $config['access'];
        /** @var array{read_days: int, keep_per_recipient: int, batch_size: int} $retention */
        $retention = $config['retention'];
        $logger    = new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $clock     = new Reference('clock', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $messenger = interface_exists(MessageBusInterface::class);
        $bus       = $messenger ? new Reference('messenger.default_bus', ContainerInterface::IGNORE_ON_INVALID_REFERENCE) : null;

        if ($recipient['resolver'] === null && $recipient['class'] !== null && !class_exists($recipient['class'])) {
            throw new LogicException(sprintf('nowo_notify_kit.recipient.class "%s" does not exist.', $recipient['class']));
        }

        $container->registerForAutoconfiguration(TypeProviderInterface::class)->addTag(self::TAG_TYPE_PROVIDER);

        $container->register(AfterCommitQueue::class, AfterCommitQueue::class)
            ->setArguments([$logger])
            ->addTag('kernel.reset', ['method' => 'reset']);
        $reset = $container->register(AfterCommitQueueResetListener::class, AfterCommitQueueResetListener::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('kernel.event_listener', ['event' => 'kernel.finish_request', 'method' => 'onFinishRequest', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'kernel.terminate', 'method' => 'onBoundary', 'priority' => -1024])
            ->addTag('kernel.event_listener', ['event' => 'console.terminate', 'method' => 'onBoundary', 'priority' => -1024]);
        foreach (['Symfony\Component\Messenger\Event\WorkerMessageHandledEvent', 'Symfony\Component\Messenger\Event\WorkerMessageFailedEvent'] as $event) {
            $reset->addTag('kernel.event_listener', ['event' => $event, 'method' => 'onBoundary', 'priority' => -1024]);
        }
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)
            ->setArguments([new Reference(AfterCommitQueue::class)])
            ->addTag('doctrine.middleware');
        $container->register(NotifyKitMetadataListener::class, NotifyKitMetadataListener::class)
            ->setArguments([$doctrine['table_prefix']])
            ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']);

        $container->register(self::TRANSACTIONS, NotifyKitTransactions::class)->setArguments([
            new Reference('doctrine'),
            new Reference(AfterCommitQueue::class),
            $doctrine['entity_manager'],
            $doctrine['transaction_runner'] !== null ? new Reference($doctrine['transaction_runner']) : null,
        ]);
        $container->setAlias(UnitOfWorkInterface::class, self::TRANSACTIONS);
        $transactions = new Reference(self::TRANSACTIONS);
        $container->register(DbalNotificationStore::class, DbalNotificationStore::class)->setArguments([$transactions, $doctrine['table_prefix']]);
        $container->setAlias(NotificationStoreInterface::class, DbalNotificationStore::class);
        $store = new Reference(NotificationStoreInterface::class);
        $container->register(DbalPreferenceStore::class, DbalPreferenceStore::class)->setArguments([$transactions, $doctrine['table_prefix']]);
        $container->setAlias(PreferenceStoreInterface::class, DbalPreferenceStore::class);
        $container->register(NullAuditTrail::class, NullAuditTrail::class);
        $container->setAlias(AuditTrailInterface::class, NullAuditTrail::class);

        $container->register(TypeRegistry::class, TypeRegistry::class)
            ->setFactory([TypeRegistry::class, 'create'])
            ->setArguments([$config['types'], $config['categories'], new TaggedIteratorArgument(self::TAG_TYPE_PROVIDER), $access['checker'] !== null, $access['default_permission']]);
        $container->setAlias(TypeRegistryInterface::class, TypeRegistry::class)->setPublic(true);

        $this->service($container, RecipientAccessCheckerInterface::class, $access['checker'], new Definition(PersonalOnlyAccessChecker::class));
        if ($recipient['resolver'] !== null) {
            $container->setAlias(RecipientResolverInterface::class, $recipient['resolver'])->setPublic(true);
        } elseif ($recipient['class'] !== null) {
            $container->register(DoctrineRecipientResolver::class, DoctrineRecipientResolver::class)
                ->setArguments([new Reference('doctrine'), $recipient['class'], $recipient['id_field'], $recipient['type']]);
            $container->setAlias(RecipientResolverInterface::class, DoctrineRecipientResolver::class)->setPublic(true);
        }

        $this->registerReadSide($container, $config, $store, $logger);

        $container->register(Preferences::class, Preferences::class)->setArguments([
            new Reference(PreferenceStoreInterface::class), $transactions, new Reference(TypeRegistryInterface::class), new Reference(ContextProviderInterface::class),
            new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $clock, $config['preferences']['scopes'],
        ]);
        $container->setAlias(PreferencesInterface::class, Preferences::class)->setPublic(true);
        $container->register(Notifier::class, Notifier::class)->setArguments([
            $store, $transactions, new Reference(TypeRegistryInterface::class), new Reference(RecipientAccessCheckerInterface::class),
            new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            $config['inbox']['repeat_window_seconds'], $config['fan_out']['max_recipients'], $clock, $logger,
            new Reference(UnreadBadge::class), new Reference(Preferences::class),
        ]);
        $container->setAlias(NotifierInterface::class, Notifier::class)->setPublic(true);
        $container->register(Inbox::class, Inbox::class)->setArguments([
            $store, $transactions, new Reference(VisibilityResolver::class), new Reference(UnreadBadge::class),
            new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE), $retention['batch_size'], $clock,
        ]);
        $container->setAlias(InboxInterface::class, Inbox::class)->setPublic(true);

        $container->register(Retention::class, Retention::class)->setArguments([
            $store, $transactions, $retention['read_days'], $retention['keep_per_recipient'], $retention['batch_size'], $config['messenger']['max_seconds'], $clock,
        ]);
        if ($messenger) {
            $container->register(PruneNotificationsHandler::class, PruneNotificationsHandler::class)
                ->setAutoconfigured(true)
                ->setArguments([new Reference(Retention::class), $bus, $logger]);
        }
        $container->register(SchemaChecker::class, SchemaChecker::class)->setArguments([new Reference('doctrine'), $doctrine['entity_manager'], $doctrine['table_prefix']]);

        // Registered from their #[AsCommand] attribute (REQ-SF-003): autoconfiguration adds the console tag.
        $container->register(PruneNotificationsCommand::class, PruneNotificationsCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference(Retention::class), $bus]);
        $container->register(SchemaCheckCommand::class, SchemaCheckCommand::class)
            ->setAutoconfigured(true)
            ->setArguments([new Reference(SchemaChecker::class)]);
    }

    /**
     * The read side (S2): read-time visibility, realtime topics and publisher, the unread badge, the people directory
     * and the context labels. The effective realtime mode is settled by {@see Compiler\RealtimePass} once the hub
     * services are known.
     *
     * @param array<string, mixed> $config
     */
    private function registerReadSide(ContainerBuilder $container, array $config, Reference $store, Reference $logger): void
    {
        /** @var array{mode: string, poll_seconds: int, subscriber_cookie: bool, publisher: string|null} $realtime */
        $realtime = $config['realtime'];
        /** @var array{directory: string|null} $recipient */
        $recipient = $config['recipient'];
        /** @var array{contexts: array{provider: string|null}} $preferences */
        $preferences = $config['preferences'];

        $container->register(VisibilityResolver::class, VisibilityResolver::class)
            ->setArguments([$store, new Reference(TypeRegistryInterface::class), new Reference(RecipientAccessCheckerInterface::class)]);
        $container->register(RealtimeTopics::class, RealtimeTopics::class)->setArguments(['%kernel.secret%'])->setPublic(true);

        $mode = $realtime['mode'];
        if ($realtime['publisher'] !== null) {
            $container->setAlias(RealtimePublisherInterface::class, $realtime['publisher'])->setPublic(true);
            $mode = 'host';
        } elseif (($mode === 'mercure' || $mode === 'auto') && interface_exists(HubInterface::class)) {
            // Kept only when the host has a hub ({@see Compiler\RealtimePass}); `auto` falls back to polling.
            $mode = 'mercure';
            $container->register(MercureRealtimePublisher::class, MercureRealtimePublisher::class)
                ->setArguments([new Reference(HubInterface::class), new Reference(RealtimeTopics::class)]);
            $container->setAlias(RealtimePublisherInterface::class, MercureRealtimePublisher::class)->setPublic(true);
            $container->register(MercureSubscription::class, MercureSubscription::class)->setArguments([
                new Reference(HubInterface::class), new Reference('request_stack'),
                $realtime['subscriber_cookie'] ? new Reference(Authorization::class, ContainerInterface::NULL_ON_INVALID_REFERENCE) : null, $logger,
            ]);
        }
        $container->setParameter(self::REALTIME_MODE, $mode === 'auto' ? 'poll' : $mode);
        $container->setParameter(self::REALTIME_REQUESTED, $realtime['mode']);

        $container->register(UnreadBadge::class, UnreadBadge::class)->setArguments([
            $store, new Reference(VisibilityResolver::class), new Reference(RealtimePublisherInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE), $logger,
        ]);
        $this->service($container, RecipientDirectoryInterface::class, $recipient['directory'], new Definition(ResolverRecipientDirectory::class, [
            new Reference(RecipientResolverInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]));
        $this->service($container, ContextProviderInterface::class, $preferences['contexts']['provider'], new Definition(NoContextProvider::class));
    }

    /**
     * The inbox page, bell and JSON endpoints (`web_ui`) and the operator page (`admin_ui`), REQ-UI-001/002. Their
     * host requirements (Twig, forms, CSRF, SecurityBundle) are checked by {@see Compiler\WebUiPass}.
     *
     * @param array<string, mixed> $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config, bool $webUi, bool $adminUi): void
    {
        /** @var array{class: string|null, resolver: string|null} $recipient */
        $recipient = $config['recipient'];
        /** @var array{path_prefix: string, layout_template: string|null, templates: array{bell: string|null, menu: string|null, inbox: string|null, preferences: string|null}, security: array{access_roles: list<string>, access_checker: string|null, preferences_roles: list<string>|null, preferences_access_checker: string|null}} $web */
        $web = $config['web_ui'];
        /** @var array{security: array{access_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $admin */
        $admin = $config['admin_ui'];
        if ($webUi && $recipient['class'] === null && $recipient['resolver'] === null) {
            throw new LogicException('nowo_notify_kit.web_ui needs to know who the logged-in person is: set nowo_notify_kit.recipient.class (Doctrine user entity) or nowo_notify_kit.recipient.resolver.');
        }
        $logger        = new Reference('logger', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $clock         = new Reference('clock', ContainerInterface::IGNORE_ON_INVALID_REFERENCE);
        $authorization = new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE);

        // FormKit wires its options merger into tagged types (REQ-TWIG-005).
        foreach ([InboxFilterType::class, ActionType::class, PreferencesType::class] as $type) {
            $container->register($type, $type)->addTag('form.type');
        }
        $container->register(CurrentRecipient::class, CurrentRecipient::class)->setArguments([
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference(RecipientResolverInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]);

        if ($webUi) {
            $this->checker($container, InboxAccessCheckerInterface::class, $web['security']['access_checker'], 'nowo_notify_kit.inbox_access_checker', [$authorization, $web['security']['access_roles'], false]);
            $container->register(RelativeTime::class, RelativeTime::class)->setArguments([new Reference('translator')]);
            $container->register(NotificationTitles::class, NotificationTitles::class)->setArguments([
                new Reference('translator'), new Reference(TypeRegistryInterface::class), new Reference(RecipientDirectoryInterface::class), new Reference(ContextProviderInterface::class),
            ]);
            $container->register(InboxController::class, InboxController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->setArguments([
                    new Reference(InboxInterface::class), new Reference(TypeRegistryInterface::class), new Reference(CurrentRecipient::class),
                    new Reference(InboxAccessCheckerInterface::class), new Reference(NotificationTitles::class), new Reference(RelativeTime::class),
                    new Reference('form.factory'), new Reference('twig'), new Reference('router'), new Reference('security.csrf.token_manager'), $clock,
                    $config['inbox']['menu_limit'], $config['inbox']['page_size'], $web['layout_template'], $web['templates']['inbox'], $web['templates']['menu'],
                ]);
            $container->register(BellRenderer::class, BellRenderer::class)
                ->addTag('twig.runtime')
                ->setArguments([
                    new Reference(CurrentRecipient::class), new Reference(InboxAccessCheckerInterface::class), new Reference(InboxInterface::class),
                    new Reference('router'), new Reference('security.csrf.token_manager'),
                    new Reference(RealtimePublisherInterface::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    new Reference(MercureSubscription::class, ContainerInterface::NULL_ON_INVALID_REFERENCE),
                    '%' . self::REALTIME_MODE . '%', $config['realtime']['poll_seconds'], $web['templates']['bell'],
                ]);
            $container->register(NotifyKitTwigExtension::class, NotifyKitTwigExtension::class)->addTag('twig.extension');

            // S3: the preferences page (its own roles, inheriting access_roles when preferences_roles is null).
            $this->checker($container, PreferencesAccessCheckerInterface::class, $web['security']['preferences_access_checker'], 'nowo_notify_kit.preferences_access_checker', [
                $authorization, $web['security']['preferences_roles'] ?? $web['security']['access_roles'], false,
            ]);
            $container->register(PreferencePages::class, PreferencePages::class)->setArguments([
                new Reference(TypeRegistryInterface::class), new Reference('translator'), $config['preferences']['scopes'], $web['layout_template'],
            ]);
            $container->register(PreferencesController::class, PreferencesController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->setArguments([
                    new Reference(PreferencesInterface::class), new Reference(ContextProviderInterface::class), new Reference(CurrentRecipient::class),
                    new Reference(PreferencesAccessCheckerInterface::class), new Reference(PreferencePages::class), new Reference('form.factory'), new Reference('twig'),
                    new Reference('router'), new Reference('security.csrf.token_manager'), $config['preferences']['scopes'], $web['templates']['preferences'],
                ]);
        }

        if ($adminUi) {
            $this->checker($container, OperatorAccessCheckerInterface::class, $admin['security']['access_checker'], 'nowo_notify_kit.operator_access_checker', [
                $authorization, $admin['security']['access_roles'], $admin['security']['allow_unauthenticated'],
            ]);
            $container->register(OperatorController::class, OperatorController::class)
                ->setPublic(true)
                ->addTag('controller.service_arguments')
                ->setArguments([
                    new Reference(NotificationStoreInterface::class), new Reference(Retention::class), new Reference(CurrentRecipient::class),
                    new Reference(OperatorAccessCheckerInterface::class), new Reference('form.factory'), new Reference('twig'), new Reference('router'),
                    new Reference('translator'), interface_exists(MessageBusInterface::class) ? new Reference('messenger.default_bus', ContainerInterface::NULL_ON_INVALID_REFERENCE) : null,
                    $clock, $logger, '%' . self::REALTIME_MODE . '%', $web['layout_template'], new Reference(AuditTrailInterface::class),
                ]);
        }
    }

    /**
     * Aliases an access-checker port to the host's service, or to a {@see RoleAccessChecker} of its own ($id).
     *
     * @param list<mixed> $arguments
     */
    private function checker(ContainerBuilder $container, string $port, ?string $hostService, string $id, array $arguments): void
    {
        if ($hostService === null) {
            $container->register($id, RoleAccessChecker::class)->setArguments($arguments);
        }
        $container->setAlias($port, $hostService ?? $id)->setPublic(true);
    }

    /**
     * Registers the default of a port, or aliases the port to the host's service. The port id is a public alias.
     */
    private function service(ContainerBuilder $container, string $port, ?string $hostService, Definition $default): void
    {
        if ($hostService !== null) {
            $container->setAlias($port, $hostService)->setPublic(true);

            return;
        }
        $container->setDefinition((string) $default->getClass(), $default);
        $container->setAlias($port, (string) $default->getClass())->setPublic(true);
    }
}
