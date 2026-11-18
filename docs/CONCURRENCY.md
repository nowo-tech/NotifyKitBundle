# Concurrency

How the notification inbox stays correct when many requests, workers and commands write at once (nowo-tech
REQ-RUNTIME-003): write paths, conflict answers, codes, host options and residual risks. The design of the writes is
in [CONTRACT.md](CONTRACT.md#53-writes-transactions-and-events-rules-6-and-7); the day-to-day rules for callers are in
[USAGE.md](USAGE.md#transactions-failures-and-events).

## Table of contents

- [Rules the bundle follows](#rules-the-bundle-follows)
- [Write paths](#write-paths)
- [Conflict answers and codes](#conflict-answers-and-codes)
- [What is not a conflict](#what-is-not-a-conflict)
- [Host options](#host-options)
- [Replays](#replays)
- [Tests and CI](#tests-and-ci)
- [Residual risks](#residual-risks)

## Rules the bundle follows

- **One transaction per write unit**, through `NotifyKitTransactions` on nowo-tech/transaction-contract and
  nowo-tech/transaction-doctrine ^1.5. Outside a transaction of yours each unit opens and commits its own; inside one
  on the same connection the unit joins it (a nested level that disappears with a rollback).
- **DBAL only.** The bundle never flushes, clears, closes or resets an EntityManager, yours or any other.
- **After a failure the connection is back at level 0** (or at the level your transaction had): a worker keeps serving.
- **Events after the commit** (`NotificationRecordedEvent`, `NotificationSkippedEvent`): held until the outermost
  commit, dropped with a rollback. No HTTP, mail or file I/O happens while a lock is held.
- **Exact keys:** recipient and context ids compare byte-exactly (NO PAD binary collations on MySQL / MariaDB, `===`
  re-check on every read and delete).
- **Stateless services** (FrankenPHP worker mode, kernel reset off): the connection is resolved per call.

## Write paths

| Write path | Transaction | Control | Conflict answer |
| --- | --- | --- | --- |
| `NotifierInterface::notify()`: dedupe claim, then one row per recipient | One unit for all recipients, recipients in key order | Dedupe: one upsert (`INSERT … ON DUPLICATE KEY UPDATE` / `ON CONFLICT DO UPDATE … WHERE expired`), no locking read and no gap lock. Repeat: read the candidate unread row, then `UPDATE … SET repeats = repeats + 1` by primary key (never a lost count). First notification: `INSERT` with a generated id (two concurrent first notifications write two rows at worst) | `TransientStorageFailure`: `CONFLICT_001`, nothing written |
| `InboxInterface::forgetContext()` | One unit per chunk (keyset by id, `batch_size` rows) | Chunked `DELETE … WHERE id IN (…)`; a repeated run finishes what a failed one left | `TransientStorageFailure`: `CONFLICT_001` for that chunk; earlier chunks stay deleted, run it again |
| Retention: read rows older than the cutoff, per-recipient cap | One unit per chunk | Chunked `DELETE` by ids (keyset cursor) | `TransientStorageFailure` to the command / handler: run it again (a failed Messenger message goes through your retry strategy) |
| Retention: expired dedupe markers | One unit per chunk | Chunked `DELETE` by key | Same |

There is no read / archive / update path in 0.x. When S2 adds one, it is a conditional `UPDATE` (`WHERE read_at IS NULL`)
in one unit and is listed here.

## Conflict answers and codes

The bundle has no controller: a producer calls `notify()` from your code, so **you** answer. A deadlock, lock wait
timeout or serialization failure surfaces as `Nowo\TransactionContract\TransientStorageFailure`
(`retryAfterSeconds()`, the storage error as `previous`), with nothing written. Answer it like the rest of the platform:

| Code | When | Status | `Retry-After` | Client should |
| --- | --- | --- | --- | --- |
| `CONFLICT_001` | A `TransientStorageFailure` (or a DBAL deadlock / lock wait / serialization error in the chain) and nothing of the request was committed | **409** (503 as a host option) | Yes (`retryAfterSeconds()`, else 1 s) | Retry |
| `CONFLICT_002` | The same, but an earlier write unit of the same request committed (`ConcurrencyConflict::$committed`), or a lost savepoint with no deadlock behind it (outcome unknown) | **409** (503 as a host option) | No | Reload, do not repeat blindly |

- The codes are `Nowo\TransactionDoctrine\ConflictCode` values. `ConflictCode::forFailure($e)` gives the code of any
  failure.
- **Nothing written by the bundle** for `CONFLICT_001`: `notify()` is one unit, so it is all or nothing. The bundle
  itself cannot know that your request committed something earlier, which is why `CONFLICT_002` is decided by your
  write units (`#[AsWriteUnit]` / `WriteUnit`) and the responder of nowo-tech/transaction-doctrine.
- Before 0.2.0 the contract and docs said "503 + Retry-After". The exception class did not change; only the documented
  answer did.

## What is not a conflict

- **Unique constraint violations** are not mapped. The only unique key the bundle relies on is the dedupe key, claimed
  by an upsert that never raises a duplicate: a repeated key is the domain result `NotifyResult::$deduplicated`, not an
  error. Identifier columns are generated ids, so another unique violation would be a bug and surfaces as a real error.
- **Lost connections** are not mapped: the outcome of the write is unknown and nothing says it was a lock.
- A **refused recipient** (access, actor, preference) is never an error: it is skipped and reported in `NotifyResult`.

## Host options

- **Answer 409 in one place.** With nowo-tech/transaction-doctrine's Symfony bundle registered, set
  `nowo_transaction_doctrine.conflict_response.map_transient_failures: true`: a `TransientStorageFailure` that reaches
  the kernel is answered `409` + `Retry-After` + `CONFLICT_001` (JSON `{"error", "code", "message"}` or the branded HTML
  page), and a lost savepoint `CONFLICT_002`. `conflict_response.status: 503` keeps the old status for the whole
  application; the codes stay.
- **Retry inside the bundle.** `nowo_notify_kit.doctrine.transaction_runner` takes a retrying runner that works on the
  bundle connection and flushes no EntityManager (for example the connection adapter of
  nowo-tech/doctrine-deadlock-retry-bundle 3.x). Only do it when your caller is idempotent: `notify()` replays are safe
  because the work reads nothing from an earlier attempt, and a dedupe key makes the repeat exact.
- **Catch it yourself** around a producer you never want to fail the request, and log: a lost notification is the only
  cost. The bundle never swallows the failure.
- Database settings: READ COMMITTED and a few seconds of `innodb_lock_wait_timeout` / `lock_timeout`.

## Replays

- Without a retrying runner each call makes **one attempt**; the caller retries after a `CONFLICT_001`.
- A replayed `notify()` loads nothing from the earlier attempt, claims the dedupe key again (the upsert of a key the
  failed attempt never committed succeeds) and holds its events until the commit.
- A Messenger handler that calls `notify()` can run twice: give the request a `dedupe_key` (24 hours), otherwise a
  replay after a commit whose acknowledgement was lost counts one more repeat.
- `PruneNotificationsHandler` and `forgetContext()` are idempotent: a second run deletes what is left.

## Tests and CI

- `tests/Integration/Database/LoadReadinessTest.php` runs on MySQL 8.4, MariaDB 11.4 and PostgreSQL 16, with DBAL 4 and
  DBAL 3, and fails when skipped:
  - a repeat waiting for a row another connection holds times out as `TransientStorageFailure`, maps to
    `CONFLICT_001` with a `Retry-After` hint, leaves the connection at level 0 and writes nothing; the retry coalesces;
  - separate processes write the same recipient concurrently: no repeat is lost, none fails;
  - dedupe claims take no gap lock, and concurrent writers claim every key exactly once.
- `tests/Unit/Infrastructure/ConflictMappingTest.php` covers the code of every failure shape (nothing committed:
  `CONFLICT_001`; committed or a lost savepoint: `CONFLICT_002`; unique violation and lost connection left alone) and the
  host mapper.
- The CI job **Databases** is a required check of `main`.

```bash
NOWO_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/app?serverVersion=8.4.0&charset=utf8mb4' \
  vendor/bin/phpunit --no-coverage --fail-on-skipped --testsuite Database
```

## Residual risks

- **A producer inside your transaction** keeps the row locks it took until your commit; a long host transaction makes
  other producers of the same recipient wait for it, up to the database's lock wait timeout.
- **`CONFLICT_002` is decided by your write units.** A request that wrote in autocommit before `notify()` failed is not
  counted by the bundle; use transaction-doctrine's write units so the responder knows.
- **Two concurrent first notifications of one recipient and target** insert two rows (no gap lock by design); the next
  repeat coalesces into the newer one. The unread count stays exact.
- **A retention run killed halfway** leaves its chunks deleted and the rest for the next run.
- **A lost acknowledgement** (commit succeeded, the connection dropped before the answer) cannot be told from a failure:
  use `dedupe_key` for producers that must not repeat.
