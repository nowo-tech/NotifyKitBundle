<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\CellWrite;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalPreferenceStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The MySQL statements the coverage job (SQLite) cannot run; the real-database job runs them on MySQL 8.4: the one-
 * statement dedupe claim (audit QL-L1), the byte comparison before LIMIT (audit QL-M3) and the S3 preference cell writes.
 */
final class MySqlStatementsTest extends TestCase
{
    public function testTheDedupeClaimIsOneUpsert(): void
    {
        $connection = $this->connection();
        $connection->expects(self::exactly(3))->method('executeStatement')
            ->with(self::stringContains('ON DUPLICATE KEY UPDATE expires_at = IF(expires_at <= ?, ?, expires_at)'))
            ->willReturnOnConsecutiveCalls(1, 2, 0);
        $store = $this->store($connection);
        $now   = new DateTimeImmutable('2026-11-15 10:00:00');

        self::assertTrue($store->claimDedupe('k', $now, $now), 'inserted');
        self::assertTrue($store->claimDedupe('k', $now, $now), 'expired marker taken over');
        self::assertFalse($store->claimDedupe('k', $now, $now), 'a live marker holds the key');
    }

    public function testIdentifiersAreComparedByteForByteBeforeTheLimit(): void
    {
        $connection = $this->connection();
        $connection->expects(self::exactly(2))->method('fetchAllAssociative')
            ->willReturnCallback(static function (string $sql, array $params): array {
                self::assertStringContainsString('CAST(recipient_id AS BINARY)', $sql);

                return [];
            });
        $store = $this->store($connection);

        self::assertSame(0, $store->countUnread(new RecipientRef('user', 'alice')));
        self::assertSame([], $store->recipientsOver(2, ['user', 'alice'], 10));
    }

    public function testPreferenceWritesAreOneConditionalStatementWithByteComparison(): void
    {
        $connection = $this->connection();
        $statements = [];
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql) use (&$statements): int {
            $statements[] = $sql;

            return 1;
        });
        $connection->method('fetchOne')->willReturn(1);
        $store = new DbalPreferenceStore($this->transactions($connection));
        $now   = new DateTimeImmutable('2026-11-23 10:00:00');
        $key   = PreferenceKey::account('issues', 'mail');
        $alice = new RecipientRef('user', 'alice');

        self::assertSame(CellWrite::Changed, $store->write($alice, $key, PreferenceExpectation::any(), new ChannelPreference(false), $now));
        self::assertSame(CellWrite::Changed, $store->write($alice, $key, PreferenceExpectation::absent(), new ChannelPreference(false), $now));
        self::assertSame(CellWrite::Changed, $store->write($alice, $key, PreferenceExpectation::stored(new ChannelPreference(false)), null, $now));
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE updated_at = IF(', $statements[0]);
        self::assertStringContainsString('ON DUPLICATE KEY UPDATE id = id', $statements[1]);
        self::assertStringContainsString('CAST(recipient_id AS BINARY) = CAST(? AS BINARY)', $statements[2]);
        self::assertStringContainsString('CAST(context_key AS BINARY) = CAST(? AS BINARY)', $statements[2]);
    }

    /**
     * @return Connection&MockObject
     */
    private function connection(): Connection
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new MySQL84Platform());

        return $connection;
    }

    private function store(Connection $connection): DbalNotificationStore
    {
        return new DbalNotificationStore($this->transactions($connection));
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
