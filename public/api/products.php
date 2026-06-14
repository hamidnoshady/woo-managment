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

function map_product_summary(array $product): array
{
    return [
        'id' => $product['id'],
        'name' => $product['name'],
        'sku' => $product['sku'],
        'price' => $product['price'],
        'regular_price' => $product['regular_price'],
        'sale_price' => $product['sale_price'],
        'on_sale' => $product['on_sale'],
        'stock_quantity' => $product['stock_quantity'],
        'stock_status' => $product['stock_status'],
        'manage_stock' => $product['manage_stock'],
        'image' => $product['images'][0]['src'] ?? null,
        'categories' => array_map(fn($c) => ['id' => $c['id'], 'name' => $c['name']], $product['categories'] ?? []),
        'permalink' => $product['permalink'] ?? null,
    ];
}
