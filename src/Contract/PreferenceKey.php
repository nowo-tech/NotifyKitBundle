<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function strpos;
use function substr;

/**
 * One cell of the preferences storage (contract §5.1 `notify_preference`): a context (`''` = account level), a category
 * and a channel. `*` as category is the master switch (account level) or the context switch; `*` as channel covers
 * every channel of the category.
 */
final readonly class PreferenceKey
{
    /** Wildcard category (master / context switch) or channel (every channel). */
    public const ANY = '*';

    /** Longest stored context key (`<type>:<id>`). */
    public const MAX_CONTEXT_KEY_LENGTH = 100;

    /**
     * @param string $contextKey `''` (account level) or a {@see ContextRef::key()}
     * @param string $category a category key or `*`
     * @param string $channel a channel key or `*`
     */
    private function __construct(
        public string $contextKey,
        public string $category,
        public string $channel,
    ) {
        if ($category !== self::ANY) {
            Guard::key('PreferenceKey category', $category);
        }
        if ($channel !== self::ANY) {
            Guard::key('PreferenceKey channel', $channel);
        }
        if ($category === self::ANY && $channel !== self::ANY && $contextKey !== '') {
            throw new InvalidNotificationArgumentException('PreferenceKey: a context switch covers every channel (category "*" needs channel "*" in a context).');
        }
    }

    /**
     * An account-level cell.
     */
    public static function account(string $category, string $channel): self
    {
        return new self('', $category, $channel);
    }

    /**
     * A cell of $context (an override, or the context switch with category and channel `*`).
     */
    public static function forContext(ContextRef $context, string $category, string $channel): self
    {
        return new self($context->key(), $category, $channel);
    }

    /**
     * A cell of $context, or of the account when $context is null.
     */
    public static function of(?ContextRef $context, string $category, string $channel): self
    {
        return $context instanceof ContextRef ? self::forContext($context, $category, $channel) : self::account($category, $channel);
    }

    /**
     * A cell as stored (`context_key` read from the table): the context key must be `''` or `<type>:<id>` with a valid
     * {@see ContextRef}.
     *
     * @throws InvalidNotificationArgumentException the stored values are not a valid key
     */
    public static function stored(string $contextKey, string $category, string $channel): self
    {
        if ($contextKey !== '') {
            $colon = strpos($contextKey, ':');
            if ($colon === false) {
                throw new InvalidNotificationArgumentException('PreferenceKey context key must be "" or "<type>:<id>".');
            }
            new ContextRef(substr($contextKey, 0, $colon), substr($contextKey, $colon + 1));
        }

        return new self($contextKey, $category, $channel);
    }

    /**
     * Whether this is an account-level cell.
     */
    public function isAccount(): bool
    {
        return $this->contextKey === '';
    }

    /**
     * Whether this is the master switch (account) or a context switch.
     */
    public function isSwitch(): bool
    {
        return $this->category === self::ANY;
    }

    /**
     * `<context or "account">/<category>/<channel>`, e.g. `account/issues/mail` or `project:42/issues/mail`. For logs, events
     * and the audit trail; ids may hold `/`, so it is not meant to be parsed back.
     */
    public function toString(): string
    {
        return ($this->contextKey === '' ? 'account' : $this->contextKey) . '/' . $this->category . '/' . $this->channel;
    }

    /**
     * Exact comparison (`===`) of the three parts.
     */
    public function equals(self $other): bool
    {
        return $this->contextKey === $other->contextKey && $this->category === $other->category && $this->channel === $other->channel;
    }
}
