<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\NotifyKitBundle\Application\Preferences\Preferences;
use Nowo\NotifyKitBundle\Application\Realtime\UnreadBadge;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\NotificationRecordedEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationSkippedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\NotifyResult;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\SkipReason;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Domain\RecordOutcome;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

use function count;
use function hash;
use function ksort;
use function sprintf;

use const SORT_STRING;

/**
 * Default {@see NotifierInterface} (contract §3, §5.3, §6.1), S1: the in-app channel; S3: its preferences.
 *
 * 1. Validates the request size (`fan_out.max_recipients`, for `recipients` and `involved`: audit QL-I3) and resolves
 *    the type (unknown keys get the generic one).
 * 2. Skips the actor and every recipient who may not open the target: a row with a context needs the type's
 *    permission granted there by the access checker (deny by default; a type without a permission is personal only).
 *    Then, when the type does not lock the in-app channel (`in_app_optional`), the person's preferences decide (S3:
 *    master switch, context switch, overrides, scope `involved`; one read for every recipient).
 * 3. Writes in one transaction, recipients in key order (one lock order, no deadlock between two requests): takes the
 *    dedupe key, then coalesces or inserts each row.
 * 4. Dispatches the events after the commit (the outermost one when the call joined a host transaction), then
 *    publishes each written recipient's unread count when a realtime publisher is configured (S2).
 *
 * Stateless (worker mode): everything a call needs lives in the call.
 */
final readonly class Notifier implements NotifierInterface
{
    /** How long a dedupe key holds. */
    public const DEDUPE_SECONDS = 86400;

    /**
     * @param int $repeatWindowSeconds `inbox.repeat_window_seconds` (0 = never coalesce)
     * @param int $maxRecipients `fan_out.max_recipients`
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private UnitOfWorkInterface $transactions,
        private TypeRegistryInterface $types,
        private RecipientAccessCheckerInterface $access,
        private ?EventDispatcherInterface $dispatcher = null,
        private int $repeatWindowSeconds = 600,
        private int $maxRecipients = 1000,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private ?UnreadBadge $badge = null,
        private ?Preferences $preferences = null,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function notify(NotificationRequest $request): NotifyResult
    {
        if (count($request->recipients) > $this->maxRecipients) {
            throw new InvalidNotificationArgumentException(sprintf('A request has at most %d recipients (fan_out.max_recipients): split it.', $this->maxRecipients));
        }
        if (count($request->involved) > $this->maxRecipients) {
            throw new InvalidNotificationArgumentException(sprintf('A request lists at most %d involved people (fan_out.max_recipients).', $this->maxRecipients));
        }
        $type = $this->types->type($request->type);

        $unique = [];
        foreach ($request->recipients as $recipient) {
            $unique[$recipient->key()] ??= $recipient;
        }
        ksort($unique, SORT_STRING);

        /** @var list<array{RecipientRef, SkipReason}> $skipped */
        $skipped    = [];
        $allowed    = [];
        $candidates = [];
        foreach ($unique as $recipient) {
            $reason = $this->refusal($recipient, $type, $request);
            if ($reason instanceof SkipReason) {
                $skipped[] = [$recipient, $reason];
            } else {
                $candidates[] = $recipient;
            }
        }
        $gate = $this->preferenceGate($type, $candidates, $request);
        foreach ($candidates as $recipient) {
            if ($gate[$recipient->key()] ?? true) {
                $allowed[] = $recipient;
            } else {
                $skipped[] = [$recipient, SkipReason::Preference];
            }
        }

        $skippedActor      = 0;
        $skippedAccess     = 0;
        $skippedPreference = 0;
        foreach ($skipped as [$recipient, $reason]) {
            ($this->logger ?? new NullLogger())->info('nowo_notify_kit: in-app notification skipped.', ['type' => $request->type, 'recipient' => $recipient->key(), 'reason' => $reason->value]);
            $skippedActor += $reason === SkipReason::Actor ? 1 : 0;
            $skippedAccess += $reason === SkipReason::Access ? 1 : 0;
            $skippedPreference += $reason === SkipReason::Preference ? 1 : 0;
        }
        if ($allowed === []) {
            $this->dispatch($request, $skipped, $allowed, []);

            return new NotifyResult(skippedActor: $skippedActor, skippedAccess: $skippedAccess, skippedPreference: $skippedPreference);
        }

        $now   = $this->now();
        $since = $type->coalesce && $this->repeatWindowSeconds > 0 ? new DateTimeImmutable('@' . ($now->getTimestamp() - $this->repeatWindowSeconds)) : null;
        /** @return list<RecordOutcome>|null */
        $work = function () use ($request, $type, $allowed, $now, $since): ?array {
            if ($request->dedupeKey !== null
                && !$this->store->claimDedupe(hash('sha256', $request->dedupeKey), $now, new DateTimeImmutable('@' . ($now->getTimestamp() + self::DEDUPE_SECONDS)))) {
                return null;
            }
            $outcomes = [];
            foreach ($allowed as $recipient) {
                $outcomes[] = $this->store->record($this->draft($request, $type, $recipient, $now), $since);
            }

            return $outcomes;
        };
        /** @param array<int, RecordOutcome>|null $outcomes */
        $then = function (?array $outcomes) use ($request, $skipped, $allowed): void {
            $this->dispatch($request, $skipped, $allowed, $outcomes);
        };

        $outcomes = $this->transactions->run($work, $then);
        if ($outcomes === null) {
            return new NotifyResult(skippedActor: $skippedActor, skippedAccess: $skippedAccess, deduplicated: true, skippedPreference: $skippedPreference);
        }
        $written = 0;
        $ids     = [];
        foreach ($outcomes as $outcome) {
            $written += $outcome->coalesced ? 0 : 1;
            $ids[] = $outcome->id;
        }

        return new NotifyResult($written, count($outcomes) - $written, $skippedActor, $skippedAccess, false, 0, $ids, $skippedPreference);
    }

    /**
     * Why $recipient gets no row, or null when it gets one.
     */
    private function refusal(RecipientRef $recipient, TypeDefinition $type, NotificationRequest $request): ?SkipReason
    {
        if ($request->actor instanceof RecipientRef && $recipient->equals($request->actor)) {
            return SkipReason::Actor;
        }
        if (!$this->preferences instanceof Preferences && !$type->writesInApp()) {
            return SkipReason::Preference;
        }

        return $this->mayOpen($recipient, $type, $request->context) ? null : SkipReason::Access;
    }

    /**
     * Whether the person's preferences let the in-app row through (S3); a type that locks `in_app` skips the read.
     *
     * @param list<RecipientRef> $candidates
     *
     * @return array<string, bool> by recipient key (missing = allowed)
     */
    private function preferenceGate(TypeDefinition $type, array $candidates, NotificationRequest $request): array
    {
        if ($candidates === [] || !$this->preferences instanceof Preferences) {
            return [];
        }

        return $this->preferences->lets($type, TypeDefinition::IN_APP, $candidates, $request->context, $request->involved);
    }

    /**
     * Deny by default: a personal row is always allowed; a row with a context needs the type's permission there.
     */
    private function mayOpen(RecipientRef $recipient, TypeDefinition $type, ?ContextRef $context): bool
    {
        if (!$context instanceof ContextRef) {
            return true;
        }
        if ($type->permission === null) {
            return false;
        }
        if ($this->access->isUnrestricted($recipient)) {
            return true;
        }
        foreach ($this->access->contextsGranting($recipient, $type->permission, [$context]) as $granted) {
            if ($granted instanceof ContextRef && $granted->equals($context)) {
                return true;
            }
        }

        return false;
    }

    private function draft(NotificationRequest $request, TypeDefinition $type, RecipientRef $recipient, DateTimeImmutable $now): Notification
    {
        return new Notification(
            new NotificationId(Uuid::v7()->toRfc4122()),
            $recipient,
            $request->type,
            $type->category,
            $request->actor,
            $request->context,
            $request->target,
            $request->url,
            $request->params,
            1,
            $now,
            $now,
        );
    }

    /**
     * @param list<array{RecipientRef, SkipReason}> $skipped
     * @param list<RecipientRef> $allowed
     * @param array<int, RecordOutcome>|null $outcomes null = the request was deduplicated
     */
    private function dispatch(NotificationRequest $request, array $skipped, array $allowed, ?array $outcomes): void
    {
        if ($this->dispatcher instanceof EventDispatcherInterface) {
            foreach ($skipped as [$recipient, $reason]) {
                $this->dispatcher->dispatch(new NotificationSkippedEvent($request->type, $recipient, $reason));
            }
            foreach ($outcomes === null ? $allowed : [] as $recipient) {
                $this->dispatcher->dispatch(new NotificationSkippedEvent($request->type, $recipient, SkipReason::Dedupe));
            }
            foreach ($outcomes ?? [] as $index => $outcome) {
                $this->dispatcher->dispatch(new NotificationRecordedEvent($outcome->id, $allowed[$index], $request->type, $request->context, $outcome->coalesced));
            }
        }
        $written = [];
        foreach ($outcomes ?? [] as $index => $outcome) {
            $written[] = $allowed[$index];
        }
        $this->badge?->publishAll($written);
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
