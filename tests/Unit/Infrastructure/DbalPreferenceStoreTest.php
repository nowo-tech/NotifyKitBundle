<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Domain\CellWrite;
use Nowo\NotifyKitBundle\Domain\StoredPreference;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\PreferenceRecord;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalPreferenceStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use PHPUnit\Framework\TestCase;

/**
 * The preference store on SQLite: every conditional write of the table in {@see DbalPreferenceStore} (changed,
 * unchanged, conflict), reads by one and many recipients, the `===` re-check on a case-insensitive table (audit LQ),
 * damaged rows, and the override reset that keeps the switch.
 */
final class DbalPreferenceStoreTest extends TestCase
{
    private Connection $connection;

    private DbalPreferenceStore $store;

    private RecipientRef $alice;

    private DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->connection = Sqlite::connection(true);
        $this->store      = new DbalPreferenceStore($this->transactions($this->connection));
        $this->alice      = new RecipientRef('user', 'Alice');
        $this->now        = new DateTimeImmutable('2026-11-23 10:00:00');
    }

    public function testConditionalWrites(): void
    {
        $key = PreferenceKey::account('issues', 'mail');
        $off = new ChannelPreference(false);
        $on  = new ChannelPreference(true, Scope::Involved);

        self::assertSame(CellWrite::Unchanged, $this->write($key, PreferenceExpectation::absent(), null), 'absent → absent');
        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::absent(), $off), 'absent → row');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::absent(), $on), 'a row exists');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::absent(), null), 'expected none, a row exists');
        self::assertSame(CellWrite::Unchanged, $this->write($key, PreferenceExpectation::stored($off), $off), 'row → same row');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::stored($on), $on), 'expected another row');
        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::stored($off), $on), 'row → row');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::stored($off), $on), 'stale update');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::stored($off), null), 'stale delete');
        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::stored($on), null), 'row → none');
        self::assertSame(CellWrite::Conflict, $this->write($key, PreferenceExpectation::stored($on), $off), 'the row is gone');

        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::any(), $off), 'upsert inserts');
        self::assertSame(CellWrite::Unchanged, $this->write($key, PreferenceExpectation::any(), $off), 'upsert of the same values');
        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::any(), $on), 'upsert updates');
        self::assertSame(CellWrite::Changed, $this->write($key, PreferenceExpectation::any(), null), 'unconditional delete');
        self::assertSame(CellWrite::Unchanged, $this->write($key, PreferenceExpectation::any(), null));
    }

    public function testReadsAreExactAndSkipDamagedRows(): void
    {
        $this->write(PreferenceKey::account('issues', 'mail'), PreferenceExpectation::absent(), new ChannelPreference(false));
        $this->connection->executeStatement("INSERT INTO nowo_notify_preference VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000001', 'user', 'alice', '', 'issues', 'push', 1, NULL, '2026-11-23 10:00:00')");
        $this->connection->executeStatement("INSERT INTO nowo_notify_preference VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000002', 'user', 'Alice', 'project:42', 'issues', 'push', 1, NULL, '2026-11-23 10:00:00')");
        $this->connection->executeStatement("INSERT INTO nowo_notify_preference VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000003', 'user', 'Alice', 'broken', 'issues', 'push', 1, NULL, '2026-11-23 10:00:00')");
        $this->connection->executeStatement("INSERT INTO nowo_notify_preference VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000005', 'user', 'ALICE', '', 'issues', '*', 0, NULL, '2026-11-23 10:00:00')");
        $this->connection->executeStatement("INSERT INTO nowo_notify_preference VALUES ('01933b1c-5d2e-7a3b-8c4d-000000000004', 'user', 'Alice', 'project:7', 'issues', 'push', 1, 'sometimes', '2026-11-23 10:00:00')");

        $all = $this->store->rows($this->alice, null);
        self::assertCount(2, $all, 'alice (lower case) and the damaged rows are skipped');
        self::assertSame(Scope::All, $all[1]->scope, 'a NULL scope reads as all');
        self::assertCount(1, $this->store->rows($this->alice, ''));
        self::assertCount(2, $this->store->rows($this->alice, 'project:42'));
        self::assertCount(1, $this->store->rows($this->alice, 'Project:42'));

        $many = $this->store->rowsOfMany([$this->alice, new RecipientRef('user', 'alice'), new RecipientRef('team', 'x')], ['', 'project:42'], ['issues', '*'], ['push', '*']);
        self::assertCount(1, $many['user:Alice'], 'project:42 / push');
        self::assertCount(1, $many['user:alice']);
        self::assertArrayNotHasKey('team:x', $many);
    }

    public function testDeleteOverridesKeepsTheSwitchAndOtherPeople(): void
    {
        $project = 'project:42';
        $this->write(PreferenceKey::stored($project, '*', '*'), PreferenceExpectation::absent(), new ChannelPreference(false));
        $this->write(PreferenceKey::stored($project, 'issues', 'mail'), PreferenceExpectation::absent(), new ChannelPreference(false));
        $this->store->write(new RecipientRef('user', 'bob'), PreferenceKey::stored($project, 'issues', 'mail'), PreferenceExpectation::absent(), new ChannelPreference(false), $this->now);

        $deleted = $this->store->deleteOverrides($this->alice, $project);
        self::assertCount(1, $deleted);
        self::assertSame('project:42/issues/mail', $deleted[0]->toString());
        self::assertSame([], $this->store->deleteOverrides($this->alice, $project));
        self::assertSame(2, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_notify_preference'));
    }

    public function testMappedClasses(): void
    {
        $row = new StoredPreference('id', 'user', 'a', '', 'issues', 'mail', false, null, $this->now);
        self::assertSame(['id', ['user', 'a'], ['', 'issues', 'mail'], [false, null]], [$row->id(), $row->recipient(), $row->cell(), $row->choice()]);
        self::assertSame($this->now, $row->updatedAt());
        $record = new PreferenceRecord('id', 'user', 'a', '', 'issues', 'mail', true, 'all', $this->now);
        self::assertTrue($record->enabled);
    }

    /**
     * @phpstan-impure
     */
    private function write(PreferenceKey $key, PreferenceExpectation $expected, ?ChannelPreference $next): CellWrite
    {
        return $this->store->write($this->alice, $key, $expected, $next, $this->now);
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
