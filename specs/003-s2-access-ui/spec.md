# Feature Specification: S2 access + UI

**Feature Branch**: `feat/s2-access-ui`
**Created**: 2026-11-23
**Status**: In review (next minor, 0.3.0; immediate REQ-SEC-004 audit before the tag)
**Input**: Contract 221-B §4.2, §4.3, §6.1–§6.6, §7.1, §8.1, §8.2, §8.4, §8.5, §11, §13 (S2) and §14; owner Q-O answers
(2026-11-12) and S1 decisions (2026-11-15); deferred audit items QL-L4, QL-I1, QL-I5, QL-I8; house bar
(`BUNDLES_FIX_RULES.md`: REQ-ARCH-001, REQ-RUNTIME-002/003, REQ-SEC-006, REQ-UI-001/002/003, REQ-TWIG-005, Chrome Modern
Web Guidance).

Deviations from the contract text are listed in [`docs/ROADMAP.md`](../../docs/ROADMAP.md#s2-notes-contract-deviations).

## Requirements

| ID | Requirement |
| --- | --- |
| FR-VIS-001 | Read-time visibility: personal rows always; a context row only while the checker grants the type's permission there (unknown types: `access.default_permission`; a permission-less type: never); unrestricted people see all; one checker call per permission with every candidate context; non-candidates ignored; damaged stored contexts hidden for everyone. |
| FR-INBOX-001 | `unreadCount()` and `page()` (keyset newest first; filters type, category, status; invalid cursor = first page; limit 1–200) return visible rows of the person only (SQL exact match + PHP `===`). |
| FR-INBOX-002 | `markRead()` / `markUnread()` by id and recipient with the visibility re-check (false = 404), conditional idempotent `UPDATE`; `markAllRead()` marks visible unread rows in batches; `NotificationReadEvent` and the realtime badge after the commit, only when something changed; nothing announced on rollback. |
| FR-RT-001 | Realtime: Mercure private updates `{event, unread}` on `notify-kit/r/<hmac>`, subscriber cookie for the person's own topic only; `auto` → Mercure with a hub, else poll; `mercure` without a hub refuses to build; host publisher used as is; publisher failures logged, never thrown. |
| FR-UI-001 | Bell (`notify_kit_bell()`): nothing for guests or people without access; `<details>` works without JS; badge `99+`; polite live region; name from content (WCAG 2.5.3); script: menu loaded on first open, read / unread / all with `X-CSRF-TOKEN`, open marks read (≤ 1.5 s), Escape and outside click close, Mercure / host event / poll paused while hidden. |
| FR-UI-002 | Inbox page (UiKit list scaffold, FormKit filter, keyset pages, per-row read / unread, mark all) and operator page (counts only, *Run retention now*), both passing the UiKit page contract with strict translations. |
| FR-HTTP-001 | Endpoints: anonymous → firewall login answer; no role / refused → 403; another person's or a hidden row → 404; POST without a valid CSRF token → 403; `_redirect` local path only (before and after decoding); `no-store, private`; count `ETag` / `304`; lock conflicts 409 + `CONFLICT_001` + `Retry-After`. |
| FR-SEC-UI-001 | REQ-UI-002: `web_ui.security.{access_roles, access_checker}`, `admin_ui.security.{access_roles, access_checker, allow_unauthenticated}`; the pages refuse to build without Twig, forms, CSRF or SecurityBundle (operator page of a demo excepted). |
| FR-TITLE-001 | Titles at display time: type key in its domain, params as `%key%`, then `%actor%` (directory name or "Someone"), `%target%`, `%repeats%`; one line; escaped inside `<bdi dir="auto">`. |
| FR-QL-L4 | `DoctrineRecipientResolver::userOf()` returns a managed user untouched and detaches what it loads. |
| FR-CONC-002 | Two connections on MySQL, MariaDB and PostgreSQL: mark read waiting on a repeat's row lock is transient (CONFLICT_001, nothing written, level 0) and the retry succeeds; mark all read against concurrent repeats never loses a repeat. |
| FR-WORKER-003 | 2,000 alternating requests of two people through the real firewall and endpoints (notify, count, menu, mark all with CSRF, another session's token refused) on one kept-alive kernel: no cross-person rows, flat memory. |
| FR-DEMO-002 | Demo: sign in as Alice / Bob, bell, inbox, operator page; Playwright: bell, mark read, live badge, deny by default, axe on the bell, inbox and operator pages; WebP screenshots. |

## Success criteria

- CI green: PHP / Symfony matrix, coverage 100 % (PHP), TypeScript (typecheck, Vitest ≥ 90 %, committed build), PHPStan
  (worker rulesets), CS, Rector, Igor, databases (SQLite, MySQL 8.4, MariaDB 11.4, PostgreSQL 16 on DBAL 3 and 4), demo
  smoke.
- Immediate REQ-SEC-004 audit before the 0.3.0 tag.

## Validation commands

```bash
make test-coverage-100 && make test-ts
NOWO_TEST_DATABASE_URL='mysql://root@127.0.0.1:3306/app?serverVersion=8.4.0&charset=utf8mb4' vendor/bin/phpunit --testsuite Database
make -C demo/symfony8 up test-e2e
```
