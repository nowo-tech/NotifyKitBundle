<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Ddd;

/**
 * DDD host user: plain PHP, XML-mapped on the `ddd` entity manager.
 */
class Member
{
    public function __construct(private string $memberId)
    {
    }

    public function memberId(): string
    {
        return $this->memberId;
    }
}
