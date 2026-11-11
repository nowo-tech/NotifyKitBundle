> **Owner decision 2026-11-12:** extraction approved; every Q-O question below is answered with its
> recommendation. The Q-B questions (Beacon) stay open: see [ROADMAP.md](ROADMAP.md#open-questions-for-beacon).
> This copy is the design contract of the bundle; the code follows it slice by slice (S0 = scaffold only).

# Contract 221-B: in-app notification inbox and per-user preferences (NotifyKitBundle). Contract v0.1 for symfony-beacon and the matrix apps

Status 2026-11-11: **v0.1, design only, awaiting the owner (Q-O) and Beacon (Q-B).** No code, no repository, no PR,
no tag. The owner approved the extraction on 2026-11-11 (Beacon request
`docs/ops/BUNDLES-REQUESTS/2026-11-11-reaudit-extraction-candidates.md`, item 2). Sibling contracts in this folder:
`API-TOKEN-CONTRACT.md` (221-A) and `REPORT-KIT-CONTRACT.md` (221-C). Q-O11 and Q-O12 of 221-A (mapping rule, shared
owner VO) also apply here.

Read for this contract (2026-11-11):

| Package / source | Version read | What was read |
| --- | --- | --- |
| symfony-beacon (read-only) | `master` `c7414f65f` | `src/Notifications/Inbox/*` (`NotificationInbox`, `NotificationType`, `NotificationRealtime`, `NotificationAccess`, `NotificationVisibility`), `Entity/Notification.php`, `Repository/NotificationRepository.php`, `Controller/NotificationInboxController.php`, `Service/MemberAlertPreference{Manager,Evaluator}.php`, `Service/QuietHoursEvaluator.php`, `Service/NotificationDigestFlusher.php`, `Entity/{MemberAccountAlertEvent,MemberProjectAlertEvent,MemberProjectAlertPreference,SlackDmPreference,SlackDmDigestItem}.php`, `Enum/{MemberAlertEvent,MemberAlertScope}.php`, `NotificationCategories.php`, `Command/PruneNotificationsCommand.php`, `Twig/NotificationInboxExtension.php`, `Twig/Components/Member*Live.php`, `Realtime/MemberIssueRealtimeNotifier.php`, `Chat/Integration/SupportTicketNotifier.php`, `assets/controllers/notification_bell_controller.ts`, `templates/{_notification_bell,notifications/inbox/*,account/display_notifications}.html.twig`, `Shared/Scheduler/MaintenanceSchedule.php` |
| PwaBundle | main (clone) | `WebPush/WebPushSenderInterface`, `PushSubscriptionProviderInterface`, `PushSubscriptionData`, `WebPushEndpointGuard` |
| SlackKitBundle | v1.2.0 | `Api/SlackApiClientInterface` (`openDirectMessage`, `postMessage`), `Linking/AccountLinkerInterface::linkOf()` |
| MailKitBundle | v1.6.0 | `Brand/BrandedEmailHelper`, `Readiness/OutboundMailReadinessInterface`, `Log/SentMailErasureInterface` (no template catalogue yet: Beacon request A.0 #3) |
| ChatKitBundle | main | `Contract/*` (`ChatPushSenderInterface`, `ChatDigestSenderInterface`), support ticket events |
| UserKitBundle | v1.14.1 | `PersonalData/{PersonalDataEraserInterface,PersonalDataExportProviderInterface,ErasureRequest}` |
| UiKitBundle | v2.47.0 | header slots, `_dialog`, `ListPage`, `SettingsPage`, `REQ-UIKIT-012` live pending state (Mercure + bounded poll) |
| Rules | 2026-11-11 | `BUNDLES_FIX_RULES.md` (bar 1–8, worker mode, LQ binary collation, API paths, new-bundle bar), `BUNDLES_FULL_SPECS_DETAILS.md`, app REQs `REQ-DATA-001…004`, `REQ-MESSENGER-004/007/008/009`, `REQ-UIKIT-012`, `REQ-PWA-005/007`, `REQ-INSTANCE-008`, `REQ-IDENTITY-002` in `OTHER_FULL_SPECS_DETAILS.md` |

Facts that shaped the design:

1. **Two systems under one name in Beacon.** The *inbox* (`user_notification`, header bell, Dashboard →
   Notifications) has no preferences: every row is written. The *member alerts* (Mercure toasts, Web Push, Slack DM)
   have an opt-out matrix (account × event, project switch, project × event overrides, scope *all* / *involved*) but no
   inbox. NotifyKit joins them: one request, one type registry, the inbox as one channel among others.
2. `NotificationInbox::record()` only **persists**; the caller's flush writes the row, and `NotificationRealtime`
   publishes from a Doctrine `postFlush` listener. Both keep per-request state (`$pending`, `$queued`) cleared by
   `ResetInterface` only. A bundle may not rely on the host's flush (rule 6) nor on the resetter alone (worker rule).
3. Access is **deny by default** and checked twice: when the row is written (`canReceive`) and when it is listed
   (`NotificationVisibility`, permission per type → project ids). A row is looked up by id **and** recipient
   (`REQ-REV-009`). Unknown (newer) types still list with a generic title and icon. The URL is a local path only.
4. Rows store the **type and parameters, never translated text**; the title is built at display time in the
   viewer's language. Repeats of the same type + target within 600 s update the unread row instead of adding one.
5. Retention: read rows older than 90 days are deleted, and each recipient keeps at most 500 rows (daily command).
6. Quiet hours exist twice (destination-level, `QuietHoursEvaluator`; personal Slack DM, `SlackDmPreference` with
   weekdays and an *urgent bypass*). Digests exist twice (destination buffer; Slack DM digest items).
7. **GDPR gap in Beacon:** `user_notification` and the preference tables are erased only through `ON DELETE CASCADE`
   on the user FK and are **not exported** (only push subscriptions have an eraser and an export provider). The
   actor link on other people's rows is `SET NULL` by FK. A bundle with ID references (no FK) must do this in code.

---

## 1. Scope

**In (generic, moves out of Beacon):**

- **Notification request API.** One entry point (`NotifierInterface::notify()`) that takes a type, recipients, an
  optional actor, an optional context (e.g. a project), a target key, a local URL, parameters, an urgency and the
  *involved* recipients. It fans out per recipient and per channel.
- **Type and category registry.** Types (icon, title key, category, the permission needed to open the target,
  default channels, urgency) declared in config or by tagged providers; categories group types for preferences.
- **In-app inbox.** One row per recipient, never for the actor, only when the recipient may open the target,
  coalescing of repeats, read / unread / mark all, unread count, visibility re-check at read time, retention.
- **Header bell and inbox page** on UiKit, with a FormKit filter (type, status), plus the Stimulus bell controller and
  its styles (UiKit tokens).
- **Realtime badge.** `realtime: mercure | poll | off`. Mercure private update per recipient after commit, carrying
  only `{event, unread}`; bounded fragment poll otherwise (`REQ-UIKIT-012`).
- **Preferences matrix.** Per recipient × category × channel, opt-out storage (only deviations), scope *all* /
  *involved*, a master switch, per-context switches and per-context × category overrides (Beacon's project matrix).
- **Quiet hours and digests** for out-of-band channels (mail, push, Slack): time zone, start / end, weekdays, urgent
  bypass; digest `off | hourly | daily`.
- **Channel SPI** with built-in `in_app` and bridges: `mail` (Symfony Mailer, MailKit branding when present), `push`
  (PwaBundle Web Push), `slack` (SlackKit DM). Hosts can add channels (SMS through SmsKit later, Teams…).
- **Personal data.** UserKit eraser and export provider for every bundle table, actor unlinking on other rows.
- **Bridges:** ChatKit support tickets (Beacon's `SupportTicketNotifier` moves here as a generic bridge), ApiTokenBundle
  expiring tokens (221-A §10.1), AuditKit (preference changes), UserKit account status.

**Stays in Beacon (product-specific):**

- Project **destinations** (webhooks, Slack channels, Teams, generic JSON: `NotificationDestination`, 595 lines),
  their formatters (852), payload builder (751), threshold rules, volume counters, uptime flap throttle, circuit
  breaker, Teams / Slack item actions, delivery attempts, the destination digest buffer. Outbound webhooks are
  candidate #15 (WebhookKit), not this bundle.
- The issue toast payloads (`MemberIssueRealtimeNotifier`, `WebPushPresentation`): Beacon keeps them as the
  *presentation* of its `issue.*` types through `NotificationPresenterInterface` (§4.3).
- Push subscription storage (`push_subscription`, `BeaconPushSubscriptionProvider`): PwaBundle's
  `PushSubscriptionProviderInterface` stays the owner of devices (Q-O5).
- Who is *involved* in an issue or task (assignee, mentioned): the producer passes it in the request.
- Mercure hub configuration (candidate A.0 #28, Mercure settings hub).

**Non-goals (v1):** no e-mail template catalogue (MailKit phase 2), no outbound webhooks, no SMS channel in 1.0
(SmsKit bridge in 1.x, Q-O9), no cross-instance federation, no read receipts for senders, no admin broadcast
composer (Q-O10).

## 2. Package

| Item | Proposal |
| --- | --- |
| Composer | **`nowo-tech/notify-kit-bundle`** |
| GitHub | `nowo-tech/NotifyKitBundle`, public, MIT; the owner creates it and registers it on Packagist after S0 is on `main` |
| Namespace / class | `Nowo\NotifyKitBundle` / `NowoNotifyKitBundle`; config root `nowo_notify_kit`; Twig namespace and translation domain `NowoNotifyKitBundle`; Stimulus identifier prefix `nowo-notify-kit--` |
| PHP / Symfony | `>=8.2 <8.6`; `^7.4 \|\| ^8.0`; demo Symfony 8.1 + FrankenPHP worker + MySQL + Mercure (REQ-DEMO-010/011/012) |
| Runtime deps | `symfony/{http-foundation,http-kernel,routing,config,dependency-injection,event-dispatcher,security-core,translation,uid,clock,rate-limiter}`, `doctrine/dbal ^3.9 \|\| ^4`, `doctrine/orm ^3.3` (mapping only), `psr/log`, `nowo-tech/ui-kit-bundle`, `nowo-tech/form-kit-bundle`, `twig/twig` |
| Suggest | `symfony/messenger` (async fan-out), `symfony/mercure-bundle` (realtime), `symfony/mailer` + `nowo-tech/mail-kit-bundle` (mail channel, branding), `nowo-tech/pwa-bundle` (push), `nowo-tech/slack-kit-bundle` (Slack DM), `nowo-tech/user-kit-bundle` (erasure, export, account status), `nowo-tech/chat-kit-bundle` (support bridge), `nowo-tech/api-token-bundle` (expiry bridge, public API auth), `nowo-tech/audit-kit-bundle`, `nowo-tech/brand-contracts` (mail and push branding) |
| First tag | 1.0.0 after S6, the REQ audit (`bundle-req-audit`, 100 %) and the SEC-004 audit |

## 3. Shape

```
producer (host listener, after its own commit)
   │  NotifierInterface::notify(NotificationRequest{type, recipients, actor, context, target, url, params, urgency, involved})
   ▼
Notifier ── validates (type known or open-set, local URL, params caps) ── buffers until the outer commit
   │
   ├── delivery.in_app = sync (default): InboxWriter (DBAL, own transaction) ─▶ coalesce ─▶ after commit: RealtimeBadge (Mercure / nothing)
   └── delivery.channels = async (default with Messenger): FanOutMessage ─▶ per recipient:
             RecipientAccessChecker (deny by default) ─▶ PreferenceResolver (master, context, category × channel, all/involved)
             ─▶ QuietHours / Digest gate ─▶ DeliverMessage(channel) ─▶ ChannelInterface (mail | push | slack | host)
                                                     └▶ DigestBuffer ─▶ scheduled DigestFlush (enqueue only)
reads: bell menu / inbox page / unread count ── VisibilityResolver re-checks access per context at read time
```

The bundle owns the request, the registry, the inbox rows, the preference rows, the quiet-hours / digest rows, the
fan-out, the channel adapters, the bell and pages, retention and personal data. The host owns who the recipients are,
whether they may open the target (`RecipientAccessCheckerInterface`), who is involved, the people directory (name,
avatar, e-mail, locale) and its own presentation for rich types.

## 4. Public ports (interfaces and value objects)

Layered namespaces (`Domain`, `Application`, `Infrastructure`, `Presentation`, `Bridge`); public contracts in
`Nowo\NotifyKitBundle\Contract\*`. No Doctrine, Symfony or HTTP type in a port, VO or event (rule 3).

### 4.1 Value objects

```php
final readonly class RecipientRef      // the person; ID mode; same rules as 221-A OwnerRef
{
    public function __construct(public string $type, public string $id) {} // type ^[a-z][a-z0-9_]{0,31}$, id 1–128 chars, no control chars, never trimmed
    public function key(): string;                 // "<type>:<id>"
    public function equals(self $other): bool;     // strict ===
}

final readonly class ContextRef        // what a notification belongs to, for access and per-context preferences
{
    public function __construct(public string $type, public string $id) {} // e.g. ('project', '42'), ('board', '<uuid>'); id ≤ 64 chars
    public function key(): string;                 // "project:42"
}

final readonly class NotificationId { public function __construct(public string $value) {} } // UUIDv7

enum Urgency: string { case Low = 'low'; case Normal = 'normal'; case High = 'high'; } // High may bypass quiet hours

final readonly class NotificationRequest
{
    /**
     * @param list<RecipientRef>    $recipients ≤ fan_out.max_recipients (larger lists are split by the caller or chunked async)
     * @param array<string,string>  $params     ≤ 20 keys, each value ≤ 200 chars (cut), never translated text, never a third person's personal data
     * @param list<RecipientRef>    $involved   for scope "involved" (assignee, mentioned…); empty = nobody is involved
     */
    public function __construct(
        public string $type, public array $recipients, public ?RecipientRef $actor, public ?ContextRef $context,
        public string $target, public string $url, public array $params = [], public Urgency $urgency = Urgency::Normal,
        public array $involved = [], public ?string $dedupeKey = null, // dedupeKey: drop a second request with the same key for 24 h (once-per-state notices)
    ) {}
}

final readonly class TypeDefinition
{
    /** @param array<string,bool> $defaultChannels channel => on by default; @param list<string> $lockedChannels channels the person cannot turn off */
    public function __construct(
        public string $key, public string $category, public string $icon, public string $titleKey,
        public ?string $permission, public array $defaultChannels, public array $lockedChannels = ['in_app'],
        public Urgency $urgency = Urgency::Normal, public bool $coalesce = true, public bool $inMatrix = true,
    ) {}
}

final readonly class CategoryDefinition { public function __construct(public string $key, public string $labelKey, public string $group = 'general', public int $order = 0) {} }

final readonly class NotificationView    // what lists, the bell and events see
{
    public function __construct(
        public NotificationId $id, public RecipientRef $recipient, public string $type, public string $category,
        public ?RecipientRef $actor, public ?ContextRef $context, public string $target, public string $url,
        public array $params, public int $repeats, public \DateTimeImmutable $createdAt, public ?\DateTimeImmutable $readAt,
    ) {}
}

final readonly class ChannelPreference { public function __construct(public bool $enabled, public Scope $scope) {} } // Scope: All | Involved
final readonly class PreferenceMatrix  { /* account rows, context switches, context overrides; resolve(category, channel, ?context): ChannelPreference */ }
final readonly class QuietHours        { public function __construct(public bool $enabled, public string $timezone, public string $start, public string $end, public array $weekdays, public bool $urgentBypass) {} public function isActive(\DateTimeImmutable $now): bool; }
enum DigestMode: string { case Off = 'off'; case Hourly = 'hourly'; case Daily = 'daily'; }
final readonly class DeliveryEnvelope  // what a channel receives: already filtered, localised, URLs absolute
{
    public function __construct(public NotificationView $notification, public string $locale, public string $title, public ?string $body, public string $absoluteUrl, public bool $digest = false, public array $items = []) {}
}
```

`QuietHours::isActive()` keeps Beacon's semantics (`SlackDmPreference`): an overnight window belongs to the weekday it
starts on; `start === end` means never; an invalid time zone falls back to UTC and logs once per request.

### 4.2 Application services (bundle-implemented)

```php
interface NotifierInterface
{
    /** Validates, then writes / enqueues after the outermost commit on the bundle connection (or at once outside a transaction). Never throws for a refused recipient: it is skipped and logged at info level. */
    public function notify(NotificationRequest $request): NotifyResult; // counts: written, coalesced, skipped_access, skipped_actor, enqueued
}

interface InboxInterface
{
    public function unreadCount(RecipientRef $recipient): int;
    /** Keyset pagination, newest first (REQ-PERF-001); filters: type, category, status (unread|read). */
    public function page(RecipientRef $recipient, InboxFilter $filter, ?string $after, int $limit): InboxPage;
    public function markRead(RecipientRef $recipient, NotificationId $id): bool;   // false = not the recipient's or not visible (→ 404)
    public function markUnread(RecipientRef $recipient, NotificationId $id): bool;
    public function markAllRead(RecipientRef $recipient): int;                     // visible rows only
    public function forgetContext(ContextRef $context): int;                       // host calls it when a project/board is deleted (no FK)
}

interface PreferencesInterface
{
    public function matrixFor(RecipientRef $recipient, ?ContextRef $context = null): PreferenceMatrix;
    public function save(RecipientRef $recipient, PreferenceChangeSet $changes): void;  // stores only deviations from the defaults
    public function quietHours(RecipientRef $recipient, string $channel = '*'): QuietHours;
    public function saveQuietHours(RecipientRef $recipient, string $channel, QuietHours $quietHours): void;
    public function digest(RecipientRef $recipient, string $channel): DigestMode;
    public function saveDigest(RecipientRef $recipient, string $channel, DigestMode $mode): void;
}

interface TypeRegistryInterface { public function type(string $key): TypeDefinition; /* unknown → generic definition, guarded by notify.default_permission */ public function categories(): array; public function channels(): array; }
```

### 4.3 Host SPIs (host-implemented; each has a safe default)

| Interface | Signature | Default |
| --- | --- | --- |
| `RecipientResolverInterface` | `recipientOf(object $user): RecipientRef` · `userOf(RecipientRef $r): ?object` | Doctrine resolver on `recipient.class` + id field, type `user` (as 221-A `OwnerResolverInterface`) |
| `RecipientAccessCheckerInterface` | `isUnrestricted(RecipientRef $r): bool` · `contextsGranting(RecipientRef $r, string $permission, list<ContextRef> $candidates): list<ContextRef>` | `PersonalOnlyAccessChecker`: rows **with** a context are refused and hidden. The container **does not build** when a registered type has a permission and no checker is configured (no silent allow) |
| `RecipientDirectoryInterface` | `displayName(RecipientRef)`, `avatarUrl(RecipientRef)`, `email(RecipientRef)`, `locale(RecipientRef)`, `timezone(RecipientRef)`, `isActive(RecipientRef)` (batched `many()` variant) | UserKit adapter when installed; otherwise the user object's `getEmail()` / `__toString()` / default locale, and `isActive` from the configured user checker |
| `NotificationPresenterInterface` (tagged, by type prefix) | `title(NotificationView, string $locale): string` · `body(...)`, `pushPayload(...)`, `slackBlocks(...)` | Translation key `notify.type.<type>.title` with `%actor%` (from the directory), `%target%` and the params; generic title for unknown types |
| `TypeProviderInterface` (tagged) | `types(): iterable<TypeDefinition>` · `categories(): iterable<CategoryDefinition>` | Config `types:` / `categories:` |
| `ContextProviderInterface` | `contextsFor(RecipientRef $r): list<ContextChoice{ref, label, group}>` (for the per-context matrix) · `label(ContextRef): ?string` | No contexts: the page shows only the account matrix |
| `ChannelInterface` (tagged) | `key(): string` · `isAvailableFor(RecipientRef): bool` · `deliver(DeliveryEnvelope, RecipientRef): DeliveryOutcome` · `supportsDigest(): bool` | `in_app` (built-in); bridges `mail`, `push`, `slack` when the bundles exist |
| `RealtimePublisherInterface` | `publishUnread(RecipientRef, int $unread): void` · `topicFor(RecipientRef): string` | `MercureRealtimePublisher` when Mercure is installed and `realtime: mercure`; `NullRealtimePublisher` otherwise |
| `NotifySettingsProviderInterface` (rule 8) | `enabled()`, `channelsEnabled()`, `retention()` | Config values; InstanceSettings bridge optional |

## 5. Persistence

### 5.1 Tables (bundle-owned, referenced by ID: rule 2)

Plain domain classes mapped by XML (`src/Resources/config/doctrine/*.orm.xml`) by default, with attribute-mapped twins
(`doctrine.mapping: xml | attribute`, same schema, tested; 221-A §5.1.1 and Q-O11). Writes go through DBAL in the
bundle's own transactions (§5.3). Prefix `doctrine.table_prefix` (default `nowo_`, `REQ-ORM-002`). Every id-like
column is **binary** (`utf8mb4_bin` / `ascii_bin` on MySQL; exact by default on PostgreSQL and SQLite, asserted by a
test). Every read or erase by recipient re-checks `RecipientRef::equals()` in PHP (LQ rule).

**`<p>notify_notification`** (the inbox)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `string(36)` bin, PK | UUIDv7 (no enumeration; Beacon's int ids are not reused) |
| `recipient_type`, `recipient_id` | `string(32)` bin, `string(128)` bin | `RecipientRef` |
| `type` | `string(64)` ascii_bin | open set |
| `category` | `string(64)` ascii_bin | copied from the type at write time |
| `actor_type`, `actor_id` | NULL, bin | set NULL by the eraser of the actor (REQ-DATA-003) |
| `context_type`, `context_id` | NULL, `string(32)` / `string(64)` bin | no FK; `forgetContext()` and visibility |
| `target` | `string(128)` bin | groups repeats (`issue:<uuid>`) |
| `url` | `string(500)` | local path only (§6.2) |
| `params` | `json` | ≤ 20 keys, values ≤ 200 chars |
| `repeats` | `int` | 1 + coalesced repeats |
| `first_created_at`, `created_at` | `datetime_immutable` UTC | `created_at` moves to the top on a repeat |
| `read_at` | NULL | |

Indexes: `(recipient_type, recipient_id, read_at, created_at, id)` (bell, count, keyset), `(recipient_type,
recipient_id, type, target)` (coalesce), `(context_type, context_id)`, `(created_at)` (retention).

**`<p>notify_preference`** (opt-out storage: only rows that differ from the type / category defaults)

| Column | Type | Notes |
| --- | --- | --- |
| `id` | `string(36)` bin, PK | |
| `recipient_type`, `recipient_id` | bin | |
| `context_key` | `string(100)` bin, NOT NULL, `''` = account level | `project:42` |
| `category` | `string(64)` ascii_bin | `*` = master switch (account) or context switch |
| `channel` | `string(32)` ascii_bin | `*` = every channel |
| `enabled` | `bool` | |
| `scope` | `string(16)` NULL | `all` \| `involved` |
| `updated_at` | `datetime_immutable` | |

Unique `(recipient_type, recipient_id, context_key, category, channel)`.

**`<p>notify_channel_setting`** (quiet hours and digest per recipient × channel; `channel = '*'` is the default for
every out-of-band channel): `recipient_*`, `channel`, `quiet_enabled`, `quiet_timezone` (`string(64)`),
`quiet_start` / `quiet_end` (`string(5)`, `HH:MM`), `quiet_weekdays` (json), `urgent_bypass` (bool), `digest`
(`off|hourly|daily`), `digest_hour` (0–23, daily), `updated_at`. Unique `(recipient_type, recipient_id, channel)`.

**`<p>notify_digest_item`** (buffered out-of-band deliveries): `id`, `recipient_*`, `channel`, `notification_id`
(NULL when the inbox row was trimmed), `type`, `params` (json snapshot), `url`, `created_at`, `release_at` (end of the
quiet window or the digest slot). Index `(release_at, recipient_type, recipient_id, channel)`.

**`<p>notify_delivery`** (idempotency and diagnostics of out-of-band deliveries, 30-day retention): `id`,
`delivery_key` (`sha256(notification_id|channel|repeats)`, **unique**: a Messenger retry never sends twice),
`recipient_*`, `channel`, `status` (`sent|skipped|failed|gone`), `attempts`, `error_code` (`string(64)`, never the
provider's message body), `created_at`, `updated_at`.

### 5.2 ID mode

Recipients, actors and contexts are `type + id` strings; there is no Doctrine relation to the host's user or project
classes, so no `resolve_target_entities`, no FK, no cascade. Deletions are explicit: the UserKit eraser (§10), the
host's call to `forgetContext()` when a context disappears, and retention. Visibility at read time hides rows whose
context the recipient can no longer open even before the host calls `forgetContext()`.

### 5.3 Writes, transactions and events (rules 6 and 7)

- `DbalNotificationStore`, `DbalPreferenceStore` and `DbalDigestStore` write through the DBAL connection of
  `doctrine.entity_manager` (default `default`), in explicit transactions opened and closed in the same call. They
  never call `flush()` on any EntityManager and never keep managed entities; reads hydrate VOs.
- **Producer inside a host transaction.** If the connection has an open transaction when `notify()` is called, the
  request is held in an after-commit buffer and processed on the outermost commit (dropped on rollback), the
  InstanceSettings `AfterCommitEventDispatcher` pattern (copied, not imported). A notification therefore never
  announces something that was rolled back. Beacon's producers already run in listeners after their flush.
- **Coalescing** runs in one transaction: `SELECT … FOR UPDATE` (MySQL / PostgreSQL; SQLite serialises writes) on the
  latest unread row of `(recipient, type, target)` newer than `inbox.repeat_window_seconds` (600), then `UPDATE`
  (actor, context, url, params, `created_at`, `repeats + 1`, `read_at = NULL`) or `INSERT`. A race can at worst create
  two rows; there is no lost update.
- **Events** (§6.6) are dispatched after commit, carry IDs only, and are buffered per call (no service state across
  requests).
- Dropped connections: one retry on `ConnectionLost` (DBAL 4 reconnects lazily).

### 5.4 Schema and migrations (`REQ-ORM-001` rule 5)

No Doctrine migration classes are shipped (house style). `docs/INSTALLATION.md` carries reference DDL for MySQL 8 /
MariaDB 11, PostgreSQL 16 and SQLite generated by a test; `nowo:notify-kit:schema-check` compares the live schema
(columns, collations, indexes, prefix) with the mapping. Schema changes in 1.x are additive and listed in
`UPGRADING.md`. The demo has its own `migrations/`.

## 6. Security model

### 6.1 Access (deny by default, twice)

- **Write time:** the actor is never notified; a recipient whose account is inactive is skipped; a type with a
  permission and a context is written only when `contextsGranting(recipient, permission, [context])` returns the
  context, or `isUnrestricted()` is true. A type without a context is personal.
- **Read time:** `VisibilityResolver` collects the distinct contexts of the recipient's own rows (one query), asks
  the checker once per permission (Beacon's `NotificationVisibility`), and builds the SQL filter "personal rows, plus
  context rows whose type's permission is granted there". Unknown types fall back to `notify.default_permission`.
  The visibility VO lives on the request only (§9).
- **Row operations** (`read`, `unread`) look the row up by id **and** recipient and re-check visibility; anything
  else is 404 (`REQ-REV-009`).

### 6.2 Content and links

- `url` must be a local path: starts with `/`, not `//`, no backslash, no control characters, ≤ 500 chars (Beacon's
  `Notification::isLocalPath`). Out-of-band channels build absolute links from the router's `default_uri` (or
  `links.base_url`), never from a request Host (`REQ-AUTH-011`).
- Titles are rendered at display time from translation keys and params; Twig autoescapes them. The mail channel uses
  text and HTML templates with autoescape; Slack text goes through SlackKit's mrkdwn escaper and push text is plain
  text (`REQ-INSTANCE-008`).
- Params are capped (20 keys, 200 chars each). The `notify()` docs forbid putting a third person's personal data in
  params (the actor is an id resolved at render time, so erasing the actor removes the name everywhere).
- The `_redirect` field of no-JS forms is accepted only when it is a local path (open-redirect guard).

### 6.3 CSRF and HTTP hygiene

- Every POST (read, unread, read all, preferences, quiet hours) is CSRF-protected: FormKit forms for the pages,
  `X-CSRF-TOKEN` header for `fetch` (token id `nowo_notify_kit_inbox`), as Beacon does today (`REQ-SEC-005`).
- Bell menu, count and inbox responses send `Cache-Control: no-store, private`.
- All internal routes require `IS_AUTHENTICATED` (config `security.access_roles`, default `['ROLE_USER']`); the
  operator page (§8.4) requires `admin_ui.security.access_roles` (default `['ROLE_ADMIN']`) and never shows
  notification content, only counts and delivery health.

### 6.4 Rate limits and floods

| Limit | Default (config) | Purpose |
| --- | --- | --- |
| Out-of-band deliveries per recipient per channel | 30 / 10 minutes (`limits.per_recipient_channel`) | Above it, items go to the digest buffer instead of being dropped |
| Recipients per request | 1,000 (`fan_out.max_recipients`) | Larger requests are refused (the producer splits them) |
| Async fan-out chunk | 200 recipients per message (`fan_out.chunk`) | Bounded handler time (`REQ-MESSENGER-009`) |
| Mark-read / preference writes per session | 120 / minute (`limits.ui_writes`) | Cheap abuse guard on the JSON endpoints |
| Poll interval | ≥ 30 s, paused while the tab is hidden (`realtime.poll_seconds`) | Bounded poll (`REQ-UIKIT-012`) |

Limiter storage is the host's `cache.rate_limiter` pool; nothing is counted in process memory.

### 6.5 Realtime

- Mercure updates are **private** (`Update(..., private: true)`) on a per-recipient topic
  `notify-kit/r/{hmac}` where `hmac = HMAC-SHA256(kernel.secret, recipient key)` (16 bytes, hex), so the topic does not
  reveal ids. `RealtimeTopics::subscribeClaimFor(RecipientRef)` gives the host the topic to add to its subscriber JWT
  (UiKit / Mercure settings hub). Beacon may keep its own topic through `RealtimePublisherInterface` (Q-B3).
- The payload is `{"event": "notify.updated", "unread": <int>}`; never a title, URL or param.
- Publish happens after commit; a failure logs a warning and never fails the request.

### 6.6 Events (ids only, after commit)

| Event (`NotifyKitEvents::*`) | Payload | When |
| --- | --- | --- |
| `NotificationRecordedEvent` | `notificationId`, `recipient`, `type`, `context`, `coalesced` (bool) | After the inbox write commits |
| `NotificationReadEvent` | `recipient`, `ids` (list, ≤ 500; `all: true` for mark-all) | After the update commits |
| `NotificationDeliveredEvent` | `notificationId`, `recipient`, `channel`, `status`, `errorCode` | After a channel outcome is recorded |
| `NotificationSkippedEvent` | `type`, `recipient`, `reason` (`actor`, `inactive`, `access`, `preference`, `dedupe`) | Debug / metrics; listeners must be cheap |
| `PreferencesChangedEvent` | `recipient`, `changedKeys` (category / channel / context keys, no values) | After the save commits; AuditKit bridge records `notify.preferences_changed` |

Events never carry params, URLs or rendered text (they can name third parties).

### 6.7 Audit

AuditKit bridge (optional): preference changes, operator actions (retention run on demand, channel switch). Reading
and marking notifications are not audited (high volume, no security value).

## 7. Configuration

```yaml
nowo_notify_kit:
    enabled: false
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }
    recipient: { type: user, class: null, id_field: id, resolver: null, directory: null }
    access: { checker: null, default_permission: null }   # container refuses to build if a type has a permission and no checker
    types:                                                # merged with tagged TypeProviderInterface services and bridges
        # invitation_accepted: { category: account, icon: 'tabler:mail-check', permission: null, channels: { in_app: true, mail: false } }
    categories:
        # account: { label: 'notify.category.account', group: general, order: 10 }
    channels:
        in_app: { enabled: true }
        mail:   { enabled: auto, from: null, template: '@NowoNotifyKit/email/notification.html.twig' }
        push:   { enabled: auto, ttl: 3600 }
        slack:  { enabled: auto }
    delivery: { in_app: sync, channels: async, transport_hint: null }  # async falls back to sync without Messenger
    inbox: { repeat_window_seconds: 600, menu_limit: 15, page_size: 25 }
    realtime: { mode: auto, poll_seconds: 60, publisher: null }        # auto = mercure if installed, else poll; off
    preferences: { scopes: [all, involved], contexts: { provider: null, label: 'notify.context.project' } }
    quiet_hours: { default_timezone: UTC, allow_urgent_bypass: true }
    digest: { modes: [off, hourly, daily], default_hour: 8 }
    fan_out: { max_recipients: 1000, chunk: 200 }
    limits:
        cache_pool: cache.rate_limiter
        per_recipient_channel: { limit: 30, interval: '10 minutes' }
        ui_writes: { limit: 120, interval: '1 minute' }
    retention: { read_days: 90, keep_per_recipient: 500, delivery_log_days: 30, batch_size: 500 }
    links: { base_url: null }                     # null = router default_uri
    schedule: { enabled: false, digests: '*/15 * * * *', retention: '15 4 * * *' }
    messenger: { max_seconds: 50 }
    bridges: { user_kit: auto, chat_kit: auto, api_token: auto, audit_kit: auto, mail_kit: auto, pwa: auto, slack_kit: auto, brand: auto }
    web_ui:
        enabled: false
        path_prefix: '/notifications'             # inbox page, bell JSON, preferences
        preferences_path: '/account/notifications'
        layout_template: null
        templates: { bell: null, menu: null, inbox: null, preferences: null, email: null }
        security: { access_roles: ['ROLE_USER'] }
    admin_ui:
        enabled: false
        path_prefix: '/admin/notifications'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    api: { enabled: false, prefix: '/api' }       # §7.1, Q-O7
    settings_provider: null
```

Strict tree (`REQ-SF-006`) with messages that name the path. `enabled: false` registers no route, listener or channel.

### 7.1 HTTP endpoints

**Internal UI endpoints** (used only by the bundle's own bell and pages; host UI prefix, not versioned, rule 5):

| Method / path (`web_ui.path_prefix`) | Answer |
| --- | --- |
| `GET /notifications` | Inbox page (UiKit `ListPage`, FormKit filter profile `filter`: type, category, status; keyset pages) |
| `GET /notifications/menu` | Bell panel fragment (latest `menu_limit`), `no-store` |
| `GET /notifications/count` | `{"unread": n}` with `ETag`; used by poll mode |
| `POST /notifications/{id}/read`, `/{id}/unread` | JSON `{ok, id, unread, unreadCount}` for `fetch`; redirect for plain forms |
| `POST /notifications/read-all` | Same, visible rows only |
| `GET/POST /account/notifications` | Preferences page (matrix, contexts, quiet hours, digest) |

**Public API** (optional, `api.enabled: false` by default, Q-O7; canonical `<prefix>/notifications/v1/…`, container
parameter `%nowo_notify_kit.api_prefix%`, OpenAPI `docs/openapi/notifications-v1.yaml` with a route-comparison test):

| Method / path | Answer |
| --- | --- |
| `GET <prefix>/notifications/v1/inbox?status=&after=&limit=` | Keyset page of `{id, type, category, title, url, context_label, actor_name, created_at, read}` in the token owner's locale |
| `GET <prefix>/notifications/v1/inbox/unread-count` | `{"unread": n}` |
| `POST <prefix>/notifications/v1/inbox/{id}/read` · `/unread` · `POST …/inbox/read-all` | `204` / `404` |
| `GET` / `PATCH <prefix>/notifications/v1/preferences` | The matrix as JSON / partial update (locked channels refused with `422`) |

Authentication through ApiTokenBundle module `notifications` (levels `read`, `write`) when installed, or the host's
firewall. Stateless, JSON refusals `{"err", "ECODE"}`, `Cache-Control: no-store`. No unversioned aliases (new paths).

## 8. UI (UiKit + FormKit, `REQ-UI-001/002/003`, `REQ-TWIG-005`)

### 8.1 Header bell

- Twig function `notify_kit_bell()` renders `@NowoNotifyKit/_bell.html.twig` into UiKit's header actions slot:
  icon button with unread badge (`99+` cap), `aria-live="polite"` count for screen readers, `<details>` disclosure so
  it works without JavaScript (links to the inbox page).
- Stimulus controller `nowo-notify-kit--bell` (TypeScript, port of Beacon's 187-line controller): loads the menu the
  first time it opens, per-row read / unread, *Mark all as read*, opening a row marks it read (waits at most 1.5 s),
  listens to the realtime event and reloads on next open; poll fallback with `visibilitychange` pause.
- Styles: Beacon's `_notifications.scss` (353 lines) rewritten on UiKit tokens (`--ui-*`), compiled CSS committed,
  dark mode and reduced motion respected. No host CSS needed.

### 8.2 Inbox page

UiKit `ListPage` (title, filter bar, table/list, empty state), FormKit filter (type with labels from the registry,
category, status), keyset *Load more*, per-row toggle form, *Mark all as read* header action. Rows show the icon,
title, context label (from `ContextProviderInterface::label()`), relative time and an unread dot.

### 8.3 Preferences page (Account → Notifications)

- UiKit `SettingsPage`: master switch; matrix with categories as rows (grouped) and channels as columns (checkbox per
  cell, *all / involved* select per category when the category supports it), locked cells disabled with a hint.
- *Per project* (context) section: a searchable list from `ContextProviderInterface`; each context has an on/off
  switch and an *Override* drawer with the same matrix, plus *Reset to account settings*.
- Quiet hours (time zone select, start, end, weekdays, *urgent alerts ignore quiet hours*) and digest per channel.
- Push: a *Enable on this device* button and the diagnostics link from PwaBundle (`REQ-PWA-007`) when the push
  channel is available; Slack: link / unlink through SlackKit's account linker when available.
- Plain FormKit forms (`REQ-TWIG-005`), progressive autosave with a Stimulus controller; no Live Component dependency
  (Beacon's two `*Live` components, 229 lines, are replaced). Beacon's display drawer keeps its own sections (chat,
  PWA) around the bundle's embeddable fragment `@NowoNotifyKit/preferences/_matrix.html.twig`.

### 8.4 Operator page

`admin_ui.path_prefix`: counts per type and channel for the last 7 days, delivery failures by error code, digest
backlog, last retention run, and a *Run retention now* action (CSRF, audited). No notification content, no
recipient list.

### 8.5 Translations and templates

Seven locales with key parity (`REQ-I18N-002`), domain `NowoNotifyKitBundle`; type titles from the producers'
domains (`title_domain` per type). Every template overridable (`REQ-TWIG-001`), documented in `docs/TEMPLATES.md`;
`PageContractTestCase` for the inbox and preferences pages.

## 9. Messenger and scheduled work

| Message | Routed to (docs) | Handler work |
| --- | --- | --- |
| `FanOutNotificationMessage` (request id, ≤ `chunk` recipients) | host's async transport | access, preferences, quiet hours / digest gate, enqueue one `DeliverNotificationMessage` per allowed channel |
| `DeliverNotificationMessage` (notification id, recipient, channel, repeats) | async (mail on the platform mail transport, `REQ-MESSENGER-006`) | idempotent through `notify_delivery.delivery_key`; `gone` (push 404/410) removes the device through PwaBundle's provider |
| `FlushDigestsMessage` (keyset cursor) | maintenance | groups released `notify_digest_item` rows per recipient × channel into one envelope; batches of 500 |
| `PruneNotificationsMessage` (keyset cursor) | maintenance | read rows older than `read_days`, trim to `keep_per_recipient`, delivery log older than `delivery_log_days` |

- The scheduler only **enqueues** (`REQ-MESSENGER-008`): recipe `nowo_notify_kit.schedule` (disabled by default) or
  the commands `nowo:notify-kit:flush-digests` / `nowo:notify-kit:prune` for hosts with their own maintenance
  schedule (Beacon `MaintenanceSchedule` lines for `app:notifications:flush-digests` and `app:notifications:prune`).
- Handlers stop after `messenger.max_seconds` (50) and re-dispatch the remainder (`REQ-MESSENGER-007/009`).
- Without Messenger every step runs synchronously after commit (small installs, demo classic mode).
- Message classes carry ids only; payloads are rebuilt from the database (no stale or personal data in the queue).

## 10. Personal data (`REQ-DATA-001…004`)

- **Eraser** (UserKit bridge, `getKey(): 'notify_kit'`, priority above the identity eraser, keyed by recipient id,
  `REQ-DATA-002`): deletes the recipient's inbox rows, preferences, channel settings, digest items and delivery rows;
  sets `actor_type/actor_id` to NULL on **other** people's rows where the erased person was the actor
  (`REQ-DATA-003`); DBAL in the erasure transaction, no flush.
- **Export provider** (`getSectionKey(): 'notifications'`): the recipient's inbox (type, title rendered in the
  person's locale, url, context label, dates, read), preferences (matrix deviations), quiet hours and digest
  settings, and the last 30 days of delivery outcomes. Actor names of other people are exported as "another user"
  (no third-party personal data). Export and erasure cover the same tables (`REQ-DATA-004`), checked by a test that
  lists every bundle table.
- Push subscriptions remain PwaBundle / host data with their own eraser (Beacon already has one).

## 11. Worker-mode design (FrankenPHP, kernel reset false)

- Services are `final readonly` and stateless. Per-request data (visibility VO, unread count memo for the Twig
  function, after-commit buffer) live in a `RequestScopedState` holder that is cleared on `kernel.request`,
  `kernel.finish_request`, `kernel.terminate` and `WorkerMessageHandledEvent`, and implements `ResetInterface` (tested
  for each path, never relied on alone).
- No managed entities; DBAL transactions open and close per call; reconnect-safe.
- The type registry and channel list are compiled into the container (immutable).
- Mercure and mail/push/Slack clients are the host's or the bridged bundles' services; the bundle holds no stream or
  socket between calls. Temporary rendering (mail) uses no files.
- **Tests:** one kernel with `disableReboot()`, two recipients in two contexts, 2,000 alternating requests (bell
  menu, count, mark read, notify from a listener, preferences save); assert no row of A is visible to B, the unread
  count memo never leaks between users, memory drift ≤ 1 MiB; a Messenger worker loop of 1,000 messages with the same
  assertions. Igor and `phpstan-frankenphp` clean; README states worker support (`REQ-DEMO-008`, `REQ-DOCS-017`).

## 12. Beacon migration (how Beacon swaps its code)

### 12.1 Data

1. Create the bundle tables (Beacon's prefix choice, Q-B1), then copy in one migration:

   | Beacon | Bundle |
   | --- | --- |
   | `user_notification` (int id, recipient FK, actor FK, project FK, type, target, url, params, created_at, read_at) | `notify_notification` (new UUIDv7, `recipient = user:<uuid>`, `actor`, `context = project:<id>`, `repeats = 1`, `first_created_at = created_at`) |
   | `member_account_alert_event` (user, event, enabled, scope) | `notify_preference` (`context_key = ''`, `category = event`, `channel = *`) |
   | `member_project_alert_preference` (user, project, enabled) | `notify_preference` (`context_key = project:<id>`, `category = *`, `channel = *`) |
   | `member_project_alert_event` (user, project, event, enabled, scope) | `notify_preference` (`context_key = project:<id>`, `category = event`) |
   | `user.member_alerts_enabled = false` | `notify_preference` (`''`, `*`, `*`, `enabled = false`) |
   | `slack_dm_preference` (overrides JSON, quiet hours, weekdays, urgent bypass) | `notify_preference` rows for `channel = slack` + `notify_channel_setting` (`channel = slack`) |
   | `slack_dm_digest_item` | `notify_digest_item` (`channel = slack`) |

2. Keep the old tables one release, then drop them (second migration), as 221-A does.

### 12.2 Code swap

- **Producers** (21 call sites of `NotificationInbox::record()` in 19 files: `Wiki/EventListener/*`, `Wiki/Sharing`,
  `Tasks/{EventListener,Recurrence,Automation}`, `Identity/{Session,Invitation}`, `Billing/{Export,Organization}`,
  `TimeTrack/{Calendar,WorkingTime}`, `Ingest/MessageHandler`, `Chat/Integration`, `Notifications/EventListener`): `record($user, TYPE, $actor, $project,
  $target, $url, $params)` becomes `notify(new NotificationRequest(TYPE, [$recipient], $actor, $context, $target,
  $url, $params))`. A Rector rule in the bundle's `docs/MIGRATION-FROM-BEACON.md` does the mechanical part.
- **Types:** Beacon's `NotificationType` (190 lines) becomes a `TypeProviderInterface` of ~80 lines (keys, icons,
  permissions, categories); `MemberAlertEvent` cases become categories.
- **Access:** Beacon implements `RecipientAccessCheckerInterface` with `PermissionScope::projects()` (~40 lines,
  replacing `NotificationAccess` 58 + `NotificationVisibility` 31).
- **Member alerts:** `MemberAlertPreferenceEvaluator::filterEligibleUsers()` callers ask
  `PreferencesInterface` (or simply `notify()` with `involved`), keeping the bulk-triage batching (one preference
  read per project and user set).
- **Issue toasts and Web Push presentation:** `MemberIssueRealtimeNotifier` / `WebPushPresentation` stay as a
  `NotificationPresenterInterface` for `issue.*` types (phase 1). Beacon's `WebPushSender` is replaced by PwaBundle's
  sender (candidate #10).
- **ChatKit:** `Chat/Integration/SupportTicketNotifier.php` (116) is replaced by the bundle's ChatKit bridge
  (Beacon keeps a small `ChatSupportRecipientsInterface` adapter for "support agents").
- `security`/layout: Beacon's header includes `notify_kit_bell()`; Mercure subscriber claims add
  `RealtimeTopics::subscribeClaimFor()` (or Beacon keeps its topic, Q-B3).

### 12.3 What Beacon deletes (line counts from `wc -l`, 2026-11-11)

| Path | Lines |
| --- | --- |
| `src/Notifications/Inbox/*` (5 files) | 434 |
| `Entity/Notification.php`, `Repository/NotificationRepository.php` | 412 |
| `Controller/NotificationInboxController.php`, `Twig/NotificationInboxExtension.php`, `Command/PruneNotificationsCommand.php` | 260 |
| `Service/MemberAlertPreferenceManager.php`, `MemberAlertPreferenceEvaluator.php` | 415 |
| `Entity/{MemberAccountAlertEvent,MemberProjectAlertEvent,MemberProjectAlertPreference}.php` + repositories, `Enum/MemberAlertScope.php` | 653 |
| `src/Twig/Components/Member{,Project}AlertPreferencesLive.php` | 229 |
| `assets/controllers/notification_bell_controller.ts`, `assets/styles/_notifications.scss` | 540 |
| `templates/_notification_bell.html.twig`, `templates/notifications/inbox/*`, member-alert part of `account/display_notifications.html.twig` | ≈ 180 |
| `Chat/Integration/SupportTicketNotifier.php` | 116 |
| **Phase 1 total** | **≈ 3,240** |
| Phase 2 (Q-B2): `Entity/SlackDmPreference.php` + repo, `Entity/SlackDmDigestItem.php` + repo, `Slack/SlackDmDigestFlusher.php`, `MessageHandler/DeliverSlackDmDigestHandler.php`, `Form/SlackDmPreferencesType.php`, part of `Slack/SlackDmEventListener.php` | ≈ 690 |

Beacon keeps: destinations and everything in §1 "Stays", `QuietHoursEvaluator` (destination quiet hours; it may use
the bundle's `QuietHours` VO), `NotificationDigestFlusher` (destination digests), `NotificationCategories`
(destination categories), push subscription storage.

## 13. Milestones

Every slice is a PR on `nowo-tech/NotifyKitBundle`. Under the 2026-11-07 batch-audit rule, S2 (access and visibility:
permissions) and S6 (public API, if enabled) need an **immediate** SEC-004 audit before a tag that contains them; the
rest is audited in the batch before 1.0.0.

| Slice | Version | Content | Done when | Audit |
| --- | --- | --- | --- | --- |
| **S0** scaffold | `main`, no tag | Composer, Docker, Makefile + release-check, CI matrix (SF 7.4 / 8.0 / 8.1 × MySQL / PostgreSQL / SQLite), PHPStan + `phpstan-frankenphp`, Igor, Rector, CS, coverage gate, Spec Kit baseline, docs set, `.github`, release / sync workflows, Flex recipe, demo skeleton (FrankenPHP worker, MySQL, Mercure), strict config tree `enabled: false` | `make release-check` green; scaffold REQ checklist 100 %; **owner registers on Packagist** | Batched |
| **S1** core inbox | 0.1.0 | Domain + XML / attribute mapping, DBAL stores, `notify()` with in-app channel only, coalescing, after-commit buffer, events, type registry (config + tagged), retention command + message, schema-check | Unit + functional, both host kinds, collation tests on MySQL | Batched |
| **S2** access + UI | 0.2.0 | `RecipientAccessCheckerInterface`, visibility, row ops (id + recipient), bell (Twig + Stimulus + CSS), inbox page, CSRF, realtime (Mercure + poll), operator page | Page contract, Playwright e2e (bell, mark read, live badge), worker test | **Immediate** (access control) |
| **S3** preferences | 0.3.0 | Preference store + matrix, contexts, all / involved, master switch, preferences page, AuditKit bridge | Matrix resolution table tests (Beacon's merge order), e2e | Batched |
| **S4** channels | 0.4.0 | Async fan-out, `notify_delivery` idempotency, mail (Mailer + MailKit branding), push (PwaBundle), Slack (SlackKit), quiet hours, digests, rate limits, scheduler recipe | Each bridge tested with the real bundle in require-dev; Messenger worker-loop test | Batched |
| **S5** GDPR + bridges | 0.5.0 | UserKit eraser + export (+ actor unlinking), ChatKit bridge, ApiToken expiring bridge, InstanceSettings provider, translations ×7, docs (USAGE, CONFIGURATION, UPGRADING, TEMPLATES, FRANKENPHP-WORKER-AUDIT, MIGRATION-FROM-BEACON) | Export ↔ erasure parity test | Batched |
| **S6** public API + 1.0 | 0.6.0 → **1.0.0** | Optional `<prefix>/notifications/v1` + OpenAPI (if Q-O7 yes), `bundle-req-audit` 100 %, SEC-004 batch audit on S0–S6 | REQ audit 100 %, audits Pass, owner OK | **Immediate** for the API; batch for the rest |
| Beacon | after 1.0.0 | §12 phase 1, then phase 2 (Slack DM) | Beacon's notification e2e suite green on the bundle | Beacon's own |

## 14. Test strategy (coverage gate ≥ 99 % PHP, ≥ 90 % TS)

- **Request validation:** local-path fuzz table (scheme, `//`, backslash, control chars, length), params caps,
  unknown type → generic definition, dedupe key.
- **Recipients:** actor skipped; inactive skipped; access refused skipped and logged; fan-out chunking.
- **Coalescing:** repeat within the window updates (actor, url, params, `repeats`, unread again); after the window
  inserts; read rows are never revived; concurrent writes (two connections) never lose an update.
- **Visibility:** personal rows always visible; context rows only with the permission; permission lost → hidden,
  count drops; unrestricted recipient sees all; unknown type falls back to the default permission; id of another
  recipient → 404.
- **Preferences:** Beacon's merge order (context × category override → account × category → defaults; master and
  context switches), *involved* scope, locked channels, deviations-only storage (setting a value back to default
  deletes the row).
- **Quiet hours / digest:** Beacon's overnight and weekday cases, urgent bypass, DST transitions, invalid time zone;
  digest grouping and release.
- **Channels:** idempotent delivery on retry; push `gone` removes the device; Slack unlinked user skipped; mail uses
  `default_uri` links; escaping of params in mail HTML, Slack mrkdwn and push text.
- **Collation (MySQL CI service):** `usera` vs `UserA` never cross in list, count, export or erasure; PHP `===`
  re-check covered by a store double returning a wrong-case row.
- **Transactions:** notify inside a rolled-back host transaction writes nothing and publishes nothing; inside a
  committed one, after commit only; no flush on the host EM (spy).
- **GDPR:** export ↔ erasure parity; actor unlinking; no third-party names in the export.
- **UI:** page contracts, CSRF missing → 403, anonymous → 302 / 401, no-JS forms, accessibility (axe) of bell and
  matrix, Vitest for the bell controller (load once, live update, poll pause).
- **Both host kinds:** standard fixture (attribute `User`, ORM resolver) and DDD fixture (XML-mapped `User` on a
  second EM, custom resolver and checker) run the same functional suite.
- **Worker:** §11. **Demo:** Symfony 8.1 + FrankenPHP worker + MySQL + Mercure; Playwright e2e with WebP screenshots
  (`REQ-DEMO-013`) of bell, inbox and preferences.

## 15. Questions

### For the owner (Q-O)

| # | Question | Recommendation | If not |
| --- | --- | --- | --- |
| Q-O1 | Name and scope: one bundle `nowo-tech/notify-kit-bundle` with inbox **and** preferences **and** channel fan-out, or an inbox-only bundle with preferences left to each app? | **One bundle with all three.** The preference matrix is what every app would otherwise rebuild, and the fan-out is where quiet hours and digests live | Inbox-only 1.0; preferences and channels in 1.1 |
| Q-O2 | Can a person turn off the **in-app** row (the bell) for a category, or is the bell always written (Beacon today) and preferences apply only to mail / push / Slack? | **Bell always written by default** (`lockedChannels: [in_app]`), with a per-type opt-in to make it optional (`in_app_optional`). The inbox stays the complete record; only interruptions are opt-out | Every channel opt-out, including the bell |
| Q-O3 | Default delivery: in-app synchronous after commit and out-of-band channels asynchronous through Messenger (fallback to sync without Messenger)? | **Yes.** Instant badge, no mail or push latency inside the request, and large fan-outs (support agents) do not block | All async (badge delayed by the queue) or all sync (slow requests) |
| Q-O4 | Row ids: UUIDv7 `string(36)` with binary collation (as 221-A Q-O2), dropping Beacon's integer ids (routes change from `\d+` to UUID)? | **Yes**, consistent with the other new bundles; Beacon's routes are internal (no installed client uses them) | Keep auto-increment integers |
| Q-O5 | Push devices: keep subscriptions in PwaBundle / the host (`PushSubscriptionProviderInterface`), NotifyKit only sends? | **Yes.** One owner of devices, already with diagnostics and an eraser in Beacon; NotifyKit depends on the interface only | NotifyKit owns a `push_subscription` table |
| Q-O6 | Default access checker when the host configures none: personal rows only, and the container refuses to build if a type declares a permission? | **Yes** (deny by default, no silent allow) | Allow everything by default with a warning |
| Q-O7 | Ship the public API `<prefix>/notifications/v1` (mobile companions, desktop apps) in 1.0, disabled by default? | **Yes, in S6, off by default**, authenticated by ApiTokenBundle module `notifications`. sms-bridge mobile and TimeTrack desktop are likely consumers | Leave it for 1.x |
| Q-O8 | Retention defaults: read rows 90 days, at most 500 rows per person, delivery log 30 days (Beacon's values)? | **Yes**, configurable; unread rows are never deleted by age, only by the per-person cap | Different defaults (e.g. 30 days) |
| Q-O9 | SMS channel through SmsKit (CRM appointment reminders, Age Desk bookings): in 1.0 or a 1.x bridge? | **1.x bridge** after 1.0 (cost per message and consent rules need their own design: opt-in only, never default on) | In 1.0 |
| Q-O10 | Admin broadcast ("maintenance tonight" to every user or a role) in this bundle? | **Not in 1.0.** It is a different product (audience, scheduling, moderation). Revisit with Core v2 | Add a simple broadcast composer to the operator page |
| Q-O11 | Mercure topic: hashed per recipient (`notify-kit/r/<hmac>`) by default, with the host free to keep its own topic? | **Yes** | Readable topics with the user id |
| Q-O12 | Beacon's GDPR gap (inbox and preferences are not exported today): fix it in Beacon now, or wait for the bundle? | **Wait for the bundle** unless the owner wants it closed sooner; the bundle export closes it in S5. Record the gap in Beacon's REQ-DATA-004 checklist meanwhile | Small Beacon PR now (two export providers, ~120 lines), deleted at the swap |

### For Beacon (Q-B)

| # | Question | Recommendation |
| --- | --- | --- |
| Q-B1 | Table names: bundle defaults (`nowo_notify_*`) or Beacon names through `table_prefix`? | Bundle defaults; drop the old tables one release after the copy |
| Q-B2 | Slack DM preferences, quiet hours and digest (`slack_dm_*`) in phase 1 or phase 2 of the swap? | Phase 2, after the bell and member alerts run on the bundle |
| Q-B3 | Realtime topic: keep `IssueRealtimeTopics::forUser(uuid)` (your issue-realtime controller re-dispatches the event) through your own `RealtimePublisherInterface`, or move to the bundle topic? | Keep yours in phase 1 (no front-end change); move when issue toasts move |
| Q-B4 | Recipient id: user UUID or integer id? | UUID (stable, already used in topics and exports) |
| Q-B5 | Issue toasts and Web Push presentation stay yours as a presenter for `issue.*` types: agreed? | Yes; they are product payloads |
| Q-B6 | Project deletion: call `InboxInterface::forgetContext(project)` from your project deletion flow (no FK cascade any more)? | Yes, in the same place that clears other project data |
