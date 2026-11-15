<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Application\Retention\RetentionReport;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Infrastructure\Console\PruneNotificationsCommand;
use Nowo\NotifyKitBundle\Infrastructure\Messenger\PruneNotificationsHandler;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use Nowo\NotifyKitBundle\Tests\Fixtures\SteppingClock;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

use function sprintf;

/**
 * Retention in bounded runs: every phase stops at the time budget and hands back a keyset cursor; the handler
 * dispatches the remainder, or finishes it without a bus; the command runs to the end or only enqueues.
 */
final class RetentionTest extends TestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        $this->connection = Sqlite::connection();
        // a: 3 old read rows; b, c, d: 3 rows each (cap 1 → 2 to trim each); 3 expired dedupe keys.
        $n = 0;
        foreach (['a' => 3, 'b' => 3, 'c' => 3, 'd' => 3] as $recipient => $rows) {
            for ($i = 0; $i < $rows; ++$i) {
                $read = $recipient === 'a' ? "'2026-01-01 00:00:00'" : 'NULL';
                $this->connection->executeStatement(sprintf("INSERT INTO nowo_notify_notification VALUES ('01933b1c-5d2e-7a3b-8c4d-%012d', 'user', '%s', 't', 'c', NULL, NULL, NULL, NULL, 'x%d', '/x', '[]', 1, '2026-01-01 00:00:00', '2026-01-01 00:%02d:00', %s)", ++$n, $recipient, $n, $n, $read));
            }
        }
        for ($i = 0; $i < 3; ++$i) {
            $this->connection->executeStatement(sprintf("INSERT INTO nowo_notify_dedupe VALUES ('%064d', '2026-01-01 00:00:00')", $i));
        }
    }

    public function testEveryPhaseStopsAtTheBudgetAndResumes(): void
    {
        $retention = $this->retention(new SteppingClock('2026-11-15 10:00:00', 100));

        $report = $retention->run();
        self::assertSame([2, 0, 0], [$report->read, $report->trimmed, $report->dedupe]);
        self::assertEquals(new PruneNotificationsMessage('read'), $report->remainder);

        $report = $retention->run($this->remainder($report));
        self::assertSame(1, $report->read);
        self::assertSame(2, $report->trimmed, 'b trimmed, then out of time');
        self::assertEquals(new PruneNotificationsMessage('trim', 'user', 'b'), $report->remainder);

        $report = $retention->run($this->remainder($report));
        self::assertSame(2, $report->trimmed);
        self::assertEquals(new PruneNotificationsMessage('trim', 'user', 'c'), $report->remainder);

        $report = $retention->run($this->remainder($report));
        self::assertEquals(new PruneNotificationsMessage('trim', 'user', 'd'), $report->remainder);

        $report = $retention->run($this->remainder($report));
        self::assertSame([0, 2], [$report->trimmed, $report->dedupe]);
        self::assertEquals(new PruneNotificationsMessage('dedupe'), $report->remainder);

        $report = $retention->run($this->remainder($report));
        self::assertSame(1, $report->dedupe);
        self::assertNull($report->remainder);
        self::assertSame(3, (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_notify_notification'));
    }

    public function testAnUnknownPhaseStartsFromTheBeginningAndARunWithoutClockFinishes(): void
    {
        $report = $this->retention(null, 100)->run(new PruneNotificationsMessage('later'));

        self::assertSame([3, 6, 3], [$report->read, $report->trimmed, $report->dedupe]);
        self::assertNull($report->remainder);
    }

    public function testTheHandlerDispatchesTheRemainderOrFinishesWithoutABus(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(new PruneNotificationsMessage('read'))
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $report = (new PruneNotificationsHandler($this->retention(new SteppingClock('2026-11-15 10:00:00', 100)), $bus))(new PruneNotificationsMessage());
        self::assertSame(2, $report->read);

        $report = (new PruneNotificationsHandler($this->retention(new SteppingClock('2026-11-15 10:00:00', 100))))(new PruneNotificationsMessage());
        self::assertSame([1, 6, 3], [$report->read, $report->trimmed, $report->dedupe]);
        self::assertNull($report->remainder);
    }

    public function testTheCommandRunsToTheEndOrEnqueues(): void
    {
        $tester = new CommandTester(new PruneNotificationsCommand($this->retention(new SteppingClock('2026-11-15 10:00:00', 100))));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 3 read notification(s), 6 beyond the per-recipient cap and 3 expired dedupe key(s).', $tester->getDisplay());

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')->with(new PruneNotificationsMessage())
            ->willReturnCallback(static fn (object $message): Envelope => new Envelope($message));
        $tester = new CommandTester(new PruneNotificationsCommand($this->retention(null), $bus));
        self::assertSame(0, $tester->execute(['--async' => true]));
        self::assertStringContainsString('Retention enqueued.', $tester->getDisplay());

        $this->expectException(LogicException::class);
        (new CommandTester(new PruneNotificationsCommand($this->retention(null))))->execute(['--async' => true]);
    }

    private function remainder(RetentionReport $report): PruneNotificationsMessage
    {
        self::assertNotNull($report->remainder);

        return $report->remainder;
    }

    private function retention(?SteppingClock $clock, int $batch = 2): Retention
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($this->connection);
        $transactions = new NotifyKitTransactions($registry, new AfterCommitQueue());

        return new Retention(new DbalNotificationStore($transactions), $transactions, 90, 1, $batch, 1, $clock);
    }
}
