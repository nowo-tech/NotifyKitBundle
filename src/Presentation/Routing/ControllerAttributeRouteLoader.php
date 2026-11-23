<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Routing;

use ReflectionClass;
use ReflectionMethod;
use Symfony\Component\Routing\Loader\AttributeClassLoader;
use Symfony\Component\Routing\Route;

/**
 * REQ-SF-004: reads the `#[Route]` attributes of one bundle controller class for {@see NotifyKitRouteLoader}.
 * `_controller` is `Class::method`, first among the defaults. Owned by that loader (no service, no `routing.loader`
 * tag), so a host that decorates `routing.loader.attribute` cannot change the bundle routes.
 *
 * @internal
 */
final class ControllerAttributeRouteLoader extends AttributeClassLoader
{
    /**
     * Sets `_controller` (first default) of a route read from a controller method.
     *
     * @param ReflectionClass<object> $class
     */
    protected function configureRoute(Route $route, ReflectionClass $class, ReflectionMethod $method, object $attr): void
    {
        $route->setDefaults(['_controller' => $class->getName() . '::' . $method->getName()] + $route->getDefaults());
    }
}
