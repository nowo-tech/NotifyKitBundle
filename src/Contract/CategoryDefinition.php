<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * A category of notification types: the rows of the preferences matrix (S3), grouped and ordered.
 */
final readonly class CategoryDefinition
{
    /**
     * @param string $key `^[a-z][a-z0-9_]{0,31}$`
     * @param string $labelKey translation key of the label
     * @param string $group the matrix group the category is shown in
     * @param int $order position inside the group
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $group = 'general',
        public int $order = 0,
    ) {
        Guard::key('CategoryDefinition key', $key);
        Guard::identifier('CategoryDefinition labelKey', $labelKey, 200);
        Guard::identifier('CategoryDefinition group', $group, 64);
    }
}
