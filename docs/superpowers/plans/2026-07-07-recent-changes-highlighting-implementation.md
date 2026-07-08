# Recent-Changes Highlighting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give product cards on `/products.php` a colored left-border + tooltip dot for 24 hours after they last changed, one color per change category (price, stock, batch price, batch stock, new product, new variation), computed entirely from the existing activity log.

**Architecture:** A new `get_recent_product_changes()` function reads recent, non-undone `activity_logs` rows for a site and buckets each into a category by inspecting its `action` + `undo_data`, producing a `productId => {category, changed_at}` map. A new read-only endpoint exposes this filtered to whatever ids the frontend asks about. `products.js` calls it once per page of rendered products (fire-and-forget, after the cards/rows are already in the DOM) and applies a border class + a small colored dot with a native `Intl.RelativeTimeFormat` tooltip.

**Tech Stack:** PHP 8 (no build step), vanilla JS, existing MySQL `activity_logs` table (no schema change), `Intl.RelativeTimeFormat` (native browser API, no new dependency).

## Global Constraints

- No Composer, no build step, no new dependencies.
- No test suite in this repo — verification is `php -l` / `node --check` per file plus manual browser exercise (both `en`/`fa`).
- Any new client-side JS string read via `t(...)` must exist in both `includes/i18n.php` and `public/assets/js/i18n.js`, symmetric `en`/`fa`.
- No new/changed database schema — this feature reads the existing `activity_logs` table exactly as-is (the one field addition is inside an existing JSON `undo_data` blob, not a column).
- A `product_update` log entry only counts as a "price changed" highlight when its `undo_data.data` includes `regular_price` or `sale_price` as a key — edits to other fields are not color-coded (deliberate scope limit, not a bug).
- Multiple changes to the same product within the 24h window: the most recent one wins (one color per card).
- Undone changes (`activity_logs.undone = 1`) must be excluded from highlighting.
- Highlighting is computed fresh on every list load/refresh — no live countdown timer.

---

### Task 1: Backend — parent-id linkage + recent-changes lookup

**Files:**
- Modify: `public/api/product-variations.php:87-91` (add `parent_id` to the variation-create undo data)
- Modify: `includes/ActivityLog.php` (add `get_recent_product_changes()`)

**Interfaces:**
- Produces: `variation_create` activity-log rows now include `undo_data.parent_id` (the variable product's own id) alongside the existing `undo_data.product_id` (the new variation's id).
- Produces: `get_recent_product_changes(int $siteId, array $productIds, int $sinceTimestamp): array` → `[productId => ['category' => string, 'changed_at' => int]]`, filtered down to only the ids in `$productIds`. `category` is one of `price`, `stock`, `batch_price`, `batch_stock`, `new_product`, `new_variation`.

- [ ] **Step 1: Add `parent_id` to the variation-create undo data**

In `public/api/product-variations.php`, find the `log_activity(...)` call (currently around lines 80-92):

```php
    $logId = null;
    try {
        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'variation_create',
            'log_variation_created',
            [$variation['attribute_summary'] ?? ''],
            [
                'type' => 'delete_product',
                'site_id' => (int) $site['id'],
                'product_id' => (int) ($variation['id'] ?? 0),
            ]
        );
    } catch (Throwable $e) {
        error_log('log_activity failed after variation create: ' . $e->getMessage());
```

Add `'parent_id' => $productId,` to the undo-data array, right after `'product_id'`:

```php
    $logId = null;
    try {
        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'variation_create',
            'log_variation_created',
            [$variation['attribute_summary'] ?? ''],
            [
                'type' => 'delete_product',
                'site_id' => (int) $site['id'],
                'product_id' => (int) ($variation['id'] ?? 0),
                'parent_id' => $productId,
            ]
        );
    } catch (Throwable $e) {
        error_log('log_activity failed after variation create: ' . $e->getMessage());
```

This is purely additive — `$productId` is already in scope at this point in the file (it's the variable product's id, read earlier from `$body['product_id']`). The existing undo handler (`public/api/logs.php`) only ever reads `product_id` for the `delete_product` case, never `parent_id`, so undo behavior is unaffected.

- [ ] **Step 2: Add `get_recent_product_changes()`**

In `includes/ActivityLog.php`, add this function at the end of the file (after `format_activity_log()`, currently ending at line 179):

```php
/**
 * Buckets recent product mutations into highlight categories for the
 * products list UI: for each of $productIds, returns the most recent
 * qualifying change (if any) within the last $sinceTimestamp seconds,
 * excluding undone changes. Categories: 'price', 'stock', 'batch_price',
 * 'batch_stock', 'new_product', 'new_variation'.
 *
 * @return array<int, array{category: string, changed_at: int}>
 */
function get_recent_product_changes(int $siteId, array $productIds, int $sinceTimestamp): array
{
    if (empty($productIds)) {
        return [];
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare(
        "SELECT * FROM activity_logs
         WHERE site_id = ? AND category = 'site' AND undone = 0 AND created_at > ?
           AND action IN ('product_update', 'stock_update', 'batch_price', 'batch_stock', 'product_create', 'variation_create')
         ORDER BY created_at ASC"
    );
    $stmt->execute([$siteId, $sinceTimestamp]);
    $rows = $stmt->fetchAll();

    // Iterating oldest-to-newest and simply overwriting each product's map
    // entry means the last write for a given id is always its most recent
    // change - "most recent wins" falls out of the loop order for free.
    $map = [];

    foreach ($rows as $row) {
        $undoData = json_decode((string) $row['undo_data'], true);
        if (!is_array($undoData)) {
            continue;
        }
        $createdAt = (int) $row['created_at'];

        switch ($row['action']) {
            case 'stock_update':
                $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'stock', 'changed_at' => $createdAt];
                break;

            case 'product_update':
                $changedFields = is_array($undoData['data'] ?? null) ? array_keys($undoData['data']) : [];
                if (array_intersect($changedFields, ['regular_price', 'sale_price'])) {
                    $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'price', 'changed_at' => $createdAt];
                }
                break;

            case 'product_create':
                $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'new_product', 'changed_at' => $createdAt];
                break;

            case 'variation_create':
                if (isset($undoData['parent_id'])) {
                    $map[(int) $undoData['parent_id']] = ['category' => 'new_variation', 'changed_at' => $createdAt];
                }
                break;

            case 'batch_price':
            case 'batch_stock':
                foreach ((array) ($undoData['updates'] ?? []) as $update) {
                    if (isset($update['id'])) {
                        $map[(int) $update['id']] = ['category' => $row['action'], 'changed_at' => $createdAt];
                    }
                }
                break;
        }
    }

    unset($map[0]);

    $wantedIds = array_flip(array_map('intval', $productIds));
    return array_intersect_key($map, $wantedIds);
}
```

- [ ] **Step 3: Lint check**

Run: `php -l public/api/product-variations.php && php -l includes/ActivityLog.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 4: Commit**

```bash
git add public/api/product-variations.php includes/ActivityLog.php
git commit -m "Add parent-id linkage for variations and a recent-changes lookup helper"
```

---

### Task 2: Backend — recent-changes API endpoint

**Files:**
- Create: `public/api/recent-changes.php`

**Interfaces:**
- Consumes: `get_recent_product_changes()` (Task 1).
- Produces: `GET /api/recent-changes.php?ids=1,2,3,...` → `{items: {"123": {"category": "price", "changed_at": 1750000000}, ...}}`. Always returns a JSON *object* for `items` (never a JSON array), even when empty or when the matched ids happen to be low sequential numbers — PHP's `json_encode` would otherwise serialize a purely-sequential-integer-keyed array as a JSON array, which the frontend's `data.items[String(id)]` lookup would silently break against.

- [ ] **Step 1: Create the endpoint**

Create `public/api/recent-changes.php`:

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

const RECENT_CHANGES_WINDOW_SECONDS = 86400;
const RECENT_CHANGES_MAX_IDS = 200;

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$idsParam = (string) ($_GET['ids'] ?? '');
$ids = array_map('intval', explode(',', $idsParam));
$ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
$ids = array_slice($ids, 0, RECENT_CHANGES_MAX_IDS);

if (empty($ids)) {
    json_response(['items' => (object) []]);
}

$changes = get_recent_product_changes((int) $site['id'], $ids, time() - RECENT_CHANGES_WINDOW_SECONDS);

$items = [];
foreach ($changes as $productId => $info) {
    $items[(string) $productId] = $info;
}

json_response(['items' => (object) $items]);
```

- [ ] **Step 2: Lint check**

Run: `php -l public/api/recent-changes.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/api/recent-changes.php
git commit -m "Add recent-changes API endpoint"
```

---

### Task 3: i18n keys (PHP + JS, en + fa)

**Files:**
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Produces: translation keys `recent_change_price`, `recent_change_stock`, `recent_change_batch_price`, `recent_change_batch_stock`, `recent_change_new_product`, `recent_change_new_variation` — used as the category label half of each highlight dot's tooltip (the relative-time half is generated client-side via `Intl.RelativeTimeFormat`, which is locale-aware natively, so no `time_*` i18n keys are needed for this feature).

- [ ] **Step 1: Add keys to `includes/i18n.php`**

In the `'en' =>` array, add near `'select_all_matching_filters'` (added by the earlier batch-overhaul work):

```php
        'recent_change_price' => 'Price changed',
        'recent_change_stock' => 'Stock changed',
        'recent_change_batch_price' => 'Price changed (batch)',
        'recent_change_batch_stock' => 'Stock changed (batch)',
        'recent_change_new_product' => 'New product',
        'recent_change_new_variation' => 'New variation added',
```

In the `'fa' =>` array, add near the Persian `'select_all_matching_filters'` entry:

```php
        'recent_change_price' => 'قیمت تغییر کرد',
        'recent_change_stock' => 'موجودی تغییر کرد',
        'recent_change_batch_price' => 'قیمت تغییر کرد (دسته‌ای)',
        'recent_change_batch_stock' => 'موجودی تغییر کرد (دسته‌ای)',
        'recent_change_new_product' => 'محصول جدید',
        'recent_change_new_variation' => 'تنوع جدید اضافه شد',
```

- [ ] **Step 2: Add the same keys to `public/assets/js/i18n.js`**

In the English object, add near `"select_all_matching_filters"`:

```js
        "recent_change_price": "Price changed",
        "recent_change_stock": "Stock changed",
        "recent_change_batch_price": "Price changed (batch)",
        "recent_change_batch_stock": "Stock changed (batch)",
        "recent_change_new_product": "New product",
        "recent_change_new_variation": "New variation added",
```

In the Persian object, add near the Persian `"select_all_matching_filters"` entry:

```js
        "recent_change_price": "قیمت تغییر کرد",
        "recent_change_stock": "موجودی تغییر کرد",
        "recent_change_batch_price": "قیمت تغییر کرد (دسته‌ای)",
        "recent_change_batch_stock": "موجودی تغییر کرد (دسته‌ای)",
        "recent_change_new_product": "محصول جدید",
        "recent_change_new_variation": "تنوع جدید اضافه شد",
```

- [ ] **Step 3: Lint check**

Run: `php -l includes/i18n.php && node --check public/assets/js/i18n.js`
Expected: `No syntax errors detected` and no output

- [ ] **Step 4: Commit**

```bash
git add includes/i18n.php public/assets/js/i18n.js
git commit -m "Add recent-change highlight translation keys (en/fa)"
```

---

### Task 4: Frontend — apply highlights to product cards/rows

**Files:**
- Modify: `public/assets/js/products.js` (`loadProducts()`, two new functions)
- Modify: `public/assets/css/app.css` (six border-color classes + dot styles)

**Interfaces:**
- Consumes: `GET /api/recent-changes.php?ids=...` (Task 2), `t(key)` (existing, `i18n.js`), `escapeHtml()` (existing, this file), `activeContainer()` (existing, this file).
- Produces: `applyRecentChangeHighlights(products): Promise<void>`, `applyRecentChangeHighlight(el, category, changedAt): void`, `formatRelativeTime(timestamp): string` — none consumed by other tasks in this plan (this is the last task).

- [ ] **Step 1: Hook into `loadProducts()`**

In `public/assets/js/products.js`, inside `loadProducts()` (currently lines 242-277), change the render block from:

```js
    if (data.items.length === 0 && state.page === 1) {
      emptyState.classList.remove('hidden');
    } else {
      emptyState.classList.add('hidden');
      data.items.forEach((product) => container.appendChild(renderProductElement(product)));
    }
```

to:

```js
    if (data.items.length === 0 && state.page === 1) {
      emptyState.classList.remove('hidden');
    } else {
      emptyState.classList.add('hidden');
      data.items.forEach((product) => container.appendChild(renderProductElement(product)));
      applyRecentChangeHighlights(data.items);
    }
```

`applyRecentChangeHighlights()` is intentionally not `await`ed here — cards render and become interactive immediately; highlights fill in a moment later, matching how this file already loads categories/taxonomy filters asynchronously after the initial page paint.

- [ ] **Step 2: Add the highlight-fetching and rendering functions**

Add these three functions to `public/assets/js/products.js`, right after `loadProducts()`'s closing `}` (currently line 277, right before `showSkeletons()`):

```js
/**
 * Fetches recent-change info for the given products (fire-and-forget - a
 * failure here just means no highlights render, never a user-facing
 * error, since this is a non-essential decoration) and applies a colored
 * border + tooltip dot to each matching card/row already in the DOM.
 */
async function applyRecentChangeHighlights(products) {
  if (products.length === 0) return;

  const ids = products.map((p) => p.id).join(',');
  try {
    const data = await App.api(`/api/recent-changes.php?ids=${ids}`);
    products.forEach((product) => {
      const info = data.items[String(product.id)];
      if (!info) return;
      const el = activeContainer().querySelector(`[data-id="${product.id}"]`);
      if (el) applyRecentChangeHighlight(el, info.category, info.changed_at);
    });
  } catch (e) {
    // Non-essential decoration; ignore failures.
  }
}

function applyRecentChangeHighlight(el, category, changedAt) {
  const isRow = el.tagName === 'TR';
  const borderHost = isRow ? el.querySelector('td:nth-child(3)') : el;
  const dotHost = isRow ? el.querySelector('td:nth-child(2)') : el;
  if (!borderHost || !dotHost) return;

  borderHost.classList.add('recent-change', `recent-change-${category}`);
  dotHost.classList.add('recent-change-dot-host');

  const dot = document.createElement('span');
  dot.className = `recent-change-dot recent-change-dot-${category}`;
  dot.title = `${t(`recent_change_${category}`)} · ${formatRelativeTime(changedAt)}`;
  dotHost.appendChild(dot);
}

function formatRelativeTime(timestamp) {
  const locale = document.documentElement.lang === 'fa' ? 'fa-IR' : 'en-US';
  const rtf = new Intl.RelativeTimeFormat(locale, { numeric: 'auto' });
  const diffMinutes = Math.round((timestamp - Date.now() / 1000) / 60);
  if (Math.abs(diffMinutes) < 60) return rtf.format(diffMinutes, 'minute');
  return rtf.format(Math.round(diffMinutes / 60), 'hour');
}
```

Note: for the table row (`<tr>`), `td:nth-child(2)` is the image cell and `td:nth-child(3)` is the name cell, per `renderProductRow()`'s existing markup (`checkbox-wrap` td is 1st, image td is 2nd, name td is 3rd) — the border goes on the name cell (most visible), the dot goes on the image cell (a stable, always-rendered anchor unlike the checkbox cell, which carries a `hidden` class outside selection mode and would hide anything appended inside it).

- [ ] **Step 3: Add the CSS**

In `public/assets/css/app.css`, add this block right after the `.variations-list` rule (currently ends at line 216):

```css
/* Recent-changes highlight (last 24h): a colored border on the card/row's
   name area, plus a small dot on its image with a tooltip. border-inline-
   start and inset-inline-start are logical properties, so both mirror
   automatically for RTL. */
.recent-change {
  border-inline-start-width: 4px !important;
  border-inline-start-style: solid !important;
}
.recent-change-price { border-inline-start-color: #60a5fa !important; }
.recent-change-stock { border-inline-start-color: #4ade80 !important; }
.recent-change-batch_price { border-inline-start-color: #c084fc !important; }
.recent-change-batch_stock { border-inline-start-color: #fbbf24 !important; }
.recent-change-new_product { border-inline-start-color: #f472b6 !important; }
.recent-change-new_variation { border-inline-start-color: #22d3ee !important; }

.recent-change-dot-host {
  position: relative;
}
.recent-change-dot {
  position: absolute;
  top: -2px;
  inset-inline-start: -2px;
  width: 10px;
  height: 10px;
  border-radius: 9999px;
  border: 2px solid #fff;
}
.recent-change-dot-price { background-color: #60a5fa; }
.recent-change-dot-stock { background-color: #4ade80; }
.recent-change-dot-batch_price { background-color: #c084fc; }
.recent-change-dot-batch_stock { background-color: #fbbf24; }
.recent-change-dot-new_product { background-color: #f472b6; }
.recent-change-dot-new_variation { background-color: #22d3ee; }
```

The `!important` on the border-color/width rules matches this file's existing pattern for overriding Tailwind utility classes already present on the same element (see `.desktop-sidebar`'s `display: none !important` a few lines below, and the CSP/RTL comment near `.cat-toggle`) — needed here because the card's own `class` already includes Tailwind's `border border-gray-100`, and a same-specificity later rule for just the inline-start edge needs to win reliably.

- [ ] **Step 4: Lint check**

Run: `node --check public/assets/js/products.js`
Expected: no output (success)

- [ ] **Step 5: Manual verification**

No live WooCommerce site available in most dev environments for this app — if one is available:
1. Change a product's price via the inline editor; reload `/products.php`; confirm the blue border + dot appear on that card, tooltip shows "Price changed · X minutes ago" (or the `fa` equivalent).
2. Adjust stock via +/-; reload; confirm the green border.
3. Run a batch price and a batch stock job (from the earlier batch-edit-overhaul feature); reload; confirm purple/amber borders on the affected products.
4. Create a new product; confirm the pink border.
5. Add a new variation to a variable product; confirm the *parent* product's card gets the cyan border (not the variation row).
6. Undo a change within its 10s window; reload; confirm the border is gone (the log row is now `undone = 1`).
7. Repeat step 1 in table view; confirm the border lands on the name cell and the dot on the image cell, not overlapping the (possibly hidden) checkbox cell.
8. Check both `en`/`fa` — confirm the border renders on the correct side (RTL should mirror to the opposite edge automatically via the logical CSS properties) and the tooltip text/relative-time are in the right language.

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/products.js public/assets/css/app.css
git commit -m "Highlight recently-changed products with a colored border and tooltip"
```
