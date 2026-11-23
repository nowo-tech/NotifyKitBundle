<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Exception;

use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use RuntimeException;

use function array_map;
use function implode;
use function sprintf;

/**
 * A save was refused because a cell no longer holds what the caller expected (another tab, device or request changed
 * it first). Nothing of the save was written: read the matrix again and retry (the bundle's endpoints answer 409 +
 * `CONFLICT_001` with the current cells).
 */
final class PreferenceConflictException extends RuntimeException
{
    /**
     * @param list<PreferenceKey> $keys the cells whose stored state changed
     */
    public function __construct(public readonly array $keys)
    {
        parent::__construct(sprintf('Preferences changed meanwhile: %s.', implode(', ', array_map(static fn (PreferenceKey $key): string => $key->toString(), $keys))));
    }
}
