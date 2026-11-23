# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to
[Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## Table of contents

- [[Unreleased]](#unreleased)
- [[0.3.0] - 2026-11-23](#030---2026-11-23)
- [[0.2.0] - 2026-11-18](#020---2026-11-18)
- [[0.1.1] - 2026-11-17](#011---2026-11-17)
- [[0.1.0] - 2026-11-15](#010---2026-11-15)

## [Unreleased]

### Added

- **S3 preferences** (contract 221-B §4.1, §4.2, §5.1, §6.6, §6.7, §7.1, §8.3; spec `specs/004-s3-preferences`):
  - **Storage** `<prefix>notify_preference` (deviations only; unique per recipient × context × category × channel;
    binary NO PAD collations on MySQL / MariaDB; XML mapping `StoredPreference.orm.xml` and attribute twin
    `PreferenceRecord`; schema check and reference DDL).
  - **`PreferencesInterface`**: `matrixFor()`, `save()` (all or nothing, cells in key order, one conditional statement
    per cell, optimistic concurrency with `PreferenceExpectation`; `PreferenceConflictException` when stale) and
    `resetContext()`. Value objects `Scope`, `ChannelPreference`, `PreferenceKey`, `PreferenceRow`, `PreferenceMatrix`
    (Beacon's merge order: locked channel → master switch → context switch → context override → account row →
    type default; scope `all` / `involved`), `PreferenceChange`, `PreferenceChangeSet`, `PreferenceExpectation`; event
    `PreferencesChangedEvent` (cell keys only, after the commit).
  - **`notify()`** honours the preferences of a type whose bell row is optional (`in_app_optional`): master switch,
    context switch, overrides, scope `involved` (one read for every recipient); `NotifyResult::$skippedPreference`.
    `TypeRegistryInterface::channels()` lists `in_app` plus the channels the registered types name.
  - **Preferences page** under `web_ui.preferences_path` (`/account/notifications`): UiKit settings scaffold, FormKit
    matrix form (master switch, category × channel checkboxes, *Always on* for locked cells, scope per category, the
    person's contexts with a switch and *Customise*), context page and dialog fragment, *Reset to account settings*,
    JSON autosave `POST …/cells` (409 + `CONFLICT_001` with the current cells when stale, 409 + `Retry-After` on a lock,
    422 when invalid). New script `js/nowo-notify-kit-preferences.js` (autosave, context dialog loaded on demand,
    REQ-ASSETS-005 "script loaded" line with the quiet switch, `build-info.json`).
  - **REQ-UI-002**: `PreferencesAccessCheckerInterface`, `web_ui.security.preferences_roles` (null = `access_roles`) and
    `web_ui.security.preferences_access_checker`. Contexts are accepted only from `ContextProviderInterface::contextsFor()`
    of the logged-in person (404 otherwise).
  - **AuditKit bridge** (`bridges.audit_kit`): `notify.preferences_changed` and `notify.retention_run` in AuditKit's
    action log (ids, cell keys and counts only).
  - Translations of the page in 7 locales; `docs/USAGE.md` (preferences, audit trail, browser console),
    `docs/CONFIGURATION.md`, `docs/CONCURRENCY.md`, `docs/TEMPLATES.md`, `docs/UPGRADING.md`.

### Changed

- Application services depend on the new `Application\UnitOfWorkInterface` port instead of the infrastructure
  transaction class (audit QL-I6).
- The after-commit DBAL middleware wraps only the bundle connection (the connection of `doctrine.entity_manager`; audit
  QL-I7).
- `NotificationRequest::$involved` larger than `fan_out.max_recipients` is refused (audit QL-I3).

## [0.3.0] - 2026-11-23

### Added

- **S2 access + UI** (contract 221-B §4.2, §6, §7.1, §8.1, §8.2, §8.4):
  - **Read-time visibility** (`Application\Visibility\VisibilityResolver`): personal rows always; a row with a
    context only while the access checker still grants the type's permission there (unknown types:
    `access.default_permission`; a personal type in a context: never). The checker is asked **once per permission**
    with every context of the person's rows; contexts it returns that were not candidates are ignored. Unrestricted
    people see everything; a stored context the value objects refuse today stays hidden for everyone (fail closed).
  - **`InboxInterface` read and row methods**: `unreadCount()`, `page()` (keyset, newest first, filters type /
    category / status), `markRead()`, `markUnread()` (by id **and** recipient, visibility re-checked, `false` = 404),
    `markAllRead()` (visible rows only, batches of `retention.batch_size`). New value objects `InboxFilter`,
    `InboxStatus`, `InboxPage`, `NotificationView`; event `NotificationReadEvent` (ids only, after the commit, only when
    something changed).
  - **Realtime badge**: `RealtimePublisherInterface`, `Application\Realtime\RealtimeTopics` (`notify-kit/r/<hmac>`,
    `subscribeClaimFor()`), the Mercure publisher (private update `{"event":"notify.updated","unread":n}` after the
    commit, never a title, URL or param) and the subscriber cookie for the person's own topic. `realtime.mode: auto`
    uses Mercure when a hub service exists and polls otherwise; `mercure` without a hub refuses to build; a host
    publisher (`realtime.publisher`) is used as is. `notify()` and the row operations publish after the commit; the
    first publish failure stops the badges for the rest of that call (audit ZAJ-3). `realtime.subscriber_cookie`
    (default `true`; `false` leaves the subscriber JWT to the host, audit ZAJ-1). The bell falls back to polling when
    the Mercure stream is closed or fails 3 times in a row (audit ZAJ-2), and loads the menu only from a
    non-redirected HTML answer, keeping only its menu element (audit ZAJ-I6).
  - **Header bell**: Twig function `notify_kit_bell()` (`<details>` disclosure that works without JavaScript, badge
    capped at `99+`, polite live region), the bell script (TypeScript, IIFE `js/nowo-notify-kit-bell.js`, Vitest
    ≥ 90 %; optional Stimulus peer `nowo-notify-kit--bell`) and `css/nowo-notify-kit.css` on UiKit tokens, both in the
    new asset package `nowo_notify_kit`.
  - **Pages and endpoints** (`@NowoNotifyKitBundle/Resources/config/routes.php`, loader type `nowo_notify_kit`):
    inbox page (UiKit list scaffold, FormKit filter, keyset *Load more*, per-row read / unread, *Mark all as read*),
    `GET …/menu`, `GET …/count` (`ETag`, `304`), `POST …/{id}/read`, `…/{id}/unread`, `…/read-all` (JSON for `fetch`
    with `X-CSRF-TOKEN`, redirect to a local `_redirect` for FormKit forms), all `Cache-Control: no-store, private`;
    operator page under `admin_ui.path_prefix` (counts only, *Run retention now*). Lock conflicts answer **409** +
    `CONFLICT_001` + `Retry-After` (REQ-RUNTIME-003 rule 6).
  - **Access to the pages (REQ-UI-002)**: `InboxAccessCheckerInterface` (`web_ui.security.access_roles` /
    new `web_ui.security.access_checker`) and `OperatorAccessCheckerInterface` (`admin_ui.security.*`); the pages need
    Twig, forms, CSRF and SecurityBundle (`Compiler\WebUiPass` names what is missing).
  - Host SPIs `RecipientDirectoryInterface` (display names; default: the user object's `__toString()`, `getEmail()`,
    `getLocale()` through the recipient resolver) and `ContextProviderInterface` (context labels; default: none);
    `types.<key>.title_domain` (translation domain of a type title, default `messages`).
  - Titles at display time (`Presentation\Title\NotificationTitles`, audit QL-I1): params as `%key%`, then the
    built-ins `%actor%`, `%target%`, `%repeats%` (a param cannot spoof them), one line, printed escaped inside
    `<bdi dir="auto">`; `_redirect` checked as a local path before and after URL-decoding.
  - Translations ×7 for the bell, the pages and the operator page; `docs/TEMPLATES.md`.
  - Demo: sign in as Alice or Bob, send notifications, the bell in the top bar, the inbox and operator pages, Playwright
    e2e (bell, mark read, live badge by polling, deny by default, axe) and WebP screenshots.

### Fixed

- `DoctrineRecipientResolver::userOf()` no longer refreshes a user the host's entity manager already manages (it
  overwrote pending changes of a managed, dirty user; audit QL-L4): the managed instance is returned untouched.
- Standard hosts whose Doctrine configuration says `orm: ~` now get the bundle mapping too (the prepend looked for a
  non-null `orm` key).

### Changed

- Igor 0.11: the `ready` list of `AfterCommitQueue` is marked `#[WorkerSafe]` with the reason (a documented false positive: `reset()` runs and empties it through `drain()`). No behaviour change.
- Demo: runs in UiKit's demo platform shell (`nowo-tech/ui-kit-bundle` ^2.60); the demo's own header is gone and the pages use the shell page headers and section cards. The demo lock is refreshed (transaction-doctrine 1.1.0 to 1.9.2) because it lagged behind the bundle's requirement. Demo only, no bundle change.
- Requires `nowo-tech/transaction-doctrine` ^1.8.1 (fixes GHSA-9wh3-m3m5-jffx and GHSA-65w2-9r4h-848g).
- Requires `nowo-tech/ui-kit-bundle` ^2.68, `nowo-tech/form-kit-bundle` ^2.24, `symfony/{asset,form,framework-bundle,
  http-foundation,routing,security-core,security-csrf,translation,twig-bundle}`, `twig/twig`, `twig/extra-bundle` and
  `twig/string-extra` (the pages and the bell, contract §2). Conflicts with `symfony/mercure` < 0.10 (a second
  subscriber cookie in the same request threw there, audit ZAJ-1). `symfony/security-bundle` and `symfony/mercure-bundle` are suggested.
- `NotificationStoreInterface` (internal) gains the read side; `countUnread()` takes an optional visibility.
- The route loader `nowo_notify_kit` is registered even while the bundle is disabled (it then adds no route), so the
  routes import never fails.

[0.3.0]: https://github.com/nowo-tech/NotifyKitBundle/releases/tag/v0.3.0

## [0.2.0] - 2026-11-18

### Added

- `docs/CONCURRENCY.md` (REQ-RUNTIME-003): write-path table, conflict codes, host options, replays, tests and residual risks.
- CI: the Databases job also runs the Database suite on MariaDB 11.4 (DBAL 4 and DBAL 3), next to MySQL 8.4 and PostgreSQL 16 (closes the QL-D-L2 known issue); the Database suite and the reference DDL test know the MariaDB `utf8mb4_nopad_bin` collation.
- Tests: `ConflictMappingTest` (code of every failure shape: `CONFLICT_001`, `CONFLICT_002`, unique violation and lost connection left alone; host mapper); the lock-wait load test asserts the conflict code and the `Retry-After` hint.

### Changed

- **Documented answer of a lock conflict (REQ-RUNTIME-003 rule 6):** `TransientStorageFailure` (deadlock, lock wait timeout, serialization failure; nothing written) is answered **409 + `Retry-After` + `CONFLICT_001`**, no longer 503; `CONFLICT_002` (409, no `Retry-After`) when your request had committed part of its work. 503 stays a host option (`nowo_transaction_doctrine.conflict_response.status`). No behaviour change in the bundle: it never answers HTTP itself, and the exception class is unchanged. See [UPGRADING](UPGRADING.md#lock-conflicts-answer-409--conflict-codes).
- Requires `nowo-tech/transaction-doctrine` ^1.5 (was ^1.1): conflict codes, serialization failures replayed by default.
- Contract, USAGE, ROADMAP and the PHPDoc of `NotifierInterface` / `InboxInterface` say 409 instead of 503.
- CI PostgreSQL service is 16 (was 17).

### Tests

- Compiled-kernel test (`MessengerHandlerTest`): with FrameworkBundle autoconfiguration, `PruneNotificationsHandler` (`#[AsMessageHandler]`) is tagged `messenger.message_handler` exactly once, and no handler is registered while the bundle is disabled (audit SE follow-up). No behaviour change.

[0.2.0]: https://github.com/nowo-tech/NotifyKitBundle/releases/tag/v0.2.0

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

[Unreleased]: https://github.com/nowo-tech/NotifyKitBundle/compare/v0.3.0...HEAD
[0.1.0]: https://github.com/nowo-tech/NotifyKitBundle/releases/tag/v0.1.0
