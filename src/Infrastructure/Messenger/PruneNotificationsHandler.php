<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Messenger;

use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Application\Retention\RetentionReport;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Handles {@see PruneNotificationsMessage} (tag `messenger.message_handler`): one {@see Retention} run within
 * `messenger.max_seconds`, then the remainder is dispatched as a new message (keyset cursor). Without a bus the
 * remainder runs at once, in further bounded runs.
 *
 * Stateless (worker mode).
 */
final readonly class PruneNotificationsHandler
{
    /**
     * Holds the retention service and the bus that takes the remainder.
     */
    public function __construct(
        private Retention $retention,
        private ?MessageBusInterface $bus = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Runs one bounded retention pass.
     */
    public function __invoke(PruneNotificationsMessage $message): RetentionReport
    {
        $report = $this->retention->run($message);
        while ($report->remainder instanceof PruneNotificationsMessage && !$this->bus instanceof MessageBusInterface) {
            $report = $report->plus($this->retention->run($report->remainder));
        }
        if ($report->remainder instanceof PruneNotificationsMessage && $this->bus instanceof MessageBusInterface) {
            $this->bus->dispatch($report->remainder);
        }
        ($this->logger ?? new NullLogger())->info('nowo_notify_kit: retention deleted {read} read row(s), {trimmed} beyond the per-recipient cap and {dedupe} dedupe key(s).', [
            'read'    => $report->read,
            'trimmed' => $report->trimmed,
            'dedupe'  => $report->dedupe,
            'more'    => $report->remainder instanceof PruneNotificationsMessage,
        ]);

        return $report;
    }
}
