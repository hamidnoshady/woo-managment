# Batch Edit Overhaul — Design

Status: approved
Part of the same 4-piece project as [2026-07-06-product-variations-design.md](2026-07-06-product-variations-design.md); this is piece 3 ("batch-edit overhaul").

## Context

`/api/batch.php` and `/batch.php` already support percent-based price
changes and stock delta/set, applied to whatever product ids the caller
sends. Three real problems, confirmed by reading the current code:

1. **Fail-fast, all-or-nothing.** `fetch_products_by_ids()` fetches each
   product individually; a single `RuntimeException` (one bad id, one
   transient network blip) aborts the whole request with a 502 —
   regardless of whether hundreds of other items would have succeeded.
   `apply_batch_updates()`'s 100-item chunking has the same problem: one
   failing chunk 502s the response even if earlier chunks already applied
   successfully on the remote site, leaving the user with no idea what
   actually happened.
2. **No live progress.** The whole operation is one synchronous HTTP
   request; for a large selection this risks a slow response or a
   gateway timeout (502/504s have already hit this app in production
   before, per prior session history) with zero visibility while it runs.
3. **Selection is capped to what's rendered in the DOM.** `state.selected`
   in `products.js` only ever contains ids of currently-loaded cards/rows;
   there is no way to act on every product matching the active filters
   without manually paging through and loading all of them first.

Scope decisions (confirmed with user):
- Rebuild as a full async job with live progress (not a simpler
  fail-soft synchronous fix) — mirrors this codebase's existing
  site-backup job/tick pattern (`SiteBackupManager`, `site_backups` table,
  `public/api/site-backups.php`'s start/poll shape) rather than inventing
  a new pattern.
- "Select all" means all products matching the *currently active
  filters* on the products page, not the unfiltered whole catalog.
- The detailed per-product report is visible live while the job runs,
  and stays viewable afterward from the existing Logs page (not just a
  one-time view in the current tab).

Variations are already selectable for batch with no additional work:
because variation rows (added in the product-variations feature) reuse
the same `.select-checkbox`/`wireProductElement()` markup as top-level
products, once a variable product is expanded its variations are
selected/deselected by the exact same selection-mode toggle. Nothing in
this spec needs to special-case variation ids — `getProduct()`/
`batchProducts()` on the WP side already resolve them like any other
WooCommerce product id.

## Data model

Two new tables in `includes/Database.php`, following the existing
`site_backups`/`site_restores` job-table convention (inline
`CREATE TABLE IF NOT EXISTS`, `addColumnIfMissing` for any later
additions):

```sql
CREATE TABLE IF NOT EXISTS batch_jobs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    user_id INT NOT NULL,
    action VARCHAR(20) NOT NULL,             -- 'price' | 'stock'
    params_json TEXT NOT NULL,                -- the request's price/stock params
    status VARCHAR(20) NOT NULL DEFAULT 'running',  -- running|completed
    total_items INT NOT NULL DEFAULT 0,
    processed_items INT NOT NULL DEFAULT 0,
    succeeded_items INT NOT NULL DEFAULT 0,
    failed_items INT NOT NULL DEFAULT 0,
    log_id INT NULL,
    started_at INT NOT NULL,
    completed_at INT NULL,
    KEY idx_batch_jobs_site (site_id, id),
    CONSTRAINT fk_batch_jobs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4

CREATE TABLE IF NOT EXISTS batch_job_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    batch_job_id INT NOT NULL,
    product_id INT NOT NULL,
    product_name VARCHAR(255) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending|success|failed
    change_json TEXT NULL,                    -- {field: {old, new}, ...} on success
    error TEXT NULL,                          -- set on failed
    KEY idx_batch_job_items_job (batch_job_id, status),
    CONSTRAINT fk_batch_job_items_job FOREIGN KEY (batch_job_id) REFERENCES batch_jobs(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
```

`activity_logs` gains one nullable column, `batch_job_id INT NULL`
(via `addColumnIfMissing`), set on the single log entry a completed batch
job writes — this is how the Logs page finds the full per-item report
for a historical batch entry.

A job is created with every target row **pre-inserted as `pending`** at
start time. This is also how "select all matching filters" resolves: the
id list is computed once, server-side, at job creation — the browser
never holds or transmits a large id array for this path.

## WP plugin addition

`Wma_Products::list_product_ids(array $params): array` — same filter
params `list_products()` already accepts (`search`, `category`,
`stock_status`, `on_sale`, `taxonomy_terms`), but:

```php
$args = ['return' => 'ids', 'limit' => -1, /* ...same filter args... */];
```

No pagination cap, no full product hydration — just ids, since a
"select all matching filters" batch could span the entire catalog. New
REST route: `GET /wma/v1/products/ids`. New `SiteAgentClient::listProductIds(array $params): array`.

## Job creation and ticking

**`POST /api/batch-jobs.php?action=start`**

Body: either `{action: 'price'|'stock', ids: [...], ...params}` (explicit
selection, current behavior) or `{action, select_all: true, filters: {...}, ...params}`
(resolves ids via `listProductIds()` using the same filter-param mapping
`products.php` already does). Inserts the job row plus one `batch_job_items`
row per resolved id (`status = 'pending'`), returns `{id: jobId}` — no
processing happens synchronously in this request.

**`GET /api/batch-jobs.php?action=poll&id=X`** — the tick. Claims up to
25 `pending` rows for this job, and for each one **individually**,
wrapped in its own try/catch:
- fetches the current product via `getProduct()`,
- computes the new value(s) exactly as today's `batch.php` does
  (`adjust_price()` for price mode, delta/set for stock),
- applies via `updateProduct()`,
- on success: row → `success`, `change_json` records `{old, new}` per
  changed field,
- on any `Throwable`: row → `failed`, `error` records the message; the
  loop continues to the next item regardless — **this is the fix for
  "no bug crash the progress."**

After the batch of up to 25, updates the job's counters
(`processed_items`, `succeeded_items`, `failed_items`) and returns the
job plus the items just processed (so the polling UI can append to a
live list without re-fetching everything already shown). When
`processed_items === total_items`, status flips to `completed`,
`invalidate_products_cache()` runs once, and a single `batch_update`
activity-log entry is written (`log_batch_price_applied`/
`log_batch_stock_applied`, same as today) with `batch_job_id` set and
undo data built **only from `success` rows** — a failed item was never
applied, so it's correctly never part of undo.

No auto-retry: a `failed` row stays failed. Re-running the batch action
from scratch (new job) is how a user retries.

## Frontend — products list

- The top "select all" checkbox keeps its current behavior (selects
  everything currently loaded, per-page). When the last products-list
  response's `total` exceeds the current in-DOM selection count, an
  inline link appears in the selection bar: *"Select all N products
  matching current filters."* Clicking it sets a mutually-exclusive
  `state.selectAllMatchingFilters = true` flag (clearing the explicit
  `Set`) and updates the selection-bar count to `N`.
- "Batch actions" writes either `{ids: [...]}` or
  `{selectAll: true, filters: {...}}` (the current `state.filters`, same
  shape `buildQuery()` already turns into request params) into
  `sessionStorage`, then navigates to `/batch.php` as today.

## Frontend — batch page

- Preview (`price-preview-btn`/`stock-preview-btn`) stays a quick
  synchronous call — it doesn't write anything, so it doesn't need the
  job machinery. For a `select_all` request, the preview endpoint caps
  its response to the first 100 matched changes plus a
  "and N more products" note, rather than resolving and returning
  a preview for a potentially huge set synchronously.
- "Apply changes" now: `POST /api/batch-jobs.php?action=start`, then
  immediately render a progress bar (`processed/total`) and an empty
  live report list, then poll `action=poll` every 1.5s (same
  `setTimeout`-based polling shape this file already uses for the
  "backup before changes" button). Each poll response's items are
  appended to the report list — green rows for `success` (showing
  old→new), red rows for `failed` (showing the error) — until
  `status === 'completed'`, at which point polling stops and a final
  summary line shows ("482 succeeded, 3 failed").
- The report stays on screen after completion (no auto-redirect to
  `/products.php` the way today's `confirmApply()` does) — the user
  decides when to navigate away, since the point of the report is to
  actually look at it.

## Frontend/backend — Logs page

- The existing Logs page's batch-type entries (`batch_price`/
  `batch_stock`) become clickable to expand the full report: a new
  endpoint `GET /api/batch-jobs.php?action=detail&log_id=X` looks up the
  job via `activity_logs.batch_job_id` and returns the job plus all its
  items, rendered with the same success/failed row styling as the live
  view.

## Error handling

- Per-item failures never abort a job (core requirement, detailed above).
- If the site-agent connection is down for an entire tick (every
  `getProduct()`/`updateProduct()` call throws), that tick's claimed
  items are all marked `failed` with the connection error; the tick
  itself still returns normally (not a 502) so polling continues
  and the next tick simply tries the next batch of `pending` rows —
  a transient outage costs some failed items, not a stuck job.
- If the browser tab closes mid-job, the job's row simply stops
  advancing (no background worker resumes it) — reopening `/batch.php`
  does not auto-resume an abandoned job. This is an accepted limitation:
  batch jobs are a chunking mechanism to avoid one blocking request, not
  a background task queue. A user who wants a job to actually finish
  needs to keep the tab open (or reload `/batch.php`, which does not
  currently re-attach to an in-progress job — out of scope for this
  spec; if this becomes a real pain point later, re-attaching to the
  most recent running job for the site is a natural small follow-up).
- `select_all` with zero matching products (e.g. filters resolve to
  nothing): `action=start` returns a 422 rather than creating an empty
  job.

## i18n

New keys needed in both `includes/i18n.php` and
`public/assets/js/i18n.js` (en + fa): `select_all_matching_filters`
(with a `%s` for the count), `batch_progress` (e.g. "X of Y processed"),
`batch_succeeded_count`, `batch_failed_count`, `batch_item_failed_reason`,
`view_batch_report` (for the Logs-page expand link).

## Testing / verification

No test suite in this repo. Manual verification:
- Select a handful of products across two different "Load more" pages,
  run a batch price change, confirm live progress + report, confirm
  WooCommerce actually reflects every change.
- Force at least one item to fail (e.g. delete a product on the WP side
  mid-batch, or select an id that will 404) and confirm the job
  completes with that one item reported `failed` and everything else
  still `success` — this is the core regression test for the fail-fast
  bug being fixed.
- Use "select all matching filters" with a narrow filter (e.g. a single
  category with 5 products) and confirm exactly those 5 are targeted,
  not the whole catalog.
- Expand a variable product, select one of its variations plus a couple
  of regular products, run a batch price change, confirm the variation's
  price actually changed in WooCommerce alongside the products.
- Undo the batch from the activity log within the 10s window, confirm
  only the successfully-changed items revert.
- Reopen the Logs page later (past the undo window) and confirm the
  full per-product report is still viewable for a past batch entry.
- Check both `en`/`fa` for all new UI text.
