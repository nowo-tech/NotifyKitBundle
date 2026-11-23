<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use DateTimeImmutable;

/**
 * One visible inbox row as lists, the bell and the pages see it (contract §4.1). It holds the type and the parameters,
 * never translated text: the title is built at display time in the viewer's language.
 */
final readonly class NotificationView
{
    /**
     * @param array<string, string> $params
     * @param int $repeats 1 + the repeats coalesced into this row
     * @param DateTimeImmutable $createdAt UTC; moves to the latest repeat
     * @param DateTimeImmutable|null $readAt UTC; null = unread
     */
    public function __construct(
        public NotificationId $id,
        public RecipientRef $recipient,
        public string $type,
        public string $category,
        public ?RecipientRef $actor,
        public ?ContextRef $context,
        public string $target,
        public string $url,
        public array $params,
        public int $repeats,
        public DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $readAt,
    ) {
    }

    /**
     * Whether the row was read.
     */
    public function isRead(): bool
    {
        return $this->readAt instanceof DateTimeImmutable;
    }
}
