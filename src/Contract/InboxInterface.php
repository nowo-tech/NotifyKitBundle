<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\TransactionContract\TransientStorageFailure;

/**
 * The inbox (contract §4.2). Implemented by the bundle only: do not implement it in a host.
 *
 * Every read is scoped to one recipient (exact match, re-checked in PHP) and re-checks visibility (contract §6.1): a
 * personal row is always visible; a row with a context only while the access checker still grants the type's
 * permission there (unknown types: `access.default_permission`), so a lost permission hides the row and lowers the
 * count at once. A row of another recipient, or a hidden one, does not exist for the caller.
 */
interface InboxInterface
{
    /**
     * Unread visible rows of $recipient.
     */
    public function unreadCount(RecipientRef $recipient): int;

    /**
     * One keyset page of the visible rows of $recipient, newest first.
     *
     * @param string|null $after the `next` cursor of the previous page; null or an invalid cursor = the first page
     * @param int $limit 1–200 (clamped)
     */
    public function page(RecipientRef $recipient, InboxFilter $filter, ?string $after, int $limit): InboxPage;

    /**
     * Marks one row read (idempotent). After the update commits: `NotificationReadEvent` and the realtime badge.
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 409 + CONFLICT_001)
     *
     * @return bool false = not a row of $recipient or not visible to them (answer 404)
     */
    public function markRead(RecipientRef $recipient, NotificationId $id): bool;

    /**
     * Marks one row unread again (idempotent); same rules as {@see self::markRead()}.
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 409 + CONFLICT_001)
     *
     * @return bool false = not a row of $recipient or not visible to them (answer 404)
     */
    public function markUnread(RecipientRef $recipient, NotificationId $id): bool;

    /**
     * Marks every visible unread row of $recipient read, in batches of `retention.batch_size` (one transaction each).
     * Hidden rows stay unread.
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 409 + CONFLICT_001;
     *                                 the batches already committed stay read)
     *
     * @return int rows marked read
     */
    public function markAllRead(RecipientRef $recipient): int;

    /**
     * Deletes every inbox row of a context (no foreign key: the host calls it when a project or board is deleted,
     * contract Q-B6).
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 409 +
     *                                 CONFLICT_001, or retry); the chunks already deleted stay deleted
     *
     * @return int rows deleted
     */
    public function forgetContext(ContextRef $context): int;
}
