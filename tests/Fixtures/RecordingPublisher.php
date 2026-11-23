<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Nowo\NotifyKitBundle\Contract\RealtimePublisherInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use RuntimeException;

/**
 * Host realtime publisher of the tests: records every announcement (`recipient key => unread`, in order); fails while
 * `$fail` is set.
 */
final class RecordingPublisher implements RealtimePublisherInterface
{
    /** @var list<array{string, int}> */
    public array $published = [];

    public bool $fail = false;

    public function publishUnread(RecipientRef $recipient, int $unread): void
    {
        if ($this->fail) {
            throw new RuntimeException('hub down');
        }
        $this->published[] = [$recipient->key(), $unread];
    }

    public function topicFor(RecipientRef $recipient): string
    {
        return 'host/' . $recipient->id;
    }
}
