<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Web;

use LogicException;
use Nowo\NotifyKitBundle\Application\Realtime\RealtimeTopics;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\TestHub;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\EventSubscriber\SetCookieSubscriber;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\HubRegistry;

use function json_decode;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

use const JSON_THROW_ON_ERROR;

/**
 * The realtime badge (contract §6.5): with a Mercure hub (`auto` or `mercure`) each committed change publishes one
 * private update `{"event":"notify.updated","unread":n}` on the recipient's hashed topic, the bell subscribes to that
 * topic with a subscriber cookie for it only; `off` and `poll` publish nothing; `mercure` without a hub refuses to build.
 */
final class RealtimeWebTest extends WebTestCase
{
    public function testMercurePublishesPrivateUpdatesOnHashedTopicsAndTheBellSubscribes(): void
    {
        $this->bootWeb(['realtime' => ['mode' => 'mercure']], static function (ContainerConfigurator $container): void {
            $container->services()->set(TestHub::class)->public();
            $container->services()->alias(HubInterface::class, TestHub::class);
            $container->services()->set(HubRegistry::class)->args([service(TestHub::class)]);
            $container->services()->set(Authorization::class)->args([service(HubRegistry::class)]);
            $container->services()->set(SetCookieSubscriber::class)->tag('kernel.event_subscriber');
        });
        $this->notify('billing.ready', ['alice', 'bob']);
        $hub = $this->kernel->getContainer()->get(TestHub::class);
        self::assertInstanceOf(TestHub::class, $hub);
        self::assertCount(2, $hub->updates);
        $topics = $this->kernel->getContainer()->get(RealtimeTopics::class);
        self::assertInstanceOf(RealtimeTopics::class, $topics);
        $alice = $topics->topicFor(new RecipientRef('user', 'alice'));
        self::assertMatchesRegularExpression('#^notify-kit/r/[0-9a-f]{32}$#D', $alice);
        self::assertStringNotContainsString('alice', $alice);
        self::assertSame($alice, $topics->subscribeClaimFor(new RecipientRef('user', 'alice')));
        self::assertNotSame($alice, $topics->topicFor(new RecipientRef('user', 'bob')));
        self::assertSame([$alice], $hub->updates[0]->getTopics());
        self::assertTrue($hub->updates[0]->isPrivate());
        self::assertSame(['event' => 'notify.updated', 'unread' => 1], json_decode($hub->updates[0]->getData(), true, 4, JSON_THROW_ON_ERROR));

        $crawler = $this->as('alice', 'GET', '/bell');
        $bell    = $crawler->filter('[data-nowo-notify-kit-bell]');
        self::assertSame('mercure', $bell->attr('data-realtime'));
        self::assertSame('http://localhost/.well-known/mercure', $bell->attr('data-hub-url'));
        self::assertSame($alice, $bell->attr('data-topic'));
        $cookie = $this->browser->getCookieJar()->get('mercureAuthorization', '/.well-known/mercure', 'localhost');
        self::assertNotNull($cookie, 'a subscriber cookie for the hub');
        self::assertSame('jwt-for-' . $alice, $cookie->getValue(), 'only the person\'s own topic');
    }

    public function testAHubTheBrowserCannotGetACookieForFallsBackToPolling(): void
    {
        $this->bootWeb(['realtime' => ['mode' => 'auto']], static function (ContainerConfigurator $container): void {
            $container->services()->set(TestHub::class)->args(['https://hub.other-site.example/.well-known/mercure']);
            $container->services()->alias(HubInterface::class, TestHub::class);
            $container->services()->set(HubRegistry::class)->args([service(TestHub::class)]);
            $container->services()->set(Authorization::class)->args([service(HubRegistry::class)]);
        });
        self::assertSame('mercure', $this->kernel->getContainer()->getParameter('nowo_notify_kit.realtime_mode'));
        self::assertSame('poll', $this->as('alice', 'GET', '/bell')->filter('[data-nowo-notify-kit-bell]')->attr('data-realtime'));
    }

    public function testOffAndPollPublishNothing(): void
    {
        $this->bootWeb(['realtime' => ['mode' => 'off']]);
        $this->notify('billing.ready', ['alice']);
        self::assertSame('off', $this->as('alice', 'GET', '/bell')->filter('[data-nowo-notify-kit-bell]')->attr('data-realtime'));
        self::assertSame('off', $this->kernel->getContainer()->getParameter('nowo_notify_kit.realtime_mode'));
    }

    public function testMercureWithoutAHubRefusesToBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Mercure hub service');
        $host = Host::standard();
        $this->bootKernel($host->bundleConfig(['realtime' => ['mode' => 'mercure']]), Host::databaseUrl(), $host->configure());
    }
}
