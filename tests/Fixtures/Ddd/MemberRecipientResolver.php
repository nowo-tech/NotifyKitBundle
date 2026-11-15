<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Ddd;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;

/**
 * DDD host resolver: members are referenced by their id, type `member`; no lookup.
 */
final readonly class MemberRecipientResolver implements RecipientResolverInterface
{
    public function recipientOf(object $user): RecipientRef
    {
        if (!$user instanceof Member) {
            throw new InvalidNotificationArgumentException('Not a member.');
        }

        return new RecipientRef('member', $user->memberId());
    }

    public function userOf(RecipientRef $recipient): ?object
    {
        return $recipient->type === 'member' ? new Member($recipient->id) : null;
    }
}
