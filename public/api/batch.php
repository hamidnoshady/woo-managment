<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$ids = array_values(array_unique(array_map('intval', $body['ids'] ?? [])));
$ids = array_filter($ids, fn($id) => $id > 0);

if (empty($ids)) {
    json_response(['error' => 'No products selected'], 422);
}

$action = $body['action'] ?? '';
$preview = !empty($body['preview']);

$client = woocommerce_client_for_site($site);

if ($action === 'price') {
    // Bulk price changes are restricted to admins.
    require_role_api(['admin', 'superadmin']);

    $percent = (float) ($body['percent'] ?? 0);
    $mode = (string) ($body['mode'] ?? 'none');
    $stepOrEnding = (float) ($body['step_or_ending'] ?? 0);
    $applyTo = $body['apply_to'] ?? ['regular'];
    $applyToRegular = in_array('regular', $applyTo, true);
    $applyToSale = in_array('sale', $applyTo, true);

    $items = fetch_products_by_ids($client, $ids);

    $changes = [];
    foreach ($items as $product) {
        $change = ['id' => $product['id'], 'name' => $product['name']];
        $update = [];

        if ($applyToRegular && $product['regular_price'] !== '') {
            $old = (float) $product['regular_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['regular_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $update['regular_price'] = format_wc_price($new);
        }

        if ($applyToSale && $product['sale_price'] !== '') {
            $old = (float) $product['sale_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['sale_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $update['sale_price'] = format_wc_price($new);
        }

        if (empty($update)) {
            continue;
        }

        $changes[] = $change;

        if (!$preview) {
            $update['id'] = $product['id'];
            $batchUpdates[] = $update;
        }
    }

    if ($preview) {
        json_response(['preview' => true, 'changes' => $changes]);
    }

    $results = apply_batch_updates($client, $batchUpdates ?? []);
    json_response(['ok' => true, 'changes' => $changes, 'results' => $results]);
}

if ($action === 'stock') {
    $stockAction = $body['stock_action'] ?? 'set';
    $value = (int) ($body['value'] ?? 0);

    $items = fetch_products_by_ids($client, $ids);

    $changes = [];
    $batchUpdates = [];

    foreach ($items as $product) {
        $current = (int) ($product['stock_quantity'] ?? 0);
        $new = $stockAction === 'delta' ? max(0, $current + $value) : max(0, $value);

        $changes[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'stock_quantity' => ['old' => $current, 'new' => $new],
        ];

        if (!$preview) {
            $batchUpdates[] = [
                'id' => $product['id'],
                'manage_stock' => true,
                'stock_quantity' => $new,
                'stock_status' => $new > 0 ? 'instock' : 'outofstock',
            ];
        }
    }

    if ($preview) {
        json_response(['preview' => true, 'changes' => $changes]);
    }

    $results = apply_batch_updates($client, $batchUpdates);
    json_response(['ok' => true, 'changes' => $changes, 'results' => $results]);
}

json_response(['error' => 'Unknown batch action'], 400);

/**
 * Fetches full product data for a list of IDs (WooCommerce has no "get many by id"
 * filter for arbitrary fields we need, so we request them via the include param).
 */
function fetch_products_by_ids(WooCommerceClient $client, array $ids): array
{
    $result = $client->listProducts([
        'include' => implode(',', $ids),
        'per_page' => 100,
    ]);

    if ($result['status'] < 200 || $result['status'] >= 300) {
        json_response(['error' => $result['data']['message'] ?? 'Failed to fetch products'], $result['status'] ?: 502);
    }

    return is_array($result['data']) ? $result['data'] : [];
}

/**
 * Sends product updates to WooCommerce in batches of up to 100.
 */
function apply_batch_updates(WooCommerceClient $client, array $updates): array
{
    $results = [];
    foreach (array_chunk($updates, 100) as $chunk) {
        $result = $client->batchProducts(['update' => $chunk]);
        if ($result['status'] < 200 || $result['status'] >= 300) {
            json_response(['error' => $result['data']['message'] ?? 'Batch update failed'], $result['status'] ?: 502);
        }
        $results = array_merge($results, $result['data']['update'] ?? []);
    }
    return $results;
}
