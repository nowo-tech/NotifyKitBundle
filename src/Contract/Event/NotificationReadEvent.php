<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Event;

use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Rows of one recipient changed their read state, after the update committed (contract §6.6). `all` is true for
 * *mark all as read* (then `ids` is empty: the rows are not listed); `read` is false when rows were marked unread
 * again. References only, never params, URLs or titles.
 */
final readonly class NotificationReadEvent
{
    /** Most ids one event lists. */
    public const MAX_IDS = 500;

    /**
     * @param list<NotificationId> $ids the rows that changed (≤ {@see self::MAX_IDS}); empty with `all`
     * @param bool $all *mark all as read*
     * @param bool $read true = marked read, false = marked unread
     */
    public function __construct(
        public RecipientRef $recipient,
        public array $ids,
        public bool $all = false,
        public bool $read = true,
    ) {
    }
}
