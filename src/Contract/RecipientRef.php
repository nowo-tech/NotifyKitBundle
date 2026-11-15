<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * The person a notification is for (or the actor), by reference: a type (`user`, `member`…) and an identifier, never
 * a host entity (ID mode, contract §4.1 and §5.2; same rules as ApiTokenBundle's `OwnerRef`).
 *
 * The identifier is stored in a binary-collated column and compared exactly (`usera` is not `UserA`); it is never
 * trimmed or normalised.
 */
final readonly class RecipientRef
{
    /** Longest identifier, in characters. */
    public const MAX_ID_LENGTH = 128;

    /**
     * @param string $type `^[a-z][a-z0-9_]{0,31}$`
     * @param string $id 1–128 UTF-8 characters, no control characters, no white space at the edges, no invisible characters
     */
    public function __construct(public string $type, public string $id)
    {
        Guard::key('RecipientRef type', $type);
        Guard::reference('RecipientRef id', $id, self::MAX_ID_LENGTH);
    }

    /**
     * `<type>:<id>`.
     */
    public function key(): string
    {
        return $this->type . ':' . $this->id;
    }

    /**
     * Exact comparison of type and identifier (`===`).
     */
    public function equals(self $other): bool
    {
        return $this->type === $other->type && $this->id === $other->id;
    }
}
