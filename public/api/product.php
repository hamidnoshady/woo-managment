<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

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

    // The list page uses this to re-fetch a single product card (e.g. after
    // an undo) without pulling in description/images/taxonomy detail.
    if (($_GET['summary'] ?? '') === '1') {
        json_response(['item' => map_product_summary($result['data'])]);
    }

    $item = map_product_detail($result['data']);

    $wp = wordpress_client_for_site($site);
    if ($wp !== null) {
        $wpResult = $wp->getProduct($id);
        if ($wpResult['status'] >= 200 && $wpResult['status'] < 300 && is_array($wpResult['data'])) {
            $item['taxonomies'] = extract_taxonomy_terms($wpResult['data'], $wp);
        }
    }

    json_response(['item' => $item]);
}

// All write operations require CSRF.
verify_csrf_api();

$body = json_body();

if ($method === 'POST' || $method === 'PUT') {
    $id = (int) ($body['id'] ?? 0);
    $data = build_product_payload($body);

    if ($id > 0) {
        // Capture the previous values of the fields we're about to change, for undo.
        $before = $client->getProduct($id);
        if ($before['status'] < 200 || $before['status'] >= 300) {
            json_response(['error' => $before['data']['message'] ?? 'Product not found'], $before['status'] ?: 404);
        }
        $previous = build_undo_data($before['data'], $data);

        $result = $client->updateProduct($id, $data);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            json_response(['error' => $result['data']['message'] ?? 'Failed to save product'], $result['status'] ?: 502);
        }
        invalidate_products_cache((int) $site['id']);

        $changeSummary = describe_product_changes($before['data'], $data, $result['data']);
        $messageKey = $changeSummary !== '' ? 'log_product_updated_detail' : 'log_product_updated';
        $messageParams = $changeSummary !== '' ? [$result['data']['name'] ?? '', $changeSummary] : [$result['data']['name'] ?? ''];

        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'product_update',
            $messageKey,
            $messageParams,
            [
                'type' => 'update_product',
                'site_id' => (int) $site['id'],
                'product_id' => $id,
                'data' => $previous,
            ]
        );
    } else {
        $result = $client->createProduct($data);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            json_response(['error' => $result['data']['message'] ?? 'Failed to save product'], $result['status'] ?: 502);
        }
        invalidate_products_cache((int) $site['id']);

        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'product_create',
            'log_product_created',
            [$result['data']['name'] ?? ''],
            [
                'type' => 'delete_product',
                'site_id' => (int) $site['id'],
                'product_id' => (int) ($result['data']['id'] ?? 0),
            ]
        );
    }

    // Custom (e.g. ACF) taxonomies aren't supported by the WooCommerce REST
    // API, so they're written separately via the WordPress REST API.
    if (isset($body['taxonomies']) && is_array($body['taxonomies'])) {
        $wp = wordpress_client_for_site($site);
        if ($wp !== null) {
            $taxonomyFields = [];
            foreach ($body['taxonomies'] as $restBase => $termIds) {
                if (!is_array($termIds)) {
                    continue;
                }
                $taxonomyFields[(string) $restBase] = array_map('intval', $termIds);
            }
            if (!empty($taxonomyFields)) {
                $wp->updateProductTaxonomies((int) ($result['data']['id'] ?? $id), $taxonomyFields);
            }
        }
    }

    $item = map_product_detail($result['data']);
    $item['log_id'] = $logId;
    $item['message'] = $id > 0
        ? t($messageKey, ...$messageParams)
        : t('log_product_created', $result['data']['name'] ?? '');
    json_response(['item' => $item]);
}

if ($method === 'DELETE') {
    require_role_api(['admin', 'superadmin']);

    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Invalid product id'], 422);
    }

    $before = $client->getProduct($id);
    $productName = $before['status'] >= 200 && $before['status'] < 300 ? (string) ($before['data']['name'] ?? '') : '';

    $result = $client->deleteProduct($id, true);
    if ($result['status'] < 200 || $result['status'] >= 300) {
        json_response(['error' => $result['data']['message'] ?? 'Failed to delete product'], $result['status'] ?: 502);
    }
    invalidate_products_cache((int) $site['id']);

    $logId = log_activity(
        $user,
        (int) $site['id'],
        'site',
        'product_delete',
        'log_product_deleted',
        [$productName],
        null
    );

    json_response(['ok' => true, 'log_id' => $logId, 'message' => t('log_product_deleted', $productName)]);
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
 * Reads the currently-assigned custom taxonomy term IDs for a product from
 * the WordPress REST API response, keyed by taxonomy rest_base.
 */
function extract_taxonomy_terms(array $wpProduct, WordPressClient $wp): array
{
    $result = [];
    foreach ($wp->listCustomProductTaxonomies() as $tax) {
        $restBase = $tax['rest_base'];
        $value = $wpProduct[$restBase] ?? [];
        $result[$restBase] = is_array($value) ? array_map('intval', $value) : [];
    }
    return $result;
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
 * Builds an undo payload containing the previous values of the fields that
 * are about to be changed by $newData, taken from $before (the product as
 * it currently exists in WooCommerce).
 */
function build_undo_data(array $before, array $newData): array
{
    $previous = [];
    foreach (array_keys($newData) as $key) {
        switch ($key) {
            case 'categories':
                $previous['categories'] = array_map(fn($c) => ['id' => $c['id']], $before['categories'] ?? []);
                break;
            case 'images':
                $previous['images'] = array_map(fn($img) => ['src' => $img['src']], $before['images'] ?? []);
                break;
            case 'manage_stock':
            case 'stock_quantity':
                $previous['manage_stock'] = $before['manage_stock'] ?? false;
                $previous['stock_quantity'] = $before['stock_quantity'] ?? 0;
                break;
            default:
                $previous[$key] = $before[$key] ?? '';
        }
    }
    return $previous;
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

/**
 * Builds a short "field: old → new" summary of what actually changed in a
 * product update, for the activity log (so "Updated product X" becomes
 * something like "Updated product X (Regular price: 100 → 90)" - the same
 * level of detail stock changes already get).
 */
function describe_product_changes(array $before, array $newData, array $after): string
{
    $fieldLabels = [
        'name' => t('name'),
        'sku' => t('sku'),
        'regular_price' => t('regular_price'),
        'sale_price' => t('sale_price'),
        'stock_status' => t('stock_status'),
        'stock_quantity' => t('stock_quantity'),
        'status' => t('status'),
    ];

    $parts = [];
    foreach ($fieldLabels as $key => $label) {
        if (!array_key_exists($key, $newData)) {
            continue;
        }

        $oldValue = $before[$key] ?? '';
        $newValue = $after[$key] ?? $newData[$key];
        if ((string) $oldValue === (string) $newValue) {
            continue;
        }

        $oldDisplay = ($oldValue === '' || $oldValue === null) ? '—' : (string) $oldValue;
        $newDisplay = ($newValue === '' || $newValue === null) ? '—' : (string) $newValue;
        $parts[] = "{$label}: {$oldDisplay} \xE2\x86\x92 {$newDisplay}";
    }

    $bulkFields = ['categories', 'images', 'short_description', 'description'];
    if (array_intersect($bulkFields, array_keys($newData))) {
        $parts[] = t('other_details_updated');
    }

    return implode(', ', $parts);
}
