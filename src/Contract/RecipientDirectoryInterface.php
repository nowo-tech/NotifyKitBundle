<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Host SPI (`recipient.directory`): the people directory (contract §4.3). The bundle stores ids only; names are read
 * here at display time, so erasing a person removes their name everywhere. Every method answers for one person and
 * may return null when it does not know.
 *
 * The default reads the host user through {@see RecipientResolverInterface}: `__toString()` for the name,
 * `getEmail()` and `getLocale()` when the class has them. Implementations are stateless (worker mode).
 */
interface RecipientDirectoryInterface
{
    /**
     * Display name, e.g. "Ana Ruiz"; null = unknown (shown as "Someone").
     */
    public function displayName(RecipientRef $recipient): ?string;

    /**
     * Avatar URL; null = none (initials).
     */
    public function avatarUrl(RecipientRef $recipient): ?string;

    /**
     * E-mail address for the mail channel (S4); null = none.
     */
    public function email(RecipientRef $recipient): ?string;

    /**
     * Preferred locale (`es`, `pt_BR`); null = the request or default locale.
     */
    public function locale(RecipientRef $recipient): ?string;

    /**
     * IANA time zone (`Europe/Madrid`); null = `quiet_hours.default_timezone`.
     */
    public function timezone(RecipientRef $recipient): ?string;

    /**
     * Whether the account is active (an inactive person gets no out-of-band delivery, S4).
     */
    public function isActive(RecipientRef $recipient): bool;
}
