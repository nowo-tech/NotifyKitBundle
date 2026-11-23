<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Visibility;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Domain\Visibility;
use Nowo\NotifyKitBundle\Domain\VisibleContext;

use function array_keys;
use function array_map;
use function array_values;

/**
 * Read-time access (contract §6.1, Beacon's `NotificationVisibility`): collects the distinct contexts of the
 * recipient's own rows (one query), maps each stored type to its permission (unknown types:
 * `access.default_permission`; a known type without a permission has no visible context rows), asks the access
 * checker **once per permission** with every candidate context, and keeps only the contexts it returned that were
 * candidates (exact comparison). Personal rows are always visible; an unrestricted recipient sees everything.
 *
 * Stateless (worker mode): the result lives in the caller's call only, never in this service.
 */
final readonly class VisibilityResolver
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private TypeRegistryInterface $types,
        private RecipientAccessCheckerInterface $access,
    ) {
    }

    /**
     * What $recipient may see now.
     */
    public function for(RecipientRef $recipient): Visibility
    {
        if ($this->access->isUnrestricted($recipient)) {
            return Visibility::unrestricted();
        }

        /** @var array<string, array<string, ContextRef>> $candidates permission => context key => context */
        $candidates = [];
        /** @var array<string, array<string, array<string, true>>> $typesOf permission => context key => type => true */
        $typesOf = [];
        foreach ($this->store->contextTypes($recipient) as [$context, $type]) {
            $permission = $this->types->type($type)->permission;
            if ($permission === null) {
                continue;
            }
            $candidates[$permission][$context->key()]     = $context;
            $typesOf[$permission][$context->key()][$type] = true;
        }

        /** @var array<string, array{ContextRef, array<string, true>}> $granted */
        $granted = [];
        foreach ($candidates as $permission => $contexts) {
            foreach ($this->access->contextsGranting($recipient, (string) $permission, array_values($contexts)) as $context) {
                $key       = $context->key();
                $candidate = $contexts[$key] ?? null;
                if (!$candidate instanceof ContextRef || !$candidate->equals($context)) {
                    continue;
                }
                $granted[$key] ??= [$candidate, []];
                $granted[$key][1] += $typesOf[$permission][$key];
            }
        }

        $visible = [];
        foreach ($granted as [$context, $types]) {
            $visible[] = new VisibleContext($context, array_map('strval', array_keys($types)));
        }

        return Visibility::of($visible);
    }
}
