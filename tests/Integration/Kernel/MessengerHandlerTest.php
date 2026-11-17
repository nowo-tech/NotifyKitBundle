<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Nowo\NotifyKitBundle\Infrastructure\Messenger\PruneNotificationsHandler;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_keys;
use function array_values;
use function str_starts_with;

/**
 * Compiled-kernel check of the `#[AsMessageHandler]` registration (REQ-MSG-001): a real kernel with FrameworkBundle
 * autoconfiguration tags the prune handler with `messenger.message_handler` exactly once, and disabled registers none.
 */
final class MessengerHandlerTest extends KernelTestCase
{
    public function testThePruneHandlerIsTaggedExactlyOnceByAutoconfiguration(): void
    {
        $found = $this->handlersAfterCompilation(['enabled' => true]);

        self::assertSame(1, $found['count'], 'the prune handler is registered exactly once in messenger.message_handler');
        self::assertSame(1, $found['total'], 'no other handler is registered by the bundle');
        self::assertCount(1, $found['tags'], 'one tag entry on the handler definition');
    }

    public function testNoHandlerWhileDisabled(): void
    {
        $found = $this->handlersAfterCompilation([]);

        self::assertSame(0, $found['count']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array{count: int, total: int, tags: list<array<string, mixed>>}
     */
    private function handlersAfterCompilation(array $config): array
    {
        $result = ['count' => 0, 'total' => 0, 'tags' => []];
        $build  = static function (ContainerBuilder $container) use (&$result): void {
            $container->addCompilerPass(new class($result) implements CompilerPassInterface {
                /** @param array{count: int, total: int, tags: list<array<string, mixed>>} $result */
                public function __construct(private array &$result)
                {
                }

                public function process(ContainerBuilder $container): void
                {
                    $ids = array_keys($container->findTaggedServiceIds('messenger.message_handler'));
                    foreach ($ids as $id) {
                        if (str_starts_with($id, 'Nowo\\NotifyKitBundle\\')) {
                            ++$this->result['total'];
                        }
                        if ($id === PruneNotificationsHandler::class) {
                            ++$this->result['count'];
                            $this->result['tags'] = array_values($container->getDefinition($id)->getTag('messenger.message_handler'));
                        }
                    }
                }
            }, PassConfig::TYPE_BEFORE_REMOVING);
        };

        $kernel = new TestKernel($this->kernelId(0), $config, $config === [] ? null : Host::databaseUrl(), $config === [] ? null : Host::standard()->configure(), false, $build);
        $kernel->boot();
        $kernel->shutdown();

        return $result;
    }
}
