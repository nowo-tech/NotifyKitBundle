<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Web;

use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

/**
 * The operator page (contract §8.4): admins only (REQ-UI-002), counts and no content, *Run retention now* behind CSRF.
 */
final class OperatorWebTest extends WebTestCase
{
    public function testOnlyOperatorsSeeTheCountsAndNeverTheContent(): void
    {
        $this->bootWeb();
        $this->notify('invitation_accepted', ['alice', 'bob'], 'carol', null, ['name' => 'Secret project']);
        $this->as(null, 'GET', '/admin/notifications');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->as('alice', 'GET', '/admin/notifications');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());

        $crawler = $this->as('root', 'GET', '/admin/notifications', roles: 'ROLE_ADMIN');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $html = (string) $this->browser->getResponse()->getContent();
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('2', $crawler->filter('[data-testid="notify-kit-rows"]')->text());
        self::assertStringContainsString('invitation_accepted', $html);
        self::assertStringNotContainsString('Secret project', $html, 'no content');
        self::assertStringNotContainsString('alice', $html, 'no recipient');
        self::assertStringContainsString('poll', $html, 'the realtime mode');
    }

    public function testRunRetentionNowNeedsTheTokenAndReportsWhatItDeleted(): void
    {
        $this->bootWeb(['retention' => ['read_days' => 1]]);
        $this->notify('billing.ready', ['alice']);
        $crawler = $this->as('root', 'GET', '/admin/notifications', roles: 'ROLE_ADMIN');
        $token   = (string) $crawler->filter('form[action$="/retention"] input[name="_token"]')->attr('value');

        $this->as('root', 'POST', '/admin/notifications/retention', [], [], 'ROLE_ADMIN');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'no token');
        $this->as('alice', 'POST', '/admin/notifications/retention', [], ['_token' => $token]);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'not an operator');
        $this->as('root', 'POST', '/admin/notifications/retention', [], ['_token' => $token, '_redirect' => '/admin/notifications'], 'ROLE_ADMIN');
        self::assertTrue($this->browser->getResponse()->isRedirect('/admin/notifications'));
        $session = $this->browser->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);
        self::assertSame(['Retention done: 0 read rows, 0 over the cap and 0 dedupe keys deleted.'], $session->getFlashBag()->peek('success'));
    }
}
