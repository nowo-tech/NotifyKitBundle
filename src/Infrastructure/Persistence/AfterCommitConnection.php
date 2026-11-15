<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Persistence;

use Doctrine\DBAL\Driver\Connection;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;

use function is_object;
use function preg_match;
use function strtoupper;

/**
 * Driver connection of {@see AfterCommitMiddleware}: reports to the queue the outermost commit and rollback, and the
 * savepoint statements DBAL issues for nested levels (`DOCTRINE_<level>` on DBAL 4, `DOCTRINE2_SAVEPOINT_<level>` on
 * DBAL 3), so work written inside a savepoint the host rolls back is never announced (audit QL-M2).
 *
 * @internal
 */
final class AfterCommitConnection extends AbstractConnectionMiddleware
{
    private const SAVEPOINT = '/^\s*(ROLLBACK\s+TO|RELEASE)\s+SAVEPOINT\s+(?:DOCTRINE_|DOCTRINE2_SAVEPOINT_)(\d+)\s*$/iD';

    /**
     * Wraps a driver connection.
     */
    public function __construct(Connection $wrapped, private readonly AfterCommitQueue $queue)
    {
        parent::__construct($wrapped);
    }

    /**
     * Runs the statement; a savepoint rollback or release then updates the held work.
     */
    public function exec(string $sql): int
    {
        // int on DBAL 3; DBAL 4 may return a numeric string, narrowed here so the signature fits both.
        $result = (int) parent::exec($sql);
        $native = $this->getNativeConnection();
        if (is_object($native) && preg_match(self::SAVEPOINT, $sql, $match) === 1) {
            if (strtoupper($match[1]) === 'RELEASE') {
                $this->queue->savepointReleased($native, (int) $match[2]);
            } else {
                $this->queue->savepointRolledBack($native, (int) $match[2]);
            }
        }

        return $result;
    }

    /**
     * Commits, then marks the work held for this connection ready (it runs at the next safe point, audit QL-M1).
     */
    public function commit(): void
    {
        parent::commit();
        $native = $this->getNativeConnection();
        if (is_object($native)) {
            $this->queue->committed($native);
        }
    }

    /**
     * Rolls back and drops the work held for this connection (also when the rollback itself fails).
     */
    public function rollBack(): void
    {
        try {
            parent::rollBack();
        } finally {
            $native = $this->getNativeConnection();
            if (is_object($native)) {
                $this->queue->rolledBack($native);
            }
        }
    }
}
