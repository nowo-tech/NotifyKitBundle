<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Why a recipient of a request got no inbox row ({@see Event\NotificationSkippedEvent}).
 */
enum SkipReason: string
{
    /** The recipient is the actor: nobody is notified of their own action. */
    case Actor = 'actor';

    /** The recipient's account is inactive (from S2, through the recipient directory). */
    case Inactive = 'inactive';

    /** The recipient may not open the target (deny by default, contract §6.1). */
    case Access = 'access';

    /** The recipient turned the category or channel off (from S3; the in-app row is locked on by default). */
    case Preference = 'preference';

    /** A request with the same dedupe key was accepted in the last 24 hours. */
    case Dedupe = 'dedupe';
}
