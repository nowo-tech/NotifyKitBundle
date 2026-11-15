<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

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
 * The after-commit queue: held per connection, run on commit, dropped on rollback and at every boundary (worker mode),
 * failures logged and never thrown.
 */
final class AfterCommitQueueTest extends TestCase
{
    public function testWorkIsHeldUntilTheCommitAndDroppedOnRollback(): void
    {
        $queue = new AfterCommitQueue();
        $a     = new stdClass();
        $b     = new stdClass();
        $ran   = [];
        $queue->hold($a, static function () use (&$ran): void {
            $ran[] = 'a1';
        });
        $queue->hold($a, static function () use (&$ran): void {
            $ran[] = 'a2';
        });
        $queue->hold($b, static function () use (&$ran): void {
            $ran[] = 'b';
        });
        self::assertSame(3, $queue->heldCount());

        $queue->rolledBack($b);
        $queue->committed($a);
        $queue->committed($b);
        self::assertSame(['a1', 'a2'], $ran);
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
        $queue->hold($connection, static function (): void {
        });
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

    public function testTheListenerClearsAtTheMainRequestAndEveryBoundary(): void
    {
        $queue    = new AfterCommitQueue();
        $listener = new AfterCommitQueueResetListener($queue);
        $kernel   = $this->createStub(HttpKernelInterface::class);

        $connection = new stdClass();
        $queue->hold($connection, static function (): void {
        });
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST));
        self::assertSame(1, $queue->heldCount(), 'a sub-request may finish inside the main request transaction');
        $listener->onFinishRequest(new FinishRequestEvent($kernel, new Request(), HttpKernelInterface::MAIN_REQUEST));
        self::assertSame(0, $queue->heldCount());

        $queue->hold($connection, static function (): void {
        });
        $listener->onBoundary();
        self::assertSame(0, $queue->heldCount());
    }
}
