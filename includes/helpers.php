<?php

require_once __DIR__ . '/Database.php';

// How long a cached products-list response stays fresh. Short on purpose:
// this app shows live stock/price data, so a stale list for too long would
// mislead whoever is acting on it.
const PRODUCTS_CACHE_TTL_SECONDS = 10;

// Custom taxonomies are cached the same short duration as products and
// invalidated by the same product-mutation hook (see
// invalidate_products_cache()) - a long-lived cache here previously let a
// single failed fetch hide a taxonomy from the UI for minutes.
const TAXONOMIES_CACHE_TTL_SECONDS = 10;

/**
 * Builds a /assets/... URL with a `?v=<mtime>` cache-busting query string,
 * so a deploy that changes a JS/CSS file's content changes its URL too -
 * browsers and the service worker (public/sw.js caches by request URL)
 * fetch the new version immediately instead of serving a stale copy until
 * the 24h Cache-Control max-age expires or the user hard-refreshes.
 */
function asset_url(string $path): string
{
    $file = __DIR__ . '/../public' . $path;
    $mtime = @filemtime($file);
    return $path . ($mtime ? '?v=' . $mtime : '');
}

/**
 * Current web app version, manually bumped in includes/VERSION on
 * meaningful releases (there's no build step to derive this from) - display
 * only, this app has no self-update mechanism (deploy is out-of-band).
 */
function app_version(): string
{
    return trim((string) @file_get_contents(__DIR__ . '/VERSION')) ?: '0.0.0';
}

/**
 * Invalidates every cached products-list variant for a site. Call this
 * after any request that creates, updates, or deletes a product (or its
 * stock) on that site, so the next list fetch reflects the change
 * immediately instead of waiting out the cache TTL.
 */
function invalidate_products_cache(int $siteId): void
{
    cache_delete_prefix('products:' . $siteId . ':');
    cache_delete_prefix('taxonomies:' . $siteId . ':');
}

/**
 * Installs a shutdown handler that turns an uncaught fatal error into a
 * JSON error response instead of leaking raw HTML. Call this at the top of
 * API endpoint files only (not HTML page files), right after requiring
 * this file - otherwise a real page fatal would render as a JSON blob.
 */
function install_json_fatal_handler(): void
{
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error === null || headers_sent()) {
            return;
        }
        $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }
        http_response_code(500);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Internal server error.'], JSON_UNESCAPED_UNICODE);
    });
}

/**
 * Sends a JSON response and stops execution.
 */
function json_response($data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Reads a JSON value previously stored with cache_set(), or null if missing
 * or expired. Backed by the `kv_cache` SQLite table — used to avoid
 * round-tripping to the WooCommerce/WordPress REST APIs for data (like
 * category lists) that rarely changes between requests.
 */
function cache_get(string $key)
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT value FROM kv_cache WHERE `key` = ? AND expires_at > ?');
    $stmt->execute([$key, time()]);
    $row = $stmt->fetch();
    if ($row === false) {
        return null;
    }
    return json_decode($row['value'], true);
}

/**
 * Stores a JSON-encodable value under $key for $ttlSeconds.
 */
function cache_set(string $key, $value, int $ttlSeconds): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO kv_cache (`key`, value, expires_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)'
    );
    $stmt->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE), time() + $ttlSeconds]);
}

/**
 * Deletes all cache entries whose key starts with $prefix. Used to
 * invalidate every cached products-list variant (different filters/pages
 * produce different keys) for a site as soon as that site's products are
 * mutated, so edits are never hidden behind a stale cached list.
 */
function cache_delete_prefix(string $prefix): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare("DELETE FROM kv_cache WHERE `key` LIKE ? ESCAPE '\\\\'");
    $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $prefix);
    $stmt->execute([$escaped . '%']);
}

/**
 * Reads and decodes the JSON request body.
 */
function json_body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/**
 * Normalizes an Iranian mobile number to the 09xxxxxxxxx format (digits only).
 */
function normalize_phone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);

    if (str_starts_with($digits, '0098')) {
        $digits = '0' . substr($digits, 4);
    } elseif (str_starts_with($digits, '98')) {
        $digits = '0' . substr($digits, 2);
    } elseif (str_starts_with($digits, '9') && strlen($digits) === 10) {
        $digits = '0' . $digits;
    }

    return $digits;
}

/**
 * Applies a percentage increase/decrease and a rounding mode to a price.
 *
 * @param float  $price    Original price (numeric, never negative).
 * @param float  $percent  Percentage to apply (e.g. 10 for +10%, -10 for -10%).
 * @param string $mode     One of: 'none', 'nearest', 'up', 'down', 'step', 'ending'.
 * @param float  $stepOrEnding For 'step': the rounding step (e.g. 1000, 0.5).
 *                              For 'ending': the desired decimal ending (e.g. 0.99, 0.95, 0).
 */
function adjust_price(float $price, float $percent, string $mode = 'none', float $stepOrEnding = 0): float
{
    $newPrice = $price * (1 + ($percent / 100));

    if ($newPrice < 0) {
        $newPrice = 0;
    }

    switch ($mode) {
        case 'nearest':
            $newPrice = round($newPrice);
            break;

        case 'up':
            $newPrice = ceil($newPrice);
            break;

        case 'down':
            $newPrice = floor($newPrice);
            break;

        case 'step':
            $step = $stepOrEnding > 0 ? $stepOrEnding : 1;
            $newPrice = round($newPrice / $step) * $step;
            break;

        case 'ending':
            // Round up to the nearest value ending in the given decimal (e.g. .99, .95).
            $ending = max(0, min(0.99, $stepOrEnding));
            $candidate = floor($newPrice) + $ending;
            if ($candidate < $newPrice) {
                $candidate += 1;
            }
            $newPrice = $candidate;
            break;

        case 'none':
        default:
            $newPrice = round($newPrice, 2);
            break;
    }

    return max(0, round($newPrice, 2));
}

/**
 * Formats a numeric price for WooCommerce (string, up to 2 decimals, no trailing zeros issues).
 */
function format_wc_price(float $price): string
{
    return number_format($price, 2, '.', '');
}

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

/**
 * Maps a raw WooCommerce product to the compact shape used by product list
 * cards (and by single-product refreshes after an undo).
 */
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
