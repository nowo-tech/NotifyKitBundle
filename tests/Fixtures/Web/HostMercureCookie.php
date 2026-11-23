<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\Mercure\Authorization;

/**
 * A host that grants its own Mercure topic (`host/wiki`) in the same request as the bell, before the page renders
 * (audit ZAJ-1: on symfony/mercure < 0.10 the bell's second `setCookie()` threw).
 */
final readonly class HostMercureCookie
{
    public function __construct(private Authorization $authorization)
    {
    }

    public function __invoke(RequestEvent $event): void
    {
        if ($event->isMainRequest()) {
            $this->authorization->setCookie($event->getRequest(), ['host/wiki']);
        }
    }
}
