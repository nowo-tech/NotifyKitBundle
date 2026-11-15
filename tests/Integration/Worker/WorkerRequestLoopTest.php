<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Worker;

use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\TerminableInterface;

use function gc_collect_cycles;
use function json_decode;
use function memory_get_usage;

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
}
