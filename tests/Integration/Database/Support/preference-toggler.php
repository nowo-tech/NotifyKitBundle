<?php

declare(strict_types=1);

/*
 * One concurrent toggler of PreferencesConcurrencyTest: boots the test kernel (cache warmed by the parent), then flips
 * the same cell N times, each time reading the matrix and saving the opposite with the token it read (optimistic
 * concurrency). A refused (stale) or transient attempt is not counted. Prints the number of saves that went through.
 *
 * Usage: php preference-toggler.php <kernel id> <database url> <toggles>
 */

use Nowo\NotifyKitBundle\Contract\ChannelPreference;
use Nowo\NotifyKitBundle\Contract\Exception\PreferenceConflictException;
use Nowo\NotifyKitBundle\Contract\PreferenceChange;
use Nowo\NotifyKitBundle\Contract\PreferenceChangeSet;
use Nowo\NotifyKitBundle\Contract\PreferenceExpectation;
use Nowo\NotifyKitBundle\Contract\PreferenceKey;
use Nowo\NotifyKitBundle\Contract\PreferencesInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Tests\Integration\Database\PreferencesConcurrencyTest;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\TestKernel;
use Nowo\TransactionContract\TransientStorageFailure;

require dirname(__DIR__, 4) . '/vendor/autoload.php';

[, $id, $url, $toggles] = $argv;
$host                   = Host::standard();
$kernel                 = new TestKernel($id, $host->bundleConfig(PreferencesConcurrencyTest::CONFIG), $url, $host->configure());
$kernel->boot();
$preferences = $kernel->getContainer()->get(PreferencesInterface::class);
assert($preferences instanceof PreferencesInterface);

$person = new RecipientRef('user', 'a');
$key    = PreferenceKey::account('account', 'mail');
$saved  = 0;
for ($i = 0; $i < (int) $toggles; ++$i) {
    $stored = $preferences->matrixFor($person)->stored($key);
    try {
        // Stored = a deviation (off); nothing stored = the default (on). Flip it.
        $preferences->save($person, new PreferenceChangeSet([new PreferenceChange($key, $stored instanceof ChannelPreference, expected: PreferenceExpectation::of($stored))]));
        ++$saved;
    } catch (PreferenceConflictException|TransientStorageFailure) {
        // Someone else flipped it first, or a lock wait: read again.
    }
}
echo $saved;
$kernel->shutdown();
