<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function count;
use function sprintf;

/**
 * The changes of one save (contract §4.2 `PreferencesInterface::save()`): applied together in one transaction, all
 * or nothing. One change per cell; at most {@see self::MAX_CHANGES}.
 */
final readonly class PreferenceChangeSet
{
    /** Most changes per save. */
    public const MAX_CHANGES = 500;

    /** @var list<PreferenceChange> */
    public array $changes;

    /**
     * @param list<PreferenceChange> $changes
     */
    public function __construct(array $changes)
    {
        $this->assertChanges($changes);
        $this->changes = $changes;
    }

    /**
     * Whether there is nothing to change.
     */
    public function isEmpty(): bool
    {
        return $this->changes === [];
    }

    /**
     * Refuses anything but a list of at most {@see self::MAX_CHANGES} changes, one per cell.
     *
     * @param array<mixed> $changes
     */
    private function assertChanges(array $changes): void
    {
        if (!array_is_list($changes) || count($changes) > self::MAX_CHANGES) {
            throw new InvalidNotificationArgumentException(sprintf('PreferenceChangeSet holds a list of at most %d changes.', self::MAX_CHANGES));
        }
        $seen = [];
        foreach ($changes as $change) {
            if (!$change instanceof PreferenceChange) {
                throw new InvalidNotificationArgumentException('PreferenceChangeSet holds PreferenceChange objects only.');
            }
            $id = $change->key->contextKey . "\0" . $change->key->category . "\0" . $change->key->channel;
            if (isset($seen[$id])) {
                throw new InvalidNotificationArgumentException(sprintf('PreferenceChangeSet changes the cell %s twice.', $change->key->toString()));
            }
            $seen[$id] = true;
        }
    }
}
