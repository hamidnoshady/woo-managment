<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'POST' && $action === 'start') {
    verify_csrf_api();

    $body = json_body();
    $batchAction = $body['action'] ?? '';
    if (!in_array($batchAction, ['price', 'stock'], true)) {
        json_response(['error' => 'Unknown batch action'], 400);
    }
    if ($batchAction === 'price') {
        require_role_api(['admin', 'superadmin']);
    }

    $client = site_agent_client_for_site($site);
    $resolved = resolve_batch_target_ids($client, $body);
    if (empty($resolved['ids'])) {
        json_response(['error' => 'No products selected'], 422);
    }

    $params = $batchAction === 'price'
        ? [
            'percent' => (float) ($body['percent'] ?? 0),
            'mode' => (string) ($body['mode'] ?? 'none'),
            'step_or_ending' => (float) ($body['step_or_ending'] ?? 0),
            'apply_to' => array_values(array_intersect((array) ($body['apply_to'] ?? ['regular']), ['regular', 'sale'])),
        ]
        : [
            'stock_action' => ($body['stock_action'] ?? 'set') === 'delta' ? 'delta' : 'set',
            'value' => (int) ($body['value'] ?? 0),
        ];

    $pdo = Database::get();
    $pdo->beginTransaction();

    try {
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO batch_jobs (site_id, user_id, action, params_json, status, total_items, started_at)
             VALUES (?, ?, ?, ?, \'running\', ?, ?)'
        );
        $stmt->execute([(int) $site['id'], (int) $user['id'], $batchAction, json_encode($params), count($resolved['ids']), $now]);
        $jobId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare('INSERT INTO batch_job_items (batch_job_id, product_id, status) VALUES (?, ?, \'pending\')');
        foreach ($resolved['ids'] as $id) {
            $itemStmt->execute([$jobId, $id]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        json_response(['error' => 'Failed to create batch job'], 500);
    }

    json_response(['id' => $jobId, 'total_items' => count($resolved['ids'])], 201);
}

json_response(['error' => 'Not found'], 404);
