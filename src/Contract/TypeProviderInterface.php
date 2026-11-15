<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Host or bridge SPI (tag `nowo_notify_kit.type_provider`, autoconfigured): contributes types and categories to the
 * registry. Read once, when the registry is built; a key declared twice (config or another provider) is refused.
 */
interface TypeProviderInterface
{
    /**
     * @return iterable<TypeDefinition>
     */
    public function types(): iterable;

    /**
     * @return iterable<CategoryDefinition>
     */
    public function categories(): iterable;
}
