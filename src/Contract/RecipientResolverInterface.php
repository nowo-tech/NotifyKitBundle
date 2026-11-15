<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Contract;

/**
 * Host SPI (`recipient.resolver`): maps the host's user objects to {@see RecipientRef} and back. The default
 * (`recipient.class` set) reads the id field of a Doctrine entity; a DDD host plugs its own.
 */
interface RecipientResolverInterface
{
    /**
     * The reference of a user object of the host.
     *
     * @throws Exception\InvalidNotificationArgumentException the object is not a supported user or has no id yet
     */
    public function recipientOf(object $user): RecipientRef;

    /**
     * The user object of a reference; null when the type is not this resolver's or the user no longer exists.
     */
    public function userOf(RecipientRef $recipient): ?object;
}
