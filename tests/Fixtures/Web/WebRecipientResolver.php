<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;

/**
 * Recipient resolver of the web test host: a logged-in `InMemoryUser` is `user:<identifier>`; the user `nobody` has
 * no inbox (refused).
 */
final readonly class WebRecipientResolver implements RecipientResolverInterface
{
    public function recipientOf(object $user): RecipientRef
    {
        if (!$user instanceof InMemoryUser || $user->getUserIdentifier() === 'nobody') {
            throw new InvalidNotificationArgumentException('Not a person of this host.');
        }

        return new RecipientRef('user', $user->getUserIdentifier());
    }

    public function userOf(RecipientRef $recipient): ?object
    {
        return $recipient->type === 'user' && $recipient->id !== 'ghost' ? new InMemoryUser($recipient->id, null) : null;
    }
}
