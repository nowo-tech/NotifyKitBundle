<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use Nowo\NotifyKitBundle\Contract\ContextRef;

use function array_values;
use function in_array;

/**
 * What one recipient may see of their own rows (contract §6.1, read time): every personal row (no context), plus the
 * rows of the granted contexts whose type's permission was granted there; or everything for an unrestricted
 * recipient. Built per call by the visibility resolver and never kept (worker mode).
 *
 * @internal
 */
final readonly class Visibility
{
    /** @var array<string, VisibleContext> */
    private array $contexts;

    /**
     * @param list<VisibleContext> $contexts
     */
    private function __construct(public bool $unrestricted, array $contexts)
    {
        $byKey = [];
        foreach ($contexts as $visible) {
            $byKey[$visible->context->key()] = $visible;
        }
        $this->contexts = $byKey;
    }

    /**
     * Everything (an unrestricted recipient).
     */
    public static function unrestricted(): self
    {
        return new self(true, []);
    }

    /**
     * Personal rows plus the listed contexts and types.
     *
     * @param list<VisibleContext> $contexts
     */
    public static function of(array $contexts): self
    {
        return new self(false, $contexts);
    }

    /**
     * The granted contexts (empty when unrestricted).
     *
     * @return list<VisibleContext>
     */
    public function contexts(): array
    {
        return array_values($this->contexts);
    }

    /**
     * Whether a row with $context and $type is visible (the PHP re-check of every row read).
     */
    public function allows(?ContextRef $context, string $type): bool
    {
        if ($this->unrestricted || !$context instanceof ContextRef) {
            return true;
        }
        $visible = $this->contexts[$context->key()] ?? null;

        return $visible instanceof VisibleContext && $visible->context->equals($context) && in_array($type, $visible->types, true);
    }
}
