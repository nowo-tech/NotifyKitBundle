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
- The package is in its scaffold stage (S0): see [ROADMAP.md](ROADMAP.md) before you depend on it.

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

Nothing is registered while `nowo_notify_kit.enabled` is false (the default). In S0 the bundle only validates its
configuration and publishes these container parameters:

| Parameter | Value |
| --- | --- |
| `%nowo_notify_kit.enabled%` | `enabled` |
| `%nowo_notify_kit.api_prefix%` | `api.prefix` (default `/api`): use it in `security.yaml` patterns so they follow one setting |
| `%nowo_notify_kit.table_prefix%` | `doctrine.table_prefix` (default `nowo_`) |
| `%nowo_notify_kit.config%` | the processed configuration |

See [CONFIGURATION.md](CONFIGURATION.md) for every key.

## Database

The bundle owns its tables and maps them in XML by default (plain domain classes, referenced by id). It ships **no
Doctrine migration classes**: standard hosts run `php bin/console doctrine:migrations:diff` after an upgrade that adds
or changes a table, and check the result against the reference DDL in this file (added with the first table, S1).

Identifier columns (owner, recipient, actor ids) use a binary collation (`utf8mb4_bin`) on MySQL / MariaDB, so ids
compare exactly; PostgreSQL and SQLite compare exactly by default.

DDD hosts set `doctrine.auto_mapping: false` and register the bundle's XML mapping on their own entity manager (see
[USAGE.md](USAGE.md#using-it-from-a-ddd--hexagonal-application)).

## Next steps

- [CONFIGURATION.md](CONFIGURATION.md): the full tree.
- [USAGE.md](USAGE.md): the integration paths (standard and DDD).
- [FRANKENPHP-WORKER-AUDIT.md](FRANKENPHP-WORKER-AUDIT.md): worker mode with the kernel kept alive.
