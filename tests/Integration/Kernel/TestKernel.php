<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Nowo\NotifyKitBundle\Tests\Fixtures\NotifyController;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function dirname;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, the bundle and, on demand, DoctrineBundle (DBAL only, or what
 * `$configure` sets up: ORM, host fixtures). With `$notifyRoute`, `/notify` runs {@see NotifyController}.
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_notify_kit` configuration
     * @param string|null $databaseUrl DBAL URL; null = no DoctrineBundle
     * @param (Closure(ContainerConfigurator, string): void)|null $configure extra host configuration
     * @param (Closure(ContainerBuilder): void)|null $build hook to add compiler passes (inspect the compiled container)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
        private readonly ?Closure $configure = null,
        private readonly bool $notifyRoute = false,
        private readonly ?Closure $build = null,
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        yield new NowoNotifyKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-notify-kit-tests';
    }

    protected function build(ContainerBuilder $container): void
    {
        if ($this->build instanceof Closure) {
            ($this->build)($container);
        }
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'S1-' . $this->id,
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'router'                => ['utf8' => true],
        ]);
        if ($this->databaseUrl !== null && !$this->configure instanceof Closure) {
            $container->extension('doctrine', ['dbal' => ['url' => $this->databaseUrl]]);
        }
        if ($this->configure instanceof Closure) {
            ($this->configure)($container, (string) $this->databaseUrl);
        }
        $container->extension('nowo_notify_kit', $this->bundleConfig);
        $container->services()->set('logger', NullLogger::class);
        if ($this->notifyRoute) {
            $container->services()
                ->set(NotifyController::class)
                ->autowire()
                ->public()
                ->tag('controller.service_arguments');
        }
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        if ($this->notifyRoute) {
            $routes->add('notify', '/notify')->controller(NotifyController::class)->methods(['GET']);
        }
    }
}
