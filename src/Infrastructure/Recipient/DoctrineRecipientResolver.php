<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Recipient;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Contract\RecipientResolverInterface;
use Stringable;

use function is_int;
use function is_object;
use function is_string;
use function sprintf;

/**
 * Default {@see RecipientResolverInterface} of standard hosts (`recipient.class`): the identifier field of a Doctrine
 * entity, type `recipient.type` (default `user`).
 *
 * `userOf()` returns the instance the host's entity manager already manages, untouched (no refresh: a managed, dirty
 * user is never overwritten, audit QL-L4); a user it had to load is detached again, so a worker never keeps a user
 * loaded for one request. The result is re-checked with `===` (audit LQ).
 */
final readonly class DoctrineRecipientResolver implements RecipientResolverInterface
{
    /**
     * @param class-string $userClass `recipient.class`
     * @param string $idField `recipient.id_field`
     * @param string $type `recipient.type`
     */
    public function __construct(
        private ManagerRegistry $registry,
        private string $userClass,
        private string $idField = 'id',
        private string $type = 'user',
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function recipientOf(object $user): RecipientRef
    {
        if (!$user instanceof $this->userClass) {
            throw new InvalidNotificationArgumentException(sprintf('The user is not a %s.', $this->userClass));
        }
        $manager = $this->registry->getManagerForClass($this->userClass);
        $id      = $this->idString($manager?->getClassMetadata($this->userClass)->getIdentifierValues($user)[$this->idField] ?? null);
        if ($id === null) {
            throw new InvalidNotificationArgumentException('The user has no identifier yet.');
        }

        return new RecipientRef($this->type, $id);
    }

    /**
     * {@inheritDoc}
     */
    public function userOf(RecipientRef $recipient): ?object
    {
        if ($recipient->type !== $this->type) {
            return null;
        }
        $manager = $this->registry->getManagerForClass($this->userClass);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('nowo_notify_kit.recipient.class "%s" is not mapped by a Doctrine ORM entity manager.', $this->userClass));
        }
        $unitOfWork = $manager->getUnitOfWork();
        $before     = $unitOfWork->size();
        $user       = $manager->createQueryBuilder()
            ->select('u')
            ->from($this->userClass, 'u')
            ->where(sprintf('u.%s = :id', $this->idField))
            ->setParameter('id', $recipient->id)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
        if (!is_object($user)) {
            return null;
        }
        if ($unitOfWork->size() > $before) {
            $manager->detach($user);
        }

        return $this->recipientOf($user)->equals($recipient) ? $user : null;
    }

    private function idString(mixed $id): ?string
    {
        return match (true) {
            is_string($id) && $id !== '' => $id,
            is_int($id)                  => (string) $id,
            $id instanceof Stringable    => (string) $id,
            default                      => null,
        };
    }
}
