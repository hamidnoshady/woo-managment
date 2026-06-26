# Unified Site Agent (woo-mgmt-agent) — Design

## Problem

The earlier design
([2026-06-26-site-backup-restore-design.md](2026-06-26-site-backup-restore-design.md))
covered only S3 backup/restore via a companion WordPress plugin. Separately,
this app's current connection method for everyday product management —
WooCommerce REST API (consumer key/secret) plus WordPress REST API
(application password) — has two confirmed root-cause problems:

1. **1-2 minute product load times.** `WordPressClient::listCustomProductTaxonomies()`
   fetches each custom taxonomy's terms via a *separate sequential HTTP
   request* (`includes/WordPressClient.php:42-71`), each up to a 30s
   timeout. The frontend (`products.js:31-36`) blocks product loading on
   this call finishing first.
2. **Custom taxonomies randomly disappear.** `WordPressClient::listTerms()`
   silently returns `[]` on any non-2xx response or timeout
   (`WordPressClient.php:76-84`), and that empty result gets cached blind
   for 300 seconds (`public/api/taxonomies.php:36`) — a single transient
   network hiccup on one taxonomy erases it from the UI for five minutes.

Since no real users depend on the current connection method yet, this is
the moment to replace it outright rather than patch around it — and since a
WordPress companion plugin (already designed for backups) runs *inside* the
WordPress process, the same architecture change that removes consumer
key/secret/app-password entirely also eliminates the N-sequential-HTTP-call
root cause, because plugin-side taxonomy/term lookups become local
`get_terms()` calls instead of N round-trips over the public internet.

This spec **supersedes the plugin scope** of the prior backup design (the
plugin is renamed and extended) and **extends** it to be the sole channel
for all site communication: products, categories, custom taxonomies, media,
and backup/restore. The backup/restore architecture itself (chunked jobs,
presigned S3 URLs, safety snapshots, atomic restore swap) is unchanged from
the prior spec and is not repeated here in full — see that document for
those details.

## Goals

- One plugin (`woo-mgmt-agent`), one pairing token, one settings screen per
  site — no consumer key/secret, no WP application password, anywhere.
- Product/category/taxonomy/media operations run as in-process WordPress
  queries inside the plugin, eliminating the N-sequential-HTTP-call pattern
  that caused both the slowness and the silent-failure flakiness.
- Full operational parity with today's `WooCommerceClient`/`WordPressClient`
  — every product CRUD, batch, category, taxonomy, and media operation
  currently used by `public/api/*.php` must have a plugin-side equivalent.
- Clean replacement, not a migration: `consumer_key`, `consumer_secret`,
  `wp_username`, `wp_app_password` are dropped from the `sites` table; every
  site must be (re-)paired with the plugin to work again.

## REST surface

All routes live under `wma/v1` (renamed from the prior design's `wmba/v1`),
authenticated by the same Bearer pairing token used for backups — one
secure channel, one token, used for everything.

**Products & catalog** (new):
- `GET /products` — paginated (`page`, `per_page`), filterable by `search`,
  `category`, `stock_status`, `on_sale`, and custom taxonomy term ids.
  Built via `wc_get_products()`; each product's category and custom
  taxonomy term assignments are attached in the same query — no separate
  "fetch taxonomies for this product" round-trip.
- `GET /products/{id}` — full detail, taxonomy assignments included.
- `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}` — create,
  update (including taxonomy term assignment in the same call), delete.
- `POST /products/batch` — bulk update (price/stock), same `{update: [...]}`
  shape as today's WooCommerce REST batch endpoint, up to 100 per call.
- `GET /categories` — product categories with parent/count, for the
  existing hierarchy-flattening logic in `public/api/categories.php`.
- `GET /taxonomies` — every custom product taxonomy and its terms in one
  response, computed via `get_object_taxonomies('product')` +
  `get_terms()` per taxonomy — still N lookups, but all in-process (no
  network latency, no partial-failure mode distinct from total failure).
- `POST /media` — binary image upload, returns attachment id + URL.

**Backup & restore** (unchanged from the prior spec): `POST /jobs`,
`GET /jobs/{id}?tick=1`.

## Caching (fixes the disappearing-taxonomy bug)

The asymmetry that caused the bug — products cached 10s and
mutation-invalidated, taxonomies cached 300s and mutation-blind — is
removed: **taxonomies are cached the same way products already are**, 10s
TTL, invalidated by the same `invalidate_products_cache()` call product
mutations already trigger. Combined with the plugin call no longer having a
silent partial-failure mode (a plugin-side error is now one clear HTTP
failure surfaced to the UI, not an empty array baked into a long cache), a
transient hiccup can no longer make a taxonomy disappear for minutes.

## Data flow example: loading the products page

```
products.js                 woo-managment                    plugin (in WP process)
    |--- GET /api/taxonomies.php -->|                              |
    |                               |--- GET /taxonomies (tick) -->|--- get_object_taxonomies() + get_terms() x N (local)
    |                               |<------------------------------|
    |<--- 10s-cached response ------|
    |--- GET /api/products.php  --->|
    |                               |--- GET /products?page=1 ----->|--- wc_get_products() (local, with taxonomy terms attached)
    |                               |<------------------------------|
    |<------------------------------|
```

Both calls are now one HTTP round-trip each (woo-managment <-> plugin),
versus the old design's 1 (products) + N (one per custom taxonomy) round
trips, each hop over the public internet with a 30s timeout.

## Sites table & app-side changes

- Drop: `consumer_key`, `consumer_secret`, `wp_username`, `wp_app_password`.
- Rename (the prior backup design's columns, not yet built, so this is a
  rename in the plan, not a live migration): `backup_agent_token` →
  `agent_token`, `backup_agent_paired_at` → `agent_paired_at`,
  `backup_agent_last_seen_at` → `agent_last_seen_at`.
- Delete `includes/WooCommerceClient.php` and `includes/WordPressClient.php`
  entirely; replace with `includes/SiteAgentClient.php`, which calls the
  plugin's `wma/v1` routes using `$site['store_url']` + `$site['agent_token']`.
- Every consumer of the old clients is rewired to `SiteAgentClient`:
  `public/api/products.php`, `product.php`, `categories.php`,
  `taxonomies.php`, `batch.php`, `stock.php`, `media.php`.
- The site sheet UI (`public/admin/sites.php`) drops the consumer
  key/secret and WP username/app-password fields entirely; only the
  pairing-token generator (already designed for backups) remains.

## AI integration (unchanged)

`includes/AiClient.php`'s text/image generation flow is untouched — it
already returns generated content to the browser for review before the
user explicitly saves it via the product update path, which now goes
through `SiteAgentClient` instead of `WooCommerceClient`. No plugin-side
change needed for AI generation itself.

## Error handling

- A plugin-side WooCommerce/WordPress error (e.g. invalid product data,
  WooCommerce not active) returns a clear HTTP 4xx/5xx with a message body,
  surfaced to the woo-managment UI as a real error — never silently
  swallowed into an empty result.
- If the plugin detects WooCommerce is not active, every product/category/
  taxonomy route returns `503` with `{"error": "WooCommerce is not active on this site."}`,
  shown verbatim in the settings/status UI rather than a generic failure.

## Out of scope

- Backward compatibility with the old consumer-key/app-password connection
  method (explicitly dropped per the "no real users yet" decision).
- Per-product real-time sync/webhooks — this remains a poll-on-demand model
  (the app fetches when the user views a page), matching today's behavior.
- Changes to the backup/restore job architecture itself — see the prior
  spec for that design, which is reused as-is under the renamed plugin.
