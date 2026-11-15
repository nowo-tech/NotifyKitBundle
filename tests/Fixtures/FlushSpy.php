<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

/**
 * Counts `onFlush` of every entity manager: the bundle must never flush one (rule 6).
 */
final class FlushSpy
{
    public int $flushes = 0;

    public function onFlush(): void
    {
        ++$this->flushes;
    }
}
