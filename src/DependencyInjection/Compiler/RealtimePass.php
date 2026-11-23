<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureRealtimePublisher;
use Nowo\NotifyKitBundle\Infrastructure\Realtime\MercureSubscription;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Mercure\HubInterface;

/**
 * Settles the realtime mode (contract §6.5) once the host's services are known: the bundle's Mercure publisher stays
 * only when a Mercure hub service exists (MercureBundle). Without one (or without symfony/mercure), `auto` falls back
 * to polling and `mercure` refuses to build.
 */
final class RealtimePass implements CompilerPassInterface
{
    /**
     * Keeps or removes the Mercure publisher.
     */
    public function process(ContainerBuilder $container): void
    {
        $publisher = $container->hasDefinition(MercureRealtimePublisher::class);
        if ($publisher && $container->has(HubInterface::class)) {
            return;
        }
        if ($container->hasParameter(NowoNotifyKitExtension::REALTIME_REQUESTED) && $container->getParameter(NowoNotifyKitExtension::REALTIME_REQUESTED) === 'mercure') {
            throw new LogicException('nowo_notify_kit.realtime.mode is "mercure" but there is no Mercure hub service: install and configure symfony/mercure-bundle (symfony/mercure), or use "auto", "poll" or "off".');
        }
        if (!$publisher) {
            return;
        }
        $container->removeDefinition(MercureRealtimePublisher::class);
        $container->removeDefinition(MercureSubscription::class);
        $container->removeAlias(RealtimePublisherInterface::class);
        $container->setParameter(NowoNotifyKitExtension::REALTIME_MODE, 'poll');
    }
}
