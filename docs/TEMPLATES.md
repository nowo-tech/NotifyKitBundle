# Templates

Pages and partials of NotifyKitBundle (contract 221-B §8). The pages follow the UiKit page contract (REQ-UI-003): the
controllers (`Nowo\NotifyKitBundle\Presentation\Controller\InboxController`, `OperatorController`) build the view
models with `Nowo\NotifyKitBundle\Presentation\View\InboxPages`; the templates extend a UiKit scaffold and fill data
blocks only. Contract test: `tests/Integration/PageContractTest.php` (UiKit `PageContractTestCase`, strict
missing-message rule).

## Table of contents

- [Pages](#pages)
- [Partials](#partials)
- [Unique pages](#unique-pages)
- [Overrides](#overrides)
- [Assets](#assets)
- [Allowed exceptions](#allowed-exceptions)

## Pages

| Page | Template | Scaffold | View model | Data blocks filled |
| --- | --- | --- | --- | --- |
| Inbox | `inbox/index.html.twig` | `page/list` | `ListPage` (header: title, "n unread" intro, *Mark all as read* (POST, CSRF-only `FormView` of `ActionType`) while something is unread; columns notification (title, context label, repeats), when (relative time), status; row actions open, *Mark as read* / *Mark as unread* (POST, same form); filters `InboxFilterType` (type, category, status); empty state; `data-testid="notify-kit-inbox"`) + `next_href` / `first_href` (keyset pages) | `nowo_ui_styles` (bundle stylesheet), `nowo_ui_list_row`, `nowo_ui_list_after` (`nowo_notify_kit_pager`) |
| Preferences (S3) | `preferences/index.html.twig` | `page/settings` | `SettingsPage` (header: title and intro on the account page; *Notifications for <context>* with *Reset to account settings* (POST, CSRF-only `ActionType` form) and *Back* on a context page; one section with the matrix form `PreferencesType`; `data-testid="notify-kit-preferences"` / `notify-kit-preferences-context`) + `grid` (`PreferenceGrid`), `notice` (`saved` / `stale`), `cells_url`, `csrf` | `nowo_ui_styles` (bundle stylesheet), `nowo_ui_content` (`nowo_notify_kit_preferences_notice`, `nowo_notify_kit_preferences_assets`), `nowo_ui_settings_section` (the grid) |
| Operator | `operator/index.html.twig` | `page/show` | `ShowPage` (header: title, intro, *Run retention now* (POST, CSRF-only form); stats: inbox rows, unread rows, live dedupe keys; sections: rows written per type in the last 7 days, delivery set-up (channels, realtime mode); `data-testid="notify-kit-operator"`). Counts only: no content, no recipient | none (the scaffold renders everything) |

Every page passes `bundle: 'NowoNotifyKitBundle'` (theme exception `nowo_ui_kit.theme.bundles.NowoNotifyKitBundle`)
and `layout: web_ui.layout_template` (null = `nowo_ui_layout.admin`).

## Partials

| Partial | Template | Rendered by | Notes |
| --- | --- | --- | --- |
| Header bell | `bell/_bell.html.twig` | `notify_kit_bell()` (Twig function) in the host's header actions slot | `<details>` disclosure (works without JavaScript: the panel links to the inbox page), unread badge (`99+` cap), polite live region; `data-*` attributes for the script (endpoint URLs, CSRF token, realtime set-up). Blocks: `nowo_notify_kit_bell_assets`, `nowo_notify_kit_bell_icon`. Override: `web_ui.templates.bell` |
| Bell menu | `bell/_menu.html.twig` | `GET <web_ui.path_prefix>/menu` (fragment) | Latest `inbox.menu_limit` visible rows, *Mark all as read*, per-row toggle buttons (the script's `fetch` + `X-CSRF-TOKEN`; no form), link to the inbox page. Override: `web_ui.templates.menu` |

| Preferences grid (S3) | `preferences/_matrix.html.twig` | the preferences pages; embeddable (Beacon's display drawer) | The matrix form: master switch, table (caption, `scope="col"` / `scope="row"` headers, a visually hidden `<label>` per checkbox, *Always on* for locked cells), scope select per category, the contexts (`<fieldset>` + `<legend>`) with switch and *Customise* link, polite `role="status"` line, submit button (hidden by the script). Every checkbox carries `data-nowo-notify-kit-cell`, `data-context`, `data-category`, `data-channel` and `data-token-id` (its hidden token) |
| Preferences dialog fragment (S3) | `preferences/_fragment.html.twig` | `GET …/context?key=…&fragment=1` | One `[data-nowo-notify-kit-fragment]` element (dialog title in `data-title`) around the grid; the script keeps only the grid |
| Save notice (S3) | `preferences/_notice.html.twig` | the preferences pages | `saved` (`role="status"`) or `stale` (`role="alert"`) after a no-JS save |

Titles, actor names and context labels are plain text built at display time (`NotificationTitles`), printed with
autoescape (never `|raw`) inside `<bdi dir="auto">`; params cannot spoof `%actor%` / `%target%` (applied after them).

## Unique pages

- (none)

## Overrides

- A host template that extends `@!NowoNotifyKitBundle/<page>.html.twig` and overrides one of the data blocks above,
  saved as `templates/bundles/NowoNotifyKitBundle/<page>.html.twig`; or `web_ui.templates.inbox` / `.menu` / `.bell` /
  `.preferences`.
- Keep the `data-nowo-notify-kit-*` attributes of the preferences grid and the hidden `k<field>` tokens: the autosave
  sends each token with its change (a missing token makes every save look stale).
- Keep the `data-*` attributes of the bell and the menu (`data-nowo-notify-kit-*`, `data-id`, `data-unread`,
  `data-action`): the script reads them. The menu fragment must have **one** element with `data-nowo-notify-kit-menu`
  that holds everything: the script inserts only that element and shows the error message when it is missing.
- Restyle through `nowo_ui_kit.theme` (slots) and the UiKit tokens (`--nowo-ui-*`), never in the templates.

## Assets

Asset package `nowo_notify_kit` (`/bundles/nowonotifykit`, `assets:install`):

- `css/nowo-notify-kit.css`: bell, menu and inbox styles on UiKit tokens; logical properties (RTL); dark mode through
  the tokens; `prefers-reduced-motion` respected. Plain CSS, no build step.
- `js/nowo-notify-kit-bell.js`: the bell script (IIFE built from `src/Resources/assets/src/*.ts` with `pnpm run build`;
  Vitest ≥ 90 % coverage). It binds every `[data-nowo-notify-kit-bell]` and logs
  `📦 [notify-kit/bell] script loaded, build: <builtAt>` once (REQ-ASSETS-005, quiet switch as below).
- `js/nowo-notify-kit-preferences.js` (S3): autosave of the preferences grid and the context dialog (IIFE built from
  `preferences-iife.ts` with `pnpm run build`, which builds both scripts; Vitest ≥ 90 %). It binds every
  `[data-nowo-notify-kit-preferences]` and logs `📦 [notify-kit/preferences] script loaded, build: <builtAt>` once
  (REQ-ASSETS-005; `build-info.json` holds the build time; quiet with `<html data-nowo-console="quiet">`).
- Optional Stimulus peer `src/Resources/assets/stimulus-peers/bell_controller.ts` (identifier `nowo-notify-kit--bell`)
  for hosts that run Symfony UX Stimulus; it runs the same code and is not built into the IIFE.

The bell partial loads both files itself (block `nowo_notify_kit_bell_assets`; the script tag carries the request's
`csp_nonce` attribute when the host sets one). Front-end checked against Chrome Modern Web Guidance (guides `html`:
details/summary disclosure; `viewport-aware-dropdowns`; `detect-initial-visibility-state`), house rules first: the
contract's `<details>` keeps the bell usable without JavaScript. The preferences page (S3) follows the guides `forms`
(`<fieldset>` / `<legend>`, a `<label>` per control, `aria-live` status) and `dialog` / `light-dismiss-a-dialog` (native
modal `<dialog>` opened with `showModal()`, `closedby="any"` plus the click-outside fallback where it is unsupported,
an `h1` title with `aria-labelledby`, a visible close button, no manual focus trap, `overscroll-behavior: contain` on
the dialog and its backdrop). House rule over the guide: the contract asks for **autosave**, so a change saves without
pressing the submit button (the guide's "no auto-submit on change"); the status line announces every save and the
form keeps its button without JavaScript.

## Allowed exceptions

- (none)
