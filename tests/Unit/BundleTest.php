<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit;

use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use PHPUnit\Framework\TestCase;

final class BundleTest extends TestCase
{
    public function testTheExtensionAliasIsTheConfigurationRootAndIsBuiltOnce(): void
    {
        $bundle    = new NowoNotifyKitBundle();
        $extension = $bundle->getContainerExtension();

        self::assertInstanceOf(NowoNotifyKitExtension::class, $extension);
        self::assertSame('nowo_notify_kit', $extension->getAlias());
        self::assertSame($extension, $bundle->getContainerExtension());
    }
}
