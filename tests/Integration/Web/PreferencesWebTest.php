<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Web;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectContextProvider;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\DomCrawler\Field\ChoiceFormField;
use Symfony\Component\DomCrawler\Form;

use function assert;
use function is_array;
use function json_decode;
use function json_encode;
use function str_repeat;

use const JSON_THROW_ON_ERROR;

/**
 * The preferences page (contract §7.1, §8.3, §14 "UI"): login, role and access answers; the person's own preferences
 * only (never a parameter); contexts only from the provider's list for this person (404 otherwise); CSRF on every POST;
 * the no-JS form (cells that differ from the page, 409 page when stale); the JSON autosave (200 / 409 stale with the
 * current cells / 422); *Reset to account settings*; the dialog fragment.
 */
final class PreferencesWebTest extends WebTestCase
{
    public function testAccessAnswers(): void
    {
        $this->boot();
        $this->as(null, 'GET', '/account/notifications');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->as(null, 'POST', '/account/notifications/cells');
        self::assertSame(401, $this->browser->getResponse()->getStatusCode());
        $this->as('alice', 'GET', '/account/notifications', roles: 'ROLE_GUEST');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'preferences_roles falls back to access_roles');
        $this->as('nobody', 'GET', '/account/notifications');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'a user the resolver refuses has no preferences');
    }

    public function testThePageShowsTheMatrixTheSwitchesAndTheOwnContextsOnly(): void
    {
        $this->boot();
        $crawler  = $this->as('alice', 'GET', '/account/notifications');
        $response = $this->browser->getResponse();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-master"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-cell-issues-mail"]'));
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-locked-issues-in_app"]'), 'the bell is locked for issues');
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-cell-mentions-in_app"]'), 'mentions make the bell optional');
        self::assertSame(['Apollo'], $crawler->filter('[data-testid="notify-kit-contexts"] label')->each(static fn (Crawler $node): string => $node->text()));
        self::assertSame('/account/notifications/context?key=project:42', $crawler->filter('[data-testid="notify-kit-override-0"]')->attr('href'));
        self::assertStringContainsString('/bundles/nowonotifykit/js/nowo-notify-kit-preferences.js', (string) $response->getContent());
        self::assertSame('-', $crawler->filter('#' . $crawler->filter('[data-testid="notify-kit-cell-issues-mail"]')->attr('data-token-id'))->attr('value'));

        $crawler = $this->as('bob', 'GET', '/account/notifications');
        self::assertCount(0, $crawler->filter('[data-testid="notify-kit-contexts"]'), 'bob has no contexts');
        $this->as('bob', 'GET', '/account/notifications/context?key=project:42');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'alice\'s project is not bob\'s');
        $this->as('alice', 'GET', '/account/notifications/context?key=project:7');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
        $this->as('alice', 'GET', '/account/notifications/context');
        self::assertSame(404, $this->browser->getResponse()->getStatusCode());
    }

    public function testTheNoJsFormSavesWhatChangedAndAnswers409WhenStale(): void
    {
        $this->boot();
        $crawler = $this->as('alice', 'GET', '/account/notifications');
        $form    = $crawler->filter('[data-testid="notify-kit-preferences-submit"]')->form();
        $mail    = (string) $crawler->filter('[data-testid="notify-kit-cell-issues-mail"]')->attr('name');
        $this->checkbox($form, $mail)->untick();
        $this->browser->submit($form, [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertSame('/account/notifications?saved=1', $this->browser->getResponse()->headers->get('Location'));
        self::assertFalse($this->preferences()->matrixFor($this->alice())->resolve('issues', 'mail')->enabled);
        $crawler = $this->as('alice', 'GET', '/account/notifications?saved=1');
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-preferences-saved"]'));

        // A page shown before another tab turned mail on and off again elsewhere: its untick of mail is stale.
        $this->preferences()->save($this->alice(), new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), true)]));
        $crawler = $this->as('alice', 'GET', '/account/notifications');
        $form    = $crawler->filter('[data-testid="notify-kit-preferences-submit"]')->form();
        $this->preferences()->save($this->alice(), new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), false)]));
        $this->checkbox($form, $mail)->untick();
        $this->checkbox($form, (string) $crawler->filter('[data-testid="notify-kit-master"]')->attr('name'))->untick();
        $crawler = $this->browser->submit($form, [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame(409, $this->browser->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-preferences-stale"]'));
        self::assertTrue($this->preferences()->matrixFor($this->alice())->masterEnabled(), 'nothing of the stale save was written');

        $this->browser->request('POST', '/account/notifications', [], [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'no CSRF token');
    }

    public function testTheJsonAutosave(): void
    {
        $this->boot();
        $token = $this->token('alice');
        $this->cells('alice', $token, [['context' => '', 'category' => 'issues', 'channel' => 'mail', 'enabled' => false, 'scope' => 'involved', 'expected' => '-']]);
        $answer = $this->json();
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertSame([['context' => '', 'category' => 'issues', 'channel' => 'mail', 'enabled' => false, 'scope' => 'involved', 'token' => '0i']], $answer['cells']);
        self::assertStringContainsString('no-store', (string) $this->browser->getResponse()->headers->get('Cache-Control'));

        $this->cells('alice', $token, [['context' => '', 'category' => 'issues', 'channel' => 'mail', 'enabled' => true, 'scope' => 'all', 'expected' => '-']]);
        self::assertSame(409, $this->browser->getResponse()->getStatusCode(), 'the page showed no row, there is one now');
        $answer = $this->json();
        self::assertSame(['stale', 'CONFLICT_001'], [$answer['error'], $answer['code']]);
        self::assertSame('0i', $answer['cells'][0]['token']);
        self::assertSame('CONFLICT_001', $this->browser->getResponse()->headers->get('X-Conflict-Code'));

        $this->cells('alice', $token, [
            ['context' => 'project:42', 'category' => '*', 'channel' => '*', 'enabled' => false, 'scope' => 'all', 'expected' => '-'],
            ['context' => 'project:42', 'category' => 'issues', 'channel' => 'mail', 'enabled' => true, 'scope' => 'all', 'expected' => '-'],
        ]);
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        $answer = $this->json();
        self::assertSame([false, '0a'], [$answer['cells'][0]['enabled'], $answer['cells'][0]['token']]);
        self::assertSame([true, '1a'], [$answer['cells'][1]['enabled'], $answer['cells'][1]['token']]);
        self::assertFalse($this->preferences()->matrixFor($this->alice())->contextEnabled(new ContextRef('project', '42')));

        $this->cells('bob', $this->token('bob'), [['context' => 'project:42', 'category' => '*', 'channel' => '*', 'enabled' => false, 'scope' => 'all', 'expected' => '-']]);
        self::assertSame(404, $this->browser->getResponse()->getStatusCode(), 'bob cannot tune alice\'s project');
        self::assertCount(0, $this->preferences()->matrixFor(new RecipientRef('user', 'bob'))->rows);

        foreach ([
            '{"changes":[{"context":"","category":"issues","channel":"in_app","enabled":false,"scope":"all","expected":"-"}]}',
            '{"changes":[{"context":"","category":"issues","channel":"mail","enabled":"no","scope":"all","expected":"-"}]}',
            '{"changes":[{"context":"","category":"issues","channel":"mail","enabled":true,"scope":"some","expected":"-"}]}',
            '{"changes":[{"context":"","category":"issues","channel":"mail","enabled":true,"scope":"all","expected":"9z"}]}',
            '{"changes":[]}',
            '{"changes":{"a":1}}',
            '[1]',
            'not json',
        ] as $body) {
            $this->browser->request('POST', '/account/notifications/cells', [], [], $this->server('alice', $token), $body);
            self::assertSame(422, $this->browser->getResponse()->getStatusCode(), $body);
        }
        $this->browser->request('POST', '/account/notifications/cells', [], [], $this->server('alice', $token), str_repeat(' ', 40000));
        self::assertSame(422, $this->browser->getResponse()->getStatusCode());

        $this->browser->request('POST', '/account/notifications/cells', [], [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => 'wrong'], '{"changes":[]}');
        self::assertSame(403, $this->browser->getResponse()->getStatusCode());
    }

    public function testTheContextPageFragmentAndReset(): void
    {
        $this->boot();
        $this->cells('alice', $this->token('alice'), [['context' => 'project:42', 'category' => 'issues', 'channel' => 'mail', 'enabled' => false, 'scope' => 'all', 'expected' => '-']]);
        $crawler = $this->as('alice', 'GET', '/account/notifications/context?key=project:42');
        self::assertSame(200, $this->browser->getResponse()->getStatusCode());
        self::assertCount(1, $crawler->filter('[data-testid="notify-kit-preferences-context"]'));
        self::assertCount(0, $crawler->filter('[data-testid="notify-kit-master"]'), 'no master switch on a context page');
        self::assertSame('project:42', $crawler->filter('[data-testid="notify-kit-cell-issues-mail"]')->attr('data-context'));

        $crawler = $this->as('alice', 'GET', '/account/notifications/context?key=project:42&fragment=1');
        self::assertCount(1, $crawler->filter('[data-nowo-notify-kit-fragment]'));
        self::assertSame('Notifications for Apollo', $crawler->filter('[data-nowo-notify-kit-fragment]')->attr('data-title'));
        self::assertStringNotContainsString('<html', (string) $this->browser->getResponse()->getContent());

        $crawler = $this->as('alice', 'GET', '/account/notifications/context?key=project:42');
        $form    = $crawler->filter('[data-testid="notify-kit-preferences-reset"]')->form();
        $this->browser->submit($form, [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame(302, $this->browser->getResponse()->getStatusCode());
        self::assertFalse($this->preferences()->matrixFor($this->alice())->hasOverrides(new ContextRef('project', '42')));
        $this->browser->request('POST', '/account/notifications/context/reset?key=project:42', [], [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame(403, $this->browser->getResponse()->getStatusCode(), 'no CSRF token');

        $crawler = $this->as('alice', 'GET', '/account/notifications/context?key=project:42');
        $form    = $crawler->filter('[data-testid="notify-kit-preferences-submit"]')->form();
        $this->checkbox($form, (string) $crawler->filter('[data-testid="notify-kit-cell-issues-mail"]')->attr('name'))->untick();
        $this->browser->submit($form, [], ['HTTP_X_TEST_USER' => 'alice', 'HTTP_X_TEST_ROLES' => 'ROLE_USER']);
        self::assertSame('/account/notifications/context?key=project:42&saved=1', $this->browser->getResponse()->headers->get('Location'));
        self::assertTrue($this->preferences()->matrixFor($this->alice())->hasOverrides(new ContextRef('project', '42')));
    }

    private function checkbox(Form $form, string $name): ChoiceFormField
    {
        $field = $form[$name];
        assert($field instanceof ChoiceFormField);

        return $field;
    }

    private function boot(): void
    {
        $this->bootWeb([
            'types' => [
                'issue.assigned' => ['category' => 'issues', 'permission' => 'issue.view', 'icon' => 'tabler:bug', 'channels' => ['in_app' => true, 'mail' => true]],
                'mention.added'  => ['category' => 'mentions', 'in_app_optional' => true],
            ],
            'preferences' => ['contexts' => ['provider' => ProjectContextProvider::class]],
        ], static function (ContainerConfigurator $container): void {
            $container->services()->set(ProjectContextProvider::class)->args([['user:alice' => ['42' => 'Apollo']]]);
        });
    }

    private function alice(): RecipientRef
    {
        return new RecipientRef('user', 'alice');
    }

    private function preferences(): PreferencesInterface
    {
        $preferences = $this->kernel->getContainer()->get(PreferencesInterface::class);
        assert($preferences instanceof PreferencesInterface);

        return $preferences;
    }

    private function token(string $user): string
    {
        return (string) $this->as($user, 'GET', '/account/notifications')->filter('[data-nowo-notify-kit-preferences]')->attr('data-csrf-token');
    }

    /**
     * @param list<array<string, mixed>> $changes
     */
    private function cells(string $user, string $token, array $changes): void
    {
        $this->browser->request('POST', '/account/notifications/cells', [], [], $this->server($user, $token), json_encode(['changes' => $changes], JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, string>
     */
    private function server(string $user, string $token): array
    {
        return ['HTTP_X_TEST_USER' => $user, 'HTTP_X_TEST_ROLES' => 'ROLE_USER', 'CONTENT_TYPE' => 'application/json', 'HTTP_X_CSRF_TOKEN' => $token];
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        $data = json_decode((string) $this->browser->getResponse()->getContent(), true, 8, JSON_THROW_ON_ERROR);
        assert(is_array($data));

        return $data;
    }
}
