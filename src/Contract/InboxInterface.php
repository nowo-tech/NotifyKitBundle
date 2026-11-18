<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\TransactionContract\TransientStorageFailure;

/**
 * The inbox (contract §4.2). Implemented by the bundle only: do not implement it in a host, it gains the read and row
 * methods (unread count, keyset page, mark read / unread / all) with the visibility re-check in S2.
 */
interface InboxInterface
{
    /**
     * Deletes every inbox row of a context (no foreign key: the host calls it when a project or board is deleted,
     * contract Q-B6).
     *
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 409 +
     *                                 CONFLICT_001, or retry); the chunks already deleted stay deleted
     *
     * @return int rows deleted
     */
    public function forgetContext(ContextRef $context): int;
}
