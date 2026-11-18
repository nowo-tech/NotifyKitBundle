# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-B). Every slice is a pull request on
`nowo-tech/NotifyKitBundle`. Under the 2026-11-07 batch-audit rule, S2 (access and visibility) and S6 (public API) need
an **immediate** REQ-SEC-004 audit before a tag that contains them; the rest is audited in the batch before 1.0.0.

## Table of contents

- [Slices](#slices)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Beacon answers](#beacon-answers)
- [S1 notes (contract deviations)](#s1-notes-contract-deviations)

## Slices

| Slice | Version | Content | Audit |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL; Mercure joins in S2), strict config tree `enabled: false`. **Then the owner registers the package on Packagist** | Batched |
| **S1** core inbox ✅ (0.1.0, 2026-11-15) | 0.1.0 | Domain + XML / attribute mapping, DBAL stores, `notify()` with the in-app channel, coalescing, after-commit buffer, events, type registry (config + tagged), retention command + message, schema-check; plus write-time access (deny by default), the Doctrine recipient resolver, `forgetContext()`, dedupe keys and the load-readiness pattern (transaction-contract, real-database CI job) | Batched |
| **S2** access + UI | 0.2.0 | `RecipientAccessCheckerInterface`, visibility, row operations, bell (Twig + Stimulus + CSS), inbox page, CSRF, realtime (Mercure + poll), operator page | **Immediate** |
| **S3** preferences | 0.3.0 | Preference store + matrix, contexts, all / involved, master switch, preferences page, AuditKit bridge | Batched |
| **S4** channels | 0.4.0 | Async fan-out, delivery idempotency, mail (Mailer + MailKit), push (PwaBundle), Slack (SlackKit), quiet hours, digests, rate limits, scheduler recipe | Batched |
| **S5** GDPR + bridges | 0.5.0 | UserKit eraser + export (+ actor unlinking), ChatKit bridge, ApiToken expiring bridge, InstanceSettings provider, translations ×7, docs | Batched |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/notifications/v1` + OpenAPI (off by default), `bundle-req-audit` 100 %, SEC-004 batch audit on S0–S6 | **Immediate** for the API |
| Beacon | after 1.0.0 | Contract §12 phase 1, then phase 2 (Slack DM) | Beacon's own |

## Owner decisions (2026-11-12)

Every owner question of the contract is answered with its recommendation:

| # | Decision |
| --- | --- |
| Q-O1 | One bundle with inbox, preferences and channel fan-out |
| Q-O2 | The bell row is always written by default (`in_app` locked); a type may opt in to make it optional (`in_app_optional`) |
| Q-O3 | In-app synchronous after commit; out-of-band channels asynchronous through Messenger, sync fallback without it |
| Q-O4 | Row ids: UUIDv7 `string(36)` with binary collation |
| Q-O5 | Push devices stay in PwaBundle / the host (`PushSubscriptionProviderInterface`); NotifyKit only sends |
| Q-O6 | Default access checker: personal rows only; the container refuses a type with a permission and no checker |
| Q-O7 | Public API `<prefix>/notifications/v1` in S6, off by default, authenticated by ApiTokenBundle module `notifications` |
| Q-O8 | Retention: read rows 90 days, 500 rows per person, delivery log 30 days; unread rows never deleted by age |
| Q-O9 | SMS channel (SmsKit) as a 1.x bridge, opt-in only |
| Q-O10 | No admin broadcast in 1.0 |
| Q-O11 | Mercure topic hashed per recipient (`notify-kit/r/<hmac>`) by default |
| Q-O12 | Beacon's inbox / preferences GDPR export gap waits for the bundle (S5); recorded in Beacon's REQ-DATA-004 checklist meanwhile |
| 221-A Q-O11 / Q-O12 | XML mapping by default + attribute twin; `RecipientRef` copied in this bundle |

## Beacon answers

Beacon accepted every recommendation of contract §15 (recorded 2026-11-15):

| # | Answer |
| --- | --- |
| Q-B1 | Bundle table names (`nowo_notify_*`); the old tables are dropped one release after the copy |
| Q-B2 | Slack DM preferences, quiet hours and digest move in phase 2 |
| Q-B3 | The bundle Mercure topic (`notify-kit/r/<hmac>`) |
| Q-B4 | Recipient ids are user UUIDs |
| Q-B5 | Issue toasts and the Web Push presentation of `issue.*` types stay in Beacon (a presenter) |
| Q-B6 | Beacon calls `InboxInterface::forgetContext(project)` from its project deletion flow |

## S1 notes (contract deviations)

**Owner decisions 2026-11-15:** the three deviations below on writes inside a host transaction, coalescing without
`FOR UPDATE` and the `nowo_notify_dedupe` table are **accepted**; a type without a permission written with a context is
**refused** (deny by default); `notify()` **propagates** `TransientStorageFailure`, and a retry happens only when the host
sets `doctrine.transaction_runner` to a retrying adapter.

| Contract | S1 | Why |
| --- | --- | --- |
| §5.3: a request inside a host transaction is held and processed on the outermost commit | The rows are written **inside** the host transaction (they commit or roll back with it); only the events wait: dropped on the outermost rollback **or a rollback to a savepoint that contains them**, made ready by the outermost commit and dispatched at the next safe point (the bundle's next unit of work at level 0, or the request / command / message boundary at the latest), never inside the driver commit (audit QL-M1 / QL-M2) | A DBAL driver middleware sees the commit while the wrapper connection is still at level 1, so no write can run there; joining keeps "nothing announced, nothing written on rollback" |
| §5.3: coalescing with `SELECT … FOR UPDATE` | Plain read of the candidate, then `UPDATE … SET repeats = repeats + 1 WHERE id = ? AND read_at IS NULL` | `FOR UPDATE` on a missing row takes a gap lock on MySQL: two concurrent first notifications deadlocked in the real-database job. The row lock of the update keeps "no lost update" |
| §4.1 `dedupeKey` (no table in §5.1) | New table `<prefix>notify_dedupe` (`sha256` of the key, `expires_at`), claimed in **one upsert**: `INSERT … ON DUPLICATE KEY UPDATE expires_at = IF(expires_at <= ?, ?, expires_at)` on MySQL / MariaDB, `INSERT … ON CONFLICT (key_hash) DO UPDATE … WHERE expires_at <= ?` elsewhere | Transactional with the write; no locking read and no update of a missing key, so no gap lock (audit QL-L1: the first S1 version updated before inserting and deadlocked on MySQL); the key itself is never stored |
| §4.2 `InboxInterface` | S1 ships `forgetContext()` only; unread count, pages and row operations come with the visibility re-check in S2 | No read API without read-time visibility |
| §6.1 write-time access (listed under S2 in §13) | In S1, with `PersonalOnlyAccessChecker` as the default | A 0.1.0 must not write context rows without the access check |
| §3 / §6.1 a type without permission and a context | Refused (deny by default; owner 2026-11-15) | The contract leaves the case open; Beacon gives every project type a permission |
| §4.2 failures of `notify()` | `TransientStorageFailure` propagates (409 + Retry-After + `CONFLICT_001` at the edge since 0.2.0; 503 was the 0.1 answer); retry only through `doctrine.transaction_runner` (owner 2026-11-15) | No silent loss of a notification; no hidden retries of a committed producer request |

## Audit QL (2026-11-15, PR #2) — fixed in S1

| ID | Fix |
| --- | --- |
| QL-M1 | Held events become *ready* on the outermost commit and run at a safe point (connection at level 0, or a boundary); listeners may open transactions, flush and call `notify()` |
| QL-M2 | The DBAL middleware sees `ROLLBACK TO SAVEPOINT` / `RELEASE SAVEPOINT` (`DOCTRINE_<n>`, DBAL 3 `DOCTRINE2_SAVEPOINT_<n>`): events of rolled-back savepoints are dropped |
| QL-M3 | Binary **NO PAD** collations on every identifier column (`utf8mb4_0900_bin` MySQL 8, `utf8mb4_nopad_bin` MariaDB; `utf8mb4_bin` only as the MySQL 5.7 fallback); byte comparison before LIMIT / OFFSET / GROUP BY on MySQL; `forgetContext()` walks the fetched rows by keyset; retention groups by the stored bytes |
| QL-L1 | One-statement dedupe claim (above) |
| QL-L2 | `RecipientRef` / `ContextRef` ids refuse edge white space, NBSP, ZWSP, BOM, bidi marks and other invisible characters (never trimmed). `target` and `dedupeKey` stay permissive (C0 / DEL refused): they are matched as given and never shown |
| QL-L3 | Every anchored pattern uses `/D` (a trailing newline no longer passes) |

## Deferred to S2 and later (audit QL)

| ID | Slice | Item |
| --- | --- | --- |
| QL-L4 | S2 | `DoctrineRecipientResolver::userOf()` uses `Query::HINT_REFRESH`, which overwrites a managed, dirty user: return the managed instance (`UnitOfWork::tryGetById()`) or query without refresh, then detach |
| QL-I1 | S2 / S4 | Titles with Twig autoescape only (no `\|raw`), bidi isolation (`<bdi>` / `dir=auto`), built-in placeholders (`%actor%`, `%target%`) applied after the params so a param cannot spoof them; strip CR/LF from values in mail subjects and headers; re-check `_redirect` with `isLocalPath()` after decoding |
| QL-I2 | S5 (docs) | Dedupe keys are global: document a `<type>:<target>:<state>` convention or hash the type into the key |
| QL-I3 | S3 | Cap `NotificationRequest::$involved` at `fan_out.max_recipients` |
| QL-I4 | S4 | Retention: deadline check inside the per-recipient trim loop; keyset instead of `OFFSET keep` |
| QL-I5 | S2 (docs) | A host that commits in `kernel.terminate` after the main `finish_request`: its events run at `kernel.terminate` (drain), document the order |
| QL-I6 | S2 | Application-level unit-of-work port instead of importing `Infrastructure\Persistence\NotifyKitTransactions` in Application / Domain |
| QL-I7 | S2 | Tag the after-commit middleware for the bundle connection only |
| QL-I8 | S2 / S4 | Batch `contextsGranting()` per permission instead of one call per recipient |

## Planned for 0.2.0 (delta audit QL-D, 2026-11-15)

| ID | Item |
| --- | --- |
| QL-D-L1 | Track host-named savepoints (`SAVEPOINT <name>` in `exec()` and `query()`) as markers in the held list: `ROLLBACK TO SAVEPOINT <name>` drops what was held after the marker, `RELEASE` pops it; until then documented as a 0.1.0 known issue |
| QL-D-L2 | MariaDB 11.4 job in the real-database CI matrix; `ReferenceDdlTest` checks the MariaDB block and `SchemaTest` expects `NotifyKitMetadataListener::collation($platform)` |
| QL-D-I2 | Document that joined events wait for the next bundle call at level 0 or the boundary (long console batches hold them until `console.terminate`), and that `reset()` drains without checking the level (a host transaction left open at the boundary receives the listeners' writes) |
| QL-D-I3 | Optionally widen `Guard::reference()` to every `\p{Zs}` except U+0020 and the Hangul fillers (U+3164, U+115F) inside an id |
| QL-D-I4 | REQ-SEC-006 rule 8: a log line or an INSTALLATION note when MySQL 5.7 falls back to `utf8mb4_bin` |
