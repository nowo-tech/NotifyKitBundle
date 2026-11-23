<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;

/**
 * A row of `<prefix>notify_preference` (contract §5.1): one deviation of a person from the defaults, for a context
 * (`''` = account level), a category (`*` = master / context switch) and a channel (`*` = every channel). Mapped by
 * `Resources/config/doctrine/StoredPreference.orm.xml` for the host's schema tooling; the bundle writes it through DBAL
 * and reads it as `Contract\PreferenceRow`.
 *
 * Not final: Doctrine generates proxies of mapped classes at cache warm-up.
 */
class StoredPreference
{
    /**
     * Holds the row values.
     */
    public function __construct(
        private string $id,
        private string $recipientType,
        private string $recipientId,
        private string $contextKey,
        private string $category,
        private string $channel,
        private bool $enabled,
        private ?string $scope,
        private DateTimeImmutable $updatedAt,
    ) {
    }

    /**
     * The row id (UUIDv7).
     */
    public function id(): string
    {
        return $this->id;
    }

    /**
     * Recipient type and id, as stored.
     *
     * @return array{string, string}
     */
    public function recipient(): array
    {
        return [$this->recipientType, $this->recipientId];
    }

    /**
     * Context key (`''` = account), category and channel, as stored.
     *
     * @return array{string, string, string}
     */
    public function cell(): array
    {
        return [$this->contextKey, $this->category, $this->channel];
    }

    /**
     * On / off and the scope (`null` = `all`).
     *
     * @return array{bool, string|null}
     */
    public function choice(): array
    {
        return [$this->enabled, $this->scope];
    }

    /**
     * Last change.
     */
    public function updatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
