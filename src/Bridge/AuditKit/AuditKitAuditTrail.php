<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Bridge\AuditKit;

use Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface;
use Nowo\NotifyKitBundle\Application\Audit\AuditTrailInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * AuditKit bridge (`bridges.audit_kit`, contract §6.7): records into AuditKit's action log by id through
 * `recordIdsAndFlush()`. It is wired **only** when AuditKit flushes in `isolated` mode (checked by `AuditKitBridgePass`,
 * audit ZAL-1): AuditKit then writes only the new row through its own short-lived entity manager, and the host's unit of
 * work is never flushed, never cleared. The person is both actor and subject of a preference change; the context holds keys and
 * counts only. Runs after the bundle's own commit, so a failure here never undoes the change (it is logged by the
 * after-commit queue).
 *
 * @internal
 */
final readonly class AuditKitAuditTrail implements AuditTrailInterface
{
    /**
     * Holds AuditKit's id recorder.
     */
    public function __construct(private ActionLogIdRecorderInterface $recorder)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function record(string $action, ?RecipientRef $actor, array $context = []): void
    {
        $id = $actor?->id;
        $this->recorder->recordIdsAndFlush($action, $id, $action === self::PREFERENCES_CHANGED ? $id : null, $context);
    }
}
