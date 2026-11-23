<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Audit;

use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Default audit trail: records nothing (no AuditKit bridge).
 *
 * @internal
 */
final readonly class NullAuditTrail implements AuditTrailInterface
{
    /**
     * Records nothing.
     */
    public function record(string $action, ?RecipientRef $actor, array $context = []): void
    {
    }
}
