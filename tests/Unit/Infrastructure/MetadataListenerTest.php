<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Unit\Infrastructure;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\DBAL\Platforms\MySQL84Platform;
use Doctrine\DBAL\Platforms\MySQLPlatform;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use LogicException;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;
use Nowo\NotifyKitBundle\Tests\Fixtures\Standard\User;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The metadata listener on a MySQL platform (the CI coverage job runs on SQLite): prefix, index names and binary NO PAD
 * collations (audit QL-M3); other classes and platforms untouched.
 */
final class MetadataListenerTest extends TestCase
{
    public function testMySqlGetsBinaryCollationsAndThePrefix(): void
    {
        $metadata = $this->metadata(Notification::class);
        (new NotifyKitMetadataListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->manager(new MySQL80Platform())));

        self::assertSame('app_notify_notification', $metadata->getTableName());
        self::assertArrayHasKey('idx_app_notify_notification_inbox', $metadata->table['indexes'] ?? []);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin'], $metadata->fieldMappings['id']->options);
        self::assertSame(['charset' => 'utf8mb4', 'collation' => 'utf8mb4_0900_bin', 'fixed' => false], $metadata->fieldMappings['recipientId']->options);
        self::assertNull($metadata->fieldMappings['url']->options);
    }

    public function testEveryMySqlFamilyGetsItsBinaryCollation(): void
    {
        self::assertSame('utf8mb4_0900_bin', NotifyKitMetadataListener::collation(new MySQL84Platform()), 'NO PAD');
        self::assertSame('utf8mb4_nopad_bin', NotifyKitMetadataListener::collation(new MariaDBPlatform()), 'NO PAD');
        self::assertSame('utf8mb4_bin', NotifyKitMetadataListener::collation(new MySQLPlatform()), 'MySQL 5.7 fallback (PAD SPACE)');
    }

    public function testOtherPlatformsAndClassesAreLeftAlone(): void
    {
        $metadata = $this->metadata(DedupeMarker::class);
        (new NotifyKitMetadataListener('nowo_'))->loadClassMetadata(new LoadClassMetadataEventArgs($metadata, $this->manager(new PostgreSQLPlatform())));
        self::assertSame('nowo_notify_dedupe', $metadata->getTableName());
        self::assertNull($metadata->fieldMappings['keyHash']->options);

        $other = new ClassMetadata(User::class);
        $other->setPrimaryTable(['name' => 'app_user']);
        (new NotifyKitMetadataListener('nowo_'))->loadClassMetadata(new LoadClassMetadataEventArgs($other, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('app_user', $other->getTableName());
    }

    public function testAnUnreachablePlatformIsExplained(): void
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willThrowException(new RuntimeException('down'));
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('doctrine.dbal.server_version');
        (new NotifyKitMetadataListener('nowo_'))->loadClassMetadata(new LoadClassMetadataEventArgs($this->metadata(Notification::class), $manager));
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable(['name' => $class === Notification::class ? 'notify_notification' : 'notify_dedupe', 'indexes' => ['idx_notify_notification_inbox' => ['columns' => ['recipient_id']], 0 => ['columns' => ['id']]]]);
        if ($class === Notification::class) {
            $metadata->mapField(['fieldName' => 'id', 'columnName' => 'id', 'type' => 'string', 'length' => 36, 'id' => true]);
            $metadata->mapField(['fieldName' => 'recipientId', 'columnName' => 'recipient_id', 'type' => 'string', 'length' => 128, 'options' => ['fixed' => false]]);
            $metadata->mapField(['fieldName' => 'url', 'columnName' => 'url', 'type' => 'string', 'length' => 500]);
        } else {
            $metadata->mapField(['fieldName' => 'keyHash', 'columnName' => 'key_hash', 'type' => 'string', 'length' => 64, 'id' => true]);
        }

        return $metadata;
    }

    private function manager(object $platform): EntityManagerInterface
    {
        $connection = $this->createStub(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn($platform);
        $manager = $this->createStub(EntityManagerInterface::class);
        $manager->method('getConnection')->willReturn($connection);

        return $manager;
    }
}
