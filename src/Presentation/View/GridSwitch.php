<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\View;

/**
 * An on / off switch of the preferences page: the master switch, or one context's switch.
 *
 * @internal
 */
final readonly class GridSwitch
{
    /**
     * @param string $field form field name (`w`, or `x<n>` for a context)
     * @param string $label translation key (bundle domain) or plain text (a context label)
     * @param bool $checked on
     * @param string $token the stored state the page shows
     * @param string $contextKey `''` (master) or the context key
     * @param string|null $href the context's override page (null for the master switch)
     * @param bool $overridden the context has overrides
     * @param string|null $group the context's heading (plain text)
     */
    public function __construct(
        public string $field,
        public string $label,
        public bool $checked,
        public string $token,
        public string $contextKey = '',
        public ?string $href = null,
        public bool $overridden = false,
        public ?string $group = null,
    ) {
    }
}
