<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Routing;

use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Symfony\Component\Config\Loader\Loader;
use Symfony\Component\Routing\RouteCollection;

/**
 * Route loader of the bundle pages (contract §7.1), type `nowo_notify_kit`: the `#[Route]` attributes of
 * {@see InboxController} under `web_ui.path_prefix` (default `/notifications`) while `web_ui.enabled`, and of
 * {@see OperatorController} under `admin_ui.path_prefix` (default `/admin/notifications`) while `admin_ui.enabled`.
 * The host imports `@NowoNotifyKitBundle/Resources/config/routes.php`; with both UIs disabled (the default) or the
 * bundle disabled the import adds no route. Internal UI endpoints: not versioned (API rule 5).
 */
final class NotifyKitRouteLoader extends Loader
{
    /** Loader type the host import names. */
    public const TYPE = 'nowo_notify_kit';

    /** Lower-case UUIDv7 of a row (`NotificationId`). */
    public const ID_REQUIREMENT = '[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}';

    /**
     * @param bool $webUi `enabled && web_ui.enabled`
     * @param bool $adminUi `enabled && admin_ui.enabled`
     */
    public function __construct(
        private readonly bool $webUi = false,
        private readonly string $webPrefix = '/notifications',
        private readonly bool $adminUi = false,
        private readonly string $adminPrefix = '/admin/notifications',
        ?string $env = null,
    ) {
        parent::__construct($env);
    }

    /**
     * The routes of the enabled UIs.
     */
    public function load(mixed $resource, ?string $type = null): RouteCollection
    {
        $routes = new RouteCollection();
        foreach ([InboxController::class => $this->webUi ? $this->webPrefix : null, OperatorController::class => $this->adminUi ? $this->adminPrefix : null] as $controller => $prefix) {
            if ($prefix === null) {
                continue;
            }
            $loaded = (new ControllerAttributeRouteLoader($this->env))->load($controller);
            foreach ($loaded->all() as $name => $route) {
                // The attribute path `/` is the prefix itself (no trailing slash).
                $routes->add($name, $route->setPath($route->getPath() === '/' ? $prefix : $prefix . $route->getPath()));
            }
            foreach ($loaded->getResources() as $classResource) {
                $routes->addResource($classResource);
            }
        }

        return $routes;
    }

    /**
     * Only the `nowo_notify_kit` type.
     */
    public function supports(mixed $resource, ?string $type = null): bool
    {
        return $type === self::TYPE;
    }
}
