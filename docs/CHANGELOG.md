# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Tests

- Compiled-kernel test (`MessengerHandlerTest`): with FrameworkBundle autoconfiguration, `PruneNotificationsHandler` (`#[AsMessageHandler]`) is tagged `messenger.message_handler` exactly once, and no handler is registered while the bundle is disabled (audit SE follow-up). No behaviour change.

## [0.1.1] - 2026-11-17

### Changed

- `PruneNotificationsHandler` carries `#[AsMessageHandler]` and is registered through autoconfiguration instead of an explicit `messenger.message_handler` tag (REQ-MSG-001); the handler is registered exactly once, as before.

### Demo

- The demo shell is UiKit's admin layout with UiKit's default theme (REQ-DEMO-014); the Bootstrap CDN shell is gone.
- Dev stack (REQ-DEMO-001): Web Profiler, debug, Twig Inspector and Hot Reload (Caddy `mercure` + `hot_reload`), Flex pin `8.1.*` (REQ-DEMO-012).
- Playwright e2e and a WebP screenshot (REQ-DEMO-013): `make test-e2e`, `make demo-screenshots`.

### Documentation

- REQ compliance sweep 2026-11-16: baseline spec user scenarios (US-01..04), non-goals and validation commands (REQ-SPECKIT-003); Spec Kit section in SPEC-DRIVEN-DEVELOPMENT.md (REQ-SPECKIT-002); translation overrides in USAGE.md (REQ-I18N-001); README "Found this useful?" line and Code of Conduct link (REQ-DOCS-009, REQ-DOCS-015, REQ-DOCS-019); committed demo `.env.test` (REQ-DEMO-003).

[0.1.1]: https://github.com/nowo-tech/NotifyKitBundle/releases/tag/v0.1.1

## [0.1.0] - 2026-11-15

### Added

- **S1 core inbox** (contract 221-B §4–§6, §9; first tag 0.1.0):
  - `NotifierInterface::notify(NotificationRequest)`: validated requests (local URL only, params capped at 20 × 200
    characters, at most `fan_out.max_recipients` recipients), one in-app row per recipient, never for the actor,
    coalescing of repeats within `inbox.repeat_window_seconds` into the unread row, 24-hour dedupe keys, `NotifyResult`.
  - Deny by default at write time: a row with a context needs the type's permission granted by the host's
    `RecipientAccessCheckerInterface` (`access.checker`); the default `PersonalOnlyAccessChecker` grants nothing.
  - Type and category registry (`TypeRegistryInterface`): configuration plus tagged `TypeProviderInterface` services,
    open-set types with a generic definition guarded by `access.default_permission`.
  - Persistence: plain domain classes `Notification` and `DedupeMarker` mapped in XML (default) with attribute twins
    (`doctrine.mapping: attribute`), tables `<prefix>notify_notification` and `<prefix>notify_dedupe`, binary collations
    on every identifier column on MySQL / MariaDB, PHP `===` re-check of every read and delete by recipient or context.
  - Load readiness: DBAL writes through `nowo-tech/transaction-contract` (default runner from
    `nowo-tech/transaction-doctrine`, `doctrine.transaction_runner` for a retrying adapter); transient failures surface as
    `TransientStorageFailure` (503 + Retry-After); no EntityManager is ever flushed; no gap locks (concurrent repeats
    never lose a count, concurrent first notifications never deadlock).
  - Inside a host transaction the rows join it and the events (`NotificationRecordedEvent`,
    `NotificationSkippedEvent`, references only) wait for the outermost commit through a DBAL middleware; dropped on
    rollback and at every request / command / message boundary.
  - `InboxInterface::forgetContext()` for hosts that delete a project or board (no foreign key).
  - Default Doctrine `RecipientResolverInterface` (`recipient.class`).
  - Retention: `nowo:notify-kit:prune` (`--async`) and `PruneNotificationsMessage` (bounded runs, keyset cursor).
  - `nowo:notify-kit:schema-check`: compares the live tables with the mapping and prints the aligning SQL.
  - Audit QL fixes before the first tag: events of a joined call run at a safe point after the commit (never inside
    the driver commit) and are dropped with a rolled-back savepoint; binary NO PAD collations
    (`utf8mb4_0900_bin` / `utf8mb4_nopad_bin`) and byte comparison before LIMIT / GROUP BY; keyset walk in
    `forgetContext()`; one-statement dedupe claim (no gap lock); reference ids refuse edge white space and invisible
    characters; `/D` on every anchored pattern.
  - Tests: standard and DDD hosts on SQLite, MySQL 8.4 and PostgreSQL 17 (DBAL 3 and 4), lock-wait and concurrent-writer
    tests, a 2,000-request worker test with two users, reference DDL checked against docs/INSTALLATION.md.

- S0 scaffold (contract 221-B, [CONTRACT.md](CONTRACT.md)): the bundle class `NowoNotifyKitBundle`, the strict configuration
  tree `nowo_notify_kit` with every key of the contract (`enabled: false` by default; nothing is registered in S0) and the
  container parameters `nowo_notify_kit.enabled`, `nowo_notify_kit.api_prefix`, `nowo_notify_kit.table_prefix` and
  `nowo_notify_kit.config`.
- Quality gates: PHPUnit (100 % coverage gate), PHPStan level 8 with the FrankenPHP worker rulesets, PHP-CS-Fixer,
  Rector, Igor, CI matrix (PHP 8.2 to 8.5, Symfony 7.4 and 8.1), a SQLite / MySQL / PostgreSQL job, a worker test (one
  kernel kept alive, 2,000 requests) and the FrankenPHP demo smoke test.
- Docs set, Spec Kit baseline, Flex recipe, demo skeleton (Symfony 8.1, FrankenPHP worker mode, MySQL).

### Known issues

- **QL-D-L1:** savepoints the host names itself (`SAVEPOINT x` / `ROLLBACK TO SAVEPOINT x`, `Connection::createSavepoint('x')`
  / `rollbackSavepoint('x')`) are not seen by the after-commit middleware, so the event of a `notify()` made inside such
  a savepoint may still be dispatched after the host rolls that savepoint back. DBAL's own nesting (`beginTransaction()`
  inside a transaction, `wrapInTransaction()`, Doctrine ORM, Messenger's `doctrine_transaction`) is covered. Fix in 0.2.0.
- **QL-D-L2:** MariaDB is not in CI yet. The runtime maps the identifier columns with `utf8mb4_nopad_bin` there; the
  reference DDL in docs/INSTALLATION.md now has a separate MariaDB block (`utf8mb4_0900_bin` exists on MySQL 8 only).
  The MariaDB CI job lands in 0.2.0.
- **QL-D-I1:** a host that enables `PDO::MYSQL_ATTR_FOUND_ROWS` (or `CLIENT_FOUND_ROWS`) on the bundle connection makes
  every MySQL dedupe claim look successful, so a duplicate notice can get through (fails open: no leak). Leave it off on
  that connection.

[Unreleased]: https://github.com/nowo-tech/NotifyKitBundle/compare/v0.1.1...HEAD
[0.1.0]: https://github.com/nowo-tech/NotifyKitBundle/releases/tag/v0.1.0
