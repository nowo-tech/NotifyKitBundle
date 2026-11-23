# Feature Specification: S3 preferences

**Feature Branch**: `feat/s3-preferences` (stacked on `feat/s2-access-ui`, PR #11)
**Created**: 2026-11-23
**Status**: In review (next minor after S2's 0.3.0: **0.4.0**; batched REQ-SEC-004 audit)
**Input**: Contract 221-B §1 (preferences matrix), §4.1 (`ChannelPreference`, `PreferenceMatrix`), §4.2
(`PreferencesInterface`), §4.3 (`ContextProviderInterface`), §5.1 (`notify_preference`), §6.3, §6.6
(`PreferencesChangedEvent`), §6.7 (audit), §7.1 (`GET/POST /account/notifications`), §8.3, §11, §13 (S3) and §14
("Preferences": Beacon's merge order); owner Q-O answers (2026-11-12), S1 decisions (2026-11-15: deny by default for
context types, dedupe table); deferred audit items QL-I3, QL-I6, QL-I7 (appendix QL); house bar
(`BUNDLES_FIX_RULES.md`: REQ-ARCH-001 rule 3, REQ-UI-002, REQ-RUNTIME-002/003, REQ-ASSETS-005, REQ-TWIG-005,
REQ-I18N-002, Chrome Modern Web Guidance).

Deviations from the contract text are listed in [`docs/ROADMAP.md`](../../docs/ROADMAP.md#s3-notes-contract-deviations).

## User scenarios

1. **A person turns mail off for one category.** On *Account → Notifications* they untick *Issues — E-mail*; the change
   is saved at once ("Saved"); from S4 no issue mail reaches them. The bell row of issues stays (locked, *Always on*).
2. **A person mutes one project.** They switch *Apollo* off in *Per context*: nothing optional reaches them for that
   project; the master switch and other projects are unchanged.
3. **A person only wants mentions when involved.** They pick *Only when I am involved* for *Mentions* (a type with
   `in_app_optional`): a mention whose request does not list them in `involved` writes no bell row.
4. **Two tabs.** Tab A turns a cell off; tab B, still showing the old state, turns the same cell on: B is told the
   setting changed elsewhere, sees the current state, and nothing of B's change is written.
5. **Customise a project.** *Customise* opens the project's matrix in a dialog (loaded on demand); *Reset to account
   settings* removes the project's overrides and keeps its switch.

## Requirements

| ID | Requirement |
| --- | --- |
| FR-PREF-001 | Storage `<prefix>notify_preference` (contract §5.1): UUIDv7 id, recipient, `context_key` (`''` = account), `category` (`*` = switch), `channel` (`*` = every channel), `enabled`, `scope` (NULL = `all`), `updated_at`; unique (recipient, context_key, category, channel); binary NO PAD collations on MySQL / MariaDB; XML mapping and attribute twin; reference DDL; schema check. Only deviations from the defaults are stored: a choice equal to the cell's default deletes the row. |
| FR-PREF-002 | Matrix: columns = `in_app` + every channel a registered type names; rows = categories with an in-matrix registered type, in registry order; a category cell is on by default when one of its types is; locked when every type of the category locks the channel. |
| FR-PREF-003 | Resolution in Beacon's merge order: locked channel → master switch (`''/*/*`, or `''/*/<channel>`) off → context switch off → context × category override (exact channel, then `*`) → account × category (exact, then `*`) → type default; scope `involved` lets a notification through only when the person is in the request's `involved`. Tested as a resolution table. |
| FR-PREF-004 | `PreferencesInterface::save()`: validates (category / channel of the matrix or `*`; not locked; scope allowed by `preferences.scopes`, `all` only on switches; a context `ContextProviderInterface::contextsFor()` lists **for this person**, deny by default); one transaction, cells in key order, one conditional statement per cell (optimistic concurrency with `PreferenceExpectation`); a stale cell → `PreferenceConflictException`, nothing written; `PreferencesChangedEvent` (keys only) after the commit when something changed. `resetContext()` deletes the context's overrides, keeps its switch. |
| FR-PREF-005 | `notify()`: for a type whose `in_app` is not locked (`in_app_optional`), the person's preferences decide the bell row (one read for all recipients; skip reason `preference`; `NotifyResult::$skippedPreference`); a locked bell reads nothing. `NotificationRequest::$involved` is capped at `fan_out.max_recipients` (QL-I3). |
| FR-UI-003 | Preferences page under `web_ui.preferences_path` (UiKit settings scaffold, FormKit form, no raw form elements): master switch, matrix table (caption, row / column headers, visually hidden labels per cell, *Always on* for locked cells), scope select per category, contexts with switch and *Customise* link; context page with *Reset to account settings* / *Back*; fragment (`fragment=1`) for the dialog. |
| FR-UI-004 | Script `js/nowo-notify-kit-preferences.js` (TypeScript IIFE, Vitest ≥ 90 %): autosave of each change (`POST …/cells`, JSON, `X-CSRF-TOKEN`, the page's token per cell), saves in sequence, current cells applied after a stale 409, one retry after a lock 409 with `Retry-After`, revert + message otherwise, polite status region; *Customise* loads the context grid on demand into a native modal `<dialog closedby="any">` (light-dismiss fallback, `h1` title, close button, removed on close); the submit button is hidden while JS saves. REQ-ASSETS-005 script-loaded line with the quiet switch (`logger.ts`, `build-info.json`). |
| FR-HTTP-003 | Endpoints: anonymous → firewall login answer; no role of `web_ui.security.preferences_roles` (null = `access_roles`) or refused by `PreferencesAccessCheckerInterface` → 403; the person always from the token storage, never a parameter; context not listed for the person → 404; missing / wrong CSRF (`nowo_notify_kit_preferences`) → 403; stale → 409 `CONFLICT_001` (+ current cells); lock → 409 + `Retry-After` + `CONFLICT_001`; invalid body → 422; `no-store, private`; body ≤ 32 KiB, ≤ 100 changes. |
| FR-AUDIT-001 | AuditKit bridge (`bridges.audit_kit`: auto / true / false): `notify.preferences_changed` (actor = subject = the person's recipient id; keys ≤ 50, count, reset) and `notify.retention_run` (operator recipient id or null; counts) through `ActionLogIdRecorderInterface::recordIdsAndFlush()` after the commit; never content or values; failures logged. |
| FR-QL-I6 | Application services depend on `Application\UnitOfWorkInterface`, implemented by `Infrastructure\Persistence\NotifyKitTransactions`; no Application or Domain class imports Infrastructure. |
| FR-QL-I7 | `AfterCommitMiddleware` is tagged `doctrine.middleware` with the bundle connection (the connection of `doctrine.entity_manager`, or the DBAL connection of that name); unknown → every connection as before. |
| FR-CONC-003 | Two connections on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16: a stale save is refused and writes nothing (level 0); a save waiting on a held cell is transient (`CONFLICT_001`, `Retry-After`) and the retry saves; four processes toggling one cell never lose a toggle or store it twice. |
| FR-WORKER-004 | Stateless services (kernel reset false): the preferences page, autosave and `notify()` gate keep no per-request state; the worker loop covers preference saves of two people. |
| FR-I18N-003 | 7 locales with key parity (`notify.preferences.*`, `notify.channel.*`). |
| FR-DEMO-003 | Demo: Alice / Bob preferences page (mail column, a project context, a mention type with an optional bell); Playwright: autosave, stale answer in two tabs, context dialog, deny by default for another person's context, axe; WebP screenshot. |

## Success criteria

- CI green: PHP / Symfony matrix, coverage 100 % (PHP), TypeScript (typecheck, Vitest ≥ 90 %, committed build and
  `build-info.json`), PHPStan (worker rulesets), CS, Rector, Igor, databases (SQLite, MySQL 8.4, MariaDB 11.4,
  PostgreSQL 16 on DBAL 3 and 4), demo smoke.
- Batched REQ-SEC-004 audit before 1.0.0 (contract §13); focus: authorization of contexts, autosave races, AuditKit data.

## Validation commands

```bash
make test-coverage-100 && make test-ts
NOWO_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/app?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --testsuite Database
make -C demo/symfony8 up test-e2e
```
