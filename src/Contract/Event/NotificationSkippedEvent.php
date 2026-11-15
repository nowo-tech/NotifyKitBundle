<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Event;

use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\SkipReason;

/**
 * A recipient of a request got no row (debug and metrics; listeners must be cheap). Dispatched with the request's
 * other events, after the write committed.
 */
final readonly class NotificationSkippedEvent
{
    /**
     * Holds the type, the recipient and the reason.
     */
    public function __construct(
        public string $type,
        public RecipientRef $recipient,
        public SkipReason $reason,
    ) {
    }
}
