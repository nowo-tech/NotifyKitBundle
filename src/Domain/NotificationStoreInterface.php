<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Storage port of the inbox rows and dedupe keys. Writes run inside the caller's transaction (the bundle's unit of
 * work, `Application\UnitOfWorkInterface`); every read by recipient re-checks the
 * recipient with `===` in PHP (audit LQ).
 *
 * @internal
 */
interface NotificationStoreInterface
{
    /**
     * Coalesces $draft into the latest unread row of the same recipient, type and target created at or after
     * $repeatSince (null = never coalesce), or inserts it. Must run inside a transaction.
     */
    public function record(Notification $draft, ?DateTimeImmutable $repeatSince): RecordOutcome;

    /**
     * Takes a dedupe key until $until; false when a live marker already holds it. Must run inside a transaction.
     */
    public function claimDedupe(string $keyHash, DateTimeImmutable $now, DateTimeImmutable $until): bool;

    /**
     * Deletes the rows of a context among the next $limit rows after $afterId (keyset by id; null = from the start).
     */
    public function forgetContext(ContextRef $context, ?string $afterId, int $limit): BatchResult;

    /**
     * Deletes at most $limit read rows created before $cutoff.
     *
     * @return int rows deleted
     */
    public function deleteReadBefore(DateTimeImmutable $cutoff, int $limit): int;

    /**
     * Recipients (type, id as stored) with more than $keep rows, after $after in (type, id) order (keyset). Raw pairs:
     * a stored id the value objects would refuse today is still trimmed.
     *
     * @param array{string, string}|null $after
     *
     * @return list<array{string, string}>
     */
    public function recipientsOver(int $keep, ?array $after, int $limit): array;

    /**
     * Deletes at most $limit rows of the recipient beyond its newest $keep.
     *
     * @return int rows deleted
     */
    public function trimRecipient(string $recipientType, string $recipientId, int $keep, int $limit): int;

    /**
     * Deletes at most $limit dedupe markers expired at $now.
     *
     * @return int markers deleted
     */
    public function deleteExpiredDedupe(DateTimeImmutable $now, int $limit): int;

    /**
     * The newest rows of $recipient, visible or not (maintenance and tests).
     *
     * @return list<Notification>
     */
    public function ofRecipient(RecipientRef $recipient, int $limit): array;

    /**
     * How many unread rows $recipient has; with $visibility, only the visible ones.
     */
    public function countUnread(RecipientRef $recipient, ?Visibility $visibility = null): int;

    /**
     * The distinct (context, stored type) pairs of $recipient's rows that have a context (the candidates the
     * visibility resolver asks the access checker about). A stored value the value objects refuse is left out (its
     * rows stay hidden).
     *
     * @return list<array{ContextRef, string}>
     */
    public function contextTypes(RecipientRef $recipient): array;

    /**
     * At most $limit visible rows of $recipient matching $filter, newest first (`created_at DESC, id DESC`), after
     * $after.
     *
     * @return list<Notification>
     */
    public function page(RecipientRef $recipient, Visibility $visibility, InboxFilter $filter, ?InboxCursor $after, int $limit): array;

    /**
     * The row $id of $recipient (visible or not: the caller re-checks visibility); null = no such row of theirs.
     */
    public function find(RecipientRef $recipient, NotificationId $id): ?Notification;

    /**
     * Sets `read_at` to $readAt (null = unread again) on the rows $ids of $recipient whose state differs. Must run
     * inside a transaction.
     *
     * @param list<string> $ids rows already checked to be $recipient's and visible
     *
     * @return int rows changed
     */
    public function changeReadState(RecipientRef $recipient, array $ids, ?DateTimeImmutable $readAt): int;

    /**
     * The ids of visible unread rows of $recipient among the next $limit unread rows after $afterId (keyset by id),
     * and the cursor of the next batch (the last id fetched when the batch was full; null = done).
     *
     * @return array{list<string>, string|null}
     */
    public function unreadIds(RecipientRef $recipient, Visibility $visibility, ?string $afterId, int $limit): array;

    /**
     * Counts of the operator page: all rows, unread rows, live dedupe markers and the rows created since $since per
     * type (at most $types types, most first).
     */
    public function statistics(DateTimeImmutable $since, DateTimeImmutable $now, int $types): InboxStatistics;
}
