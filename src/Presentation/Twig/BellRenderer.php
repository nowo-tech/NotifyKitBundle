<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Twig;

use Nowo\NotifyKitBundle\Application\Realtime\RealtimeSubscriptionInterface;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Security\InboxAccessCheckerInterface;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Http\CurrentRecipient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Twig\Environment;
use Twig\Extension\RuntimeExtensionInterface;

/**
 * Runtime of `notify_kit_bell()` (contract §8.1): renders the header bell of the logged-in person, or nothing for a
 * guest or a person without inbox access. The unread count is read per call (one query; no memo kept between
 * requests, worker mode). The bell gets the URLs of its endpoints, the CSRF token of `fetch` calls and its realtime
 * set-up: `mercure` (hub URL + hashed topic), `host` (a host publisher: the page dispatches `nowo-notify-kit:updated`),
 * `poll` (`realtime.poll_seconds`, paused while the tab is hidden) or `off`.
 */
final readonly class BellRenderer implements RuntimeExtensionInterface
{
    /** Placeholder of the row id in the URL templates the script fills in. */
    public const ID_PLACEHOLDER = '00000000-0000-7000-8000-000000000000';

    /**
     * @param string $realtime effective mode: `mercure`, `host`, `poll` or `off`
     * @param string|null $template `web_ui.templates.bell`
     */
    public function __construct(
        private CurrentRecipient $current,
        private InboxAccessCheckerInterface $access,
        private InboxInterface $inbox,
        private UrlGeneratorInterface $router,
        private CsrfTokenManagerInterface $csrf,
        private ?RealtimePublisherInterface $publisher = null,
        private ?RealtimeSubscriptionInterface $mercure = null,
        private string $realtime = 'poll',
        private int $pollSeconds = 60,
        private ?string $template = null,
    ) {
    }

    /**
     * The bell, or an empty string.
     *
     * @param array<string, mixed> $options `{label: string}` (accessible name override)
     */
    public function render(Environment $twig, array $options = []): string
    {
        $user      = $this->current->user();
        $recipient = $this->current->recipientOf($user);
        if ($user === null || !$recipient instanceof RecipientRef || !$this->access->canAccess($user)) {
            return '';
        }
        $unread = $this->inbox->unreadCount($recipient);
        $mode   = $this->realtime;
        $topic  = null;
        $hub    = null;
        if ($mode === 'mercure' && $this->publisher instanceof RealtimePublisherInterface) {
            $topic = $this->publisher->topicFor($recipient);
            $hub   = $this->mercure?->hubUrlFor($topic);
            $mode  = $hub === null ? 'poll' : 'mercure';
        }

        return $twig->render($this->template ?? '@NowoNotifyKitBundle/bell/_bell.html.twig', [
            'unread'  => $unread,
            'options' => $options,
            'urls'    => [
                'inbox'    => $this->router->generate('nowo_notify_kit_inbox'),
                'menu'     => $this->router->generate('nowo_notify_kit_menu'),
                'count'    => $this->router->generate('nowo_notify_kit_count'),
                'read_all' => $this->router->generate('nowo_notify_kit_read_all'),
                'read'     => $this->router->generate('nowo_notify_kit_read', ['id' => self::ID_PLACEHOLDER]),
                'unread'   => $this->router->generate('nowo_notify_kit_unread', ['id' => self::ID_PLACEHOLDER]),
            ],
            'id_placeholder' => self::ID_PLACEHOLDER,
            'csrf'           => $this->csrf->getToken(InboxController::CSRF_ID)->getValue(),
            'realtime'       => ['mode' => $mode, 'topic' => $topic, 'hub' => $hub, 'poll_seconds' => $this->pollSeconds],
        ]);
    }
}
