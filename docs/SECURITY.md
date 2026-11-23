# Security

## Table of contents

- [Status](#status)
- [Threat model](#threat-model)
- [Audits](#audits)
- [Reporting](#reporting)

## Status

S1 (core inbox): `notify()` writes rows only for recipients who may open the target (a context row needs the type's
permission granted by the host's access checker; the default checker grants nothing), refuses non-local URLs and caps
params; every query by recipient is re-checked with `===` in PHP on binary-collated columns. The configuration refuses
a type or a default permission without an access checker (deny by default, Q-O6).

S2 (access + UI, **immediate REQ-SEC-004 audit before the tag**):

- **Read-time visibility.** Every read (count, page, row lookup, *mark all*) is scoped to the logged-in person (SQL
  equality, byte comparison on MySQL, then `===` in PHP) and re-checks the context permission per call; a hidden row is
  never listed, counted, read or marked; a damaged stored context is hidden for everyone.
- **Row operations** look the row up by id **and** recipient; anything else answers 404 (REQ-REV-009).
- **Web UI exposure (REQ-UI-002).** `web_ui` and `admin_ui` are off by default. When on: two layers (your firewall
  `access_control` on the prefixes, and the bundle's access checkers: `web_ui.security.access_roles` default
  `ROLE_USER`, `admin_ui.security.access_roles` default `ROLE_ADMIN`). The container refuses to build the pages without
  SecurityBundle. `admin_ui.security.allow_unauthenticated` is a **demo-only footgun**: it opens the operator page
  (counts and *Run retention now*) to anyone; never set it in production. The inbox has no such switch: it always needs
  a logged-in person.
- **CSRF** on every POST (`nowo_notify_kit_inbox`, `nowo_notify_kit_operator`): `X-CSRF-TOKEN` for `fetch`, FormKit
  `_token` for forms; 403 otherwise. `_redirect` must be a local path before and after URL-decoding.
- **Content.** Titles are built at display time and printed with autoescape inside `<bdi dir="auto">`; params cannot
  override `%actor%` / `%target%`; the operator page shows counts only. Bell, menu, count and JSON answers are
  `no-store, private`.
- **Realtime.** Private Mercure updates on `notify-kit/r/<hmac>` (HMAC-SHA256 with `kernel.secret`), payload
  `{event, unread}` only; the subscriber cookie names the person's own topic only (one cookie per hub path: the bell's
  and the host's replace each other across requests, `realtime.subscriber_cookie: false` leaves the JWT to the host).
  The bell injects the menu only from a non-redirected `text/html` answer and keeps only its menu element.
- Not yet: the `limits.ui_writes` rate limit (S4).

S3 (preferences, batched audit before 1.0.0):

- **Own preferences only.** Every preferences endpoint resolves the person from the token storage; no request carries
  a recipient. `web_ui.security.preferences_roles` (default: `access_roles`) or your
  `PreferencesAccessCheckerInterface` decides who may open the page (403 otherwise).
- **Contexts deny by default.** A context switch or override is accepted only for a context
  `ContextProviderInterface::contextsFor()` lists for the logged-in person, checked again by `PreferencesInterface::save()`
  and `resetContext()` (without a provider no context can be tuned); the page, the dialog fragment, the autosave and the
  reset answer 404 for any other context, so another person's project cannot be probed.
- **Matrix integrity.** Unknown categories and channels, locked cells and scopes the configuration refuses are 422 (JSON)
  / 403 (form); the autosave body is capped (32 KiB, 100 changes, depth 8).
- **Races.** Optimistic per-cell expectations: a stale change is refused (409 `CONFLICT_001`) and writes nothing of its
  save; see [CONCURRENCY.md](CONCURRENCY.md#write-paths).
- **CSRF** `nowo_notify_kit_preferences` on every POST (header for the autosave, `_token` for forms); 403 otherwise.
- **Audit (AuditKit bridge).** `notify.preferences_changed` (cell keys, count, reset flag; never the values) and
  `notify.retention_run` (counts); never notification content or another person.
- **Dialog.** The script inserts only the `[data-nowo-notify-kit-preferences]` element of a non-redirected `text/html`
  answer from the bundle's own endpoint; context labels are escaped plain text.
- **Reviewed `innerHTML` sites (REQ-UX-001 rule 2).** Exactly two, both parsing server HTML of the bundle's own
  endpoints into a detached `<template>` and keeping only one known element:
  - `src/Resources/assets/src/bell.ts` `load()`: the menu fragment (`GET …/menu`), kept element
    `[data-nowo-notify-kit-menu]`;
  - `src/Resources/assets/src/preferences.ts` `openOverrides()`: the context fragment (`GET …/context?fragment=1`), kept
    element `[data-nowo-notify-kit-fragment] [data-nowo-notify-kit-preferences]`.

  Both `fetch` same-origin with `redirect: 'error'`, refuse a redirected or non-`text/html` answer, and the templates
  print every value with autoescape (no `|raw`); titles set by script go through `textContent`.
- **No host flush.** The AuditKit bridge is wired only when AuditKit writes in `isolated` mode (audit ZAL-1).

## Threat model

From contract §6. Each threat names the control that answers it and the slice that ships it.

| Threat | Control | Slice |
| --- | --- | --- |
| A notification reveals a target the recipient may not open | Deny by default twice: access checked at write time and visibility re-checked at read time per context | S1, S2 |
| Reading or marking another person's row | Row operations look rows up by id **and** recipient, re-check visibility, 404 otherwise | S2 |
| Open redirect / injected links | `url` must be a local path (no `//`, no backslash, no control characters, ≤ 500 chars); absolute links from `default_uri` / `links.base_url`, never the request Host | S1, S4 |
| XSS through params or titles | Titles rendered from translation keys at display time with autoescape; mail templates autoescaped; Slack mrkdwn escaper; push plain text | S2, S4 |
| CSRF on read / unread / preferences | FormKit forms and the `X-CSRF-TOKEN` header for `fetch` | S2, S3 |
| Changing another person's preferences, or a context one cannot open | The person from the token storage only; contexts from the provider's list for that person (404 otherwise); checked in the service too | S3 |
| Lost or crossed preference updates (two tabs, two devices) | One conditional statement per cell with the expectation the page showed; stale → 409, all or nothing | S3 |
| Realtime leak | Private Mercure updates on an HMAC topic per recipient; payload `{event, unread}` only | S2 |
| Floods | Recipients per request cap (S1); per-recipient-per-channel limit (overflow to the digest), UI write limit, bounded poll (S2, S4) | S1, S4 |
| Personal data in events or logs | Events carry ids only, never params, URLs or rendered text | S1 |
| Collation (audit LQ) | Recipient ids in `utf8mb4_bin`, PHP `===` re-check on list, count, export and erasure | S1 |
| Worker-mode bleed | Per-request state in a holder cleared at the request boundary (and `ResetInterface`); N-request test with two recipients | S1, S2 |

## Audits

S2 (access control) and S6 (public API) need an **immediate REQ-SEC-004 audit** before a tag that contains them; the
other slices are audited in the batch before 1.0.0, together with the `bundle-req-audit` at 100 %.

## Reporting

See [.github/SECURITY.md](../.github/SECURITY.md): report privately to hectorfranco@nowo.tech.
