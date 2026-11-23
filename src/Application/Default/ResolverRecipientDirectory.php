<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Default;

use Nowo\NotifyKitBundle\Contract\RecipientDirectoryInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Stringable;

use function is_callable;
use function is_string;
use function trim;

/**
 * Default directory (`recipient.directory: null`, contract §4.3): reads the host user through the recipient resolver,
 * per call. The name is the user's `__toString()`, the e-mail `getEmail()` and the locale `getLocale()` when the class
 * has them; no avatar, no time zone; every person is active. Without a resolver every answer is null.
 *
 * Stateless (worker mode): nothing is cached; the resolver detaches what it loads.
 */
final readonly class ResolverRecipientDirectory implements RecipientDirectoryInterface
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(private ?RecipientResolverInterface $resolver = null)
    {
    }

    /**
     * {@inheritDoc}
     */
    public function displayName(RecipientRef $recipient): ?string
    {
        $user = $this->resolver?->userOf($recipient);

        return $user instanceof Stringable ? $this->text((string) $user) : null;
    }

    /**
     * No avatars by default.
     */
    public function avatarUrl(RecipientRef $recipient): ?string
    {
        return null;
    }

    /**
     * {@inheritDoc}
     */
    public function email(RecipientRef $recipient): ?string
    {
        return $this->call($recipient, 'getEmail');
    }

    /**
     * {@inheritDoc}
     */
    public function locale(RecipientRef $recipient): ?string
    {
        return $this->call($recipient, 'getLocale');
    }

    /**
     * No time zone by default.
     */
    public function timezone(RecipientRef $recipient): ?string
    {
        return null;
    }

    /**
     * Every person is active by default.
     */
    public function isActive(RecipientRef $recipient): bool
    {
        return true;
    }

    private function call(RecipientRef $recipient, string $method): ?string
    {
        $user     = $this->resolver?->userOf($recipient);
        $callable = [$user, $method];

        return $user !== null && is_callable($callable) ? $this->text($callable()) : null;
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
