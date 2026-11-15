<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * One inbox row (contract §5.1, `<prefix>notify_notification`): plain PHP, mapped by
 * `Resources/config/doctrine/Notification.orm.xml` for the host's schema tooling. The bundle reads and writes it through
 * DBAL ({@see NotificationStoreInterface}); Doctrine never manages it.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class Notification
{
    private string $id;

    private string $recipientType;

    private string $recipientId;

    private ?string $actorType;

    private ?string $actorId;

    private ?string $contextType;

    private ?string $contextId;

    /**
     * Holds the row values.
     *
     * @param array<string, string> $params
     */
    public function __construct(
        NotificationId $id,
        RecipientRef $recipient,
        private string $type,
        private string $category,
        ?RecipientRef $actor,
        ?ContextRef $context,
        private string $target,
        private string $url,
        private array $params,
        private int $repeats,
        private DateTimeImmutable $firstCreatedAt,
        private DateTimeImmutable $createdAt,
        private ?DateTimeImmutable $readAt = null,
    ) {
        $this->id            = $id->value;
        $this->recipientType = $recipient->type;
        $this->recipientId   = $recipient->id;
        $this->actorType     = $actor?->type;
        $this->actorId       = $actor?->id;
        $this->contextType   = $context?->type;
        $this->contextId     = $context?->id;
    }

    /**
     * The row id.
     */
    public function id(): NotificationId
    {
        return new NotificationId($this->id);
    }

    /**
     * Who the row is for.
     */
    public function recipient(): RecipientRef
    {
        return new RecipientRef($this->recipientType, $this->recipientId);
    }

    /**
     * The type key.
     */
    public function type(): string
    {
        return $this->type;
    }

    /**
     * The category, copied from the type when the row was written.
     */
    public function category(): string
    {
        return $this->category;
    }

    /**
     * Who acted last (null once the actor is erased, or for a system notice).
     */
    public function actor(): ?RecipientRef
    {
        return $this->actorType !== null && $this->actorId !== null ? new RecipientRef($this->actorType, $this->actorId) : null;
    }

    /**
     * What the row belongs to; null = personal.
     */
    public function context(): ?ContextRef
    {
        return $this->contextType !== null && $this->contextId !== null ? new ContextRef($this->contextType, $this->contextId) : null;
    }

    /**
     * The target key that groups repeats.
     */
    public function target(): string
    {
        return $this->target;
    }

    /**
     * The local path the row links to.
     */
    public function url(): string
    {
        return $this->url;
    }

    /**
     * The title params.
     *
     * @return array<string, string>
     */
    public function params(): array
    {
        return $this->params;
    }

    /**
     * 1 + the repeats coalesced into the row.
     */
    public function repeats(): int
    {
        return $this->repeats;
    }

    /**
     * When the first request of the row arrived.
     */
    public function firstCreatedAt(): DateTimeImmutable
    {
        return $this->firstCreatedAt;
    }

    /**
     * When the last request of the row arrived (a repeat moves it to the top).
     */
    public function createdAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    /**
     * When the recipient read it; null = unread.
     */
    public function readAt(): ?DateTimeImmutable
    {
        return $this->readAt;
    }
}
