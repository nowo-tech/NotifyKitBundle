<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Event;

use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * A person's preferences changed, after the save committed (contract §6.6): the cells that changed, never their
 * values. The AuditKit bridge records it as `notify.preferences_changed`.
 */
final readonly class PreferencesChangedEvent
{
    /**
     * @param list<PreferenceKey> $changedKeys the cells written or deleted
     * @param bool $reset the change was *Reset to account settings* of one context
     */
    public function __construct(
        public RecipientRef $recipient,
        public array $changedKeys,
        public bool $reset = false,
    ) {
    }
}
