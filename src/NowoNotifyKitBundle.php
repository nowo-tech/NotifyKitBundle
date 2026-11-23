<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle;

use Nowo\NotifyKitBundle\Contract\TranslationDomain;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\AuditKitBridgePass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\RealtimePass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\RequireDoctrinePass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\NotifyKitBundle\DependencyInjection\Compiler\WebUiPass;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Notifications for Symfony: one notify() call, an in-app inbox with a real-time badge, per-person preferences by category and channel, quiet hours and digests, mail / push / Slack channels and GDPR export and erasure.
 *
 * S1 core: `NotifierInterface::notify()` writes the in-app rows (coalescing, deny by default, after-commit events),
 * the type registry, retention and the schema check. S2: read-time visibility, the inbox read and row operations,
 * the header bell, the inbox and operator pages and the realtime badge. S3: per-person preferences (category × channel
 * matrix, master and context switches, scope all / involved), the preferences page and the AuditKit bridge (see
 * docs/ROADMAP.md for the later slices).
 * Nothing but the (empty) route loader is registered while `nowo_notify_kit.enabled` is false.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoNotifyKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = TranslationDomain::NAME;

    /**
     * Adds the compiler passes: DoctrineBundle required, the after-commit middleware on the bundle connection only,
     * realtime mode, host pieces of the pages, Twig namespaces, the AuditKit bridge.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RequireDoctrinePass());
        $container->addCompilerPass(new AfterCommitMiddlewarePass(), PassConfig::TYPE_BEFORE_OPTIMIZATION, AfterCommitMiddlewarePass::PRIORITY);
        $container->addCompilerPass(new AuditKitBridgePass());
        $container->addCompilerPass(new RealtimePass());
        $container->addCompilerPass(new WebUiPass());
        $container->addCompilerPass(new TwigPathsPass());
    }

    /**
     * The extension alias is `nowo_notify_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ExtensionInterface
    {
        if (!$this->extension instanceof ExtensionInterface) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoNotifyKitExtension();
        }

        return $this->extension;
    }
}
