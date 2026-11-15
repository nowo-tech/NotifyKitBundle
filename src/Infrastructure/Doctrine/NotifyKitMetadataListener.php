<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Infrastructure\Doctrine;

use Doctrine\DBAL\Platforms\AbstractMySQLPlatform;
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
 * REQ-ORM-002) and, on MySQL / MariaDB, a binary collation to every identifier column (audit LQ): `ascii_bin` for the
 * ascii keys and ids, `utf8mb4_bin` for the recipient, actor and context identifiers and the target. PostgreSQL and
 * SQLite compare exactly by default and need nothing.
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

    /** Columns that hold ascii keys, ids or hashes. */
    public const ASCII_COLUMNS = ['id', 'recipient_type', 'type', 'category', 'actor_type', 'context_type', 'key_hash'];

    /** Columns that hold UTF-8 identifiers. */
    public const UTF8_COLUMNS = ['recipient_id', 'actor_id', 'context_id', 'target'];

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
        foreach ($metadata->fieldMappings as $field => $mapping) {
            if (in_array($mapping->columnName, self::ASCII_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'ascii', 'collation' => 'ascii_bin'] + ($mapping->options ?? []);
            } elseif (in_array($mapping->columnName, self::UTF8_COLUMNS, true)) {
                $metadata->fieldMappings[$field]->options = ['charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin'] + ($mapping->options ?? []);
            }
        }
    }
}
