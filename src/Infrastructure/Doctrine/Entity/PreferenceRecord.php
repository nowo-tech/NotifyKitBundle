<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\NotifyKitBundle\Domain\StoredPreference;

/**
 * Attribute-mapped twin of {@see StoredPreference} (`doctrine.mapping: attribute`, same schema, tested). Read-only for
 * hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notify_preference')]
#[ORM\UniqueConstraint(name: 'uniq_notify_preference_cell', columns: ['recipient_type', 'recipient_id', 'context_key', 'category', 'channel'])]
class PreferenceRecord
{
    /**
     * Holds the column values.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'id', type: Types::STRING, length: 36)]
        public readonly string $id,
        #[ORM\Column(name: 'recipient_type', type: Types::STRING, length: 32)]
        public readonly string $recipientType,
        #[ORM\Column(name: 'recipient_id', type: Types::STRING, length: 128)]
        public readonly string $recipientId,
        #[ORM\Column(name: 'context_key', type: Types::STRING, length: 100)]
        public readonly string $contextKey,
        #[ORM\Column(name: 'category', type: Types::STRING, length: 64)]
        public readonly string $category,
        #[ORM\Column(name: 'channel', type: Types::STRING, length: 32)]
        public readonly string $channel,
        #[ORM\Column(name: 'enabled', type: Types::BOOLEAN)]
        public readonly bool $enabled,
        #[ORM\Column(name: 'scope', type: Types::STRING, length: 16, nullable: true)]
        public readonly ?string $scope,
        #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $updatedAt,
    ) {
    }
}
