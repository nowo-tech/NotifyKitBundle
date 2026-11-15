<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\DedupeRecord;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\NotificationRecord;
use Nowo\NotifyKitBundle\Infrastructure\Schema\SchemaChecker;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/**
 * The attribute twins hold their columns; the schema check refuses a manager that is not an ORM entity manager.
 */
final class InfrastructureDefaultsTest extends TestCase
{
    public function testTheAttributeTwinsHoldTheirColumns(): void
    {
        $at     = new DateTimeImmutable('2026-11-15 10:00:00');
        $record = new NotificationRecord('01933b1c-5d2e-7a3b-8c4d-000000000001', 'user', 'a', 'issue.assigned', 'issues', null, null, 'project', '42', 'issue:1', '/i', ['k' => 'v'], 2, $at, $at, null);
        self::assertSame([2, '42', ['k' => 'v']], [$record->repeats, $record->contextId, $record->params]);
        self::assertSame(str_repeat('a', 64), (new DedupeRecord(str_repeat('a', 64), $at))->keyHash);
    }

    public function testTheSchemaCheckNeedsAnOrmEntityManager(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManager')->willReturn($this->createStub(ObjectManager::class));

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('"default" is not a Doctrine ORM entity manager');
        (new SchemaChecker($registry))->differences();
    }
}
