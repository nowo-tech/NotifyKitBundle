# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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
