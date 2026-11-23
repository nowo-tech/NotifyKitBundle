<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Worker;

use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectAccessChecker;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\WebRecipientResolver;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function explode;
use function gc_collect_cycles;
use function intdiv;
use function json_decode;
use function memory_get_usage;
use function min;
use function str_replace;
use function strtoupper;

use const JSON_THROW_ON_ERROR;

/**
 * FrankenPHP worker mode with the kernel kept alive and NOT reset (`FRANKENPHP_RESET_KERNEL=false`): one booted
 * kernel serves 2,000 alternating requests of two users through `http_kernel`, without the services resetter. Each
 * request notifies one user and reads that user's inbox: no row of A is ever visible to B, no after-commit work is
 * carried into the next request, the unread count only grows with the user's own rows, and memory stays flat.
 */
final class WorkerRequestLoopTest extends KernelTestCase
{
    private const REQUESTS = 2000;

    public function testOneKeptAliveKernelServesManyRequestsWithoutLeakingStateOrMemory(): void
    {
        $host   = Host::standard();
        $kernel = $this->bootKernel($host->bundleConfig(['inbox' => ['repeat_window_seconds' => 0]]), Host::databaseUrl(), $host->configure(), true);
        $host->resetSchema($kernel);
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        $baseline = 0;
        $unread   = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $for      = $i % 2 === 0 ? 'a' : 'b';
            $request  = Request::create('/notify', 'GET', ['for' => $for, 'n' => (string) $i], server: ['REMOTE_ADDR' => '203.0.113.9']);
            $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
            $http->terminate($request, $response);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getContent());
            $body = json_decode((string) $response->getContent(), true, 8, JSON_THROW_ON_ERROR);
            ++$unread[$for];
            self::assertSame(['written' => 1, 'recipients' => ['user:' . $for], 'unread' => $unread[$for], 'held' => 0], $body, 'request ' . $i . ' sees only its own data');

            if ($i === 100) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' requests');
    }

    public function testTheBellInboxAndRowOperationsServeTwoPeopleWithoutLeaks(): void
    {
        $host   = Host::standard();
        $base   = $host->configure();
        $kernel = $this->bootKernel($host->bundleConfig([
            'recipient' => ['class' => null, 'resolver' => WebRecipientResolver::class],
            'access'    => ['checker' => ProjectAccessChecker::class, 'default_permission' => 'project.view'],
            'inbox'     => ['repeat_window_seconds' => 0],
            'web_ui'    => ['enabled' => true],
        ]), Host::databaseUrl(), static function (ContainerConfigurator $container, string $url) use ($base): void {
            $base($container, $url);
            $container->services()->set(WebRecipientResolver::class);
            $container->services()->set(ProjectAccessChecker::class)->args([['user:a|project.view' => ['project:42']]]);
        }, true, true);
        $host->resetSchema($kernel);
        $http = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $http);
        self::assertInstanceOf(TerminableInterface::class, $http);

        /** @var array<string, array{cookie: string, token: string}> $sessions */
        $sessions = [];
        foreach (['a', 'b'] as $user) {
            $response        = $this->send($http, $user, 'GET', '/bell');
            $cookie          = explode(';', (string) $response->headers->get('Set-Cookie'))[0];
            $token           = (string) (new Crawler((string) $response->getContent()))->filter('[data-nowo-notify-kit-bell]')->attr('data-csrf-token');
            $sessions[$user] = ['cookie' => $cookie, 'token' => $token];
        }

        $baseline = 0;
        $unread   = ['a' => 0, 'b' => 0];
        for ($i = 0; $i < self::REQUESTS; ++$i) {
            $user  = $i % 2 === 0 ? 'a' : 'b';
            $other = $user === 'a' ? 'b' : 'a';
            switch (intdiv($i, 2) % 4) {
                case 0:
                    // notify() from a host controller (the NotifyController of the S1 loop): one row for $user.
                    $response = $this->send($http, $user, 'GET', '/notify?for=' . $user . '&n=' . $i);
                    ++$unread[$user];
                    break;
                case 1:
                    $response = $this->send($http, $user, 'GET', '/notifications/count', $sessions[$user]['cookie']);
                    self::assertSame(['unread' => $unread[$user]], json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR), 'request ' . $i);
                    break;
                case 2:
                    $response = $this->send($http, $user, 'GET', '/notifications/menu', $sessions[$user]['cookie']);
                    $menu     = new Crawler((string) $response->getContent());
                    self::assertSame((string) $unread[$user], $menu->filter('[data-nowo-notify-kit-menu]')->attr('data-unread'));
                    self::assertCount(min(15, $unread[$user]), $menu->filter('li[data-unread="1"]'), 'request ' . $i . ' sees only its own rows');
                    self::assertStringNotContainsString($user . ' accepted', (string) $response->getContent(), 'never a row where the viewer is the actor');
                    break;
                default:
                    $response = $this->send($http, $user, 'POST', '/notifications/read-all', $sessions[$user]['cookie'], ['X-CSRF-TOKEN' => $sessions[$user]['token']]);
                    self::assertSame(['ok' => true, 'marked' => $unread[$user], 'unreadCount' => 0], json_decode((string) $response->getContent(), true, 4, JSON_THROW_ON_ERROR), 'request ' . $i);
                    $unread[$user] = 0;
                    // The other person's token never works in this session.
                    try {
                        $this->send($http, $user, 'POST', '/notifications/read-all', $sessions[$user]['cookie'], ['X-CSRF-TOKEN' => $sessions[$other]['token']]);
                        self::fail('request ' . $i . ': another session\'s token must be refused');
                    } catch (AccessDeniedHttpException) {
                        // 403 (the loop does not catch, so the kernel exception surfaces as is).
                    }
            }
            self::assertLessThan(400, $response->getStatusCode(), 'request ' . $i . ': ' . $response->getStatusCode());

            if ($i === 200) {
                gc_collect_cycles();
                $baseline = memory_get_usage();
            }
        }
        gc_collect_cycles();

        self::assertLessThan(1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat over ' . self::REQUESTS . ' requests');
    }

    /**
     * @param array<string, string> $headers
     */
    private function send(HttpKernelInterface&TerminableInterface $http, string $user, string $method, string $uri, ?string $cookie = null, array $headers = []): Response
    {
        $server = ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_TEST_USER' => $user, 'HTTP_X_TEST_ROLES' => 'ROLE_USER'];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }
        if ($cookie !== null) {
            $server['HTTP_COOKIE'] = $cookie;
        }
        $request = Request::create($uri, $method, server: $server);
        if ($cookie !== null) {
            [$name, $value] = explode('=', $cookie, 2);
            $request->cookies->set($name, $value);
        }
        $response = $http->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $http->terminate($request, $response);

        return $response;
    }
}
