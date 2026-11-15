<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\NotifyKitBundle\DependencyInjection\NowoNotifyKitExtension;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

/**
 * The enabled bundle stores its rows through Doctrine DBAL: refuses to build without DoctrineBundle, with a message
 * that says so (instead of a missing `doctrine` service further down).
 */
final class RequireDoctrinePass implements CompilerPassInterface
{
    /**
     * Checks for the `doctrine` registry.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->getParameter(NowoNotifyKitExtension::ALIAS . '.enabled') === true && !$container->has('doctrine')) {
            throw new LogicException('nowo_notify_kit is enabled but DoctrineBundle is not: install and enable doctrine/doctrine-bundle (the notifications are stored through Doctrine DBAL).');
        }
    }
}
