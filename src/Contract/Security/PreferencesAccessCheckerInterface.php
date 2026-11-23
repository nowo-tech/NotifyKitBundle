<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Security;

/**
 * Host SPI (`web_ui.security.preferences_access_checker`, REQ-UI-002): may this logged-in user open their
 * notification preferences page and save it? The default grants when the user holds one of
 * `web_ui.security.preferences_roles` (null = `web_ui.security.access_roles`). Whatever it answers, a person only ever
 * reads and changes their own preferences (the person comes from the token storage, never from the request).
 */
interface PreferencesAccessCheckerInterface
{
    /**
     * @param object $user the authenticated user (Security user)
     */
    public function canAccess(object $user): bool;
}
