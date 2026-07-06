# CLAUDE.md

## What this is

A mobile-first admin web app for managing products in WooCommerce stores.
Plain PHP 8 (no Composer, no build step) so it can be deployed by uploading
files to any shared host. MySQL is the datastore; connection credentials live
in `includes/config.php` (gitignored, copy from `includes/config.example.php`)
since the app needs a database connection before it can read its own settings
table — everything else is managed in-app. See [README.md](README.md) for
setup/deployment and feature details.

## Directory layout

- `public/` — web root. Pages (`products.php`, `batch.php`, ...), `admin/`
  (superadmin-only pages), `api/` (JSON endpoints), `assets/js|css`.
- `includes/` — PHP classes/helpers, outside the web root (plus `.htaccess`
  deny-all as a second line of defense).

No bundler: one JS file per page in `public/assets/js/`, Tailwind via CDN.
Shared cross-page JS helpers (toasts, undo notifications, the collapsible
category-tree renderer) live on the global `App` object in `app.js`.

AI text/image generation goes through an OpenRouter-compatible chat
completions API ([includes/AiClient.php](includes/AiClient.php)), configured
via `ai_base_url`/`ai_api_key`/`ai_model` (text) and an optional separate
`ai_image_model` (image editing).

## API endpoint pattern

Every file in `public/api/` follows this exact boilerplate order — copy an
existing endpoint rather than writing one from scratch:

```php
require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();   // converts uncaught fatals to JSON, not HTML
require_once __DIR__ . '/../../includes/auth.php';
// ...other includes...

$user = require_login_api();
$site = require_site_api($user);   // skip if endpoint isn't site-scoped
verify_csrf_api();                  // checks X-CSRF-Token header

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
```

`install_json_fatal_handler()` must only be called from API files, never
from HTML page files (a real page fatal would otherwise render as a JSON
blob to the browser).

## Database

MySQL. Schema lives entirely in [includes/Database.php](includes/Database.php)
as inline `CREATE TABLE IF NOT EXISTS` — there is no migration framework. To
add a column to an existing table, check
`INFORMATION_SCHEMA.COLUMNS` for the column (`WHERE table_schema = DATABASE()
AND table_name = ? AND column_name = ?`) and `ALTER TABLE ... ADD COLUMN` if
missing, so existing installs upgrade in place on next request. `key` is a
reserved word in MySQL — always backtick-quote it (`` `key` ``) in the
`settings`/`kv_cache` tables. Use `INSERT ... ON DUPLICATE KEY UPDATE`, not
SQLite's `ON CONFLICT` syntax.

## Activity log + undo

Mutating actions log via `log_activity($user, $siteId, $category, $action,
$messageKey, $messageParams, $undoData)` ([includes/ActivityLog.php](includes/ActivityLog.php)).
`$undoData` is a JSON blob with a `type` (e.g. `update_product`) that the
undo handler replays. Undo is only available for `ACTIVITY_UNDO_WINDOW_SECONDS`
(10s) after the action, and only to the acting user or a superadmin. When
adding a new mutating endpoint, decide whether it needs undo support and
follow the existing shape in [public/api/stock.php](public/api/stock.php).

## i18n / RTL

Translations are a single `TRANSLATIONS` array (`en`/`fa`) in
[includes/i18n.php](includes/i18n.php); language is stored in a cookie.
Persian renders RTL. RTL layout bugs (sidebar overlap, chevron direction,
etc.) have been a recurring source of fixes — when touching layout/CSS,
check both `en` (LTR) and `fa` (RTL) before considering a change done.

Client-side JS uses a **separate, hand-maintained mirror**:
[public/assets/js/i18n.js](public/assets/js/i18n.js), same `en`/`fa`
structure. Adding a key to `includes/i18n.php` does not make it available
to JS — any key read via a JS `t(...)` call must be added to both files
(and kept symmetric between `en`/`fa` in each).

## Content-Security-Policy

`public/.htaccess` sets a strict CSP: scripts/styles are only allowed from
`'self'`, `cdn.tailwindcss.com`, and `cdn.jsdelivr.net`. Adding any other
external script/style/font source will be silently blocked by the browser —
update the CSP header there if a new CDN is needed.

## Gotchas

- `app.js`'s global `App` object has **no `.t()` method** — translation
  lookups in JS use the bare global `t(...)` function (defined in
  `i18n.js`, loaded before `app.js`). `App.t(...)` is a plausible-looking
  but nonexistent call; it will throw at runtime, not lint-time.
- The GD PHP extension isn't guaranteed to be present (confirmed missing in
  this local dev environment) — any code calling GD functions
  ([includes/ImageProcessor.php](includes/ImageProcessor.php)) must guard with
  `extension_loaded('gd')` and return a JSON error rather than let it fatal.
- Adding a new entry to `SETTINGS_FIELDS` in [includes/Settings.php](includes/Settings.php)
  shows up in the UI automatically, but its `group` label is only translated
  if also added to `settings_group_key()`'s map in
  [public/api/settings.php](public/api/settings.php) — otherwise the raw
  English group name silently leaks through regardless of language.
- The admin sub-nav tab row (Users/Sites/Settings/Backups/...) is **not**
  centralized in [includes/nav.php](includes/nav.php) — it's duplicated
  inline near the top of every `public/admin/*.php` page. Adding a new
  admin page means manually adding its tab link to every other admin
  page's row, not just `nav.php`.

## Verification

There's no test suite, linter, or build step — but run `php -l <file>` (and
`node --check <file>` for JS) on everything touched before calling it done.
"Done" otherwise means manually exercising the affected page(s) in a browser
(check both roles where relevant — `superadmin`/`admin`/`shop_manager` — and
both languages for any UI change).
