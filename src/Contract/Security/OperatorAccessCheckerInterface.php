<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract\Security;

/**
 * Host SPI (`admin_ui.security.access_checker`, REQ-UI-002): may this user open the operator page (counts, delivery
 * health, *Run retention now*)? The default grants when the user holds one of `admin_ui.security.access_roles`
 * (`ROLE_ADMIN`). The page never shows notification content or recipients.
 */
interface OperatorAccessCheckerInterface
{
    /**
     * @param object|null $user the authenticated user; null only with `admin_ui.security.allow_unauthenticated` (demo)
     */
    public function canAccess(?object $user): bool;
}
