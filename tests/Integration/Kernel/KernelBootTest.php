<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Symfony\Component\DependencyInjection\Container;

use function str_starts_with;

/**
 * Standard Symfony host: the bundle boots with zero configuration and registers nothing in S0.
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

    public function testEnablingTheBundleInS0StillRegistersNoService(): void
    {
        $container = $this->bootKernel(['enabled' => true, 'api' => ['prefix' => '/v-api']])->getContainer();

        self::assertTrue($container->getParameter('nowo_notify_kit.enabled'));
        self::assertSame('/v-api', $container->getParameter('nowo_notify_kit.api_prefix'));
        self::assertSame([], $this->bundleServiceIds($container));
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
