<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Audit;

use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;

use function array_map;
use function array_slice;
use function count;

/**
 * Records {@see PreferencesChangedEvent} in the audit trail as `notify.preferences_changed` (contract §6.6, §6.7): the
 * person, the cells that changed (at most {@see self::MAX_KEYS}, never their values) and whether it was a context reset.
 * Runs after the save committed. Registered with the AuditKit bridge.
 *
 * @internal
 */
final readonly class PreferencesAuditListener
{
    /** Most cell keys written into one audit row. */
    public const MAX_KEYS = 50;

    /**
     * Holds the audit trail.
     */
    public function __construct(private AuditTrailInterface $trail)
    {
    }

    /**
     * Records the change.
     */
    public function __invoke(PreferencesChangedEvent $event): void
    {
        $this->trail->record(AuditTrailInterface::PREFERENCES_CHANGED, $event->recipient, [
            'recipient_type' => $event->recipient->type,
            'changed'        => array_map(static fn (PreferenceKey $key): string => $key->toString(), array_slice($event->changedKeys, 0, self::MAX_KEYS)),
            'count'          => count($event->changedKeys),
            'reset'          => $event->reset,
        ]);
    }
}
