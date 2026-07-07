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

const BATCH_TICK_SIZE = 25;

if ($method === 'GET' && $action === 'poll') {
    verify_csrf_api();

    $jobId = (int) ($_GET['id'] ?? 0);
    $job = fetch_batch_job($jobId, (int) $site['id']);
    if ($job === null) {
        json_response(['error' => 'Batch job not found'], 404);
    }

    $items = [];
    if ($job['status'] === 'running') {
        $items = tick_batch_job($job, $user);
        $job = fetch_batch_job($jobId, (int) $site['id']);
    }

    json_response(['job' => map_batch_job($job), 'items' => array_map('map_batch_job_item', $items)]);
}

if ($method === 'GET' && $action === 'detail') {
    $logId = (int) ($_GET['log_id'] ?? 0);
    $pdo = Database::get();

    $stmt = $pdo->prepare('SELECT batch_job_id FROM activity_logs WHERE id = ? AND site_id = ?');
    $stmt->execute([$logId, (int) $site['id']]);
    $jobId = (int) ($stmt->fetchColumn() ?: 0);
    if ($jobId <= 0) {
        json_response(['error' => 'No batch report for this log entry'], 404);
    }

    $job = fetch_batch_job($jobId, (int) $site['id']);
    if ($job === null) {
        json_response(['error' => 'Batch job not found'], 404);
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM batch_job_items WHERE batch_job_id = ? ORDER BY id ASC');
    $itemsStmt->execute([$jobId]);

    json_response(['job' => map_batch_job($job), 'items' => array_map('map_batch_job_item', $itemsStmt->fetchAll())]);
}

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

function fetch_batch_job(int $jobId, int $siteId): ?array
{
    if ($jobId <= 0) {
        return null;
    }
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM batch_jobs WHERE id = ? AND site_id = ?');
    $stmt->execute([$jobId, $siteId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Processes up to BATCH_TICK_SIZE pending items for this job, one at a
 * time, each independently wrapped in try/catch. A single item's failure
 * is recorded and the loop continues - this is the fix for the previous
 * fail-fast behavior where one bad product aborted the entire batch.
 * When the last pending item is processed, the job is marked completed
 * and a single activity-log entry is written for the whole batch.
 *
 * @return array the batch_job_items rows just processed
 */
function tick_batch_job(array $job, array $user): array
{
    $pdo = Database::get();
    $client = site_agent_client_for_site(get_site((int) $job['site_id']));
    $params = json_decode((string) $job['params_json'], true) ?: [];

    $stmt = $pdo->prepare(
        'SELECT * FROM batch_job_items WHERE batch_job_id = ? AND status = \'pending\' ORDER BY id ASC LIMIT ' . BATCH_TICK_SIZE
    );
    $stmt->execute([$job['id']]);
    $pending = $stmt->fetchAll();

    $processed = [];
    $succeededDelta = 0;
    $failedDelta = 0;

    foreach ($pending as $item) {
        try {
            $result = apply_one_batch_item($client, (int) $item['product_id'], $job['action'], $params);

            $updateStmt = $pdo->prepare(
                'UPDATE batch_job_items SET status = \'success\', product_name = ?, change_json = ? WHERE id = ?'
            );
            $updateStmt->execute([$result['name'], json_encode($result['change']), $item['id']]);
            $succeededDelta++;
        } catch (Throwable $e) {
            $updateStmt = $pdo->prepare(
                'UPDATE batch_job_items SET status = \'failed\', error = ? WHERE id = ?'
            );
            $updateStmt->execute([$e->getMessage(), $item['id']]);
            $failedDelta++;
        }

        $refetch = $pdo->prepare('SELECT * FROM batch_job_items WHERE id = ?');
        $refetch->execute([$item['id']]);
        $processed[] = $refetch->fetch();
    }

    $processedDelta = count($pending);
    $pdo->prepare(
        'UPDATE batch_jobs
         SET processed_items = processed_items + ?, succeeded_items = succeeded_items + ?, failed_items = failed_items + ?
         WHERE id = ?'
    )->execute([$processedDelta, $succeededDelta, $failedDelta, $job['id']]);

    $refreshed = fetch_batch_job((int) $job['id'], (int) $job['site_id']);
    if ($refreshed !== null && $refreshed['processed_items'] >= $refreshed['total_items'] && $refreshed['status'] === 'running') {
        complete_batch_job($refreshed, $user);
    }

    return $processed;
}

/**
 * Applies one product's price/stock change and returns its display name
 * and the {field: {old, new}} change made. Throws on any failure (missing
 * product, remote update error) - the caller records that as this item's
 * failure and moves on.
 */
function apply_one_batch_item(SiteAgentClient $client, int $productId, string $action, array $params): array
{
    $product = $client->getProduct($productId);
    if ($product === null) {
        throw new RuntimeException('Product not found');
    }

    if ($action === 'price') {
        $applyTo = $params['apply_to'] ?? ['regular'];
        $update = [];
        $change = [];

        if (in_array('regular', $applyTo, true) && $product['regular_price'] !== '') {
            $old = (float) $product['regular_price'];
            $new = adjust_price($old, (float) $params['percent'], (string) $params['mode'], (float) $params['step_or_ending']);
            $update['regular_price'] = format_wc_price($new);
            $change['regular_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
        }
        if (in_array('sale', $applyTo, true) && $product['sale_price'] !== '') {
            $old = (float) $product['sale_price'];
            $new = adjust_price($old, (float) $params['percent'], (string) $params['mode'], (float) $params['step_or_ending']);
            $update['sale_price'] = format_wc_price($new);
            $change['sale_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
        }

        if (empty($update)) {
            return ['name' => $product['name'] ?? '', 'change' => []];
        }

        $client->updateProduct($productId, $update);
        return ['name' => $product['name'] ?? '', 'change' => $change];
    }

    // stock
    $current = (int) ($product['stock_quantity'] ?? 0);
    $new = ($params['stock_action'] ?? 'set') === 'delta' ? max(0, $current + (int) $params['value']) : max(0, (int) $params['value']);

    $client->updateProduct($productId, [
        'manage_stock' => true,
        'stock_quantity' => $new,
        'stock_status' => $new > 0 ? 'instock' : 'outofstock',
    ]);

    return ['name' => $product['name'] ?? '', 'change' => ['stock_quantity' => ['old' => $current, 'new' => $new]]];
}

function complete_batch_job(array $job, array $user): void
{
    $pdo = Database::get();

    // Guard: only the caller that actually flips status running -> completed
    // proceeds to log the activity / invalidate the cache. Two concurrent
    // poll requests can both see status = 'running' and both reach this
    // function; this conditional UPDATE ensures only one of them gets
    // rowCount() === 1, closing the race without an explicit lock/transaction.
    $completeStmt = $pdo->prepare(
        'UPDATE batch_jobs SET status = \'completed\', completed_at = ? WHERE id = ? AND status = \'running\''
    );
    $completeStmt->execute([time(), $job['id']]);
    if ($completeStmt->rowCount() !== 1) {
        // Another concurrent tick already completed this job.
        return;
    }

    $itemsStmt = $pdo->prepare('SELECT * FROM batch_job_items WHERE batch_job_id = ? AND status = \'success\'');
    $itemsStmt->execute([$job['id']]);
    $succeeded = $itemsStmt->fetchAll();

    $messageKey = $job['action'] === 'price' ? 'log_batch_price_applied' : 'log_batch_stock_applied';
    $undoUpdates = [];
    foreach ($succeeded as $item) {
        $change = json_decode((string) $item['change_json'], true) ?: [];
        $undo = ['id' => (int) $item['product_id']];
        if (isset($change['regular_price'])) {
            $undo['regular_price'] = $change['regular_price']['old'];
        }
        if (isset($change['sale_price'])) {
            $undo['sale_price'] = $change['sale_price']['old'];
        }
        if (isset($change['stock_quantity'])) {
            $oldQty = (int) $change['stock_quantity']['old'];
            $undo['manage_stock'] = true;
            $undo['stock_quantity'] = $oldQty;
            $undo['stock_status'] = $oldQty > 0 ? 'instock' : 'outofstock';
        }
        $undoUpdates[] = $undo;
    }

    $logId = null;
    if (!empty($undoUpdates)) {
        $logId = log_activity(
            $user,
            (int) $job['site_id'],
            'site',
            $job['action'] === 'price' ? 'batch_price' : 'batch_stock',
            $messageKey,
            [(string) count($undoUpdates)],
            [
                'type' => 'batch_update',
                'site_id' => (int) $job['site_id'],
                'updates' => $undoUpdates,
            ],
            (int) $job['id']
        );

        $pdo->prepare('UPDATE batch_jobs SET log_id = ? WHERE id = ?')
            ->execute([$logId, $job['id']]);
    }

    invalidate_products_cache((int) $job['site_id']);
}

function map_batch_job(array $job): array
{
    return [
        'id' => (int) $job['id'],
        'action' => $job['action'],
        'status' => $job['status'],
        'total_items' => (int) $job['total_items'],
        'processed_items' => (int) $job['processed_items'],
        'succeeded_items' => (int) $job['succeeded_items'],
        'failed_items' => (int) $job['failed_items'],
        'log_id' => $job['log_id'] !== null ? (int) $job['log_id'] : null,
    ];
}

function map_batch_job_item(array $item): array
{
    return [
        'product_id' => (int) $item['product_id'],
        'product_name' => $item['product_name'],
        'status' => $item['status'],
        'change' => $item['change_json'] !== null ? json_decode($item['change_json'], true) : null,
        'error' => $item['error'],
    ];
}
