<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientAccessCheckerInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;

/**
 * Host access checker of the tests: grants are `"<recipient key>|<permission>" => list of context keys`; `admin` ids
 * are unrestricted. Returns an extra, foreign context to prove the bundle ignores what it did not ask for.
 */
final readonly class ProjectAccessChecker implements RecipientAccessCheckerInterface
{
    /**
     * @param array<string, list<string>> $grants
     */
    public function __construct(private array $grants = [])
    {
    }

    public function isUnrestricted(RecipientRef $recipient): bool
    {
        return $recipient->id === 'admin';
    }

    public function contextsGranting(RecipientRef $recipient, string $permission, array $candidates): array
    {
        $granted = [new ContextRef('project', 'foreign')];
        foreach ($candidates as $candidate) {
            foreach ($this->grants[$recipient->key() . '|' . $permission] ?? [] as $key) {
                if ($candidate->key() === $key) {
                    $granted[] = $candidate;
                }
            }
        }

        return $granted;
    }
}
