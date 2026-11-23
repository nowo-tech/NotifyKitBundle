<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * The types and categories known to the bundle: `nowo_notify_kit.types` / `categories` merged with every tagged
 * {@see TypeProviderInterface}. Built once per container and immutable.
 */
interface TypeRegistryInterface
{
    /**
     * The definition of $key. An unknown (open-set) key gets the generic definition: category `general`, the generic
     * title, in-app only, guarded by `access.default_permission` when the request has a context.
     */
    public function type(string $key): TypeDefinition;

    /**
     * Whether $key is registered (not the generic fallback).
     */
    public function has(string $key): bool;

    /**
     * @return array<string, TypeDefinition> registered types by key
     */
    public function types(): array;

    /**
     * @return array<string, CategoryDefinition> categories by key, in group / order / key order
     */
    public function categories(): array;

    /**
     * @return list<string> the channels of the preferences matrix: `in_app` first, then every channel a registered type
     *                      names in its default or locked channels, in declaration order (delivery to out-of-band
     *                      channels starts in S4; hosts may already read the preferences for their own alerts)
     */
    public function channels(): array;
}
