<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Identity;

use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Domain\NotificationIdGeneratorInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Default {@see NotificationIdGeneratorInterface}: `Uuid::v7()` of symfony/uid (time-ordered, monotonic within a
 * millisecond in the same process).
 *
 * Stateless (worker mode): symfony/uid keeps its own monotonic counter, which is meant to live for the process.
 */
final readonly class UuidV7NotificationIdGenerator implements NotificationIdGeneratorInterface
{
    /**
     * A new UUIDv7 in its canonical lower-case form.
     */
    public function next(): NotificationId
    {
        return new NotificationId(Uuid::v7()->toRfc4122());
    }
}
