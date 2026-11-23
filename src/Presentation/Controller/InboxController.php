<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Controller;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\InboxStatus;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationView;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Security\InboxAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Presentation\Form\ActionType;
use Nowo\NotifyKitBundle\Presentation\Form\InboxFilterType;
use Nowo\NotifyKitBundle\Presentation\Http\Conflicts;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Http\Responses;
use Nowo\NotifyKitBundle\Presentation\Routing\NotifyKitRouteLoader;
use Nowo\NotifyKitBundle\Presentation\Title\NotificationTitles;
use Nowo\NotifyKitBundle\Presentation\View\InboxPages;
use Nowo\NotifyKitBundle\Presentation\View\InboxRow;
use Nowo\NotifyKitBundle\Presentation\View\RelativeTime;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Clock\ClockInterface;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;

use function array_filter;
use function array_map;
use function in_array;
use function is_string;
use function sprintf;

/**
 * The person's inbox (contract §7.1, §8.1–§8.2): the inbox page, the bell menu fragment, the unread count, and the
 * row operations. Every action resolves the logged-in person (none → the firewall's login answer), checks the inbox
 * access checker (REQ-UI-002) and works on **that person's rows only**: an id of another person, or a hidden row,
 * answers 404. POSTs need the CSRF token `nowo_notify_kit_inbox` (`X-CSRF-TOKEN` header for `fetch`, the `_token`
 * field of a FormKit form otherwise): a missing or wrong token answers 403. Answers are `no-store, private`.
 *
 * Stateless (worker mode): every value comes from the request and the ports.
 */
final readonly class InboxController
{
    /** CSRF token id of every inbox POST. */
    public const CSRF_ID = 'nowo_notify_kit_inbox';

    /**
     * @param int $menuLimit `inbox.menu_limit`
     * @param int $pageSize `inbox.page_size`
     * @param string|null $layout `web_ui.layout_template`
     * @param string|null $inboxTemplate `web_ui.templates.inbox`
     * @param string|null $menuTemplate `web_ui.templates.menu`
     */
    public function __construct(
        private InboxInterface $inbox,
        private TypeRegistryInterface $types,
        private CurrentRecipient $current,
        private InboxAccessCheckerInterface $access,
        private NotificationTitles $titles,
        private RelativeTime $time,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrf,
        private ?ClockInterface $clock = null,
        private int $menuLimit = 15,
        private int $pageSize = 25,
        private ?string $layout = null,
        private ?string $inboxTemplate = null,
        private ?string $menuTemplate = null,
    ) {
    }

    /**
     * The inbox page: filters (type, category, status), keyset pages of `inbox.page_size`, per-row read / unread and
     * *Mark all as read*.
     */
    #[Route('/', name: 'nowo_notify_kit_inbox', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $recipient = $this->recipient();
        $types     = [];
        foreach ($this->types->types() as $key => $type) {
            $types[(string) $key] = (string) $key;
        }
        $categories = [];
        foreach ($this->types->categories() as $key => $category) {
            $categories[$category->labelKey] = (string) $key;
        }
        $filters = $this->forms->createNamed('', InboxFilterType::class, null, ['types' => $types, 'categories' => $categories]);
        $filters->handleRequest($request);
        $filter = InboxFilter::all();
        if ($filters->isSubmitted() && $filters->isValid()) {
            $type     = $this->text($filters->get('type')->getData());
            $category = $this->text($filters->get('category')->getData());
            $filter   = new InboxFilter(
                $type !== null && in_array($type, $types, true) ? $type : null,
                $category !== null && in_array($category, $categories, true) ? $category : null,
                InboxStatus::tryFrom($this->text($filters->get('status')->getData()) ?? ''),
            );
        }
        $after = $this->text($request->query->get('after'));
        $page  = $this->inbox->page($recipient, $filter, $after, $this->pageSize);
        $keep  = array_filter(['type' => $filter->type, 'category' => $filter->category, 'status' => $filter->status?->value], static fn (?string $value): bool => $value !== null);
        $here  = $request->getRequestUri();
        $forms = [];
        foreach (['read', 'unread', 'read_all'] as $action) {
            $forms[$action] = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => self::CSRF_ID, 'redirect' => $here])->createView();
        }
        $list = (new InboxPages($this->layout))->inbox(
            $this->rows($page->items, $request->getLocale()),
            $filters->createView(),
            $forms,
            $this->inbox->unreadCount($recipient),
            $this->router->generate('nowo_notify_kit_read_all'),
            $filter->isEmpty() ? null : $this->router->generate('nowo_notify_kit_inbox'),
        );

        return Responses::noStore(new Response($this->twig->render($this->inboxTemplate ?? '@NowoNotifyKitBundle/inbox/index.html.twig', [
            'page'       => $list,
            'next_href'  => $page->next !== null ? $this->router->generate('nowo_notify_kit_inbox', $keep + ['after' => $page->next]) : null,
            'first_href' => $after !== null ? $this->router->generate('nowo_notify_kit_inbox', $keep) : null,
        ])));
    }

    /**
     * The bell panel fragment: the latest `inbox.menu_limit` visible rows.
     */
    #[Route('/menu', name: 'nowo_notify_kit_menu', methods: ['GET'])]
    public function menu(Request $request): Response
    {
        $recipient = $this->recipient();
        $page      = $this->inbox->page($recipient, InboxFilter::all(), null, $this->menuLimit);

        return Responses::noStore(new Response($this->twig->render($this->menuTemplate ?? '@NowoNotifyKitBundle/bell/_menu.html.twig', [
            'rows'       => $this->rows($page->items, $request->getLocale()),
            'unread'     => $this->inbox->unreadCount($recipient),
            'inbox_href' => $this->router->generate('nowo_notify_kit_inbox'),
        ])));
    }

    /**
     * `{"unread": n}` with an `ETag` (poll mode); `304` when it did not change.
     */
    #[Route('/count', name: 'nowo_notify_kit_count', methods: ['GET'])]
    public function count(Request $request): Response
    {
        $unread   = $this->inbox->unreadCount($this->recipient());
        $response = Responses::noStore(new JsonResponse(['unread' => $unread], 200, ['ETag' => '"u' . $unread . '"']));
        $response->isNotModified($request);

        return $response;
    }

    /**
     * Marks every visible unread row read.
     */
    #[Route('/read-all', name: 'nowo_notify_kit_read_all', methods: ['POST'])]
    public function readAll(Request $request): Response
    {
        $recipient = $this->recipient();
        $this->checkCsrf($request);
        try {
            $marked = $this->inbox->markAllRead($recipient);
        } catch (TransientStorageFailure $failure) {
            return $this->conflict($request, $failure);
        }
        if (Responses::isFetch($request)) {
            return Responses::noStore(new JsonResponse(['ok' => true, 'marked' => $marked, 'unreadCount' => $this->inbox->unreadCount($recipient)]));
        }

        return new RedirectResponse(Responses::redirectTarget($request, $this->router->generate('nowo_notify_kit_inbox')));
    }

    /**
     * Marks one row read.
     */
    #[Route('/{id}/read', name: 'nowo_notify_kit_read', requirements: ['id' => NotifyKitRouteLoader::ID_REQUIREMENT], methods: ['POST'])]
    public function read(Request $request, string $id): Response
    {
        return $this->toggle($request, $id, true);
    }

    /**
     * Marks one row unread again.
     */
    #[Route('/{id}/unread', name: 'nowo_notify_kit_unread', requirements: ['id' => NotifyKitRouteLoader::ID_REQUIREMENT], methods: ['POST'])]
    public function unread(Request $request, string $id): Response
    {
        return $this->toggle($request, $id, false);
    }

    /**
     * Display rows of $views.
     *
     * @param list<NotificationView> $views
     *
     * @return list<InboxRow>
     */
    private function rows(array $views, string $locale): array
    {
        $now = $this->clock?->now() ?? new DateTimeImmutable();

        return array_map(fn (NotificationView $view): InboxRow => new InboxRow(
            $view,
            $this->titles->title($view, $locale),
            $this->titles->contextLabel($view->context),
            $this->time->label($view->createdAt, $now, $locale),
            $this->types->type($view->type)->icon,
            $this->router->generate('nowo_notify_kit_read', ['id' => $view->id->value]),
            $this->router->generate('nowo_notify_kit_unread', ['id' => $view->id->value]),
        ), $views);
    }

    private function toggle(Request $request, string $id, bool $read): Response
    {
        $recipient = $this->recipient();
        $this->checkCsrf($request);
        if (!NotificationId::isValid($id)) {
            throw new NotFoundHttpException('Notification not found.');
        }
        $notification = new NotificationId($id);
        try {
            $found = $read ? $this->inbox->markRead($recipient, $notification) : $this->inbox->markUnread($recipient, $notification);
        } catch (TransientStorageFailure $failure) {
            return $this->conflict($request, $failure);
        }
        if (!$found) {
            if (Responses::isFetch($request)) {
                return Responses::noStore(new JsonResponse(['ok' => false, 'error' => 'not_found'], 404));
            }

            throw new NotFoundHttpException('Notification not found.');
        }
        if (Responses::isFetch($request)) {
            return Responses::noStore(new JsonResponse(['ok' => true, 'id' => $id, 'unread' => !$read, 'unreadCount' => $this->inbox->unreadCount($recipient)]));
        }

        return new RedirectResponse(Responses::redirectTarget($request, $this->router->generate('nowo_notify_kit_inbox')));
    }

    private function conflict(Request $request, TransientStorageFailure $failure): JsonResponse
    {
        if (Responses::isFetch($request)) {
            return Conflicts::json($failure);
        }

        throw Conflicts::exception($failure);
    }

    /**
     * The logged-in person: none → the firewall's login answer (401 / 302); refused by the access checker → 403.
     */
    private function recipient(): RecipientRef
    {
        $user = $this->current->user();
        if ($user === null) {
            throw new AccessDeniedException('Log in to read your notifications.');
        }
        $recipient = $this->current->recipientOf($user);
        if (!$recipient instanceof RecipientRef || !$this->access->canAccess($user)) {
            throw new AccessDeniedHttpException('No access to the notification inbox.');
        }

        return $recipient;
    }

    /**
     * CSRF of a POST: the header of a `fetch` call, or the `_token` of the FormKit form; 403 otherwise.
     */
    private function checkCsrf(Request $request): void
    {
        if (Responses::isFetch($request)) {
            $token = $request->headers->get(Responses::CSRF_HEADER);
            if (is_string($token) && $this->csrf->isTokenValid(new CsrfToken(self::CSRF_ID, $token))) {
                return;
            }
        } else {
            $form = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => self::CSRF_ID]);
            $form->handleRequest($request);
            if ($form->isSubmitted() && $form->isValid()) {
                return;
            }
        }

        throw new AccessDeniedHttpException(sprintf('Invalid CSRF token (%s).', self::CSRF_ID));
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
