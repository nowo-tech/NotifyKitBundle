<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\NotifyKitBundle\Application\Notifier;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\RequireDoctrinePass;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Nowo\NotifyKitBundle\Domain\NotificationIdGeneratorInterface;
use Nowo\NotifyKitBundle\Infrastructure\Identity\UuidV7NotificationIdGenerator;
use Nowo\NotifyKitBundle\Infrastructure\Messenger\PruneNotificationsHandler;
use Nowo\NotifyKitBundle\Infrastructure\Recipient\DoctrineRecipientResolver;
use Nowo\NotifyKitBundle\Presentation\Routing\NotifyKitRouteLoader;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

use function array_filter;
use function array_keys;
use function array_values;
use function str_starts_with;

final class ExtensionTest extends TestCase
{
    public function testDefaultsPublishTheParametersAndRegisterOnlyTheEmptyRouteLoader(): void
    {
        $container = new ContainerBuilder();
        (new NowoNotifyKitExtension())->load([], $container);

        self::assertFalse($container->getParameter('nowo_notify_kit.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_notify_kit.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_notify_kit.table_prefix'));
        self::assertIsArray($container->getParameter('nowo_notify_kit.config'));
        self::assertSame([NotifyKitRouteLoader::class], array_values(array_filter(
            array_keys($container->getDefinitions()),
            static fn (string $id): bool => !str_starts_with($id, 'service_container'),
        )), 'only the route loader, which adds no route while disabled');
        self::assertSame([false, '/notifications', false, '/admin/notifications', '%kernel.environment%', '/account/notifications'], $container->getDefinition(NotifyKitRouteLoader::class)->getArguments());
    }

    public function testEnabledRegistersTheCore(): void
    {
        $container = new ContainerBuilder();
        (new NowoNotifyKitExtension())->load([
            ['enabled' => true, 'api' => ['enabled' => true, 'prefix' => '/api/v-host'], 'doctrine' => ['table_prefix' => 'app_', 'transaction_runner' => 'app.runner'], 'recipient' => ['class' => User::class]],
        ], $container);

        self::assertTrue($container->getParameter('nowo_notify_kit.enabled'));
        self::assertSame('/api/v-host', $container->getParameter('nowo_notify_kit.api_prefix'));
        self::assertSame('app_', $container->getParameter('nowo_notify_kit.table_prefix'));
        self::assertTrue($container->hasDefinition(Notifier::class));
        self::assertSame(Notifier::class, (string) $container->getAlias(NotifierInterface::class));
        self::assertSame(UuidV7NotificationIdGenerator::class, (string) $container->getAlias(NotificationIdGeneratorInterface::class), 'new rows get symfony/uid UUIDv7 ids (REQ-ARCH-002 port)');
        self::assertSame(DoctrineRecipientResolver::class, (string) $container->getAlias(RecipientResolverInterface::class));
        self::assertTrue($container->hasDefinition(PruneNotificationsHandler::class));
        $runner = $container->getDefinition(NowoNotifyKitExtension::TRANSACTIONS)->getArgument(3);
        self::assertInstanceOf(Reference::class, $runner);
        self::assertSame('app.runner', (string) $runner);
    }

    public function testAHostResolverIsAliased(): void
    {
        $container = new ContainerBuilder();
        (new NowoNotifyKitExtension())->load([['enabled' => true, 'recipient' => ['resolver' => 'app.recipients']]], $container);

        self::assertSame('app.recipients', (string) $container->getAlias(RecipientResolverInterface::class));
    }

    public function testAnUnknownRecipientClassIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('nowo_notify_kit.recipient.class "App\Missing" does not exist.');

        (new NowoNotifyKitExtension())->load([['enabled' => true, 'recipient' => ['class' => 'App\Missing']]], new ContainerBuilder());
    }

    public function testPrependRegistersTheMappingOnlyForAnEnabledOrmHost(): void
    {
        self::assertSame([], $this->prepended([], true), 'disabled');
        self::assertSame([], $this->prepended(['enabled' => true, 'doctrine' => ['auto_mapping' => false]], true), 'DDD host maps it itself');
        self::assertSame([], $this->prepended(['enabled' => true], false), 'DBAL only');
        self::assertSame([], (static function (): array {
            $container = new ContainerBuilder();
            (new NowoNotifyKitExtension())->prepend($container);

            return $container->getExtensionConfig('doctrine');
        })(), 'no DoctrineBundle');

        $xml = $this->prepended(['enabled' => true], true);
        self::assertSame('xml', $xml[0]['orm']['entity_managers']['default']['mappings']['NowoNotifyKitBundle']['type']);
        $attribute = $this->prepended(['enabled' => true, 'doctrine' => ['mapping' => 'attribute', 'entity_manager' => 'app']], true);
        self::assertSame('Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity', $attribute[0]['orm']['entity_managers']['app']['mappings']['NowoNotifyKitBundle']['prefix']);
    }

    public function testTheEnabledBundleNeedsDoctrine(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_notify_kit.enabled', false);
        (new RequireDoctrinePass())->process($container);

        $container->setParameter('nowo_notify_kit.enabled', true);
        $this->expectException(LogicException::class);
        (new RequireDoctrinePass())->process($container);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return list<array<string, mixed>>
     */
    private function prepended(array $config, bool $orm): array
    {
        $container = new ContainerBuilder();
        $container->registerExtension(new class extends Extension {
            public function load(array $configs, ContainerBuilder $container): void
            {
            }

            public function getAlias(): string
            {
                return 'doctrine';
            }
        });
        $container->prependExtensionConfig('doctrine', $orm ? ['dbal' => [], 'orm' => []] : ['dbal' => []]);
        $container->prependExtensionConfig('nowo_notify_kit', $config);
        (new NowoNotifyKitExtension())->prepend($container);
        $all = $container->getExtensionConfig('doctrine');

        /** @var list<array<string, mixed>> $added */
        $added = [];
        foreach ($all as $entry) {
            if (isset($entry['orm']['entity_managers'])) {
                $added[] = $entry;
            }
        }

        return $added;
    }
}
