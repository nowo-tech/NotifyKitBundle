<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle;

use Nowo\NotifyKitBundle\DependencyInjection\Compiler\RequireDoctrinePass;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Notifications for Symfony: one notify() call, an in-app inbox with a real-time badge, per-person preferences by category and channel, quiet hours and digests, mail / push / Slack channels and GDPR export and erasure.
 *
 * S1 core: `NotifierInterface::notify()` writes the in-app rows (coalescing, deny by default, after-commit events),
 * the type registry, retention and the schema check (see docs/ROADMAP.md for the later slices). Nothing is registered
 * while `nowo_notify_kit.enabled` is false.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoNotifyKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoNotifyKitBundle';

    /**
     * Adds the compiler pass that refuses an enabled bundle without DoctrineBundle.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new RequireDoctrinePass());
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
