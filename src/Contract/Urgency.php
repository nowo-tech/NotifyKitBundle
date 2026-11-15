<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * How urgent a notification is. From S4, `High` may bypass quiet hours when the person allows it.
 */
enum Urgency: string
{
    case Low    = 'low';
    case Normal = 'normal';
    case High   = 'high';
}
