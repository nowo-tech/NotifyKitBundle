<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Persistence;

use Closure;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\TransactionContract\TransactionRunnerInterface;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;

use function array_key_exists;
use function is_object;
use function sprintf;

/**
 * The bundle's units of work (contract §5.3, load readiness): DBAL only, on the connection of
 * `doctrine.entity_manager`, through a `nowo-tech/transaction-contract` runner. No EntityManager is ever flushed,
 * cleared or reset: the default runner is `DoctrineTransactionRunner::forConnection()` (one attempt), built per call on
 * the current connection; `doctrine.transaction_runner` plugs a retrying adapter that works on that connection.
 *
 * - Outside a transaction: the runner opens and commits one; a deadlock, lock wait timeout or serialization failure
 *   surfaces as {@see TransientStorageFailure} with the connection back at level 0 (answer 503 + Retry-After).
 * - Inside a host transaction on the same connection: the work joins it (nested level; it disappears with a rollback)
 *   and the after-commit work waits for the outermost commit ({@see AfterCommitQueue}); a rollback, or a rollback to a
 *   savepoint that contains it, drops it. After that commit it runs at the next safe point: the next unit of work of
 *   the bundle on a connection at level 0, or the request / command / message boundary at the latest.
 *
 * Stateless (worker mode): the connection is resolved per call, so a connection the registry replaced is picked up.
 */
final readonly class NotifyKitTransactions
{
    /**
     * @param string $entityManager `doctrine.entity_manager` (falls back to a DBAL connection of that name)
     * @param TransactionRunnerInterface|null $runner `doctrine.transaction_runner`; null = one attempt per call
     */
    public function __construct(
        private ManagerRegistry $registry,
        private AfterCommitQueue $afterCommit,
        private string $entityManager = 'default',
        private ?TransactionRunnerInterface $runner = null,
    ) {
    }

    /**
     * The DBAL connection the bundle tables live on.
     */
    public function connection(): Connection
    {
        if (array_key_exists($this->entityManager, $this->registry->getManagerNames())) {
            $manager = $this->registry->getManager($this->entityManager);
            if ($manager instanceof EntityManagerInterface) {
                return $manager->getConnection();
            }
        }
        if (array_key_exists($this->entityManager, $this->registry->getConnectionNames())) {
            $connection = $this->registry->getConnection($this->entityManager);
            if ($connection instanceof Connection) {
                return $connection;
            }
        }

        throw new LogicException(sprintf('nowo_notify_kit: no Doctrine entity manager or DBAL connection named "%s" (doctrine.entity_manager).', $this->entityManager));
    }

    /**
     * Runs $work in one transaction, then $afterCommit with its result once the outermost transaction committed.
     * A failure of $afterCommit is logged, never thrown (the data is committed).
     *
     * @template T
     *
     * @param Closure(): T $work replayable: a retrying runner may run it again
     * @param (Closure(T): void)|null $afterCommit
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (nothing was written)
     * @throws DbalException any other storage failure
     *
     * @return T
     */
    public function run(Closure $work, ?Closure $afterCommit = null): mixed
    {
        $connection = $this->connection();
        $this->drainAtLevelZero($connection);
        $result = ($this->runner ?? DoctrineTransactionRunner::forConnection($connection))->run($work);
        if ($afterCommit instanceof Closure) {
            $then = static function () use ($afterCommit, $result): void {
                $afterCommit($result);
            };
            $native = $connection->isTransactionActive() ? $connection->getNativeConnection() : null;
            if (is_object($native)) {
                $this->afterCommit->hold($native, $connection->getTransactionNestingLevel(), $then);
            } else {
                $this->afterCommit->drain();
                $this->afterCommit->run($then);
            }
        }
        $this->drainAtLevelZero($connection);

        return $result;
    }

    /**
     * A safe point (audit QL-M1): with the connection at level 0, work made ready by an earlier host commit runs now.
     */
    private function drainAtLevelZero(Connection $connection): void
    {
        if (!$connection->isTransactionActive()) {
            $this->afterCommit->drain();
        }
    }
}
