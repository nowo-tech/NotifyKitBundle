<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Host SPI (`realtime.publisher`): announces a recipient's new unread count after a commit (contract §4.3, §6.5). The
 * bundle's Mercure publisher sends a private update `{"event": "notify.updated", "unread": n}` on the recipient's
 * hashed topic; a host may keep its own channel (Beacon, Q-B3). The payload never carries a title, URL or param.
 *
 * Called after commit only; a failure must not escape (the bundle logs it and goes on). Stateless (worker mode).
 */
interface RealtimePublisherInterface
{
    /**
     * Announces $unread to $recipient's subscribers.
     */
    public function publishUnread(RecipientRef $recipient, int $unread): void;

    /**
     * The topic $recipient's browser subscribes to.
     */
    public function topicFor(RecipientRef $recipient): string;
}
