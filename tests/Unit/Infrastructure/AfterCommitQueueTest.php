<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use Closure;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitConnection;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueueResetListener;
use Nowo\NotifyKitBundle\Tests\Fixtures\MemoryLogger;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\FinishRequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The after-commit queue (audit QL-M1 / QL-M2): held per connection and level, ready after the outermost commit and
 * run only at a safe point, dropped on rollback and on a rollback to a savepoint that contains it.
 */
final class AfterCommitQueueTest extends TestCase
{
    /** @var list<string> */
    private array $ran = [];

    public function testHeldWorkBecomesReadyOnCommitAndRunsOnlyWhenDrained(): void
    {
        $queue = new AfterCommitQueue();
        $a     = new stdClass();
        $b     = new stdClass();
        $queue->hold($a, 1, $this->work('a1'));
        $queue->hold($a, 2, $this->work('a2'));
        $queue->hold($b, 1, $this->work('b'));
        self::assertSame(3, $queue->heldCount());

        $queue->rolledBack($b);
        $queue->committed($a);
        $queue->committed($b);
        self::assertSame([], $this->ran, 'nothing runs inside the commit');
        self::assertSame([0, 2], [$queue->heldCount(), $queue->readyCount()]);

        $queue->drain();
        self::assertSame(['a1', 'a2'], $this->ran);
        self::assertSame(0, $queue->readyCount());
    }

    public function testSavepointsDropOrMoveTheWorkTheyContain(): void
    {
        $queue = new AfterCommitQueue();
        $c     = new stdClass();
        $queue->hold($c, 1, $this->work('outer'));
        $queue->hold($c, 2, $this->work('released'));
        $queue->savepointReleased($c, 2);     // level 2 joins level 1
        $queue->hold($c, 2, $this->work('rolled back'));
        $queue->hold($c, 3, $this->work('deeper'));
        $queue->savepointRolledBack($c, 2);   // a new savepoint 2 is rolled back
        $queue->savepointRolledBack(new stdClass(), 2);
        $queue->committed($c);
        $queue->drain();

        self::assertSame(['outer', 'released'], $this->ran);

        $queue->hold($c, 2, $this->work('alone'));
        $queue->savepointRolledBack($c, 2);
        self::assertSame(0, $queue->heldCount());
    }

    public function testTheDriverConnectionReportsCommitsRollbacksAndSavepoints(): void
    {
        $native = new stdClass();
        $driver = $this->createStub(DriverConnection::class);
        $driver->method('getNativeConnection')->willReturn($native);
        $driver->method('exec')->willReturn(0);
        $queue      = new AfterCommitQueue();
        $connection = new AfterCommitConnection($driver, $queue);

        $queue->hold($native, 2, $this->work('nested'));
        $connection->exec('RELEASE SAVEPOINT DOCTRINE_2');
        $queue->hold($native, 2, $this->work('dropped'));
        $connection->exec('ROLLBACK TO SAVEPOINT DOCTRINE2_SAVEPOINT_2');
        $connection->exec('SELECT 1');
        $connection->commit();
        $queue->drain();
        self::assertSame(['nested'], $this->ran);

        $queue->hold($native, 1, $this->work('rolled back'));
        $connection->rollBack();
        self::assertSame(0, $queue->heldCount());
    }

    public function testFailuresAndDroppedWorkAreLogged(): void
    {
        $logger = new MemoryLogger();
        $queue  = new AfterCommitQueue($logger);
        $queue->run(static function (): never {
            throw new RuntimeException('listener failure');
        });
        $connection = new stdClass();
        $queue->hold($connection, 1, $this->work('held'));
        $queue->reset();
        $queue->reset();

        self::assertCount(2, $logger->records);
        self::assertSame('error', $logger->records[0][0]);
        self::assertSame('warning', $logger->records[1][0]);
        self::assertSame(0, $queue->heldCount());

        (new AfterCommitQueue())->run(static function (): never {
            throw new RuntimeException('no logger');
        });
        (new AfterCommitQueue())->reset();
    }

    public function testTheListenerDrainsAndClearsAtTheMainRequestAndEveryBoundary(): void
    {
        $queue      = new AfterCommitQueue();
        $listener   = new AfterCommitQueueResetListener($queue);
        $kernel     = $this->createStub(HttpKernelInterface::class);
        $connection = new stdClass();

        $queue->hold($connection, 1, $this->work('held'));
        $queue->hold($connection, 1, $this->work('ready'));
        $queue->committed($connection);
        $queue->hold($connection, 1, $this->work('never committed'));
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame([1, 2], [$queue->heldCount(), $queue->readyCount()], 'a sub-request may finish inside the main request transaction');
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame([0, 0], [$queue->heldCount(), $queue->readyCount()]);
        self::assertSame(['held', 'ready'], $this->ran);

        $queue->hold($connection, 1, $this->work('x'));
        $listener->onBoundary();
        self::assertSame(0, $queue->heldCount());
    }

    /**
     * @return Closure(): void
     */
    private function work(string $name): Closure
    {
        return function () use ($name): void {
            $this->ran[] = $name;
        };
    }
}
