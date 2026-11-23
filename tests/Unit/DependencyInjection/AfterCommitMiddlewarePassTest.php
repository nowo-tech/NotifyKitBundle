<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\DependencyInjection;

use Nowo\NotifyKitBundle\DependencyInjection\Compiler\AfterCommitMiddlewarePass;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitMiddleware;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

/**
 * Audit QL-I7: the after-commit middleware wraps the bundle connection only (the connection of
 * `doctrine.entity_manager`, or the DBAL connection of that name); unknown → every connection, as before.
 */
final class AfterCommitMiddlewarePassTest extends TestCase
{
    public function testTheEntityManagerConnectionIsTagged(): void
    {
        $container = $this->container('ddd');
        $container->setDefinition('doctrine.orm.ddd_entity_manager', new Definition('stdClass', [new Reference('doctrine.dbal.second_connection')]));
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'second']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testAConnectionOfThatNameIsTagged(): void
    {
        $container = $this->container('reporting');
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection', 'reporting' => 'doctrine.dbal.reporting_connection']);
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'reporting']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));
    }

    public function testUnknownConnectionsKeepTheUntaggedMiddleware(): void
    {
        $container = $this->container('nope');
        $container->setDefinition('doctrine.orm.nope_entity_manager', new Definition('stdClass', ['not a reference']));
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([[]], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'));

        $container = new ContainerBuilder();
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertFalse($container->hasDefinition(AfterCommitMiddleware::class), 'bundle disabled: nothing to do');

        $container = $this->container('default');
        $container->setParameter('nowo_notify_kit.config', 'broken');
        $container->setParameter('doctrine.connections', ['default' => 'doctrine.dbal.default_connection']);
        (new AfterCommitMiddlewarePass())->process($container);
        self::assertSame([['connection' => 'default']], $container->getDefinition(AfterCommitMiddleware::class)->getTag('doctrine.middleware'), 'falls back to "default"');
    }

    private function container(string $manager): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('nowo_notify_kit.config', ['doctrine' => ['entity_manager' => $manager]]);
        $container->register(AfterCommitMiddleware::class, AfterCommitMiddleware::class)->addTag('doctrine.middleware');

        return $container;
    }
}
