<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Keeps log records (test only).
 */
final class MemoryLogger extends AbstractLogger
{
    /** @var list<array{mixed, string, array<mixed>}> */
    public array $records = [];

    /**
     * @param array<mixed> $context
     */
    public function log($level, string|Stringable $message, array $context = []): void
    {
        $this->records[] = [$level, (string) $message, $context];
    }
}
