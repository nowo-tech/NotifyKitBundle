<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;

/**
 * Standard host: attribute `User` entity on the default entity manager, the bundle's XML mapping prepended there, the
 * Doctrine recipient resolver and no access checker (personal rows only).
 */
final class StandardHostTest extends CoreFunctionalTestCase
{
    protected function host(): Host
    {
        return Host::standard();
    }
}
