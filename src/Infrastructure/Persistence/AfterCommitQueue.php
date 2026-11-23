<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Persistence;

use Closure;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Contracts\Service\ResetInterface;
use Throwable;
use WeakMap;

use function array_shift;
use function count;

/**
 * After-commit work of writes that joined a host transaction (contract §5.3, audit QL-M1 / QL-M2).
 *
 * - **Held** per native connection with the nesting level it was written at. The host's `ROLLBACK TO SAVEPOINT` drops
 *   the work held at that level or deeper, `RELEASE SAVEPOINT` moves it one level up, the outermost rollback drops it
 *   all ({@see AfterCommitConnection}).
 * - The outermost commit only moves it to **ready**: at that moment DBAL still reports nesting level 1 although the
 *   server transaction is gone, so nothing may run then (a listener opening a transaction would get a savepoint without
 *   a transaction and leave the connection at level 1).
 * - Ready work runs at the next safe point: when the bundle's next unit of work starts or ends on a connection at
 *   level 0 ({@see NotifyKitTransactions}), and at every request / command / message boundary
 *   ({@see AfterCommitQueueResetListener}) and `kernel.reset`. So joined events arrive at the latest at the boundary.
 *
 * Worker mode: everything is request state; the boundary drains the ready work and drops what is still held (a
 * transaction that never committed through the middleware). Weak keys: a closed connection takes its held work with it.
 *
 * @internal
 */
final class AfterCommitQueue implements ResetInterface
{
    /** @var WeakMap<object, list<array{int, Closure(): void}>> */
    private WeakMap $held;

    /** @var list<Closure(): void> */
    private array $ready = [];

    /**
     * @param LoggerInterface|null $logger failures of after-commit work are logged here, never thrown
     */
    public function __construct(private readonly ?LoggerInterface $logger = null)
    {
        $this->held = new WeakMap();
    }

    /**
     * Holds work until the outermost commit of this native connection.
     *
     * @param int $level the connection's nesting level when the work was written
     * @param Closure(): void $work
     */
    public function hold(object $nativeConnection, int $level, Closure $work): void
    {
        $this->held[$nativeConnection] = [...($this->held[$nativeConnection] ?? []), [$level, $work]];
    }

    /**
     * The outermost transaction of this connection committed: its held work becomes ready (it runs at the next safe
     * point, never inside the driver commit).
     */
    public function committed(object $nativeConnection): void
    {
        foreach ($this->held[$nativeConnection] ?? [] as [, $work]) {
            $this->ready[] = $work;
        }
        unset($this->held[$nativeConnection]);
    }

    /**
     * The outermost transaction of this connection rolled back: forgets its held work.
     */
    public function rolledBack(object $nativeConnection): void
    {
        unset($this->held[$nativeConnection]);
    }

    /**
     * The host rolled back to the savepoint of nesting level $level: forgets the work held at that level or deeper.
     */
    public function savepointRolledBack(object $nativeConnection, int $level): void
    {
        $kept = [];
        foreach ($this->held[$nativeConnection] ?? [] as $item) {
            if ($item[0] < $level) {
                $kept[] = $item;
            }
        }
        $this->store($nativeConnection, $kept);
    }

    /**
     * The savepoint of nesting level $level was released: its work now belongs to the enclosing level.
     */
    public function savepointReleased(object $nativeConnection, int $level): void
    {
        $moved = [];
        foreach ($this->held[$nativeConnection] ?? [] as [$at, $work]) {
            $moved[] = [$at >= $level ? $level - 1 : $at, $work];
        }
        $this->store($nativeConnection, $moved);
    }

    /**
     * Runs the ready work, oldest first (work that becomes ready meanwhile runs too). Call it only where no transaction
     * of the bundle is being committed.
     */
    public function drain(): void
    {
        while ($this->ready !== []) {
            $work = array_shift($this->ready);
            $this->run($work);
        }
    }

    /**
     * Runs work now; a failure is logged (the data is already committed, so the caller must not see it as failed).
     *
     * @param Closure(): void $work
     */
    public function run(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->error('nowo_notify_kit: after-commit work failed: {class}', ['class' => $exception::class, 'exception' => $exception]);
        }
    }

    /**
     * How many work items are held (all connections).
     */
    public function heldCount(): int
    {
        $count = 0;
        foreach ($this->held as $work) {
            $count += count($work);
        }

        return $count;
    }

    /**
     * How many committed work items wait for a safe point.
     */
    public function readyCount(): int
    {
        return count($this->ready);
    }

    /**
     * Request / message boundary and `kernel.reset`: runs the ready work, then drops everything still held. Held work
     * at a boundary means the surrounding transaction never committed through the bundle's DBAL middleware: logged as
     * a warning.
     */
    public function reset(): void
    {
        $this->drain();
        $this->ready = [];
        $count       = $this->heldCount();
        if ($count > 0) {
            ($this->logger ?? new NullLogger())->warning('nowo_notify_kit: dropped {count} after-commit item(s) of a transaction that did not commit through the bundle middleware.', ['count' => $count]);
        }
        $this->held = new WeakMap();
    }

    /**
     * @param list<array{int, Closure(): void}> $items
     */
    private function store(object $nativeConnection, array $items): void
    {
        if ($items === []) {
            unset($this->held[$nativeConnection]);

            return;
        }
        $this->held[$nativeConnection] = $items;
    }
}
