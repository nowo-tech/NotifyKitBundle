<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Symfony\Component\DependencyInjection\Container;

use function str_starts_with;

/**
 * Standard Symfony host: the bundle boots with zero configuration and registers nothing while disabled; enabled, it
 * needs DoctrineBundle and exposes its public ports.
 */
final class KernelBootTest extends KernelTestCase
{
    public function testTheBundleBootsWithZeroConfigurationAndIsDisabled(): void
    {
        $container = $this->bootKernel()->getContainer();

        self::assertFalse($container->getParameter('nowo_notify_kit.enabled'));
        self::assertSame('/api', $container->getParameter('nowo_notify_kit.api_prefix'));
        self::assertSame('nowo_', $container->getParameter('nowo_notify_kit.table_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
    }

    public function testEnabledWithoutDoctrineIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('DoctrineBundle is not');
        $this->bootKernel(['enabled' => true]);
    }

    public function testEnabledExposesThePublicPorts(): void
    {
        $container = $this->bootKernel(['enabled' => true, 'api' => ['prefix' => '/v-api']], Host::databaseUrl(), Host::standard()->configure())->getContainer();

        self::assertSame('/v-api', $container->getParameter('nowo_notify_kit.api_prefix'));
        foreach ([NotifierInterface::class, InboxInterface::class, TypeRegistryInterface::class, RecipientAccessCheckerInterface::class] as $port) {
            self::assertInstanceOf($port, $container->get($port));
        }
        self::assertFalse($container->has(RecipientResolverInterface::class), 'no resolver without recipient.class or recipient.resolver');
        $types = $container->get(TypeRegistryInterface::class);
        self::assertInstanceOf(TypeRegistryInterface::class, $types);
        self::assertSame(['in_app'], $types->channels());
    }

    /**
     * @return list<string>
     */
    private function bundleServiceIds(object $container): array
    {
        self::assertInstanceOf(Container::class, $container);
        $ids = [];
        foreach ($container->getServiceIds() as $id) {
            $bundleService = str_starts_with($id, 'nowo_notify_kit') || str_starts_with($id, 'Nowo\\NotifyKitBundle\\');
            if ($bundleService && !str_starts_with($id, 'Nowo\\NotifyKitBundle\\Tests\\')) {
                $ids[] = $id;
            }
        }

        return $ids;
    }
}
