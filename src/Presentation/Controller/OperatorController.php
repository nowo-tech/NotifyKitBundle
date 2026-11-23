<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Nowo\NotifyKitBundle\Application\Retention\Retention;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Contract\Security\OperatorAccessCheckerInterface;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\NowoNotifyKitBundle;
use Nowo\NotifyKitBundle\Presentation\Form\ActionType;
use Nowo\NotifyKitBundle\Presentation\Http\Conflicts;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Nowo\NotifyKitBundle\Presentation\Http\Responses;
use Nowo\NotifyKitBundle\Presentation\View\InboxPages;
use Nowo\TransactionContract\TransientStorageFailure;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Environment;

use function sprintf;

/**
 * The operator page (contract §8.4): counts of the inbox (all rows, unread rows, live dedupe markers, rows written per
 * type in the last {@see self::DAYS} days), the delivery set-up and *Run retention now* (POST + CSRF
 * `nowo_notify_kit_operator`, logged). Never notification content, never a recipient. Access: the operator access
 * checker (`admin_ui.security`, `ROLE_ADMIN` by default; REQ-UI-002).
 *
 * Stateless (worker mode).
 */
final readonly class OperatorController
{
    /** CSRF token id of the operator actions. */
    public const CSRF_ID = 'nowo_notify_kit_operator';

    /** Length of the period of the per-type counts. */
    public const DAYS = 7;

    /** Most types listed. */
    public const TYPES = 50;

    /**
     * @param string $realtime the effective realtime mode (`mercure`, `host`, `poll`, `off`)
     * @param string|null $layout `web_ui.layout_template`
     */
    public function __construct(
        private NotificationStoreInterface $store,
        private Retention $retention,
        private CurrentRecipient $current,
        private OperatorAccessCheckerInterface $access,
        private FormFactoryInterface $forms,
        private Environment $twig,
        private UrlGeneratorInterface $router,
        private TranslatorInterface $translator,
        private ?MessageBusInterface $bus = null,
        private ?ClockInterface $clock = null,
        private ?LoggerInterface $logger = null,
        private string $realtime = 'poll',
        private ?string $layout = null,
    ) {
    }

    /**
     * The counts.
     */
    #[Route('/', name: 'nowo_notify_kit_operator', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $this->check();
        $now   = $this->now();
        $form  = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => self::CSRF_ID, 'redirect' => $request->getRequestUri()]);
        $stats = $this->store->statistics(new DateTimeImmutable('@' . ($now->getTimestamp() - self::DAYS * 86400)), $now, self::TYPES);

        return Responses::noStore(new Response($this->twig->render('@NowoNotifyKitBundle/operator/index.html.twig', [
            'page' => (new InboxPages($this->layout))->operator($stats, self::DAYS, $this->realtime, $form->createView(), $this->router->generate('nowo_notify_kit_operator_retention')),
        ])));
    }

    /**
     * Runs one bounded retention pass now (`messenger.max_seconds`); the remainder goes to the message bus when there
     * is one, otherwise the page says to run it again.
     */
    #[Route('/retention', name: 'nowo_notify_kit_operator_retention', methods: ['POST'])]
    public function retention(Request $request): RedirectResponse
    {
        $user = $this->check();
        $form = $this->forms->createNamed('', ActionType::class, null, ['csrf_token_id' => self::CSRF_ID]);
        $form->handleRequest($request);
        if (!$form->isSubmitted() || !$form->isValid()) {
            throw new AccessDeniedHttpException(sprintf('Invalid CSRF token (%s).', self::CSRF_ID));
        }
        try {
            $report = $this->retention->run();
        } catch (TransientStorageFailure $failure) {
            throw Conflicts::exception($failure);
        }
        $queued = $report->remainder instanceof PruneNotificationsMessage && $this->bus instanceof MessageBusInterface;
        if ($queued) {
            $this->bus->dispatch($report->remainder);
        }
        ($this->logger ?? new NullLogger())->info('nowo_notify_kit: retention run from the operator page.', [
            'operator' => $user === null ? null : $user::class,
            'read'     => $report->read,
            'trimmed'  => $report->trimmed,
            'dedupe'   => $report->dedupe,
            'complete' => !$report->remainder instanceof PruneNotificationsMessage,
        ]);
        $session = $request->hasSession() ? $request->getSession() : null;
        if ($session instanceof FlashBagAwareSessionInterface) {
            $key = $report->remainder instanceof PruneNotificationsMessage ? $queued ? 'notify.operator.retention_queued' : 'notify.operator.retention_partial' : ('notify.operator.retention_done');
            $session->getFlashBag()->add('success', $this->translator->trans($key, ['%read%' => $report->read, '%trimmed%' => $report->trimmed, '%dedupe%' => $report->dedupe], NowoNotifyKitBundle::TRANSLATION_DOMAIN));
        }

        return new RedirectResponse(Responses::redirectTarget($request, $this->router->generate('nowo_notify_kit_operator')));
    }

    /**
     * The operator, or the firewall's login answer / 403.
     */
    private function check(): ?object
    {
        $user = $this->current->user();
        if ($this->access->canAccess($user)) {
            return $user;
        }
        if ($user === null) {
            throw new AccessDeniedException('Log in to open the notification operator page.');
        }

        throw new AccessDeniedHttpException('No access to the notification operator page.');
    }

    private function now(): DateTimeImmutable
    {
        return ($this->clock?->now() ?? new DateTimeImmutable())->setTimezone(new DateTimeZone('UTC'));
    }
}
