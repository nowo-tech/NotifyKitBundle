<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\TransactionContract\TransientStorageFailure;

/**
 * The one entry point of producers (contract §4.2): `notify(new NotificationRequest(...))`.
 *
 * S1 writes the in-app row (the bell) for every recipient that is not the actor and may open the target, coalescing a
 * repeat of the same type and target within `inbox.repeat_window_seconds` into the unread row. Out-of-band channels
 * (mail, push, Slack) arrive with S4.
 */
interface NotifierInterface
{
    /**
     * Writes the rows in one transaction of the bundle connection. Inside a host transaction on that connection the
     * rows join it (they disappear with a rollback) and the events wait for the outermost commit.
     *
     * Never throws for a refused recipient: it is skipped and logged at info level.
     *
     * @throws InvalidNotificationArgumentException more recipients than `fan_out.max_recipients`
     * @throws TransientStorageFailure a deadlock, lock wait timeout or serialization failure (answer 503 + Retry-After,
     *                                 or retry); nothing was written
     */
    public function notify(NotificationRequest $request): NotifyResult;
}
