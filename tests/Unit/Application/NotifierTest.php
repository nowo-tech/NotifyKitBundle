<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Application\Default\PersonalOnlyAccessChecker;
use Nowo\NotifyKitBundle\Application\Notifier;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use PHPUnit\Framework\TestCase;

/**
 * Paths of the notifier the functional suites do not reach: no event dispatcher and no clock.
 */
final class NotifierTest extends TestCase
{
    public function testWorksWithoutDispatcherOrClock(): void
    {
        $transactions = $this->transactions();
        $notifier     = new Notifier(new DbalNotificationStore($transactions), $transactions, TypeRegistry::create([], [], [], false, null), new PersonalOnlyAccessChecker());

        $result = $notifier->notify(new NotificationRequest('some.type', [new RecipientRef('user', 'a'), new RecipientRef('user', 'b')], new RecipientRef('user', 'b'), null, 't', '/t', dedupeKey: 'k'));
        self::assertSame([1, 1], [$result->written, $result->skippedActor]);
        self::assertTrue($notifier->notify(new NotificationRequest('some.type', [new RecipientRef('user', 'a')], null, null, 't', '/t', dedupeKey: 'k'))->deduplicated);
        self::assertSame(1, $notifier->notify(new NotificationRequest('some.type', [new RecipientRef('user', 'b')], new RecipientRef('user', 'b'), null, 't', '/t'))->skippedActor);
    }

    private function transactions(): NotifyKitTransactions
    {
        $connection = Sqlite::connection();
        $registry   = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($connection);

        return new NotifyKitTransactions($registry, new AfterCommitQueue());
    }
}
