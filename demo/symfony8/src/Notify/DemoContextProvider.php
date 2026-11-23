<?php

declare(strict_types=1);

namespace App\Notify;

use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * The demo's contexts (S3 per-context preferences): Alice may tune project "demo" (she holds `project.view` there),
 * Bob has a project of his own, "sandbox". Nobody can tune a project the provider does not list for them.
 */
final readonly class DemoContextProvider implements ContextProviderInterface
{
    private const PROJECTS = [
        'user:alice' => ['demo' => 'Demo project'],
        'user:bob'   => ['sandbox' => 'Sandbox'],
    ];

    public function contextsFor(RecipientRef $recipient): array
    {
        $choices = [];
        foreach (self::PROJECTS[$recipient->key()] ?? [] as $id => $label) {
            $choices[] = new ContextChoice(new ContextRef('project', $id), $label, 'Projects');
        }

        return $choices;
    }

    public function label(ContextRef $context): ?string
    {
        foreach (self::PROJECTS as $projects) {
            if ($context->type === 'project' && isset($projects[$context->id])) {
                return $projects[$context->id];
            }
        }

        return null;
    }
}
