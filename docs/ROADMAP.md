# Roadmap

Built slice by slice from [CONTRACT.md](CONTRACT.md) (contract 221-B). Every slice is a pull request on
`nowo-tech/NotifyKitBundle`. Under the 2026-11-07 batch-audit rule, S2 (access and visibility) and S6 (public API) need
an **immediate** REQ-SEC-004 audit before a tag that contains them; the rest is audited in the batch before 1.0.0.

## Table of contents

- [Slices](#slices)
- [Owner decisions (2026-11-12)](#owner-decisions-2026-11-12)
- [Open questions for Beacon](#open-questions-for-beacon)

## Slices

| Slice | Version | Content | Audit |
| --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SQLite / MySQL / PostgreSQL job), PHPStan + worker rulesets, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL; Mercure joins in S2), strict config tree `enabled: false`. **Then the owner registers the package on Packagist** | Batched |
| **S1** core inbox | 0.1.0 | Domain + XML / attribute mapping, DBAL stores, `notify()` with the in-app channel, coalescing, after-commit buffer, events, type registry (config + tagged), retention command + message, schema-check | Batched |
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

## Open questions for Beacon

Still open (contract §15, "For Beacon"); the recommendation is what the bundle will assume unless Beacon answers
otherwise.

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Table names: bundle defaults (`nowo_notify_*`) or Beacon names through `table_prefix`? | Bundle defaults; drop the old tables one release after the copy |
| Q-B2 | Slack DM preferences, quiet hours and digest in phase 1 or phase 2 of the swap? | Phase 2 |
| Q-B3 | Realtime topic: keep `IssueRealtimeTopics::forUser(uuid)` through your own `RealtimePublisherInterface`, or the bundle topic? | Keep yours in phase 1 |
| Q-B4 | Recipient id: user UUID or integer id? | UUID |
| Q-B5 | Issue toasts and Web Push presentation stay yours as a presenter for `issue.*` types? | Yes |
| Q-B6 | Project deletion calls `InboxInterface::forgetContext(project)` (no FK cascade any more)? | Yes |
