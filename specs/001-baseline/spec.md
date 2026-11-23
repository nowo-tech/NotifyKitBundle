# Feature Specification: NotifyKitBundle baseline

**Feature Branch**: `001-baseline`
**Created**: 2026-11-12
**Status**: Baseline (S0 scaffold)
**Input**: Contract 221-B ([`docs/CONTRACT.md`](../../docs/CONTRACT.md)), owner-approved 2026-11-12 with every Q-O
answered as recommended.

Related: [`docs/SPEC-DRIVEN-DEVELOPMENT.md`](../../docs/SPEC-DRIVEN-DEVELOPMENT.md),
[`docs/CONFIGURATION.md`](../../docs/CONFIGURATION.md), [`docs/ROADMAP.md`](../../docs/ROADMAP.md),
[`code-inventory.md`](code-inventory.md).

## Summary

S0 guarantees (S1 adds the in-app inbox, see [`../002-s1-core-inbox/spec.md`](../002-s1-core-inbox/spec.md); S2 the read side, bell and pages, see [`../003-s2-access-ui/spec.md`](../003-s2-access-ui/spec.md)): the bundle registers in a standard Symfony host with zero configuration; the configuration root
`nowo_notify_kit` is strict (unknown keys and invalid values are refused with the path in the message) and already holds
every key of the contract; `enabled` defaults to false; S0 registers no service, route, listener or table, whatever the
configuration says; the container parameters `nowo_notify_kit.enabled`, `nowo_notify_kit.api_prefix`, `nowo_notify_kit.table_prefix`
and `nowo_notify_kit.config` exist.

## User scenarios

### US-01 — Install and configure (S0)

- **Given** a standard Symfony 7.4 / 8.x host, **when** the bundle is registered with no configuration, **then** the
  container compiles, `enabled` is false and the bundle adds no service, route, listener or table.
- **Given** an unknown key or an invalid value under `nowo_notify_kit`, **when** the container compiles, **then** it
  fails with the path of the key in the message (FR-CONFIG-001).

### US-02 — Notify people in-app (S1)

- **Given** an enabled bundle, **when** the application calls `notify()` with several recipients including the actor,
  **then** one unread row is written per distinct recipient and none for the actor; more than
  `fan_out.max_recipients` recipients is refused (FR-NOTIFY-001).
- **Given** an unread row for (recipient, type, target), **when** the same notification repeats within
  `inbox.repeat_window_seconds`, **then** the row's `repeats` grows instead of a new row; a read row is never revived
  (FR-NOTIFY-003). **Given** a dedupe key, **when** the same request comes again within 24 hours, **then** it is
  dropped (FR-NOTIFY-004).
- **Given** a notification with a context, **when** no access checker grants the type's permission for that context,
  **then** the row is skipped (deny by default, FR-ACCESS-001).

### US-03 — Transactions and worker mode (S1)

- **Given** a host transaction, **when** `notify()` runs inside it and the transaction rolls back, **then** no row is
  kept and no event is dispatched; on commit the events follow the outermost commit (FR-TX-002, FR-EVENT-001).
- **Given** one kept-alive FrankenPHP worker kernel (reset off), **when** two users alternate 2,000 requests, **then** no
  row or held work crosses users and memory stays flat (FR-WORKER-001, FR-WORKER-002).

### US-04 — Retention and erasure (S1)

- **Given** old read rows, **when** `nowo:notify-kit:prune` (or the message handler) runs, **then** they are deleted in
  bounded keyset runs, each recipient keeps at most `keep_per_recipient` rows and expired dedupe keys are forgotten
  (FR-RET-001); `forgetContext()` deletes a context's rows in batches (FR-CTX-001).

## Requirements

| ID | Requirement |
| --- | --- |
| FR-BUNDLE-001 | The bundle class returns the `nowo_notify_kit` extension. |
| FR-CONFIG-001 | Strict tree: every key of contract §7 with its default; invalid values name the path. |
| FR-CONFIG-002 | Container parameters published for `security.yaml` (one-value API prefix rule). |
| FR-WORKER-001 | One kernel kept alive serves N requests with no bleed between owners and flat memory. |
| FR-DB-001 | Identifier columns compare exactly on SQLite, MySQL (`utf8mb4_bin`) and PostgreSQL. |
| FR-I18N-001 | Seven translation catalogues with the same keys (en, es, it, fr, pt, de, nl). |

## Success criteria

- `make release-check` green; CI green (matrix, coverage 100 %, PHPStan, CS, databases, demo smoke).

## Non-goals (baseline)

- No HTML pages, bell, preferences UI or HTTP API yet: they land with the later slices of [`docs/ROADMAP.md`](../../docs/ROADMAP.md).
- No mail / push / Slack delivery in S1 (channel bridges are later slices); no message bodies are stored, only type keys,
  targets and short params.
- The bundle never flushes the application's EntityManager (DBAL only, FR-TX-001).

## Validation commands

```bash
make test            # PHPUnit (unit + functional, SQLite)
make test-with-db    # integration suite (databases)
make test-coverage   # PHP coverage (100 % gate)
make phpstan         # PHPStan + FrankenPHP worker rulesets
make cs-check        # PHP-CS-Fixer (dry run)
make release-check   # full chain, demo smoke included
```
