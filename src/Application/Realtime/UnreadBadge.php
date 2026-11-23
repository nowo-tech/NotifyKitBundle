<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Realtime;

use Nowo\NotifyKitBundle\Application\Visibility\VisibilityResolver;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Throwable;

/**
 * Publishes a recipient's visible unread count after a commit (contract §6.5): only when a publisher is configured
 * (`realtime.mode: mercure`, or `realtime.publisher`); with `poll` / `off` nothing is counted. A failure logs a warning
 * and never fails the caller (the data is committed).
 *
 * Stateless (worker mode).
 */
final readonly class UnreadBadge
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private VisibilityResolver $visibility,
        private ?RealtimePublisherInterface $publisher = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Announces the current unread count of $recipient.
     */
    public function publish(RecipientRef $recipient): void
    {
        if (!$this->publisher instanceof RealtimePublisherInterface) {
            return;
        }
        try {
            $this->publisher->publishUnread($recipient, $this->store->countUnread($recipient, $this->visibility->for($recipient)));
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->warning('nowo_notify_kit: the realtime badge could not be published.', ['exception' => $exception::class]);
        }
    }
}
