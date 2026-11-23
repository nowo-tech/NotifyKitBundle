<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function array_is_list;
use function in_array;

/**
 * A person's preferences (contract §4.1): the stored deviations (account rows, context switches, context overrides)
 * over the defaults of the type registry, resolved in Beacon's merge order (contract §14):
 *
 * 1. a channel the type (or every type of the category) locks is always on, scope `all`;
 * 2. the master switch (`''`, `*`, `*`, or `''`, `*`, `<channel>`) turned off → off;
 * 3. the context switch (`<context>`, `*`, `*`) turned off → off;
 * 4. the context × category override (`<context>`, `<category>`, `<channel>`, else `<channel>` `*`);
 * 5. the account × category row (`''`, `<category>`, `<channel>`, else `*`);
 * 6. the default: the category's default (on when one of its types is on by default) for a type shown in the matrix,
 *    the same value the page shows; the type's own default otherwise (`in_app` on unless the type turns it off).
 *
 * Immutable, built per call (worker mode).
 */
final readonly class PreferenceMatrix
{
    /** @var list<PreferenceRow> */
    public array $rows;

    /** @var array<string, PreferenceRow> */
    private array $byKey;

    /**
     * @param RecipientRef $recipient whose preferences these are
     * @param list<string> $channels the matrix columns
     * @param list<string> $categories the matrix rows (categories shown on the page)
     * @param array<string, array<string, bool>> $defaults category => channel => on by default
     * @param array<string, list<string>> $locked category => channels every type of the category locks
     * @param list<PreferenceRow> $rows the stored deviations
     */
    public function __construct(
        public RecipientRef $recipient,
        public array $channels,
        public array $categories,
        private array $defaults,
        private array $locked,
        array $rows,
    ) {
        if (!array_is_list($rows)) {
            throw new InvalidNotificationArgumentException('PreferenceMatrix rows must be a list.');
        }
        $byKey = [];
        foreach ($rows as $row) {
            if (!$row instanceof PreferenceRow) {
                throw new InvalidNotificationArgumentException('PreferenceMatrix rows hold PreferenceRow objects only.');
            }
            $byKey[$this->id($row->key)] = $row;
        }
        $this->rows  = $rows;
        $this->byKey = $byKey;
    }

    /**
     * The stored row of a cell, as a choice; null = the cell follows its default.
     */
    public function stored(PreferenceKey $key): ?ChannelPreference
    {
        return ($this->byKey[$this->id($key)] ?? null)?->choice();
    }

    /**
     * Whether the master switch is on (no stored "off" row).
     */
    public function masterEnabled(): bool
    {
        return $this->stored(PreferenceKey::account(PreferenceKey::ANY, PreferenceKey::ANY))->enabled ?? true;
    }

    /**
     * Whether $context's switch is on (no stored "off" row).
     */
    public function contextEnabled(ContextRef $context): bool
    {
        return $this->stored(PreferenceKey::forContext($context, PreferenceKey::ANY, PreferenceKey::ANY))->enabled ?? true;
    }

    /**
     * Whether every type of $category locks $channel (the page disables that cell).
     */
    public function isLocked(string $category, string $channel): bool
    {
        return in_array($channel, $this->locked[$category] ?? [], true);
    }

    /**
     * Whether $context has stored overrides (cells other than its switch).
     */
    public function hasOverrides(ContextRef $context): bool
    {
        foreach ($this->rows as $row) {
            if ($row->key->contextKey === $context->key() && !$row->key->isSwitch()) {
                return true;
            }
        }

        return false;
    }

    /**
     * The default of a cell: on for a switch; the category default for an account cell; the account choice for a
     * context cell (an override that equals it is not stored).
     */
    public function defaultFor(PreferenceKey $key): ChannelPreference
    {
        if ($key->isSwitch()) {
            return new ChannelPreference(true);
        }
        if (!$key->isAccount()) {
            return $this->cell($key->category, $key->channel, null);
        }

        return new ChannelPreference($this->categoryDefault($key->category, $key->channel));
    }

    /**
     * What the page shows in a matrix cell (steps 4–6, switches not applied): the override of $context, else the
     * account row, else the category default. A locked cell is on.
     */
    public function cell(string $category, string $channel, ?ContextRef $context): ChannelPreference
    {
        if ($this->isLocked($category, $channel)) {
            return new ChannelPreference(true, Scope::All, true);
        }

        return $this->stack($category, $channel, $context) ?? new ChannelPreference($this->categoryDefault($category, $channel));
    }

    /**
     * The effective choice of a category × channel in $context (the whole merge order, category defaults).
     */
    public function resolve(string $category, string $channel, ?ContextRef $context = null): ChannelPreference
    {
        if ($this->isLocked($category, $channel)) {
            return new ChannelPreference(true, Scope::All, true);
        }

        return $this->switchedOff($channel, $context) ? new ChannelPreference(false) : $this->cell($category, $channel, $context);
    }

    /**
     * The effective choice for one notification type (what `notify()` applies): the type's own locks and defaults.
     */
    public function resolveType(TypeDefinition $type, string $channel, ?ContextRef $context = null): ChannelPreference
    {
        if (in_array($channel, $type->lockedChannels, true)) {
            return new ChannelPreference(true, Scope::All, true);
        }
        if ($this->switchedOff($channel, $context)) {
            return new ChannelPreference(false);
        }

        return $this->stack($type->category, $channel, $context) ?? new ChannelPreference($this->typeDefault($type, $channel));
    }

    /**
     * The default a type gets when nothing is stored: for a type of a category shown in the matrix, the category's
     * default, so what the page shows is what is delivered (audit ZAL-3: in a category whose types disagree, a channel
     * on for one type is on for all of them until the person turns it off); otherwise the type's own default.
     */
    private function typeDefault(TypeDefinition $type, string $channel): bool
    {
        if ($type->inMatrix && isset($this->defaults[$type->category][$channel])) {
            return $this->defaults[$type->category][$channel];
        }

        return $type->isDefaultOn($channel);
    }

    /**
     * Steps 2–3: the master switch or the context switch is off.
     */
    private function switchedOff(string $channel, ?ContextRef $context): bool
    {
        $master = $this->stored(PreferenceKey::account(PreferenceKey::ANY, $channel)) ?? $this->stored(PreferenceKey::account(PreferenceKey::ANY, PreferenceKey::ANY));
        if ($master instanceof ChannelPreference && !$master->enabled) {
            return true;
        }

        return $context instanceof ContextRef && !$this->contextEnabled($context);
    }

    /**
     * Steps 4–5: the context override, else the account row (exact channel first, then `*`); null = default.
     */
    private function stack(string $category, string $channel, ?ContextRef $context): ?ChannelPreference
    {
        $levels = $context instanceof ContextRef ? [$context, null] : [null];
        foreach ($levels as $level) {
            $found = $this->stored(PreferenceKey::of($level, $category, $channel)) ?? $this->stored(PreferenceKey::of($level, $category, PreferenceKey::ANY));
            if ($found instanceof ChannelPreference) {
                return $found;
            }
        }

        return null;
    }

    private function categoryDefault(string $category, string $channel): bool
    {
        return $this->defaults[$category][$channel] ?? false;
    }

    private function id(PreferenceKey $key): string
    {
        return $key->contextKey . "\0" . $key->category . "\0" . $key->channel;
    }
}
