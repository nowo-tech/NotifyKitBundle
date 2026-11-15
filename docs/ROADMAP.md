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
| **S1** core inbox ✅ (PR) | 0.1.0 | Domain + XML / attribute mapping, DBAL stores, `notify()` with the in-app channel, coalescing, after-commit buffer, events, type registry (config + tagged), retention command + message, schema-check; plus write-time access (deny by default), the Doctrine recipient resolver, `forgetContext()`, dedupe keys and the load-readiness pattern (transaction-contract, real-database CI job) | Batched |
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

Beacon accepted every recommendation of contract §15:

| # | Answer |
| --- | --- |
| Q-B1 | Bundle table names (`nowo_notify_*`); the old tables are dropped one release after the copy |
| Q-B2 | Slack DM preferences, quiet hours and digest move in phase 2 |
| Q-B3 | The bundle Mercure topic (`notify-kit/r/<hmac>`) |
| Q-B4 | Recipient ids are user UUIDs |
| Q-B5 | Issue toasts and the Web Push presentation of `issue.*` types stay in Beacon (a presenter) |
| Q-B6 | Beacon calls `InboxInterface::forgetContext(project)` from its project deletion flow |

## S1 notes (contract deviations)

| Contract | S1 | Why |
| --- | --- | --- |
| §5.3: a request inside a host transaction is held and processed on the outermost commit | The rows are written **inside** the host transaction (they commit or roll back with it); only the events wait for the outermost commit | A DBAL driver middleware sees the commit while the wrapper connection is still at level 1, so no write can run there; joining keeps "nothing announced, nothing written on rollback" |
| §5.3: coalescing with `SELECT … FOR UPDATE` | Plain read of the candidate, then `UPDATE … SET repeats = repeats + 1 WHERE id = ? AND read_at IS NULL` | `FOR UPDATE` on a missing row takes a gap lock on MySQL: two concurrent first notifications deadlocked in the real-database job. The row lock of the update keeps "no lost update" |
| §4.1 `dedupeKey` (no table in §5.1) | New table `<prefix>notify_dedupe` (`sha256` of the key, `expires_at`), claimed with `INSERT IGNORE` / `ON CONFLICT DO NOTHING` | Transactional with the write; no gap lock; the key itself is never stored |
| §4.2 `InboxInterface` | S1 ships `forgetContext()` only; unread count, pages and row operations come with the visibility re-check in S2 | No read API without read-time visibility |
| §6.1 write-time access (listed under S2 in §13) | In S1, with `PersonalOnlyAccessChecker` as the default | A 0.1.0 must not write context rows without the access check |
| §3 / §6.1 a type without permission and a context | Refused (deny by default) | The contract leaves the case open; Beacon gives every project type a permission |
