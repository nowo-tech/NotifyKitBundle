<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;

/**
 * A dedupe key taken by an accepted request (`<prefix>notify_dedupe`): `sha256` of the key, valid until `expiresAt`
 * (24 hours). Mapped by `Resources/config/doctrine/DedupeMarker.orm.xml` for the host's schema tooling; the bundle
 * writes it through DBAL. The hash never reveals the key, which may name a record of the host.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class DedupeMarker
{
    /**
     * Holds the row values.
     */
    public function __construct(
        private string $keyHash,
        private DateTimeImmutable $expiresAt,
    ) {
    }

    /**
     * The hex `sha256` of the dedupe key.
     */
    public function keyHash(): string
    {
        return $this->keyHash;
    }

    /**
     * When the key may be used again.
     */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }
}
