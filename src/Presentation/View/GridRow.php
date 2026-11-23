<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

/**
 * One category of the preferences grid: its cells (one per channel) and its scope select.
 *
 * @internal
 */
final readonly class GridRow
{
    /**
     * @param string $category category key
     * @param string $label translation key of the label (bundle domain)
     * @param string $group the category's matrix group
     * @param list<GridCell> $cells in channel order
     * @param string|null $scopeField form field of the scope select (`s<n>`); null = no select
     * @param string $scope the scope the row shows (`all` / `involved`)
     */
    public function __construct(
        public string $category,
        public string $label,
        public string $group,
        public array $cells,
        public ?string $scopeField,
        public string $scope,
    ) {
    }
}
