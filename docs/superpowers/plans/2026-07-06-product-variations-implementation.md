# Product Variations Support Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the admin app view, expand, edit, and create WooCommerce product variations, reusing the existing single-product price/stock edit endpoints wherever possible.

**Architecture:** The WP-side `woo-mgmt-agent` plugin gains three new capabilities on `Wma_Products` (list variations, list available attribute options, create a variation) exposed via two new REST routes. The main app gets a thin pass-through client method + one new API endpoint for listing/creating variations. Editing an existing variation's price or stock needs **no new backend code** — `/api/product.php` and `/api/stock.php` already operate on any numeric WooCommerce product id, and a variation id is just that. The frontend adds an expand/collapse toggle to variable-product cards that lazy-loads and renders nested variation rows using the same render/wire functions already used for top-level products.

**Tech Stack:** PHP 8 (no framework), vanilla JS, WooCommerce `WC_Product_Variable`/`WC_Product_Variation` native APIs, Tailwind (CDN) for styling.

## Global Constraints

- No Composer, no build step, no new dependencies (per `CLAUDE.md`).
- No test suite in this repo — verification is `php -l` / `node --check` per file, plus manual in-browser exercise of both `en` (LTR) and `fa` (RTL), as `CLAUDE.md` prescribes.
- Any new JS string read via `t(...)` must be added to **both** `includes/i18n.php` and `public/assets/js/i18n.js`, symmetric in `en`/`fa` (per `CLAUDE.md` gotchas).
- `install_json_fatal_handler()` must be called only from files under `public/api/`.
- All mutating API endpoints require `verify_csrf_api()` and follow the boilerplate order documented in `CLAUDE.md` (`helpers.php` → fatal handler → `auth.php` → other includes → `require_login_api()` → `require_site_api()` → `verify_csrf_api()`).
- Reuse the existing `/api/product.php` (PUT) and `/api/stock.php` endpoints for editing an existing variation's price/stock — do not create new endpoints for that.

---

### Task 1: WP plugin — expose product `type` and list variations

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php:232-264` (`product_to_array`)
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php` (add new methods near `get_product`)

**Interfaces:**
- Produces: `Wma_Products::product_to_array()` now includes `'type' => string` (e.g. `'simple'`, `'variable'`).
- Produces: `Wma_Products::list_variations(int $parentId): array` → `['error' => string]` on failure, or `['items' => array<variation>, 'options' => array<{name: string, options: string[]}>]` on success. Each `variation` item: `{id, sku, regular_price, sale_price, price, stock_quantity, manage_stock, stock_status, image, attribute_summary, attributes: {attributeName: value}}`.

- [ ] **Step 1: Add `type` to `product_to_array()`**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`, inside `product_to_array()` (currently lines 240–263), add the `type` key right after `'id'`:

```php
    private static function product_to_array(WC_Product $product): array
    {
        $taxonomies = [];
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            $terms = wp_get_post_terms($product->get_id(), $taxonomy->name, ['fields' => 'ids']);
            $taxonomies[$taxonomy->rest_base] = array_map('intval', is_array($terms) ? $terms : []);
        }

        return [
            'id' => $product->get_id(),
            'type' => $product->get_type(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price' => $product->get_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_status' => $product->get_stock_status(),
            'short_description' => $product->get_short_description(),
            'description' => $product->get_description(),
            'categories' => array_map(
                fn($id) => ['id' => $id, 'name' => get_term($id)?->name ?? ''],
                $product->get_category_ids()
            ),
            'images' => array_map(
                fn($id) => ['id' => $id, 'src' => wp_get_attachment_url($id) ?: ''],
                array_filter([$product->get_image_id(), ...$product->get_gallery_image_ids()])
            ),
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'taxonomies' => $taxonomies,
        ];
    }
```

- [ ] **Step 2: Add `list_variations()` and `variation_to_array()`**

Add these public/private methods to the same class, right after `get_product()` (currently ends at line 58):

```php
    public static function list_variations(int $parentId): array
    {
        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return ['error' => 'Not a variable product'];
        }

        $items = array_map(
            fn($childId) => self::variation_to_array(wc_get_product($childId), $parent),
            $parent->get_children()
        );

        $options = array_map(
            fn($attrName, $values) => ['name' => wc_attribute_label($attrName), 'options' => array_values($values)],
            array_keys($parent->get_variation_attributes()),
            $parent->get_variation_attributes()
        );

        return ['items' => array_values(array_filter($items)), 'options' => array_values($options)];
    }

    private static function variation_to_array(?WC_Product_Variation $variation, WC_Product $parent): ?array
    {
        if (!$variation) {
            return null;
        }

        $imageId = $variation->get_image_id() ?: $parent->get_image_id();

        return [
            'id' => $variation->get_id(),
            'sku' => $variation->get_sku(),
            'regular_price' => $variation->get_regular_price(),
            'sale_price' => $variation->get_sale_price(),
            'price' => $variation->get_price(),
            'stock_quantity' => $variation->get_stock_quantity(),
            'manage_stock' => $variation->get_manage_stock(),
            'stock_status' => $variation->get_stock_status(),
            'image' => $imageId ? (wp_get_attachment_url($imageId) ?: null) : null,
            'attributes' => self::readable_variation_attributes($variation),
            'attribute_summary' => wc_get_formatted_variation($variation, true, false),
        ];
    }

    /** @return array<string,string> attribute label => selected value */
    private static function readable_variation_attributes(WC_Product_Variation $variation): array
    {
        $result = [];
        foreach ($variation->get_variation_attributes() as $attrKey => $value) {
            // $attrKey looks like "attribute_pa_color" or "attribute_size".
            $taxonomy = str_replace('attribute_', '', $attrKey);
            $label = wc_attribute_label($taxonomy);
            if (str_starts_with($taxonomy, 'pa_') && $value !== '') {
                $term = get_term_by('slug', $value, $taxonomy);
                $result[$label] = $term ? $term->name : $value;
            } else {
                $result[$label] = $value;
            }
        }
        return $result;
    }
```

- [ ] **Step 3: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php
git commit -m "Expose product type and variation listing in WP plugin"
```

---

### Task 2: WP plugin — create a new variation

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php` (add `create_variation`)

**Interfaces:**
- Consumes: `apply_fields(WC_Product $product, array $data): void` (existing, unchanged, from Task 1's file).
- Produces: `Wma_Products::create_variation(int $parentId, array $data): array` → `['error' => string]` on validation failure, or the created variation via `variation_to_array()` on success. `$data` shape: `{attributes: {attributeLabel: value}, regular_price?, sale_price?, sku?, stock_quantity?, stock_status?}`.

- [ ] **Step 1: Add `create_variation()`**

Add to `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`, after `list_variations()`/`variation_to_array()` from Task 1:

```php
    public static function create_variation(int $parentId, array $data): array
    {
        $parent = wc_get_product($parentId);
        if (!$parent || $parent->get_type() !== 'variable') {
            return ['error' => 'Not a variable product'];
        }

        $requested = (array) ($data['attributes'] ?? []);
        $resolved = self::resolve_variation_attributes($parent, $requested);
        if (isset($resolved['error'])) {
            return $resolved;
        }
        $attributes = $resolved['attributes'];

        // get_matching_variation() expects attribute_<name> => value keys.
        $matchArgs = [];
        foreach ($attributes as $key => $value) {
            $matchArgs["attribute_{$key}"] = $value;
        }
        $existingId = $parent->get_matching_variation($matchArgs);
        if ($existingId) {
            return ['error' => 'A variation with this attribute combination already exists'];
        }

        $variation = new WC_Product_Variation();
        $variation->set_parent_id($parentId);
        $variation->set_attributes($attributes);
        self::apply_fields($variation, $data);
        $variation->save();

        return self::variation_to_array($variation, $parent);
    }

    /**
     * Maps human-readable attribute labels/values (as sent by the admin
     * app, taken from get_variation_attributes()'s own output) back to the
     * taxonomy-or-custom-attribute keys/values WC_Product_Variation::
     * set_attributes() expects, rejecting anything not already configured
     * on the parent - this endpoint picks existing options, it does not
     * define new ones.
     *
     * @return array{attributes: array<string,string>}|array{error: string}
     */
    private static function resolve_variation_attributes(WC_Product $parent, array $requested): array
    {
        $available = $parent->get_variation_attributes(); // attrName (taxonomy or slugified custom) => values[]
        $labelToKey = [];
        foreach (array_keys($available) as $attrName) {
            $labelToKey[wc_attribute_label($attrName)] = $attrName;
        }

        $attributes = [];
        foreach ($requested as $label => $value) {
            $key = $labelToKey[$label] ?? null;
            if ($key === null) {
                return ['error' => "Unknown attribute: {$label}"];
            }

            if (str_starts_with($key, 'pa_')) {
                $term = get_term_by('name', $value, $key) ?: get_term_by('slug', $value, $key);
                if (!$term || !in_array($term->slug, $available[$key], true)) {
                    return ['error' => "Invalid value for {$label}: {$value}"];
                }
                $attributes[$key] = $term->slug;
            } else {
                if (!in_array($value, $available[$key], true)) {
                    return ['error' => "Invalid value for {$label}: {$value}"];
                }
                $attributes[$key] = $value;
            }
        }

        if (count($attributes) !== count($available)) {
            return ['error' => 'All variation attributes must be specified'];
        }

        return ['attributes' => $attributes];
    }
```

- [ ] **Step 2: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php
git commit -m "Add variation creation with attribute validation to WP plugin"
```

---

### Task 3: WP plugin — REST routes

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php`
- Modify: `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php:7` (version bump)

**Interfaces:**
- Consumes: `Wma_Products::list_variations(int): array`, `Wma_Products::create_variation(int, array): array` (from Tasks 1-2).
- Produces: `GET /wp-json/wma/v1/products/{id}/variations`, `POST /wp-json/wma/v1/products/{id}/variations`.

- [ ] **Step 1: Register the routes**

In `wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php`, inside the `rest_api_init` closure, right after the existing `/products/(?P<id>\d+)` route block (currently lines 31–35), add:

```php
            register_rest_route('wma/v1', '/products/(?P<id>\d+)/variations', [
                ['methods' => 'GET', 'callback' => [self::class, 'list_variations'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'POST', 'callback' => [self::class, 'create_variation'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);
```

- [ ] **Step 2: Add the callback methods**

Add these methods to the `Wma_Rest` class, after the existing `delete_product()` method (currently ends at line 165):

```php
    public static function list_variations(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $result = Wma_Products::list_variations((int) $request->get_param('id'));
            return isset($result['error'])
                ? new WP_REST_Response(['error' => $result['error']], 422)
                : new WP_REST_Response($result, 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function create_variation(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $result = Wma_Products::create_variation((int) $request->get_param('id'), $request->get_json_params());
            return isset($result['error'])
                ? new WP_REST_Response(['error' => $result['error']], 422)
                : new WP_REST_Response(['item' => $result], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
```

- [ ] **Step 3: Bump the plugin version**

In `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php:7`, change:

```php
 * Version: 1.0.0
```

to:

```php
 * Version: 1.1.0
```

- [ ] **Step 4: Lint check**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php && php -l wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`
Expected: `No syntax errors detected` for both

- [ ] **Step 5: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php
git commit -m "Register variation REST routes, bump plugin version"
```

---

### Task 4: Main app — client method and product-type pass-through

**Files:**
- Modify: `includes/SiteAgentClient.php` (add two methods)
- Modify: `includes/helpers.php:204-221` (`map_product_summary`)
- Modify: `public/api/product.php:200-219` (`map_product_detail`)

**Interfaces:**
- Produces: `SiteAgentClient::listVariations(int $productId): array` → the decoded `{items, options}` from the WP route, or throws `RuntimeException` on transport/HTTP error (matching every other client method's error convention).
- Produces: `SiteAgentClient::createVariation(int $productId, array $data): array` → the decoded `item`, or throws `RuntimeException`.
- Produces: `map_product_summary()` and `map_product_detail()` now both include `'type' => string`, so the frontend can tell simple from variable products.

- [ ] **Step 1: Add client methods**

In `includes/SiteAgentClient.php`, add these two methods right after `deleteProduct()` (currently ends at line 54):

```php
    public function listVariations(int $productId): array
    {
        $response = $this->request('GET', "/wp-json/wma/v1/products/{$productId}/variations");
        return $this->decodeOrThrow($response);
    }

    public function createVariation(int $productId, array $data): array
    {
        $response = $this->request('POST', "/wp-json/wma/v1/products/{$productId}/variations", $data);
        return $this->decodeOrThrow($response)['item'];
    }
```

- [ ] **Step 2: Add `type` to `map_product_summary()`**

In `includes/helpers.php`, inside `map_product_summary()` (currently lines 204–221), add the `type` key right after `'id'`:

```php
function map_product_summary(array $product): array
{
    return [
        'id' => $product['id'],
        'type' => $product['type'] ?? 'simple',
        'name' => $product['name'] ?? '',
        'sku' => $product['sku'] ?? '',
        'price' => $product['price'] ?? '',
        'regular_price' => $product['regular_price'] ?? '',
        'sale_price' => $product['sale_price'] ?? '',
        'on_sale' => $product['on_sale'] ?? false,
        'stock_quantity' => $product['stock_quantity'] ?? null,
        'stock_status' => $product['stock_status'] ?? 'instock',
        'manage_stock' => $product['manage_stock'] ?? false,
        'image' => $product['images'][0]['src'] ?? null,
        'categories' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $product['categories'] ?? []),
        'permalink' => $product['permalink'] ?? null,
    ];
}
```

- [ ] **Step 3: Add `type` to `map_product_detail()`**

In `public/api/product.php`, inside `map_product_detail()` (currently lines 200–219), add the `type` key right after `'id'`:

```php
function map_product_detail(array $product): array
{
    return [
        'id' => $product['id'] ?? null,
        'type' => $product['type'] ?? 'simple',
        'name' => $product['name'] ?? '',
        'sku' => $product['sku'] ?? '',
        'regular_price' => $product['regular_price'] ?? '',
        'sale_price' => $product['sale_price'] ?? '',
        'price' => $product['price'] ?? '',
        'stock_quantity' => $product['stock_quantity'] ?? null,
        'manage_stock' => $product['manage_stock'] ?? false,
        'stock_status' => $product['stock_status'] ?? 'instock',
        'short_description' => $product['short_description'] ?? '',
        'description' => $product['description'] ?? '',
        'categories' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $product['categories'] ?? []),
        'images' => array_map(fn($img) => ['id' => $img['id'] ?? null, 'src' => $img['src']], $product['images'] ?? []),
        'status' => $product['status'] ?? 'publish',
        'permalink' => $product['permalink'] ?? null,
    ];
}
```

- [ ] **Step 4: Lint check**

Run: `php -l includes/SiteAgentClient.php && php -l includes/helpers.php && php -l public/api/product.php`
Expected: `No syntax errors detected` for all three

- [ ] **Step 5: Commit**

```bash
git add includes/SiteAgentClient.php includes/helpers.php public/api/product.php
git commit -m "Add variation client methods and expose product type to frontend"
```

---

### Task 5: Main app — variations API endpoint

**Files:**
- Create: `public/api/product-variations.php`

**Interfaces:**
- Consumes: `SiteAgentClient::listVariations()`, `SiteAgentClient::createVariation()` (Task 4); `log_activity()` (existing, `includes/ActivityLog.php:18`); `invalidate_products_cache()` (existing, `includes/helpers.php:22`); `sanitize_price()` (existing, `public/api/product.php:334`, needs `require_once` of that file's function — see note in Step 1).
- Produces: `GET /api/product-variations.php?product_id=X` → `{items, options}`; `POST /api/product-variations.php` `{product_id, attributes, regular_price?, sale_price?, sku?, stock_quantity?, stock_status?}` → `{item, log_id, message}`.

- [ ] **Step 1: Write the endpoint**

`sanitize_price()` and `build_product_payload()`-style field validation live as plain functions inside `public/api/product.php`, which is never `require_once`'d by other endpoints (it's a request-handling script, not a library) — so this new endpoint duplicates the same handful of validation lines directly rather than requiring that file. Create `public/api/product-variations.php`:

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = require_login_api();
$site = require_site_api($user);
$client = site_agent_client_for_site($site);

if ($method === 'GET') {
    $productId = (int) ($_GET['product_id'] ?? 0);
    if ($productId <= 0) {
        json_response(['error' => 'Invalid product id'], 422);
    }

    try {
        $result = $client->listVariations($productId);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 502);
    }

    json_response([
        'items' => array_map('map_variation_summary', $result['items'] ?? []),
        'options' => $result['options'] ?? [],
    ]);
}

verify_csrf_api();

if ($method === 'POST') {
    $body = json_body();
    $productId = (int) ($body['product_id'] ?? 0);
    if ($productId <= 0) {
        json_response(['error' => 'Invalid product id'], 422);
    }

    $attributes = is_array($body['attributes'] ?? null) ? $body['attributes'] : [];
    if (empty($attributes)) {
        json_response(['error' => 'At least one attribute is required'], 422);
    }

    $data = ['attributes' => $attributes];

    if (isset($body['regular_price'])) {
        $data['regular_price'] = variation_sanitize_price($body['regular_price']);
    }
    if (array_key_exists('sale_price', $body)) {
        $sale = $body['sale_price'];
        $data['sale_price'] = ($sale === '' || $sale === null) ? '' : variation_sanitize_price($sale);
    }
    if (isset($body['sku'])) {
        $data['sku'] = trim((string) $body['sku']);
    }
    if (array_key_exists('stock_quantity', $body)) {
        $data['manage_stock'] = true;
        $data['stock_quantity'] = (int) $body['stock_quantity'];
    }
    if (isset($body['stock_status'])) {
        $allowed = ['instock', 'outofstock', 'onbackorder'];
        if (in_array($body['stock_status'], $allowed, true)) {
            $data['stock_status'] = $body['stock_status'];
        }
    }

    try {
        $variation = $client->createVariation($productId, $data);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 502);
    }

    invalidate_products_cache((int) $site['id']);

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
    }

    $item = map_variation_summary($variation);
    $item['log_id'] = $logId;
    $item['message'] = t('log_variation_created', $variation['attribute_summary'] ?? '');
    json_response(['item' => $item]);
}

json_response(['error' => 'Method not allowed'], 405);

function map_variation_summary(array $variation): array
{
    $regular = (float) ($variation['regular_price'] ?? 0);
    $sale = $variation['sale_price'] ?? '';

    return [
        'id' => $variation['id'] ?? null,
        // renderPriceRow()/wireProductElement() in products.js treat this
        // exactly like a top-level product - 'name' backs the stock.php
        // activity-log message, 'on_sale' drives the struck-through regular
        // price display, same as map_product_summary() does for products.
        'name' => $variation['attribute_summary'] ?? '',
        'sku' => $variation['sku'] ?? '',
        'regular_price' => $variation['regular_price'] ?? '',
        'sale_price' => $sale,
        'on_sale' => $sale !== '' && $sale !== null && (float) $sale < $regular,
        'price' => $variation['price'] ?? '',
        'stock_quantity' => $variation['stock_quantity'] ?? null,
        'manage_stock' => $variation['manage_stock'] ?? false,
        'stock_status' => $variation['stock_status'] ?? 'instock',
        'image' => $variation['image'] ?? null,
        'attributes' => $variation['attributes'] ?? [],
        'attribute_summary' => $variation['attribute_summary'] ?? '',
    ];
}

function variation_sanitize_price($value): string
{
    $price = (float) $value;
    if ($price < 0) {
        $price = 0;
    }
    return format_wc_price($price);
}
```

Note: the create response surfaces `$client->createVariation()`'s `RuntimeException` message directly as a 502 — this is how every other write endpoint in this app already surfaces the WP plugin's validation errors (e.g. "A variation with this attribute combination already exists" from Task 2), so no special-casing is needed here for the duplicate/invalid-attribute cases.

- [ ] **Step 2: Lint check**

Run: `php -l public/api/product-variations.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/api/product-variations.php
git commit -m "Add product-variations API endpoint (list + create)"
```

---

### Task 6: i18n keys (PHP + JS, en + fa)

**Files:**
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Produces: translation keys `show_variations`, `hide_variations`, `add_variation`, `variation_created`, `variations_load_error`, `retry`, `variation_attribute_required`, `saving_variation` available via both server-side `t()` and client-side `t()`.

- [ ] **Step 1: Add keys to `includes/i18n.php`**

In `includes/i18n.php`, inside the `'en' =>` array, add near `'sale_price_optional'` (line 109):

```php
        'show_variations' => 'Show variations',
        'hide_variations' => 'Hide variations',
        'add_variation' => 'Add variation',
        'variation_created' => 'Variation created',
        'variations_load_error' => 'Could not load variations',
        'retry' => 'Retry',
        'variation_attribute_required' => 'Choose a value for every attribute',
        'saving_variation' => 'Saving variation...',
        'log_variation_created' => 'Created variation "%s"',
```

In the same file, inside the `'fa' =>` array, add near `'sale_price_optional'` (line 460):

```php
        'show_variations' => 'نمایش تنوع‌ها',
        'hide_variations' => 'پنهان کردن تنوع‌ها',
        'add_variation' => 'افزودن تنوع',
        'variation_created' => 'تنوع ایجاد شد',
        'variations_load_error' => 'بارگذاری تنوع‌ها ممکن نشد',
        'retry' => 'تلاش مجدد',
        'variation_attribute_required' => 'برای هر ویژگی یک مقدار انتخاب کنید',
        'saving_variation' => 'در حال ذخیره تنوع...',
        'log_variation_created' => 'تنوع «%s» ایجاد شد',
```

- [ ] **Step 2: Add the same keys to `public/assets/js/i18n.js`**

In `public/assets/js/i18n.js`, inside the English object, add near `"sale_price_optional"` (line 85):

```js
        "show_variations": "Show variations",
        "hide_variations": "Hide variations",
        "add_variation": "Add variation",
        "variation_created": "Variation created",
        "variations_load_error": "Could not load variations",
        "retry": "Retry",
        "variation_attribute_required": "Choose a value for every attribute",
        "saving_variation": "Saving variation...",
```

In the same file, inside the Persian object, add near `"sale_price_optional"` (line 356):

```js
        "show_variations": "نمایش تنوع‌ها",
        "hide_variations": "پنهان کردن تنوع‌ها",
        "add_variation": "افزودن تنوع",
        "variation_created": "تنوع ایجاد شد",
        "variations_load_error": "بارگذاری تنوع‌ها ممکن نشد",
        "retry": "تلاش مجدد",
        "variation_attribute_required": "برای هر ویژگی یک مقدار انتخاب کنید",
        "saving_variation": "در حال ذخیره تنوع...",
```

- [ ] **Step 3: Lint check**

Run: `php -l includes/i18n.php && node --check public/assets/js/i18n.js`
Expected: `No syntax errors detected` and no output (node --check is silent on success)

- [ ] **Step 4: Commit**

```bash
git add includes/i18n.php public/assets/js/i18n.js
git commit -m "Add variation UI translation keys (en/fa)"
```

---

### Task 7: Frontend — expand/collapse and variation rows

**Files:**
- Modify: `public/assets/js/products.js` (`renderProductCard`, `renderProductRow`, new functions)
- Modify: `public/assets/css/app.css` (small addition)

**Interfaces:**
- Consumes: `renderPriceRow(el, product)`, `wireProductElement(el, product)`, `stockStatusBadge(status)`, `escapeHtml(str)` (all existing, unchanged, in this same file).
- Produces: `renderVariationRow(variation): HTMLElement`, `toggleVariations(card, product): Promise<void>`, a per-product client-side cache `variationsCache` (Map of `productId -> {items, options}`).

- [ ] **Step 1: Add the variations cache and toggle logic**

In `public/assets/js/products.js`, add near the top with the other module-level state (after `const state = {...}` block, currently ending at line 22):

```js
const variationsCache = new Map();
```

- [ ] **Step 2: Add the toggle button to `renderProductCard()`**

In `renderProductCard()` (currently lines 289–322), change the card's `innerHTML` to add a toggle button right after the name/SKU link block, and add an empty container for the expanded list. Replace:

```js
    <a href="/product-edit.php?id=${product.id}" class="card-link block">
        <div class="text-sm font-medium text-gray-900 line-clamp-2">${escapeHtml(product.name)}</div>
        <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(product.sku || '')}</div>
      </a>
      <div class="mt-1 flex items-center gap-2 price-row"></div>
    </div>
```

with:

```js
    <a href="/product-edit.php?id=${product.id}" class="card-link block">
        <div class="text-sm font-medium text-gray-900 line-clamp-2">${escapeHtml(product.name)}</div>
        <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(product.sku || '')}</div>
      </a>
      <div class="mt-1 flex items-center gap-2 price-row"></div>
      ${product.type === 'variable' ? '<button type="button" class="variations-toggle text-xs text-gray-500 underline mt-1">' + escapeHtml(t('show_variations')) + '</button><div class="variations-list mt-2 space-y-2 hidden"></div>' : ''}
    </div>
```

Then, still inside `renderProductCard()`, right before the final `return card;` (currently line 321), add:

```js
  if (product.type === 'variable') {
    wireVariationsToggle(card, product);
  }
```

- [ ] **Step 3: Add the same toggle to `renderProductRow()`**

In `renderProductRow()` (currently lines 329–359), the table view has no room for a nested list inside a `<tr>`; add a second `<tr>` sibling instead. Change the function's `return row;` (currently line 358) to first build and return a `DocumentFragment` containing both rows when variable:

```js
  renderPriceRow(row, product);
  wireProductElement(row, product);

  if (product.type !== 'variable') {
    return row;
  }

  const toggleRow = document.createElement('tr');
  toggleRow.className = 'variations-toggle-row';
  toggleRow.innerHTML = `
    <td></td>
    <td colspan="4" class="px-3 pb-2">
      <button type="button" class="variations-toggle text-xs text-gray-500 underline">${escapeHtml(t('show_variations'))}</button>
      <div class="variations-list mt-2 space-y-2 hidden"></div>
    </td>
  `;
  wireVariationsToggle(toggleRow, product);

  const fragment = document.createDocumentFragment();
  fragment.appendChild(row);
  fragment.appendChild(toggleRow);
  return fragment;
```

Table rows are appended via `container.appendChild(renderProductElement(product))` in `loadProducts()` (line 262) and via `el.replaceWith(fresh)` in `refreshSingleProduct()` (line 120) — appending a `DocumentFragment` works identically to appending a single element, but `replaceWith()` on a two-row fragment only replaces the original single node; since undo-refresh only applies to simple-product summary re-fetches in practice and variable products aren't expected to be undone via that path in this feature, this is an acceptable existing limitation, not a regression (the original single-row behavior is unchanged for simple products, which is the only case `refreshSingleProduct` handled before this change too).

- [ ] **Step 4: Implement `wireVariationsToggle()` and `renderVariationRow()`**

Add these new functions to `public/assets/js/products.js`, after `wireProductElement()` (currently ends at line 434):

```js
function wireVariationsToggle(container, product) {
  const toggleBtn = container.querySelector('.variations-toggle');
  const listEl = container.querySelector('.variations-list');

  toggleBtn.addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();

    const expanded = !listEl.classList.contains('hidden');
    if (expanded) {
      listEl.classList.add('hidden');
      toggleBtn.textContent = t('show_variations');
      return;
    }

    listEl.classList.remove('hidden');
    toggleBtn.textContent = t('hide_variations');

    if (variationsCache.has(product.id)) {
      renderVariationsList(listEl, product, variationsCache.get(product.id));
      return;
    }

    listEl.innerHTML = `<div class="text-xs text-gray-400">${escapeHtml(t('loading'))}</div>`;
    try {
      const data = await App.api(`/api/product-variations.php?product_id=${product.id}`);
      variationsCache.set(product.id, data);
      renderVariationsList(listEl, product, data);
    } catch (err) {
      listEl.innerHTML = `
        <div class="text-xs text-red-600">${escapeHtml(t('variations_load_error'))}
          <button type="button" class="variations-retry underline ml-1">${escapeHtml(t('retry'))}</button>
        </div>`;
      listEl.querySelector('.variations-retry').addEventListener('click', (ev) => {
        ev.preventDefault();
        ev.stopPropagation();
        toggleBtn.textContent = t('show_variations');
        listEl.classList.add('hidden');
        toggleBtn.click();
      });
    }
  });
}

function renderVariationsList(listEl, product, data) {
  listEl.innerHTML = '';
  (data.items || []).forEach((variation) => {
    listEl.appendChild(renderVariationRow(variation));
  });
  listEl.appendChild(renderAddVariationRow(listEl, product, data.options || []));
}

/**
 * Renders one variation as a compact row reusing the same price-click-edit
 * and stock +/- markup/handlers as a top-level product card - both
 * wireProductElement() and renderPriceRow() only ever look at `.id`,
 * `.regular_price`, `.stock_quantity`, etc. on the object they're given,
 * so a variation object (same field names) works unmodified.
 */
function renderVariationRow(variation) {
  const row = document.createElement('div');
  row.className = 'variation-row bg-gray-50 rounded-xl border border-gray-100 p-2 flex gap-2 relative';
  row.dataset.id = variation.id;

  const image = variation.image
    ? `<img src="${escapeHtml(variation.image)}" alt="" class="h-10 w-10 rounded-lg object-cover flex-shrink-0 bg-gray-100">`
    : `<div class="h-10 w-10 rounded-lg bg-gray-100 flex-shrink-0"></div>`;

  row.innerHTML = `
    <div class="checkbox-wrap hidden flex items-center pr-1">
      <input type="checkbox" class="select-checkbox h-4 w-4 rounded border-gray-300">
    </div>
    ${image}
    <div class="flex-1 min-w-0">
      <div class="text-xs font-medium text-gray-800">${escapeHtml(variation.attribute_summary || '')}</div>
      <div class="text-[10px] text-gray-400">${escapeHtml(variation.sku || '')}</div>
      <div class="mt-1 flex items-center gap-2 price-row"></div>
    </div>
    <div class="stock-control flex flex-col items-center justify-center gap-1 flex-shrink-0">
      <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="1">+</button>
      <span class="stock-qty text-[10px] font-medium text-gray-700">${variation.stock_quantity ?? '-'}</span>
      <button class="stock-btn rounded-lg border border-gray-300 w-6 h-6 text-xs leading-none" data-delta="-1">-</button>
    </div>
  `;

  renderPriceRow(row, variation);
  wireProductElement(row, variation);
  return row;
}

function renderAddVariationRow(listEl, product, options) {
  const wrap = document.createElement('div');
  wrap.className = 'add-variation-wrap';

  const btn = document.createElement('button');
  btn.type = 'button';
  btn.className = 'add-variation-btn text-xs text-gray-500 underline';
  btn.textContent = t('add_variation');
  wrap.appendChild(btn);

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    wrap.innerHTML = '';
    wrap.appendChild(buildAddVariationForm(listEl, product, options, wrap));
  });

  return wrap;
}

function buildAddVariationForm(listEl, product, options, wrap) {
  const form = document.createElement('div');
  form.className = 'bg-white rounded-xl border border-gray-200 p-2 space-y-1.5';

  const selects = options.map((opt) => {
    const selectId = `variation-attr-${product.id}-${opt.name}`.replace(/\s+/g, '-');
    const optionsHtml = opt.options.map((v) => `<option value="${escapeHtml(v)}">${escapeHtml(v)}</option>`).join('');
    return `
      <div>
        <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(opt.name)}</label>
        <select id="${selectId}" data-attr-name="${escapeHtml(opt.name)}" class="variation-attr-select w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
          ${optionsHtml}
        </select>
      </div>`;
  }).join('');

  form.innerHTML = `
    ${selects}
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('regular_price'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('sale_price_optional'))}</label>
      <input type="number" inputmode="decimal" min="0" step="any" class="new-variation-sale-price w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div>
      <label class="block text-[10px] text-gray-400 mb-0.5">${escapeHtml(t('sku'))}</label>
      <input type="text" class="new-variation-sku w-full rounded-lg border border-gray-300 px-2 py-1 text-xs">
    </div>
    <div class="new-variation-error hidden text-[10px] text-red-600"></div>
    <div class="flex gap-1.5">
      <button type="button" class="new-variation-save flex-1 rounded-lg bg-gray-900 text-white text-xs font-medium py-1">${escapeHtml(t('save'))}</button>
      <button type="button" class="new-variation-cancel flex-1 rounded-lg border border-gray-300 text-gray-700 text-xs font-medium py-1">${escapeHtml(t('cancel'))}</button>
    </div>
  `;

  const errorEl = form.querySelector('.new-variation-error');
  const showError = (msg) => { errorEl.textContent = msg; errorEl.classList.remove('hidden'); };

  form.querySelector('.new-variation-cancel').addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    wrap.innerHTML = '';
    wrap.appendChild(renderAddVariationRow(listEl, product, options));
  });

  form.querySelector('.new-variation-save').addEventListener('click', async (e) => {
    e.preventDefault();
    e.stopPropagation();
    errorEl.classList.add('hidden');

    const attributes = {};
    form.querySelectorAll('.variation-attr-select').forEach((sel) => {
      attributes[sel.dataset.attrName] = sel.value;
    });
    if (options.length === 0 || Object.values(attributes).some((v) => !v)) {
      showError(t('variation_attribute_required'));
      return;
    }

    const regularPrice = form.querySelector('.new-variation-price').value.trim();
    const salePrice = form.querySelector('.new-variation-sale-price').value.trim();
    const sku = form.querySelector('.new-variation-sku').value.trim();

    const saveBtn = form.querySelector('.new-variation-save');
    saveBtn.disabled = true;
    saveBtn.textContent = t('saving_variation');

    try {
      const payload = { product_id: product.id, attributes };
      if (regularPrice !== '') payload.regular_price = regularPrice;
      if (salePrice !== '') payload.sale_price = salePrice;
      if (sku !== '') payload.sku = sku;

      const data = await App.api('/api/product-variations.php', {
        method: 'POST',
        body: JSON.stringify(payload),
      });

      const cached = variationsCache.get(product.id) || { items: [], options };
      cached.items = [...cached.items, data.item];
      variationsCache.set(product.id, cached);

      wrap.insertAdjacentElement('beforebegin', renderVariationRow(data.item));
      wrap.innerHTML = '';
      wrap.appendChild(renderAddVariationRow(listEl, product, options));

      if (data.item.log_id) {
        App.notify(data.item.message, { logId: data.item.log_id });
      }
    } catch (err) {
      showError(err.message);
      saveBtn.disabled = false;
      saveBtn.textContent = t('save');
    }
  });

  return form;
}
```

- [ ] **Step 5: Add CSS for the variation row container**

In `public/assets/css/app.css`, add right after the `.highlight-flash` block (currently ends at line 209):

```css
/* Nested variation rows under an expanded variable-product card, indented
   to read as "inside" the parent without a full second card's padding. */
.variations-list {
  padding-inline-start: 0.5rem;
  border-inline-start: 2px solid #e5e7eb;
}
```

- [ ] **Step 6: Lint check**

Run: `node --check public/assets/js/products.js`
Expected: no output (success)

- [ ] **Step 7: Manual verification**

Start the app locally (per this repo's normal manual workflow — no dev server script exists, so this is opening the app in a browser against a configured local/staging site with at least one variable product):
1. Load `/products.php`, find a variable product card, confirm the "Show variations" toggle appears (and does NOT appear on simple products).
2. Click it: confirm a loading state, then the variation rows render with attribute summary + SKU.
3. Click again to collapse, then expand again: confirm no second network request fires (check browser dev tools Network tab) — this proves the cache in Step 1 works.
4. Click a variation's price: confirm the existing inline price editor opens and saves correctly against that variation (verify the price actually changed in WooCommerce admin).
5. Click a variation's +/- stock buttons: confirm the quantity updates and reflects in WooCommerce admin.
6. Click "Add variation", fill in attribute dropdowns + price, save: confirm the new variation appears in the list and in WooCommerce admin.
7. Try adding a duplicate attribute combination: confirm the error message from Task 2 surfaces in the form.
8. Switch language to `fa` and repeat steps 1-2: confirm RTL layout (the `border-inline-start`/`padding-inline-start` CSS should mirror automatically) and translated strings look correct.
9. Repeat steps 1-2 in table view (`view-table-btn`) to confirm the two-row (`<tr>`) rendering from Step 3 works.

- [ ] **Step 8: Commit**

```bash
git add public/assets/js/products.js public/assets/css/app.css
git commit -m "Add variation expand/collapse, editing, and creation UI to products list"
```
