# Upgrading

## Table of contents

- [Unreleased](#unreleased)
  - [S3: preferences](#s3-preferences)
  - [S2: inbox, bell and pages](#s2-inbox-bell-and-pages)
- [To 0.2.0](#to-020)
  - [Lock conflicts answer 409 + CONFLICT codes](#lock-conflicts-answer-409--conflict-codes)
- [To 0.1.1](#to-011)
- [To 0.1.0](#to-010)

## Unreleased

### S3: preferences

```bash
composer update nowo-tech/notify-kit-bundle
php bin/console nowo:notify-kit:schema-check    # shows the CREATE TABLE of <prefix>notify_preference
php bin/console assets:install                  # js/nowo-notify-kit-preferences.js
```

- **Schema (additive)**: one new table, `<prefix>notify_preference` (reference DDL in
  [INSTALLATION.md](INSTALLATION.md)). Create it with your migrations (`doctrine:migrations:diff` picks it up from the
  bundle mapping) before you deploy. Until it exists, `notify()` of a type with `in_app_optional: true` and the
  preferences page fail; nothing else reads it.
- **`notify()`**: a type with `in_app_optional: true` now follows each person's preferences for its bell row (with no
  stored preference the result is the same as before). Types with the default locked bell row are unchanged and read
  nothing new.
- **`NotificationRequest::$involved`** above `fan_out.max_recipients` (default 1,000) is now refused with
  `InvalidNotificationArgumentException` (audit QL-I3).
- **The page** (`web_ui.enabled`): `GET/POST {web_ui.preferences_path}` (default `/account/notifications`) plus
  `…/cells`, `…/context`, `…/context/reset` come with the routes you already import. Add an `access_control` line for the
  path; `web_ui.security.preferences_roles` (default: `access_roles`) and `preferences_access_checker` set who may open
  it. Per-context switches need `preferences.contexts.provider`: only the contexts it lists for the person can be
  tuned.
- **AuditKit**: with nowo-tech/audit-kit-bundle's action log on, `bridges.audit_kit: auto` (default) starts recording
  `notify.preferences_changed` and `notify.retention_run`. Set it to `false` to keep them out.
- **Internal**: the application services take `Application\UnitOfWorkInterface` (audit QL-I6); the after-commit DBAL
  middleware now wraps only the bundle connection (audit QL-I7). A host that constructed `Notifier`, `Inbox` or
  `Retention` by hand keeps working (`NotifyKitTransactions` implements the port).

### S2: inbox, bell and pages

```bash
composer update nowo-tech/notify-kit-bundle --with-all-dependencies
php bin/console assets:install
```

- **New dependencies**: `nowo-tech/ui-kit-bundle` ^2.68, `nowo-tech/form-kit-bundle` ^2.24, the Symfony asset, form,
  framework-bundle, http-foundation, routing, security-core / security-csrf, translation and Twig packages, and
  `twig/extra-bundle` + `twig/string-extra`. Register `NowoUiKitBundle`,
  `NowoFormKitBundle` and `TwigExtraBundle` if Flex did not.
- **No schema change.** The read side uses the existing indexes.
- **Nothing changes until you turn it on.** `web_ui.enabled` and `admin_ui.enabled` stay `false`; `realtime.mode: auto`
  only publishes when a Mercure hub service exists (otherwise it is `poll`, and nothing is published).
- **Hosts that already run a Mercure hub**: with the default `realtime.mode: auto`, `notify()` and the row operations
  now publish a private update (the unread count only) on `notify-kit/r/<hmac>` after each commit, even while
  `web_ui.enabled` is `false`. **Cost**: synchronously, per written recipient, one visibility check, one count query
  and one HTTP call to the hub (a `notify()` to 1,000 people makes 1,000 hub calls; the first failure stops the rest of
  that call). Set `realtime.mode: off` to keep the 0.2 behaviour, or keep the hub off the request with a short hub
  timeout or a Messenger publisher ([USAGE](USAGE.md#what-publishing-costs)). The topic is derived from `kernel.secret`:
  rotating the secret changes every topic (open tabs pick up the new one on the next page load).
- **symfony/mercure 0.10 or later** when it is installed (`conflict: <0.10`): older versions throw when the bell and
  the host set the subscriber cookie in the same request. There is one subscriber cookie per hub path, so the bell's
  cookie and a cookie you set on another request replace each other: add `RealtimeTopics::subscribeClaimFor()` to your
  own grants, or set `realtime.subscriber_cookie: false` and issue the JWT yourself
  ([USAGE](USAGE.md#the-mercure-subscriber-cookie)).
- To use the pages: import `@NowoNotifyKitBundle/Resources/config/routes.php`, set `recipient.class` or
  `recipient.resolver` (the container refuses `web_ui` without one), keep SecurityBundle and add `access_control` for
  `web_ui.path_prefix` (`ROLE_USER`) and `admin_ui.path_prefix` (`ROLE_ADMIN`); render `{{ notify_kit_bell() }}` in
  your header. See [USAGE](USAGE.md#inbox-bell-and-pages-s2).
- **`InboxInterface`** gains `unreadCount()`, `page()`, `markRead()`, `markUnread()` and `markAllRead()` (it was
  documented as bundle-implemented only). **`TypeDefinition`** gains an optional last argument `titleDomain`.
- **Read-time visibility**: rows with a context whose permission the checker no longer grants are hidden (and not
  counted) at once; a row with a stored context the value objects refuse is hidden for everyone.
- `DoctrineRecipientResolver::userOf()` returns the managed instance untouched (audit QL-L4); a user it loads is still
  detached.

## To 0.2.0

From **0.1.1**:

```bash
composer update nowo-tech/notify-kit-bundle
```

### Lock conflicts answer 409 + CONFLICT codes

```bash
composer update nowo-tech/notify-kit-bundle nowo-tech/transaction-doctrine
```

`nowo-tech/transaction-doctrine` ^1.5 is required (was ^1.1). No migration, no configuration change.

The bundle has no HTTP layer, so nothing changes in what it throws: a deadlock, lock wait timeout or serialization
failure is still a `Nowo\TransactionContract\TransientStorageFailure` with nothing written. What changes is the answer
the documentation tells you to give (REQ-RUNTIME-003 rule 6, see [CONCURRENCY](CONCURRENCY.md)):

| Failure | 0.1 | Now |
| --- | --- | --- |
| Lock conflict, nothing committed | 503 + `Retry-After` | **409** + `Retry-After`, code `CONFLICT_001` |
| Same, after an earlier write unit of your request committed | 503 + `Retry-After` | **409**, no `Retry-After`, code `CONFLICT_002` |
| Unique violation, lost connection | not mapped | not mapped |

- If you answered the exception yourself, switch the status and add the `code`. Clients that retried on 503 should
  retry on 409 with `CONFLICT_001` and reload, not retry, on `CONFLICT_002`.
- If you use nowo-tech/transaction-doctrine's Symfony bundle, `conflict_response.map_transient_failures: true` answers it
  for you (JSON or HTML, with the code in the body). `conflict_response.status: 503` keeps 503 for the whole application.

## To 0.1.1

From **0.1.0**:

```bash
composer update nowo-tech/notify-kit-bundle
```

Nothing yet.

## To 0.1.0

S1 core inbox, released 2026-11-15. First release with runtime code. With `enabled: true`:

- **DoctrineBundle is required** (the container refuses to build without it).
- **Two new tables**: `<prefix>notify_notification` and `<prefix>notify_dedupe` (default prefix `nowo_`). Generate a
  migration (`doctrine:migrations:diff`) and compare it with the reference DDL in
  [INSTALLATION.md](INSTALLATION.md#reference-ddl); then `php bin/console nowo:notify-kit:schema-check`.
- On MySQL / MariaDB the identifier columns use `utf8mb4_0900_bin` (MySQL 8) or `utf8mb4_nopad_bin` (MariaDB), binary
  NO PAD; MySQL 5.7 gets `utf8mb4_bin`.
- Joined events (a `notify()` inside your transaction) run after your commit at the next safe point, at the latest at
  the request / command / message boundary.
- MariaDB: create the tables from the MariaDB reference DDL (`utf8mb4_nopad_bin`), not the MySQL 8 one; see the known
  issues of 0.1.0 in the [CHANGELOG](CHANGELOG.md).
- **New configuration key** `doctrine.transaction_runner` (default `null`).
- `access.default_permission` now needs `access.checker`, like a type with a permission (deny by default).
- `InboxInterface` is bundle-implemented: do not implement it in your application, it gains methods in S2.

Every schema change in 1.x is additive and will be listed here with its DDL.
