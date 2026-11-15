# Feature Specification: S1 core inbox

**Feature Branch**: `feat/s1`
**Created**: 2026-11-15
**Status**: Implemented (first tag 0.1.0)
**Input**: Contract 221-B §4–§6, §9, §11, §13 (S1) and §14; owner Q-O answers and Beacon Q-B answers as recommended;
house bar (`BUNDLES_FIX_RULES.md`: architecture bar, worker mode, LQ binary collation, load readiness).

Deviations from the contract text are listed in [`docs/ROADMAP.md`](../../docs/ROADMAP.md#s1-notes-contract-deviations).

## Requirements

| ID | Requirement |
| --- | --- |
| FR-NOTIFY-001 | `NotifierInterface::notify()` writes one in-app row per distinct recipient, never for the actor; more than `fan_out.max_recipients` recipients is refused. |
| FR-NOTIFY-002 | `NotificationRequest` refuses invalid type keys, targets, non-local URLs, more than 20 params and non-string params; cuts values to 200 characters. |
| FR-NOTIFY-003 | A repeat (recipient, type, target) within `inbox.repeat_window_seconds` updates the latest unread row (`repeats + 1`); read rows are never revived; `coalesce: false` types always insert. |
| FR-NOTIFY-004 | A dedupe key drops the same request for 24 hours; only its sha256 is stored. |
| FR-ACCESS-001 | Deny by default: a row with a context needs the type's permission (or `access.default_permission`) granted by the access checker for that context, or an unrestricted recipient; the default checker grants nothing. |
| FR-TYPES-001 | Registry from configuration and tagged providers; duplicates refused; a permission without a checker refused; unknown keys get the generic definition. |
| FR-TX-001 | DBAL only, through a transaction-contract runner; no EntityManager flushed; transient failures surface as `TransientStorageFailure` with nothing written and the connection at level 0. |
| FR-TX-002 | Inside a host transaction the rows join it and the events wait for the outermost commit; dropped on rollback and at every boundary. |
| FR-TX-003 | Concurrent repeats never lose a count; concurrent first notifications never deadlock (no gap locks). |
| FR-EVENT-001 | `NotificationRecordedEvent` / `NotificationSkippedEvent` after commit, references only. |
| FR-DB-002 | XML mapping and attribute twins give the same tables; prefix on tables and index names; binary collations on MySQL; PHP `===` re-check on every read and delete by recipient or context. |
| FR-DB-003 | `nowo:notify-kit:schema-check` reports the SQL that aligns the live tables with the mapping. |
| FR-RET-001 | Retention deletes old read rows, trims to `keep_per_recipient`, forgets expired dedupe keys, in bounded runs with a keyset cursor (command, `--async`, message handler). |
| FR-CTX-001 | `InboxInterface::forgetContext()` deletes a context's rows in batches. |
| FR-RECIPIENT-001 | Default Doctrine `RecipientResolverInterface`; a host resolver replaces it. |
| FR-HOSTS-001 | The same functional suite runs on a standard host and a DDD host (second entity manager, XML mapping, own resolver and checker). |
| FR-WORKER-002 | 2,000 alternating requests of two users in one kept-alive kernel: no cross-user rows, no held work carried over, flat memory. |

## Success criteria

- CI green: PHP / Symfony matrix, coverage 100 %, PHPStan (worker rulesets), CS, Rector, Igor, databases (SQLite, MySQL
  8.4 and PostgreSQL 17 on DBAL 3 and 4, lock-wait and concurrent-writer tests), demo smoke.
