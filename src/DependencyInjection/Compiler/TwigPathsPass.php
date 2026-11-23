<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function dirname;
use function is_dir;
use function is_string;
use function rtrim;

/**
 * Registers the `NowoNotifyKitBundle` and `!NowoNotifyKitBundle` Twig namespaces (REQ-TWIG-001/002) with the host
 * override directory `templates/bundles/NowoNotifyKitBundle` first, so application overrides win and an override can
 * extend the bundle's own template through `@!NowoNotifyKitBundle/...`.
 */
final class TwigPathsPass implements CompilerPassInterface
{
    private const TWIG_NAMESPACE = 'NowoNotifyKitBundle';

    /**
     * Adds the paths to the native Twig loader.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition('twig.loader.native_filesystem')) {
            return;
        }
        $views      = dirname(__DIR__, 2) . '/Resources/views';
        $definition = $container->getDefinition('twig.loader.native_filesystem');
        $projectDir = $container->hasParameter('kernel.project_dir') ? $container->getParameter('kernel.project_dir') : null;
        if (is_string($projectDir) && is_dir(rtrim($projectDir, '/\\') . '/templates/bundles/' . self::TWIG_NAMESPACE)) {
            // @igor-ignore - Compile-time DI definition wiring; runs once at container build
            $definition->addMethodCall('prependPath', [rtrim($projectDir, '/\\') . '/templates/bundles/' . self::TWIG_NAMESPACE, self::TWIG_NAMESPACE]);
        }
        // @igor-ignore - Compile-time DI definition wiring; runs once at container build
        $definition->addMethodCall('addPath', [$views, self::TWIG_NAMESPACE]);
        // @igor-ignore - Compile-time DI definition wiring; runs once at container build
        $definition->addMethodCall('addPath', [$views, '!' . self::TWIG_NAMESPACE]);
    }
}
