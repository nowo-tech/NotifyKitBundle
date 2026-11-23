# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-B). Every slice is a pull request on
`nowo-tech/NotifyKitBundle`. Under the 2026-11-07 batch-audit rule, S2 (access and visibility) and S6 (public API) need
an **immediate** REQ-SEC-004 audit before a tag that contains them; the rest is audited in the batch before 1.0.0.

## Table of contents

- [Slices](#slices)
- [Road to 1.0.0](#road-to-100)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Beacon answers](#beacon-answers)
- [S1 notes (contract deviations)](#s1-notes-contract-deviations)
- [S2 notes (contract deviations)](#s2-notes-contract-deviations)
- [S3 notes (contract deviations)](#s3-notes-contract-deviations)

## Slices

| Slice | Version | Content | Audit | Status (2026-11-23) |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL; Mercure joins in S2), strict config tree `enabled: false`. **Then the owner registers the package on Packagist** | Batched | Released (on `main`, packaged in v0.1.0) |
| **S1** core inbox | 0.1.0 | Domain + XML / attribute mapping, DBAL stores, `notify()` with the in-app channel, coalescing, after-commit buffer, events, type registry (config + tagged), retention command + message, schema-check; plus write-time access (deny by default), the Doctrine recipient resolver, `forgetContext()`, dedupe keys and the load-readiness pattern (transaction-contract, real-database CI job) | Batched | Released (v0.1.0 2026-11-15; v0.1.1 and v0.2.0 are maintenance / concurrency releases) |
| **S2** access + UI | 0.3.0 (next free tag) | `RecipientAccessCheckerInterface`, visibility, row operations, bell (Twig + script + CSS; Stimulus peer), inbox page, CSRF, realtime (Mercure + poll), operator page. Demo realtime: poll (Mercure covered by the kernel tests) | **Immediate** (before the tag) | Released (v0.3.0 2026-11-23, PR #11; REQ-SEC-004 appendix ZAJ) |
| **S3** preferences | 0.4.0 (after S2's 0.3.0) | Preference store + matrix, contexts, all / involved, master switch, preferences page, AuditKit bridge; QL-I3, QL-I6, QL-I7 | Batched | In review (branch `feat/s3-preferences`, stacked on S2's PR #11); spec [`specs/004-s3-preferences`](../specs/004-s3-preferences/spec.md) |
| **S4** channels | 0.4.0 | Async fan-out, delivery idempotency, mail (Mailer + MailKit), push (PwaBundle), Slack (SlackKit), quiet hours, digests, rate limits, scheduler recipe | Batched | Not started |
| **S5** GDPR + bridges | 0.5.0 | UserKit eraser + export (+ actor unlinking), ChatKit bridge, ApiToken expiring bridge, InstanceSettings provider, translations ×7, docs | Batched | Not started |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | `<prefix>/notifications/v1` + OpenAPI (off by default), `bundle-req-audit` 100 %, SEC-004 batch audit on S0–S6 | **Immediate** for the API | Not started |
| Beacon | after 1.0.0 | Contract §12 phase 1, then phase 2 (Slack DM) | Beacon's own | Waiting for 1.0.0 |

## Road to 1.0.0

State on 2026-11-23: tags `v0.1.0` (S1 core inbox), `v0.1.1` (prune handler by attribute) and `v0.2.0` (2026-11-18,
**concurrency release, not slice S2**: 409 + `CONFLICT_001` / `CONFLICT_002`, MariaDB leg, `docs/CONCURRENCY.md`, closes
QL-D-L2). `main` has two unreleased, non-runtime changes (transaction-doctrine `^1.8.1`, demo in UiKit's platform
shell). No open pull request.

Because `0.2.0` was used by the concurrency release, the slice-to-version column is a plan: S2 to S6 take the next free
`0.x` tag (`0.3.0` and up), one per slice after its audit, and S6 ends in `1.0.0`.

What is left:

- **Slices S2 to S6**: S2 (access and UI: bell, inbox page, realtime, operator page) is in review; preferences,
  channels, GDPR and bridges, public API are not started. S2 and the S6 API need an **immediate** REQ-SEC-004 audit
  before their tags.
- **Deferred audit items** (tables below): S2 closes QL-L4, QL-I1 (in-app), QL-I5 and QL-I8 (read side); QL-I3,
  QL-I4 and QL-I8 (write side) remain for S4 (S3 closes QL-I3, QL-I6 and QL-I7); QL-I2 is docs; QL-D-L1 (host-named savepoints, known issue of 0.1.x, still open), QL-D-I2, QL-D-I3, QL-D-I4 (QL-D-L2 closed in 0.2.0).
- **REQ gaps:** none open for what is built (checklist row at v0.2.0: 112 ok, 0 gaps; REQ-RUNTIME-003 closed by 0.2.0).
  REQs deferred to the unbuilt slices and the final `bundle-req-audit` at 100 % of the applicable REQs remain.
- **REQ-SEC-004:** appendix QL (0.1.0 S1), SE and SI (batches, 0.1.1 / 0.1.2 checks) and TJ (0.2.0, release OK) cover
  everything released. S2 needs its immediate audit (access control, read-time visibility, CSRF, realtime topics)
  before `0.3.0`; nothing covers S3 to S6 yet.
- **Owner decisions:** none open. Q-O1 to Q-O12 answered 2026-11-12, S1 deviations accepted 2026-11-15, Beacon Q-B1 to
  Q-B6 answered as recommended. The API in S6 needs ApiTokenBundle (module `notifications`), which is itself before 1.0.0.
- **Final gate:** `bundle-req-audit` at 100 %, then the REQ-SEC-004 batch audit over S0 to S6, then `1.0.0`.

Distance to 1.0.0: large (S2 in review, four slices not started).

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

Status after S2: QL-L4, QL-I1 (in-app part) and QL-I5 are done; QL-I8 is done at read time (one checker call per
permission) and stays open for the write-time fan-out (S4); QL-I6 and QL-I7 move to S3.

| ID | Slice | Item |
| --- | --- | --- |
| QL-L4 | S2 ✅ | `DoctrineRecipientResolver::userOf()` uses `Query::HINT_REFRESH`, which overwrites a managed, dirty user: return the managed instance (`UnitOfWork::tryGetById()`) or query without refresh, then detach |
| QL-I1 | S2 ✅ in-app / S4 mail | Titles with Twig autoescape only (no `\|raw`), bidi isolation (`<bdi>` / `dir=auto`), built-in placeholders (`%actor%`, `%target%`) applied after the params so a param cannot spoof them; strip CR/LF from values in mail subjects and headers; re-check `_redirect` with `isLocalPath()` after decoding |
| QL-I2 | S5 (docs) | Dedupe keys are global: document a `<type>:<target>:<state>` convention or hash the type into the key |
| QL-I3 | S3 ✅ | Cap `NotificationRequest::$involved` at `fan_out.max_recipients` |
| QL-I4 | S4 | Retention: deadline check inside the per-recipient trim loop; keyset instead of `OFFSET keep` |
| QL-I5 | S2 ✅ (docs, USAGE) | A host that commits in `kernel.terminate` after the main `finish_request`: its events run at `kernel.terminate` (drain), document the order |
| QL-I6 | S3 ✅ | Application-level unit-of-work port (`Application\UnitOfWorkInterface`) instead of importing `Infrastructure\Persistence\NotifyKitTransactions` in Application / Domain |
| QL-I7 | S3 ✅ | Tag the after-commit middleware for the bundle connection only (`AfterCommitMiddlewarePass`) |
| QL-I8 | S2 ✅ read / S4 write | Batch `contextsGranting()` per permission instead of one call per recipient |

## Delta audit QL-D items (2026-11-15)

These were planned for 0.2.0, but 0.2.0 shipped as the concurrency release. Only QL-D-L2 (MariaDB job) was delivered there;
the others stay open and ride with the next slices.

| ID | Item |
| --- | --- |
| QL-D-L1 | Track host-named savepoints (`SAVEPOINT <name>` in `exec()` and `query()`) as markers in the held list: `ROLLBACK TO SAVEPOINT <name>` drops what was held after the marker, `RELEASE` pops it; until then documented as a 0.1.0 known issue |
| QL-D-L2 | MariaDB 11.4 job in the real-database CI matrix; `ReferenceDdlTest` checks the MariaDB block and `SchemaTest` expects `NotifyKitMetadataListener::collation($platform)` |
| QL-D-I2 | Document that joined events wait for the next bundle call at level 0 or the boundary (long console batches hold them until `console.terminate`), and that `reset()` drains without checking the level (a host transaction left open at the boundary receives the listeners' writes) |
| QL-D-I3 | Optionally widen `Guard::reference()` to every `\p{Zs}` except U+0020 and the Hangul fillers (U+3164, U+115F) inside an id |
| QL-D-I4 | REQ-SEC-006 rule 8: a log line or an INSTALLATION note when MySQL 5.7 falls back to `utf8mb4_bin` |

## S2 notes (contract deviations)

| Contract | S2 | Why |
| --- | --- | --- |
| §8.1 Stimulus controller `nowo-notify-kit--bell` | A no-build script (`js/nowo-notify-kit-bell.js`) binds every bell; the Stimulus controller ships as an optional peer that runs the same code | UiKit house style: hosts without Symfony UX Stimulus get a working bell |
| §6.4 `limits.ui_writes` (120 / minute) | Not enforced yet; the S2 endpoints need a logged-in person, CSRF and touch only that person's rows | The ROADMAP lists rate limits under S4 (with `per_recipient_channel`) |
| §6.6 `NotificationReadEvent` | Also dispatched for *mark unread* (`read: false`) | One event for every read-state change |
| §4.3 `NotificationPresenterInterface` | Not shipped; in-app titles come from the type's translation key | Rich presentations are for the out-of-band channels (S4) |
| §8.4 operator page "delivery failures, digest backlog, last retention run" | Counts of the inbox and the realtime mode; *Run retention now* is logged | Deliveries and digests arrive with S4; AuditKit with S3 |
| §6.4 poll interval ≥ 30 s | `realtime.poll_seconds` defaults to 60 and accepts 10 to 3600 | The demo polls every 10 s; hosts keep the 60 s default unless they lower it |
| §14 demo + Mercure | The demo polls every 10 s | Mercure is covered by the kernel tests (private update, hashed topic, subscriber cookie) |

## S3 notes (contract deviations)

| Contract | S3 | Why |
| --- | --- | --- |
| §4.2 `PreferencesInterface` (matrix, quiet hours, digest) | S3 ships `matrixFor()`, `save()` and `resetContext()`; `quietHours()` / `saveQuietHours()` / `digest()` / `saveDigest()` and `notify_channel_setting` come with S4 | Quiet hours and digests only matter for the out-of-band channels (S4) |
| §4.2 `save(): void` | `save()` returns the changed cells (`list<PreferenceKey>`) and takes per-cell expectations (`PreferenceExpectation`) | REQ-RUNTIME-003: optimistic concurrency of the autosave (409 + `CONFLICT_001` when stale) |
| §4.2 (no reset method) | `resetContext()` for *Reset to account settings* (§8.3) | The page needs it; a set of per-cell deletes would need every key |
| §8.3 matrix columns | `in_app` plus the channels the registered types name; out-of-band channels are stored and readable now, delivered from S4 | Beacon can read the preferences for its member alerts before S4 (§12.2); no channel SPI yet |
| §8.3 "searchable list" of contexts | A plain list of the provider's contexts (with their group) | A search field joins when a host has many contexts (Beacon: tens of projects) |
| §8.3 Push / Slack device links, quiet hours, digest | Not on the page | S4 (channels) |
| §6.4 `limits.ui_writes` | Still not enforced (S4, with the other rate limits) | As in S2: logged-in person, CSRF, own rows only |
| §6.7 audit of the channel switch | Only preference changes and *Run retention now* are audited | There is no operator channel switch before S4 |
| §6.7 AuditKit bridge | Wired only when AuditKit flushes in `isolated` mode (2.x default); `<2.0` is a conflict | Audit ZAL-1: AuditKit's `host` mode would flush the host's entity manager (REQ-ARCH-001 rule 6) |
| §5.1 "only deviations from the type / category defaults" | A type shown in the matrix takes its category's default when nothing is stored (on when one of the category's types is on) | Audit ZAL-3: what the page shows is what is delivered |

