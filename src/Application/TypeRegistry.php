<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application;

use LogicException;
use Nowo\NotifyKitBundle\Contract\CategoryDefinition;
use Nowo\NotifyKitBundle\Contract\TypeDefinition;
use Nowo\NotifyKitBundle\Contract\TypeProviderInterface;
use Nowo\NotifyKitBundle\Contract\TypeRegistryInterface;
use Nowo\NotifyKitBundle\Contract\Urgency;

use function array_keys;
use function array_values;
use function sprintf;
use function uasort;

/**
 * Default {@see TypeRegistryInterface}: the configured types and categories plus every tagged provider, merged once
 * when the service is built (immutable afterwards, worker-safe).
 *
 * A key declared twice is refused; a category a type names but nobody declared is added with the label
 * `notify.category.<key>`; deny by default (Q-O6): a type with a permission needs a host access checker.
 */
final readonly class TypeRegistry implements TypeRegistryInterface
{
    /** Category of open-set (unknown) types. */
    public const GENERIC_CATEGORY = 'general';

    /** Title key of open-set (unknown) types. */
    public const GENERIC_TITLE_KEY = 'notify.type.generic.title';

    /** Icon of types that set none. */
    public const DEFAULT_ICON = 'tabler:bell';

    /** @var array<string, TypeDefinition> */
    private array $types;

    /** @var array<string, CategoryDefinition> */
    private array $categories;

    /** @var list<string> */
    private array $channels;

    /**
     * @param array<string, TypeDefinition> $types
     * @param array<string, CategoryDefinition> $categories
     */
    private function __construct(array $types, array $categories, private ?string $defaultPermission)
    {
        $this->types      = $types;
        $this->categories = $categories;
        $channels         = [TypeDefinition::IN_APP => true];
        foreach ($types as $type) {
            foreach ([...array_keys($type->defaultChannels), ...$type->lockedChannels] as $channel) {
                $channels[$channel] = true;
            }
        }
        $this->channels = array_keys($channels);
    }

    /**
     * Builds the registry from the processed `types` / `categories` configuration and the tagged providers.
     *
     * @param array<string, array{category: string, icon: string|null, title_key: string|null, title_domain?: string|null, permission: string|null, channels: array<string, bool>, in_app_optional: bool, locked_channels: list<string>, urgency: string, coalesce: bool, in_matrix: bool}> $types
     * @param array<string, array{label: string, group: string, order: int}> $categories
     * @param iterable<TypeProviderInterface> $providers
     * @param bool $hasAccessChecker whether the host configured `access.checker`
     */
    public static function create(array $types, array $categories, iterable $providers, bool $hasAccessChecker, ?string $defaultPermission): self
    {
        $typeDefinitions     = [];
        $categoryDefinitions = [];
        foreach ($categories as $key => $category) {
            $categoryDefinitions[$key] = new CategoryDefinition($key, $category['label'], $category['group'], $category['order']);
        }
        foreach ($types as $key => $type) {
            $locked                = $type['in_app_optional'] ? $type['locked_channels'] : [TypeDefinition::IN_APP, ...$type['locked_channels']];
            $typeDefinitions[$key] = new TypeDefinition(
                $key,
                $type['category'],
                $type['icon'] ?? self::DEFAULT_ICON,
                $type['title_key'] ?? 'notify.type.' . $key . '.title',
                $type['permission'],
                $type['channels'] + [TypeDefinition::IN_APP => true],
                array_values(array_unique($locked)),
                Urgency::from($type['urgency']),
                $type['coalesce'],
                $type['in_matrix'],
                $type['title_domain'] ?? null,
            );
        }
        foreach ($providers as $provider) {
            foreach ($provider->categories() as $category) {
                if (isset($categoryDefinitions[$category->key])) {
                    throw new LogicException(sprintf('nowo_notify_kit: category "%s" is declared twice (configuration or %s).', $category->key, $provider::class));
                }
                $categoryDefinitions[$category->key] = $category;
            }
            foreach ($provider->types() as $type) {
                if (isset($typeDefinitions[$type->key])) {
                    throw new LogicException(sprintf('nowo_notify_kit: type "%s" is declared twice (configuration or %s).', $type->key, $provider::class));
                }
                $typeDefinitions[$type->key] = $type;
            }
        }
        foreach ($typeDefinitions as $type) {
            if ($type->permission !== null && !$hasAccessChecker) {
                throw new LogicException(sprintf('nowo_notify_kit: type "%s" needs the permission "%s", so set nowo_notify_kit.access.checker (deny by default: the bundle never guesses who may open a target).', $type->key, $type->permission));
            }
            $categoryDefinitions[$type->category] ??= new CategoryDefinition($type->category, 'notify.category.' . $type->category);
        }
        $categoryDefinitions[self::GENERIC_CATEGORY] ??= new CategoryDefinition(self::GENERIC_CATEGORY, 'notify.category.' . self::GENERIC_CATEGORY);
        uasort($categoryDefinitions, static fn (CategoryDefinition $a, CategoryDefinition $b): int => [$a->group, $a->order, $a->key] <=> [$b->group, $b->order, $b->key]);

        return new self($typeDefinitions, $categoryDefinitions, $defaultPermission);
    }

    /**
     * {@inheritDoc}
     */
    public function type(string $key): TypeDefinition
    {
        return $this->types[$key] ?? new TypeDefinition(
            $key,
            self::GENERIC_CATEGORY,
            self::DEFAULT_ICON,
            self::GENERIC_TITLE_KEY,
            $this->defaultPermission,
            [TypeDefinition::IN_APP => true],
        );
    }

    /**
     * {@inheritDoc}
     */
    public function has(string $key): bool
    {
        return isset($this->types[$key]);
    }

    /**
     * {@inheritDoc}
     */
    public function types(): array
    {
        return $this->types;
    }

    /**
     * {@inheritDoc}
     */
    public function categories(): array
    {
        return $this->categories;
    }

    /**
     * {@inheritDoc}
     */
    public function channels(): array
    {
        return $this->channels;
    }
}
