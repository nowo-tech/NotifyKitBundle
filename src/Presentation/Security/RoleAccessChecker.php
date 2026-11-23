<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Security;

use Nowo\NotifyKitBundle\Contract\Security\InboxAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\Security\OperatorAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\Security\PreferencesAccessCheckerInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Default access checker of the inbox (`web_ui.security`), of the preferences page (`web_ui.security.preferences_roles`,
 * S3) and of the operator page (`admin_ui.security`), REQ-UI-002:
 * a logged-in user holding at least one of `access_roles` (an empty list = any logged-in user, the firewall decides).
 * Without a user it answers `allow_unauthenticated` (operator page, demos only; the inbox always needs a person).
 *
 * Stateless (worker mode).
 */
final readonly class RoleAccessChecker implements InboxAccessCheckerInterface, OperatorAccessCheckerInterface, PreferencesAccessCheckerInterface
{
    /**
     * @param list<string> $roles `access_roles`
     * @param bool $allowUnauthenticated `allow_unauthenticated` (never in production)
     */
    public function __construct(
        private ?AuthorizationCheckerInterface $authorization,
        private array $roles,
        private bool $allowUnauthenticated = false,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function canAccess(?object $user): bool
    {
        if ($user === null) {
            return $this->allowUnauthenticated;
        }
        if ($this->roles === []) {
            return true;
        }
        foreach ($this->roles as $role) {
            if ($this->authorization?->isGranted($role) === true) {
                return true;
            }
        }

        return false;
    }
}
