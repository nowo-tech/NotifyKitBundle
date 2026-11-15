<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use LogicException;
use Nowo\NotifyKitBundle\Application\Default\PersonalOnlyAccessChecker;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;
use Nowo\NotifyKitBundle\Contract\Urgency;
use Nowo\NotifyKitBundle\Tests\Fixtures\TestTypeProvider;
use PHPUnit\Framework\TestCase;

use function array_keys;

final class TypeRegistryTest extends TestCase
{
    private const TYPE = ['category' => 'account', 'icon' => null, 'title_key' => null, 'permission' => null, 'channels' => [], 'in_app_optional' => false, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true];

    public function testConfigAndProvidersMerge(): void
    {
        $registry = TypeRegistry::create(
            [
                'invitation_accepted' => self::TYPE,
                'quiet.notice'        => ['in_app_optional' => true, 'locked_channels' => ['mail'], 'channels' => ['in_app' => false], 'urgency' => 'high', 'icon' => 'tabler:moon', 'title_key' => 'q.title', 'coalesce' => false, 'in_matrix' => false] + self::TYPE,
            ],
            ['account' => ['label' => 'Account', 'group' => 'general', 'order' => 10], 'billing' => ['label' => 'Billing', 'group' => 'general', 'order' => 1]],
            [new TestTypeProvider()],
            true,
            'project.view',
        );

        $invitation = $registry->type('invitation_accepted');
        self::assertSame(['tabler:bell', 'notify.type.invitation_accepted.title', ['in_app' => true], ['in_app']], [$invitation->icon, $invitation->titleKey, $invitation->defaultChannels, $invitation->lockedChannels]);
        $quiet = $registry->type('quiet.notice');
        self::assertSame(['tabler:moon', 'q.title', ['in_app' => false], ['mail'], Urgency::High, false, false], [$quiet->icon, $quiet->titleKey, $quiet->defaultChannels, $quiet->lockedChannels, $quiet->urgency, $quiet->coalesce, $quiet->inMatrix]);
        self::assertTrue($registry->has('task.assigned'));
        self::assertFalse($registry->has('some.new_type'));
        self::assertSame(['invitation_accepted', 'quiet.notice', 'task.assigned'], array_keys($registry->types()));
        self::assertSame(['general', 'billing', 'account', 'tasks'], array_keys($registry->categories()), 'group, order, key');
        self::assertSame(['in_app'], $registry->channels());

        $generic = $registry->type('some.new_type');
        self::assertSame(['general', 'notify.type.generic.title', 'project.view'], [$generic->category, $generic->titleKey, $generic->permission]);
    }

    public function testATypeWithAPermissionNeedsAChecker(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('type "task.assigned" needs the permission "task.view"');
        TypeRegistry::create([], [], [new TestTypeProvider()], false, null);
    }

    public function testATypeDeclaredTwiceIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('type "task.assigned" is declared twice');
        TypeRegistry::create(['task.assigned' => self::TYPE], [], [new TestTypeProvider()], true, null);
    }

    public function testACategoryDeclaredTwiceIsRefused(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('category "tasks" is declared twice');
        TypeRegistry::create([], ['tasks' => ['label' => 'Tasks', 'group' => 'work', 'order' => 0]], [new TestTypeProvider()], true, null);
    }

    public function testAProviderMayDeclareCategoriesOnly(): void
    {
        $provider = new class implements TypeProviderInterface {
            public function types(): iterable
            {
                return [];
            }

            public function categories(): iterable
            {
                return [new CategoryDefinition('extra', 'Extra')];
            }
        };
        self::assertSame(['extra', 'general'], array_keys(TypeRegistry::create([], [], [$provider], false, null)->categories()));
    }

    public function testThePersonalOnlyCheckerGrantsNothing(): void
    {
        $checker = new PersonalOnlyAccessChecker();
        self::assertFalse($checker->isUnrestricted(new RecipientRef('user', 'admin')));
        self::assertSame([], $checker->contextsGranting(new RecipientRef('user', 'a'), 'project.view', [new ContextRef('project', '1')]));
        self::assertSame('general', TypeRegistry::create([], [], [], false, null)->type('x')->category);
    }
}
