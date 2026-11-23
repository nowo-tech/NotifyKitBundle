<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Preferences;

use Nowo\NotifyKitBundle\Contract\PreferenceMatrix;
use Nowo\NotifyKitBundle\Contract\PreferenceRow;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;

use function array_filter;
use function array_keys;
use function in_array;

/**
 * The shape of the matrix from the type registry (contract §8.3): the channels (columns), the categories that hold at
 * least one registered type shown in the matrix (rows, in registry order), each category's default per channel (on when
 * one of its types is on by default) and the channels every type of the category locks.
 *
 * Plain data built per call from the immutable registry (worker mode).
 *
 * @internal
 */
final readonly class PreferenceDefaults
{
    /**
     * @param list<string> $channels
     * @param list<string> $categories
     * @param array<string, array<string, bool>> $defaults
     * @param array<string, list<string>> $locked
     */
    private function __construct(
        public array $channels,
        public array $categories,
        public array $defaults,
        public array $locked,
    ) {
    }

    /**
     * Reads the registry.
     */
    public static function of(TypeRegistryInterface $types): self
    {
        $channels = $types->channels();
        /** @var array<string, array<string, bool>> $defaults */
        $defaults = [];
        /** @var array<string, array<string, bool>> $lockedBy */
        $lockedBy = [];
        foreach ($types->types() as $type) {
            if (!$type->inMatrix) {
                continue;
            }
            foreach ($channels as $channel) {
                $defaults[$type->category][$channel] = ($defaults[$type->category][$channel] ?? false) || ($type->defaultChannels[$channel] ?? false);
                $locked                              = in_array($channel, $type->lockedChannels, true);
                $lockedBy[$type->category][$channel] = ($lockedBy[$type->category][$channel] ?? true) && $locked;
            }
        }
        $categories = [];
        foreach (array_keys($types->categories()) as $category) {
            if (isset($defaults[$category])) {
                $categories[] = (string) $category;
            }
        }
        $locked = [];
        foreach ($lockedBy as $category => $byChannel) {
            $locked[$category] = array_keys(array_filter($byChannel));
        }

        return new self($channels, $categories, $defaults, $locked);
    }

    /**
     * A matrix of $recipient over $rows.
     *
     * @param list<PreferenceRow> $rows
     */
    public function matrix(RecipientRef $recipient, array $rows): PreferenceMatrix
    {
        return new PreferenceMatrix($recipient, $this->channels, $this->categories, $this->defaults, $this->locked, $rows);
    }
}
