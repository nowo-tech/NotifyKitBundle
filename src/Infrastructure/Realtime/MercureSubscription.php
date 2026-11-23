<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Realtime;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Mercure\Authorization;
use Symfony\Component\Mercure\HubInterface;
use Throwable;

/**
 * The browser side of `realtime.mode: mercure`: the hub's public URL for the bell, and the subscriber cookie that
 * lets the current person (and only them) receive their own private topic (MercureBundle's `Authorization`, the same
 * cookie its `mercure()` Twig function sets). Without an `Authorization` service, or with
 * `realtime.subscriber_cookie: false` (no `Authorization` is injected then), the host gives the JWT itself
 * (`RealtimeTopics::subscribeClaimFor()`).
 *
 * There is one subscriber cookie per hub path: the bell's cookie and a cookie the host sets on another request replace
 * each other (symfony/mercure >= 0.10, required through `conflict`, merges the grants set in the same request).
 *
 * Stateless (worker mode): the request comes from the stack per call.
 */
final readonly class MercureSubscription
{
    /**
     * Holds the collaborators (stateless).
     */
    public function __construct(
        private HubInterface $hub,
        private RequestStack $requests,
        private ?Authorization $authorization = null,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * The hub URL the bell subscribes to for $topic, after granting the current request a subscriber cookie for it;
     * null when it could not be prepared (the bell then polls).
     */
    public function hubUrlFor(string $topic): ?string
    {
        try {
            $request = $this->requests->getMainRequest();
            if ($this->authorization instanceof Authorization && $request instanceof Request) {
                // @igor-ignore - Writes the cookie into this request's attributes; Authorization itself keeps no state
                $this->authorization->setCookie($request, [$topic]);
            }

            return $this->hub->getPublicUrl();
        } catch (Throwable $exception) {
            ($this->logger ?? new NullLogger())->warning('nowo_notify_kit: the Mercure subscription of the bell could not be prepared.', ['exception' => $exception::class]);

            return null;
        }
    }
}
