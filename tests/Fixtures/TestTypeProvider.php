<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;

/**
 * A tagged type provider (autoconfigured): one project-scoped type and its category.
 */
final readonly class TestTypeProvider implements TypeProviderInterface
{
    public function types(): iterable
    {
        yield new TypeDefinition('task.assigned', 'tasks', 'tabler:checkbox', 'notify.type.task.assigned.title', 'task.view', ['in_app' => true]);
    }

    public function categories(): iterable
    {
        yield new CategoryDefinition('tasks', 'notify.category.tasks', 'work', 5);
    }
}
