<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection;

use LogicException;
use Nowo\NotifyKitBundle\Application\Default\PersonalOnlyAccessChecker;
use Nowo\NotifyKitBundle\Application\Inbox;
use Nowo\NotifyKitBundle\Application\Notifier;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Console\PruneNotificationsCommand;
use Nowo\NotifyKitBundle\Infrastructure\Console\SchemaCheckCommand;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;
use Nowo\NotifyKitBundle\Infrastructure\Messenger\PruneNotificationsHandler;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Infrastructure\Recipient\DoctrineRecipientResolver;
use Nowo\NotifyKitBundle\Infrastructure\Schema\SchemaChecker;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\Messenger\MessageBusInterface;

use function class_exists;
use function interface_exists;
use function is_array;
use function sprintf;

/**
 * Loads `nowo_notify_kit`: validates the strict configuration tree, publishes it as container parameters and, while
 * `enabled` is true, registers the S1 core (contract 221-B §4–§5, §6.1, §6.6, §9): the type registry, the DBAL store
 * with its after-commit middleware and transaction runner, the notifier, the inbox maintenance service, the access and
 * recipient defaults, the Doctrine metadata listener, retention (command + message handler) and the schema check.
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

    /**
     * The extension alias, `nowo_notify_kit`.
     */
    public function getAlias(): string
    {
        return self::ALIAS;
    }

    /**
     * Standard hosts (`doctrine.auto_mapping: true`, the default): registers the XML mapping (or the attribute twins)
     * on `doctrine.entity_manager` when the host configures the ORM and the bundle is enabled.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('doctrine')) {
            return;
        }
        $config = $this->processConfiguration(new Configuration(), $container->getExtensionConfig(self::ALIAS));
        if (!$config['enabled'] || !$config['doctrine']['auto_mapping']) {
            return;
        }
        $hasOrm = false;
        foreach ($container->getExtensionConfig('doctrine') as $doctrine) {
            $hasOrm = $hasOrm || (is_array($doctrine) && isset($doctrine['orm']));
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
        if (!$config['enabled']) {
            return;
        }

        $this->registerCore($container, $config);
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
        $transactions = new Reference(self::TRANSACTIONS);
        $container->register(DbalNotificationStore::class, DbalNotificationStore::class)->setArguments([$transactions, $doctrine['table_prefix']]);
        $container->setAlias(NotificationStoreInterface::class, DbalNotificationStore::class);
        $store = new Reference(NotificationStoreInterface::class);

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

        $container->register(Notifier::class, Notifier::class)->setArguments([
            $store, $transactions, new Reference(TypeRegistryInterface::class), new Reference(RecipientAccessCheckerInterface::class),
            new Reference('event_dispatcher', ContainerInterface::IGNORE_ON_INVALID_REFERENCE),
            $config['inbox']['repeat_window_seconds'], $config['fan_out']['max_recipients'], $clock, $logger,
        ]);
        $container->setAlias(NotifierInterface::class, Notifier::class)->setPublic(true);
        $container->register(Inbox::class, Inbox::class)->setArguments([$store, $transactions, $retention['batch_size']]);
        $container->setAlias(InboxInterface::class, Inbox::class)->setPublic(true);

        $container->register(Retention::class, Retention::class)->setArguments([
            $store, $transactions, $retention['read_days'], $retention['keep_per_recipient'], $retention['batch_size'], $config['messenger']['max_seconds'], $clock,
        ]);
        if ($messenger) {
            $container->register(PruneNotificationsHandler::class, PruneNotificationsHandler::class)
                ->setArguments([new Reference(Retention::class), $bus, $logger])
                ->addTag('messenger.message_handler', ['handles' => PruneNotificationsMessage::class]);
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
