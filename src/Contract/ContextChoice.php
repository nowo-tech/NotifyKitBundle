<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * One context a person can tune notifications for (the per-context matrix of S3): its reference, label and group.
 */
final readonly class ContextChoice
{
    /**
     * @param string $label display text (plain text: escaped when shown)
     * @param string|null $group optional heading (e.g. an organisation)
     */
    public function __construct(public ContextRef $ref, public string $label, public ?string $group = null)
    {
    }
}
