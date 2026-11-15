# Installation

## Table of contents

- [Requirements](#requirements)
- [Install](#install)
- [Enable the bundle](#enable-the-bundle)
- [Database](#database)
- [Next steps](#next-steps)

## Requirements

- PHP 8.2 to 8.5 (Symfony 8.x needs PHP 8.4+).
- Symfony 7.4, 8.0 or 8.1 (CI covers 7.4 and 8.1).
- Doctrine DBAL 3.9+ or 4, Doctrine ORM 3.3+ (mapping only) and DoctrineBundle; MySQL 8 / MariaDB 10.6+, PostgreSQL 13+
  or SQLite.
- The package is built slice by slice (S1, core inbox, is the first tag 0.1.0): see [ROADMAP.md](ROADMAP.md) before you
  depend on it.

## Install

```bash
composer require nowo-tech/notify-kit-bundle
```

With Symfony Flex the recipe registers the bundle and copies `config/packages/nowo_notify_kit.yaml` with
`enabled: false`. Without Flex, register it yourself:

```php
// config/bundles.php
return [
    // ...
    Nowo\NotifyKitBundle\NowoNotifyKitBundle::class => ['all' => true],
];
```

## Enable the bundle

Nothing is registered while `nowo_notify_kit.enabled` is false (the default); the bundle only validates its
configuration and publishes these container parameters:

| Parameter | Value |
| --- | --- |
| `%nowo_notify_kit.enabled%` | `enabled` |
| `%nowo_notify_kit.api_prefix%` | `api.prefix` (default `/api`): use it in `security.yaml` patterns so they follow one setting |
| `%nowo_notify_kit.table_prefix%` | `doctrine.table_prefix` (default `nowo_`) |
| `%nowo_notify_kit.config%` | the processed configuration |

With `enabled: true` the bundle needs DoctrineBundle (the container refuses to build without it) and registers the S1
core: `NotifierInterface`, `InboxInterface`, `TypeRegistryInterface`, the access checker and recipient resolver
defaults, the commands `nowo:notify-kit:prune` and `nowo:notify-kit:schema-check`, and, with symfony/messenger, the
`PruneNotificationsMessage` handler. See [CONFIGURATION.md](CONFIGURATION.md) for every key and [USAGE.md](USAGE.md) for
the calls.

## Database

The bundle owns its tables and maps them in XML by default (plain domain classes, referenced by id; the attribute
twins give the same schema with `doctrine.mapping: attribute`). It ships **no Doctrine migration classes**: run
`php bin/console doctrine:migrations:diff` after installing or upgrading, and compare the migration with the reference
DDL below. Then check the live tables:

```bash
php bin/console nowo:notify-kit:schema-check   # exit 0 = the tables match the mapping; 1 = prints the aligning SQL
```

| Table (default prefix) | Holds |
| --- | --- |
| `nowo_notify_notification` | The inbox: one row per recipient (coalesced repeats), UUIDv7 ids |
| `nowo_notify_dedupe` | Dedupe keys of `NotificationRequest::$dedupeKey` (sha256 of the key, 24 hours) |

Identifier columns (ids, recipient / actor / context references, type, category, target) use a binary collation on
MySQL / MariaDB (`ascii_bin` / `utf8mb4_bin`), so ids compare exactly (`usera` is not `UserA`); PostgreSQL and SQLite
compare exactly by default. The prefix also applies to the index names.

DDD hosts set `doctrine.auto_mapping: false` and register the bundle's XML mapping on their own entity manager (see
[USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-application)).

### Reference DDL

Generated from the mapping by `tests/Integration/Database/ReferenceDdlTest.php` (DBAL 4, ORM 3; `NOWO_PRINT_DDL=1`
prints it), default prefix.

MySQL 8.4 / MariaDB:

<!-- ddl:mysql -->
```sql
CREATE TABLE nowo_notify_notification (recipient_type VARCHAR(32) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, recipient_id VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, type VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, category VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, actor_type VARCHAR(32) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, actor_id VARCHAR(128) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_bin`, context_type VARCHAR(32) CHARACTER SET ascii DEFAULT NULL COLLATE `ascii_bin`, context_id VARCHAR(64) CHARACTER SET utf8mb4 DEFAULT NULL COLLATE `utf8mb4_bin`, target VARCHAR(128) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_bin`, url VARCHAR(500) NOT NULL, params JSON NOT NULL, repeats INT NOT NULL, first_created_at DATETIME NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, id VARCHAR(36) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_nowo_notify_notification_inbox (recipient_type, recipient_id, read_at, created_at, id), INDEX idx_nowo_notify_notification_repeat (recipient_type, recipient_id, type, target), INDEX idx_nowo_notify_notification_context (context_type, context_id), INDEX idx_nowo_notify_notification_created (created_at), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4;
CREATE TABLE nowo_notify_dedupe (expires_at DATETIME NOT NULL, key_hash VARCHAR(64) CHARACTER SET ascii NOT NULL COLLATE `ascii_bin`, INDEX idx_nowo_notify_dedupe_expires (expires_at), PRIMARY KEY (key_hash)) DEFAULT CHARACTER SET utf8mb4;
```

PostgreSQL 16 / 17:

<!-- ddl:postgresql -->
```sql
CREATE TABLE nowo_notify_notification (recipient_type VARCHAR(32) NOT NULL, recipient_id VARCHAR(128) NOT NULL, type VARCHAR(64) NOT NULL, category VARCHAR(64) NOT NULL, actor_type VARCHAR(32) DEFAULT NULL, actor_id VARCHAR(128) DEFAULT NULL, context_type VARCHAR(32) DEFAULT NULL, context_id VARCHAR(64) DEFAULT NULL, target VARCHAR(128) NOT NULL, url VARCHAR(500) NOT NULL, params JSON NOT NULL, repeats INT NOT NULL, first_created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, read_at TIMESTAMP(0) WITHOUT TIME ZONE DEFAULT NULL, id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_nowo_notify_notification_inbox ON nowo_notify_notification (recipient_type, recipient_id, read_at, created_at, id);
CREATE INDEX idx_nowo_notify_notification_repeat ON nowo_notify_notification (recipient_type, recipient_id, type, target);
CREATE INDEX idx_nowo_notify_notification_context ON nowo_notify_notification (context_type, context_id);
CREATE INDEX idx_nowo_notify_notification_created ON nowo_notify_notification (created_at);
CREATE TABLE nowo_notify_dedupe (expires_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, key_hash VARCHAR(64) NOT NULL, PRIMARY KEY (key_hash));
CREATE INDEX idx_nowo_notify_dedupe_expires ON nowo_notify_dedupe (expires_at);
```

SQLite:

<!-- ddl:sqlite -->
```sql
CREATE TABLE nowo_notify_notification (recipient_type VARCHAR(32) NOT NULL, recipient_id VARCHAR(128) NOT NULL, type VARCHAR(64) NOT NULL, category VARCHAR(64) NOT NULL, actor_type VARCHAR(32) DEFAULT NULL, actor_id VARCHAR(128) DEFAULT NULL, context_type VARCHAR(32) DEFAULT NULL, context_id VARCHAR(64) DEFAULT NULL, target VARCHAR(128) NOT NULL, url VARCHAR(500) NOT NULL, params CLOB NOT NULL, repeats INTEGER NOT NULL, first_created_at DATETIME NOT NULL, created_at DATETIME NOT NULL, read_at DATETIME DEFAULT NULL, id VARCHAR(36) NOT NULL, PRIMARY KEY (id));
CREATE INDEX idx_nowo_notify_notification_inbox ON nowo_notify_notification (recipient_type, recipient_id, read_at, created_at, id);
CREATE INDEX idx_nowo_notify_notification_repeat ON nowo_notify_notification (recipient_type, recipient_id, type, target);
CREATE INDEX idx_nowo_notify_notification_context ON nowo_notify_notification (context_type, context_id);
CREATE INDEX idx_nowo_notify_notification_created ON nowo_notify_notification (created_at);
CREATE TABLE nowo_notify_dedupe (expires_at DATETIME NOT NULL, key_hash VARCHAR(64) NOT NULL, PRIMARY KEY (key_hash));
CREATE INDEX idx_nowo_notify_dedupe_expires ON nowo_notify_dedupe (expires_at);
```

## Next steps

- [CONFIGURATION.md](CONFIGURATION.md): the full tree.
- [USAGE.md](USAGE.md): the integration paths (standard and DDD).
- [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): worker mode with the kernel kept alive.
