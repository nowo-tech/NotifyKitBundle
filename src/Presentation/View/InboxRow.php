<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

use Nowo\NotifyKitBundle\Contract\NotificationView;

/**
 * One row of the bell menu and of the inbox page: the stored view plus its display text (plain text, printed
 * escaped) and the URLs of its actions.
 *
 * @internal
 */
final readonly class InboxRow
{
    /**
     * @param string $title display title (plain text)
     * @param string|null $context context label (plain text)
     * @param string $when relative time label
     * @param string $icon type icon name (e.g. `tabler:bell`), printed as a data attribute only
     * @param string $readHref POST URL that marks the row read
     * @param string $unreadHref POST URL that marks the row unread
     */
    public function __construct(
        public NotificationView $notification,
        public string $title,
        public ?string $context,
        public string $when,
        public string $icon,
        public string $readHref,
        public string $unreadHref,
    ) {
    }

    /**
     * Whether the row is unread.
     */
    public function isUnread(): bool
    {
        return !$this->notification->isRead();
    }
}
