<?php

declare(strict_types=1);

/*
 * One concurrent reader of LoadReadinessTest: boots the test kernel (cache warmed by the parent), then marks every
 * row of user `a` read N times while writers keep repeating. A TransientStorageFailure (409 + CONFLICT_001 at an edge)
 * is retried like a client would. Prints `marked=<n> conflicts=<n>`; any other failure exits non-zero.
 *
 * Usage: php mark-reader.php <kernel id> <database url> <rounds>
 */

use Nowo\NotifyKitBundle\Contract\InboxInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $id, $url, $rounds] = $argv;
$host                  = Host::standard();
$kernel                = new TestKernel($id, $host->bundleConfig(), $url, $host->configure());
$kernel->boot();
$inbox = $kernel->getContainer()->get(InboxInterface::class);
assert($inbox instanceof InboxInterface);

$marked    = 0;
$conflicts = 0;
for ($i = 0; $i < (int) $rounds; ++$i) {
    try {
        $marked += $inbox->markAllRead(new RecipientRef('user', 'a'));
    } catch (TransientStorageFailure) {
        ++$conflicts;
    }
}
echo 'marked=' . $marked . ' conflicts=' . $conflicts;
$kernel->shutdown();
