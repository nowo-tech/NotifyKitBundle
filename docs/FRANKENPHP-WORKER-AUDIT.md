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
| Cross-user leakage | ✅ | `WorkerRequestLoopTest`: no row of A is ever returned to B, the unread count only grows with the user's own rows, `heldCount()` is 0 at the start of every request; S2: 2,000 alternating requests of two people (notify, count, bell menu, *mark all as read* with CSRF, another session's token refused) on one kept-alive kernel through the real firewall and endpoints; S3: 2,000 alternating preference saves (autosave with tokens) and page loads of two people, each always seeing only their own choice, memory flat |
| Memory growth | ✅ | < 1 MiB drift after warm-up over 2,000 requests (both loops) |
| Per-request read state (S2) | ✅ | None kept: the visibility, the unread count of the bell and the current person are computed per call (no memo), so nothing can leak between requests |
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
| `Application\Visibility\VisibilityResolver`, `Realtime\RealtimeTopics`, `Realtime\UnreadBadge`, `Default\ResolverRecipientDirectory`, `Default\NoContextProvider` (S2) | `final readonly`; the visibility lives in the caller's call | ✅ |
| `Infrastructure\Realtime\MercureRealtimePublisher`, `MercureSubscription` (S2) | `final readonly`; the hub is the host's service; the subscriber cookie goes into the current request's attributes (`@igor-ignore` with justification) | ✅ |
| `Application\Preferences\Preferences`, `PreferenceDefaults`, `Audit\*`, `Bridge\AuditKit\AuditKitAuditTrail`, `Infrastructure\Persistence\DbalPreferenceStore`, `Presentation\View\PreferencePages`, `PreferencesController` (S3) | `final readonly`; the matrix, its defaults and the person's contexts are built per call from the immutable registry and the ports; nothing is memoised | ✅ |
| `Presentation\Controller\*`, `Twig\BellRenderer`, `Http\CurrentRecipient`, `Security\RoleAccessChecker`, `Title\NotificationTitles`, `View\RelativeTime` (S2) | `final readonly`; the person comes from the token storage per call | ✅ |

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
