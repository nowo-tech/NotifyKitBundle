<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Domain\BatchResult;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;

/**
 * Default {@see InboxInterface} (S1: `forgetContext()`; the read side lands in S2).
 *
 * Stateless (worker mode).
 */
final readonly class Inbox implements InboxInterface
{
    /**
     * @param int $batchSize `retention.batch_size`: rows deleted per transaction
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private NotifyKitTransactions $transactions,
        private int $batchSize = 500,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function forgetContext(ContextRef $context): int
    {
        $total  = 0;
        $cursor = null;
        do {
            $batch = $this->transactions->run(fn (): BatchResult => $this->store->forgetContext($context, $cursor, $this->batchSize));
            $total += $batch->deleted;
            $cursor = $batch->cursor;
        } while ($cursor !== null);

        return $total;
    }
}
