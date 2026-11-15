<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * What a notification belongs to, for access checks and (from S3) per-context preferences: e.g. `('project', '42')`
 * or `('board', '<uuid>')`. No foreign key: when the context disappears the host calls
 * {@see InboxInterface::forgetContext()}.
 */
final readonly class ContextRef
{
    /** Longest identifier, in characters. */
    public const MAX_ID_LENGTH = 64;

    /**
     * @param string $type `^[a-z][a-z0-9_]{0,31}$`
     * @param string $id 1–64 UTF-8 characters, no control characters, no white space at the edges, no invisible characters
     */
    public function __construct(public string $type, public string $id)
    {
        Guard::key('ContextRef type', $type);
        Guard::reference('ContextRef id', $id, self::MAX_ID_LENGTH);
    }

    /**
     * `<type>:<id>`, e.g. `project:42`.
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
