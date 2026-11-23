<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Audit;

use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Application port of the audit trail (contract §6.7): preference changes and operator actions. Reading and marking
 * notifications are never audited (high volume, no security value). Default: nothing is recorded; the AuditKit bridge
 * (`bridges.audit_kit`) records into AuditKit's action log.
 *
 * @internal
 */
interface AuditTrailInterface
{
    /** A person changed their preferences. */
    public const PREFERENCES_CHANGED = 'notify.preferences_changed';

    /** An operator ran retention from the operator page. */
    public const RETENTION_RUN = 'notify.retention_run';

    /**
     * Records $action. $context holds keys and counts only: never notification content, never another person.
     *
     * @param RecipientRef|null $actor who acted (null = unknown / system)
     * @param array<string, bool|int|list<string>|string> $context
     */
    public function record(string $action, ?RecipientRef $actor, array $context = []): void;
}
