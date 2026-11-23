<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Limits the after-commit middleware to the bundle connection (audit QL-I7): the DBAL connection of
 * `doctrine.entity_manager` (or the DBAL connection of that name). Runs before DoctrineBundle's middleware pass
 * (priority {@see self::PRIORITY}), which reads the `connection` attribute of the `doctrine.middleware` tag. When the
 * connection cannot be told at compile time the middleware keeps wrapping every connection (harmless: it only watches
 * commits).
 */
final class AfterCommitMiddlewarePass implements CompilerPassInterface
{
    /** Compiler pass priority: above DoctrineBundle's `MiddlewaresPass` (0). */
    public const PRIORITY = 10;

    /**
     * Tags the middleware with the bundle connection's name.
     */
    public function process(ContainerBuilder $container): void
    {
        if (!$container->hasDefinition(AfterCommitMiddleware::class) || !$container->hasParameter('nowo_notify_kit.config')) {
            return;
        }
        $config  = $container->getParameter('nowo_notify_kit.config');
        $manager = is_array($config) && is_array($config['doctrine'] ?? null) && is_string($config['doctrine']['entity_manager'] ?? null) ? $config['doctrine']['entity_manager'] : 'default';
        $name    = self::connectionOf($container, $manager);
        if ($name === null) {
            return;
        }
        $container->getDefinition(AfterCommitMiddleware::class)
            ->clearTag('doctrine.middleware')
            ->addTag('doctrine.middleware', ['connection' => $name]);
    }

    /**
     * The DBAL connection name of the entity manager $manager, or $manager itself when it names a connection.
     */
    public static function connectionOf(ContainerBuilder $container, string $manager): ?string
    {
        $id = 'doctrine.orm.' . $manager . '_entity_manager';
        if ($container->hasDefinition($id)) {
            $connection = $container->getDefinition($id)->getArguments()[0] ?? null;
            if ($connection instanceof Reference && preg_match('/^doctrine\.dbal\.(.+)_connection$/D', (string) $connection, $match) === 1) {
                return $match[1];
            }
        }
        $connections = $container->hasParameter('doctrine.connections') ? $container->getParameter('doctrine.connections') : [];

        return is_array($connections) && array_key_exists($manager, $connections) ? $manager : null;
    }
}
