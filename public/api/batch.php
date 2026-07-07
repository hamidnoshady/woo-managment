<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

// This endpoint is preview-only: it computes what a batch action *would*
// change without applying anything. Actual application goes through
// public/api/batch-jobs.php's start/poll job, which is crash-proof and
// chunked - a single synchronous request here (especially for a large
// "select all matching filters" set) would risk the same timeout/abort
// problems the job engine exists to avoid.
const BATCH_PREVIEW_MAX_ITEMS = 100;

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$action = $body['action'] ?? '';

$client = site_agent_client_for_site($site);

$resolved = resolve_batch_target_ids($client, $body);
if (empty($resolved['ids'])) {
    json_response(['error' => 'No products selected'], 422);
}

// Only fetch the first N ids' full product data for the preview - a
// "select all matching filters" resolve can be thousands of ids, and the
// preview only ever needs to show a bounded sample.
$previewIds = array_slice($resolved['ids'], 0, BATCH_PREVIEW_MAX_ITEMS);
$truncated = $resolved['total'] > count($previewIds);
$hiddenCount = $resolved['total'] - count($previewIds);

if ($action === 'price') {
    require_role_api(['admin', 'superadmin']);

    $percent = (float) ($body['percent'] ?? 0);
    $mode = (string) ($body['mode'] ?? 'none');
    $stepOrEnding = (float) ($body['step_or_ending'] ?? 0);
    $applyTo = $body['apply_to'] ?? ['regular'];
    $applyToRegular = in_array('regular', $applyTo, true);
    $applyToSale = in_array('sale', $applyTo, true);

    $items = fetch_products_by_ids($client, $previewIds);

    $changes = [];
    foreach ($items as $product) {
        $change = ['id' => $product['id'], 'name' => $product['name']];
        $hasChange = false;

        if ($applyToRegular && $product['regular_price'] !== '') {
            $old = (float) $product['regular_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['regular_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $hasChange = true;
        }

        if ($applyToSale && $product['sale_price'] !== '') {
            $old = (float) $product['sale_price'];
            $new = adjust_price($old, $percent, $mode, $stepOrEnding);
            $change['sale_price'] = ['old' => format_wc_price($old), 'new' => format_wc_price($new)];
            $hasChange = true;
        }

        if ($hasChange) {
            $changes[] = $change;
        }
    }

    json_response(['preview' => true, 'changes' => $changes, 'total_matched' => $resolved['total'], 'truncated' => $truncated, 'hidden_count' => $hiddenCount]);
}

if ($action === 'stock') {
    $stockAction = $body['stock_action'] ?? 'set';
    $value = (int) ($body['value'] ?? 0);

    $items = fetch_products_by_ids($client, $previewIds);

    $changes = [];
    foreach ($items as $product) {
        $current = (int) ($product['stock_quantity'] ?? 0);
        $new = $stockAction === 'delta' ? max(0, $current + $value) : max(0, $value);

        $changes[] = [
            'id' => $product['id'],
            'name' => $product['name'],
            'stock_quantity' => ['old' => $current, 'new' => $new],
        ];
    }

    json_response(['preview' => true, 'changes' => $changes, 'total_matched' => $resolved['total'], 'truncated' => $truncated, 'hidden_count' => $hiddenCount]);
}

json_response(['error' => 'Unknown batch action'], 400);

/**
 * Fetches full product data for a list of ids. The plugin has no "get many
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
