<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Default;

use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Default access checker (`access.checker: null`, Q-O6): grants nothing, so only personal rows (no context) are
 * written. Deny by default: the bundle never guesses who may open a target in a project or board.
 */
final readonly class PersonalOnlyAccessChecker implements RecipientAccessCheckerInterface
{
    /**
     * Nobody sees every context.
     */
    public function isUnrestricted(RecipientRef $recipient): bool
    {
        return false;
    }

    /**
     * No context grants anything.
     */
    public function contextsGranting(RecipientRef $recipient, string $permission, array $candidates): array
    {
        return [];
    }
}
