<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Default;

use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Default context provider (`preferences.contexts.provider: null`): no contexts, no labels.
 */
final readonly class NoContextProvider implements ContextProviderInterface
{
    /**
     * No contexts to tune.
     */
    public function contextsFor(RecipientRef $recipient): array
    {
        return [];
    }

    /**
     * No label.
     */
    public function label(ContextRef $context): ?string
    {
        return null;
    }
}
