<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Console;

use LogicException;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

use function sprintf;

/**
 * `nowo:notify-kit:prune`: runs retention now, in bounded batches until done, or with `--async` only enqueues a
 * {@see PruneNotificationsMessage} (a scheduler only enqueues, REQ-MESSENGER-008).
 */
#[AsCommand(name: 'nowo:notify-kit:prune', description: 'Delete old read notifications, trim each recipient to the configured cap and forget expired dedupe keys')]
final class PruneNotificationsCommand extends Command
{
    /**
     * Wires the command.
     */
    public function __construct(
        private readonly Retention $retention,
        private readonly ?MessageBusInterface $bus = null,
    ) {
        parent::__construct();
    }

    /**
     * Declares `--async`.
     */
    protected function configure(): void
    {
        $this->addOption('async', null, InputOption::VALUE_NONE, 'Dispatch a PruneNotificationsMessage instead of running now');
    }

    /**
     * Runs or enqueues retention and prints what it did.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('async') === true) {
            if (!$this->bus instanceof MessageBusInterface) {
                throw new LogicException('nowo:notify-kit:prune --async needs symfony/messenger (a message bus).');
            }
            $this->bus->dispatch(new PruneNotificationsMessage());
            $output->writeln('Retention enqueued.');

            return Command::SUCCESS;
        }
        $report = $this->retention->run();
        while ($report->remainder instanceof PruneNotificationsMessage) {
            $report = $report->plus($this->retention->run($report->remainder));
        }
        $output->writeln(sprintf('Deleted %d read notification(s), %d beyond the per-recipient cap and %d expired dedupe key(s).', $report->read, $report->trimmed, $report->dedupe));

        return Command::SUCCESS;
    }
}
