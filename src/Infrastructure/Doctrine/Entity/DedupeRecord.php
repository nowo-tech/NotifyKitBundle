<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;

/**
 * Attribute-mapped twin of {@see DedupeMarker} (`doctrine.mapping: attribute`, same schema, tested). Read-only for
 * hosts: the bundle writes through DBAL.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
#[ORM\Entity(readOnly: true)]
#[ORM\Table(name: 'notify_dedupe')]
#[ORM\Index(name: 'idx_notify_dedupe_expires', columns: ['expires_at'])]
class DedupeRecord
{
    /**
     * Holds the column values.
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(name: 'key_hash', type: Types::STRING, length: 64)]
        public readonly string $keyHash,
        #[ORM\Column(name: 'expires_at', type: Types::DATETIME_IMMUTABLE)]
        public readonly DateTimeImmutable $expiresAt,
    ) {
    }
}
