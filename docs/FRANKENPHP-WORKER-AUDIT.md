# FrankenPHP worker mode audit (kernel not reset between requests)

| Field | Value |
|-------|-------|
| Package | `nowo-tech/notify-kit-bundle` (`symfony-bundle`) |
| Audited revision | S1 core inbox (`feat/s1`, first tag 0.1.0) |
| Audit date | 2026-11-15 |
| Method | Manual review of every file under `src/`; PHPStan `ruleset-classic` + `ruleset-worker-strict` + `ruleset-hardening`; Igor (`composer igor`); `WorkerRequestLoopTest` (one kernel, no resetter, 2,000 requests of two users alternating: each request notifies one user and reads that user's inbox; memory drift < 1 MiB) |
| **Verdict** | ✅ **Viable** — every S1 service is stateless except the after-commit queue, which the bundle clears at every boundary itself |

## Table of contents

- [Execution model assumed](#execution-model-assumed)
- [Summary](#summary)
- [Services reviewed](#services-reviewed)
- [Rules for the next slices](#rules-for-the-next-slices)
- [Re-audit triggers](#re-audit-triggers)

## Execution model assumed

FrankenPHP worker mode boots the kernel once and serves many requests with the same container. The bundle must work
in the strictest case: **kernel kept alive and NOT reset** (`FRANKENPHP_RESET_KERNEL=false`), with no services
resetter between requests.

## Summary

| Area | Status | Notes |
|------|--------|-------|
| Mutable state in shared services | ✅ | Only `AfterCommitQueue`: events of calls that joined a host transaction, held per native connection (`WeakMap`) and level, then *ready* after the commit until a safe point (audit QL-M1: never run inside the driver commit, so a listener cannot leave the host connection at nesting level 1 across requests) |
| `ResetInterface` / `kernel.reset` | ✅ | `AfterCommitQueue::reset()` (tag `kernel.reset`), never relied on alone: `AfterCommitQueueResetListener` drains the ready work and drops the held work on `kernel.finish_request` (main request), `kernel.terminate`, `console.terminate`, `WorkerMessageHandledEvent` and `WorkerMessageFailedEvent`; tested |
| Cross-user leakage | ✅ | `WorkerRequestLoopTest`: no row of A is ever returned to B, the unread count only grows with the user's own rows, `heldCount()` is 0 at the start of every request |
| Memory growth | ✅ | < 1 MiB drift after warm-up over 2,000 requests |
| Static properties / `static` locals | ✅ | None (the bundle's extension instance is the Symfony boot-time cache, `@igor-ignore` with justification) |
| Superglobals, `putenv`, `ini_set`, `setlocale`, timezone | ✅ | None; times are converted to UTC explicitly |
| Doctrine | ✅ | DBAL only, one transaction per call through a `transaction-contract` runner built per call on the current connection (reconnect-safe); no EntityManager is flushed, cleared or reset; no managed entity is kept (the Doctrine recipient resolver detaches what it loaded) |
| Resources held open | ✅ | None |

## Services reviewed

| Class | State | Verdict |
|-------|-------|---------|
| `NowoNotifyKitBundle` | Extension instance cached at boot | ✅ boot-time only |
| `DependencyInjection\*` | Container build only | ✅ |
| `Application\Notifier`, `Inbox`, `Retention\Retention`, `Default\PersonalOnlyAccessChecker` | `final readonly`, collaborators only | ✅ |
| `Application\TypeRegistry` | Types and categories merged once when built (configuration + tagged providers), immutable | ✅ boot data, not request data |
| `Infrastructure\Persistence\DbalNotificationStore`, `NotifyKitTransactions` | `final readonly`; connection resolved per call | ✅ |
| `Infrastructure\Persistence\AfterCommitQueue` | Held events per native connection | ✅ cleared at every boundary + `kernel.reset` (tested) |
| `Infrastructure\Persistence\AfterCommitMiddleware` / `AfterCommitConnection` | Wraps the driver; no state of its own | ✅ |
| `Infrastructure\Doctrine\NotifyKitMetadataListener` | Table prefix only | ✅ |
| `Infrastructure\Recipient\DoctrineRecipientResolver` | `final readonly`; detaches users it loaded | ✅ |
| `Infrastructure\Console\*`, `Messenger\PruneNotificationsHandler`, `Schema\SchemaChecker` | Stateless; retention cursor travels in the message | ✅ |

## Rules for the next slices

- No request, user, owner or tenant data in service properties, statics or process caches unless the bundle clears it
  at the request boundary (`kernel.request` / `kernel.terminate` / `kernel.finish_request`, and Messenger
  `WorkerMessageHandledEvent`); memos also implement `ResetInterface` with a test, never relied on alone.
- The bundle never flushes the host's EntityManager; writes use DBAL in an explicit transaction opened and closed in
  the call; a dropped connection is retried once.
- Rate-limit counters live in the cache pool, never in process memory.
- Every stateful path joins `WorkerRequestLoopTest` (N requests, two owners, flat memory).

## Re-audit triggers

Any new service, listener, memo, static, cache or Doctrine access; any change of the worker test.
