<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;

/**
 * The preferences matrix as the page renders it (contract §8.3): channels as columns, categories as rows, the switch
 * (master on the account page, the context switch on a context page) and, on the account page, the contexts with their
 * switches. Built per request from a {@see PreferenceMatrix} (worker mode).
 *
 * @internal
 */
final readonly class PreferenceGrid
{
    /**
     * @param string $contextKey `''` = account grid
     * @param string|null $contextLabel plain-text label of the context
     * @param array<string, string> $channels channel key => translated label
     * @param list<GridRow> $rows
     * @param GridSwitch|null $switch the master switch (account) or null (a context grid: its switch is on the account page)
     * @param list<GridSwitch> $contexts the context switches (account grid only)
     */
    public function __construct(
        public string $contextKey,
        public ?string $contextLabel,
        public array $channels,
        public array $rows,
        public ?GridSwitch $switch,
        public array $contexts = [],
    ) {
    }

    /**
     * The checkbox cells and switches by field name (what a submitted form is compared with).
     *
     * @return array<string, array{GridCell|GridSwitch, GridRow|null}>
     */
    public function fields(): array
    {
        $fields = [];
        if ($this->switch instanceof GridSwitch) {
            $fields[$this->switch->field] = [$this->switch, null];
        }
        foreach ($this->contexts as $context) {
            $fields[$context->field] = [$context, null];
        }
        foreach ($this->rows as $row) {
            foreach ($row->cells as $cell) {
                if ($cell->field !== null) {
                    $fields[$cell->field] = [$cell, $row];
                }
            }
        }

        return $fields;
    }
}
