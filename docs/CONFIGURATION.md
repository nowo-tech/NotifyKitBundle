# Configuration

Root key: `nowo_notify_kit`. The tree is **strict** (REQ-SF-006): unknown keys and invalid values are refused at
container build, and the message names the path and the allowed values. Every key of contract §7 is accepted from S0;
the column *Used from* says which slice reads it. **S0 registers nothing**, whatever the values.

## Table of contents

- [Full reference](#full-reference)
- [Container parameters](#container-parameters)
- [Validation rules](#validation-rules)

## Full reference

```yaml
nowo_notify_kit:
    enabled: false
    doctrine: { entity_manager: default, table_prefix: 'nowo_', auto_mapping: true, mapping: xml }
    recipient: { type: user, class: null, id_field: id, resolver: null, directory: null }
    access: { checker: null, default_permission: null }   # the container refuses a type with a permission and no checker (Q-O6)
    types:                                                # merged with tagged TypeProviderInterface services and bridges
        # invitation_accepted: { category: account, icon: 'tabler:mail-check', permission: null, channels: { in_app: true, mail: false } }
        # Other keys: title_key (null), in_app_optional (false, Q-O2), locked_channels ([]), urgency (low|normal|high),
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
    realtime: { mode: auto, poll_seconds: 60, publisher: null }        # auto = mercure if installed, else poll; off
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
        security: { access_roles: ['ROLE_USER'] }
    admin_ui:
        enabled: false
        path_prefix: '/admin/notifications'
        security: { access_roles: ['ROLE_ADMIN'], access_checker: null, allow_unauthenticated: false }
    api: { enabled: false, prefix: '/api' }       # <prefix>/notifications/v1 (Q-O7), container parameter %nowo_notify_kit.api_prefix%
    settings_provider: null
```

| Key | Used from |
| --- | --- |
| `enabled`, `doctrine`, `recipient`, `types`, `categories`, `inbox`, `retention`, `messenger` | S1 |
| `access`, `realtime`, `web_ui`, `admin_ui` | S2 |
| `preferences` | S3 |
| `channels`, `delivery`, `quiet_hours`, `digest`, `fan_out`, `limits`, `links`, `schedule` | S4 |
| `bridges`, `settings_provider` | S5 |
| `api` | S6 |

## Container parameters

| Parameter | Value |
| --- | --- |
| `nowo_notify_kit.enabled` | `enabled` |
| `nowo_notify_kit.api_prefix` | `api.prefix` |
| `nowo_notify_kit.table_prefix` | `doctrine.table_prefix` |
| `nowo_notify_kit.config` | the processed tree |

## Validation rules

- Deny by default (Q-O6): a type with a `permission` needs `access.checker`.
- Type keys: `^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$` (e.g. `issue.assigned`); category keys and `recipient.type`:
  `^[a-z][a-z0-9_]{0,31}$`; a type needs a `category`.
- `quiet_hours.default_timezone`: a PHP time zone identifier; `digest.default_hour`: 0 to 23.
- `links.base_url`: null or an absolute `http(s)://` URL without a trailing slash.
- Tri-state switches (`channels.*.enabled`, `bridges.*`): `true`, `false` or `"auto"`.
- Paths: absolute, no trailing slash. Rate-limit intervals: `<n> second|minute|hour|day[s]`. Schedules: five-field cron.
