<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function preg_match;
use function sprintf;

/**
 * What the caller believes is stored for a cell before a change (optimistic concurrency, REQ-RUNTIME-003): nothing
 * (`absent()`), a given row (`stored()`), or no expectation (`any()`: last write wins). A change whose expectation no
 * longer holds is refused with {@see Exception\PreferenceConflictException} and writes nothing.
 *
 * {@see self::token()} is the compact form the preferences page carries per cell: `-` (no row), or `1a` / `1i` /
 * `0a` / `0i` (enabled 1 / 0, scope all / involved).
 */
final readonly class PreferenceExpectation
{
    private function __construct(public bool $checked, public ?ChannelPreference $stored)
    {
    }

    /**
     * No expectation: the change applies whatever is stored.
     */
    public static function any(): self
    {
        return new self(false, null);
    }

    /**
     * The cell has no stored row (it follows the default).
     */
    public static function absent(): self
    {
        return new self(true, null);
    }

    /**
     * The cell stores $stored.
     */
    public static function stored(ChannelPreference $stored): self
    {
        return new self(true, new ChannelPreference($stored->enabled, $stored->scope));
    }

    /**
     * The expectation of a stored row, or of no row.
     */
    public static function of(?ChannelPreference $stored): self
    {
        return $stored instanceof ChannelPreference ? self::stored($stored) : self::absent();
    }

    /**
     * Parses a {@see self::token()}.
     *
     * @throws InvalidNotificationArgumentException not a token
     */
    public static function fromToken(string $token): self
    {
        if ($token === '-') {
            return self::absent();
        }
        if (preg_match('/^([01])([ai])$/D', $token, $parts) !== 1) {
            throw new InvalidNotificationArgumentException(sprintf('PreferenceExpectation token must be "-" or one of 1a, 1i, 0a, 0i; got "%s".', $token));
        }

        return self::stored(new ChannelPreference($parts[1] === '1', $parts[2] === 'i' ? Scope::Involved : Scope::All));
    }

    /**
     * The compact token of a stored state: `-` or `1a` / `1i` / `0a` / `0i`.
     */
    public static function tokenOf(?ChannelPreference $stored): string
    {
        return $stored instanceof ChannelPreference ? ($stored->enabled ? '1' : '0') . ($stored->scope === Scope::Involved ? 'i' : 'a') : '-';
    }

    /**
     * Whether $current (the stored row, null = none) meets the expectation.
     */
    public function holds(?ChannelPreference $current): bool
    {
        if (!$this->checked) {
            return true;
        }
        if (!$this->stored instanceof ChannelPreference || !$current instanceof ChannelPreference) {
            return !$this->stored instanceof ChannelPreference && !$current instanceof ChannelPreference;
        }

        return $this->stored->sameChoice($current);
    }
}
