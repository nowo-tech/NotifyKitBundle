<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Read state filter of the inbox (contract §4.2).
 */
enum InboxStatus: string
{
    case Unread = 'unread';
    case Read   = 'read';
}
