<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\ProtocolVersion;
use Symfony\Component\Mercure\Update;

use function is_object;
use function is_string;

/**
 * A Mercure hub of the tests: records every update; the token factory answers a fixed JWT naming the topics.
 */
final class TestHub implements HubInterface, TokenFactoryInterface
{
    /** @var list<Update> */
    public array $updates = [];

    private MockHub $hub;

    public function __construct(string $publicUrl = 'http://localhost/.well-known/mercure')
    {
        $this->hub = new MockHub('https://hub.internal/.well-known/mercure', new StaticTokenProvider('publisher-jwt'), function (Update $update): string {
            $this->updates[] = $update;

            return 'urn:uuid:test';
        }, $this, $publicUrl, 'mercureAuthorization');
    }

    public function getUrl(): string
    {
        return $this->hub->getUrl();
    }

    public function getPublicUrl(): string
    {
        return $this->hub->getPublicUrl();
    }

    public function getProvider(): TokenProviderInterface
    {
        return $this->hub->getProvider();
    }

    public function getFactory(): TokenFactoryInterface
    {
        return $this;
    }

    public function getProtocolVersion(): ProtocolVersion
    {
        return $this->hub->getProtocolVersion();
    }

    public function getCookieName(): string
    {
        return $this->hub->getCookieName();
    }

    public function publish(Update $update): string
    {
        return $this->hub->publish($update);
    }

    /**
     * Compatible with symfony/mercure 0.6 (`$subscribe, $publish, $additionalClaims`) and 0.10 (`$grants`,
     * `$additionalClaims`): the JWT names the subscribed topics.
     *
     * @param array<mixed>|null $subscribe topics (0.6) or Grant objects (0.10)
     * @param array<string, mixed> $additionalClaims
     */
    public function create(?array $subscribe = [], mixed $publish = null, array $additionalClaims = []): string
    {
        $topics = [];
        foreach ($subscribe ?? [] as $grant) {
            foreach (is_object($grant) && property_exists($grant, 'topics') ? (array) $grant->topics : [$grant] as $topic) {
                $topics[] = is_string($topic) ? $topic : '?';
            }
        }

        return 'jwt-for-' . implode(',', $topics);
    }
}
