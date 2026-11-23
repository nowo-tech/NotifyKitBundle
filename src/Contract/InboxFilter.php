<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Filters of an inbox page (contract §4.2): one type, one category and the read state; null = any.
 */
final readonly class InboxFilter
{
    /**
     * @param string|null $type a type key (`issue.assigned`), matched exactly
     * @param string|null $category a category key, matched exactly
     */
    public function __construct(
        public ?string $type = null,
        public ?string $category = null,
        public ?InboxStatus $status = null,
    ) {
        if ($type !== null) {
            Guard::type('InboxFilter type', $type);
        }
        if ($category !== null) {
            Guard::key('InboxFilter category', $category);
        }
    }

    /**
     * No filter: every visible row.
     */
    public static function all(): self
    {
        return new self();
    }

    /**
     * Whether no filter is set.
     */
    public function isEmpty(): bool
    {
        return $this->type === null && $this->category === null && !$this->status instanceof InboxStatus;
    }
}
