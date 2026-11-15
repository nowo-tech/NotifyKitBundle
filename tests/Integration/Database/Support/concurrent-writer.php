<?php

declare(strict_types=1);

/*
 * One concurrent writer of LoadReadinessTest: boots the test kernel (cache warmed by the parent), then sends the same
 * repeat N times. Exit code 0 when every call returned; an uncaught failure exits non-zero with the error on stderr.
 *
 * Usage: php concurrent-writer.php <kernel id> <database url> <repeats>
 */

use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $id, $url, $repeats] = $argv;
$host                   = Host::standard();
$kernel                 = new TestKernel($id, $host->bundleConfig(), $url, $host->configure());
$kernel->boot();
$notifier = $kernel->getContainer()->get(NotifierInterface::class);
assert($notifier instanceof NotifierInterface);

// An uncaught failure ends the script with a non-zero exit code and the error on stderr.
for ($i = 0; $i < (int) $repeats; ++$i) {
    $notifier->notify(new NotificationRequest('invitation_accepted', [new RecipientRef('user', 'a')], null, null, 'invite:race', '/a'));
}
$kernel->shutdown();
