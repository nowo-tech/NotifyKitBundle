<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\Persistence\ObjectManager;
use LogicException;
use Nowo\NotifyKitBundle\Contract\Exception\InvalidNotificationArgumentException;
use Nowo\NotifyKitBundle\Contract\RecipientRef;
use Nowo\NotifyKitBundle\Infrastructure\Recipient\DoctrineRecipientResolver;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use Stringable;

/**
 * Identifier shapes of the default recipient resolver (int, Stringable, missing) and an unmapped user class.
 */
final class RecipientResolverTest extends TestCase
{
    public function testIdentifierShapes(): void
    {
        self::assertSame('42', $this->resolver(42)->recipientOf(new User('ignored'))->id);
        $uuid = new class implements Stringable {
            public function __toString(): string
            {
                return '0193a1b2-0000-7000-8000-000000000001';
            }
        };
        self::assertSame('0193a1b2-0000-7000-8000-000000000001', $this->resolver($uuid)->recipientOf(new User('ignored'))->id);

        $this->expectException(InvalidNotificationArgumentException::class);
        $this->expectExceptionMessage('no identifier yet');
        $this->resolver(null)->recipientOf(new User('ignored'));
    }

    public function testAnUnmappedClassIsExplained(): void
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn(null);

        $this->expectException(LogicException::class);
        (new DoctrineRecipientResolver($registry, User::class))->userOf(new RecipientRef('user', 'a'));
    }

    private function resolver(mixed $id): DoctrineRecipientResolver
    {
        $metadata = $this->createStub(ClassMetadata::class);
        $metadata->method('getIdentifierValues')->willReturn(['id' => $id]);
        $manager = $this->createStub(ObjectManager::class);
        $manager->method('getClassMetadata')->willReturn($metadata);
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($manager);

        return new DoctrineRecipientResolver($registry, User::class);
    }
}
