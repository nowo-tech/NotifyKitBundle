<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\NotifyKitBundle\Application\Realtime\UnreadBadge;
use Nowo\NotifyKitBundle\Application\Visibility\VisibilityResolver;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\NotificationReadEvent;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\InboxPage;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationView;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\BatchResult;
use Nowo\NotifyKitBundle\Domain\InboxCursor;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;

use function array_map;
use function array_slice;
use function count;
use function max;
use function min;

/**
 * Default {@see InboxInterface} (contract §4.2, §6.1).
 *
 * - Reads: one recipient (exact, re-checked in PHP) and the read-time visibility of {@see VisibilityResolver}, built
 *   per call; keyset pages newest first.
 * - Row operations look the row up by id **and** recipient, re-check its visibility, then update it in one
 *   transaction (`UPDATE … WHERE id = ? AND recipient … AND read_at IS [NOT] NULL`: idempotent, no read-modify-write);
 *   *mark all* walks the visible unread ids in batches of `retention.batch_size`, one transaction each.
 * - After the commit: {@see NotificationReadEvent} (ids only) and the realtime badge, only when something changed.
 *
 * Stateless (worker mode): everything a call needs lives in the call.
 */
final readonly class Inbox implements InboxInterface
{
    /** Largest page. */
    public const MAX_PAGE = 200;

    /**
     * @param int $batchSize `retention.batch_size`: rows deleted or marked per transaction
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private UnitOfWorkInterface $transactions,
        private VisibilityResolver $visibility,
        private UnreadBadge $badge,
        private ?EventDispatcherInterface $dispatcher = null,
        private int $batchSize = 500,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function unreadCount(RecipientRef $recipient): int
    {
        return $this->store->countUnread($recipient, $this->visibility->for($recipient));
    }

    /**
     * {@inheritDoc}
     */
    public function page(RecipientRef $recipient, InboxFilter $filter, ?string $after, int $limit): InboxPage
    {
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $rows  = $this->store->page($recipient, $this->visibility->for($recipient), $filter, InboxCursor::parse($after), $limit + 1);
        $next  = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $next = InboxCursor::after($rows[$limit - 1])->toString();
        }

        return new InboxPage(array_map(self::view(...), $rows), $next);
    }

    /**
     * {@inheritDoc}
     */
    public function markRead(RecipientRef $recipient, NotificationId $id): bool
    {
        return $this->toggle($recipient, $id, true);
    }

    /**
     * {@inheritDoc}
     */
    public function markUnread(RecipientRef $recipient, NotificationId $id): bool
    {
        return $this->toggle($recipient, $id, false);
    }

    /**
     * {@inheritDoc}
     */
    public function markAllRead(RecipientRef $recipient): int
    {
        $visibility = $this->visibility->for($recipient);
        $now        = $this->now();
        $total      = 0;
        $cursor     = null;
        do {
            [$ids, $cursor] = $this->store->unreadIds($recipient, $visibility, $cursor, $this->batchSize);
            $before         = $total;
            // The last batch announces the whole walk after it commits (the earlier batches are committed already).
            $then = $cursor !== null ? null : function (int $changed) use ($recipient, $before): void {
                if ($before + $changed === 0) {
                    return;
                }
                $this->dispatcher?->dispatch(new NotificationReadEvent($recipient, [], true));
                $this->badge->publish($recipient);
            };
            $work = fn (): int => $this->store->changeReadState($recipient, $ids, $now);
            $total += $this->transactions->run($work, $then);
        } while ($cursor !== null);

        return $total;
    }

    /**
     * {@inheritDoc}
     */
    public function forgetContext(ContextRef $context): int
    {
        $total  = 0;
        $cursor = null;
        do {
            $batch = $this->transactions->run(fn (): BatchResult => $this->store->forgetContext($context, $cursor, $this->batchSize));
            $total += $batch->deleted;
            $cursor = $batch->cursor;
        } while ($cursor !== null);

        return $total;
    }

    /**
     * The public view of a stored row.
     */
    public static function view(Notification $row): NotificationView
    {
        return new NotificationView(
            $row->id(),
            $row->recipient(),
            $row->type(),
            $row->category(),
            $row->actor(),
            $row->context(),
            $row->target(),
            $row->url(),
            $row->params(),
            $row->repeats(),
            $row->createdAt(),
            $row->readAt(),
        );
    }

    private function toggle(RecipientRef $recipient, NotificationId $id, bool $read): bool
    {
        $row = $this->store->find($recipient, $id);
        if (!$row instanceof Notification || !$row->recipient()->equals($recipient)
            || !$this->visibility->for($recipient)->allows($row->context(), $row->type())) {
            return false;
        }
        $readAt = $read ? $this->now() : null;
        $work   = fn (): int => $this->store->changeReadState($recipient, [$id->value], $readAt);
        $then   = function (int $changed) use ($recipient, $id, $read): void {
            if ($changed === 0) {
                return;
            }
            $this->dispatcher?->dispatch(new NotificationReadEvent($recipient, [$id], false, $read));
            $this->badge->publish($recipient);
        };
        $this->transactions->run($work, $then);

        return true;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
