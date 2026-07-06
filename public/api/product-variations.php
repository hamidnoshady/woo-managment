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
