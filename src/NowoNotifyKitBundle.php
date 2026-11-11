<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle;

use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Notifications for Symfony: one notify() call, an in-app inbox with a real-time badge, per-person preferences by category and channel, quiet hours and digests, mail / push / Slack channels and GDPR export and erasure.
 *
 * S0 scaffold: the bundle class and its strict configuration tree (`nowo_notify_kit`). No service, route, listener or table
 * is registered yet; the slices S1 and later add them (see docs/ROADMAP.md).
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoNotifyKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoNotifyKitBundle';

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
