<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use DateTimeImmutable;
use DateTimeZone;
use Psr\Clock\ClockInterface;

/**
 * A clock that moves $step seconds forward on every read (retention time budget tests).
 */
final class SteppingClock implements ClockInterface
{
    private DateTimeImmutable $now;

    public function __construct(string $start, private readonly int $step)
    {
        $this->now = new DateTimeImmutable($start, new DateTimeZone('UTC'));
    }

    public function now(): DateTimeImmutable
    {
        $now       = $this->now;
        $this->now = $now->setTimestamp($now->getTimestamp() + $this->step);

        return $now;
    }
}
