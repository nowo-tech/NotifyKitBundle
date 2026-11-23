<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Security;

/**
 * Host SPI (`web_ui.security.access_checker`, REQ-UI-002): may this logged-in user open their inbox, bell and inbox
 * JSON endpoints? The default grants when the user holds one of `web_ui.security.access_roles`. Whatever it answers,
 * a person only ever sees and changes their own rows.
 */
interface InboxAccessCheckerInterface
{
    /**
     * @param object $user the authenticated user (Security user)
     */
    public function canAccess(object $user): bool;
}
