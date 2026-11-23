<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

/**
 * One category × channel cell of the preferences grid.
 *
 * @internal
 */
final readonly class GridCell
{
    /**
     * @param string $channel channel key
     * @param string|null $field form field name (`c<n>`); null = locked (no field, shown as on)
     * @param string $label accessible label ("<category> — <channel>"), plain text
     * @param bool $checked the cell's current choice
     * @param string $token the stored state the page shows (`-`, `1a`…)
     */
    public function __construct(
        public string $channel,
        public ?string $field,
        public string $label,
        public bool $checked,
        public string $token,
    ) {
    }
}
