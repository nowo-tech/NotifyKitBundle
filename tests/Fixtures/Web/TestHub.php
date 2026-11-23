<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Web;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\StaticTokenProvider;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\MockHub;
use Symfony\Component\Mercure\Update;

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
        }, $this, $publicUrl);
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

    public function publish(Update $update): string
    {
        return $this->hub->publish($update);
    }

    public function create(?array $subscribe = [], ?array $publish = [], array $additionalClaims = []): string
    {
        return 'jwt-for-' . implode(',', $subscribe ?? []);
    }
}
