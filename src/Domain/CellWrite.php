<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

/**
 * Outcome of one conditional cell write of {@see PreferenceStoreInterface::write()}.
 */
enum CellWrite
{
    /** The row was inserted, updated or deleted. */
    case Changed;

    /** The cell already held the wanted state: nothing to write. */
    case Unchanged;

    /** The stored state no longer matched the expectation: nothing written. */
    case Conflict;
}
