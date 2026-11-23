<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Application;

use Nowo\NotifyKitBundle\Application\Preferences\PreferenceDefaults;
use Nowo\NotifyKitBundle\Application\TypeRegistry;
use Nowo\NotifyKitBundle\Contract\ContextRef;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Beacon's merge order (contract §14 "Preferences"): locked channel → master switch → context switch → context ×
 * category override → account × category → default; exact channel before `*` at each level; scope `involved`.
 */
final class PreferenceMatrixTest extends TestCase
{
    /**
     * Rows are `[context ('' = account), category, channel, enabled, scope]`.
     *
     * @return iterable<string, array{list<array{string, string, string, bool, string}>, string, ?string, bool, string, bool}>
     */
    public static function resolutionTable(): iterable
    {
        // [rows, channel, context, expected enabled, expected scope, involved → lets]
        yield 'default on (type default)' => [[], 'mail', null, true, 'all', true];
        yield 'default off (no default)' => [[], 'push', null, false, 'all', false];
        yield 'account row' => [[['', 'issues', 'mail', false, 'all']], 'mail', null, false, 'all', false];
        yield 'account row for every channel' => [[['', 'issues', '*', true, 'all']], 'push', null, true, 'all', true];
        yield 'exact channel before *' => [[['', 'issues', '*', false, 'all'], ['', 'issues', 'push', true, 'all']], 'push', null, true, 'all', true];
        yield 'master off' => [[['', '*', '*', false, 'all'], ['', 'issues', 'mail', true, 'all']], 'mail', null, false, 'all', false];
        yield 'master off for one channel' => [[['', '*', 'mail', false, 'all']], 'mail', null, false, 'all', false];
        yield 'master off for one channel leaves the others' => [[['', '*', 'push', false, 'all']], 'mail', null, true, 'all', true];
        yield 'master on row changes nothing' => [[['', '*', '*', true, 'all']], 'mail', '42', true, 'all', true];
        yield 'context switch off' => [[['project:42', '*', '*', false, 'all']], 'mail', '42', false, 'all', false];
        yield 'context switch of another context' => [[['project:7', '*', '*', false, 'all']], 'mail', '42', true, 'all', true];
        yield 'context override wins over account' => [[['', 'issues', 'mail', false, 'all'], ['project:42', 'issues', 'mail', true, 'all']], 'mail', '42', true, 'all', true];
        yield 'context override for every channel' => [[['project:42', 'issues', '*', false, 'all']], 'mail', '42', false, 'all', false];
        yield 'context override ignored without context' => [[['project:42', 'issues', 'mail', false, 'all']], 'mail', null, true, 'all', true];
        yield 'account row applies in a context' => [[['', 'issues', 'mail', false, 'all']], 'mail', '42', false, 'all', false];
        yield 'scope involved, involved' => [[['', 'issues', 'mail', true, 'involved']], 'mail', null, true, 'involved', true];
        yield 'locked in_app ignores the master' => [[['', '*', '*', false, 'all'], ['', 'issues', 'in_app', false, 'all']], 'in_app', null, true, 'all', true];
    }

    /**
     * @param list<array{string, string, string, bool, string}> $rows
     */
    #[DataProvider('resolutionTable')]
    public function testResolution(array $rows, string $channel, ?string $context, bool $enabled, string $scope, bool $lets): void
    {
        $matrix = $this->matrix($rows);
        $ref    = $context === null ? null : new ContextRef('project', $context);
        $choice = $matrix->resolveType($this->type(), $channel, $ref);
        self::assertSame([$enabled, $scope], [$choice->enabled, $choice->scope->value]);
        self::assertSame($lets, $choice->lets(true));
        $category = $matrix->resolve('issues', $channel, $ref);
        self::assertSame($enabled, $category->enabled, 'the category resolution agrees (one type per category)');
    }

    public function testInvolvedScopeNeedsTheRecipientInvolved(): void
    {
        $choice = $this->matrix([['', 'issues', 'mail', true, 'involved']])->resolveType($this->type(), 'mail');
        self::assertFalse($choice->lets(false));
        self::assertTrue($choice->lets(true));
    }

    public function testCellsDefaultsAndSwitches(): void
    {
        $project = new ContextRef('project', '42');
        $matrix  = $this->matrix([['', 'issues', 'mail', false, 'all'], ['project:42', '*', '*', false, 'all'], ['project:42', 'issues', 'push', true, 'involved']]);
        self::assertSame(['in_app', 'mail', 'push'], $matrix->channels);
        self::assertSame(['issues'], $matrix->categories);
        self::assertTrue($matrix->isLocked('issues', 'in_app'));
        self::assertTrue($matrix->cell('issues', 'in_app', null)->locked);
        self::assertTrue($matrix->resolve('issues', 'in_app')->locked);
        self::assertFalse($matrix->cell('issues', 'mail', $project)->enabled, 'the account row shows in the context');
        self::assertTrue($matrix->cell('issues', 'push', $project)->enabled);
        self::assertFalse($matrix->resolve('issues', 'push', $project)->enabled, 'the context switch is off');
        self::assertTrue($matrix->masterEnabled());
        self::assertFalse($matrix->contextEnabled($project));
        self::assertTrue($matrix->hasOverrides($project));
        self::assertFalse($matrix->hasOverrides(new ContextRef('project', '7')));
        self::assertTrue($matrix->defaultFor(PreferenceKey::forContext($project, '*', '*'))->enabled);
        self::assertTrue($matrix->defaultFor(PreferenceKey::account('issues', 'mail'))->enabled, 'the category default');
        self::assertFalse($matrix->defaultFor(PreferenceKey::forContext($project, 'issues', 'mail'))->enabled, 'a context cell defaults to the account choice');
        self::assertFalse($matrix->resolve('other', 'mail')->enabled, 'unknown category: off');
        self::assertNull($matrix->stored(PreferenceKey::account('issues', 'push')));
        self::assertSame(Scope::Involved, $matrix->stored(PreferenceKey::forContext($project, 'issues', 'push'))?->scope);
    }

    public function testCategoryDefaultsAndLocksFollowTheirTypes(): void
    {
        $registry = TypeRegistry::create([
            'a.one'   => $this->config('cat', ['mail' => true], false, []),
            'a.two'   => $this->config('cat', ['mail' => false, 'push' => false], true, ['push']),
            'b.three' => $this->config('hidden', ['mail' => true], false, [], false),
        ], [], [], false, null);
        $defaults = PreferenceDefaults::of($registry);
        self::assertSame(['in_app', 'mail', 'push'], $defaults->channels);
        self::assertSame(['cat'], $defaults->categories, 'a category of types out of the matrix is not shown');
        self::assertSame(['in_app' => true, 'mail' => true, 'push' => false], $defaults->defaults['cat']);
        self::assertSame([], $defaults->locked['cat'], 'in_app is optional for a.two only; push locked by a.two only');
        $matrix = $defaults->matrix(new RecipientRef('user', 'a'), []);
        self::assertFalse($matrix->isLocked('cat', 'in_app'));
    }

    public function testAProviderTypeWithoutAnInAppDefaultKeepsItsBell(): void
    {
        $provider = new TypeDefinition('provider.type', 'other', 'tabler:bell', 'k', null, [], []);
        self::assertTrue($provider->isDefaultOn('in_app'), 'ZAL-2: as writesInApp() in S1');
        self::assertFalse($provider->isDefaultOn('mail'));
        self::assertTrue($this->matrix([])->resolveType($provider, 'in_app')->enabled, 'a category outside the matrix: the type default');
    }

    public function testWhatThePageShowsIsWhatIsDeliveredInAMixedCategory(): void
    {
        $registry = TypeRegistry::create([
            'a.loud'  => $this->config('mixed', ['in_app' => true], true, []),
            'a.quiet' => $this->config('mixed', ['in_app' => false], true, []),
        ], [], [], false, null);
        $defaults = PreferenceDefaults::of($registry);
        $quiet    = $registry->type('a.quiet');

        $untouched = $defaults->matrix(new RecipientRef('user', 'a'), []);
        self::assertTrue($untouched->cell('mixed', 'in_app', null)->enabled, 'the page shows on');
        self::assertTrue($untouched->resolveType($quiet, 'in_app')->enabled, 'ZAL-3: and the quiet type is delivered too');

        $off = $defaults->matrix(new RecipientRef('user', 'a'), [new PreferenceRow(PreferenceKey::account('mixed', 'in_app'), false)]);
        self::assertFalse($off->cell('mixed', 'in_app', null)->enabled);
        self::assertFalse($off->resolveType($quiet, 'in_app')->enabled);
        self::assertFalse($off->resolveType($registry->type('a.loud'), 'in_app')->enabled);
    }

    /**
     * @param list<array{string, string, string, bool, string}> $rows
     */
    private function matrix(array $rows): PreferenceMatrix
    {
        $stored = [];
        foreach ($rows as [$context, $category, $channel, $enabled, $scope]) {
            $stored[] = new PreferenceRow(PreferenceKey::stored($context, $category, $channel), $enabled, Scope::from($scope));
        }

        return PreferenceDefaults::of(TypeRegistry::create(['issue.assigned' => $this->config('issues', ['in_app' => true, 'mail' => true, 'push' => false], false, [])], [], [], false, null))
            ->matrix(new RecipientRef('user', 'a'), $stored);
    }

    private function type(): TypeDefinition
    {
        return new TypeDefinition('issue.assigned', 'issues', 'tabler:bug', 'k', null, ['in_app' => true, 'mail' => true, 'push' => false]);
    }

    /**
     * @param array<string, bool> $channels
     * @param list<string> $locked
     *
     * @return array{category: string, icon: null, title_key: null, permission: null, channels: array<string, bool>, in_app_optional: bool, locked_channels: list<string>, urgency: string, coalesce: bool, in_matrix: bool}
     */
    private function config(string $category, array $channels, bool $optional, array $locked, bool $inMatrix = true): array
    {
        return ['category' => $category, 'icon' => null, 'title_key' => null, 'permission' => null, 'channels' => $channels, 'in_app_optional' => $optional, 'locked_channels' => $locked, 'urgency' => 'normal', 'coalesce' => true, 'in_matrix' => $inMatrix];
    }
}
