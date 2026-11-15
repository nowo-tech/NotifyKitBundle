<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures\Standard;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Standard host user: an attribute-mapped entity with a string id.
 */
#[ORM\Entity]
#[ORM\Table(name: 'app_user')]
class User
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 128)]
        public string $id,
        #[ORM\Column(type: Types::STRING, length: 64)]
        public string $name = 'someone',
    ) {
    }
}
