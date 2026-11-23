<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Realtime;

use Nowo\NotifyKitBundle\Application\Realtime\RealtimeTopics;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

use function json_encode;

use const JSON_THROW_ON_ERROR;

/**
 * `realtime.mode: mercure` (contract §6.5): one **private** Mercure update per recipient on their hashed topic, with
 * the payload `{"event": "notify.updated", "unread": n}` only. Uses the host's hub (MercureBundle); holds no stream.
 */
final readonly class MercureRealtimePublisher implements RealtimePublisherInterface
{
    /** Event name of the payload. */
    public const EVENT = 'notify.updated';

    /**
     * Holds the hub and the topics (stateless).
     */
    public function __construct(private HubInterface $hub, private RealtimeTopics $topics)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function publishUnread(RecipientRef $recipient, int $unread): void
    {
        $this->hub->publish(new Update($this->topics->topicFor($recipient), json_encode(['event' => self::EVENT, 'unread' => $unread], JSON_THROW_ON_ERROR), true));
    }

    /**
     * {@inheritDoc}
     */
    public function topicFor(RecipientRef $recipient): string
    {
        return $this->topics->topicFor($recipient);
    }
}
