<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function array_is_list;
use function in_array;
use function is_bool;
use function is_string;

/**
 * A notification type (contract §4.1): its category, icon, title key, the permission needed to open its target when
 * it has a context, its default and locked channels. Declared in `nowo_notify_kit.types` or by a tagged
 * {@see TypeProviderInterface}.
 */
final readonly class TypeDefinition
{
    /** The built-in in-app channel (the bell). */
    public const IN_APP = 'in_app';

    /**
     * @param string $key the type key, e.g. `issue.assigned`
     * @param string $category the category key (grouping for preferences)
     * @param string $icon an icon name, e.g. `tabler:mail-check`
     * @param string $titleKey translation key of the title
     * @param string|null $permission the permission a recipient needs in the notification's context; null = the
     *                                type is personal (a request with a context then needs `access.default_permission`)
     * @param array<string, bool> $defaultChannels channel => on by default
     * @param list<string> $lockedChannels channels the person cannot turn off (`in_app` unless the type opts out)
     * @param bool $coalesce whether a repeat of the same target within the repeat window updates the unread row
     * @param bool $inMatrix whether the type's category is shown in the preferences matrix (S3)
     * @param string|null $titleDomain translation domain of $titleKey (the producer's domain, contract §8.5); null =
     *                                 `messages`
     */
    public function __construct(
        public string $key,
        public string $category,
        public string $icon,
        public string $titleKey,
        public ?string $permission,
        public array $defaultChannels,
        public array $lockedChannels = [self::IN_APP],
        public Urgency $urgency = Urgency::Normal,
        public bool $coalesce = true,
        public bool $inMatrix = true,
        public ?string $titleDomain = null,
    ) {
        Guard::type('TypeDefinition key', $key);
        Guard::key('TypeDefinition category', $category);
        Guard::identifier('TypeDefinition icon', $icon, 100);
        Guard::identifier('TypeDefinition titleKey', $titleKey, 200);
        if ($permission !== null) {
            Guard::identifier('TypeDefinition permission', $permission, 100);
        }
        if ($titleDomain !== null) {
            Guard::identifier('TypeDefinition titleDomain', $titleDomain, 100);
        }
        foreach ($defaultChannels as $channel => $enabled) {
            if (!is_string($channel) || !is_bool($enabled)) {
                throw new InvalidNotificationArgumentException('TypeDefinition defaultChannels maps channel keys to booleans.');
            }
            Guard::key('TypeDefinition channel', $channel);
        }
        if (!array_is_list($lockedChannels)) {
            throw new InvalidNotificationArgumentException('TypeDefinition lockedChannels must be a list.');
        }
        foreach ($lockedChannels as $channel) {
            if (!is_string($channel)) {
                throw new InvalidNotificationArgumentException('TypeDefinition lockedChannels holds channel keys.');
            }
            Guard::key('TypeDefinition locked channel', $channel);
        }
    }

    /**
     * Whether $channel is on by default for this type: its `defaultChannels` value; a channel it does not name is off,
     * except `in_app`, which is on (the bell is written unless the type turns it off; audit ZAL-2).
     */
    public function isDefaultOn(string $channel): bool
    {
        return $this->defaultChannels[$channel] ?? $channel === self::IN_APP;
    }

    /**
     * Whether the in-app row (the bell) is written for this type (S1: on unless the type turns it off by default).
     */
    public function writesInApp(): bool
    {
        return $this->isDefaultOn(self::IN_APP) || in_array(self::IN_APP, $this->lockedChannels, true);
    }
}
