<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Contract;

use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\Event\PreferencesChangedEvent;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

use function array_fill;

/**
 * The S3 value objects: cell keys, expectations (tokens), change sets, choices and the matrix's own guards.
 */
final class PreferenceValueObjectsTest extends TestCase
{
    public function testKeys(): void
    {
        $project = new ContextRef('project', '42');
        $account = PreferenceKey::account('issues', 'mail');
        self::assertTrue($account->isAccount());
        self::assertFalse($account->isSwitch());
        self::assertSame('account/issues/mail', $account->toString());
        $context = PreferenceKey::forContext($project, PreferenceKey::ANY, PreferenceKey::ANY);
        self::assertSame('project:42/*/*', $context->toString());
        self::assertTrue($context->isSwitch());
        self::assertFalse($context->isAccount());
        self::assertTrue(PreferenceKey::of(null, 'issues', 'mail')->equals($account));
        self::assertTrue(PreferenceKey::of($project, '*', '*')->equals($context));
        self::assertFalse($account->equals(PreferenceKey::account('issues', 'push')));
        self::assertTrue(PreferenceKey::stored('project:42', 'issues', '*')->equals(PreferenceKey::forContext($project, 'issues', '*')));
        self::assertTrue(PreferenceKey::stored('', '*', 'mail')->isSwitch(), 'a per-channel master switch');
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'no colon' => ['project42', 'issues', 'mail'];
        yield 'bad context type' => ['Project:42', 'issues', 'mail'];
        yield 'bad context id' => ['project: 42', 'issues', 'mail'];
        yield 'bad category' => ['', 'Issues', 'mail'];
        yield 'bad channel' => ['', 'issues', 'e-mail'];
        yield 'context switch per channel' => ['project:42', '*', 'mail'];
    }

    #[DataProvider('invalidKeys')]
    public function testStoredKeysAreValidated(string $context, string $category, string $channel): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        PreferenceKey::stored($context, $category, $channel);
    }

    public function testExpectations(): void
    {
        $off = new ChannelPreference(false, Scope::Involved);
        self::assertTrue(PreferenceExpectation::any()->holds(null));
        self::assertTrue(PreferenceExpectation::any()->holds($off));
        self::assertFalse(PreferenceExpectation::any()->checked);
        self::assertTrue(PreferenceExpectation::absent()->holds(null));
        self::assertFalse(PreferenceExpectation::absent()->holds($off));
        self::assertTrue(PreferenceExpectation::stored($off)->holds(new ChannelPreference(false, Scope::Involved, true)));
        self::assertFalse(PreferenceExpectation::stored($off)->holds(null));
        self::assertFalse(PreferenceExpectation::stored($off)->holds(new ChannelPreference(false)));
        self::assertTrue(PreferenceExpectation::of(null)->holds(null));
        self::assertTrue(PreferenceExpectation::of($off)->holds($off));

        self::assertSame('-', PreferenceExpectation::tokenOf(null));
        foreach (['1a' => [true, Scope::All], '1i' => [true, Scope::Involved], '0a' => [false, Scope::All], '0i' => [false, Scope::Involved]] as $token => [$enabled, $scope]) {
            $choice = new ChannelPreference($enabled, $scope);
            self::assertSame($token, PreferenceExpectation::tokenOf($choice));
            self::assertTrue(PreferenceExpectation::fromToken($token)->holds($choice));
        }
        self::assertTrue(PreferenceExpectation::fromToken('-')->holds(null));
        $this->expectException(InvalidNotificationArgumentException::class);
        PreferenceExpectation::fromToken('1x');
    }

    public function testChoicesAndChanges(): void
    {
        self::assertTrue((new ChannelPreference(true))->lets(false));
        self::assertFalse((new ChannelPreference(false))->lets(true));
        self::assertFalse((new ChannelPreference(true, Scope::Involved))->lets(false));
        self::assertTrue((new ChannelPreference(true, Scope::Involved))->lets(true));
        self::assertTrue((new ChannelPreference(true))->sameChoice(new ChannelPreference(true, Scope::All, true)));

        $change = new PreferenceChange(PreferenceKey::account('issues', 'mail'), false, Scope::Involved);
        self::assertFalse($change->expectation()->checked);
        self::assertTrue($change->choice()->sameChoice(new ChannelPreference(false, Scope::Involved)));
        self::assertTrue((new PreferenceRow(PreferenceKey::account('issues', 'mail'), true))->choice()->enabled);

        $set = new PreferenceChangeSet([$change]);
        self::assertFalse($set->isEmpty());
        self::assertTrue((new PreferenceChangeSet([]))->isEmpty());

        $conflict = new PreferenceConflictException([PreferenceKey::account('issues', 'mail')]);
        self::assertSame('Preferences changed meanwhile: account/issues/mail.', $conflict->getMessage());
        $event = new PreferencesChangedEvent(new RecipientRef('user', 'a'), [PreferenceKey::account('issues', 'mail')], true);
        self::assertTrue($event->reset);
    }

    /**
     * @return iterable<string, array{array<mixed>}>
     */
    public static function invalidChangeSets(): iterable
    {
        $change = new PreferenceChange(PreferenceKey::account('issues', 'mail'), true);
        yield 'not a list' => [['a' => $change]];
        yield 'not a change' => [[new stdClass()]];
        yield 'twice the same cell' => [[$change, new PreferenceChange(PreferenceKey::account('issues', 'mail'), false)]];
        yield 'too many' => [array_fill(0, PreferenceChangeSet::MAX_CHANGES + 1, $change)];
    }

    /**
     * @param array<mixed> $changes
     */
    #[DataProvider('invalidChangeSets')]
    public function testInvalidChangeSetsAreRefused(array $changes): void
    {
        $this->expectException(InvalidNotificationArgumentException::class);
        new PreferenceChangeSet($changes); // @phpstan-ignore argument.type
    }

    public function testTheMatrixRefusesRowsThatAreNotAListOfRows(): void
    {
        $recipient = new RecipientRef('user', 'a');
        try {
            new PreferenceMatrix($recipient, [], [], [], [], ['x' => new PreferenceRow(PreferenceKey::account('a', 'b'), true)]); // @phpstan-ignore argument.type
            self::fail('not a list');
        } catch (InvalidNotificationArgumentException) {
        }
        $this->expectException(InvalidNotificationArgumentException::class);
        new PreferenceMatrix($recipient, [], [], [], [], [new stdClass()]); // @phpstan-ignore argument.type
    }
}
