<?php

declare(strict_types=1);

namespace App\Notify;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The demo people are Symfony in-memory users: `user:<identifier>` (a DDD-style host resolver, no entity).
 */
final readonly class DemoRecipientResolver implements RecipientResolverInterface
{
    /** People who can sign in. */
    public const PEOPLE = ['alice', 'bob'];

    /** Display names (Carol is a teammate who never signs in: she only acts). */
    public const NAMES = ['alice' => 'Alice', 'bob' => 'Bob', 'carol' => 'Carol'];

    public function recipientOf(object $user): RecipientRef
    {
        if (!$user instanceof UserInterface || !\in_array($user->getUserIdentifier(), self::PEOPLE, true)) {
            throw new InvalidNotificationArgumentException('Not a demo person.');
        }

        return new RecipientRef('user', $user->getUserIdentifier());
    }

    public function userOf(RecipientRef $recipient): ?object
    {
        return $recipient->type === 'user' && isset(self::NAMES[$recipient->id]) ? new InMemoryUser(self::NAMES[$recipient->id], null) : null;
    }
}
