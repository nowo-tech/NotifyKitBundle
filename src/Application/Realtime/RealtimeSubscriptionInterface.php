<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Realtime;

/**
 * Port of the bell's browser subscription (REQ-ARCH-002): the hub URL the bell subscribes to for a topic, after the
 * current request got whatever grant it needs (Mercure: the subscriber cookie). The Mercure adapter is
 * `Infrastructure\Realtime\MercureSubscription`.
 */
interface RealtimeSubscriptionInterface
{
    /**
     * The hub URL the bell subscribes to for $topic, or null when it could not be prepared (the bell then polls).
     */
    public function hubUrlFor(string $topic): ?string;
}
