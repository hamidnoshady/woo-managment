<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';

$user = require_login_api();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === '') {
    $scope = $_GET['scope'] ?? null;
    $page = max(1, (int) ($_GET['page'] ?? 1));
    $perPage = (int) ($_GET['per_page'] ?? 20);

    $result = get_activity_logs($user, is_string($scope) ? $scope : null, $page, $perPage);
    json_response($result);
}

if ($method === 'POST' && $action === 'undo') {
    verify_csrf_api();

    $body = json_body();
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        json_response(['error' => 'Invalid log id'], 422);
    }

    $log = find_activity_log($id);
    if ($log === null) {
        json_response(['error' => 'Log entry not found'], 404);
    }

    if (!can_undo_activity_log($log, $user)) {
        json_response(['error' => 'This change can no longer be undone.'], 409);
    }

    $undoData = json_decode((string) $log['undo_data'], true);
    if (!is_array($undoData)) {
        json_response(['error' => 'This change can no longer be undone.'], 409);
    }

    $site = get_site((int) $undoData['site_id']);
    if ($site === null || !user_can_access_site($user, (int) $site['id'])) {
        json_response(['error' => 'This change can no longer be undone.'], 409);
    }

    $client = woocommerce_client_for_site($site);

    switch ($undoData['type'] ?? '') {
        case 'update_product':
            $result = $client->updateProduct((int) $undoData['product_id'], $undoData['data']);
            if ($result['status'] < 200 || $result['status'] >= 300) {
                json_response(['error' => $result['data']['message'] ?? 'Failed to undo change'], $result['status'] ?: 502);
            }
            break;

        case 'delete_product':
            $result = $client->deleteProduct((int) $undoData['product_id'], true);
            if ($result['status'] < 200 || $result['status'] >= 300) {
                json_response(['error' => $result['data']['message'] ?? 'Failed to undo change'], $result['status'] ?: 502);
            }
            break;

        case 'batch_update':
            foreach (array_chunk($undoData['updates'], 100) as $chunk) {
                $result = $client->batchProducts(['update' => $chunk]);
                if ($result['status'] < 200 || $result['status'] >= 300) {
                    json_response(['error' => $result['data']['message'] ?? 'Failed to undo change'], $result['status'] ?: 502);
                }
            }
            break;

        default:
            json_response(['error' => 'This change can no longer be undone.'], 409);
    }

    mark_activity_log_undone($id);

    $params = json_decode((string) $log['message_params'], true);
    $params = is_array($params) ? $params : [];
    $undoneMessage = t($log['message_key'], ...$params);

    log_activity($user, (int) $log['site_id'], 'site', 'undo', 'log_undo_applied', [$undoneMessage]);

    json_response(['ok' => true]);
}

json_response(['error' => 'Not found'], 404);
