<?php

declare(strict_types=1);

namespace Nowo\NotifyKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;

/**
 * In-memory SQLite connection with the bundle tables, for unit tests. `$caseInsensitive` maps the identifier columns
 * with `COLLATE NOCASE`, the misconfiguration the PHP `===` re-check guards against (audit LQ).
 */
final class Sqlite
{
    public static function connection(bool $caseInsensitive = false): Connection
    {
        $connection = DriverManager::getConnection(['driver' => 'pdo_sqlite', 'memory' => true]);
        $ci         = $caseInsensitive ? ' COLLATE NOCASE' : '';
        $connection->executeStatement('CREATE TABLE nowo_notify_notification (id VARCHAR(36) PRIMARY KEY, recipient_type VARCHAR(32) NOT NULL, recipient_id VARCHAR(128)' . $ci . ' NOT NULL, type VARCHAR(64) NOT NULL, category VARCHAR(64) NOT NULL, actor_type VARCHAR(32), actor_id VARCHAR(128), context_type VARCHAR(32), context_id VARCHAR(64)' . $ci . ', target VARCHAR(128) NOT NULL, url VARCHAR(500) NOT NULL, params CLOB, repeats INTEGER NOT NULL, first_created_at DATETIME NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME)');
        $connection->executeStatement('CREATE TABLE nowo_notify_dedupe (key_hash VARCHAR(64) PRIMARY KEY, expires_at DATETIME NOT NULL)');

        return $connection;
    }
}
