<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Event;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * An inbox row was written (or an unread row updated by a repeat), after the write committed.
 */
final readonly class NotificationRecordedEvent
{
    /**
     * @param bool $coalesced true when a repeat updated the unread row instead of adding one
     */
    public function __construct(
        public NotificationId $notificationId,
        public RecipientRef $recipient,
        public string $type,
        public ?ContextRef $context,
        public bool $coalesced,
    ) {
    }
}
