<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Message;

/**
 * Retention as a Messenger message (contract §9): route it to the host's maintenance transport. The handler deletes
 * read rows older than `retention.read_days`, trims every recipient to `retention.keep_per_recipient` rows and
 * forgets expired dedupe keys, in batches of `retention.batch_size`; after `messenger.max_seconds` it dispatches the
 * remainder as a new message with the cursor (keyset, no offsets).
 */
final readonly class PruneNotificationsMessage
{
    /** First phase: read rows older than the cutoff. */
    public const PHASE_READ = 'read';

    /** Second phase: rows beyond the per-recipient cap. */
    public const PHASE_TRIM = 'trim';

    /** Third phase: expired dedupe keys. */
    public const PHASE_DEDUPE = 'dedupe';

    /**
     * @param string $phase where to resume
     * @param string|null $afterRecipientType trim phase: resume after this recipient (keyset)
     * @param string|null $afterRecipientId trim phase: resume after this recipient (keyset)
     */
    public function __construct(
        public string $phase = self::PHASE_READ,
        public ?string $afterRecipientType = null,
        public ?string $afterRecipientId = null,
    ) {
    }
}
