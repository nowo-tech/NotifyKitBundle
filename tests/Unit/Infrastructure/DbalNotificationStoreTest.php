<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The store on a misconfigured, case-insensitive table (`COLLATE NOCASE`): the database matches `usera` for `UserA`,
 * the PHP `===` re-check never lets such a row through (audit LQ). Plus damaged rows and the connection lookup.
 */
final class DbalNotificationStoreTest extends TestCase
{
    private Connection $connection;

    private DbalNotificationStore $store;

    protected function setUp(): void
    {
        $this->connection = Sqlite::connection(true);
        $this->store      = new DbalNotificationStore($this->transactions($this->connection));
    }

    public function testACaseInsensitiveMatchIsNeverCoalescedReadTrimmedOrForgotten(): void
    {
        $now   = new DateTimeImmutable('2026-11-15 10:00:00', new DateTimeZone('UTC'));
        $upper = new RecipientRef('user', 'UserA');
        $lower = new RecipientRef('user', 'usera');
        $this->store->record($this->draft('01933b1c-5d2e-7a3b-8c4d-000000000001', $upper, new ContextRef('project', 'P1'), $now), $now);

        $outcome = $this->store->record($this->draft('01933b1c-5d2e-7a3b-8c4d-000000000002', $lower, new ContextRef('project', 'P1'), $now), $now->modify('-10 minutes'));
        self::assertFalse($outcome->coalesced, 'the database matched UserA; PHP refused it');
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_notify_notification'));

        self::assertSame(1, $this->store->countUnread($lower));
        self::assertCount(1, $this->store->ofRecipient($lower, 10));
        self::assertSame('usera', $this->store->ofRecipient($lower, 10)[0]->recipient()->id);
        self::assertSame(1, $this->store->trimRecipient('user', 'usera', 0, 10));
        self::assertSame(1, $this->store->countUnread($upper));
        $batch = $this->store->forgetContext(new ContextRef('project', 'p1'), null, 1);
        self::assertSame(0, $batch->deleted, 'p1 is not P1');
        self::assertSame('01933b1c-5d2e-7a3b-8c4d-000000000001', $batch->cursor, 'the walk goes on past a refused row');
        self::assertNull($this->store->forgetContext(new ContextRef('project', 'p1'), $batch->cursor, 1)->cursor);
        self::assertSame(1, $this->store->forgetContext(new ContextRef('project', 'P1'), null, 10)->deleted);
    }

    public function testDamagedRowsReadSafely(): void
    {
        $this->connection->executeStatement("INSERT INTO nowo_notify_notification VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000003', 'user', 'a', 't', 'c', 'user', NULL, 'project', NULL, 'x', '/x', '{\"k\":1,\"ok\":\"v\",\"0\":\"z\"}', 'many', '2026-11-15 10:00:00', '2026-11-15T10:00:00+00:00', '2026-11-15 11:00:00')");
        $this->connection->executeStatement("INSERT INTO nowo_notify_notification VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000004', 'user', 'a', 't', 'c', NULL, NULL, NULL, NULL, 'y', '/y', '\"text\"', 2, '2026-11-15 10:00:00', '2026-11-15 09:00:00', NULL)");
        $this->connection->executeStatement("INSERT INTO nowo_notify_notification VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000005', 'user', 'a', 't', 'c', NULL, NULL, NULL, NULL, 'z', '/z', NULL, 1, '2026-11-15 10:00:00', '2026-11-15 08:00:00', NULL)");

        [$first, $second, $third] = $this->store->ofRecipient(new RecipientRef('user', 'a'), 10);
        self::assertSame(['ok' => 'v'], $first->params());
        self::assertSame(1, $first->repeats());
        self::assertNull($first->actor());
        self::assertNull($first->context());
        self::assertSame('2026-11-15 11:00:00', $first->readAt()?->format('Y-m-d H:i:s'));
        self::assertSame('c', $first->category());
        self::assertSame('/x', $first->url());
        self::assertSame('2026-11-15', $first->firstCreatedAt()->format('Y-m-d'));
        self::assertSame([], $second->params());
        self::assertSame(2, $second->repeats());
        self::assertSame([], $third->params());
        self::assertSame(0, $this->store->deleteExpiredDedupe(new DateTimeImmutable('2026-11-15'), 10));
        self::assertSame([], $this->store->recipientsOver(5, ['user', 'a'], 10));
    }

    public function testTheConnectionIsTheEntityManagersOrTheDbalConnectionOfThatName(): void
    {
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($this->connection);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn(['default' => 'doctrine.orm.default_entity_manager']);
        $registry->method('getManager')->willReturn($manager);
        self::assertSame($this->connection, (new NotifyKitTransactions($registry, new AfterCommitQueue()))->connection());

        $odd = $this->createStub(ManagerRegistry::class);
        $odd->method('getManagerNames')->willReturn(['default' => 'x']);
        $odd->method('getManager')->willReturn($this->createStub(ObjectManager::class));
        $odd->method('getConnectionNames')->willReturn(['default' => 'y']);
        $odd->method('getConnection')->willReturn(new stdClass());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Doctrine entity manager or DBAL connection named "default"');
        (new NotifyKitTransactions($odd, new AfterCommitQueue()))->connection();
    }

    private function draft(string $id, RecipientRef $recipient, ?ContextRef $context, DateTimeImmutable $at): Notification
    {
        return new Notification(new NotificationId($id), $recipient, 'issue.assigned', 'issues', null, $context, 'issue:1', '/i', [], 1, $at, $at);
    }

    private function transactions(Connection $connection): NotifyKitTransactions
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($connection);

        return new NotifyKitTransactions($registry, new AfterCommitQueue());
    }
}
