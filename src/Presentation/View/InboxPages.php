<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

use Nowo\NotifyKitBundle\Contract\TranslationDomain;
use Nowo\NotifyKitBundle\Domain\InboxStatistics;
use Nowo\UiKitBundle\View\Action;
use Nowo\UiKitBundle\View\Column;
use Nowo\UiKitBundle\View\DetailItem;
use Nowo\UiKitBundle\View\DetailSection;
use Nowo\UiKitBundle\View\EmptyState;
use Nowo\UiKitBundle\View\FilterField;
use Nowo\UiKitBundle\View\Label;
use Nowo\UiKitBundle\View\ListPage;
use Nowo\UiKitBundle\View\PageHeader;
use Nowo\UiKitBundle\View\ShowPage;
use Nowo\UiKitBundle\View\Stat;
use Symfony\Component\Form\FormView;

use function assert;

/**
 * Builds the UiKit view models of the pages (REQ-UI-003): the inbox (list scaffold, contract §8.2) and the operator
 * page (show scaffold, §8.4). Plain data in, immutable view models out, built per request (worker safe).
 *
 * @internal
 */
final readonly class InboxPages
{
    /** Translation domain of every label. */
    public const DOMAIN = TranslationDomain::NAME;

    /** Bundle name for per-bundle theme exceptions (`nowo_ui_kit.theme.bundles.NowoNotifyKitBundle`). */
    public const BUNDLE = 'NowoNotifyKitBundle';

    /**
     * @param string|null $layout `web_ui.layout_template`; null = the UiKit admin layout
     */
    public function __construct(private ?string $layout = null)
    {
    }

    /**
     * The inbox page.
     *
     * @param list<InboxRow> $rows
     * @param array{read: FormView, unread: FormView, read_all: FormView} $forms CSRF forms of the actions
     * @param string $readAllHref POST URL of *Mark all as read*
     * @param string|null $clearHref the unfiltered page when a filter is set
     */
    public function inbox(array $rows, FormView $filters, array $forms, int $unread, string $readAllHref, ?string $clearHref): ListPage
    {
        $actions = $unread > 0 ? [new Action('mark_all_done', $this->label('notify.action.read_all'), $readAllHref, method: 'POST', attributes: ['data-testid' => 'notify-kit-read-all'], form: $forms['read_all'])] : [];

        return new ListPage(
            new PageHeader($this->label('notify.inbox.title'), $this->label('notify.inbox.intro', ['%count%' => $unread]), $actions),
            [
                new Column('notification', $this->label('notify.column.notification'), required: true, phone: 'primary'),
                new Column('when', $this->label('notify.column.when'), priority: 2),
                new Column('status', $this->label('notify.column.status')),
            ],
            $rows,
            $filters,
            function (mixed $row) use ($forms): array {
                assert($row instanceof InboxRow);
                $id = $row->notification->id->value;

                return [
                    new Action('view', $this->label('notify.action.open'), $row->notification->url, attributes: ['data-testid' => 'notify-kit-open-' . $id], display: 'text'),
                    $row->isUnread()
                        ? new Action('mark_done', $this->label('notify.action.mark_read'), $row->readHref, method: 'POST', attributes: ['data-testid' => 'notify-kit-read-' . $id], form: $forms['read'], display: 'text')
                        : new Action('custom', $this->label('notify.action.mark_unread'), $row->unreadHref, method: 'POST', attributes: ['data-testid' => 'notify-kit-unread-' . $id], form: $forms['unread'], display: 'text'),
                ];
            },
            null,
            new EmptyState($this->label($clearHref !== null ? 'notify.inbox.empty_filtered' : 'notify.inbox.empty')),
            $clearHref,
            caption: $this->label('notify.inbox.caption'),
            bundle: self::BUNDLE,
            layout: $this->layout,
            attributes: ['data-testid' => 'notify-kit-inbox'],
            filterFields: [new FilterField('type', width: 'md'), new FilterField('category', width: 'md'), new FilterField('status', width: 'sm')],
            filtersSubmit: new Action('filter', $this->label('notify.action.filter')),
            captionVisible: false,
        );
    }

    /**
     * The operator page: totals as key figures, the rows written per type in the period, and *Run retention now*.
     * Counts only: no content, no recipient.
     *
     * @param int $days length of the period
     */
    public function operator(InboxStatistics $statistics, int $days, string $realtime, FormView $retentionForm, string $retentionHref): ShowPage
    {
        $byType = [];
        foreach ($statistics->writtenByType as $type => $count) {
            $byType[] = new DetailItem((string) $type, (string) $count);
        }

        return new ShowPage(
            new PageHeader(
                $this->label('notify.operator.title'),
                $this->label('notify.operator.intro', ['%days%' => $days]),
                [new Action('run', $this->label('notify.action.run_retention'), $retentionHref, method: 'POST', attributes: ['data-testid' => 'notify-kit-run-retention'], form: $retentionForm)],
            ),
            [
                new DetailSection($this->label('notify.operator.by_type', ['%days%' => $days]), $byType === [] ? [new DetailItem($this->label('notify.operator.type'), null)] : $byType, 'notify-kit-by-type'),
                new DetailSection($this->label('notify.operator.delivery'), [
                    new DetailItem($this->label('notify.operator.channels'), 'in_app'),
                    new DetailItem($this->label('notify.operator.realtime'), $realtime),
                ], 'notify-kit-delivery'),
            ],
            [],
            self::BUNDLE,
            $this->layout,
            ['data-testid' => 'notify-kit-operator'],
            [
                new Stat($this->label('notify.operator.rows'), $statistics->rows, attributes: ['data-testid' => 'notify-kit-rows']),
                new Stat($this->label('notify.operator.unread'), $statistics->unread, null, 'info', attributes: ['data-testid' => 'notify-kit-unread']),
                new Stat($this->label('notify.operator.dedupe'), $statistics->dedupeMarkers),
            ],
        );
    }

    /**
     * @param array<string, int|string> $params
     */
    private function label(string $key, array $params = []): Label
    {
        return new Label($key, self::DOMAIN, $params);
    }
}
