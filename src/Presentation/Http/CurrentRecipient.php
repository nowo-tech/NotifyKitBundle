<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Presentation\Http;

use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

/**
 * The logged-in person of the current request and their recipient reference (resolved per call from the token
 * storage, never kept: worker mode). A user the resolver does not support has no inbox.
 *
 * @internal
 */
final readonly class CurrentRecipient
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(
        private ?TokenStorageInterface $tokens,
        private ?RecipientResolverInterface $resolver,
    ) {
    }

    /**
     * The authenticated user, or null.
     */
    public function user(): ?object
    {
        return $this->tokens?->getToken()?->getUser();
    }

    /**
     * The recipient of $user, or null when there is none or the resolver refuses it.
     */
    public function recipientOf(?object $user): ?RecipientRef
    {
        if ($user === null || !$this->resolver instanceof RecipientResolverInterface) {
            return null;
        }
        try {
            return $this->resolver->recipientOf($user);
        } catch (InvalidNotificationArgumentException) {
            return null;
        }
    }
}
