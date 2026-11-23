<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Web;

use Closure;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectAccessChecker;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\WebRecipientResolver;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;

use function array_replace_recursive;
use function assert;

/**
 * A host with pages (standard host on SQLite or the CI database): logged-in people through the test header
 * authenticator, the bundle's inbox and operator pages, a page with the bell, the project access checker (alice holds
 * `issue.view` in project 42).
 */
abstract class WebTestCase extends KernelTestCase
{
    protected TestKernel $kernel;

    protected KernelBrowser $browser;

    /**
     * @param array<string, mixed> $override
     */
    protected function bootWeb(array $override = [], ?Closure $extra = null): KernelBrowser
    {
        $host   = Host::standard();
        $config = array_replace_recursive($host->bundleConfig([
            'recipient' => ['class' => null, 'resolver' => WebRecipientResolver::class],
            'access'    => ['checker' => ProjectAccessChecker::class, 'default_permission' => 'project.view'],
            'types'     => ['issue.assigned' => ['category' => 'issues', 'permission' => 'issue.view', 'icon' => 'tabler:bug']],
            'web_ui'    => ['enabled' => true],
            'admin_ui'  => ['enabled' => true],
        ]), $override);
        $base      = $host->configure();
        $configure = static function (ContainerConfigurator $container, string $url) use ($base, $extra): void {
            $base($container, $url);
            $container->services()->set(WebRecipientResolver::class);
            $container->services()->set(ProjectAccessChecker::class)->args([['user:alice|issue.view' => ['project:42']]]);
            if ($extra instanceof Closure) {
                $extra($container);
            }
        };
        $this->kernel = $this->bootKernel($config, Host::databaseUrl(), $configure, false, true);
        $host->resetSchema($this->kernel);
        $browser = $this->kernel->getContainer()->get('test.client');
        assert($browser instanceof KernelBrowser);
        $this->browser = $browser;

        return $browser;
    }

    /**
     * @param array<string, string> $headers extra HTTP headers (without the HTTP_ prefix)
     * @param array<string, string> $parameters
     */
    protected function as(?string $user, string $method, string $uri, array $headers = [], array $parameters = [], string $roles = 'ROLE_USER'): Crawler
    {
        $server = [];
        if ($user !== null) {
            $server['HTTP_X_TEST_USER']  = $user;
            $server['HTTP_X_TEST_ROLES'] = $roles;
        }
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }

        return $this->browser->request($method, $uri, $parameters, [], $server);
    }

    /**
     * @param list<string> $recipients user ids
     * @param array<string, string> $params
     *
     * @return list<NotificationId>
     */
    protected function notify(string $type, array $recipients, ?string $actor = null, ?string $project = null, array $params = [], string $url = '/target'): array
    {
        $notifier = $this->kernel->getContainer()->get(NotifierInterface::class);
        assert($notifier instanceof NotifierInterface);
        $to = [];
        foreach ($recipients as $id) {
            $to[] = new RecipientRef('user', $id);
        }

        return $notifier->notify(new NotificationRequest(
            $type,
            $to,
            $actor === null ? null : new RecipientRef('user', $actor),
            $project === null ? null : new ContextRef('project', $project),
            'target:' . $type . ':' . $url,
            $url,
            $params,
        ))->notificationIds;
    }

    protected function inbox(): InboxInterface
    {
        $inbox = $this->kernel->getContainer()->get(InboxInterface::class);
        assert($inbox instanceof InboxInterface);

        return $inbox;
    }

    protected function unread(string $user): int
    {
        return $this->inbox()->unreadCount(new RecipientRef('user', $user));
    }

    /**
     * The CSRF token of the bell `fetch` calls, from the page with the bell (same session as the next requests).
     */
    protected function bellToken(string $user): string
    {
        $crawler = $this->as($user, 'GET', '/bell');

        return (string) $crawler->filter('[data-nowo-notify-kit-bell]')->attr('data-csrf-token');
    }
}
