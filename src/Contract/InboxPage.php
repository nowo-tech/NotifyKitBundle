<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * One keyset page of the inbox, newest first (contract §4.2, REQ-PERF-001).
 */
final readonly class InboxPage
{
    /**
     * @param list<NotificationView> $items
     * @param string|null $next the opaque cursor of the next page (pass it as `$after`); null = last page
     */
    public function __construct(public array $items, public ?string $next)
    {
    }
}
