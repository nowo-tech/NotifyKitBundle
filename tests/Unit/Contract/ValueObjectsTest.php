<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Contract;

use DateTimeImmutable;
use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\NotificationRecordedEvent;
use Nowo\NotifyKitBundle\Contract\Event\NotificationSkippedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Guard;
use Nowo\NotifyKitBundle\Contract\Message\PruneNotificationsMessage;
use Nowo\NotifyKitBundle\Contract\NotificationId;
use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifyResult;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\SkipReason;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\Urgency;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function mb_strlen;
use function range;
use function str_repeat;

final class ValueObjectsTest extends TestCase
{
    public function testRecipientAndContextReferencesCompareExactly(): void
    {
        $upper = new RecipientRef('user', 'UserA');
        self::assertSame('user:UserA', $upper->key());
        self::assertFalse($upper->equals(new RecipientRef('user', 'usera')));
        self::assertTrue($upper->equals(new RecipientRef('user', 'UserA')));
        self::assertFalse($upper->equals(new RecipientRef('member', 'UserA')));
        self::assertSame(' padded ', (new RecipientRef('user', ' padded '))->id, 'never trimmed');
        self::assertSame('ñandú', (new RecipientRef('user', 'ñandú'))->id);
        self::assertSame(128, mb_strlen((new RecipientRef('user', str_repeat('é', 128)))->id));

        $project = new ContextRef('project', '42');
        self::assertSame('project:42', $project->key());
        self::assertTrue($project->equals(new ContextRef('project', '42')));
        self::assertFalse($project->equals(new ContextRef('board', '42')));
    }

    /**
     * @return iterable<string, array{callable(): mixed, string}>
     */
    public static function invalidValues(): iterable
    {
        yield 'recipient type' => [static fn (): RecipientRef => new RecipientRef('User', 'a'), 'RecipientRef type'];
        yield 'empty recipient id' => [static fn (): RecipientRef => new RecipientRef('user', ''), 'RecipientRef id'];
        yield 'long recipient id' => [static fn (): RecipientRef => new RecipientRef('user', str_repeat('a', 129)), 'RecipientRef id'];
        yield 'control character' => [static fn (): RecipientRef => new RecipientRef('user', "a\nb"), 'RecipientRef id'];
        yield 'not UTF-8' => [static fn (): RecipientRef => new RecipientRef('user', "\xff"), 'RecipientRef id'];
        yield 'long context id' => [static fn (): ContextRef => new ContextRef('project', str_repeat('1', 65)), 'ContextRef id'];
        yield 'notification id' => [static fn (): NotificationId => new NotificationId('01933B1C-5D2E-7A3B-8C4D-123456789ABC'), 'UUIDv7'];
        yield 'uuid v4' => [static fn (): NotificationId => new NotificationId('8c4d1b2e-5d2e-4a3b-8c4d-123456789abc'), 'UUIDv7'];
        yield 'type key' => [static fn (): NotificationRequest => self::request(['type' => 'Issue']), 'NotificationRequest type'];
        yield 'long type key' => [static fn (): NotificationRequest => self::request(['type' => str_repeat('a', 65)]), 'NotificationRequest type'];
        yield 'recipients map' => [static fn (): NotificationRequest => self::request(['recipients' => ['x' => new RecipientRef('user', 'a')]]), 'recipients must be a list'];
        yield 'recipient object' => [static fn (): NotificationRequest => self::request(['recipients' => ['user:a']]), 'recipients must hold RecipientRef'];
        yield 'involved object' => [static fn (): NotificationRequest => self::request(['involved' => [new ContextRef('project', '1')]]), 'involved must hold RecipientRef'];
        yield 'target' => [static fn (): NotificationRequest => self::request(['target' => '']), 'NotificationRequest target'];
        yield 'dedupe key' => [static fn (): NotificationRequest => self::request(['dedupeKey' => str_repeat('k', 192)]), 'NotificationRequest dedupeKey'];
        yield 'too many params' => [static fn (): NotificationRequest => self::request(['params' => array_fill_keys(range('a', 'u'), 'v')]), 'at most 20 keys'];
        yield 'param not a string' => [static fn (): NotificationRequest => self::request(['params' => ['n' => 1]]), 'string keys to string values'];
        yield 'param list' => [static fn (): NotificationRequest => self::request(['params' => ['v']]), 'string keys to string values'];
        yield 'param key' => [static fn (): NotificationRequest => self::request(['params' => [str_repeat('k', 65) => 'v']]), 'param key'];
        yield 'param not UTF-8' => [static fn (): NotificationRequest => self::request(['params' => ['k' => "\xff"]]), 'must be UTF-8'];
        yield 'type definition key' => [static fn (): TypeDefinition => new TypeDefinition('Bad', 'general', 'i', 't', null, []), 'TypeDefinition key'];
        yield 'type definition permission' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', '', []), 'TypeDefinition permission'];
        /* @phpstan-ignore argument.type (invalid on purpose) */
        yield 'type definition channels' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', null, ['in_app' => 'yes']), 'maps channel keys to booleans'];
        yield 'type definition channel key' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', null, ['In-App' => true]), 'TypeDefinition channel'];
        /* @phpstan-ignore argument.type (invalid on purpose) */
        yield 'locked list' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', null, [], ['a' => 'in_app']), 'lockedChannels must be a list'];
        /* @phpstan-ignore argument.type (invalid on purpose) */
        yield 'locked key' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', null, [], [1]), 'lockedChannels holds channel keys'];
        yield 'locked channel' => [static fn (): TypeDefinition => new TypeDefinition('ok', 'general', 'i', 't', null, [], ['In']), 'TypeDefinition locked channel'];
        yield 'category key' => [static fn (): CategoryDefinition => new CategoryDefinition('Bad', 'label'), 'CategoryDefinition key'];
    }

    /**
     * @param callable(): mixed $build
     */
    #[DataProvider('invalidValues')]
    public function testInvalidValuesAreRefusedWithTheField(callable $build, string $message): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        $this->expectExceptionMessage($message);
        $build();
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function urls(): iterable
    {
        yield 'path' => ['/projects/42/issues/1?tab=a#c', true];
        yield 'root' => ['/', true];
        yield 'unicode' => ['/wiki/ñandú', true];
        yield 'empty' => ['', false];
        yield 'relative' => ['projects/42', false];
        yield 'scheme' => ['https://evil.example/', false];
        yield 'javascript' => ['javascript:alert(1)', false];
        yield 'protocol relative' => ['//evil.example/', false];
        yield 'backslash' => ['/\\evil.example', false];
        yield 'backslash first' => ['\\\\evil.example', false];
        yield 'newline' => ["/a\nb", false];
        yield 'tab' => ["/a\tb", false];
        yield 'delete' => ["/a\x7f", false];
        yield 'not UTF-8' => ["/\xff", false];
        yield 'too long' => ['/' . str_repeat('a', 500), false];
        yield 'longest' => ['/' . str_repeat('a', 499), true];
    }

    #[DataProvider('urls')]
    public function testOnlyLocalPathsAreAccepted(string $url, bool $local): void
    {
        self::assertSame($local, Guard::isLocalPath($url));
        if (!$local) {
            $this->expectException(InvalidNotificationArgumentException::class);
            $this->expectExceptionMessage('url must be a local path');
        }
        self::assertSame($url, self::request(['url' => $url])->url);
    }

    public function testARequestCutsLongParamsAndKeepsItsValues(): void
    {
        $actor   = new RecipientRef('user', 'x');
        $request = new NotificationRequest('issue.assigned', [new RecipientRef('user', 'a')], $actor, new ContextRef('project', '1'), 'issue:1', '/i/1', ['title' => str_repeat('é', 250), 'empty' => ''], Urgency::High, [new RecipientRef('user', 'a')], 'once');

        self::assertSame(200, mb_strlen($request->params['title']));
        self::assertSame('', $request->params['empty']);
        self::assertSame(Urgency::High, $request->urgency);
        self::assertSame('once', $request->dedupeKey);
        self::assertSame($actor, $request->actor);
        self::assertCount(1, $request->involved);
    }

    public function testDefinitionsAndResults(): void
    {
        $type = new TypeDefinition('issue.assigned', 'issues', 'tabler:bug', 'notify.type.issue.assigned.title', 'issue.view', ['in_app' => true, 'mail' => false]);
        self::assertSame(['in_app'], $type->lockedChannels);
        self::assertTrue($type->writesInApp());
        self::assertTrue((new TypeDefinition('a', 'b', 'i', 't', null, ['in_app' => false]))->writesInApp(), 'locked on');
        self::assertFalse((new TypeDefinition('a', 'b', 'i', 't', null, ['in_app' => false], []))->writesInApp());
        self::assertTrue((new TypeDefinition('a', 'b', 'i', 't', null, [], []))->writesInApp(), 'on unless turned off');

        $category = new CategoryDefinition('issues', 'notify.category.issues');
        self::assertSame(['general', 0], [$category->group, $category->order]);

        $result = new NotifyResult();
        self::assertSame([0, 0, 0, 0, false, 0, []], [$result->written, $result->coalesced, $result->skippedActor, $result->skippedAccess, $result->deduplicated, $result->enqueued, $result->notificationIds]);
        self::assertTrue(NotificationId::isValid('01933b1c-5d2e-7a3b-8c4d-123456789abc'));

        $id    = new NotificationId('01933b1c-5d2e-7a3b-8c4d-123456789abc');
        $event = new NotificationRecordedEvent($id, new RecipientRef('user', 'a'), 'issue.assigned', null, true);
        self::assertTrue($event->coalesced);
        self::assertSame(SkipReason::Dedupe, (new NotificationSkippedEvent('t', new RecipientRef('user', 'a'), SkipReason::Dedupe))->reason);
        self::assertSame(['read', null, null], [(new PruneNotificationsMessage())->phase, (new PruneNotificationsMessage())->afterRecipientType, (new PruneNotificationsMessage())->afterRecipientId]);

        $marker = new DedupeMarker(str_repeat('a', 64), new DateTimeImmutable('2026-11-16 10:00:00'));
        self::assertSame(str_repeat('a', 64), $marker->keyHash());
        self::assertSame('2026-11-16', $marker->expiresAt()->format('Y-m-d'));
    }

    /**
     * @param array<string, mixed> $override
     */
    private static function request(array $override = []): NotificationRequest
    {
        $values = $override + ['type' => 'issue.assigned', 'recipients' => [new RecipientRef('user', 'a')], 'target' => 'issue:1', 'url' => '/i/1', 'params' => [], 'involved' => [], 'dedupeKey' => null];

        /* @phpstan-ignore-next-line argument.type (invalid values on purpose) */
        return new NotificationRequest($values['type'], $values['recipients'], null, null, $values['target'], $values['url'], $values['params'], Urgency::Normal, $values['involved'], $values['dedupeKey']);
    }
}
