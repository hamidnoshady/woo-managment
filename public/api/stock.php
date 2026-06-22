<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
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

$client = woocommerce_client_for_site($site);

// Fetch the current product so we can build the undo payload and the log message.
$current = $client->getProduct($id);
if ($current['status'] < 200 || $current['status'] >= 300) {
    json_response(['error' => $current['data']['message'] ?? 'Product not found'], $current['status'] ?: 404);
}

$currentQty = (int) ($current['data']['stock_quantity'] ?? 0);
$currentStatus = (string) ($current['data']['stock_status'] ?? 'instock');
$productName = (string) ($current['data']['name'] ?? '');

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

$result = $client->updateProduct($id, $data);

if ($result['status'] < 200 || $result['status'] >= 300) {
    json_response(['error' => $result['data']['message'] ?? 'Failed to update stock'], $result['status'] ?: 502);
}

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
    'stock_quantity' => $result['data']['stock_quantity'],
    'stock_status' => $result['data']['stock_status'],
    'log_id' => $logId,
    'message' => $logId !== null ? t('log_stock_changed', $productName, (string) $currentQty, (string) $newQty) : null,
]);
