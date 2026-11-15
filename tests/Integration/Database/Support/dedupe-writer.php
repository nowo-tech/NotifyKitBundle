<?php

declare(strict_types=1);

/*
 * One concurrent writer of LoadReadinessTest::testConcurrentDedupeClaimsNeverFailAndOneWins: boots the test kernel
 * (cache warmed by the parent), then sends N requests whose dedupe keys every writer shares, and prints how many it
 * claimed. An uncaught failure exits non-zero with the error on stderr.
 *
 * Usage: php dedupe-writer.php <kernel id> <database url> <writer> <keys>
 */

use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $id, $url, $writer, $keys] = $argv;
$host                         = Host::standard();
$kernel                       = new TestKernel($id, $host->bundleConfig(), $url, $host->configure());
$kernel->boot();
$notifier = $kernel->getContainer()->get(NotifierInterface::class);
assert($notifier instanceof NotifierInterface);

$claimed = 0;
for ($i = 0; $i < (int) $keys; ++$i) {
    $result = $notifier->notify(new NotificationRequest('invitation_accepted', [new RecipientRef('user', 'w' . $writer)], null, null, 'k:' . $i, '/a', dedupeKey: 'key-' . $i));
    $claimed += $result->deduplicated ? 0 : 1;
}
echo 'claimed=' . $claimed;
$kernel->shutdown();
