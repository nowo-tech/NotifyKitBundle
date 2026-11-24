<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use Nowo\NotifyKitBundle\Application\Default\RandomNotificationIdGenerator;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Infrastructure\Identity\UuidV7NotificationIdGenerator;
use PHPUnit\Framework\TestCase;

use function hexdec;
use function microtime;
use function str_replace;
use function substr;

/**
 * The id port of new inbox rows (REQ-ARCH-002): the symfony/uid adapter the container wires and the plain-PHP
 * fallback of a hand-built notifier both mint canonical, distinct UUIDv7 values stamped with the current time.
 */
final class NotificationIdGeneratorTest extends TestCase
{
    public function testSymfonyAdapterMintsOrderedUuidV7(): void
    {
        $ids   = new UuidV7NotificationIdGenerator();
        $first = $ids->next();
        $next  = $ids->next();

        self::assertTrue(NotificationId::isValid($first->value));
        self::assertLessThan($next->value, $first->value, 'monotonic within the process');
    }

    public function testFallbackMintsDistinctUuidV7WithTheCurrentTime(): void
    {
        $before = (int) (microtime(true) * 1000);
        $ids    = new RandomNotificationIdGenerator();
        $first  = $ids->next();
        $second = $ids->next();
        $after  = (int) (microtime(true) * 1000);

        self::assertTrue(NotificationId::isValid($first->value));
        self::assertTrue(NotificationId::isValid($second->value));
        self::assertNotSame($first->value, $second->value);

        $milliseconds = (int) hexdec(substr(str_replace('-', '', $first->value), 0, 12));
        self::assertGreaterThanOrEqual($before, $milliseconds);
        self::assertLessThanOrEqual($after, $milliseconds);
    }
}
