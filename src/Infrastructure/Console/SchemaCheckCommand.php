<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Console;

use Nowo\NotifyKitBundle\Infrastructure\Schema\SchemaChecker;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

use function implode;
use function sprintf;

/**
 * `nowo:notify-kit:schema-check`: exits 0 when the bundle tables match the mapping (prefix, columns, binary
 * collations, indexes), 1 with the aligning SQL otherwise. Read-only: it never changes the schema.
 */
#[AsCommand(name: 'nowo:notify-kit:schema-check', description: 'Compare the NotifyKit tables with the mapping (columns, collations, indexes, prefix)')]
final class SchemaCheckCommand extends Command
{
    /**
     * Wires the command.
     */
    public function __construct(private readonly SchemaChecker $checker)
    {
        parent::__construct();
    }

    /**
     * Prints the result.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $differences = $this->checker->differences();
        if ($differences === []) {
            $output->writeln(sprintf('The NotifyKit tables match the mapping (%s).', implode(', ', $this->checker->tables())));

            return Command::SUCCESS;
        }
        $output->writeln('The NotifyKit tables differ from the mapping. SQL that would align them (write a migration):');
        foreach ($differences as $sql) {
            $output->writeln($sql . ';');
        }

        return Command::FAILURE;
    }
}
