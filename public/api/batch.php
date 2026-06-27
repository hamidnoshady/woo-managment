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
$ids = array_values(array_unique(array_map('intval', $body['ids'] ?? [])));
$ids = array_filter($ids, fn($id) => $id > 0);

if (empty($ids)) {
    json_response(['error' => 'No products selected'], 422);
}

$action = $body['action'] ?? '';
$preview = !empty($body['preview']);

$client = site_agent_client_for_site($site);

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

            $undoUpdate = ['id' => $product['id']];
            if (isset($change['regular_price'])) {
                $undoUpdate['regular_price'] = $change['regular_price']['old'];
            }
            if (isset($change['sale_price'])) {
                $undoUpdate['sale_price'] = $change['sale_price']['old'];
            }
            $undoUpdates[] = $undoUpdate;
        }
    }

    if ($preview) {
        json_response(['preview' => true, 'changes' => $changes]);
    }

    $results = apply_batch_updates($client, $batchUpdates ?? [], (int) $site['id']);

    $logId = null;
    if (!empty($changes)) {
        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'batch_price',
            'log_batch_price_applied',
            [(string) count($changes)],
            [
                'type' => 'batch_update',
                'site_id' => (int) $site['id'],
                'updates' => $undoUpdates ?? [],
            ]
        );
    }

    json_response([
        'ok' => true,
        'changes' => $changes,
        'results' => $results,
        'log_id' => $logId,
        'message' => $logId !== null ? t('log_batch_price_applied', (string) count($changes)) : null,
    ]);
}

if ($action === 'stock') {
    $stockAction = $body['stock_action'] ?? 'set';
    $value = (int) ($body['value'] ?? 0);

    $items = fetch_products_by_ids($client, $ids);

    $changes = [];
    $batchUpdates = [];
    $undoUpdates = [];

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

            $undoUpdates[] = [
                'id' => $product['id'],
                'manage_stock' => true,
                'stock_quantity' => $current,
                'stock_status' => $product['stock_status'] ?? ($current > 0 ? 'instock' : 'outofstock'),
            ];
        }
    }

    if ($preview) {
        json_response(['preview' => true, 'changes' => $changes]);
    }

    $results = apply_batch_updates($client, $batchUpdates, (int) $site['id']);

    $logId = null;
    if (!empty($changes)) {
        $logId = log_activity(
            $user,
            (int) $site['id'],
            'site',
            'batch_stock',
            'log_batch_stock_applied',
            [(string) count($changes)],
            [
                'type' => 'batch_update',
                'site_id' => (int) $site['id'],
                'updates' => $undoUpdates,
            ]
        );
    }

    json_response([
        'ok' => true,
        'changes' => $changes,
        'results' => $results,
        'log_id' => $logId,
        'message' => $logId !== null ? t('log_batch_stock_applied', (string) count($changes)) : null,
    ]);
}

json_response(['error' => 'Unknown batch action'], 400);

/**
 * Fetches full product data for a list of IDs. The plugin has no "get many
 * by id" filter, so each id is fetched individually (missing/deleted ids
 * are simply skipped).
 */
function fetch_products_by_ids(SiteAgentClient $client, array $ids): array
{
    $items = [];
    foreach ($ids as $id) {
        try {
            $product = $client->getProduct($id);
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 502);
        }
        if ($product !== null) {
            $items[] = $product;
        }
    }
    return $items;
}

/**
 * Sends product updates to the site agent in batches of up to 100.
 */
function apply_batch_updates(SiteAgentClient $client, array $updates, int $siteId): array
{
    $results = [];
    foreach (array_chunk($updates, 100) as $chunk) {
        try {
            $results = array_merge($results, $client->batchProducts($chunk));
        } catch (RuntimeException $e) {
            json_response(['error' => $e->getMessage()], 502);
        }
    }
    if (!empty($updates)) {
        invalidate_products_cache($siteId);
    }
    return $results;
}
