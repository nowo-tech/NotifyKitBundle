<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * The effective choice for one category × channel (contract §4.1): on or off, and the scope (`all` / `involved`).
 * `locked` is true when the channel cannot be turned off for the category (always on, scope `all`).
 */
final readonly class ChannelPreference
{
    /**
     * @param bool $enabled whether the channel delivers
     * @param Scope $scope who it lets through
     * @param bool $locked the channel is locked on by the type definition (the person cannot change it)
     */
    public function __construct(
        public bool $enabled,
        public Scope $scope = Scope::All,
        public bool $locked = false,
    ) {
    }

    /**
     * Whether a notification reaches the person: on, and (scope `involved`) the person is involved.
     */
    public function lets(bool $involved): bool
    {
        return $this->enabled && ($this->scope === Scope::All || $involved);
    }

    /**
     * Same enabled flag and scope (the lock is not compared).
     */
    public function sameChoice(self $other): bool
    {
        return $this->enabled === $other->enabled && $this->scope === $other->scope;
    }
}
