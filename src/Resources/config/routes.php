<?php

declare(strict_types=1);

/*
 * Routes of the inbox page, bell endpoints and operator page (contract §7.1). Import them from the host:
 *
 *     # config/routes/nowo_notify_kit.yaml
 *     nowo_notify_kit:
 *         resource: '@NowoNotifyKitBundle/Resources/config/routes.php'
 *
 * The inbox sits under `nowo_notify_kit.web_ui.path_prefix` (default `/notifications`), the operator page under
 * `admin_ui.path_prefix` (default `/admin/notifications`). With `web_ui.enabled` / `admin_ui.enabled` false (the
 * defaults) the import adds no route.
 */

use Nowo\NotifyKitBundle\Presentation\Routing\NotifyKitRouteLoader;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

return static function (RoutingConfigurator $routes): void {
    $routes->import('.', NotifyKitRouteLoader::TYPE);
};
