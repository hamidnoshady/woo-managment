# Product Variations Support — Design

Status: approved
Part of a 4-piece project (see below); this spec covers piece 1 only.

## Context

The full request from the user bundles four largely independent subsystems:

1. **Variations support** (this spec) — view, edit, and create WooCommerce
   variations from the admin app.
2. Per-variation price/stock editing UX (mostly falls out of #1 by reusing
   existing endpoints).
3. Batch-edit overhaul — select-all-across-pages, crash-proof progress,
   detailed change report, include variations in batch selection.
4. 24-hour "recently changed" color-coded highlighting on product cards.

These will be designed and implemented as separate spec → plan → build
cycles. This document covers #1 (and folds in #2, since it requires no new
backend work beyond #1).

Today the app has **zero** support for WooCommerce variable products:
`SiteAgentClient` and the `woo-mgmt-agent` WP plugin only speak to
`/wp-json/wma/v1/products` for simple products; there is no
`/variations` route anywhere. `Wma_Products::product_to_array()` doesn't
even expose a product's `type`, so the app currently can't tell a variable
product from a simple one.

Scope decision (confirmed with user): variation *attributes* (Size, Color,
etc.) are configured on the parent product in WooCommerce already — this
feature does not build an attribute-definition UI. "Add a variation" means
picking a combination from the parent's *existing* attribute values, not
defining new attributes.

## Backend: WP plugin (`wp-plugin/woo-mgmt-agent`)

### `includes/class-wma-products.php`

- `product_to_array()`: add `'type' => $product->get_type()` (returns
  `simple`, `variable`, etc. per WC's `WC_Product::get_type()`).
- New `list_variations(int $parentId): array`:
  - `wc_get_product($parentId)`; if not a `WC_Product_Variable`, return
    `['error' => 'Not a variable product']` semantics via the REST layer.
  - `$parent->get_children()` → array of variation post IDs.
  - For each, `wc_get_product($childId)` (returns `WC_Product_Variation`)
    and map via a new `variation_to_array()`:
    ```
    id, sku, regular_price, sale_price, price, stock_quantity,
    manage_stock, stock_status, image (single, falls back to parent image),
    attributes (assoc array, taxonomy/attribute name => term/value),
    attribute_summary (string, via wc_get_formatted_variation($variation, true, false))
    ```
- New `get_variation_options(int $parentId): array` — returns the parent's
  configured variation attributes and their possible values, e.g.
  `[{name: 'Color', options: ['Red', 'Blue']}, {name: 'Size', options: ['S','M','L']}]`,
  from `$parent->get_variation_attributes()`. Used to populate the "add
  variation" dropdowns.
- New `create_variation(int $parentId, array $data): array`:
  - Validate `$parentId` resolves to a `WC_Product_Variable`.
  - Validate the submitted attribute combination is a subset of
    `get_variation_attributes()` (reject anything not already configured —
    this is a read of existing options, not attribute creation).
  - Reject if a variation with that exact attribute combination already
    exists (`WC_Data_Store` lookup via `$parent->get_matching_variation()` —
    return a clear error rather than creating a silent duplicate).
  - `new WC_Product_Variation()`, `set_parent_id($parentId)`,
    `set_attributes($attributes)`, then reuse the existing
    `apply_fields()` for price/stock/sku, `$variation->save()`.
  - Return via `variation_to_array()`.
- `update_product()` and `batch_update()` need **no changes** —
  `wc_get_product()` already resolves variation IDs to `WC_Product_Variation`
  objects, and `apply_fields()`'s setters (`set_regular_price`,
  `set_stock_quantity`, etc.) are all defined on the shared `WC_Product`
  base class.

### `includes/class-wma-rest.php`

Three new routes, following the existing permission/error pattern:

```
GET  /wma/v1/products/{id}/variations         -> list_variations + get_variation_options combined
POST /wma/v1/products/{id}/variations         -> create_variation
```

Combining `list_variations` and `get_variation_options` into one GET
response (`{items: [...], options: [...]}`) avoids a second round trip
since the UI needs both whenever it expands a card.

## Backend: main app

### `includes/SiteAgentClient.php`

- `listVariations(int $productId): array` → GET `/products/{id}/variations`.
- `createVariation(int $productId, array $data): array` → POST same path.

### `public/api/product-variations.php` (new file)

Follows the standard endpoint boilerplate
(`install_json_fatal_handler()`, `require_login_api()`,
`require_site_api()`, `verify_csrf_api()` on POST).

- `GET ?product_id=123` → `{items: [...], options: [...]}`.
- `POST {product_id, attributes: {...}, regular_price, sale_price, stock_quantity, stock_status, sku}`:
  - Server-side sanitizes price/stock fields with the same
    `sanitize_price()` / stock validation `product.php` already uses.
  - On success, logs activity: `type: 'create_variation'`,
    `undo_data: {product_id, variation_id}` — undo calls
    `deleteProduct($variationId, true)` (variations delete via the same
    generic delete route, since `wc_get_product` resolves them).
  - Returns the created variation plus `log_id`/`message` like
    `product.php` does.

### Reused, unchanged, endpoints

- `public/api/product.php` (PUT) — single price/SKU/etc. edit. Works
  as-is for a variation id; no code change needed.
- `public/api/stock.php` — stock ± and set. Works as-is for a variation id.

This is the main simplification: the existing per-product edit and stock
endpoints are already generic over "an id that resolves to a WC_Product",
so piece #2 (per-variation price/stock editing UX) needs **no new backend
route** — only frontend wiring to point at a variation's id instead of a
top-level product's id.

## Frontend (`public/assets/js/products.js`, `public/products.php`)

- Product card gains a chevron/expand toggle, shown only when
  `item.type === 'variable'`. Collapsed by default.
- First expand triggers `GET /api/product-variations.php?product_id=X`;
  result cached in a per-card JS map so re-collapsing/re-expanding doesn't
  re-fetch.
- Each variation renders as a nested row (indented, using the
  `attribute_summary` + SKU as its label, e.g. "Red / Large — SKU:
  TS-RED-L") reusing the existing price-click-to-edit and stock ±/qty
  markup and handlers — those handlers already only care about the
  numeric id and current price/stock values, not whether the id belongs
  to a "product" or a "variation" conceptually.
- Loading state: a small inline spinner in place of the chevron while the
  variations request is in flight; a failed fetch shows an inline retry
  link rather than silently collapsing.
- "Add variation" as the last row in the expanded list: a small inline
  form with one `<select>` per attribute from `options` (values only —
  no free text, no way to introduce a new attribute value), plus
  price/stock/SKU fields, Save/Cancel. On save, POST, then splice the new
  variation into the already-rendered list (no full re-fetch).

## Data flow summary

```
Expand toggle click
  -> GET /api/product-variations.php?product_id=X
  -> SiteAgentClient::listVariations($id)
  -> WP: GET /wma/v1/products/{id}/variations
  -> Wma_Products::list_variations() + get_variation_options()
  -> render nested rows + "Add variation" form

Variation price click (existing pattern, unchanged)
  -> PUT /api/product.php {id: variationId, regular_price, ...}
  -> unchanged code path

Variation stock +/- (existing pattern, unchanged)
  -> POST /api/stock.php {id: variationId, delta}
  -> unchanged code path

Add variation submit
  -> POST /api/product-variations.php {product_id, attributes, regular_price, stock_quantity, ...}
  -> SiteAgentClient::createVariation()
  -> WP: POST /wma/v1/products/{id}/variations
  -> Wma_Products::create_variation() (validates attrs, rejects duplicates)
  -> activity log (undo = delete variation)
```

## Error handling

- Attempting to expand a simple product: toggle isn't rendered at all
  (guarded by `item.type`), so this can't happen from the UI; the
  WP-side `list_variations` still guards with a clear error if hit
  directly.
- Creating a variation with a combination that already exists: WP plugin
  rejects with 409-style error message, surfaced as a toast, form stays
  open with entered values intact so the user can adjust and retry.
- Creating a variation with an attribute value not in the parent's
  configured options: rejected server-side (dropdowns already constrain
  this client-side, but server validates independently since this is a
  trust boundary).
- Network/API failure fetching variations: inline retry link, product
  card itself is unaffected (no crash of the list page).

## i18n

New client-side strings needed in both `includes/i18n.php` and
`public/assets/js/i18n.js` (en + fa), e.g.: `show_variations`,
`hide_variations`, `add_variation`, `variation_created`,
`variation_exists_error`, `variations_load_error`, `retry`.

## Testing / verification

No test suite in this repo. Verification is manual:
`php -l` / `node --check` on every touched file, then in-browser:
- Expand/collapse a variable product card, confirm lazy-load + caching.
- Edit a variation's price via the existing click-to-edit UI; confirm it
  updates only that variation (not the parent or siblings) in WooCommerce.
- Adjust a variation's stock via +/-.
- Add a new variation with a fresh attribute combination; confirm it
  appears in WooCommerce admin.
- Attempt to add a duplicate combination; confirm the rejection message.
- Check both `en` and `fa` (RTL) for the new UI.
- Check `admin`/`shop_manager` roles both see and can use this (no new
  role restriction is introduced — variation edits follow the same
  permission model as regular product edits).
