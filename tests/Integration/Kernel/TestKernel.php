<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Nowo\NotifyKitBundle\Tests\Fixtures\NotifyController;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\HeaderAuthenticator;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\HostController;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: FrameworkBundle, the bundle and, on demand, DoctrineBundle (DBAL only, or what
 * `$configure` sets up: ORM, host fixtures). With `$notifyRoute`, `/notify` runs {@see NotifyController}. With `$web`,
 * a host with pages: SecurityBundle (a header authenticator: `X-Test-User`, `X-Test-Roles`), Twig, UiKit, FormKit,
 * sessions, CSRF and forms, the bundle routes and `/bell` (a page that renders `notify_kit_bell()`).
 */
final class TestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param array<string, mixed> $bundleConfig `nowo_notify_kit` configuration
     * @param string|null $databaseUrl DBAL URL; null = no DoctrineBundle
     * @param (Closure(ContainerConfigurator, string): void)|null $configure extra host configuration
     * @param (Closure(ContainerBuilder): void)|null $build hook to add compiler passes (inspect the compiled container)
     * @param list<BundleInterface> $extraBundles further bundles (e.g. AuditKit for the bridge test)
     */
    public function __construct(
        private readonly string $id,
        private readonly array $bundleConfig = [],
        private readonly ?string $databaseUrl = null,
        private readonly ?Closure $configure = null,
        private readonly bool $notifyRoute = false,
        private readonly ?Closure $build = null,
        private readonly bool $web = false,
        private readonly array $extraBundles = [],
    ) {
        parent::__construct('test', false);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if ($this->databaseUrl !== null) {
            yield new DoctrineBundle();
        }
        if ($this->web) {
            yield new SecurityBundle();
            yield new TwigBundle();
            yield new TwigExtraBundle();
            yield new NowoUiKitBundle();
            yield new NowoFormKitBundle();
        }
        yield from $this->extraBundles;
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
        if ($this->web) {
            $this->configureWeb($container);
        }
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
        if ($this->web) {
            $routes->import(dirname(__DIR__, 3) . '/src/Resources/config/routes.php');
            $routes->add('host_bell', '/bell')->controller(HostController::class . '::bell')->methods(['GET']);
        }
    }

    private function configureWeb(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'csrf_protection' => true,
            'form'            => ['enabled' => true],
            'session'         => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'assets'          => ['enabled' => true],
            'translator'      => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
        ]);
        $container->extension('twig', ['strict_variables' => true]);
        $container->extension('security', [
            'providers' => ['none' => ['memory' => ['users' => []]]],
            'firewalls' => ['main' => ['stateless' => true, 'provider' => 'none', 'custom_authenticators' => [HeaderAuthenticator::class], 'entry_point' => HeaderAuthenticator::class]],
        ]);
        $container->services()->set(HeaderAuthenticator::class);
        $container->services()->set(HostController::class)->args([service('twig')])->public()->tag('controller.service_arguments');
    }
}
