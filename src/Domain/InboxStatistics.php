<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

/**
 * Counts for the operator page (contract §8.4): never content, never recipients.
 *
 * @internal
 */
final readonly class InboxStatistics
{
    /**
     * @param array<string, int> $writtenByType rows created per type in the period (stored type keys), most first
     */
    public function __construct(
        public int $rows,
        public int $unread,
        public int $dedupeMarkers,
        public array $writtenByType,
    ) {
    }
}
