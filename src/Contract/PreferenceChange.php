<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * One requested change of a cell: the wanted choice and what the caller believes is stored now. The service stores the
 * choice only when it differs from the cell's default (contract §5.1: deviations only) and deletes the row otherwise.
 */
final readonly class PreferenceChange
{
    /**
     * @param PreferenceKey $key the cell
     * @param bool $enabled on / off
     * @param Scope $scope who it lets through
     * @param PreferenceExpectation|null $expected the stored state the caller saw; null = {@see PreferenceExpectation::any()}
     */
    public function __construct(
        public PreferenceKey $key,
        public bool $enabled,
        public Scope $scope = Scope::All,
        public ?PreferenceExpectation $expected = null,
    ) {
    }

    /**
     * The wanted choice.
     */
    public function choice(): ChannelPreference
    {
        return new ChannelPreference($this->enabled, $this->scope);
    }

    /**
     * The expectation (never null).
     */
    public function expectation(): PreferenceExpectation
    {
        return $this->expected ?? PreferenceExpectation::any();
    }
}
