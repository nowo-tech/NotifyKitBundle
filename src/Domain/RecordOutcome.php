<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use Nowo\NotifyKitBundle\Contract\NotificationId;

/**
 * What {@see NotificationStoreInterface::record()} did for one recipient.
 */
final readonly class RecordOutcome
{
    /**
     * @param bool $coalesced true when an unread row of the same type and target was updated
     */
    public function __construct(public NotificationId $id, public bool $coalesced)
    {
    }
}
