<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);
$client = site_agent_client_for_site($site);

$params = [
    'per_page' => max(1, min(100, (int) ($_GET['per_page'] ?? 20))),
    'page'     => max(1, (int) ($_GET['page'] ?? 1)),
    'orderby'  => $_GET['orderby'] ?? 'date',
    'order'    => $_GET['order'] ?? 'desc',
];

if (!empty($_GET['search'])) {
    $params['search'] = trim((string) $_GET['search']);
}

if (!empty($_GET['category'])) {
    $params['category'] = (string) $_GET['category'];
}

if (!empty($_GET['stock_status'])) {
    $params['stock_status'] = (string) $_GET['stock_status'];
}

if (!empty($_GET['on_sale']) && $_GET['on_sale'] === '1') {
    $params['on_sale'] = 'true';
}

// Custom (e.g. ACF) taxonomy filters, passed as tax_<rest_base>=<term_id>.
$taxFilters = [];
foreach ($_GET as $key => $value) {
    if (str_starts_with($key, 'tax_') && $value !== '') {
        $taxFilters[substr($key, 4)] = (int) $value;
    }
}

if (!empty($taxFilters)) {
    $params['taxonomy_terms'] = $taxFilters;
}

// Optional client-side price range filter (applied to the current page only).
$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float) $_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float) $_GET['max_price'] : null;

// Cache the rendered list per site+filter combination for a few seconds.
// This is intentionally short: long enough to make repeat loads (paging
// back and forth, the page re-rendering, a duplicate request) feel instant,
// but short enough that stock/price edits and undos are never hidden
// behind a stale list for more than a moment.
ksort($params);
$cacheKey = 'products:' . $site['id'] . ':' . md5(json_encode($params) . '|' . $minPrice . '|' . $maxPrice);
$cached = cache_get($cacheKey);
if ($cached !== null) {
    json_response($cached);
}

try {
    $result = $client->listProducts($params);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 502);
}

$products = is_array($result['items'] ?? null) ? $result['items'] : [];

if ($minPrice !== null || $maxPrice !== null) {
    $products = array_values(array_filter($products, function ($product) use ($minPrice, $maxPrice) {
        $price = (float) ($product['price'] ?? 0);
        if ($minPrice !== null && $price < $minPrice) {
            return false;
        }
        if ($maxPrice !== null && $price > $maxPrice) {
            return false;
        }
        return true;
    }));
}

$items = array_map('map_product_summary', $products);

$response = [
    'items' => $items,
    'page' => $params['page'],
    'per_page' => $params['per_page'],
    'total' => (int) ($result['total'] ?? count($items)),
    'total_pages' => (int) ($result['total_pages'] ?? 1),
];

cache_set($cacheKey, $response, PRODUCTS_CACHE_TTL_SECONDS);

json_response($response);
