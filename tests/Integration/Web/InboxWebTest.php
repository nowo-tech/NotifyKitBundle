<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Web;

use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\RecordingPublisher;

use function json_decode;

use const JSON_THROW_ON_ERROR;

/**
 * The inbox endpoints on a standard host (contract §6.3, §7.1, §14 "UI"): login and role answers, own rows only (404
 * for anyone else's), CSRF on every POST (403 without it), JSON for `fetch` and redirects for plain forms (local
 * `_redirect` only), `no-store` answers, filters and keyset pages, the bell.
 */
final class InboxWebTest extends WebTestCase
{
    public function testAnonymousPeopleGetTheLoginAnswerAndPeopleWithoutTheRoleOrAnInboxGet403(): void
    {
        $this->bootWeb();
        foreach (['/notifications', '/notifications/menu', '/notifications/count'] as $uri) {
            $this->as(null, 'GET', $uri);
            self::assertSame(401, $this->browser->getResponse()->getStatusCode(), $uri);
        }
        $this->as(null, 'POST', '/notifications/read-all');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->as('alice', 'GET', '/notifications', roles: 'ROLE_GUEST');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'without web_ui.security.access_roles');
        $this->as('nobody', 'GET', '/notifications/count');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'a user the resolver refuses has no inbox');
        $this->as(null, 'GET', '/bell');
        self::assertSame('<header></header>', $this->browser->getResponse()->getContent(), 'no bell for guests');
        $this->as('alice', 'GET', '/bell', roles: 'ROLE_GUEST');
        self::assertSame('<header></header>', $this->browser->getResponse()->getContent(), 'no bell without access');
        $this->as('nobody', 'GET', '/bell');
        self::assertSame('<header></header>', $this->browser->getResponse()->getContent());
    }

    public function testTheBellCarriesTheCountTheEndpointsAndThePollSetUp(): void
    {
        $this->bootWeb();
        $this->notify('invitation_accepted', ['alice', 'bob'], 'carol', null, ['name' => 'Team <b>A</b>']);
        $crawler = $this->as('alice', 'GET', '/bell');
        $bell    = $crawler->filter('[data-nowo-notify-kit-bell]');
        self::assertSame('/notifications/menu', $bell->attr('data-menu-url'));
        self::assertSame('/notifications/count', $bell->attr('data-count-url'));
        self::assertSame('/notifications/00000000-0000-7000-8000-000000000000/read', $bell->attr('data-read-url'));
        self::assertSame('poll', $bell->attr('data-realtime'), 'no Mercure hub: auto falls back to polling');
        self::assertSame('60', $bell->attr('data-poll-seconds'));
        self::assertNotSame('', (string) $bell->attr('data-csrf-token'));
        self::assertSame('1', $crawler->filter('[data-nowo-notify-kit-badge]')->text());
        self::assertSame('Notifications (1 unread)', $crawler->filter('[data-nowo-notify-kit-name]')->text());
        self::assertNull($crawler->filter('summary')->attr('aria-label'), 'the name comes from the content (WCAG 2.5.3 label in name)');
        self::assertSame('1 unread', $crawler->filter('[data-nowo-notify-kit-live]')->text());
        self::assertStringContainsString('/bundles/nowonotifykit/js/nowo-notify-kit-bell.js', (string) $this->browser->getResponse()->getContent());
    }

    public function testTheMenuAndCountShowOnlyTheOwnVisibleRows(): void
    {
        $this->bootWeb();
        $this->notify('invitation_accepted', ['alice'], 'carol', null, ['name' => '<script>x</script>']);
        $this->notify('issue.assigned', ['alice', 'bob'], 'carol', '42', [], '/p/42/issues/1');
        $this->notify('billing.ready', ['bob'], null, null, [], '/billing');

        $crawler  = $this->as('alice', 'GET', '/notifications/menu');
        $response = $this->browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        self::assertCount(2, $crawler->filter('[data-id]'), 'bob\'s project row was refused at write time, billing is bob\'s');
        $titles = $crawler->filter('.nowo-notify-kit-item__title')->each(static fn ($node): string => $node->text());
        self::assertSame(['carol assigned you an issue', 'carol accepted your invitation to <script>x</script>'], $titles);
        self::assertStringNotContainsString('<script>x</script>', (string) $response->getContent(), 'params are escaped');
        self::assertSame('tabler:bug', $crawler->filter('[data-id]')->first()->attr('data-icon'));
        self::assertSame('2', $crawler->filter('[data-nowo-notify-kit-menu]')->attr('data-unread'));

        $this->as('alice', 'GET', '/notifications/count');
        $count = $this->browser->getResponse();
        self::assertSame(['unread' => 2], json_decode((string) $count->getContent(), true, 4, JSON_THROW_ON_ERROR));
        self::assertSame('"u2"', $count->getEtag());
        $this->as('alice', 'GET', '/notifications/count', ['If-None-Match' => '"u2"']);
        self::assertSame(304, $this->browser->getResponse()->getStatusCode());
        $this->as('bob', 'GET', '/notifications/count');
        self::assertSame(['unread' => 1], json_decode((string) $this->browser->getResponse()->getContent(), true, 4, JSON_THROW_ON_ERROR));

        $this->as('dave', 'GET', '/notifications/menu');
        self::assertStringContainsString('You are all caught up.', (string) $this->browser->getResponse()->getContent());
    }

    public function testFetchRowOperationsNeedTheCsrfHeaderAndOnlyTouchOwnRows(): void
    {
        $this->bootWeb(['realtime' => ['publisher' => RecordingPublisher::class]]);
        [$aliceRow, $bobRow] = $this->notify('billing.ready', ['alice', 'bob']);
        $this->notify('invitation_accepted', ['alice'], 'bob', null, ['name' => 'Ops']);
        $token = $this->bellToken('alice');
        $bell  = $this->browser->getCrawler()->filter('[data-nowo-notify-kit-bell]');
        self::assertSame('host', $bell->attr('data-realtime'), 'a host publisher');

        $this->as('alice', 'POST', '/notifications/' . $aliceRow->value . '/read', ['X-CSRF-TOKEN' => 'wrong']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
        self::assertSame(2, $this->unread('alice'));

        $this->as('alice', 'POST', '/notifications/' . $aliceRow->value . '/read', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(['ok' => true, 'id' => $aliceRow->value, 'unread' => false, 'unreadCount' => 1], $this->json());
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        $this->as('alice', 'POST', '/notifications/' . $aliceRow->value . '/unread', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(['ok' => true, 'id' => $aliceRow->value, 'unread' => true, 'unreadCount' => 2], $this->json());

        $this->as('alice', 'POST', '/notifications/' . $bobRow->value . '/read', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'bob\'s row does not exist for alice');
        self::assertSame(['ok' => false, 'error' => 'not_found'], $this->json());
        self::assertSame(1, $this->unread('bob'));
        $this->as('alice', 'POST', '/notifications/019a0000-0000-7000-8000-000000000001/read', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        $this->as('alice', 'POST', '/notifications/not-an-id/read', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'the route requirement refuses it');
        $this->as('alice', 'GET', '/notifications/' . $aliceRow->value . '/read');
        self::assertSame(405, $this->browser->getResponse()->getStatusCode(), 'POST only');

        $this->as('alice', 'POST', '/notifications/read-all', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(['ok' => true, 'marked' => 2, 'unreadCount' => 0], $this->json());
        self::assertSame(1, $this->unread('bob'));
        $publisher = $this->kernel->getContainer()->get(RecordingPublisher::class);
        self::assertInstanceOf(RecordingPublisher::class, $publisher);
        self::assertContains(['user:alice', 0], $publisher->published);
        $this->browser->restart();
        $this->as('bob', 'POST', '/notifications/read-all', ['X-CSRF-TOKEN' => $token]);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'a token of another session is refused');
        self::assertSame(1, $this->unread('bob'));
    }

    public function testPlainFormsUseTheFormKitTokenAndRedirectToALocalPathOnly(): void
    {
        $this->bootWeb();
        [$row]   = $this->notify('billing.ready', ['alice']);
        $crawler = $this->as('alice', 'GET', '/notifications');
        $token   = (string) $crawler->filter('form[action$="/read"] input[name="_token"]')->attr('value');
        self::assertNotSame('', $token);
        self::assertSame('/notifications', $crawler->filter('form[action$="/read"] input[name="_redirect"]')->attr('value'));

        $this->as('alice', 'POST', '/notifications/' . $row->value . '/read', [], ['_redirect' => '/notifications?status=read']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'no token');
        $this->as('alice', 'POST', '/notifications/' . $row->value . '/read', [], ['_token' => $token, '_redirect' => '/notifications?status=read']);
        self::assertTrue($this->browser->getResponse()->isRedirect('/notifications?status=read'));
        self::assertSame(0, $this->unread('alice'));
        foreach (['//evil.example', 'https://evil.example/', '/\\evil.example', '/%2F%2Fevil.example', ''] as $target) {
            $this->as('alice', 'POST', '/notifications/' . $row->value . '/unread', [], ['_token' => $token, '_redirect' => $target]);
            self::assertTrue($this->browser->getResponse()->isRedirect('/notifications'), 'refused: ' . $target);
        }
        $this->as('alice', 'POST', '/notifications/read-all', [], ['_token' => $token, '_redirect' => '/elsewhere']);
        self::assertTrue($this->browser->getResponse()->isRedirect('/elsewhere'));
        $this->as('alice', 'POST', '/notifications/019a0000-0000-7000-8000-000000000001/read', [], ['_token' => $token, '_redirect' => '/notifications']);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
    }

    public function testTheInboxPageFiltersAndPagesByKeyset(): void
    {
        $this->bootWeb(['inbox' => ['page_size' => 2]]);
        $this->notify('billing.ready', ['alice'], null, null, [], '/billing/1');
        $this->notify('invitation_accepted', ['alice'], 'bob', null, ['name' => 'Ops'], '/invite');
        $this->notify('issue.assigned', ['alice'], 'bob', '42', [], '/p/42/issues/1');

        $crawler = $this->as('alice', 'GET', '/notifications');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), substr((string) $this->browser->getResponse()->getContent(), 0, 3000));
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertCount(2, $crawler->filter('tbody tr'));
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-read-all"]'));
        $next = (string) $crawler->filter('[data-testid="notify-kit-next"]')->attr('href');
        self::assertStringStartsWith('/notifications?after=', $next);
        $crawler = $this->as('alice', 'GET', $next);
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertSame('/notifications', $crawler->filter('[data-testid="notify-kit-first"]')->attr('href'));

        $crawler = $this->as('alice', 'GET', '/notifications?type=issue.assigned');
        self::assertCount(1, $crawler->filter('tbody tr'));
        self::assertStringContainsString('bob assigned you an issue', $crawler->filter('tbody')->text());
        $crawler = $this->as('alice', 'GET', '/notifications?category=account&status=unread');
        self::assertCount(2, $crawler->filter('tbody tr'));
        $crawler = $this->as('alice', 'GET', '/notifications?status=read');
        self::assertCount(0, $crawler->filter('tbody tr'));
        self::assertStringContainsString('No notification matches these filters.', (string) $this->browser->getResponse()->getContent());
        $crawler = $this->as('alice', 'GET', '/notifications?type=unknown.type&status=bogus');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode(), 'invalid filter values are ignored');

        $this->inbox()->markAllRead(new RecipientRef('user', 'alice'));
        $crawler = $this->as('alice', 'GET', '/notifications');
        self::assertCount(0, $crawler->filter('[data-testid="notify-kit-read-all"]'), 'nothing to mark');
        self::assertCount(2, $crawler->filter('form[action$="/unread"]'));
        $this->as('dave', 'GET', '/notifications');
        self::assertStringContainsString('No notifications yet.', (string) $this->browser->getResponse()->getContent());
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }
}
