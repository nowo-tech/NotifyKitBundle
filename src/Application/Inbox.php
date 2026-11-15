<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
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
        $total = 0;
        do {
            $deleted = $this->transactions->run(fn (): int => $this->store->forgetContext($context, $this->batchSize));
            $total += $deleted;
        } while ($deleted >= $this->batchSize);

        return $total;
    }
}
