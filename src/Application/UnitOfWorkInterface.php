<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use Closure;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * Application port of the bundle's units of work (audit QL-I6): run some work in one transaction on the bundle
 * connection, then run follow-up work once the outermost transaction committed (dropped on rollback). Implemented by
 * `Infrastructure\Persistence\NotifyKitTransactions` (DBAL, nowo-tech/transaction-contract).
 *
 * The application services depend on this port only, never on the infrastructure class.
 */
interface UnitOfWorkInterface
{
    /**
     * Runs $work in one transaction (joining the caller's transaction on the same connection), then $afterCommit with
     * its result once the outermost transaction committed. A failure of $afterCommit is logged, never thrown.
     *
     * @template T
     *
     * @param Closure(): T $work replayable: a retrying runner may run it again
     * @param (Closure(T): void)|null $afterCommit
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (nothing was written)
     *
     * @return T
     */
    public function run(Closure $work, ?Closure $afterCommit = null): mixed;
}
