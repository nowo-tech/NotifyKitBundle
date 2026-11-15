<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Retention;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Psr\Clock\ClockInterface;

use function count;

/**
 * Retention (contract §9, Q-O8): deletes read rows created more than `retention.read_days` ago (unread rows are never
 * deleted by age), trims every recipient to its newest `retention.keep_per_recipient` rows (read or not) and forgets
 * expired dedupe keys. Each batch of `retention.batch_size` rows is its own short transaction; the run stops after
 * `messenger.max_seconds` and returns a keyset cursor to resume from (REQ-MESSENGER-007 / 009).
 *
 * Stateless (worker mode): the cursor travels in the message.
 */
final readonly class Retention
{
    /**
     * @param int $readDays `retention.read_days`
     * @param int $keepPerRecipient `retention.keep_per_recipient`
     * @param int $batchSize `retention.batch_size`
     * @param int $maxSeconds `messenger.max_seconds`
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private NotifyKitTransactions $transactions,
        private int $readDays = 90,
        private int $keepPerRecipient = 500,
        private int $batchSize = 500,
        private int $maxSeconds = 50,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Runs from $cursor until done or out of time.
     */
    public function run(PruneNotificationsMessage $cursor = new PruneNotificationsMessage()): RetentionReport
    {
        $now      = $this->now();
        $deadline = new DateTimeImmutable('@' . ($now->getTimestamp() + $this->maxSeconds));
        $phase    = $cursor->phase;
        $read     = 0;
        $trimmed  = 0;
        $dedupe   = 0;

        if ($phase !== PruneNotificationsMessage::PHASE_TRIM && $phase !== PruneNotificationsMessage::PHASE_DEDUPE) {
            $cutoff = new DateTimeImmutable('@' . ($now->getTimestamp() - $this->readDays * 86400));
            do {
                $deleted = $this->transactions->run(fn (): int => $this->store->deleteReadBefore($cutoff, $this->batchSize));
                $read += $deleted;
                if ($deleted >= $this->batchSize && $this->outOfTime($deadline)) {
                    return new RetentionReport($read, 0, 0, new PruneNotificationsMessage(PruneNotificationsMessage::PHASE_READ));
                }
            } while ($deleted >= $this->batchSize);
            $phase = PruneNotificationsMessage::PHASE_TRIM;
        }

        if ($phase === PruneNotificationsMessage::PHASE_TRIM) {
            $after = $cursor->afterRecipientType !== null && $cursor->afterRecipientId !== null ? new RecipientRef($cursor->afterRecipientType, $cursor->afterRecipientId) : null;
            do {
                $recipients = $this->store->recipientsOver($this->keepPerRecipient, $after, $this->batchSize);
                foreach ($recipients as $recipient) {
                    do {
                        $deleted = $this->transactions->run(fn (): int => $this->store->trimRecipient($recipient, $this->keepPerRecipient, $this->batchSize));
                        $trimmed += $deleted;
                    } while ($deleted >= $this->batchSize);
                    $after = $recipient;
                    if ($this->outOfTime($deadline)) {
                        return new RetentionReport($read, $trimmed, 0, new PruneNotificationsMessage(PruneNotificationsMessage::PHASE_TRIM, $recipient->type, $recipient->id));
                    }
                }
            } while (count($recipients) >= $this->batchSize);
        }

        do {
            $deleted = $this->transactions->run(fn (): int => $this->store->deleteExpiredDedupe($now, $this->batchSize));
            $dedupe += $deleted;
            if ($deleted >= $this->batchSize && $this->outOfTime($deadline)) {
                return new RetentionReport($read, $trimmed, $dedupe, new PruneNotificationsMessage(PruneNotificationsMessage::PHASE_DEDUPE));
            }
        } while ($deleted >= $this->batchSize);

        return new RetentionReport($read, $trimmed, $dedupe);
    }

    private function outOfTime(DateTimeImmutable $deadline): bool
    {
        return $this->now() >= $deadline;
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
