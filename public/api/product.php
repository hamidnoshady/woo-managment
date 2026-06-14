<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$method = $_SERVER['REQUEST_METHOD'];
$user = require_login_api();
$site = require_site_api($user);
$client = woocommerce_client_for_site($site);

if ($method === 'GET') {
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Invalid product id'], 422);
    }

    $result = $client->getProduct($id);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        json_response(['error' => $result['data']['message'] ?? 'Product not found'], $result['status'] ?: 404);
    }

    json_response(['item' => map_product_detail($result['data'])]);
}

// All write operations require CSRF.
verify_csrf_api();

$body = json_body();

if ($method === 'POST' || $method === 'PUT') {
    $id = (int) ($body['id'] ?? 0);
    $data = build_product_payload($body);

    if ($id > 0) {
        $result = $client->updateProduct($id, $data);
    } else {
        $result = $client->createProduct($data);
    }

    if ($result['status'] < 200 || $result['status'] >= 300) {
        json_response(['error' => $result['data']['message'] ?? 'Failed to save product'], $result['status'] ?: 502);
    }

    json_response(['item' => map_product_detail($result['data'])]);
}

if ($method === 'DELETE') {
    require_role_api(['admin', 'superadmin']);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Invalid product id'], 422);
    }

    $result = $client->deleteProduct($id, true);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        json_response(['error' => $result['data']['message'] ?? 'Failed to delete product'], $result['status'] ?: 502);
    }

    json_response(['ok' => true]);
}

json_response(['error' => 'Method not allowed'], 405);

function map_product_detail(array $product): array
{
    return [
        'id' => $product['id'] ?? null,
        'name' => $product['name'] ?? '',
        'sku' => $product['sku'] ?? '',
        'regular_price' => $product['regular_price'] ?? '',
        'sale_price' => $product['sale_price'] ?? '',
        'price' => $product['price'] ?? '',
        'stock_quantity' => $product['stock_quantity'],
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

/**
 * Builds a sanitized WooCommerce product payload from the request body.
 */
function build_product_payload(array $body): array
{
    $data = [];

    if (isset($body['name'])) {
        $data['name'] = trim((string) $body['name']);
    }

    if (isset($body['sku'])) {
        $data['sku'] = trim((string) $body['sku']);
    }

    if (isset($body['regular_price'])) {
        $data['regular_price'] = sanitize_price($body['regular_price']);
    }

    if (array_key_exists('sale_price', $body)) {
        $sale = $body['sale_price'];
        $data['sale_price'] = ($sale === '' || $sale === null) ? '' : sanitize_price($sale);
    }

    if (isset($body['stock_status'])) {
        $allowed = ['instock', 'outofstock', 'onbackorder'];
        if (in_array($body['stock_status'], $allowed, true)) {
            $data['stock_status'] = $body['stock_status'];
        }
    }

    if (array_key_exists('stock_quantity', $body)) {
        $data['manage_stock'] = true;
        $data['stock_quantity'] = (int) $body['stock_quantity'];
    }

    if (isset($body['short_description'])) {
        $data['short_description'] = (string) $body['short_description'];
    }

    if (isset($body['description'])) {
        $data['description'] = (string) $body['description'];
    }

    if (isset($body['categories']) && is_array($body['categories'])) {
        $data['categories'] = array_map(function ($id) {
            return ['id' => (int) $id];
        }, $body['categories']);
    }

    if (isset($body['images']) && is_array($body['images'])) {
        $images = [];
        foreach ($body['images'] as $img) {
            $src = is_array($img) ? ($img['src'] ?? '') : (string) $img;
            $src = trim($src);
            if ($src !== '') {
                $images[] = ['src' => $src];
            }
        }
        $data['images'] = $images;
    }

    if (isset($body['status'])) {
        $allowed = ['publish', 'draft', 'private'];
        if (in_array($body['status'], $allowed, true)) {
            $data['status'] = $body['status'];
        }
    }

    if (!isset($body['id']) || (int) $body['id'] <= 0) {
        // New products default to a simple product type.
        $data['type'] = 'simple';
    }

    return $data;
}

/**
 * Validates and formats a price value for the WooCommerce API.
 */
function sanitize_price($value): string
{
    $price = (float) $value;
    if ($price < 0) {
        $price = 0;
    }
    return format_wc_price($price);
}
