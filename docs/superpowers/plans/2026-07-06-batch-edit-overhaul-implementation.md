# Batch Edit Overhaul Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Rebuild batch price/stock editing as a chunked, crash-proof background job with live progress, a persistent per-product report, and the ability to target every product matching the current filters (not just what's currently loaded on screen).

**Architecture:** Two new local tables (`batch_jobs`, `batch_job_items`) back a start/tick/poll job pattern that mirrors this codebase's existing `site_backups` job pattern. `POST .../batch-jobs.php?action=start` resolves the target id set once (either an explicit list, or server-side via a new WP-plugin "ids only" query for "select all matching filters") and pre-inserts one row per target as `pending`. `GET .../batch-jobs.php?action=poll` is the tick: it claims a bounded batch of `pending` rows, applies each individually in its own try/catch (so one bad item can never abort the rest), and returns live progress. The existing `/api/batch.php` endpoint is trimmed down to preview-only (bounded to the first 100 matched items, regardless of how many match overall) since actual application now goes through the job engine.

**Tech Stack:** PHP 8 (no build step), vanilla JS, MySQL (via existing `Database.php` inline-migration pattern), WooCommerce native PHP APIs on the WP-plugin side.

## Global Constraints

- No Composer, no build step, no new dependencies.
- No test suite in this repo — verification is `php -l` / `node --check` per file plus manual browser exercise (both `en`/`fa`).
- `install_json_fatal_handler()` only from files under `public/api/`.
- Any new client-side JS string read via `t(...)` must exist in both `includes/i18n.php` and `public/assets/js/i18n.js`, symmetric `en`/`fa`.
- `key` is a MySQL reserved word — backtick-quote it if ever used as a column name (not needed by this plan's new tables).
- Schema changes follow the existing `Database.php` pattern exactly: inline `CREATE TABLE IF NOT EXISTS` for new tables, `addColumnIfMissing()` for new columns on existing tables — no separate migration files.
- **Known, deliberate limitation carried into this plan:** "select all matching filters" resolves ids via a WooCommerce `return => 'ids'` query, which cannot apply the products page's client-side `min_price`/`max_price` range filter (that filter today is applied in PHP *after* fetching full product data — incompatible with an ids-only bulk query without hydrating every match first, which would defeat the point). Price-range is therefore excluded from what "select all matching filters" honors; every other filter (search, category, stock status, on-sale, custom taxonomies) is honored. Mark this in code with a `ponytail:` comment — add price-range support later only if it's actually needed, via a dedicated WC price meta-query, not by hydrating every matched product upfront.

---

### Task 1: Data model — job tables and activity-log linkage

**Files:**
- Modify: `includes/Database.php` (add two tables + one column)
- Modify: `includes/ActivityLog.php` (`log_activity()` signature, `format_activity_log()`)

**Interfaces:**
- Produces: `batch_jobs` table (`id, site_id, user_id, action, params_json, status, total_items, processed_items, succeeded_items, failed_items, log_id, started_at, completed_at`).
- Produces: `batch_job_items` table (`id, batch_job_id, product_id, product_name, status, change_json, error`).
- Produces: `activity_logs.batch_job_id` (nullable INT).
- Produces: `log_activity(array $user, ?int $siteId, string $category, string $action, string $messageKey, array $messageParams = [], ?array $undoData = null, ?int $batchJobId = null): int` — one new optional trailing parameter, fully backward compatible (every existing call site passes at most 6 args after `$user`).
- Produces: `format_activity_log()`'s returned array now includes `'batch_job_id' => int|null`.

- [ ] **Step 1: Add the two new tables**

In `includes/Database.php`, right after the `site_restores` table's closing `);` (currently ends at line 181, immediately before the `self::addColumnIfMissing($pdo, 'sites', ...)` calls), add:

```php
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS batch_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                user_id INT NOT NULL,
                action VARCHAR(20) NOT NULL,
                params_json TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                total_items INT NOT NULL DEFAULT 0,
                processed_items INT NOT NULL DEFAULT 0,
                succeeded_items INT NOT NULL DEFAULT 0,
                failed_items INT NOT NULL DEFAULT 0,
                log_id INT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                KEY idx_batch_jobs_site (site_id, id),
                CONSTRAINT fk_batch_jobs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS batch_job_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_job_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                change_json TEXT NULL,
                error TEXT NULL,
                KEY idx_batch_job_items_job (batch_job_id, status),
                CONSTRAINT fk_batch_job_items_job FOREIGN KEY (batch_job_id) REFERENCES batch_jobs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
```

- [ ] **Step 2: Add the `activity_logs.batch_job_id` column**

In the same file, in the `self::addColumnIfMissing($pdo, 'sites', ...)` block (currently lines 183-188), add one more line right after it (still before the `self::dropColumnIfPresent(...)` calls):

```php
        self::addColumnIfMissing($pdo, 'activity_logs', 'batch_job_id', 'INT NULL');
```

- [ ] **Step 3: Extend `log_activity()` with the optional `$batchJobId` parameter**

In `includes/ActivityLog.php`, replace the `log_activity()` function (currently lines 18-40) with:

```php
function log_activity(array $user, ?int $siteId, string $category, string $action, string $messageKey, array $messageParams = [], ?array $undoData = null, ?int $batchJobId = null): int
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs
            (user_id, user_name, user_phone, site_id, category, action, message_key, message_params, undo_data, undone, created_at, batch_job_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
    );
    $stmt->execute([
        (int) $user['id'],
        (string) ($user['name'] ?? ''),
        (string) ($user['phone'] ?? ''),
        $siteId,
        $category,
        $action,
        $messageKey,
        json_encode($messageParams, JSON_UNESCAPED_UNICODE),
        $undoData !== null ? json_encode($undoData, JSON_UNESCAPED_UNICODE) : null,
        time(),
        $batchJobId,
    ]);

    return (int) $pdo->lastInsertId();
}
```

- [ ] **Step 4: Expose `batch_job_id` from `format_activity_log()`**

In the same file, replace `format_activity_log()` (currently lines 159-177) with:

```php
function format_activity_log(array $row, array $user): array
{
    $params = json_decode($row['message_params'], true);
    $params = is_array($params) ? $params : [];

    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'user_name' => $row['user_name'],
        'user_phone' => $row['user_phone'],
        'site_id' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
        'category' => $row['category'],
        'action' => $row['action'],
        'message' => t($row['message_key'], ...$params),
        'undone' => (bool) $row['undone'],
        'can_undo' => can_undo_activity_log($row, $user),
        'created_at' => (int) $row['created_at'],
        'batch_job_id' => isset($row['batch_job_id']) && $row['batch_job_id'] !== null ? (int) $row['batch_job_id'] : null,
    ];
}
```

- [ ] **Step 5: Lint check**

Run: `php -l includes/Database.php && php -l includes/ActivityLog.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 6: Commit**

```bash
git add includes/Database.php includes/ActivityLog.php
git commit -m "Add batch_jobs/batch_job_items tables and activity-log linkage"
```

---

### Task 2: WP plugin — ids-only product query

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php` (add `list_product_ids()`)
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php` (add route + callback)
- Modify: `includes/SiteAgentClient.php` (add `listProductIds()`)

**Interfaces:**
- Produces: `Wma_Products::list_product_ids(array $params): array` — returns a flat array of int product ids (no pagination cap), using the same filter params `list_products()` accepts (`search`, `category`, `stock_status`, `on_sale`, `taxonomy_terms`).
- Produces: `GET /wp-json/wma/v1/products/ids`.
- Produces: `SiteAgentClient::listProductIds(array $params): array` — throws `RuntimeException` on transport/HTTP error, same convention as every other client method.

- [ ] **Step 1: Add `list_product_ids()` to `Wma_Products`**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`, add this method right after `list_products()` (which currently ends at line 52, right before `get_product()`):

```php
    /**
     * Returns every matching product's id, with no pagination limit and no
     * per-product hydration - used for "select all matching filters" in
     * batch editing, where a full list_products() page-by-page fetch would
     * be far too slow for a store with thousands of products.
     */
    public static function list_product_ids(array $params): array
    {
        $args = ['return' => 'ids', 'limit' => -1];

        if (!empty($params['search'])) {
            $args['s'] = (string) $params['search'];
        }
        if (!empty($params['category'])) {
            $args['category'] = [(string) $params['category']];
        }
        if (!empty($params['stock_status'])) {
            $args['stock_status'] = (string) $params['stock_status'];
        }
        if (!empty($params['taxonomy_terms']) && is_array($params['taxonomy_terms'])) {
            $args['tax_query'] = self::build_tax_query($params['taxonomy_terms']);
        }

        $ids = wc_get_products($args);

        if (!empty($params['on_sale'])) {
            $ids = array_values(array_filter($ids, function ($id) {
                $product = wc_get_product($id);
                return $product && $product->is_on_sale();
            }));
        }

        return array_map('intval', $ids);
    }
```

- [ ] **Step 2: Register the REST route**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php`, inside the `rest_api_init` closure, right after the existing `/products` route block (currently lines 22-25), add:

```php
            register_rest_route('wma/v1', '/products/ids', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_product_ids'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
```

Then add the callback method right after the existing `list_products()` callback (currently lines 99-109):

```php
    public static function list_product_ids(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['ids' => Wma_Products::list_product_ids($request->get_params())], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
```

- [ ] **Step 3: Add the client method**

In `includes/SiteAgentClient.php`, add this method right after `listProducts()` (currently lines 20-24):

```php
    public function listProductIds(array $params): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/products/ids?' . http_build_query($params));
        return $this->decodeOrThrow($response)['ids'];
    }
```

- [ ] **Step 4: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php && php -l wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php && php -l includes/SiteAgentClient.php`
Expected: `No syntax errors detected` for all three

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php includes/SiteAgentClient.php
git commit -m "Add ids-only product query for select-all-matching-filters"
```

---

### Task 3: Shared id-resolution helper + trim `/api/batch.php` to preview-only

**Files:**
- Modify: `includes/helpers.php` (add `resolve_batch_target_ids()`, `build_batch_filter_params()`)
- Modify: `public/api/batch.php` (remove the apply path; preview-only, bounded fetch)

**Interfaces:**
- Produces: `resolve_batch_target_ids(SiteAgentClient $client, array $body): array` → `['ids' => int[], 'total' => int]`. `$body['ids']` (explicit list) takes precedence; otherwise `$body['select_all']` + `$body['filters']` resolves via `listProductIds()`. `total` is the full resolved count *before* any later slicing a caller does.
- Produces: `build_batch_filter_params(array $filters): array` — maps the products-page filter shape (`search`, `category`, `stock_status`, `on_sale`, `taxonomies: {restBase: termId}`) to the WP-plugin's query param names (`search`, `category`, `stock_status`, `on_sale`, `taxonomy_terms`). Deliberately does not map `min_price`/`max_price` (see Global Constraints).
- Consumes (Task 4/5 will also consume): both new helpers, and the same shape for `$body['filters']`.

- [ ] **Step 1: Add the two helpers**

In `includes/helpers.php`, add these two functions right after `format_wc_price()` (currently ends at line 198, right before `map_product_summary()`):

```php
/**
 * Maps the products-page filter shape (as sent by products.js's
 * buildQuery()/state.filters) to the WP-plugin's list_products()/
 * list_product_ids() query param names.
 *
 * ponytail: min_price/max_price are intentionally NOT mapped here - the
 * products list applies that filter client-side in PHP after fetching
 * full product data, which isn't compatible with an ids-only bulk query
 * (matching would require hydrating every candidate first, defeating the
 * point of an ids-only fetch for "select all"). Add real price-range
 * support here only if it's actually needed, via a dedicated WC price
 * meta-query - not by hydrating every match upfront.
 */
function build_batch_filter_params(array $filters): array
{
    $params = [];

    if (!empty($filters['search'])) {
        $params['search'] = trim((string) $filters['search']);
    }
    if (!empty($filters['category'])) {
        $params['category'] = (string) $filters['category'];
    }
    if (!empty($filters['stock_status'])) {
        $params['stock_status'] = (string) $filters['stock_status'];
    }
    if (!empty($filters['on_sale'])) {
        $params['on_sale'] = 'true';
    }
    if (!empty($filters['taxonomies']) && is_array($filters['taxonomies'])) {
        $taxFilters = [];
        foreach ($filters['taxonomies'] as $restBase => $termId) {
            if ($termId !== '' && $termId !== null) {
                $taxFilters[(string) $restBase] = (int) $termId;
            }
        }
        if (!empty($taxFilters)) {
            $params['taxonomy_terms'] = $taxFilters;
        }
    }

    return $params;
}

/**
 * Resolves a batch request's target product ids: an explicit `ids` array
 * takes precedence, otherwise `select_all` + `filters` resolves the full
 * matching set server-side via a single ids-only WooCommerce query -
 * the browser never holds or transmits a large id list for that path.
 *
 * @return array{ids: array<int>, total: int}
 */
function resolve_batch_target_ids(SiteAgentClient $client, array $body): array
{
    if (!empty($body['ids']) && is_array($body['ids'])) {
        $ids = array_values(array_unique(array_map('intval', $body['ids'])));
        $ids = array_values(array_filter($ids, fn($id) => $id > 0));
        return ['ids' => $ids, 'total' => count($ids)];
    }

    if (!empty($body['select_all'])) {
        $filters = is_array($body['filters'] ?? null) ? $body['filters'] : [];
        $params = build_batch_filter_params($filters);
        $ids = $client->listProductIds($params);
        return ['ids' => $ids, 'total' => count($ids)];
    }

    return ['ids' => [], 'total' => 0];
}
```

- [ ] **Step 2: Rewrite `public/api/batch.php` as preview-only**

Replace the entire contents of `public/api/batch.php` with:

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

// This endpoint is preview-only: it computes what a batch action *would*
// change without applying anything. Actual application goes through
// public/api/batch-jobs.php's start/poll job, which is crash-proof and
// chunked - a single synchronous request here (especially for a large
// "select all matching filters" set) would risk the same timeout/abort
// problems the job engine exists to avoid.
const BATCH_PREVIEW_MAX_ITEMS = 100;

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$action = $body['action'] ?? '';

$client = site_agent_client_for_site($site);

$resolved = resolve_batch_target_ids($client, $body);
if (empty($resolved['ids'])) {
    json_response(['error' => 'No products selected'], 422);
}

// Only fetch the first N ids' full product data for the preview - a
// "select all matching filters" resolve can be thousands of ids, and the
// preview only ever needs to show a bounded sample.
$previewIds = array_slice($resolved['ids'], 0, BATCH_PREVIEW_MAX_ITEMS);
$truncated = $resolved['total'] > count($previewIds);

if ($action === 'price') {
    require_role_api(['admin', 'superadmin']);

    $percent = (float) ($body['percent'] ?? 0);
    $mode = (string) ($body['mode'] ?? 'none');
    $stepOrEnding = (float) ($body['step_or_ending'] ?? 0);
    $applyTo = $body['apply_to'] ?? ['regular'];
    $applyToRegular = in_array('regular', $applyTo, true);
    $applyToSale = in_array('sale', $applyTo, true);

    $items = fetch_products_by_ids($client, $previewIds);

    $changes = [];
    foreach ($items as $product) {
        $change = ['id' => $product['id'], 'name' => $product['name']];
        $hasChange = false;

        if ($applyToRegular && $product['regular_price'] !== '') {
            $old = (float) $product['regular_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['regular_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $hasChange = true;
        }

        if ($applyToSale && $product['sale_price'] !== '') {
            $old = (float) $product['sale_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['sale_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $hasChange = true;
        }

        if ($hasChange) {
            $changes[] = $change;
        }
    }

    json_response(['preview' => true, 'changes' => $changes, 'total_matched' => $resolved['total'], 'truncated' => $truncated]);
}

if ($action === 'stock') {
    $stockAction = $body['stock_action'] ?? 'set';
    $value = (int) ($body['value'] ?? 0);

    $items = fetch_products_by_ids($client, $previewIds);

    $changes = [];
    foreach ($items as $product) {
        $current = (int) ($product['stock_quantity'] ?? 0);
        $new = $stockAction === 'delta' ? max(0, $current + $value) : max(0, $value);

        $changes[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'stock_quantity' => ['old' => $current, 'new' => $new],
        ];
    }

    json_response(['preview' => true, 'changes' => $changes, 'total_matched' => $resolved['total'], 'truncated' => $truncated]);
}

json_response(['error' => 'Unknown batch action'], 400);

/**
 * Fetches full product data for a list of ids. The plugin has no "get many
 * by id" filter, so each id is fetched individually (missing/deleted ids
 * are simply skipped).
 */
function fetch_products_by_ids(SiteAgentClient $client, array $ids): array
{
    $items = [];
    foreach ($ids as $id) {
        try {
            $product = $client->getProduct($id);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 502);
        }
        if ($product !== null) {
            $items[] = $product;
        }
    }
    return $items;
}
```

- [ ] **Step 3: Lint check**

Run: `php -l includes/helpers.php && php -l public/api/batch.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 4: Commit**

```bash
git add includes/helpers.php public/api/batch.php
git commit -m "Add shared batch id-resolution helper; trim batch.php to preview-only"
```

---

### Task 4: Job creation endpoint

**Files:**
- Create: `public/api/batch-jobs.php` (only the `action=start` branch in this task; `poll`/`detail` land in Task 5)

**Interfaces:**
- Consumes: `resolve_batch_target_ids()` (Task 3), `adjust_price()`/`format_wc_price()` (existing, `includes/helpers.php`).
- Produces: `POST /api/batch-jobs.php?action=start` → `{id: int, total_items: int}` on success, `422` if the resolved id set is empty.

- [ ] **Step 1: Create the file with the `start` action**

Create `public/api/batch-jobs.php`:

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'start') {
    verify_csrf_api();

    $body = json_body();
    $batchAction = $body['action'] ?? '';
    if (!in_array($batchAction, ['price', 'stock'], true)) {
        json_response(['error' => 'Unknown batch action'], 400);
    }
    if ($batchAction === 'price') {
        require_role_api(['admin', 'superadmin']);
    }

    $client = site_agent_client_for_site($site);
    $resolved = resolve_batch_target_ids($client, $body);
    if (empty($resolved['ids'])) {
        json_response(['error' => 'No products selected'], 422);
    }

    $params = $batchAction === 'price'
        ? [
            'percent' => (float) ($body['percent'] ?? 0),
            'mode' => (string) ($body['mode'] ?? 'none'),
            'step_or_ending' => (float) ($body['step_or_ending'] ?? 0),
            'apply_to' => array_values(array_intersect((array) ($body['apply_to'] ?? ['regular']), ['regular', 'sale'])),
        ]
        : [
            'stock_action' => ($body['stock_action'] ?? 'set') === 'delta' ? 'delta' : 'set',
            'value' => (int) ($body['value'] ?? 0),
        ];

    $pdo = Database::get();
    $pdo->beginTransaction();

    $now = time();
    $stmt = $pdo->prepare(
        'INSERT INTO batch_jobs (site_id, user_id, action, params_json, status, total_items, started_at)
         VALUES (?, ?, ?, ?, \'running\', ?, ?)'
    );
    $stmt->execute([(int) $site['id'], (int) $user['id'], $batchAction, json_encode($params), count($resolved['ids']), $now]);
    $jobId = (int) $pdo->lastInsertId();

    $itemStmt = $pdo->prepare('INSERT INTO batch_job_items (batch_job_id, product_id, status) VALUES (?, ?, \'pending\')');
    foreach ($resolved['ids'] as $id) {
        $itemStmt->execute([$jobId, $id]);
    }

    $pdo->commit();

    json_response(['id' => $jobId, 'total_items' => count($resolved['ids'])], 201);
}

json_response(['error' => 'Not found'], 404);
```

- [ ] **Step 2: Lint check**

Run: `php -l public/api/batch-jobs.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/api/batch-jobs.php
git commit -m "Add batch job creation endpoint (action=start)"
```

---

### Task 5: Job ticking (poll) and detail endpoints

**Files:**
- Modify: `public/api/batch-jobs.php` (add `action=poll` and `action=detail`)

**Interfaces:**
- Consumes: `Database::get()`, `SiteAgentClient::getProduct()`/`updateProduct()` (existing), `adjust_price()`/`format_wc_price()`/`invalidate_products_cache()` (existing, `includes/helpers.php`), `log_activity()` (Task 1's new signature).
- Produces: `GET /api/batch-jobs.php?action=poll&id=X` → `{job: {...}, items: [...]}` (only the items processed in *this* tick). `GET /api/batch-jobs.php?action=detail&log_id=X` → `{job: {...}, items: [...all...]}`.

- [ ] **Step 1: Add the `poll` and `detail` GET actions**

In `public/api/batch-jobs.php`, add this right after the `require_login_api()`/`require_site_api()` lines (before the existing `if ($method === 'POST' && $action === 'start')` block):

```php
const BATCH_TICK_SIZE = 25;

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    $job = fetch_batch_job($jobId, (int) $site['id']);
    if ($job === null) {
        json_response(['error' => 'Batch job not found'], 404);
    }

    $items = [];
    if ($job['status'] === 'running') {
        $items = tick_batch_job($job, $user);
        $job = fetch_batch_job($jobId, (int) $site['id']);
    }

    json_response(['job' => map_batch_job($job), 'items' => array_map('map_batch_job_item', $items)]);
}

if ($method === 'GET' && $action === 'detail') {
    $logId = (int) ($_GET['log_id'] ?? 0);
    $pdo = Database::get();

    $stmt = $pdo->prepare('SELECT batch_job_id FROM activity_logs WHERE id = ? AND site_id = ?');
    $stmt->execute([$logId, (int) $site['id']]);
    $jobId = (int) ($stmt->fetchColumn() ?: 0);
    if ($jobId <= 0) {
        json_response(['error' => 'No batch report for this log entry'], 404);
    }

    $job = fetch_batch_job($jobId, (int) $site['id']);
    if ($job === null) {
        json_response(['error' => 'Batch job not found'], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM batch_job_items WHERE batch_job_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$jobId]);

    json_response(['job' => map_batch_job($job), 'items' => array_map('map_batch_job_item', $itemsStmt->fetchAll())]);
}
```

- [ ] **Step 2: Add the tick/fetch/map helper functions**

Add these at the end of `public/api/batch-jobs.php` (after the final `json_response(['error' => 'Not found'], 404);` line from Task 4):

```php
function fetch_batch_job(int $jobId, int $siteId): ?array
{
    if ($jobId <= 0) {
        return null;
    }
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM batch_jobs WHERE id = ? AND site_id = ?');
    $stmt->execute([$jobId, $siteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Processes up to BATCH_TICK_SIZE pending items for this job, one at a
 * time, each independently wrapped in try/catch. A single item's failure
 * is recorded and the loop continues - this is the fix for the previous
 * fail-fast behavior where one bad product aborted the entire batch.
 * When the last pending item is processed, the job is marked completed
 * and a single activity-log entry is written for the whole batch.
 *
 * @return array the batch_job_items rows just processed
 */
function tick_batch_job(array $job, array $user): array
{
    $pdo = Database::get();
    $client = site_agent_client_for_site(get_site((int) $job['site_id']));
    $params = json_decode((string) $job['params_json'], true) ?: [];

    $stmt = $pdo->prepare(
        'SELECT * FROM batch_job_items WHERE batch_job_id = ? AND status = \'pending\' ORDER BY id ASC LIMIT ' . BATCH_TICK_SIZE
    );
    $stmt->execute([$job['id']]);
    $pending = $stmt->fetchAll();

    $processed = [];
    $succeededDelta = 0;
    $failedDelta = 0;

    foreach ($pending as $item) {
        try {
            $result = apply_one_batch_item($client, (int) $item['product_id'], $job['action'], $params);

            $updateStmt = $pdo->prepare(
                'UPDATE batch_job_items SET status = \'success\', product_name = ?, change_json = ? WHERE id = ?'
            );
            $updateStmt->execute([$result['name'], json_encode($result['change']), $item['id']]);
            $succeededDelta++;
        } catch (Throwable $e) {
            $updateStmt = $pdo->prepare(
                'UPDATE batch_job_items SET status = \'failed\', error = ? WHERE id = ?'
            );
            $updateStmt->execute([$e->getMessage(), $item['id']]);
            $failedDelta++;
        }

        $refetch = $pdo->prepare('SELECT * FROM batch_job_items WHERE id = ?');
        $refetch->execute([$item['id']]);
        $processed[] = $refetch->fetch();
    }

    $processedDelta = count($pending);
    $pdo->prepare(
        'UPDATE batch_jobs
         SET processed_items = processed_items + ?, succeeded_items = succeeded_items + ?, failed_items = failed_items + ?
         WHERE id = ?'
    )->execute([$processedDelta, $succeededDelta, $failedDelta, $job['id']]);

    $refreshed = fetch_batch_job((int) $job['id'], (int) $job['site_id']);
    if ($refreshed !== null && $refreshed['processed_items'] >= $refreshed['total_items'] && $refreshed['status'] === 'running') {
        complete_batch_job($refreshed, $user);
    }

    return $processed;
}

/**
 * Applies one product's price/stock change and returns its display name
 * and the {field: {old, new}} change made. Throws on any failure (missing
 * product, remote update error) - the caller records that as this item's
 * failure and moves on.
 */
function apply_one_batch_item(SiteAgentClient $client, int $productId, string $action, array $params): array
{
    $product = $client->getProduct($productId);
    if ($product === null) {
        throw new RuntimeException('Product not found');
    }

    if ($action === 'price') {
        $applyTo = $params['apply_to'] ?? ['regular'];
        $update = [];
        $change = [];

        if (in_array('regular', $applyTo, true) && $product['regular_price'] !== '') {
            $old = (float) $product['regular_price'];
            $new = adjust_price($old, (float) $params['percent'], (string) $params['mode'], (float) $params['step_or_ending']);
            $update['regular_price'] = format_wc_price($new);
            $change['regular_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
        }
        if (in_array('sale', $applyTo, true) && $product['sale_price'] !== '') {
            $old = (float) $product['sale_price'];
            $new = adjust_price($old, (float) $params['percent'], (string) $params['mode'], (float) $params['step_or_ending']);
            $update['sale_price'] = format_wc_price($new);
            $change['sale_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
        }

        if (empty($update)) {
            return ['name' => $product['name'] ?? '', 'change' => []];
        }

        $client->updateProduct($productId, $update);
        return ['name' => $product['name'] ?? '', 'change' => $change];
    }

    // stock
    $current = (int) ($product['stock_quantity'] ?? 0);
    $new = ($params['stock_action'] ?? 'set') === 'delta' ? max(0, $current + (int) $params['value']) : max(0, (int) $params['value']);

    $client->updateProduct($productId, [
        'manage_stock' => true,
        'stock_quantity' => $new,
        'stock_status' => $new > 0 ? 'instock' : 'outofstock',
    ]);

    return ['name' => $product['name'] ?? '', 'change' => ['stock_quantity' => ['old' => $current, 'new' => $new]]];
}

function complete_batch_job(array $job, array $user): void
{
    $pdo = Database::get();

    $itemsStmt = $pdo->prepare('SELECT * FROM batch_job_items WHERE batch_job_id = ? AND status = \'success\'');
    $itemsStmt->execute([$job['id']]);
    $succeeded = $itemsStmt->fetchAll();

    $messageKey = $job['action'] === 'price' ? 'log_batch_price_applied' : 'log_batch_stock_applied';
    $undoUpdates = [];
    foreach ($succeeded as $item) {
        $change = json_decode((string) $item['change_json'], true) ?: [];
        $undo = ['id' => (int) $item['product_id']];
        if (isset($change['regular_price'])) {
            $undo['regular_price'] = $change['regular_price']['old'];
        }
        if (isset($change['sale_price'])) {
            $undo['sale_price'] = $change['sale_price']['old'];
        }
        if (isset($change['stock_quantity'])) {
            $undo['manage_stock'] = true;
            $undo['stock_quantity'] = $change['stock_quantity']['old'];
        }
        $undoUpdates[] = $undo;
    }

    $logId = null;
    if (!empty($undoUpdates)) {
        $logId = log_activity(
            $user,
            (int) $job['site_id'],
            'site',
            $job['action'] === 'price' ? 'batch_price' : 'batch_stock',
            $messageKey,
            [(string) count($undoUpdates)],
            [
                'type' => 'batch_update',
                'site_id' => (int) $job['site_id'],
                'updates' => $undoUpdates,
            ],
            (int) $job['id']
        );
    }

    $pdo->prepare('UPDATE batch_jobs SET status = \'completed\', completed_at = ?, log_id = ? WHERE id = ?')
        ->execute([time(), $logId, $job['id']]);

    invalidate_products_cache((int) $job['site_id']);
}

function map_batch_job(array $job): array
{
    return [
        'id' => (int) $job['id'],
        'action' => $job['action'],
        'status' => $job['status'],
        'total_items' => (int) $job['total_items'],
        'processed_items' => (int) $job['processed_items'],
        'succeeded_items' => (int) $job['succeeded_items'],
        'failed_items' => (int) $job['failed_items'],
        'log_id' => $job['log_id'] !== null ? (int) $job['log_id'] : null,
    ];
}

function map_batch_job_item(array $item): array
{
    return [
        'product_id' => (int) $item['product_id'],
        'product_name' => $item['product_name'],
        'status' => $item['status'],
        'change' => $item['change_json'] !== null ? json_decode($item['change_json'], true) : null,
        'error' => $item['error'],
    ];
}
```

Note: `site_agent_client_for_site()` and `get_site()` are existing functions (`includes/site_context.php`/`includes/Sites.php`) already used identically elsewhere in this codebase (e.g. `includes/SiteBackupManager.php`) — no new dependency.

- [ ] **Step 3: Lint check**

Run: `php -l public/api/batch-jobs.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add public/api/batch-jobs.php
git commit -m "Add batch job ticking (poll) and detail endpoints"
```

---

### Task 6: i18n keys (PHP + JS, en + fa)

**Files:**
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Produces: translation keys `select_all_matching_filters` (with a `%s` for the count), `batch_progress` (with two `%s` for processed/total), `batch_succeeded_count` (with a `%s`), `batch_failed_count` (with a `%s`), `batch_item_failed_reason` (with a `%s`), `view_batch_report`, `batch_report_title`, `and_n_more` (with a `%s`).

- [ ] **Step 1: Add keys to `includes/i18n.php`**

In the `'en' =>` array, add near `'preview_changes'`:

```php
        'select_all_matching_filters' => 'Select all %s products matching filters',
        'batch_progress' => '%s of %s processed',
        'batch_succeeded_count' => '%s succeeded',
        'batch_failed_count' => '%s failed',
        'batch_item_failed_reason' => 'Failed: %s',
        'view_batch_report' => 'View report',
        'batch_report_title' => 'Batch report',
        'and_n_more' => 'and %s more',
```

In the `'fa' =>` array, add near the Persian `'preview_changes'` entry:

```php
        'select_all_matching_filters' => 'انتخاب همه %s محصول منطبق با فیلترها',
        'batch_progress' => '%s از %s پردازش شد',
        'batch_succeeded_count' => '%s موفق',
        'batch_failed_count' => '%s ناموفق',
        'batch_item_failed_reason' => 'ناموفق: %s',
        'view_batch_report' => 'مشاهده گزارش',
        'batch_report_title' => 'گزارش دسته‌ای',
        'and_n_more' => 'و %s مورد دیگر',
```

- [ ] **Step 2: Add the same keys to `public/assets/js/i18n.js`**

In the English object, add near `"preview_changes"`:

```js
        "select_all_matching_filters": "Select all %s products matching filters",
        "batch_progress": "%s of %s processed",
        "batch_succeeded_count": "%s succeeded",
        "batch_failed_count": "%s failed",
        "batch_item_failed_reason": "Failed: %s",
        "view_batch_report": "View report",
        "batch_report_title": "Batch report",
        "and_n_more": "and %s more",
```

In the Persian object, add near the Persian `"preview_changes"` entry:

```js
        "select_all_matching_filters": "انتخاب همه %s محصول منطبق با فیلترها",
        "batch_progress": "%s از %s پردازش شد",
        "batch_succeeded_count": "%s موفق",
        "batch_failed_count": "%s ناموفق",
        "batch_item_failed_reason": "ناموفق: %s",
        "view_batch_report": "مشاهده گزارش",
        "batch_report_title": "گزارش دسته‌ای",
        "and_n_more": "و %s مورد دیگر",
```

Note: the client-side `t()` function (in `public/assets/js/i18n.js`'s surrounding code) does simple `%s`-sequential substitution, same convention already used by every other multi-arg key in this file — no new substitution mechanism needed.

- [ ] **Step 3: Lint check**

Run: `php -l includes/i18n.php && node --check public/assets/js/i18n.js`
Expected: `No syntax errors detected` and no output

- [ ] **Step 4: Commit**

```bash
git add includes/i18n.php public/assets/js/i18n.js
git commit -m "Add batch-overhaul UI translation keys (en/fa)"
```

---

### Task 7: Frontend — select all matching filters (products list)

**Files:**
- Modify: `public/products.php` (selection bar markup)
- Modify: `public/assets/js/products.js` (`state`, `bindEvents()`, `updateSelectionBar()`, batch-trigger handler)

**Interfaces:**
- Produces: `state.selectAllMatchingFilters: boolean` (new field, mutually exclusive with `state.selected` having entries — setting one clears the other).
- Consumes: `state.filters` (existing), `buildQuery()` (existing, for reference — the raw `state.filters` object is what gets sent as `filters` in the batch request body, not the query-string form).

- [ ] **Step 1: Add the "select all matching filters" link to the selection bar**

In `public/products.php`, replace the selection bar block (currently lines 154-160):

```php
  <!-- Selection action bar -->
  <div id="selection-bar" class="selection-bar-desktop hidden fixed bottom-16 left-0 right-0 z-30 bg-gray-900 text-white px-4 py-3 flex flex-col gap-2">
    <div class="flex items-center justify-between">
      <span id="selection-count" class="text-sm">0 <?php echo htmlspecialchars(t('selected_count')); ?></span>
      <div class="flex gap-2">
        <button id="selection-cancel" class="rounded-lg border border-white/30 px-3 py-2 text-sm"><?php echo htmlspecialchars(t('cancel')); ?></button>
        <button id="selection-batch" class="rounded-lg bg-white text-gray-900 px-3 py-2 text-sm font-medium"><?php echo htmlspecialchars(t('batch_actions')); ?></button>
      </div>
    </div>
    <button id="select-all-matching-filters" class="hidden text-xs text-white/80 underline text-left"></button>
  </div>
```

- [ ] **Step 2: Track total-matched count and add the state field**

In `public/assets/js/products.js`, add `selectAllMatchingFilters: false,` to the `state` object (currently lines 6-22), right after `selected: new Set(),` (line 11):

```js
  selected: new Set(),
  selectAllMatchingFilters: false,
  lastTotal: 0,
```

In `loadProducts()` (currently lines 240-271), right after `state.totalPages = data.total_pages;` (line 258), add:

```js
    state.lastTotal = data.total;
    updateSelectAllMatchingFiltersLink();
```

- [ ] **Step 3: Add the link's render/wire logic**

Add this new function to `public/assets/js/products.js`, right after `updateSelectionBar()` (currently ends around line 1006 — find it via `grep -n "^function updateSelectionBar" public/assets/js/products.js` since the exact line has likely shifted further by the time this task runs; insert immediately after that function's closing `}`, before `updateFilterBadge()`):

```js
function updateSelectAllMatchingFiltersLink() {
  const link = document.getElementById('select-all-matching-filters');
  const loadedCount = activeContainer().querySelectorAll('[data-id]').length;

  if (!state.selectionMode || state.lastTotal <= loadedCount) {
    link.classList.add('hidden');
    return;
  }

  link.classList.remove('hidden');
  link.textContent = state.selectAllMatchingFilters
    ? t('selected_count') + ': ' + state.lastTotal
    : t('select_all_matching_filters', state.lastTotal);
}
```

- [ ] **Step 4: Wire the link's click handler and integrate with existing selection state**

In `bindEvents()` (currently starts at line 868 — confirm with `grep -n "^function bindEvents" public/assets/js/products.js` since line numbers shift as earlier tasks/features land), add this right after the `selectAllCheckbox` change handler block (currently ends around line 955, right before `document.getElementById('selection-cancel')`):

```js
  document.getElementById('select-all-matching-filters').addEventListener('click', () => {
    state.selectAllMatchingFilters = !state.selectAllMatchingFilters;
    if (state.selectAllMatchingFilters) {
      state.selected.clear();
    }
    updateSelectionBar();
    updateSelectAllMatchingFiltersLink();
  });
```

In the existing `selectAllCheckbox` change handler (currently lines 701-719), add `state.selectAllMatchingFilters = false;` as the first line inside the handler (right after `state.selectionMode = selectAllCheckbox.checked;`), so ticking/unticking the page-level select-all checkbox always falls back to explicit-id selection:

```js
  selectAllCheckbox.addEventListener('change', () => {
    state.selectionMode = selectAllCheckbox.checked;
    state.selectAllMatchingFilters = false;
    if (!state.selectionMode) state.selected.clear();
```

In `updateSelectionBar()` (currently lines 756-770), change the count line and the `selectAllCheckbox` sync so both selection modes display correctly:

```js
function updateSelectionBar() {
  const bar = document.getElementById('selection-bar');
  const count = document.getElementById('selection-count');
  const selectedCount = state.selectAllMatchingFilters ? state.lastTotal : state.selected.size;

  if (state.selectionMode && selectedCount > 0) {
    bar.classList.remove('hidden');
    count.textContent = `${selectedCount} ${t('selected_count')}`;
  } else {
    bar.classList.add('hidden');
  }

  const total = activeContainer().querySelectorAll('[data-id]').length;
  const selectAllCheckbox = document.getElementById('select-all-checkbox');
  selectAllCheckbox.checked = state.selectionMode && !state.selectAllMatchingFilters && total > 0 && state.selected.size === total;
  selectAllCheckbox.indeterminate = state.selectionMode && !state.selectAllMatchingFilters && state.selected.size > 0 && state.selected.size < total;

  updateSelectAllMatchingFiltersLink();
}
```

Finally, change the `selection-batch` click handler (currently around lines 962-966 — find it via `grep -n "selection-batch" public/assets/js/products.js`) to carry either shape into `sessionStorage`:

```js
  document.getElementById('selection-batch').addEventListener('click', () => {
    if (state.selectAllMatchingFilters) {
      sessionStorage.setItem('batch_selection', JSON.stringify({ selectAll: true, filters: state.filters, total: state.lastTotal }));
    } else {
      if (state.selected.size === 0) return;
      sessionStorage.setItem('batch_selection', JSON.stringify({ ids: Array.from(state.selected) }));
    }
    window.location.href = '/batch.php';
  });
```

- [ ] **Step 5: Lint check**

Run: `php -l public/products.php && node --check public/assets/js/products.js`
Expected: `No syntax errors detected` and no output

- [ ] **Step 6: Commit**

```bash
git add public/products.php public/assets/js/products.js
git commit -m "Add select-all-matching-filters option to products list selection"
```

---

### Task 8: Frontend — batch page progress bar and live report

**Files:**
- Modify: `public/batch.php` (progress/report markup, `sessionStorage` key read)
- Modify: `public/assets/js/batch.js` (job start/poll loop, replaces the old direct-apply flow)

**Interfaces:**
- Consumes: `POST /api/batch-jobs.php?action=start` and `GET /api/batch-jobs.php?action=poll&id=X` (Tasks 4-5). `App.api()`, `App.toast()`, `App.notifyOnNextPage()` (existing, `public/assets/js/app.js`).
- Produces: none consumed by later tasks (this is the last frontend integration point for the live flow; Task 9 covers the separate Logs-page view).

- [ ] **Step 1: Read the new `batch_selection` sessionStorage shape**

In `public/assets/js/batch.js`, replace the top of `init()` (currently lines 10-33) — specifically the `selectedIds` read (lines 13-23) — with:

```js
let selection = null; // {ids: [...]} or {selectAll: true, filters: {...}, total: N}

async function init() {
  await ensureSession();

  try {
    selection = JSON.parse(sessionStorage.getItem('batch_selection') || 'null');
  } catch (e) {
    selection = null;
  }

  const count = selection && selection.ids ? selection.ids.length : (selection && selection.selectAll ? selection.total : 0);

  if (!selection || count === 0) {
    document.getElementById('no-selection').classList.remove('hidden');
    document.getElementById('logout-btn').addEventListener('click', () => App.logout());
    return;
  }

  document.getElementById('batch-content').classList.remove('hidden');
  document.getElementById('selection-count').textContent = count;

  if (window.CURRENT_USER.role === 'admin' || window.CURRENT_USER.role === 'superadmin') {
    document.getElementById('price-section').classList.remove('hidden');
  }

  bindEvents();
}
```

Every other reference to `selectedIds` in this file changes to `selection` — see the following steps for each call site.

- [ ] **Step 2: Update `previewPrice()`/`previewStock()`/`runPreview()` to send the new selection shape**

In `previewPrice()` (currently lines 127-161), change the `pendingRequest` object's `ids: selectedIds,` line to spread the selection shape instead:

```js
  pendingRequest = Object.assign({
    action: 'price',
    percent,
    mode,
    step_or_ending: stepOrEnding,
    apply_to: applyTo,
  }, selection);
```

In `previewStock()` (currently lines 163-177), make the equivalent change:

```js
  pendingRequest = Object.assign({
    action: 'stock',
    stock_action: stockAction,
    value,
  }, selection);
```

`runPreview()` (currently lines 179-210) itself needs no change — it already just POSTs `Object.assign({ preview: true }, request)` to `/api/batch.php`, and the preview endpoint (Task 3) already reads `ids`/`select_all`/`filters` off the body directly via `resolve_batch_target_ids()`. Add one line right after the existing `if (data.changes.length === 0) { ... }` block (currently lines 191-193) to surface the "and N more" truncation note:

```js
    if (data.truncated) {
      const note = document.createElement('p');
      note.className = 'text-xs text-gray-400 pt-1';
      note.textContent = t('and_n_more', data.total_matched - data.changes.length);
      previewList.appendChild(note);
    }
```

- [ ] **Step 3: Replace `confirmApply()` with the job start/poll flow**

Replace `confirmApply()` (currently lines 212-241) with:

```js
async function confirmApply() {
  if (!pendingRequest) return;

  const confirmBtn = document.getElementById('preview-confirm');
  confirmBtn.disabled = true;
  confirmBtn.textContent = t('applying');

  try {
    const startBody = Object.assign({}, pendingRequest);
    delete startBody.preview;

    const started = await App.api('/api/batch-jobs.php?action=start', {
      method: 'POST',
      body: JSON.stringify(startBody),
    });

    document.getElementById('preview-section').classList.add('hidden');
    pendingRequest = null;
    sessionStorage.removeItem('batch_selection');

    showProgress(started.id, started.total_items);
  } catch (err) {
    App.toast(err.message, 'error');
    confirmBtn.disabled = false;
    confirmBtn.textContent = t('apply_changes');
  }
}

function showProgress(jobId, totalItems) {
  const section = document.getElementById('progress-section');
  const bar = document.getElementById('progress-bar');
  const label = document.getElementById('progress-label');
  const reportList = document.getElementById('progress-report-list');

  section.classList.remove('hidden');
  section.scrollIntoView({ behavior: 'smooth', block: 'start' });
  label.textContent = t('batch_progress', 0, totalItems);
  bar.style.width = '0%';

  const poll = async () => {
    try {
      const data = await App.api(`/api/batch-jobs.php?action=poll&id=${jobId}`);
      const job = data.job;

      const percent = job.total_items > 0 ? Math.round((job.processed_items / job.total_items) * 100) : 100;
      bar.style.width = `${percent}%`;
      label.textContent = t('batch_progress', job.processed_items, job.total_items);

      data.items.forEach((item) => reportList.appendChild(renderReportRow(item)));

      if (job.status === 'completed') {
        const summary = document.createElement('p');
        summary.className = 'text-sm font-medium text-gray-900 pt-2';
        summary.textContent = `${t('batch_succeeded_count', job.succeeded_items)} · ${t('batch_failed_count', job.failed_items)}`;
        reportList.parentElement.insertBefore(summary, reportList);

        if (job.log_id) {
          App.notifyOnNextPage(null, { logId: job.log_id });
        }
        return;
      }

      setTimeout(poll, 1500);
    } catch (err) {
      App.toast(err.message, 'error');
      setTimeout(poll, 1500);
    }
  };

  poll();
}

function renderReportRow(item) {
  const row = document.createElement('div');
  const ok = item.status === 'success';
  row.className = `flex items-center justify-between text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0 ${ok ? 'text-gray-700' : 'text-red-600'}`;

  const detail = ok
    ? Object.entries(item.change || {}).map(([field, v]) => `${field}: ${v.old} → ${v.new}`).join(' · ')
    : t('batch_item_failed_reason', item.error || '');

  row.innerHTML = `
    <span class="truncate pr-2">${escapeHtml(item.product_name || ('#' + item.product_id))}</span>
    <span class="text-xs whitespace-nowrap">${escapeHtml(detail)}</span>
  `;
  return row;
}
```

Note: `App.notifyOnNextPage(null, {logId})` — check `App.notifyOnNextPage()`'s existing signature in `public/assets/js/app.js` before this step; every other call site in this codebase passes a message string as the first argument (e.g. `App.notifyOnNextPage(data.message, {logId: data.log_id})`). Since this job's completion doesn't have a single pre-built message string the way `/api/batch.php`'s old response did, pass the same summary text already rendered on-screen instead of `null`: `` `${t('batch_succeeded_count', job.succeeded_items)} · ${t('batch_failed_count', job.failed_items)}` `` — build that string once above the `if (job.log_id)` check and reuse it for both the on-screen summary paragraph and this call, rather than duplicating the template literal.

- [ ] **Step 4: Add the progress/report markup**

In `public/batch.php`, add this new section right after the existing `<!-- Preview / confirm -->` block (currently ends at line 129, right before the closing `</div>` of `#batch-content` on line 130):

```php
      <!-- Live progress / report (shown once "Apply changes" starts a job) -->
      <div id="progress-section" class="hidden bg-white rounded-2xl border border-gray-100 p-4 space-y-3">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('batch_report_title')); ?></h2>
        <div class="w-full bg-gray-100 rounded-full h-2 overflow-hidden">
          <div id="progress-bar" class="bg-gray-900 h-2 rounded-full transition-all" style="width: 0%"></div>
        </div>
        <p id="progress-label" class="text-xs text-gray-500"></p>
        <div id="progress-report-list" class="space-y-2 max-h-96 overflow-y-auto"></div>
      </div>
```

- [ ] **Step 5: Lint check**

Run: `php -l public/batch.php && node --check public/assets/js/batch.js`
Expected: `No syntax errors detected` and no output

- [ ] **Step 6: Manual verification**

1. On `/products.php`, enter selection mode, select a handful of products across two "Load more" pages, click Batch actions.
2. Run a price preview, confirm the change list and confirm the truncation note doesn't appear (fewer than 100 selected).
3. Apply — confirm a progress bar appears and fills, the report list populates with green success rows, and the summary line shows all-succeeded.
4. Back on `/products.php`, use "select all matching filters" with a narrow filter (e.g. one category), confirm the link shows the correct total, run a batch stock change, confirm progress + report again and that only that category's products were affected in WooCommerce.
5. Check both `en`/`fa`.

- [ ] **Step 7: Commit**

```bash
git add public/batch.php public/assets/js/batch.js
git commit -m "Add live progress bar and per-product report to batch apply flow"
```

---

### Task 9: Frontend — view a past batch report from Logs

**Files:**
- Modify: `public/assets/js/logs.js` (`renderLogItem()`)
- Modify: `public/logs.php` (add a report modal container)

**Interfaces:**
- Consumes: `GET /api/batch-jobs.php?action=detail&log_id=X` (Task 5), `renderReportRow()`-equivalent rendering (duplicated here deliberately — `batch.js` and `logs.js` are two separate page scripts per this codebase's "one JS file per page" convention; see `CLAUDE.md`).

- [ ] **Step 1: Add the report modal markup**

In `public/logs.php`, add this right before the closing `</body>` tag (find it via `grep -n "</body>" public/logs.php` — it's the last line before the `<script>` tags' closing, same pattern as every other page in this app):

```php
  <div id="batch-report-modal" class="hidden fixed inset-0 z-50 bg-black/50 flex items-end sm:items-center justify-center p-4">
    <div class="bg-white rounded-2xl max-w-lg w-full max-h-[80vh] flex flex-col">
      <div class="flex items-center justify-between p-4 border-b border-gray-100">
        <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('batch_report_title')); ?></h2>
        <button id="batch-report-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>
      <div id="batch-report-list" class="p-4 space-y-2 overflow-y-auto"></div>
    </div>
  </div>
```

- [ ] **Step 2: Wire the "View report" link and modal**

In `public/assets/js/logs.js`, add this to `bindEvents()` (currently lines 41-62), right before the closing brace:

```js
  document.getElementById('batch-report-close').addEventListener('click', () => {
    document.getElementById('batch-report-modal').classList.add('hidden');
  });
```

In `renderLogItem()` (currently lines 103-148), add this right after the `if (log.can_undo) { ... }` block (currently ends at line 145, right before `return item;`):

```js
  if (log.batch_job_id) {
    const link = document.createElement('button');
    link.className = 'mt-2 ml-3 text-xs font-medium text-gray-600 underline';
    link.textContent = t('view_batch_report');
    link.addEventListener('click', () => openBatchReport(log.id));
    item.querySelector('.flex-1').appendChild(link);
  }
```

Then add these two new functions at the end of the file (after `escapeHtml()`, currently ending at line 160):

```js
async function openBatchReport(logId) {
  const modal = document.getElementById('batch-report-modal');
  const list = document.getElementById('batch-report-list');
  list.innerHTML = `<p class="text-sm text-gray-400">${escapeHtml(t('loading'))}</p>`;
  modal.classList.remove('hidden');

  try {
    const data = await App.api(`/api/batch-jobs.php?action=detail&log_id=${logId}`);
    list.innerHTML = '';

    const summary = document.createElement('p');
    summary.className = 'text-sm font-medium text-gray-900 pb-2 border-b border-gray-100';
    summary.textContent = `${t('batch_succeeded_count', data.job.succeeded_items)} · ${t('batch_failed_count', data.job.failed_items)}`;
    list.appendChild(summary);

    data.items.forEach((item) => list.appendChild(renderBatchReportRow(item)));
  } catch (err) {
    list.innerHTML = `<p class="text-sm text-red-600">${escapeHtml(err.message)}</p>`;
  }
}

function renderBatchReportRow(item) {
  const row = document.createElement('div');
  const ok = item.status === 'success';
  row.className = `flex items-center justify-between text-sm border-b border-gray-100 pb-2 last:border-0 last:pb-0 ${ok ? 'text-gray-700' : 'text-red-600'}`;

  const detail = ok
    ? Object.entries(item.change || {}).map(([field, v]) => `${field}: ${v.old} → ${v.new}`).join(' · ')
    : t('batch_item_failed_reason', item.error || '');

  row.innerHTML = `
    <span class="truncate pr-2">${escapeHtml(item.product_name || ('#' + item.product_id))}</span>
    <span class="text-xs whitespace-nowrap">${escapeHtml(detail)}</span>
  `;
  return row;
}
```

- [ ] **Step 3: Lint check**

Run: `php -l public/logs.php && node --check public/assets/js/logs.js`
Expected: `No syntax errors detected` and no output

- [ ] **Step 4: Manual verification**

1. After Task 8's manual batch run, open the Logs page, confirm the batch entry shows a "View report" link.
2. Click it, confirm the modal shows the same per-product breakdown as the live view did.
3. Check both `en`/`fa`.

- [ ] **Step 5: Commit**

```bash
git add public/logs.php public/assets/js/logs.js
git commit -m "Add past-batch report viewer to the Logs page"
```
