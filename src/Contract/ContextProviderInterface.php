<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Host SPI (`preferences.contexts.provider`): names the contexts (projects, boards) of the host (contract §4.3). The
 * inbox shows {@see self::label()} next to a row; the preferences page (S3) lists {@see self::contextsFor()}.
 *
 * Default: no contexts (no label, no per-context matrix). Implementations are stateless (worker mode).
 */
interface ContextProviderInterface
{
    /**
     * The contexts $recipient may tune (only contexts they can open).
     *
     * @return list<ContextChoice>
     */
    public function contextsFor(RecipientRef $recipient): array;

    /**
     * Display label of $context (plain text); null = none or unknown.
     */
    public function label(ContextRef $context): ?string;
}
