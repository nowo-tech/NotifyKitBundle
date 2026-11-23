<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use Nowo\NotifyKitBundle\Contract\ContextRef;

/**
 * A context of the recipient's rows in which the access checker granted a permission, with the types (stored type
 * keys) whose permission that is.
 *
 * @internal
 */
final readonly class VisibleContext
{
    /**
     * @param list<string> $types
     */
    public function __construct(public ContextRef $context, public array $types)
    {
    }
}
