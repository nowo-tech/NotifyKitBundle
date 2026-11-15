<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;

/**
 * DDD host: XML-mapped plain `Member` on a second entity manager `ddd`, `doctrine.auto_mapping: false` with the bundle's
 * XML registered by the host, its own recipient resolver and access checker, recipient type `member`, prefix `ddd_`.
 */
final class DddHostTest extends CoreFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::ddd();
    }
}
