<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Nowo\NotifyKitBundle\Contract\NotificationRequest;
use Nowo\NotifyKitBundle\Contract\NotifierInterface;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Domain\NotificationStoreInterface;
use Nowo\NotifyKitBundle\Infrastructure\Persistence\AfterCommitQueue;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Worker-test endpoint: `?for=<id>` notifies that user (actor: the other one) and answers what that user's inbox
 * holds now, plus how much after-commit work is held.
 */
final readonly class NotifyController
{
    public function __construct(
        private NotifierInterface $notifier,
        private NotificationStoreInterface $store,
        private AfterCommitQueue $queue,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $for       = (string) $request->query->get('for', 'a');
        $recipient = new RecipientRef('user', $for);
        $result    = $this->notifier->notify(new NotificationRequest('invitation_accepted', [$recipient], new RecipientRef('user', $for === 'a' ? 'b' : 'a'), null, 'invite:' . $request->query->get('n', '0'), '/account'));
        $mine      = [];
        foreach ($this->store->ofRecipient($recipient, 50) as $notification) {
            $mine[] = $notification->recipient()->key();
        }

        return new JsonResponse(['written' => $result->written, 'recipients' => array_values(array_unique($mine)), 'unread' => $this->store->countUnread($recipient), 'held' => $this->queue->heldCount()]);
    }
}
