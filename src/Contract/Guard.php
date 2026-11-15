<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function mb_check_encoding;
use function mb_strlen;
use function preg_match;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function strlen;

/**
 * Shared checks of the value objects (contract §4.1). Never trims: a value is valid as given or refused.
 *
 * @internal
 */
final class Guard
{
    /** Recipient, context, category and channel keys. */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]{0,31}$/';

    /** Notification type keys, e.g. `invitation_accepted` or `issue.assigned` (at most 64 characters). */
    public const TYPE_PATTERN = '/^(?=.{1,64}$)[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/';

    /** Longest local URL path. */
    public const MAX_URL_LENGTH = 500;

    /**
     * Refuses a key that does not match {@see self::KEY_PATTERN}.
     */
    public static function key(string $field, string $value): string
    {
        if (preg_match(self::KEY_PATTERN, $value) !== 1) {
            throw new InvalidNotificationArgumentException(sprintf('%s must match ^[a-z][a-z0-9_]{0,31}$.', $field));
        }

        return $value;
    }

    /**
     * Refuses a type key that does not match {@see self::TYPE_PATTERN}.
     */
    public static function type(string $field, string $value): string
    {
        if (preg_match(self::TYPE_PATTERN, $value) !== 1) {
            throw new InvalidNotificationArgumentException(sprintf('%s must be a type key such as "issue.assigned" (lower-case segments separated by dots, at most 64 characters).', $field));
        }

        return $value;
    }

    /**
     * Refuses an identifier that is empty, longer than $max characters, not UTF-8 or holds control characters.
     */
    public static function identifier(string $field, string $value, int $max): string
    {
        if ($value === '' || !mb_check_encoding($value, 'UTF-8') || mb_strlen($value, 'UTF-8') > $max || preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
            throw new InvalidNotificationArgumentException(sprintf('%s must be 1 to %d UTF-8 characters without control characters.', $field, $max));
        }

        return $value;
    }

    /**
     * Whether $url is a path on this host: starts with `/`, not `//`, no backslash, no control characters, at most
     * {@see self::MAX_URL_LENGTH} bytes (Beacon's `Notification::isLocalPath`, contract §6.2).
     */
    public static function isLocalPath(string $url): bool
    {
        return $url !== ''
            && strlen($url) <= self::MAX_URL_LENGTH
            && str_starts_with($url, '/')
            && !str_starts_with($url, '//')
            && !str_contains($url, '\\')
            && mb_check_encoding($url, 'UTF-8')
            && preg_match('/[\x00-\x1F\x7F]/', $url) !== 1;
    }
}
