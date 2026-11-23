<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Twig;

use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions of the bundle: `notify_kit_bell(options = {})` renders the header bell of the logged-in person
 * (contract §8.1) into the host's header actions slot.
 */
final class NotifyKitTwigExtension extends AbstractExtension
{
    /**
     * `notify_kit_bell`.
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('notify_kit_bell', [BellRenderer::class, 'render'], ['needs_environment' => true, 'is_safe' => ['html']]),
        ];
    }
}
