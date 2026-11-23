# Configuration

Root key: `nowo_notify_kit`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. Nothing is registered while `enabled` is false.

## Table of contents

- [Full reference](#full-reference)
- [Container parameters](#container-parameters)
- [Validation rules](#validation-rules)

## Full reference

```yaml
nowo_notify_kit:
    enabled: false
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml, transaction_runner: null }
    recipient: { type: user, class: null, id_field: id, resolver: null, directory: null }
    access: { checker: null, default_permission: null }   # the container refuses a type with a permission and no checker (Q-O6)
    types:                                                # merged with tagged TypeProviderInterface services and bridges
        # invitation_accepted: { category: account, icon: 'tabler:mail-check', permission: null, channels: { in_app: true, mail: false } }
        # Other keys: title_key (null), title_domain (null = messages), in_app_optional (false, Q-O2), locked_channels ([]), urgency (low|normal|high),
        #             coalesce (true), in_matrix (true)
    categories:
        # account: { label: 'notify.category.account', group: general, order: 10 }
    channels:
        in_app: { enabled: true }
        mail:   { enabled: auto, from: null, template: '@NowoNotifyKit/email/notification.html.twig' }
        push:   { enabled: auto, ttl: 3600 }
        slack:  { enabled: auto }
    delivery: { in_app: sync, channels: async, transport_hint: null }  # async falls back to sync without Messenger (Q-O3)
    inbox: { repeat_window_seconds: 600, menu_limit: 15, page_size: 25 }
    realtime: { mode: auto, poll_seconds: 60, subscriber_cookie: true, publisher: null }  # auto = mercure if installed, else poll; off
    preferences: { scopes: [all, involved], contexts: { provider: null, label: 'notify.context.project' } }
    quiet_hours: { default_timezone: UTC, allow_urgent_bypass: true }
    digest: { modes: [off, hourly, daily], default_hour: 8 }
    fan_out: { max_recipients: 1000, chunk: 200 }
    limits:
        cache_pool: cache.rate_limiter
        per_recipient_channel: { limit: 30, interval: '10 minutes' }
        ui_writes: { limit: 120, interval: '1 minute' }
    retention: { read_days: 90, keep_per_recipient: 500, delivery_log_days: 30, batch_size: 500 }   # Q-O8
    links: { base_url: null }                     # null = router default_uri
    schedule: { enabled: false, digests: '*/15 * * * *', retention: '15 4 * * *' }
    messenger: { max_seconds: 50 }
    bridges: { user_kit: auto, chat_kit: auto, api_token: auto, audit_kit: auto, mail_kit: auto, pwa: auto, slack_kit: auto, brand: auto }
    web_ui:
        enabled: false
        path_prefix: '/notifications'             # inbox page, bell JSON
        preferences_path: '/account/notifications'
        layout_template: null
        templates: { bell: null, menu: null, inbox: null, preferences: null, email: null }
        security:
            access_roles: ['ROLE_USER']
            access_checker: null
            preferences_roles: null                 # S3; null = access_roles
            preferences_access_checker: null        # S3; PreferencesAccessCheckerInterface service
    admin_ui:
        enabled: false
        path_prefix: '/admin/notifications'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    api: { enabled: false, prefix: '/api' }       # <prefix>/notifications/v1 (Q-O7), container parameter %nowo_notify_kit.api_prefix%
    settings_provider: null
```

| Key | Used from |
| --- | --- |
| `enabled`, `doctrine`, `recipient` (`type`, `class`, `id_field`, `resolver`), `access` (write time), `types`, `categories`, `inbox.repeat_window_seconds`, `fan_out.max_recipients`, `retention` (`read_days`, `keep_per_recipient`, `batch_size`), `messenger` | S1 |
| `recipient.directory`, `access` (read time), `inbox.menu_limit` / `page_size`, `realtime`, `web_ui`, `admin_ui` | S2 |
| `preferences`, `web_ui.preferences_path`, `web_ui.templates.preferences`, `web_ui.security.preferences_*`, `bridges.audit_kit` | S3 |
| `channels`, `delivery`, `quiet_hours`, `digest`, `fan_out.chunk`, `limits`, `links`, `schedule`, `retention.delivery_log_days` | S4 |
| `bridges`, `settings_provider` | S5 |
| `api` | S6 |

### Keys read in S1

| Key | Effect |
| --- | --- |
| `doctrine.entity_manager` | The entity manager (or DBAL connection) whose connection holds the tables; standard hosts get the mapping prepended there |
| `doctrine.table_prefix` | Prefix of the tables and their index names (default `nowo_`) |
| `doctrine.auto_mapping` | `false` for DDD hosts that register the XML mapping themselves |
| `doctrine.mapping` | `xml` (plain domain classes, default) or `attribute` (twin record classes, same schema) |
| `doctrine.transaction_runner` | A `Nowo\TransactionContract\TransactionRunnerInterface` service for the bundle writes (e.g. a retrying adapter); it must work on the bundle connection and flush no EntityManager. `null` = one attempt per write (`nowo-tech/transaction-doctrine`) |
| `recipient.class` / `id_field` / `type` | The default Doctrine `RecipientResolverInterface` (none is registered without `class` or `resolver`) |
| `recipient.resolver` | Your `RecipientResolverInterface` service |
| `access.checker` / `default_permission` | Your `RecipientAccessCheckerInterface` service; the permission of unknown types with a context |
| `types` / `categories` | The registry (merged with tagged `TypeProviderInterface` services) |
| `inbox.repeat_window_seconds` | Coalescing window (0 = never coalesce) |
| `fan_out.max_recipients` | Most recipients per request |
| `retention.*`, `messenger.max_seconds` | `nowo:notify-kit:prune` and `PruneNotificationsMessage` |

### Keys read in S2

| Key | Effect |
| --- | --- |
| `recipient.directory` | Your `RecipientDirectoryInterface` service (display names of actors); `null` = the user object through the recipient resolver (`__toString()`, `getEmail()`, `getLocale()`) |
| `access.checker` / `default_permission` (read time) | The same checker filters the inbox at read time: asked once per permission with every context of the person's rows |
| `types.<key>.title_domain` | Translation domain of the type's `title_key` (default `messages`) |
| `inbox.menu_limit` / `page_size` | Rows in the bell menu / per inbox page |
| `realtime.mode` | `auto` (Mercure when a hub service exists, else `poll`), `mercure` (refuses to build without a hub), `poll`, `off` |
| `realtime.poll_seconds` | Poll interval of the bell (paused while the tab is hidden) |
| `realtime.subscriber_cookie` | `true`: the bell sets the Mercure subscriber cookie for the person's own topic. `false`: no cookie; your JWT must hold `RealtimeTopics::subscribeClaimFor()` ([USAGE](USAGE.md#the-mercure-subscriber-cookie)) |
| `realtime.publisher` | Your `RealtimePublisherInterface` service (used as is; effective mode `host`) |
| `preferences.contexts.provider` | Your `ContextProviderInterface` service (context labels on the rows; S3: the per-context switches and overrides) |
| `web_ui.enabled` / `path_prefix` / `layout_template` / `templates.{bell,menu,inbox}` | The inbox page, bell endpoints and templates; needs `recipient.class` or `recipient.resolver`, Twig, forms, CSRF and SecurityBundle |
| `web_ui.security.access_roles` / `access_checker` | REQ-UI-002: one of the roles (`[]` = any logged-in person), or your `InboxAccessCheckerInterface` service |
| `admin_ui.enabled` / `path_prefix` / `security.*` | The operator page: `access_roles` (`ROLE_ADMIN`), `access_checker` (`OperatorAccessCheckerInterface`), `allow_unauthenticated` (demo only: also lets the page run without SecurityBundle) |

### Keys read in S3

| Key | Effect |
| --- | --- |
| `types.<key>.channels` / `locked_channels` / `in_app_optional` / `in_matrix` | The matrix: its columns are `in_app` plus every channel a registered type names; a category's cell is on by default when one of its types is; a cell is locked (always on, no checkbox) when every type of the category locks that channel. `in_app` is locked unless `in_app_optional: true` (Q-O2); `in_matrix: false` keeps a type's category off the page |
| `preferences.scopes` | `[all, involved]` shows a scope select per category; `[all]` hides it (a save with `involved` is refused) |
| `preferences.contexts.provider` | Your `ContextProviderInterface` service: `contextsFor()` is the list of contexts a person may switch off or override (**only these**: any other context is refused, 404 on the page) |
| `web_ui.preferences_path` | Path of the preferences page and its endpoints (default `/account/notifications`) |
| `web_ui.templates.preferences` | Your page template (extend `@!NowoNotifyKitBundle/preferences/index.html.twig`) |
| `web_ui.security.preferences_roles` | Roles that open the preferences page; `null` (default) = `access_roles`; `[]` = any logged-in person |
| `web_ui.security.preferences_access_checker` | Your `PreferencesAccessCheckerInterface` service (REQ-UI-002) |
| `bridges.audit_kit` | `auto` (default): record `notify.preferences_changed` and `notify.retention_run` in AuditKit's action log when it is on; `true`: refuse to build without it; `false`: never |
| `fan_out.max_recipients` | Also caps `NotificationRequest::$involved` (audit QL-I3) |

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_notify_kit.enabled` | `enabled` |
| `nowo_notify_kit.api_prefix` | `api.prefix` |
| `nowo_notify_kit.table_prefix` | `doctrine.table_prefix` |
| `nowo_notify_kit.config` | the processed tree |
| `nowo_notify_kit.realtime_mode` | the effective realtime mode: `mercure`, `host`, `poll` or `off` (enabled bundle) |
| `nowo_notify_kit.realtime_requested` | `realtime.mode` as configured (enabled bundle) |

## Validation rules

- Deny by default (Q-O6): a type with a `permission`, or `access.default_permission`, needs `access.checker` (types
  from tagged providers are checked when the registry is built).
- Type keys: `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` (e.g. `issue.assigned`); category keys and `recipient.type`:
  `^[a-z][a-z0-9_]{0,31}$`; a type needs a `category`.
- `quiet_hours.default_timezone`: a PHP time zone identifier; `digest.default_hour`: 0 to 23.
- `links.base_url`: null or an absolute `http(s)://` URL without a trailing slash.
- Tri-state switches (`channels.*.enabled`, `bridges.*`): `true`, `false` or `"auto"`.
- Paths: absolute, no trailing slash. Rate-limit intervals: `<n> second|minute|hour|day[s]`. Schedules: five-field cron.
