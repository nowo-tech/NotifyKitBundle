<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Presentation;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Application\Default\NoContextProvider;
use Nowo\NotifyKitBundle\Application\Default\ResolverRecipientDirectory;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxStatus;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationView;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Domain\InboxCursor;
use Nowo\NotifyKitBundle\Presentation\Http\Conflicts;
use Nowo\NotifyKitBundle\Presentation\Http\Responses;
use Nowo\NotifyKitBundle\Presentation\Security\RoleAccessChecker;
use Nowo\NotifyKitBundle\Presentation\Title\NotificationTitles;
use Nowo\NotifyKitBundle\Presentation\View\RelativeTime;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureKind;
use Nowo\TransactionDoctrine\WriteUnit\ConcurrencyConflict;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Translation\Loader\ArrayLoader;
use Symfony\Component\Translation\Translator;

use function json_decode;

/**
 * The small pieces of the read side and the pages: role checker, titles, relative time, HTTP helpers, directory and
 * context defaults, filters and cursors.
 */
final class PresentationPiecesTest extends TestCase
{
    public function testTheRoleCheckerNeedsAUserAndOneRole(): void
    {
        $authorization = $this->createMock(AuthorizationCheckerInterface::class);
        $authorization->method('isGranted')->willReturnCallback(static fn (string $role): bool => $role === 'ROLE_OPS');
        $user = new stdClass();

        self::assertTrue((new RoleAccessChecker($authorization, ['ROLE_ADMIN', 'ROLE_OPS']))->canAccess($user));
        self::assertFalse((new RoleAccessChecker($authorization, ['ROLE_ADMIN']))->canAccess($user));
        self::assertTrue((new RoleAccessChecker($authorization, []))->canAccess($user), 'no role list: any logged-in user');
        self::assertFalse((new RoleAccessChecker(null, ['ROLE_ADMIN']))->canAccess($user), 'no SecurityBundle: nobody holds a role');
        self::assertFalse((new RoleAccessChecker($authorization, []))->canAccess(null));
        self::assertTrue((new RoleAccessChecker(null, ['ROLE_ADMIN'], true))->canAccess(null), 'allow_unauthenticated (demo)');
    }

    public function testTitlesTranslateAtDisplayTimeAndParamsCannotSpoofTheBuiltIns(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['notify.type.invite.title' => '%actor% invited you to %name% (%target%, %repeats%x)'], 'en', 'messages');
        $translator->addResource('array', ['notify.type.generic.title' => 'Notification', 'notify.actor.someone' => 'Someone'], 'en', 'NowoNotifyKitBundle');
        $translator->addResource('array', ['team.title' => "%actor%\r\nbroke\tit"], 'en', 'team');
        $types = TypeRegistry::create(
            ['invite' => $this->type('notify.type.invite.title', null), 'team.broke' => $this->type('team.title', 'team')],
            [],
            [],
            false,
            null,
        );
        $directory = new ResolverRecipientDirectory(new class implements RecipientResolverInterface {
            public function recipientOf(object $user): RecipientRef
            {
                return new RecipientRef('user', 'x');
            }

            public function userOf(RecipientRef $recipient): ?object
            {
                return $recipient->id === 'ana' ? new class {
                    public function __toString(): string
                    {
                        return "Ana\nRuiz";
                    }

                    public function getEmail(): string
                    {
                        return 'ana@example.com';
                    }

                    public function getLocale(): string
                    {
                        return '';
                    }
                } : null;
            }
        });
        $contexts = new class implements ContextProviderInterface {
            public function contextsFor(RecipientRef $recipient): array
            {
                return [];
            }

            public function label(ContextRef $context): ?string
            {
                return $context->id === '42' ? "Web\nsite" : null;
            }
        };
        $titles = new NotificationTitles($translator, $types, $directory, $contexts);

        self::assertSame('Ana Ruiz invited you to Team (invite:1, 2x)', $titles->title($this->view('invite', 'ana', ['name' => 'Team', 'actor' => 'Mallory', 'target' => 'evil', 'repeats' => '99']), 'en'));
        self::assertSame('Someone invited you to %name% (invite:1, 2x)', $titles->title($this->view('invite', null, [])));
        self::assertSame('Someone broke it', $titles->title($this->view('team.broke', 'ghost', [])), 'one line');
        self::assertSame('Notification', $titles->title($this->view('brand.new_type', 'ana', [])), 'an unknown type gets the generic title');
        self::assertSame('Web site', $titles->contextLabel(new ContextRef('project', '42')));
        self::assertNull($titles->contextLabel(new ContextRef('project', '7')));
        self::assertNull($titles->contextLabel(null));
        self::assertSame('ana@example.com', $directory->email(new RecipientRef('user', 'ana')));
        self::assertNull($directory->locale(new RecipientRef('user', 'ana')), 'an empty value is no value');
        self::assertNull($directory->email(new RecipientRef('user', 'ghost')));
        self::assertNull($directory->avatarUrl(new RecipientRef('user', 'ana')));
        self::assertNull($directory->timezone(new RecipientRef('user', 'ana')));
        self::assertTrue($directory->isActive(new RecipientRef('user', 'ana')));
        self::assertNull((new ResolverRecipientDirectory())->displayName(new RecipientRef('user', 'ana')), 'no resolver');
        self::assertNull((new ResolverRecipientDirectory())->email(new RecipientRef('user', 'ana')));
    }

    public function testRelativeTime(): void
    {
        $translator = new Translator('en');
        $translator->addLoader('array', new ArrayLoader());
        $translator->addResource('array', ['notify.time.just_now' => 'now', 'notify.time.minutes' => '%count%m', 'notify.time.hours' => '%count%h', 'notify.time.days' => '%count%d'], 'en', 'NowoNotifyKitBundle');
        $time = new RelativeTime($translator);
        $now  = new DateTimeImmutable('2026-11-15 10:00:00');

        self::assertSame('now', $time->label($now->modify('+5 seconds'), $now));
        self::assertSame('5m', $time->label($now->modify('-5 minutes'), $now));
        self::assertSame('3h', $time->label($now->modify('-3 hours'), $now));
        self::assertSame('2d', $time->label($now->modify('-2 days'), $now));
        self::assertSame('2026-11-01', $time->label(new DateTimeImmutable('2026-11-01 08:00:00'), $now));
    }

    public function testConflictAnswersAndRedirectTargets(): void
    {
        $json = Conflicts::json(new TransientStorageFailure(3));
        self::assertSame(409, $json->getStatusCode());
        self::assertSame('3', $json->headers->get('Retry-After'));
        self::assertSame(['ok' => false, 'error' => 'conflict', 'code' => 'CONFLICT_001'], json_decode((string) $json->getContent(), true));
        self::assertStringContainsString('no-store', (string) $json->headers->get('Cache-Control'));
        self::assertSame('1', Conflicts::exception(new TransientStorageFailure())->getHeaders()['Retry-After']);
        self::assertSame('CONFLICT_001', Conflicts::exception(new TransientStorageFailure())->getMessage());
        $committed = new ConcurrencyConflict(1, TransientFailureKind::Deadlock, null, new RuntimeException('deadlock'), true);
        self::assertSame([], Conflicts::exception($committed)->getHeaders(), 'CONFLICT_002 has no Retry-After');
        self::assertSame('CONFLICT_002', Conflicts::exception($committed)->getMessage());

        self::assertSame('/a?b=1', Responses::redirectTarget(Request::create('/', 'POST', ['_redirect' => '/a?b=1']), '/x'));
        self::assertSame('/x', Responses::redirectTarget(Request::create('/', 'POST', ['_redirect' => ['/a']]), '/x'));
        self::assertSame('/x', Responses::redirectTarget(Request::create('/', 'POST'), '/x'));
        self::assertFalse(Responses::isFetch(Request::create('/')));
    }

    public function testDefaultsFiltersAndCursors(): void
    {
        self::assertSame([], (new NoContextProvider())->contextsFor(new RecipientRef('user', 'a')));
        self::assertNull((new NoContextProvider())->label(new ContextRef('project', '1')));
        $choice = new ContextChoice(new ContextRef('project', '1'), 'Web', 'Acme');
        self::assertSame('Acme', $choice->group);

        self::assertTrue(InboxFilter::all()->isEmpty());
        self::assertFalse((new InboxFilter(null, null, InboxStatus::Read))->isEmpty());
        self::assertFalse((new InboxFilter('a.b'))->isEmpty());
        self::assertFalse((new InboxFilter(null, 'account'))->isEmpty());
        self::assertNull(InboxCursor::parse(null));
        self::assertNull(InboxCursor::parse('20261399000000.019a0000-0000-7000-8000-000000000001'), 'not a date');
        self::assertSame('20261115100000.019a0000-0000-7000-8000-000000000001', InboxCursor::parse('20261115100000.019a0000-0000-7000-8000-000000000001')?->toString());

        $this->expectException(InvalidNotificationArgumentException::class);
        new TypeDefinition('a.b', 'general', 'tabler:bell', 'k', null, [], titleDomain: '');
    }

    public function testAFilterRefusesABadTypeOrCategory(): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        new InboxFilter(null, 'Not A Key');
    }

    /**
     * @return array{category: string, icon: null, title_key: string, title_domain: string|null, permission: null, channels: array<string, bool>, in_app_optional: bool, locked_channels: list<string>, urgency: string, coalesce: bool, in_matrix: bool}
     */
    private function type(string $titleKey, ?string $domain): array
    {
        return ['category' => 'general', 'icon' => null, 'title_key' => $titleKey, 'title_domain' => $domain, 'permission' => null, 'channels' => [], 'in_app_optional' => false, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true];
    }

    /**
     * @param array<string, string> $params
     */
    private function view(string $type, ?string $actor, array $params): NotificationView
    {
        return new NotificationView(
            new NotificationId('019a0000-0000-7000-8000-000000000001'),
            new RecipientRef('user', 'u1'),
            $type,
            'general',
            $actor === null ? null : new RecipientRef('user', $actor),
            null,
            'invite:1',
            '/i',
            $params,
            2,
            new DateTimeImmutable('2026-11-15 10:00:00'),
            null,
        );
    }
}
