<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);
$client = woocommerce_client_for_site($site);

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
    $wp = wordpress_client_for_site($site);
    if ($wp === null) {
        json_response(['items' => [], 'page' => $params['page'], 'per_page' => $params['per_page'], 'total' => 0, 'total_pages' => 1]);
    }

    $matchingIds = null;
    foreach ($taxFilters as $restBase => $termId) {
        $ids = $wp->listProductIdsByTerm($restBase, $termId);
        $matchingIds = $matchingIds === null ? $ids : array_intersect($matchingIds, $ids);
    }

    if (empty($matchingIds)) {
        json_response(['items' => [], 'page' => $params['page'], 'per_page' => $params['per_page'], 'total' => 0, 'total_pages' => 1]);
    }

    $params['include'] = implode(',', $matchingIds);
}

$result = $client->listProducts($params);

if ($result['status'] < 200 || $result['status'] >= 300) {
    json_response(['error' => $result['data']['message'] ?? 'Failed to fetch products'], $result['status'] ?: 502);
}

$products = is_array($result['data']) ? $result['data'] : [];

// Optional client-side price range filter (applied to the current page only).
$minPrice = isset($_GET['min_price']) && $_GET['min_price'] !== '' ? (float) $_GET['min_price'] : null;
$maxPrice = isset($_GET['max_price']) && $_GET['max_price'] !== '' ? (float) $_GET['max_price'] : null;

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

json_response([
    'items' => $items,
    'page' => $params['page'],
    'per_page' => $params['per_page'],
    'total' => (int) ($result['headers']['x-wp-total'] ?? count($items)),
    'total_pages' => (int) ($result['headers']['x-wp-totalpages'] ?? 1),
]);
