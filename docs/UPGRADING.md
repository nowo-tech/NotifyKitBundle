# Upgrading

## Table of contents

- [Unreleased](#unreleased)
- [To 0.1.1](#to-011)
- [To 0.1.0](#to-010)

## Unreleased

## To 0.1.1

From **0.1.0**:

```bash
composer update nowo-tech/notify-kit-bundle
```

Nothing yet.

## To 0.1.0

S1 core inbox, released 2026-11-15. First release with runtime code. With `enabled: true`:

- **DoctrineBundle is required** (the container refuses to build without it).
- **Two new tables**: `<prefix>notify_notification` and `<prefix>notify_dedupe` (default prefix `nowo_`). Generate a
  migration (`doctrine:migrations:diff`) and compare it with the reference DDL in
  [INSTALLATION.md](INSTALLATION.md#reference-ddl); then `php bin/console nowo:notify-kit:schema-check`.
- On MySQL / MariaDB the identifier columns use `utf8mb4_0900_bin` (MySQL 8) or `utf8mb4_nopad_bin` (MariaDB), binary
  NO PAD; MySQL 5.7 gets `utf8mb4_bin`.
- Joined events (a `notify()` inside your transaction) run after your commit at the next safe point, at the latest at
  the request / command / message boundary.
- MariaDB: create the tables from the MariaDB reference DDL (`utf8mb4_nopad_bin`), not the MySQL 8 one; see the known
  issues of 0.1.0 in the [CHANGELOG](CHANGELOG.md).
- **New configuration key** `doctrine.transaction_runner` (default `null`).
- `access.default_permission` now needs `access.checker`, like a type with a permission (deny by default).
- `InboxInterface` is bundle-implemented: do not implement it in your application, it gains methods in S2.

Every schema change in 1.x is additive and will be listed here with its DDL.
