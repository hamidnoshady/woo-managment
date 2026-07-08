# Recent-Changes Highlighting — Design

Status: approved
Last (4th) piece of the original 4-piece project (see [2026-07-06-product-variations-design.md](2026-07-06-product-variations-design.md) and [2026-07-06-batch-edit-overhaul-design.md](2026-07-06-batch-edit-overhaul-design.md) for the earlier pieces).

## Context

The user wants product cards on `/products.php` to show a colored highlight for the 24 hours after they last changed, with a different color per kind of change (stock, price, batch price, "and etc."). Today the only trace of "this just changed" is a 1.5s CSS flash after an undo (`highlight-flash` in `app.css`, `products.js:122-123`) — nothing persists across a page load or shows *what* changed.

The activity log (`activity_logs` table, `includes/ActivityLog.php`) already records every product mutation with a timestamp and enough `undo_data` to know which product and which fields changed — this feature is a read/display layer over data that already exists, not a new tracking system.

Scope decisions (confirmed with user):
- **6 color categories**, driven by what the log already distinguishes: stock changed, price changed, batch price changed, batch stock changed, new product, new variation (highlights the parent product's card, since variations aren't shown as top-level cards).
- **Visual treatment**: colored left border + a small dot/badge (tooltip on hover: category + relative time), not a full background tint.
- **Expiry**: recomputed on every list load/refresh — no live countdown timer removing color mid-view.
- A `product_update` log entry only counts as a "price changed" highlight when its `undo_data` shows `regular_price`/`sale_price` among the changed fields. Edits to other fields (name, description, images, categories, status) are not color-coded — this feature only surfaces the change types the user asked for, not a generic "this product was edited" indicator.
- Multiple changes to the same product within the window: the **most recent** one wins (one color per card, not a stack).
- Undone changes (`activity_logs.undone = 1`) are excluded — if a change was reverted, showing its color would be misleading.

## Backend: one small addition to an existing endpoint

`public/api/product-variations.php`'s `create_variation` log call (the only place `variation_create` is logged) currently stores only the variation's own id in `undo_data.product_id` — there's no way to know which parent product to highlight. Add `'parent_id' => $productId` to that `undo_data` array (the parent id is already in scope as `$productId` at that point in the function) — purely additive, doesn't change undo behavior (`logs.php`'s undo switch only ever reads `product_id`, never `parent_id`).

## Backend: recent-changes lookup

### `includes/ActivityLog.php`

New function `get_recent_product_changes(int $siteId, array $productIds, int $sinceTimestamp): array`:

- Query: `SELECT * FROM activity_logs WHERE site_id = ? AND category = 'site' AND action IN ('product_update','stock_update','batch_price','batch_stock','product_create','variation_create') AND undone = 0 AND created_at > ? ORDER BY created_at ASC`.
- Iterate rows oldest-to-newest, decoding each row's `undo_data`. For each row, determine (a) which product id(s) it affects, (b) its category, and write/overwrite `$map[$productId] = ['category' => ..., 'changed_at' => $row['created_at']]` — iterating oldest-first means a later row for the same product naturally overwrites an earlier one, giving "most recent wins" for free.
- Per-action mapping:
  - `stock_update` → category `stock`, product id = `undo_data.product_id`.
  - `product_update` → only produce an entry if `undo_data.data` has `regular_price` or `sale_price` as a key; category `price`, product id = `undo_data.product_id`.
  - `product_create` → category `new_product`, product id = `undo_data.product_id`.
  - `variation_create` → category `new_variation`, product id = `undo_data.parent_id` (falls back to skipping the row if `parent_id` is absent — e.g. a log written before this feature shipped).
  - `batch_price` / `batch_stock` → category `batch_price`/`batch_stock`, iterate `undo_data.updates` (each has an `id`) and write one map entry per product id in that array, all sharing this row's `created_at`.
- Filter the final map down to just `$productIds` (the ids the caller actually asked about) before returning — the query itself isn't filtered by product id (a `WHERE product_id IN (...)` doesn't work cleanly against JSON-encoded `undo_data`, and the 24h-site-scoped row count is small enough that filtering in PHP after decode is simpler and fast enough).

### `public/api/recent-changes.php` (new file)

Standard endpoint boilerplate (`install_json_fatal_handler()`, `require_login_api()`, `require_site_api()` — read-only, no CSRF needed).

`GET ?ids=1,2,3,...` → validates/parses the comma-separated id list (caps at a sane max, e.g. 200, matching the largest realistic single page of loaded cards), calls `get_recent_product_changes($site['id'], $ids, time() - 86400)`, returns `{items: {"123": {"category": "price", "changed_at": 1750000000}, ...}}`.

## Frontend

### `public/assets/js/products.js`

- After `loadProducts()` renders a batch of product elements (both the initial load and each "load more"/infinite-scroll page), collect the just-rendered ids and call `GET /api/recent-changes.php?ids=...`, then apply highlighting to each matching card/row via a new `applyRecentChangeHighlight(el, category, changedAt)` function: adds a category-specific border class and injects a small dot element with a `title` tooltip built from an i18n label + a relative-time string (e.g. "3h ago" — a small new helper, not a dependency).
- This call is fire-and-forget relative to the main render: cards render immediately without highlights, then highlights apply a moment later when the lookup resolves — consistent with how categories/taxonomy filters already load asynchronously after the initial page paint in this file.
- Applies identically to grid cards and table rows (same hook point, same function, both already share `renderPriceRow`/`wireProductElement` — this reuses that same "shared render path" pattern).

### `public/assets/css/app.css`

Six new small border-color utility classes (e.g. `.recent-change-price { border-inline-start: 3px solid #60a5fa; }` etc., using logical properties so RTL mirrors automatically, consistent with the pattern already used for `.variations-list`) plus one small dot-badge style shared by all six (color set via the same class).

## i18n

New keys in both `includes/i18n.php` and `public/assets/js/i18n.js` (en + fa): `recent_change_price`, `recent_change_stock`, `recent_change_batch_price`, `recent_change_batch_stock`, `recent_change_new_product`, `recent_change_new_variation`, and a small relative-time set (`time_just_now`, `time_hours_ago` with a `%s`, `time_minutes_ago` with a `%s`) — or reuse `Intl.RelativeTimeFormat` client-side instead of hand-rolled i18n keys if that's simpler and already locale-aware for `fa`; the implementation plan will decide based on what's simplest given the existing `formatTime()` helper already in `logs.js`.

## Error handling

- A failed `/api/recent-changes.php` fetch: cards simply render without highlights (already the default state before the fetch resolves) — no toast, no visible error, since this is a non-essential decoration, not a data-correctness feature.
- A product id that has no recent-changes entry: no border/dot, unchanged from today's card appearance.
- `get_recent_product_changes()` receiving a malformed/undecodable `undo_data` for some row (shouldn't happen, but defensively): skip that row rather than fatal.

## Testing / verification

No test suite in this repo. Manual verification:
- Change a product's price via the inline editor, confirm the blue price border+dot appears immediately (page reload) and the tooltip shows the correct relative time.
- Adjust stock via +/-, confirm the green stock border.
- Run a batch price/stock job (from the just-built batch-edit overhaul), confirm every successfully-changed product gets the batch-purple/batch-amber border.
- Create a new product, confirm the pink border.
- Add a new variation to a variable product, confirm the *parent* product's card (not the variation row) gets the cyan border.
- Undo a change within its 10s window, confirm the border disappears on the next list refresh (since the log row is now `undone = 1`).
- Wait past 24h (or manually backdate a log row's `created_at` in a local dev DB) and confirm the border no longer appears after that point.
- Check both `en`/`fa` (RTL border-side mirroring, tooltip text, relative-time formatting).
