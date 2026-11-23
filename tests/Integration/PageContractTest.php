<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Application\Preferences\PreferenceDefaults;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationView;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\InboxStatistics;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Nowo\NotifyKitBundle\Presentation\Form\ActionType;
use Nowo\NotifyKitBundle\Presentation\Form\InboxFilterType;
use Nowo\NotifyKitBundle\Presentation\Form\PreferencesType;
use Nowo\NotifyKitBundle\Presentation\View\InboxPages;
use Nowo\NotifyKitBundle\Presentation\View\InboxRow;
use Nowo\NotifyKitBundle\Presentation\View\PreferencePages;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectAccessChecker;
use Nowo\NotifyKitBundle\Tests\Fixtures\Web\WebRecipientResolver;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Contracts\Translation\TranslatorInterface;

use function assert;
use function dirname;

/**
 * REQ-UI-003: the inbox, preferences (S3) and operator pages extend a UiKit scaffold, fill the canonical blocks, print framework classes
 * only through theme slots, keep the CSRF fields of their action forms and have no raw translation keys (UiKit
 * PageContractTestCase). The view models are built by {@see InboxPages} as the controllers do; the FormViews come
 * from the bundle's FormKit types in a booted test kernel.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        [$forms, $translator] = self::services();
        $pages                = new InboxPages();
        $filters              = $forms->createNamed('', InboxFilterType::class, null, ['types' => ['billing.ready' => 'billing.ready'], 'categories' => ['notify.category.general' => 'general']])->createView();
        $actions              = [];
        foreach (['read', 'unread', 'read_all'] as $action) {
            $actions[$action] = $forms->createNamed('', ActionType::class, null, ['csrf_token_id' => InboxController::CSRF_ID, 'redirect' => '/notifications'])->createView();
        }
        $rows = [
            self::row('019a0000-0000-7000-8000-000000000001', 'Ana assigned you <b>an issue</b>', 'Website', null, 3),
            self::row('019a0000-0000-7000-8000-000000000002', 'Your export is ready', null, new DateTimeImmutable('2026-11-15 10:30:00'), 1),
        ];

        yield 'inbox' => new PageFixture('@NowoNotifyKitBundle/inbox/index.html.twig', ['page' => $pages->inbox($rows, $filters, $actions, 1, '/notifications/read-all', null), 'next_href' => '/notifications?after=x', 'first_href' => '/notifications']);
        yield 'inbox (filtered, empty)' => new PageFixture('@NowoNotifyKitBundle/inbox/index.html.twig', ['page' => $pages->inbox([], $filters, $actions, 0, '/notifications/read-all', '/notifications'), 'next_href' => null, 'first_href' => null]);
        yield 'inbox (empty)' => new PageFixture('@NowoNotifyKitBundle/inbox/index.html.twig', ['page' => $pages->inbox([], $filters, $actions, 0, '/notifications/read-all', null), 'next_href' => null, 'first_href' => null]);

        $retention = $forms->createNamed('', ActionType::class, null, ['csrf_token_id' => OperatorController::CSRF_ID, 'redirect' => '/admin/notifications'])->createView();
        yield 'operator' => new PageFixture('@NowoNotifyKitBundle/operator/index.html.twig', ['page' => $pages->operator(new InboxStatistics(120, 40, 3, ['issue.assigned' => 80, 'billing.ready' => 12]), 7, 'mercure', $retention, '/admin/notifications/retention')]);
        yield from self::preferencePages($forms, $translator);
        yield 'operator (no rows)' => new PageFixture('@NowoNotifyKitBundle/operator/index.html.twig', ['page' => $pages->operator(new InboxStatistics(0, 0, 0, []), 7, 'poll', $retention, '/admin/notifications/retention')]);
    }

    /**
     * The preferences page (account, with a stale notice) and a context page (S3).
     *
     * @return iterable<string, PageFixture>
     */
    private static function preferencePages(FormFactoryInterface $forms, TranslatorInterface $translator): iterable
    {
        $registry = TypeRegistry::create(['billing.ready' => ['category' => 'general', 'icon' => null, 'title_key' => null, 'permission' => null, 'channels' => ['in_app' => true, 'mail' => true], 'in_app_optional' => false, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true]], [], [], false, null);
        $pages    = new PreferencePages($registry, $translator);
        $matrix   = PreferenceDefaults::of($registry)->matrix(new RecipientRef('user', 'u1'), [new PreferenceRow(PreferenceKey::account('general', 'mail'), false)]);
        $project  = new ContextChoice(new ContextRef('project', '42'), 'Website <b>', 'Projects');
        $href     = static fn (ContextChoice $choice): string => '/account/notifications/context?key=' . $choice->ref->key();
        $vars     = ['cells_url' => '/account/notifications/cells', 'csrf' => 'token'];

        $grid = $pages->grid($matrix, null, [$project], $href);
        $form = $forms->createNamed('nkp', PreferencesType::class, null, ['grid' => $grid])->createView();
        yield 'preferences' => new PageFixture('@NowoNotifyKitBundle/preferences/index.html.twig', ['page' => $pages->page($grid, $form), 'grid' => $grid, 'form' => $form, 'notice' => 'stale'] + $vars);

        $grid   = $pages->grid($matrix, $project, [], $href);
        $form   = $forms->createNamed('nkc', PreferencesType::class, null, ['grid' => $grid])->createView();
        $reset  = $forms->createNamed('', ActionType::class, null, ['csrf_token_id' => PreferencesType::CSRF_ID, 'redirect' => '/account/notifications'])->createView();
        $action = [
            new Action('reset', new Label('notify.preferences.reset', 'NowoNotifyKitBundle'), '/account/notifications/context/reset?key=project:42', method: 'POST', form: $reset),
            new Action('prev', new Label('notify.preferences.back', 'NowoNotifyKitBundle'), '/account/notifications'),
        ];
        yield 'preferences (context)' => new PageFixture('@NowoNotifyKitBundle/preferences/index.html.twig', ['page' => $pages->page($grid, $form, $action), 'grid' => $grid, 'form' => $form, 'notice' => 'saved'] + $vars);
    }

    protected static function templatePaths(): array
    {
        $views = dirname(__DIR__, 2) . '/src/Resources/views';

        return ['NowoNotifyKitBundle' => $views, '!NowoNotifyKitBundle' => $views, 'NowoNotifyKit' => $views, '!NowoNotifyKit' => $views];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 2) . '/src/Resources/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 2) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }

    private static function row(string $id, string $title, ?string $context, ?DateTimeImmutable $readAt, int $repeats): InboxRow
    {
        $view = new NotificationView(
            new NotificationId($id),
            new RecipientRef('user', 'u1'),
            'issue.assigned',
            'issues',
            new RecipientRef('user', 'ana'),
            $context === null ? null : new ContextRef('project', '42'),
            'issue:1',
            '/p/42/issues/1',
            [],
            $repeats,
            new DateTimeImmutable('2026-11-15 10:00:00'),
            $readAt,
        );

        return new InboxRow($view, $title, $context, '5 min ago', 'tabler:bug', '/notifications/' . $id . '/read', '/notifications/' . $id . '/unread');
    }

    /**
     * The form factory (FormKit types, CSRF on a mock session) and the translator of a booted web test kernel.
     *
     * @return array{FormFactoryInterface, TranslatorInterface}
     */
    private static function services(): array
    {
        $host   = Host::standard();
        $base   = $host->configure();
        $kernel = new TestKernel('page_contract', $host->bundleConfig([
            'recipient' => ['class' => null, 'resolver' => WebRecipientResolver::class],
            'access'    => ['checker' => ProjectAccessChecker::class],
            'web_ui'    => ['enabled' => true],
        ]), Host::databaseUrl(), static function (ContainerConfigurator $container, string $url) use ($base): void {
            $base($container, $url);
            $container->services()->set(WebRecipientResolver::class);
            $container->services()->set(ProjectAccessChecker::class);
        }, false, null, true);
        $kernel->boot();
        $services = $kernel->getContainer()->get('test.service_container');
        assert($services instanceof ContainerInterface);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $stack = $services->get('request_stack');
        assert($stack instanceof RequestStack);
        $stack->push($request);
        $forms = $services->get('form.factory');
        assert($forms instanceof FormFactoryInterface);
        $translator = $services->get('translator');
        assert($translator instanceof TranslatorInterface);

        return [$forms, $translator];
    }
}
