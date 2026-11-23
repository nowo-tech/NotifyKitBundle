<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Composer\InstalledVersions;
use Doctrine\DBAL\Connection;
use LogicException;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\NotificationReadEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationRecordedEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationSkippedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\InboxFilter;
use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\InboxStatus;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotificationView;
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
use Nowo\NotifyKitBundle\Tests\Fixtures\RecordingPublisher;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use stdClass;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Throwable;

use function array_map;
use function sprintf;
use function str_starts_with;
use function strlen;

/**
 * The S1 functional suite, run once per host kind (standard and DDD) on the configured database (SQLite locally and
 * in the coverage job; MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 on DBAL 3 and 4 in the CI database jobs).
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
        self::assertSame([], $this->listener()->events, 'never inside the driver commit (audit QL-M1)');
        self::assertSame(1, $this->queue()->readyCount());
        $this->queue()->reset(); // the request boundary
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
        self::assertSame(1, $this->store()->trimRecipient($lower->type, $lower->id, 1, 10));
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
        // In its own category: a category has one default per channel (audit ZAL-3), and `account` has the bell on.
        $this->boot(['types' => ['quiet.notice' => ['category' => 'quiet', 'in_app_optional' => true, 'channels' => ['in_app' => false]]]]);

        $result = $this->notifier()->notify(new NotificationRequest('quiet.notice', [$this->r('a')], null, null, 'q', '/q'));
        self::assertSame(0, $result->written);
        self::assertSame(SkipReason::Preference, $this->listener()->of(NotificationSkippedEvent::class)[0]->reason);
    }

    public function testARolledBackSavepointAnnouncesNothing(): void
    {
        $this->boot();
        $connection = $this->connection();
        if (str_starts_with((string) InstalledVersions::getVersion('doctrine/dbal'), '3.')) {
            // DBAL 3 emulates nesting by default (a nested rollback marks the whole transaction rollback-only).
            $connection->setNestTransactionsWithSavepoints(true);
        }

        $connection->beginTransaction();
        $connection->beginTransaction(); // the host's savepoint
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'invite:1', '/a'));
        $connection->rollBack();         // the host undoes the nested part
        $connection->beginTransaction(); // a new savepoint at the same level, released
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('b')], null, null, 'invite:1', '/b'));
        $connection->commit();
        $connection->commit();
        $this->queue()->reset();

        self::assertSame(['b'], $this->column('SELECT recipient_id FROM %s'));
        $recorded = $this->listener()->of(NotificationRecordedEvent::class);
        self::assertCount(1, $recorded, 'no event for the row of the rolled-back savepoint (audit QL-M2)');
        self::assertSame('b', $recorded[0]->recipient->id);
    }

    public function testAListenerMayOpenTransactionsAndNotifyAfterAJoinedCommit(): void
    {
        $kernel     = $this->boot();
        $connection = $this->connection();
        $connection->executeStatement('DROP TABLE IF EXISTS ql_side');
        $connection->executeStatement('CREATE TABLE ql_side (n INT NOT NULL)');
        $dispatcher = $kernel->getContainer()->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $levels = [];
        $errors = [];
        $host   = $this->host();
        $dispatcher->addListener(NotificationRecordedEvent::class, function (NotificationRecordedEvent $event) use ($connection, $kernel, $host, &$levels, &$errors): void {
            $levels[] = $connection->getTransactionNestingLevel();
            try {
                if ($event->recipient->id === 'a') {
                    // A listener that changes its mind, then a host flush and a cascade notify().
                    $connection->beginTransaction();
                    $connection->executeStatement('INSERT INTO ql_side (n) VALUES (1)');
                    $connection->rollBack();
                    $host->createUser($kernel, 'from-listener');
                    $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('c')], null, null, 'cascade:1', '/c'));
                }
            } catch (Throwable $failure) {
                $errors[] = $failure::class . ': ' . $failure->getMessage();
            }
        });

        $connection->beginTransaction();
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'invite:1', '/a'));
        $connection->commit();
        $this->queue()->reset();

        self::assertSame([], $errors);
        self::assertSame([0, 0], $levels, 'listeners run with the connection at level 0 (audit QL-M1)');
        self::assertSame(0, $connection->getTransactionNestingLevel());
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM ql_side'), 'the listener rollback holds');
        self::assertSame(['a', 'c'], $this->column('SELECT recipient_id FROM %s ORDER BY recipient_id'));
        self::assertNotNull($this->host()->entityManager($kernel)->find($this->host()->kind === Host::STANDARD ? User::class : Member::class, 'from-listener'));
        self::assertSame(0, $this->queue()->readyCount());
        $connection->executeStatement('DROP TABLE ql_side');
    }

    public function testReadyEventsRunAtTheNextUnitOfWork(): void
    {
        $this->boot();
        $connection = $this->connection();
        $connection->beginTransaction();
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'invite:1', '/a'));
        $connection->commit();
        self::assertSame([], $this->listener()->events);

        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('b')], null, null, 'invite:1', '/b'));
        $recorded = $this->listener()->of(NotificationRecordedEvent::class);
        self::assertSame(['a', 'b'], array_map(static fn (NotificationRecordedEvent $e): string => $e->recipient->id, $recorded), 'oldest first');
    }

    public function testLookAlikeIdsNeverStandInForEachOther(): void
    {
        $this->boot(['retention' => ['batch_size' => 2, 'keep_per_recipient' => 2]]);
        $connection = $this->connection();
        $insert     = function (string $id, string $recipient, ?string $context, string $at) use ($connection): void {
            $connection->insert($this->table(), ['id' => $id, 'recipient_type' => $this->host()->recipientType(), 'recipient_id' => $recipient, 'type' => 't', 'category' => 'general', 'context_type' => $context === null ? null : 'project', 'context_id' => $context, 'target' => 'x', 'url' => '/', 'params' => '{}', 'repeats' => 1, 'first_created_at' => $at, 'created_at' => $at]);
        };
        // Look-alike context rows sort first by id; a full batch of them must not stop the walk (audit QL-M3).
        $insert('00000000-0000-7000-8000-000000000001', 'u', '42 ', '2026-11-15 09:00:00');
        $insert('00000000-0000-7000-8000-000000000002', 'u', '42 ', '2026-11-15 09:00:00');
        $insert('00000000-0000-7000-8000-000000000003', 'u', '42', '2026-11-15 09:00:00');
        $insert('00000000-0000-7000-8000-000000000004', 'u', '42', '2026-11-15 09:00:00');
        self::assertSame(2, $this->inbox()->forgetContext(new ContextRef('project', '42')));
        self::assertSame(['42 ', '42 '], $this->column('SELECT context_id FROM %s ORDER BY id'));
        $connection->executeStatement(sprintf('DELETE FROM %s', $this->table()));

        // alice has 2 rows (the cap), 'alice ' and 'Alice' 2 newer each: nobody is over the cap.
        $insert('00000000-0000-7000-8000-000000000011', 'alice', null, '2026-11-15 09:01:00');
        $insert('00000000-0000-7000-8000-000000000012', 'alice', null, '2026-11-15 09:02:00');
        $insert('00000000-0000-7000-8000-000000000013', 'alice ', null, '2026-11-15 09:03:00');
        $insert('00000000-0000-7000-8000-000000000014', 'alice ', null, '2026-11-15 09:04:00');
        $insert('00000000-0000-7000-8000-000000000015', 'Alice', null, '2026-11-15 09:05:00');
        $insert('00000000-0000-7000-8000-000000000016', 'Alice', null, '2026-11-15 09:06:00');
        self::assertSame([], $this->store()->recipientsOver(2, null, 10));
        self::assertSame(0, $this->store()->trimRecipient($this->host()->recipientType(), 'alice', 2, 10));
        self::assertSame(2, $this->store()->countUnread($this->r('alice')));
        $tester = new CommandTester((new Application($this->kernel))->find('nowo:notify-kit:prune'));
        self::assertSame(0, $tester->execute([]));
        self::assertSame(6, (int) $connection->fetchOne(sprintf('SELECT COUNT(*) FROM %s', $this->table())), 'retention deletes nobody');

        // A coalescing repeat of alice never updates a look-alike's row.
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('alice')], null, null, 'x', '/a'));
        self::assertSame(3, $this->store()->countUnread($this->r('alice')));
    }

    public function testThePageListsTheRecipientsRowsNewestFirstWithKeysetAndFilters(): void
    {
        $this->boot();
        $a = $this->r('a');
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], $this->r('x'), null, 'invite:1', '/one', ['name' => 'Team']));
        $this->clock()->sleep(5);
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$a, $this->r('b')], null, null, 'export:1', '/billing'));
        $this->clock()->sleep(5);
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$a], null, null, 'invite:2', '/two'));

        $first = $this->inbox()->page($a, InboxFilter::all(), null, 2);
        self::assertSame(['/two', '/billing'], array_map(static fn (NotificationView $view): string => $view->url, $first->items));
        self::assertNotNull($first->next);
        self::assertFalse($first->items[0]->isRead());
        self::assertTrue($first->items[0]->recipient->equals($a));
        $second = $this->inbox()->page($a, InboxFilter::all(), $first->next, 2);
        self::assertSame(['/one'], array_map(static fn (NotificationView $view): string => $view->url, $second->items));
        self::assertNull($second->next);
        self::assertSame(['name' => 'Team'], $second->items[0]->params);
        self::assertSame('x', $second->items[0]->actor?->id);
        self::assertCount(3, $this->inbox()->page($a, InboxFilter::all(), 'not-a-cursor', 10)->items, 'an invalid cursor is the first page');
        self::assertCount(3, $this->inbox()->page($a, InboxFilter::all(), null, 1000)->items, 'the limit is clamped');

        self::assertCount(1, $this->inbox()->page($a, new InboxFilter('billing.ready'), null, 10)->items);
        self::assertCount(3, $this->inbox()->page($a, new InboxFilter(null, 'account'), null, 10)->items);
        self::assertCount(0, $this->inbox()->page($a, new InboxFilter(null, 'other'), null, 10)->items);
        $this->inbox()->markRead($a, $first->items[1]->id);
        self::assertSame(['/two', '/one'], array_map(static fn (NotificationView $view): string => $view->url, $this->inbox()->page($a, new InboxFilter(null, null, InboxStatus::Unread), null, 10)->items));
        $read = $this->inbox()->page($a, new InboxFilter(null, null, InboxStatus::Read), null, 10)->items;
        self::assertSame(['/billing'], array_map(static fn (NotificationView $view): string => $view->url, $read));
        self::assertTrue($read[0]->isRead());
        self::assertSame(['/billing'], array_map(static fn (NotificationView $view): string => $view->url, $this->inbox()->page($this->r('b'), InboxFilter::all(), null, 10)->items), 'b sees only its own row');
        self::assertSame(2, $this->inbox()->unreadCount($a));
    }

    public function testRowOperationsOnlyTouchTheRecipientsRowsAndAnnounceAfterTheCommit(): void
    {
        $this->boot(['retention' => ['batch_size' => 2], 'realtime' => ['publisher' => RecordingPublisher::class]]);
        $a   = $this->r('a');
        $b   = $this->r('b');
        $ids = $this->notifier()->notify(new NotificationRequest('billing.ready', [$a, $b], null, null, 'export:1', '/billing'))->notificationIds;
        self::assertSame([[$a->key(), 1], [$b->key(), 1]], $this->publisher()->published, 'notify() publishes each written recipient\'s count');
        $this->publisher()->published = [];
        $this->listener()->clear();
        [$mine, $theirs] = $ids;

        self::assertFalse($this->inbox()->markRead($b, $mine), 'a row of another recipient does not exist for b');
        self::assertFalse($this->inbox()->markRead($a, new NotificationId('019a0000-0000-7000-8000-000000000001')));
        self::assertTrue($this->inbox()->markRead($a, $mine));
        self::assertTrue($this->inbox()->markRead($a, $mine), 'idempotent');
        $events = $this->listener()->of(NotificationReadEvent::class);
        self::assertCount(1, $events, 'only a change is announced');
        self::assertSame($mine->value, $events[0]->ids[0]->value);
        self::assertTrue($events[0]->read);
        self::assertFalse($events[0]->all);
        self::assertSame([[$a->key(), 0]], $this->publisher()->published);
        self::assertSame(0, $this->inbox()->unreadCount($a));
        self::assertSame(1, $this->inbox()->unreadCount($b), 'b is untouched');

        self::assertTrue($this->inbox()->markUnread($a, $mine));
        self::assertFalse($this->listener()->of(NotificationReadEvent::class)[1]->read);
        self::assertFalse($this->inbox()->markUnread($b, $mine));
        self::assertSame(1, $this->inbox()->unreadCount($a));

        foreach (['2', '3', '4', '5'] as $n) {
            $this->notifier()->notify(new NotificationRequest('billing.ready', [$a], null, null, 'export:' . $n, '/billing'));
        }
        $this->listener()->clear();
        $this->publisher()->published = [];
        self::assertSame(5, $this->inbox()->markAllRead($a), 'three batches of two');
        $events = $this->listener()->of(NotificationReadEvent::class);
        self::assertCount(1, $events, 'one announcement after the last batch');
        self::assertTrue($events[0]->all);
        self::assertSame([], $events[0]->ids);
        self::assertSame([[$a->key(), 0]], $this->publisher()->published);
        self::assertSame(0, $this->inbox()->markAllRead($a));
        self::assertCount(1, $this->listener()->of(NotificationReadEvent::class), 'nothing changed: no event');
        self::assertSame(1, $this->inbox()->unreadCount($b));
        self::assertTrue($this->inbox()->markRead($b, $theirs));

        $this->publisher()->fail = true;
        self::assertTrue($this->inbox()->markUnread($b, $theirs), 'a failing publisher never fails the call');
        self::assertSame(1, $this->inbox()->unreadCount($b));

        $this->publisher()->attempts = 0;
        $ids                         = $this->notifier()->notify(new NotificationRequest('billing.ready', [$a, $b, $this->r('c')], null, null, 'export:9', '/billing'))->notificationIds;
        self::assertCount(3, $ids, 'every row is written');
        self::assertSame(1, $this->publisher()->attempts, 'the first hub failure stops the badges of the rest of the call (audit ZAJ-3)');
        $this->publisher()->fail = false;
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$a, $b], null, null, 'export:10', '/billing'));
        self::assertSame(3, $this->publisher()->attempts, 'the next call publishes again');
    }

    public function testRowOperationsInsideARolledBackHostTransactionAnnounceNothing(): void
    {
        $this->boot();
        $a  = $this->r('a');
        $id = $this->notifier()->notify(new NotificationRequest('billing.ready', [$a], null, null, 'export:1', '/billing'))->notificationIds[0];
        $this->listener()->clear();
        $connection = $this->connection();
        $connection->beginTransaction();
        self::assertTrue($this->inbox()->markRead($a, $id));
        self::assertSame([], $this->listener()->events);
        $connection->rollBack();
        self::assertSame([], $this->listener()->events, 'a rolled-back change is never announced');
        self::assertSame(1, $this->inbox()->unreadCount($a));
    }

    public function testVisibilityIsCheckedAgainAtReadTime(): void
    {
        $this->boot();
        $project = new ContextRef('project', '42');
        if ($this->host()->kind === Host::STANDARD) {
            // Personal rows only: a context row written behind the bundle's back stays hidden.
            $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'i:1', '/i'));
            $this->insertRow('a', 'invitation_accepted', $project);
            self::assertSame(1, $this->inbox()->unreadCount($this->r('a')));
            self::assertCount(1, $this->inbox()->page($this->r('a'), InboxFilter::all(), null, 10)->items);
            self::assertFalse($this->inbox()->markRead($this->r('a'), new NotificationId($this->lastInsertedId)));
            self::assertSame(1, $this->inbox()->markAllRead($this->r('a')));
            self::assertSame(1, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE read_at IS NULL', $this->table())), 'the hidden row stays unread');

            return;
        }

        $m1 = $this->r('m-1');
        $this->notifier()->notify(new NotificationRequest('issue.assigned', [$m1], null, $project, 'issue:1', '/p/42/issues/1'));
        $this->notifier()->notify(new NotificationRequest('some.new_type', [$m1], null, $project, 'x:1', '/x'));
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$m1], null, null, 'i:1', '/i'));
        self::assertSame(3, $this->inbox()->unreadCount($m1));

        // Written before m-1 lost access: issue.view is not granted in project 7 any more.
        $this->insertRow('m-1', 'issue.assigned', new ContextRef('project', '7'));
        $hidden = new NotificationId($this->lastInsertedId);
        // A personal type in a context is never visible; an unknown type needs the default permission there.
        $this->insertRow('m-1', 'invitation_accepted', $project);
        $this->insertRow('m-1', 'other.type', new ContextRef('board', '9'));
        self::assertSame(3, $this->inbox()->unreadCount($m1), 'hidden rows do not count');
        self::assertSame(['/i', '/x', '/p/42/issues/1'], array_map(static fn (NotificationView $view): string => $view->url, $this->inbox()->page($m1, InboxFilter::all(), null, 10)->items));
        self::assertFalse($this->inbox()->markRead($m1, $hidden));
        self::assertSame(3, $this->inbox()->markAllRead($m1));
        self::assertSame(3, (int) $this->connection()->fetchOne(sprintf('SELECT COUNT(*) FROM %s WHERE read_at IS NULL', $this->table())), 'hidden rows stay unread');

        // An unrestricted recipient sees every context.
        $this->insertRow('admin', 'issue.assigned', new ContextRef('project', '7'));
        $this->insertRow('admin', 'other.type', new ContextRef('board', '9'));
        self::assertSame(2, $this->inbox()->unreadCount($this->r('admin')));
        self::assertTrue($this->inbox()->markRead($this->r('admin'), new NotificationId($this->lastInsertedId)));
    }

    public function testLookAlikeRecipientsNeverCross(): void
    {
        $this->boot();
        $lower = $this->r('usera');
        $upper = $this->r('UserA');
        $id    = $this->notifier()->notify(new NotificationRequest('billing.ready', [$upper], null, null, 'export:1', '/billing'))->notificationIds[0];
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$lower], null, null, 'export:1', '/billing'));

        self::assertSame(1, $this->inbox()->unreadCount($lower));
        self::assertCount(1, $this->inbox()->page($lower, InboxFilter::all(), null, 10)->items);
        self::assertFalse($this->inbox()->markRead($lower, $id));
        self::assertSame(1, $this->inbox()->markAllRead($lower));
        self::assertSame(1, $this->inbox()->unreadCount($upper), 'UserA is untouched');
        self::assertSame([], $this->store()->contextTypes($upper));
    }

    public function testStatisticsCountWithoutContent(): void
    {
        $this->boot();
        $this->notifier()->notify(new NotificationRequest('billing.ready', [$this->r('a'), $this->r('b')], null, null, 'export:1', '/billing', dedupeKey: 'k1'));
        $this->notifier()->notify(new NotificationRequest('invitation_accepted', [$this->r('a')], null, null, 'i:1', '/i'));
        $this->inbox()->markAllRead($this->r('b'));
        $now   = $this->clock()->now();
        $stats = $this->store()->statistics($now->modify('-7 days'), $now, 10);
        self::assertSame(3, $stats->rows);
        self::assertSame(2, $stats->unread);
        self::assertSame(1, $stats->dedupeMarkers);
        self::assertSame(['billing.ready' => 2, 'invitation_accepted' => 1], $stats->writtenByType);
        self::assertSame(['billing.ready' => 2], $this->store()->statistics($now->modify('-7 days'), $now, 1)->writtenByType);
        self::assertSame([], $this->store()->statistics($now->modify('+1 day'), $now->modify('+2 days'), 10)->writtenByType);
    }

    public function testTheDoctrineResolverKeepsAManagedUserUntouched(): void
    {
        $kernel = $this->boot();
        if ($this->host()->kind !== Host::STANDARD) {
            self::assertNull($this->resolver()->userOf(new RecipientRef('user', 'x')), 'another recipient type');

            return;
        }
        $user = $this->host()->createUser($kernel, 'u1');
        self::assertInstanceOf(User::class, $user);
        $user->name = 'changed, not flushed';
        $again      = $this->resolver()->userOf($this->r('u1'));
        self::assertSame($user, $again, 'the managed instance is returned');
        self::assertSame('changed, not flushed', $user->name, 'never refreshed over the host\'s pending change (audit QL-L4)');
        $this->host()->entityManager($kernel)->clear();
        $loaded = $this->resolver()->userOf($this->r('u1'));
        self::assertInstanceOf(User::class, $loaded);
        self::assertFalse($this->host()->entityManager($kernel)->contains($loaded), 'a user it loaded is detached again');
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

    protected function publisher(): RecordingPublisher
    {
        return $this->service(RecordingPublisher::class, RecordingPublisher::class);
    }

    protected function resolver(): RecipientResolverInterface
    {
        return $this->service(RecipientResolverInterface::class, RecipientResolverInterface::class);
    }

    /** Id of the last row {@see self::insertRow()} wrote. */
    protected string $lastInsertedId = '';

    /** Rows {@see self::insertRow()} wrote in this test. */
    private int $inserted = 0;

    /**
     * Writes a row behind the bundle's back (a row written before the recipient lost an access, or by an older version).
     */
    protected function insertRow(string $recipientId, string $type, ?ContextRef $context): void
    {
        $n                    = ++$this->inserted;
        $this->lastInsertedId = sprintf('019a0000-0000-7000-8000-%012d', $n);
        $this->connection()->insert($this->table(), [
            'id'         => $this->lastInsertedId, 'recipient_type' => $this->host()->recipientType(), 'recipient_id' => $recipientId, 'type' => $type, 'category' => 'general',
            'actor_type' => null, 'actor_id' => null, 'context_type' => $context?->type, 'context_id' => $context?->id, 'target' => 't:' . $n, 'url' => '/hidden/' . $n,
            'params'     => '{}', 'repeats' => 1, 'first_created_at' => '2026-11-14 09:00:00', 'created_at' => '2026-11-14 09:00:00', 'read_at' => null,
        ]);
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
