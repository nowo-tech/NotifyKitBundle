<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

use function array_key_last;
use function array_map;
use function array_sum;
use function fclose;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;

use const PHP_BINARY;

/**
 * Load readiness (Beacon spec 239 pattern, ChatKit 1.30 reference) on a server database (MySQL 8.4 / PostgreSQL 17,
 * DBAL 3 and 4; skipped on SQLite, which serialises writers):
 *
 * - a repeat waiting on the row another session holds answers {@see TransientStorageFailure} (503 + Retry-After at an
 *   edge), leaves the connection at level 0 and writes nothing; the retry after the holder commits coalesces;
 * - concurrent writers of the same recipient and target never lose a repeat (the count is incremented in SQL on a
 *   locked row) and never fail.
 */
final class LoadReadinessTest extends KernelTestCase
{
    private const WRITERS = 4;

    private const REPEATS = 15;

    protected function setUp(): void
    {
        if (!Host::onServerDatabase()) {
            self::markTestSkipped('Lock waits and concurrent writers need MySQL or PostgreSQL (NOWO_TEST_DATABASE_URL).');
        }
    }

    public function testALockWaitIsTransientAndTheRetryCoalesces(): void
    {
        $host   = Host::standard();
        $holder = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $host->resetSchema($holder);
        $waiter = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $a      = new RecipientRef('user', 'a');
        $repeat = static fn (): NotificationRequest => new NotificationRequest('invitation_accepted', [$a], null, null, 'invite:1', '/a');

        self::assertSame(1, $this->notifier($holder)->notify($repeat())->written);
        $holding = $this->connection($holder);
        $holding->beginTransaction();
        self::assertSame(1, $this->notifier($holder)->notify($repeat())->coalesced, 'the holder locks the unread row');

        $waiting = $this->connection($waiter);
        $waiting->executeStatement($waiting->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
        try {
            $this->notifier($waiter)->notify($repeat());
            self::fail('the waiter must time out');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertSame(0, $waiting->getTransactionNestingLevel(), 'no transaction is left open in the worker');

        $holding->commit();
        self::assertSame(1, $this->notifier($waiter)->notify($repeat())->coalesced);
        self::assertSame(3, (int) $waiting->fetchOne('SELECT repeats FROM nowo_notify_notification'));
    }

    public function testConcurrentWritersNeverLoseARepeat(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $host->resetSchema($kernel);
        $kernel->shutdown();

        $processes = [];
        for ($i = 0; $i < self::WRITERS; ++$i) {
            // @phpstan-ignore-next-line Test harness only: bounded writer loops, proc_close() waits for each.
            $process = proc_open([PHP_BINARY, __DIR__ . '/Support/concurrent-writer.php', $this->kernelId(0), Host::databaseUrl(), (string) self::REPEATS], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $outputs[] = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), sprintf('writer output: %s', $outputs[array_key_last($outputs)]));
        }

        $check = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $rows  = $this->connection($check)->fetchFirstColumn('SELECT repeats FROM nowo_notify_notification');
        self::assertSame(self::WRITERS * self::REPEATS, array_sum(array_map('intval', $rows)), 'every repeat is counted');
    }

    private function notifier(TestKernel $kernel): NotifierInterface
    {
        $notifier = $kernel->getContainer()->get(NotifierInterface::class);
        self::assertInstanceOf(NotifierInterface::class, $notifier);

        return $notifier;
    }

    private function connection(TestKernel $kernel): Connection
    {
        return Host::standard()->entityManager($kernel)->getConnection();
    }
}
