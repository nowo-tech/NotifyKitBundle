<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
use Doctrine\DBAL\Platforms\MariaDBPlatform;
use Doctrine\DBAL\Platforms\MySQL80Platform;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use LogicException;
use Nowo\NotifyKitBundle\Domain\DedupeMarker;
use Nowo\NotifyKitBundle\Domain\Notification;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\DedupeRecord;
use Nowo\NotifyKitBundle\Infrastructure\Doctrine\Entity\NotificationRecord;
use Throwable;

use function in_array;
use function is_string;
use function str_replace;

/**
 * Applies `doctrine.table_prefix` to the bundle tables and their index names (`idx_<prefix>notify_notification_inbox`,
 * REQ-ORM-002) and, on MySQL / MariaDB, a binary **NO PAD** collation to every identifier column (audit LQ,
 * REQ-SEC-006 rule 3, audit QL-M3): `utf8mb4_0900_bin` on MySQL 8, `utf8mb4_nopad_bin` on MariaDB, so `'42'` and `'42 '`
 * never compare equal. `utf8mb4_bin` (PAD SPACE) is only the fallback of MySQL 5.7, where the PHP `===` re-check and
 * the refusal of edge white space in the reference value objects keep look-alikes apart. PostgreSQL and SQLite compare
 * exactly by default and need nothing.
 *
 * Touches only the bundle's mapped classes, on whichever entity manager maps them (standard and DDD hosts).
 */
final readonly class NotifyKitMetadataListener
{
    /** Unprefixed inbox table. */
    public const NOTIFICATION_TABLE = 'notify_notification';

    /** Unprefixed dedupe table. */
    public const DEDUPE_TABLE = 'notify_dedupe';

    /** Mapped class => unprefixed table. */
    public const TABLES = [
        Notification::class       => self::NOTIFICATION_TABLE,
        NotificationRecord::class => self::NOTIFICATION_TABLE,
        DedupeMarker::class       => self::DEDUPE_TABLE,
        DedupeRecord::class       => self::DEDUPE_TABLE,
    ];

    /** Identifier columns: keys, ids, references, the target and the dedupe hash. */
    public const ID_COLUMNS = ['id', 'recipient_type', 'recipient_id', 'type', 'category', 'actor_type', 'actor_id', 'context_type', 'context_id', 'target', 'key_hash'];

    /**
     * The binary NO PAD collation of the platform: MariaDB `utf8mb4_nopad_bin`, MySQL 8 `utf8mb4_0900_bin`, else the
     * MySQL 5.7 fallback `utf8mb4_bin` (PAD SPACE).
     */
    public static function collation(AbstractMySQLPlatform $platform): string
    {
        return match (true) {
            $platform instanceof MariaDBPlatform => 'utf8mb4_nopad_bin',
            $platform instanceof MySQL80Platform => 'utf8mb4_0900_bin',
            default                              => 'utf8mb4_bin',
        };
    }

    /**
     * @param string $tablePrefix `doctrine.table_prefix`, may be empty
     */
    public function __construct(private string $tablePrefix)
    {
    }

    /**
     * Doctrine `loadClassMetadata` listener.
     */
    public function loadClassMetadata(LoadClassMetadataEventArgs $args): void
    {
        $metadata = $args->getClassMetadata();
        $base     = self::TABLES[$metadata->getName()] ?? null;
        if ($base === null) {
            return;
        }

        $table = ['name' => $this->tablePrefix . $base];
        foreach (['indexes', 'uniqueConstraints'] as $key) {
            $renamed = [];
            foreach ($metadata->table[$key] ?? [] as $name => $definition) {
                $renamed[is_string($name) ? str_replace('_' . $base . '_', '_' . $this->tablePrefix . $base . '_', $name) : $name] = $definition;
            }
            $table[$key] = $renamed;
        }
        $metadata->setPrimaryTable($table);

        try {
            $platform = $args->getObjectManager()->getConnection()->getDatabasePlatform();
        } catch (Throwable $exception) {
            throw new LogicException('nowo_notify_kit needs the database platform to map the identifier columns with a binary collation. Set doctrine.dbal.server_version (or make the database reachable) before warming up the cache.', 0, $exception);
        }
        if (!$platform instanceof AbstractMySQLPlatform) {
            return;
        }
        $collation = self::collation($platform);
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($mapping->columnName, self::ID_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => $collation] + ($mapping->options ?? []);
            }
        }
    }
}
