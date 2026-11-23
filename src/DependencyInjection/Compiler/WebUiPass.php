<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\NotifyKitBundle\Presentation\Controller\InboxController;
use Nowo\NotifyKitBundle\Presentation\Controller\OperatorController;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function implode;
use function sprintf;

/**
 * The pages need their host pieces (REQ-UI-002 rule 4): Twig, forms, CSRF protection, the router and the translator,
 * and SecurityBundle (a logged-in person). The operator page may run without SecurityBundle only with
 * `admin_ui.security.allow_unauthenticated: true` (demos). Refuses to build with a message that names what is missing.
 */
final class WebUiPass implements CompilerPassInterface
{
    /**
     * Checks the services the enabled pages use.
     */
    public function process(ContainerBuilder $container): void
    {
        $inbox    = $container->hasDefinition(InboxController::class);
        $operator = $container->hasDefinition(OperatorController::class);
        if (!$inbox && !$operator) {
            return;
        }
        $missing = [];
        foreach (['twig' => 'TwigBundle', 'form.factory' => 'framework.form', 'security.csrf.token_manager' => 'framework.csrf_protection', 'router' => 'framework.router', 'translator' => 'framework.translator'] as $id => $what) {
            if (!$container->has($id)) {
                $missing[] = $what;
            }
        }
        $config   = $container->getParameter('nowo_notify_kit.config');
        $demoOnly = !$inbox && ($config['admin_ui']['security']['allow_unauthenticated'] ?? false) === true;
        if (!$demoOnly && !$container->has('security.token_storage')) {
            $missing[] = 'SecurityBundle (a firewall with logged-in users)';
        }
        if ($missing !== []) {
            throw new LogicException(sprintf('nowo_notify_kit.web_ui / admin_ui need: %s.', implode(', ', $missing)));
        }
    }
}
