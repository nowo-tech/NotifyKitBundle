<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;

use function preg_match;

/**
 * Identifier of an inbox row: a UUIDv7 in its canonical lower-case form (36 characters; no enumeration, contract §5.1
 * and Q-O4).
 */
final readonly class NotificationId
{
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D';

    /**
     * @param string $value a canonical lower-case UUIDv7
     */
    public function __construct(public string $value)
    {
        if (!self::isValid($value)) {
            throw new InvalidNotificationArgumentException('NotificationId must be a canonical lower-case UUIDv7.');
        }
    }

    /**
     * Whether $value is a canonical lower-case UUIDv7 (an id taken from a URL is checked with this before any query).
     */
    public static function isValid(string $value): bool
    {
        return preg_match(self::PATTERN, $value) === 1;
    }
}
