# Upgrading

## Table of contents

- [0.1.0 (S1 core inbox, unreleased)](#010-s1-core-inbox-unreleased)

## 0.1.0 (S1 core inbox, unreleased)

First release with runtime code. With `enabled: true`:

- **DoctrineBundle is required** (the container refuses to build without it).
- **Two new tables**: `<prefix>notify_notification` and `<prefix>notify_dedupe` (default prefix `nowo_`). Generate a
  migration (`doctrine:migrations:diff`) and compare it with the reference DDL in
  [INSTALLATION.md](INSTALLATION.md#reference-ddl); then `php bin/console nowo:notify-kit:schema-check`.
- **New configuration key** `doctrine.transaction_runner` (default `null`).
- `access.default_permission` now needs `access.checker`, like a type with a permission (deny by default).
- `InboxInterface` is bundle-implemented: do not implement it in your application, it gains methods in S2.

Every schema change in 1.x is additive and will be listed here with its DDL.
