<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\ContextProviderInterface;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Host context provider of the tests: `"<recipient key>" => [project id => label]`.
 */
final readonly class ProjectContextProvider implements ContextProviderInterface
{
    /**
     * @param array<string, array<int|string, string>> $projects
     */
    public function __construct(private array $projects = [])
    {
    }

    public function contextsFor(RecipientRef $recipient): array
    {
        $choices = [];
        foreach ($this->projects[$recipient->key()] ?? [] as $id => $label) {
            $choices[] = new ContextChoice(new ContextRef('project', (string) $id), $label, 'Projects');
        }

        return $choices;
    }

    public function label(ContextRef $context): ?string
    {
        foreach ($this->projects as $projects) {
            if ($context->type === 'project' && isset($projects[$context->id])) {
                return $projects[$context->id];
            }
        }

        return null;
    }
}
