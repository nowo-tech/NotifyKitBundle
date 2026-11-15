<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Retention;

use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;

/**
 * What one {@see Retention::run()} deleted, and where to resume when it stopped at its time budget.
 */
final readonly class RetentionReport
{
    /**
     * @param int $read read rows older than the cutoff
     * @param int $trimmed rows beyond the per-recipient cap
     * @param int $dedupe expired dedupe keys
     * @param PruneNotificationsMessage|null $remainder null = done
     */
    public function __construct(
        public int $read = 0,
        public int $trimmed = 0,
        public int $dedupe = 0,
        public ?PruneNotificationsMessage $remainder = null,
    ) {
    }

    /**
     * Adds another run's counts (and takes its remainder).
     */
    public function plus(self $next): self
    {
        return new self($this->read + $next->read, $this->trimmed + $next->trimmed, $this->dedupe + $next->dedupe, $next->remainder);
    }
}
