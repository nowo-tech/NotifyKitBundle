<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * One stored deviation from the defaults (a row of `notify_preference`): its key, on / off and scope.
 */
final readonly class PreferenceRow
{
    /**
     * Holds the values.
     */
    public function __construct(
        public PreferenceKey $key,
        public bool $enabled,
        public Scope $scope = Scope::All,
    ) {
    }

    /**
     * The row as a choice.
     */
    public function choice(): ChannelPreference
    {
        return new ChannelPreference($this->enabled, $this->scope);
    }
}
