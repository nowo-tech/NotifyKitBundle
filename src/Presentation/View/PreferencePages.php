<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

use Nowo\NotifyKitBundle\Contract\ContextChoice;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;
use Nowo\NotifyKitBundle\Contract\Scope;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Presentation\TranslationDomain;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\SettingsPage;
use Nowo\UiKitBundle\View\SettingsSection;
use Symfony\Component\Form\FormView;
use Symfony\Contracts\Translation\TranslatorInterface;

use function count;

/**
 * Builds the preferences page (contract §8.3, REQ-UI-003): the grid of a matrix ({@see PreferenceGrid}) and the UiKit
 * settings scaffold around it. Plain data in, immutable view models out, built per request (worker safe).
 *
 * @internal
 */
final readonly class PreferencePages
{
    /** Translation domain of every label. */
    public const DOMAIN = TranslationDomain::NAME;

    /**
     * @param list<string> $scopes `preferences.scopes`
     * @param string|null $layout `web_ui.layout_template`
     */
    public function __construct(
        private TypeRegistryInterface $types,
        private TranslatorInterface $translator,
        private array $scopes = ['all', 'involved'],
        private ?string $layout = null,
    ) {
    }

    /**
     * The grid of $matrix: the account grid (with the master switch and $contexts), or the grid of $context.
     *
     * @param list<ContextChoice> $contexts the person's contexts (account grid only)
     * @param callable(ContextChoice): string $href the override page of a context
     */
    public function grid(PreferenceMatrix $matrix, ?ContextChoice $context, array $contexts, callable $href): PreferenceGrid
    {
        $ref      = $context?->ref;
        $channels = [];
        foreach ($matrix->channels as $channel) {
            $channels[$channel] = $this->text('notify.channel.' . $channel, $channel);
        }
        $categories = $this->types->categories();
        $rows       = [];
        $n          = 0;
        foreach ($matrix->categories as $index => $category) {
            $definition = $categories[$category] ?? null;
            $labelKey   = $definition->labelKey ?? 'notify.category.' . $category;
            $cells      = [];
            $scope      = null;
            foreach ($matrix->channels as $channel) {
                $choice  = $matrix->cell($category, $channel, $ref);
                $cells[] = new GridCell(
                    $channel,
                    $choice->locked ? null : 'c' . $n++,
                    $this->text($labelKey, $category) . ' — ' . $channels[$channel],
                    $choice->enabled,
                    PreferenceExpectation::tokenOf($matrix->stored(PreferenceKey::of($ref, $category, $channel))),
                );
                $scope ??= $choice->locked ? null : $choice->scope;
            }
            $rows[] = new GridRow(
                $category,
                $labelKey,
                $definition->group ?? 'general',
                $cells,
                $scope instanceof Scope && count($this->scopes) > 1 ? 's' . $index : null,
                ($scope ?? Scope::All)->value,
            );
        }

        if ($context instanceof ContextChoice) {
            return new PreferenceGrid($context->ref->key(), $context->label, $channels, $rows, null);
        }
        $master   = PreferenceKey::account(PreferenceKey::ANY, PreferenceKey::ANY);
        $switches = [];
        foreach ($contexts as $index => $choice) {
            $switches[] = new GridSwitch(
                'x' . $index,
                $choice->label,
                $matrix->contextEnabled($choice->ref),
                PreferenceExpectation::tokenOf($matrix->stored(PreferenceKey::forContext($choice->ref, PreferenceKey::ANY, PreferenceKey::ANY))),
                $choice->ref->key(),
                $href($choice),
                $matrix->hasOverrides($choice->ref),
                $choice->group,
            );
        }

        return new PreferenceGrid('', null, $channels, $rows, new GridSwitch('w', 'notify.preferences.master', $matrix->masterEnabled(), PreferenceExpectation::tokenOf($matrix->stored($master))), $switches);
    }

    /**
     * The settings scaffold of a grid: the account page, or a context page with *Reset to account settings* and
     * *Back*.
     *
     * @param list<Action> $actions header actions (context page)
     */
    public function page(PreferenceGrid $grid, FormView $form, array $actions = []): SettingsPage
    {
        $account = $grid->contextKey === '';

        return new SettingsPage(
            new PageHeader(
                $account ? $this->label('notify.preferences.title') : $this->label('notify.preferences.context_title', ['%context%' => (string) $grid->contextLabel]),
                $this->label($account ? 'notify.preferences.intro' : 'notify.preferences.context_intro'),
                $actions,
            ),
            [new SettingsSection('preferences', $this->label('notify.preferences.matrix'), form: $form, submitLabel: $this->label('notify.preferences.save'))],
            null,
            InboxPages::BUNDLE,
            $this->layout,
            ['data-testid' => $account ? 'notify-kit-preferences' : 'notify-kit-preferences-context'],
        );
    }

    /**
     * $key translated in the bundle domain, or $fallback when there is no translation.
     */
    private function text(string $key, string $fallback): string
    {
        $text = $this->translator->trans($key, [], self::DOMAIN);

        return $text === $key ? $fallback : $text;
    }

    /**
     * @param array<string, int|string> $params
     */
    private function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }
}
