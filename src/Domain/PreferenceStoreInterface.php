<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Storage port of the preference rows (`notify_preference`, contract §5.1). Writes run inside the caller's unit of
 * work and are conditional single statements (no read-modify-write); every read by recipient re-checks the recipient
 * and the cell with `===` in PHP (audit LQ). A stored row whose values the value objects refuse is skipped.
 *
 * @internal
 */
interface PreferenceStoreInterface
{
    /** Most rows read for one person. */
    public const MAX_ROWS = 10000;

    /**
     * The rows of $recipient: every row (null), or the account rows plus those of $contextKey.
     *
     * @return list<PreferenceRow>
     */
    public function rows(RecipientRef $recipient, ?string $contextKey): array;

    /**
     * The rows of many recipients restricted to the cells a decision needs (the notifier: one category and channel,
     * the account and one context).
     *
     * @param list<RecipientRef> $recipients
     * @param list<string> $contextKeys
     * @param list<string> $categories
     * @param list<string> $channels
     *
     * @return array<string, list<PreferenceRow>> by {@see RecipientRef::key()}
     */
    public function rowsOfMany(array $recipients, array $contextKeys, array $categories, array $channels): array;

    /**
     * Brings one cell to $next (null = no row) if $expected still holds, in one conditional statement. Must run
     * inside a transaction.
     *
     * @phpstan-impure
     */
    public function write(RecipientRef $recipient, PreferenceKey $key, PreferenceExpectation $expected, ?ChannelPreference $next, DateTimeImmutable $now): CellWrite;

    /**
     * Deletes the overrides of one context (every cell of $contextKey except its switch). Must run inside a transaction.
     *
     * @return list<PreferenceKey> the cells deleted
     */
    public function deleteOverrides(RecipientRef $recipient, string $contextKey): array;
}
