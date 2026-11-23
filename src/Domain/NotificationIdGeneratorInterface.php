<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use Nowo\NotifyKitBundle\Contract\NotificationId;

/**
 * Port that mints the id of a new inbox row (a time-ordered UUIDv7, contract §5.1). The container wires
 * `Infrastructure\Identity\UuidV7NotificationIdGenerator` (symfony/uid, monotonic within a millisecond), so the
 * Application layer stays framework-free (REQ-ARCH-002).
 */
interface NotificationIdGeneratorInterface
{
    /**
     * A new, unused notification id.
     */
    public function next(): NotificationId;
}
