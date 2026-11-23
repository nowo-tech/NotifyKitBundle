<?php

declare(strict_types=1);

namespace App\Notify;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Who may open targets in a context: Alice holds `project.view` in project "demo", Bob holds nothing. Rows of project
 * "demo" are written for Alice only and stay visible to her only (deny by default).
 */
final readonly class DemoAccessChecker implements RecipientAccessCheckerInterface
{
    public function isUnrestricted(RecipientRef $recipient): bool
    {
        return false;
    }

    public function contextsGranting(RecipientRef $recipient, string $permission, array $candidates): array
    {
        if ($recipient->key() !== 'user:alice' || $permission !== 'project.view') {
            return [];
        }

        return array_values(array_filter($candidates, static fn (ContextRef $context): bool => $context->key() === 'project:demo'));
    }
}
