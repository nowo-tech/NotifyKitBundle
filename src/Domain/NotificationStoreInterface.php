<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;

/**
 * Storage port of the inbox rows and dedupe keys. Writes run inside the caller's transaction (the bundle's
 * {@see NotifyKitTransactions}); every read by recipient re-checks
 * the recipient with `===` in PHP (audit LQ).
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
     * The newest rows of $recipient (the S2 inbox builds on it).
     *
     * @return list<Notification>
     */
    public function ofRecipient(RecipientRef $recipient, int $limit): array;

    /**
     * How many unread rows $recipient has.
     */
    public function countUnread(RecipientRef $recipient): int;
}
