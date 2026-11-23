<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\ConflictCode;

use function array_key_last;
use function array_sum;
use function fclose;
use function proc_close;
use function proc_open;
use function sprintf;
use function stream_get_contents;
use function trim;

use const PHP_BINARY;

/**
 * Preferences under concurrency (REQ-RUNTIME-003) on a server database (MySQL 8.4 / MariaDB 11.4 / PostgreSQL 16;
 * skipped on SQLite), with two real connections:
 *
 * - a save whose expectation another connection made stale is refused ({@see PreferenceConflictException}, the
 *   endpoints' 409 + CONFLICT_001), writes nothing and leaves the connection at level 0;
 * - a save waiting on a cell another open transaction holds is transient ({@see TransientStorageFailure},
 *   CONFLICT_001 with Retry-After), and the retry after the holder commits goes through;
 * - concurrent processes toggling the same cell never lose a toggle and never store the cell twice: the final state
 *   is the initial one flipped once per save that went through.
 */
final class PreferencesConcurrencyTest extends KernelTestCase
{
    /** Bundle configuration of the test: one category with the bell and mail on by default. */
    public const CONFIG = ['types' => ['invitation_accepted' => ['category' => 'account', 'channels' => ['in_app' => true, 'mail' => true]]]];

    private const TOGGLERS = 4;

    private const TOGGLES = 15;

    protected function setUp(): void
    {
        if (!Host::onServerDatabase()) {
            self::markTestSkipped('Lock waits and concurrent writers need MySQL or PostgreSQL (NOWO_TEST_DATABASE_URL).');
        }
    }

    public function testAStaleSaveFromAnotherConnectionIsRefused(): void
    {
        [$first, $second] = $this->two();
        $person           = new RecipientRef('user', 'a');
        $key              = PreferenceKey::account('account', 'mail');
        $this->preferences($first)->save($person, new PreferenceChangeSet([new PreferenceChange($key, false, expected: PreferenceExpectation::absent())]));
        try {
            $this->preferences($second)->save($person, new PreferenceChangeSet([
                new PreferenceChange(PreferenceKey::account('*', '*'), false, expected: PreferenceExpectation::absent()),
                new PreferenceChange($key, false, Scope::Involved, PreferenceExpectation::absent()),
            ]));
            self::fail('the second connection saw no row');
        } catch (PreferenceConflictException $conflict) {
            self::assertSame('account/account/mail', $conflict->keys[0]->toString());
        }
        self::assertSame(0, $this->connection($second)->getTransactionNestingLevel());
        $matrix = $this->preferences($second)->matrixFor($person);
        self::assertTrue($matrix->masterEnabled(), 'all or nothing: the master switch was not written');
        self::assertEquals(new ChannelPreference(false), $matrix->stored($key));
    }

    public function testALockWaitIsTransientAndTheRetrySaves(): void
    {
        [$first, $second] = $this->two();
        $person           = new RecipientRef('user', 'a');
        $key              = PreferenceKey::account('account', 'mail');
        $holding          = $this->connection($first);
        $holding->beginTransaction();
        $this->preferences($first)->save($person, new PreferenceChangeSet([new PreferenceChange($key, false)]));

        $waiting = $this->connection($second);
        $waiting->executeStatement($waiting->getDatabasePlatform() instanceof AbstractMySQLPlatform ? 'SET SESSION innodb_lock_wait_timeout = 1' : "SET lock_timeout = '1s'");
        try {
            $this->preferences($second)->save($person, new PreferenceChangeSet([new PreferenceChange($key, false, Scope::Involved)]));
            self::fail('the waiter must time out');
        } catch (TransientStorageFailure $failure) {
            self::assertSame(ConflictCode::RetrySafe, ConflictCode::forFailure($failure));
            self::assertSame(1, $failure->retryAfterSeconds());
        }
        self::assertSame(0, $waiting->getTransactionNestingLevel());

        $holding->commit();
        $this->preferences($second)->save($person, new PreferenceChangeSet([new PreferenceChange($key, false, Scope::Involved, PreferenceExpectation::fromToken('0a'))]));
        self::assertEquals(new ChannelPreference(false, Scope::Involved), $this->preferences($first)->matrixFor($person)->stored($key));
    }

    public function testConcurrentTogglersNeverLoseAToggle(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(self::CONFIG), Host::databaseUrl(), $host->configure());
        $host->resetSchema($kernel);
        $kernel->shutdown();

        $processes = [];
        for ($i = 0; $i < self::TOGGLERS; ++$i) {
            // @phpstan-ignore-next-line Test harness only: bounded toggler loops, proc_close() waits for each.
            $process = proc_open([PHP_BINARY, __DIR__ . '/Support/preference-toggler.php', $this->kernelId(0), Host::databaseUrl(), (string) self::TOGGLES], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            self::assertIsResource($process);
            $processes[] = [$process, $pipes];
        }
        $saves   = [];
        $outputs = [];
        foreach ($processes as [$process, $pipes]) {
            $out       = (string) stream_get_contents($pipes[1]);
            $outputs[] = $out . stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), sprintf('toggler output: %s', $outputs[array_key_last($outputs)]));
            $saves[] = (int) trim($out);
        }

        $check = $this->bootKernel($host->bundleConfig(self::CONFIG), Host::databaseUrl(), $host->configure());
        $rows  = (int) $this->connection($check)->fetchOne('SELECT COUNT(*) FROM nowo_notify_preference');
        self::assertLessThanOrEqual(1, $rows, 'one cell, at most one row');
        self::assertGreaterThan(0, array_sum($saves));
        self::assertSame(array_sum($saves) % 2, $rows, 'every save flipped the cell exactly once: an odd count leaves it off (one row)');
    }

    /**
     * @return array{TestKernel, TestKernel}
     */
    private function two(): array
    {
        $host  = Host::standard();
        $first = $this->bootKernel($host->bundleConfig(self::CONFIG), Host::databaseUrl(), $host->configure());
        $host->resetSchema($first);

        return [$first, $this->bootKernel($host->bundleConfig(self::CONFIG), Host::databaseUrl(), $host->configure())];
    }

    private function preferences(TestKernel $kernel): PreferencesInterface
    {
        $preferences = $kernel->getContainer()->get(PreferencesInterface::class);
        self::assertInstanceOf(PreferencesInterface::class, $preferences);

        return $preferences;
    }

    private function connection(TestKernel $kernel): Connection
    {
        return Host::standard()->entityManager($kernel)->getConnection();
    }
}
