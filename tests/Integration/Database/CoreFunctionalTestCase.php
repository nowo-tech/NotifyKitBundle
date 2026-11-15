<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\NotificationRecordedEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationSkippedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Nowo\NotifyKitBundle\Contract\SkipReason;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Tests\Fixtures\Ddd\Member;
use Nowo\NotifyKitBundle\Tests\Fixtures\FlushSpy;
use Nowo\NotifyKitBundle\Tests\Fixtures\RecordingListener;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

use function array_map;
use function sprintf;
use function strlen;

/**
 * The S1 functional suite, run once per host kind (standard and DDD) on the configured database (SQLite locally and
 * in the coverage job; MySQL 8.4 and PostgreSQL 17 on DBAL 3 and 4 in the CI database jobs).
 */
abstract class CoreFunctionalTestCase extends KernelTestCase
{
    private TestKernel $kernel;

    abstract protected function host(): Host;

    /**
     * @param array<string, mixed> $override
     */
    protected function boot(array $override = []): TestKernel
    {
        $host         = $this->host();
        $this->kernel = $this->bootKernel($host->bundleConfig($override), Host::databaseUrl(), $host->configure());
        $host->resetSchema($this->kernel);

        return $this->kernel;
    }

    public function testOneRowPerRecipientNeverForTheActorAndEventsAfterTheCommit(): void
    {
        $this->boot();
        $a = $this->r('a');
        $b = $this->r('b');

        $result = $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$b, $a, $b, $this->r('actor')], $this->r('actor'), null, 'invite:1', '/account/invitations', ['name' => 'Team']));

        self::assertSame(2, $result->written);
        self::assertSame(0, $result->coalesced);
        self::assertSame(1, $result->skippedActor);
        self::assertCount(2, $result->notificationIds);
        self::assertSame(['a', 'b'], $this->column('SELECT recipient_id FROM %s ORDER BY recipient_id'));
        $row = $this->rows('SELECT * FROM %s WHERE recipient_id = ?', ['a'])[0];
        self::assertSame($this->host()->recipientType(), $row['recipient_type']);
        self::assertSame('account', $row['category']);
        self::assertSame('actor', $row['actor_id']);
        self::assertNull($row['context_type']);
        self::assertSame('/account/invitations', $row['url']);
        self::assertSame(1, (int) $row['repeats']);
        self::assertNull($row['read_at']);

        $recorded = $this->listener()->of(NotificationRecordedEvent::class);
        self::assertCount(2, $recorded);
        self::assertSame('a', $recorded[0]->recipient->id, 'recipients are written in key order');
        self::assertFalse($recorded[0]->coalesced);
        $skipped = $this->listener()->of(NotificationSkippedEvent::class);
        self::assertCount(1, $skipped);
        self::assertSame(SkipReason::Actor, $skipped[0]->reason);
        self::assertSame(0, $this->flushSpy()->flushes, 'the bundle never flushes an entity manager');

        $store = $this->store();
        self::assertSame(1, $store->countUnread($a));
        $mine = $store->ofRecipient($a, 10);
        self::assertCount(1, $mine);
        self::assertSame(['name' => 'Team'], $mine[0]->params());
        self::assertSame('invite:1', $mine[0]->target());
        self::assertSame('2026-11-15 10:00:00', $mine[0]->createdAt()->format('Y-m-d H:i:s'));
        self::assertSame('actor', $mine[0]->actor()?->id);
    }

    public function testARepeatWithinTheWindowUpdatesTheUnreadRow(): void
    {
        $this->boot();
        $a     = $this->r('a');
        $first = $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], $this->r('x'), null, 'invite:1', '/one', ['n' => '1']));
        $this->clock()->sleep(300);
        $second = $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], $this->r('y'), null, 'invite:1', '/two', ['n' => '2']));

        self::assertSame(1, $second->coalesced);
        self::assertSame($first->notificationIds[0]->value, $second->notificationIds[0]->value);
        $row = $this->rows('SELECT * FROM %s')[0];
        self::assertSame(2, (int) $row['repeats']);
        self::assertSame('y', $row['actor_id']);
        self::assertSame('/two', $row['url']);
        self::assertStringStartsWith('2026-11-15 10:05:00', (string) $row['created_at']);
        self::assertStringStartsWith('2026-11-15 10:00:00', (string) $row['first_created_at']);
        self::assertTrue($this->listener()->of(NotificationRecordedEvent::class)[1]->coalesced);

        // After the window: a new row.
        $this->clock()->sleep(601);
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], null, null, 'invite:1', '/three'))->written);

        // A read row is never revived.
        $this->connection()->executeStatement(sprintf('UPDATE %s SET read_at = created_at', $this->table()));
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], null, null, 'invite:1', '/four'))->written);
        self::assertSame(3, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())));

        // A type that does not coalesce always adds a row.
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$a], null, null, 'export:1', '/billing'));
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('billing.ready', [$a], null, null, 'export:1', '/billing'))->written);
    }

    public function testRowsWithAContextNeedTheTypePermissionThere(): void
    {
        $this->boot();
        $project = new ContextRef('project', '42');
        if ($this->host()->kind === Host::STANDARD) {
            // No access checker: personal rows only (Q-O6), whatever the type.
            $result = $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, $project, 'issue:1', '/p/42'));
            self::assertSame(1, $result->skippedAccess);
            self::assertSame(1, $this->notifier()->notify(new NotificationRequest('some.new_type', [$this->r('a')], null, null, 'x:1', '/x'))->written);
            self::assertSame(1, $this->notifier()->notify(new NotificationRequest('some.new_type', [$this->r('b')], null, $project, 'x:1', '/x'))->skippedAccess);
            self::assertSame(SkipReason::Access, $this->listener()->of(NotificationSkippedEvent::class)[0]->reason);

            return;
        }

        $result = $this->notifier()->notify(new NotificationRequest('issue.assigned', [$this->r('m-1'), $this->r('m-2'), $this->r('admin'), $this->r('m-3')], null, $project, 'issue:1', '/p/42/issues/1'));
        self::assertSame(2, $result->written, 'm-1 holds issue.view in project 42; admin is unrestricted');
        self::assertSame(2, $result->skippedAccess, 'm-2 has no grant there; m-3 only gets a context it did not ask for');
        self::assertSame(['admin', 'm-1'], $this->column('SELECT recipient_id FROM %s ORDER BY recipient_id'));
        self::assertSame(['project'], $this->column('SELECT DISTINCT context_type FROM %s'));

        // An open-set type falls back to access.default_permission (project.view).
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('some.new_type', [$this->r('m-1'), $this->r('m-2')], null, $project, 'x:1', '/x'))->written);
        // A type from a tagged provider (task.view in project 7).
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('task.assigned', [$this->r('m-2')], null, new ContextRef('project', '7'), 'task:1', '/t/1'))->written);
        // A registered personal type never goes into a context.
        self::assertSame(1, $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('m-1')], null, $project, 'i:1', '/i'))->skippedAccess);
        self::assertTrue($this->types()->has('task.assigned'));
        self::assertSame('work', $this->types()->categories()['tasks']->group);
    }

    public function testADedupeKeyDropsTheSameRequestFor24Hours(): void
    {
        $this->boot();
        $request = new NotificationRequest('invitation_accepted', [$this->r('a'), $this->r('b')], null, null, 'token:1', '/tokens', dedupeKey: 'token-expiring:1');

        self::assertSame(2, $this->notifier()->notify($request)->written);
        $again = $this->notifier()->notify($request);
        self::assertTrue($again->deduplicated);
        self::assertSame(0, $again->written);
        self::assertSame(2, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())));
        $dedupe = $this->listener()->of(NotificationSkippedEvent::class);
        self::assertCount(2, $dedupe);
        self::assertSame(SkipReason::Dedupe, $dedupe[0]->reason);
        $hash = (string) $this->connection()->fetchOne(sprintf('SELECT key_hash FROM %snotify_dedupe', $this->host()->tablePrefix()));
        self::assertSame(64, strlen($hash), 'the key is stored as a hash only');

        $this->clock()->sleep(86401);
        self::assertSame(2, $this->notifier()->notify(new NotificationRequest('billing.ready', [$this->r('a'), $this->r('b')], null, null, 'token:1', '/tokens', dedupeKey: 'token-expiring:1'))->written);
    }

    public function testInsideAHostTransactionTheRowsJoinItAndTheEventsWaitForTheCommit(): void
    {
        $this->boot();
        $connection = $this->connection();

        $connection->beginTransaction();
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'invite:1', '/a'));
        self::assertSame([], $this->listener()->events, 'nothing is announced before the commit');
        self::assertSame(1, $this->queue()->heldCount());
        $connection->rollBack();
        self::assertSame(0, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())), 'a rolled-back request writes nothing');
        self::assertSame([], $this->listener()->events, 'and announces nothing');
        self::assertSame(0, $this->queue()->heldCount());

        $connection->beginTransaction();
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'invite:1', '/a'));
        $connection->beginTransaction();
        $connection->commit();
        self::assertSame([], $this->listener()->events, 'a nested commit is not the outermost one');
        $connection->commit();
        self::assertCount(1, $this->listener()->of(NotificationRecordedEvent::class));
        self::assertSame(1, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())));
        self::assertSame(0, $this->flushSpy()->flushes);
    }

    public function testRecipientIdsCompareExactly(): void
    {
        $this->boot();
        $upper = $this->r('UserA');
        $lower = $this->r('usera');
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$upper], null, null, 'invite:1', '/a'));
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$lower], null, null, 'invite:1', '/a'));
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$lower], null, null, 'invite:2', '/a'));

        self::assertSame(1, $this->store()->countUnread($upper));
        self::assertSame(2, $this->store()->countUnread($lower));
        self::assertSame(0, $this->store()->countUnread($this->r('USERA')));
        self::assertSame(['UserA'], array_map(static fn (Notification $n): string => $n->recipient()->id, $this->store()->ofRecipient($upper, 10)));
        self::assertSame(1, $this->store()->trimRecipient($lower, 1, 10));
        self::assertSame(1, $this->store()->countUnread($upper), 'trimming usera never touches UserA');
    }

    public function testForgetContextDeletesTheContextRowsInBatches(): void
    {
        $this->boot(['retention' => ['batch_size' => 2]]);
        if ($this->host()->kind === Host::STANDARD) {
            self::assertSame(0, $this->inbox()->forgetContext(new ContextRef('project', '42')));

            return;
        }
        $project = new ContextRef('project', '42');
        foreach (['1', '2', '3'] as $n) {
            $this->notifier()->notify(new NotificationRequest('issue.assigned', [$this->r('m-1')], null, $project, 'issue:' . $n, '/i'));
        }
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('m-1')], null, null, 'invite:1', '/i'));

        self::assertSame(3, $this->inbox()->forgetContext($project));
        self::assertSame(1, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())));
    }

    public function testRetentionCommandDeletesOldReadRowsTrimsAndForgetsExpiredKeys(): void
    {
        $this->boot(['retention' => ['keep_per_recipient' => 3, 'batch_size' => 2]]);
        $a = $this->r('a');
        $b = $this->r('b');
        for ($i = 1; $i <= 5; ++$i) {
            $this->notifier()->notify(new NotificationRequest('billing.ready', [$a, $b], null, null, 'n:' . $i, '/n'));
            $this->clock()->sleep(60);
        }
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$this->r('c')], null, null, 'old', '/n', dedupeKey: 'old'));
        $this->clock()->sleep(100 * 86400);
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$this->r('c')], null, null, 'new', '/n', dedupeKey: 'new'));
        // a: the two oldest rows read; one more old but unread. b keeps its five rows unread.
        $this->connection()->executeStatement(sprintf("UPDATE %s SET read_at = created_at WHERE recipient_id = 'a' AND target IN ('n:1', 'n:2')", $this->table()));

        $tester = new CommandTester((new Application($this->kernel))->find('nowo:notify-kit:prune'));
        self::assertSame(0, $tester->execute([]));
        self::assertStringContainsString('Deleted 2 read notification(s), 2 beyond the per-recipient cap and 1 expired dedupe key(s).', $tester->getDisplay());
        self::assertSame(['n:3', 'n:4', 'n:5'], $this->column("SELECT target FROM %s WHERE recipient_id = 'a' ORDER BY target"));
        self::assertSame(['n:3', 'n:4', 'n:5'], $this->column("SELECT target FROM %s WHERE recipient_id = 'b' ORDER BY target"));
        self::assertSame(1, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %snotify_dedupe', $this->host()->tablePrefix())));
    }

    public function testTheSchemaCheckComparesTheLiveTablesWithTheMapping(): void
    {
        $this->boot();
        $application = new Application($this->kernel);
        $tester      = new CommandTester($application->find('nowo:notify-kit:schema-check'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString($this->table(), $tester->getDisplay());

        $this->connection()->executeStatement(sprintf('DROP TABLE %snotify_dedupe', $this->host()->tablePrefix()));
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('CREATE TABLE ' . $this->host()->tablePrefix() . 'notify_dedupe', $tester->getDisplay());
    }

    public function testTheRecipientResolverMapsHostUsers(): void
    {
        $kernel   = $this->boot();
        $resolver = $kernel->getContainer()->get(RecipientResolverInterface::class);
        self::assertInstanceOf(RecipientResolverInterface::class, $resolver);
        $user = $this->host()->createUser($kernel, 'u-1');
        $this->host()->entityManager($kernel)->clear();

        $ref = $resolver->recipientOf($user);
        self::assertTrue($ref->equals($this->r('u-1')));
        $found = $resolver->userOf($ref);
        self::assertInstanceOf($this->host()->kind === Host::STANDARD ? User::class : Member::class, $found);
        self::assertNull($resolver->userOf(new RecipientRef('robot', 'u-1')));
        if ($this->host()->kind === Host::STANDARD) {
            self::assertNull($resolver->userOf($this->r('nobody')));
            self::assertNull($resolver->userOf($this->r('U-1')), 'ids compare exactly');
        }

        $this->expectException(InvalidNotificationArgumentException::class);
        $resolver->recipientOf(new stdClass());
    }

    public function testTooManyRecipientsAreRefused(): void
    {
        $this->boot(['fan_out' => ['max_recipients' => 2]]);

        $this->expectException(InvalidNotificationArgumentException::class);
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a'), $this->r('b'), $this->r('c')], null, null, 'i', '/i'));
    }

    public function testATypeWhoseBellIsOffWritesNothing(): void
    {
        $this->boot(['types' => ['quiet.notice' => ['category' => 'account', 'in_app_optional' => true, 'channels' => ['in_app' => false]]]]);

        $result = $this->notifier()->notify(new NotificationRequest('quiet.notice', [$this->r('a')], null, null, 'q', '/q'));
        self::assertSame(0, $result->written);
        self::assertSame(SkipReason::Preference, $this->listener()->of(NotificationSkippedEvent::class)[0]->reason);
    }

    protected function r(string $id): RecipientRef
    {
        return $this->host()->recipient($id);
    }

    protected function table(): string
    {
        return $this->host()->tablePrefix() . 'notify_notification';
    }

    protected function connection(): Connection
    {
        return $this->host()->entityManager($this->kernel)->getConnection();
    }

    /**
     * @param list<mixed> $params
     *
     * @return list<array<string, mixed>>
     */
    protected function rows(string $sql, array $params = []): array
    {
        return $this->connection()->fetchAllAssociative(sprintf($sql, $this->table()), $params);
    }

    /**
     * @return list<mixed>
     */
    protected function column(string $sql): array
    {
        return $this->connection()->fetchFirstColumn(sprintf($sql, $this->table()));
    }

    protected function notifier(): NotifierInterface
    {
        return $this->service(NotifierInterface::class, NotifierInterface::class);
    }

    protected function inbox(): InboxInterface
    {
        return $this->service(InboxInterface::class, InboxInterface::class);
    }

    protected function types(): TypeRegistryInterface
    {
        return $this->service(TypeRegistryInterface::class, TypeRegistryInterface::class);
    }

    protected function store(): NotificationStoreInterface
    {
        return $this->privateService(NotificationStoreInterface::class, NotificationStoreInterface::class);
    }

    protected function queue(): AfterCommitQueue
    {
        return $this->privateService(AfterCommitQueue::class, AfterCommitQueue::class);
    }

    protected function listener(): RecordingListener
    {
        return $this->service(RecordingListener::class, RecordingListener::class);
    }

    protected function flushSpy(): FlushSpy
    {
        return $this->service(FlushSpy::class, FlushSpy::class);
    }

    protected function clock(): MockClock
    {
        return $this->service('clock', MockClock::class);
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function service(string $id, string $class): object
    {
        $service = $this->kernel->getContainer()->get($id);
        self::assertInstanceOf($class, $service);

        return $service;
    }

    /**
     * @template T of object
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    private function privateService(string $id, string $class): object
    {
        $container = $this->kernel->getContainer()->get('test.service_container');
        self::assertInstanceOf(ContainerInterface::class, $container);
        $service = $container->get($id);
        if (!$service instanceof $class) {
            throw new LogicException(sprintf('%s is not a %s.', $id, $class));
        }

        return $service;
    }
}
