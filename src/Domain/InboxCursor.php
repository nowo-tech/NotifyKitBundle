<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Domain;

use DateTimeImmutable;
use DateTimeZone;

use function preg_match;
use function sprintf;

/**
 * Keyset position of an inbox page (newest first: `created_at DESC, id DESC`): the creation time (UTC, seconds) and id
 * of the last row shown, as the opaque string `YmdHis.<uuid>`. An invalid string is no cursor (first page).
 *
 * @internal
 */
final readonly class InboxCursor
{
    private const PATTERN = '/^(\d{14})\.([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})$/D';

    private function __construct(public string $createdAt, public string $id)
    {
    }

    /**
     * The position after $notification.
     */
    public static function after(Notification $notification): self
    {
        return new self($notification->createdAt()->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $notification->id()->value);
    }

    /**
     * Parses a cursor; null when $value is not one.
     */
    public static function parse(?string $value): ?self
    {
        if ($value === null || preg_match(self::PATTERN, $value, $match) !== 1) {
            return null;
        }
        $at = DateTimeImmutable::createFromFormat('!YmdHis', $match[1], new DateTimeZone('UTC'));
        if (!$at instanceof DateTimeImmutable || $at->format('YmdHis') !== $match[1]) {
            return null;
        }

        return new self($at->format('Y-m-d H:i:s'), $match[2]);
    }

    /**
     * The opaque string form.
     */
    public function toString(): string
    {
        $at = new DateTimeImmutable($this->createdAt, new DateTimeZone('UTC'));

        return sprintf('%s.%s', $at->format('YmdHis'), $this->id);
    }
}
