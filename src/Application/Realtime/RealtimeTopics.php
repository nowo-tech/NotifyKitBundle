<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Application\Realtime;

use Nowo\NotifyKitBundle\Contract\RecipientRef;

use function bin2hex;
use function hash_hmac;
use function substr;

/**
 * Mercure topics of the bell (contract §6.5, Q-O11): `notify-kit/r/<hmac>` where `hmac` is the first 16 bytes (hex) of
 * `HMAC-SHA256(kernel.secret, recipient key)`, so a topic never reveals a person's id. The host adds
 * {@see self::subscribeClaimFor()} to the subscriber JWT it gives that person (the bell's `mercure()` call does it when
 * MercureBundle is installed).
 *
 * Stateless (worker mode): the secret is compile-time configuration.
 */
final readonly class RealtimeTopics
{
    /** Prefix of every topic. */
    public const PREFIX = 'notify-kit/r/';

    /**
     * @param string $secret `kernel.secret`
     */
    public function __construct(private string $secret)
    {
    }

    /**
     * The private topic of $recipient.
     */
    public function topicFor(RecipientRef $recipient): string
    {
        return self::PREFIX . bin2hex(substr(hash_hmac('sha256', $recipient->key(), $this->secret, true), 0, 16));
    }

    /**
     * The `mercure.subscribe` claim that lets $recipient (and only them) receive their topic.
     */
    public function subscribeClaimFor(RecipientRef $recipient): string
    {
        return $this->topicFor($recipient);
    }
}
