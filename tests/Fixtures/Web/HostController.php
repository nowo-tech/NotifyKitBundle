<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Symfony\Component\HttpFoundation\Response;
use Twig\Environment;

/**
 * A host page with the header bell (`notify_kit_bell()`).
 */
final readonly class HostController
{
    public function __construct(private Environment $twig)
    {
    }

    public function bell(): Response
    {
        return new Response($this->twig->createTemplate('<header>{{ notify_kit_bell() }}</header>')->render());
    }
}
