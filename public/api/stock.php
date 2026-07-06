<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$id = (int) ($body['id'] ?? 0);

if ($id <= 0) {
    json_response(['error' => 'Invalid product id'], 422);
}

$client = site_agent_client_for_site($site);

// The product list already holds the current quantity/status/name client-side
// (it just rendered them), so the client sends them along to skip a redundant
// getProduct round-trip. Fall back to fetching if they're missing (e.g. an
// absolute "quantity" set from a context that doesn't have them cached).
if (isset($body['current_quantity'], $body['current_status'], $body['name'])) {
    $currentQty = (int) $body['current_quantity'];
    $currentStatus = (string) $body['current_status'];
    $productName = (string) $body['name'];
} else {
    try {
        $current = $client->getProduct($id);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 502);
    }
    if ($current === null) {
        json_response(['error' => 'Product not found'], 404);
    }
    $currentQty = (int) ($current['stock_quantity'] ?? 0);
    $currentStatus = (string) ($current['stock_status'] ?? 'instock');
    $productName = (string) ($current['name'] ?? '');
}

if (isset($body['delta'])) {
    $newQty = max(0, $currentQty + (int) $body['delta']);
} elseif (isset($body['quantity'])) {
    $newQty = max(0, (int) $body['quantity']);
} else {
    json_response(['error' => 'Provide either "delta" or "quantity"'], 422);
}

$data = [
    'manage_stock' => true,
    'stock_quantity' => $newQty,
];

if ($newQty > 0) {
    $data['stock_status'] = 'instock';
} elseif (!isset($body['keep_status'])) {
    $data['stock_status'] = 'outofstock';
}

try {
    $result = $client->updateProduct($id, $data);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 502);
}
if ($result === null) {
    json_response(['error' => 'Product not found'], 404);
}
invalidate_products_cache((int) $site['id']);

$logId = null;
if ($newQty !== $currentQty) {
    $logId = log_activity(
        $user,
        (int) $site['id'],
        'site',
        'stock_update',
        'log_stock_changed',
        [$productName, (string) $currentQty, (string) $newQty],
        [
            'type' => 'update_product',
            'site_id' => (int) $site['id'],
            'product_id' => $id,
            'data' => [
                'manage_stock' => true,
                'stock_quantity' => $currentQty,
                'stock_status' => $currentStatus,
            ],
        ]
    );
}

json_response([
    'ok' => true,
    'stock_quantity' => $result['stock_quantity'],
    'stock_status' => $result['stock_status'],
    'log_id' => $logId,
    'message' => $logId !== null ? t('log_stock_changed', $productName, (string) $currentQty, (string) $newQty) : null,
]);
