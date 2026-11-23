<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\NotifyKitBundle\Application\Default\NoContextProvider;
use Nowo\NotifyKitBundle\Application\Notifier;
use Nowo\NotifyKitBundle\Application\Preferences\Preferences;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalNotificationStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\DbalPreferenceStore;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\NotifyKitTransactions;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectAccessChecker;
use Nowo\NotifyKitBundle\Tests\Fixtures\ProjectContextProvider;
use Nowo\NotifyKitBundle\Tests\Fixtures\Sqlite;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;

use function array_map;

/**
 * The preferences service on SQLite (contract §4.2, §5.1, §14 "Preferences"): deviations only, all or nothing,
 * optimistic concurrency, validation (matrix cells, locked cells, scopes, the person's own contexts), the event after
 * the commit, *Reset to account settings*, and the in-app gate of `notify()` (including the `involved` cap, QL-I3).
 */
final class PreferencesTest extends TestCase
{
    private Connection $connection;

    private NotifyKitTransactions $transactions;

    private EventDispatcher $dispatcher;

    /** @var list<PreferencesChangedEvent> */
    private array $events = [];

    private RecipientRef $alice;

    protected function setUp(): void
    {
        $this->connection = Sqlite::connection();
        $registry         = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerNames')->willReturn([]);
        $registry->method('getConnectionNames')->willReturn(['default' => 'doctrine.dbal.default_connection']);
        $registry->method('getConnection')->willReturn($this->connection);
        $this->transactions = new NotifyKitTransactions($registry, new AfterCommitQueue());
        $this->dispatcher   = new EventDispatcher();
        $this->dispatcher->addListener(PreferencesChangedEvent::class, function (PreferencesChangedEvent $event): void {
            $this->events[] = $event;
        });
        $this->alice = new RecipientRef('user', 'alice');
    }

    public function testOnlyDeviationsAreStoredAndADefaultDeletesTheRow(): void
    {
        $preferences = $this->preferences();
        $changed     = $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), false)]));
        self::assertSame(['account/issues/mail'], $this->strings($changed));
        self::assertSame(1, $this->rows());
        self::assertFalse($preferences->matrixFor($this->alice)->resolve('issues', 'mail')->enabled);
        self::assertCount(1, $this->events);

        self::assertSame([], $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), false)])), 'the same choice again changes nothing');
        self::assertCount(1, $this->events, 'no event without a change');

        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), true, Scope::All, PreferenceExpectation::fromToken('0a'))]));
        self::assertSame(0, $this->rows(), 'back to the default: the row is deleted');
        self::assertSame([], $preferences->save($this->alice, new PreferenceChangeSet([])));

        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'push'), true, Scope::Involved, PreferenceExpectation::absent())]));
        self::assertSame(Scope::Involved, $preferences->matrixFor($this->alice)->stored(PreferenceKey::account('issues', 'push'))?->scope);
        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'push'), true, Scope::All, PreferenceExpectation::fromToken('1i'))]));
        self::assertSame(Scope::All, $preferences->matrixFor($this->alice)->stored(PreferenceKey::account('issues', 'push'))?->scope, 'on for everything differs from the default off');
    }

    public function testAStaleExpectationWritesNothingOfTheSave(): void
    {
        $preferences = $this->preferences();
        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), false)]));
        try {
            $preferences->save($this->alice, new PreferenceChangeSet([
                new PreferenceChange(PreferenceKey::account('issues', 'push'), true, Scope::All, PreferenceExpectation::absent()),
                new PreferenceChange(PreferenceKey::account('issues', 'mail'), false, Scope::Involved, PreferenceExpectation::absent()),
            ]));
            self::fail('stale');
        } catch (PreferenceConflictException $conflict) {
            self::assertSame(['account/issues/mail'], $this->strings($conflict->keys));
        }
        self::assertSame(1, $this->rows(), 'the push change rolled back with the conflict');
        self::assertSame(0, $this->connection->getTransactionNestingLevel());
        self::assertCount(1, $this->events);
    }

    /**
     * @return iterable<string, array{PreferenceChange}>
     */
    public static function invalidChanges(): iterable
    {
        yield 'unknown category' => [new PreferenceChange(PreferenceKey::account('nope', 'mail'), false)];
        yield 'unknown channel' => [new PreferenceChange(PreferenceKey::account('issues', 'sms'), false)];
        yield 'locked cell' => [new PreferenceChange(PreferenceKey::account('issues', 'in_app'), false)];
        yield 'scope on a switch' => [new PreferenceChange(PreferenceKey::account('*', '*'), false, Scope::Involved)];
        yield 'a context the person cannot tune' => [new PreferenceChange(PreferenceKey::forContext(new ContextRef('project', '7'), 'issues', 'mail'), false)];
    }

    #[DataProvider('invalidChanges')]
    public function testInvalidChangesAreRefused(PreferenceChange $change): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        $this->preferences()->save($this->alice, new PreferenceChangeSet([$change]));
    }

    public function testAScopeTheConfigurationDoesNotAllowIsRefused(): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        $this->preferences(['all'])->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('issues', 'mail'), true, Scope::Involved)]));
    }

    public function testContextsSwitchesOverridesAndReset(): void
    {
        $preferences = $this->preferences();
        $project     = new ContextRef('project', '42');
        self::assertCount(1, $preferences->contextsFor($this->alice));
        $preferences->save($this->alice, new PreferenceChangeSet([
            new PreferenceChange(PreferenceKey::forContext($project, '*', '*'), false),
            new PreferenceChange(PreferenceKey::forContext($project, 'issues', 'mail'), false),
            new PreferenceChange(PreferenceKey::forContext($project, 'issues', 'push'), false),
        ]));
        self::assertSame(2, $this->rows(), 'push off in the context equals the account choice: not stored');
        $matrix = $preferences->matrixFor($this->alice, $project);
        self::assertFalse($matrix->contextEnabled($project));
        self::assertTrue($matrix->hasOverrides($project));

        self::assertSame(1, $preferences->resetContext($this->alice, $project));
        self::assertSame(1, $this->rows(), 'the switch stays');
        self::assertTrue($this->events[1]->reset);
        self::assertSame(0, $preferences->resetContext($this->alice, $project));
        self::assertCount(2, $this->events, 'nothing deleted: no event');

        $this->expectException(InvalidNotificationArgumentException::class);
        $preferences->resetContext($this->alice, new ContextRef('project', '7'));
    }

    public function testTheNotifierGateOfAnOptionalBellAndTheInvolvedScope(): void
    {
        $preferences = $this->preferences();
        $notifier    = new Notifier(new DbalNotificationStore($this->transactions), $this->transactions, $this->registry(), new ProjectAccessChecker(['user:alice|issue.view' => ['project:42'], 'user:bob|issue.view' => ['project:42']]), null, 600, 3, null, null, null, $preferences);
        $bob         = new RecipientRef('user', 'bob');
        $project     = new ContextRef('project', '42');

        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('mentions', 'in_app'), true, Scope::Involved)]));
        $result = $notifier->notify(new NotificationRequest('mention.added', [$this->alice, $bob], null, $project, 't1', '/t'));
        self::assertSame([1, 1], [$result->written, $result->skippedPreference], 'alice only wants it when involved');
        $result = $notifier->notify(new NotificationRequest('mention.added', [$this->alice], null, $project, 't2', '/t', involved: [$this->alice]));
        self::assertSame(1, $result->written);

        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::forContext($project, '*', '*'), false)]));
        $result = $notifier->notify(new NotificationRequest('mention.added', [$this->alice], null, $project, 't3', '/t', involved: [$this->alice]));
        self::assertSame([0, 1], [$result->written, $result->skippedPreference], 'the context switch is off');
        $result = $notifier->notify(new NotificationRequest('issue.assigned', [$this->alice], null, $project, 't4', '/t'));
        self::assertSame(1, $result->written, 'a locked bell ignores the switches');

        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::account('*', '*'), false)]));
        self::assertSame(1, $notifier->notify(new NotificationRequest('mention.added', [$this->alice], null, null, 't5', '/t', involved: [$this->alice]))->skippedPreference, 'the master switch is off');
        self::assertSame(1, $notifier->notify(new NotificationRequest('mention.added', [$this->alice], $this->alice, null, 't6', '/t'))->skippedActor);

        $this->expectException(InvalidNotificationArgumentException::class);
        $notifier->notify(new NotificationRequest('mention.added', [$this->alice], null, null, 't7', '/t', involved: [$bob, $bob, $bob, $bob]));
    }

    public function testTheGateKeepsTheBellOfAProviderTypeWithoutAnInAppDefault(): void
    {
        $provider = new TypeDefinition('provider.type', 'outside', 'tabler:bell', 'k', null, [], []);
        self::assertSame(['user:alice' => true], $this->preferences()->lets($provider, 'in_app', [$this->alice], null, []), 'ZAL-2');
    }

    public function testTheGateLetsEveryoneThroughForALockedChannel(): void
    {
        $lets = $this->preferences()->lets($this->registry()->type('issue.assigned'), 'in_app', [$this->alice], null, []);
        self::assertSame(['user:alice' => true], $lets);
    }

    public function testWithoutContextsEveryContextChangeIsRefused(): void
    {
        $preferences = new Preferences(new DbalPreferenceStore($this->transactions), $this->transactions, $this->registry(), new NoContextProvider());
        $this->expectException(InvalidNotificationArgumentException::class);
        $preferences->save($this->alice, new PreferenceChangeSet([new PreferenceChange(PreferenceKey::forContext(new ContextRef('project', '42'), '*', '*'), false)]));
    }

    /**
     * @param list<string> $scopes
     */
    private function preferences(array $scopes = ['all', 'involved']): Preferences
    {
        return new Preferences(
            new DbalPreferenceStore($this->transactions),
            $this->transactions,
            $this->registry(),
            new ProjectContextProvider(['user:alice' => ['42' => 'Apollo']]),
            $this->dispatcher,
            null,
            $scopes,
        );
    }

    private function registry(): TypeRegistry
    {
        return TypeRegistry::create([
            'issue.assigned' => ['category' => 'issues', 'icon' => null, 'title_key' => null, 'permission' => 'issue.view', 'channels' => ['in_app' => true, 'mail' => true, 'push' => false], 'in_app_optional' => false, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true],
            'mention.added'  => ['category' => 'mentions', 'icon' => null, 'title_key' => null, 'permission' => 'issue.view', 'channels' => ['in_app' => true], 'in_app_optional' => true, 'locked_channels' => [], 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => true],
        ], [], [], true, null);
    }

    private function rows(): int
    {
        return (int) $this->connection->fetchOne('SELECT COUNT(*) FROM nowo_notify_preference');
    }

    /**
     * @param list<PreferenceKey> $keys
     *
     * @return list<string>
     */
    private function strings(array $keys): array
    {
        return array_map(static fn (PreferenceKey $key): string => $key->toString(), $keys);
    }
}
