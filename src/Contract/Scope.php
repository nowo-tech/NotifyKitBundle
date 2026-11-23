<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Who a channel lets through for a category (contract §4.1, Beacon's member-alert scope): every notification, or only
 * those where the person is among the request's `involved` people (assignee, mentioned…).
 */
enum Scope: string
{
    /** Every notification of the category. */
    case All = 'all';

    /** Only notifications whose request lists the person in `involved`. */
    case Involved = 'involved';
}
