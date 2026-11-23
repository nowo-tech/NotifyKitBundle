<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Integration\Database;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Schema\Table;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Domain\StoredPreference;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\DedupeRecord;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\NotificationRecord;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\PreferenceRecord;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\NotifyKitMetadataListener;
use Nowo\NotifyKitBundle\Infrastructure\Schema\SchemaChecker;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\Host;
use Nowo\NotifyKitBundle\Tests\Integration\Kernel\KernelTestCase;

use function array_keys;
use function ksort;
use function sort;
use function strtolower;

/**
 * Mapping (contract §5.1): the XML mapping of the plain domain classes and the attribute twins give the same tables;
 * the prefix applies to the tables and the index names; MySQL gets binary collations on every identifier column.
 */
final class SchemaTest extends KernelTestCase
{
    private ?string $binaryCollation = null;

    public function testXmlAndAttributeMappingsGiveTheSameTables(): void
    {
        $xml       = $this->table(['doctrine' => ['mapping' => 'xml']], Notification::class);
        $attribute = $this->table(['doctrine' => ['mapping' => 'attribute']], NotificationRecord::class);

        self::assertSame('nowo_notify_notification', $xml->getName());
        self::assertSame($this->describe($xml), $this->describe($attribute));
        $indexes = array_keys($this->describe($xml)['indexes']);
        sort($indexes);
        self::assertSame(['idx_nowo_notify_notification_context', 'idx_nowo_notify_notification_created', 'idx_nowo_notify_notification_inbox', 'idx_nowo_notify_notification_repeat', 'primary'], $indexes);

        self::assertSame(
            $this->describe($this->table(['doctrine' => ['mapping' => 'xml']], DedupeMarker::class)),
            $this->describe($this->table(['doctrine' => ['mapping' => 'attribute']], DedupeRecord::class)),
        );

        $preference = $this->describe($this->table(['doctrine' => ['mapping' => 'xml']], StoredPreference::class));
        self::assertSame($preference, $this->describe($this->table(['doctrine' => ['mapping' => 'attribute']], PreferenceRecord::class)));
        self::assertSame([['recipient_type', 'recipient_id', 'context_key', 'category', 'channel'], true, false], $preference['indexes']['uniq_nowo_notify_preference_cell']);
    }

    public function testPreferenceCellColumnsAreBinaryOnMySql(): void
    {
        $table = $this->table([], StoredPreference::class);
        foreach (['id', 'recipient_type', 'recipient_id', 'context_key', 'category', 'channel', 'scope'] as $column) {
            self::assertSame($this->binaryCollation, $table->getColumn($column)->getPlatformOptions()['collation'] ?? null, $column);
        }
    }

    public function testIdentifierColumnsAreBinaryOnMySqlAndExactElsewhere(): void
    {
        $table = $this->table(['doctrine' => ['table_prefix' => '']], Notification::class);
        self::assertSame('notify_notification', $table->getName());

        // MySQL 8.4 (utf8mb4_0900_bin) and MariaDB 11.4 (utf8mb4_nopad_bin) in CI: binary NO PAD, so '42' and '42 ' differ (audit QL-M3).
        foreach (['id', 'recipient_type', 'recipient_id', 'type', 'category', 'actor_type', 'actor_id', 'context_type', 'context_id', 'target'] as $column) {
            $actual = $table->getColumn($column)->getPlatformOptions()['collation'] ?? null;
            self::assertSame($this->binaryCollation, $actual, $column . ': PostgreSQL and SQLite compare exactly by default');
        }
        self::assertNull($table->getColumn('url')->getPlatformOptions()['collation'] ?? null, 'the URL is not an identifier');
    }

    public function testTheSchemaCheckNeedsTheMappingOnTheConfiguredEntityManager(): void
    {
        $host     = Host::standard();
        $kernel   = $this->bootKernel($host->bundleConfig(['doctrine' => ['auto_mapping' => false]]), Host::databaseUrl(), $host->configure());
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $checker = new SchemaChecker($doctrine, 'default', 'nowo_');
        self::assertSame(['nowo_notify_notification', 'nowo_notify_dedupe', 'nowo_notify_preference'], $checker->tables());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the bundle mapping is not registered on the entity manager "default"');
        $checker->differences();
    }

    /**
     * @param array<string, mixed> $override
     * @param class-string $class
     */
    private function table(array $override, string $class): Table
    {
        $host     = Host::standard();
        $kernel   = $this->bootKernel($host->bundleConfig($override), Host::databaseUrl(), $host->configure());
        $doctrine = $kernel->getContainer()->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);
        $em = $doctrine->getManager();
        self::assertInstanceOf(EntityManagerInterface::class, $em);
        $platform              = $em->getConnection()->getDatabasePlatform();
        $this->binaryCollation = $platform instanceof AbstractMySQLPlatform ? NotifyKitMetadataListener::collation($platform) : null;
        foreach ((new SchemaTool($em))->getSchemaFromMetadata([$em->getClassMetadata($class)])->getTables() as $table) {
            return $table;
        }

        throw new LogicException('no table');
    }

    /**
     * @return array{columns: array<string, mixed>, indexes: array<string, mixed>}
     */
    private function describe(Table $table): array
    {
        $columns = [];
        foreach ($table->getColumns() as $column) {
            $columns[strtolower($column->getName())] = [$column->getType()::class, $column->getLength(), $column->getNotnull(), $column->getPlatformOptions()];
        }
        ksort($columns);
        $indexes = [];
        foreach ($table->getIndexes() as $name => $index) {
            $indexes[strtolower((string) $name)] = [$index->getColumns(), $index->isUnique(), $index->isPrimary()];
        }
        ksort($indexes);

        return ['columns' => $columns, 'indexes' => $indexes];
    }
}
