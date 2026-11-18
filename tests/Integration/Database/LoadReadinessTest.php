<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;
use Nowo\TransactionDoctrine\Symfony\Conflict\TransientFailureConflictMapper;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_key_last;
use function array_map;
use function array_sum;
use function fclose;
use function preg_match;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function substr;
use function trim;

use const PHP_BINARY;

/**
 * Load readiness (Beacon spec 239 pattern, ChatKit 1.30 reference) on a server database (MySQL 8.4 / MariaDB 11.4 /
 * PostgreSQL 16, DBAL 3 and 4; skipped on SQLite, which serialises writers):
 *
 * - a repeat waiting on the row another session holds answers {@see TransientStorageFailure} (409 + Retry-After +
 *   CONFLICT_001 at an edge, REQ-RUNTIME-003 rule 6; CONFLICT_002 once the host committed part of the request), leaves the connection at level 0 and writes nothing; the retry after the holder commits coalesces;
 * - concurrent writers of the same recipient and target never lose a repeat (the count is incremented in SQL on a
 *   locked row) and never fail;
 * - dedupe claims take no gap lock: two open transactions claim two fresh keys without waiting, and concurrent writers
 *   claiming the same keys never fail and claim each key once (audit QL-L1).
 */
final class LoadReadinessTest extends KernelTestCase
{
    private const WRITERS = 4;

    private const REPEATS = 15;

    private const DEDUPE_KEYS = 60;

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
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure), 'nothing was committed: CONFLICT_001 with Retry-After');
            self::assertTrue((new TransientFailureConflictMapper())->isConflict($failure));
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

    public function testDedupeClaimsTakeNoGapLock(): void
    {
        $host  = Host::standard();
        $first = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $host->resetSchema($first);
        $second = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $a      = $this->connection($first);
        $b      = $this->connection($second);
        foreach ([$a, $b] as $connection) {
            $connection->executeStatement($connection->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
        }
        $a->executeStatement("INSERT INTO nowo_notify_dedupe (key_hash, expires_at) VALUES ('zzzz', '2030-01-01 00:00:00')");
        $now   = new DateTimeImmutable('2026-11-15 10:00:00');
        $until = new DateTimeImmutable('2026-11-16 10:00:00');

        // Two fresh keys in the same gap, claimed by two open transactions: neither waits (audit QL-L1).
        $a->beginTransaction();
        $b->beginTransaction();
        self::assertTrue($this->store($first)->claimDedupe('kkk1', $now, $until));
        self::assertTrue($this->store($second)->claimDedupe('kkk2', $now, $until));
        $a->commit();
        $b->commit();

        // A live key is refused, an expired one is taken over.
        self::assertFalse($this->store($second)->claimDedupe('kkk1', $now, $until));
        self::assertTrue($this->store($second)->claimDedupe('kkk1', new DateTimeImmutable('2026-11-17 10:00:00'), new DateTimeImmutable('2026-11-18 10:00:00')));
        self::assertSame('2026-11-18 10:00:00', substr((string) $a->fetchOne("SELECT expires_at FROM nowo_notify_dedupe WHERE key_hash = 'kkk1'"), 0, 19));
    }

    public function testConcurrentDedupeClaimsNeverFailAndOneWins(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(), Host::databaseUrl(), $host->configure());
        $host->resetSchema($kernel);
        $kernel->shutdown();

        $processes = [];
        for ($i = 0; $i < self::WRITERS; ++$i) {
            // @phpstan-ignore-next-line Test harness only: bounded writer loops, proc_close() waits for each.
            $process = proc_open([PHP_BINARY, __DIR__ . '/Support/dedupe-writer.php', $this->kernelId(0), Host::databaseUrl(), (string) $i, (string) self::DEDUPE_KEYS], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $claimed = 0;
        foreach ($processes as [$process, $pipes]) {
            $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), sprintf('writer output: %s', $output));
            self::assertSame(1, preg_match('/^claimed=(\d+)$/D', trim($output), $match), $output);
            $claimed += (int) $match[1];
        }
        self::assertSame(self::DEDUPE_KEYS, $claimed, 'every key is claimed exactly once');
    }

    private function store(TestKernel $kernel): NotificationStoreInterface
    {
        $container = $kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $store = $container->get(NotificationStoreInterface::class);
        self::assertInstanceOf(NotificationStoreInterface::class, $store);

        return $store;
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
