# Upgrading

## Table of contents

- [Unreleased](#unreleased)
  - [Lock conflicts answer 409 + CONFLICT codes](#lock-conflicts-answer-409--conflict-codes)
- [To 0.1.1](#to-011)
- [To 0.1.0](#to-010)

## Unreleased

### Lock conflicts answer 409 + CONFLICT codes

```bash
composer update nowo-tech/notify-kit-bundle nowo-tech/transaction-doctrine
```

`nowo-tech/transaction-doctrine` ^1.5 is required (was ^1.1). No migration, no configuration change.

The bundle has no HTTP layer, so nothing changes in what it throws: a deadlock, lock wait timeout or serialization
failure is still a `Nowo\TransactionContract\TransientStorageFailure` with nothing written. What changes is the answer
the documentation tells you to give (REQ-RUNTIME-003 rule 6, see [CONCURRENCY](CONCURRENCY.md)):

| Failure | 0.1 | Now |
| --- | --- | --- |
| Lock conflict, nothing committed | 503 + `Retry-After` | **409** + `Retry-After`, code `CONFLICT_001` |
| Same, after an earlier write unit of your request committed | 503 + `Retry-After` | **409**, no `Retry-After`, code `CONFLICT_002` |
| Unique violation, lost connection | not mapped | not mapped |

- If you answered the exception yourself, switch the status and add the `code`. Clients that retried on 503 should
  retry on 409 with `CONFLICT_001` and reload, not retry, on `CONFLICT_002`.
- If you use nowo-tech/transaction-doctrine's Symfony bundle, `conflict_response.map_transient_failures: true` answers it
  for you (JSON or HTML, with the code in the body). `conflict_response.status: 503` keeps 503 for the whole application.

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
