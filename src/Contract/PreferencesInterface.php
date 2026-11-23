<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * A person's notification preferences (contract §4.2, slice S3): the category × channel matrix, the master switch,
 * per-context switches and overrides, scope `all` / `involved`. Quiet hours and digests join in S4.
 *
 * Only deviations from the defaults are stored: setting a cell back to its default deletes its row. A context cell is
 * accepted only for a context the context provider lists for the person (deny by default).
 */
interface PreferencesInterface
{
    /**
     * The person's matrix. With $context: the account rows and that context's rows; without: every row of the person
     * (account rows, every context switch and override). Read from storage on every call.
     *
     * @phpstan-impure
     */
    public function matrixFor(RecipientRef $recipient, ?ContextRef $context = null): PreferenceMatrix;

    /**
     * Applies $changes in one transaction, all or nothing (cells in key order), then dispatches
     * {@see Event\PreferencesChangedEvent} after the commit when something changed.
     *
     * @throws InvalidNotificationArgumentException a category or channel outside the matrix, a locked cell, or a
     *                                              context the provider does not list for the person
     * @throws PreferenceConflictException a cell no longer holds what its change expected (nothing written)
     * @throws TransientStorageFailure a deadlock or lock wait (nothing written; retry)
     *
     * @return list<PreferenceKey> the cells written or deleted
     */
    public function save(RecipientRef $recipient, PreferenceChangeSet $changes): array;

    /**
     * *Reset to account settings*: deletes every override of $context (its switch stays).
     *
     * @throws InvalidNotificationArgumentException a context the provider does not list for the person
     * @throws TransientStorageFailure a deadlock or lock wait (nothing written; retry)
     *
     * @return int overrides deleted
     */
    public function resetContext(RecipientRef $recipient, ContextRef $context): int;
}
