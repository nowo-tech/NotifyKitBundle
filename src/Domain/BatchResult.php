<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

/**
 * One batch of a keyset walk that deletes rows: how many it deleted and where the next batch starts (null = done).
 */
final readonly class BatchResult
{
    /**
     * @param int $deleted rows deleted by this batch
     * @param string|null $cursor the last id fetched when the batch was full; null when the walk is over
     */
    public function __construct(public int $deleted, public ?string $cursor)
    {
    }
}
