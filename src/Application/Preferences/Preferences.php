<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Preferences;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\NotifyKitBundle\Application\UnitOfWorkInterface;
use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\CellWrite;
use Nowo\NotifyKitBundle\Domain\PreferenceStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_values;
use function count;
use function in_array;
use function sprintf;
use function strcmp;
use function usort;

/**
 * Default {@see PreferencesInterface} (contract §4.2, §5.1, §8.3) and the preference gate of `notify()`.
 *
 * - Reads: the person's stored rows over the registry defaults ({@see PreferenceDefaults}), resolved by
 *   {@see PreferenceMatrix} in Beacon's merge order.
 * - `save()`: validates every change (a category and channel of the matrix or `*`, an unlocked cell, a scope the
 *   configuration allows, a context the context provider lists **for this person**), stores only deviations (a choice
 *   equal to the cell's default deletes the row) and writes every cell in one transaction, in key order, each with one
 *   conditional statement (optimistic concurrency): a stale cell aborts the whole save with
 *   {@see PreferenceConflictException}; a lock conflict surfaces as `TransientStorageFailure`. After the commit:
 *   {@see PreferencesChangedEvent} (keys only).
 *
 * Stateless (worker mode): everything a call needs lives in the call.
 */
final readonly class Preferences implements PreferencesInterface
{
    /**
     * @param list<string> $scopes `preferences.scopes`
     */
    public function __construct(
        private PreferenceStoreInterface $store,
        private UnitOfWorkInterface $transactions,
        private TypeRegistryInterface $types,
        private ContextProviderInterface $contexts,
        private ?EventDispatcherInterface $dispatcher = null,
        private ?ClockInterface $clock = null,
        private array $scopes = ['all', 'involved'],
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function matrixFor(RecipientRef $recipient, ?ContextRef $context = null): PreferenceMatrix
    {
        return PreferenceDefaults::of($this->types)->matrix($recipient, $this->store->rows($recipient, $context?->key()));
    }

    /**
     * {@inheritDoc}
     */
    public function save(RecipientRef $recipient, PreferenceChangeSet $changes): array
    {
        if ($changes->isEmpty()) {
            return [];
        }
        $matrix  = $this->matrixFor($recipient);
        $allowed = null;
        /** @var list<array{PreferenceChange, ChannelPreference|null}> $plan */
        $plan = [];
        foreach ($changes->changes as $change) {
            if (!$change->key->isAccount()) {
                $allowed ??= $this->contextKeys($recipient);
                $this->assertContext($change->key->contextKey, $allowed);
            }
            $this->assertCell($matrix, $change);
            $choice = $change->choice();
            $plan[] = [$change, $choice->sameChoice($matrix->defaultFor($change->key)) ? null : $choice];
        }
        // One lock order for every writer: cells sorted by context, category, channel.
        usort($plan, static fn (array $a, array $b): int => strcmp(self::sortKey($a[0]->key), self::sortKey($b[0]->key)));

        $now  = $this->now();
        $work = function () use ($recipient, $plan, $now): array {
            $changed   = [];
            $conflicts = [];
            foreach ($plan as [$change, $next]) {
                $outcome = $this->store->write($recipient, $change->key, $change->expectation(), $next, $now);
                if ($outcome === CellWrite::Conflict) {
                    $conflicts[] = $change->key;
                } elseif ($outcome === CellWrite::Changed) {
                    $changed[] = $change->key;
                }
            }
            if ($conflicts !== []) {
                // Thrown inside the unit of work: the transaction rolls back, nothing of the save stays.
                throw new PreferenceConflictException($conflicts);
            }

            return $changed;
        };
        /** @param list<PreferenceKey> $changed */
        $then = function (array $changed) use ($recipient): void {
            if ($changed !== []) {
                $this->dispatcher?->dispatch(new PreferencesChangedEvent($recipient, array_values($changed)));
            }
        };

        return $this->transactions->run($work, $then);
    }

    /**
     * {@inheritDoc}
     */
    public function resetContext(RecipientRef $recipient, ContextRef $context): int
    {
        $this->assertContext($context->key(), $this->contextKeys($recipient));
        $work = fn (): array => $this->store->deleteOverrides($recipient, $context->key());
        /** @param list<PreferenceKey> $deleted */
        $then = function (array $deleted) use ($recipient): void {
            if ($deleted !== []) {
                $this->dispatcher?->dispatch(new PreferencesChangedEvent($recipient, array_values($deleted), true));
            }
        };

        return count($this->transactions->run($work, $then));
    }

    /**
     * The contexts $recipient may tune (the context provider's list for this person).
     *
     * @return list<ContextChoice>
     */
    public function contextsFor(RecipientRef $recipient): array
    {
        return $this->contexts->contextsFor($recipient);
    }

    /**
     * The `notify()` gate (contract §3): for each of $recipients, whether $channel of $type lets the notification
     * through in $context. Reads only when the type does not lock the channel (one query for every recipient).
     *
     * @param list<RecipientRef> $recipients
     * @param list<RecipientRef> $involved the request's involved people
     *
     * @return array<string, bool> by {@see RecipientRef::key()}
     */
    public function lets(TypeDefinition $type, string $channel, array $recipients, ?ContextRef $context, array $involved): array
    {
        $out = [];
        if (in_array($channel, $type->lockedChannels, true)) {
            foreach ($recipients as $recipient) {
                $out[$recipient->key()] = true;
            }

            return $out;
        }
        $rows     = $this->store->rowsOfMany($recipients, $context instanceof ContextRef ? ['', $context->key()] : [''], [$type->category, PreferenceKey::ANY], [$channel, PreferenceKey::ANY]);
        $defaults = PreferenceDefaults::of($this->types);
        foreach ($recipients as $recipient) {
            $isInvolved = false;
            foreach ($involved as $person) {
                $isInvolved = $isInvolved || $person->equals($recipient);
            }
            $out[$recipient->key()] = $defaults->matrix($recipient, $rows[$recipient->key()] ?? [])->resolveType($type, $channel, $context)->lets($isInvolved);
        }

        return $out;
    }

    /**
     * @param list<string> $allowed
     */
    private function assertContext(string $contextKey, array $allowed): void
    {
        if (!in_array($contextKey, $allowed, true)) {
            throw new InvalidNotificationArgumentException('Preferences: this context is not one the person can tune (the context provider does not list it).');
        }
    }

    private function assertCell(PreferenceMatrix $matrix, PreferenceChange $change): void
    {
        $key = $change->key;
        if ($key->category !== PreferenceKey::ANY && !in_array($key->category, $matrix->categories, true)) {
            throw new InvalidNotificationArgumentException(sprintf('Preferences: unknown category "%s".', $key->category));
        }
        if ($key->channel !== PreferenceKey::ANY && !in_array($key->channel, $matrix->channels, true)) {
            throw new InvalidNotificationArgumentException(sprintf('Preferences: unknown channel "%s".', $key->channel));
        }
        if ($key->category !== PreferenceKey::ANY && $key->channel !== PreferenceKey::ANY && $matrix->isLocked($key->category, $key->channel)) {
            throw new InvalidNotificationArgumentException(sprintf('Preferences: the cell %s is locked.', $key->toString()));
        }
        if ($change->scope !== Scope::All && ($key->isSwitch() || !in_array($change->scope->value, $this->scopes, true))) {
            throw new InvalidNotificationArgumentException(sprintf('Preferences: scope "%s" is not allowed for %s.', $change->scope->value, $key->toString()));
        }
    }

    /**
     * @return list<string>
     */
    private function contextKeys(RecipientRef $recipient): array
    {
        $keys = [];
        foreach ($this->contexts->contextsFor($recipient) as $choice) {
            $keys[] = $choice->ref->key();
        }

        return $keys;
    }

    private static function sortKey(PreferenceKey $key): string
    {
        return $key->contextKey . "\0" . $key->category . "\0" . $key->channel;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
