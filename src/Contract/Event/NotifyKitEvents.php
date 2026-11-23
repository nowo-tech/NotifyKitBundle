<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Event;

/**
 * Names of the bundle events (the event class names, contract §6.6). Every event is dispatched after the write
 * commits and carries references only: never params, URLs or rendered text.
 */
final class NotifyKitEvents
{
    /** {@see NotificationRecordedEvent}. */
    public const RECORDED = NotificationRecordedEvent::class;

    /** {@see NotificationReadEvent}. */
    public const READ = NotificationReadEvent::class;

    /** {@see NotificationSkippedEvent}. */
    public const SKIPPED = NotificationSkippedEvent::class;

    /** {@see PreferencesChangedEvent} (S3). */
    public const PREFERENCES_CHANGED = PreferencesChangedEvent::class;
}
