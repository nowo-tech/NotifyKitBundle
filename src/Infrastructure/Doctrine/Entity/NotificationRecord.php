<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\NotifyKitBundle\Domain\Notification;

/**
 * Attribute-mapped twin of {@see Notification} (contract §5.1, Q-O11): the same table and columns, for hosts whose
 * tooling only reads attributes (`doctrine.mapping: attribute`). A test asserts that both mappings produce the same
 * schema. Read-only for hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notify_notification')]
#[ORM\Index(name: 'idx_notify_notification_inbox', columns: ['recipient_type', 'recipient_id', 'read_at', 'created_at', 'id'])]
#[ORM\Index(name: 'idx_notify_notification_repeat', columns: ['recipient_type', 'recipient_id', 'type', 'target'])]
#[ORM\Index(name: 'idx_notify_notification_context', columns: ['context_type', 'context_id'])]
#[ORM\Index(name: 'idx_notify_notification_created', columns: ['created_at'])]
class NotificationRecord
{
    /**
     * Holds the column values.
     *
     * @param array<string, string> $params
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'recipient_type', type: Types::STRING, length: 32)]
        public readonly string $recipientType,
        #[ORM\Column(name: 'recipient_id', type: Types::STRING, length: 128)]
        public readonly string $recipientId,
        #[ORM\Column(name: 'type', type: Types::STRING, length: 64)]
        public readonly string $type,
        #[ORM\Column(name: 'category', type: Types::STRING, length: 64)]
        public readonly string $category,
        #[ORM\Column(name: 'actor_type', type: Types::STRING, length: 32, nullable: true)]
        public readonly ?string $actorType,
        #[ORM\Column(name: 'actor_id', type: Types::STRING, length: 128, nullable: true)]
        public readonly ?string $actorId,
        #[ORM\Column(name: 'context_type', type: Types::STRING, length: 32, nullable: true)]
        public readonly ?string $contextType,
        #[ORM\Column(name: 'context_id', type: Types::STRING, length: 64, nullable: true)]
        public readonly ?string $contextId,
        #[ORM\Column(name: 'target', type: Types::STRING, length: 128)]
        public readonly string $target,
        #[ORM\Column(name: 'url', type: Types::STRING, length: 500)]
        public readonly string $url,
        #[ORM\Column(name: 'params', type: Types::JSON)]
        public readonly array $params,
        #[ORM\Column(name: 'repeats', type: Types::INTEGER)]
        public readonly int $repeats,
        #[ORM\Column(name: 'first_created_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $firstCreatedAt,
        #[ORM\Column(name: 'created_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'read_at', type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public readonly ?DateTimeImmutable $readAt,
    ) {
    }
}
