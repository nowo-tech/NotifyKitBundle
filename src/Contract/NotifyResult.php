<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * What one {@see NotifierInterface::notify()} call did. Inside a host transaction the rows are written with it: they
 * exist once that transaction commits (and not at all if it rolls back).
 */
final readonly class NotifyResult
{
    /**
     * @param int $written new inbox rows
     * @param int $coalesced unread rows a repeat updated instead of adding one
     * @param int $skippedActor recipients skipped because they acted
     * @param int $skippedAccess recipients skipped because they may not open the target
     * @param bool $deduplicated the whole request was dropped (same dedupe key within 24 hours)
     * @param int $enqueued out-of-band deliveries enqueued (from S4; always 0 in S1)
     * @param list<NotificationId> $notificationIds the rows written or updated, in recipient order
     */
    public function __construct(
        public int $written = 0,
        public int $coalesced = 0,
        public int $skippedActor = 0,
        public int $skippedAccess = 0,
        public bool $deduplicated = false,
        public int $enqueued = 0,
        public array $notificationIds = [],
    ) {
    }
}
