<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\NotifyKitBundle\Application\Audit\AuditTrailInterface;
use Nowo\NotifyKitBundle\Application\Audit\PreferencesAuditListener;
use Nowo\NotifyKitBundle\Bridge\AuditKit\AuditKitAuditTrail;
use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function is_array;

/**
 * The AuditKit bridge (`bridges.audit_kit`, contract §6.7): when AuditKit's action log is on (its id recorder service
 * exists), preference changes (`notify.preferences_changed`) and *Run retention now* (`notify.retention_run`) are
 * recorded there. `auto` (default) wires it when the service exists; `true` refuses to build without it; `false`
 * never wires it.
 */
final class AuditKitBridgePass implements CompilerPassInterface
{
    /** AuditKit's id recorder port (`nowo-tech/audit-kit-bundle` ≥ 1.14 with `action_log` enabled). */
    public const RECORDER_PORT = 'Nowo\AuditKitBundle\ActionLog\ActionLogIdRecorderInterface';

    /** AuditKit's recorder service (registered when `nowo_audit_kit.action_log.entity_class` is set). */
    public const RECORDER = 'Nowo\AuditKitBundle\ActionLog\ActionLogRecorder';

    /**
     * Wires the bridge, or leaves the null audit trail.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasAlias(AuditTrailInterface::class) || !$container->hasParameter('nowo_notify_kit.config')) {
            return;
        }
        $config = $container->getParameter('nowo_notify_kit.config');
        $mode   = is_array($config) && is_array($config['bridges'] ?? null) ? ($config['bridges']['audit_kit'] ?? 'auto') : 'auto';
        if ($mode === false) {
            return;
        }
        $recorder = $container->has(self::RECORDER_PORT) ? self::RECORDER_PORT : ($container->has(self::RECORDER) ? self::RECORDER : null);
        if ($recorder === null) {
            if ($mode === true) {
                throw new LogicException('nowo_notify_kit.bridges.audit_kit is true but AuditKit\'s action log is not available: install nowo-tech/audit-kit-bundle and set nowo_audit_kit.action_log.entity_class, or use "auto" / false.');
            }

            return;
        }
        $container->register(AuditKitAuditTrail::class, AuditKitAuditTrail::class)->setArguments([new Reference($recorder)]);
        $container->setAlias(AuditTrailInterface::class, AuditKitAuditTrail::class);
        $container->register(PreferencesAuditListener::class, PreferencesAuditListener::class)
            ->setArguments([new Reference(AuditTrailInterface::class)])
            ->addTag('kernel.event_listener', ['event' => PreferencesChangedEvent::class, 'method' => '__invoke']);
    }
}
