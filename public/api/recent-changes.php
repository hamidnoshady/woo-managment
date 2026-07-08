<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

const RECENT_CHANGES_WINDOW_SECONDS = 86400;
const RECENT_CHANGES_MAX_IDS = 200;

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$idsParam = (string) ($_GET['ids'] ?? '');
$ids = array_map('intval', explode(',', $idsParam));
$ids = array_values(array_unique(array_filter($ids, fn($id) => $id > 0)));
$ids = array_slice($ids, 0, RECENT_CHANGES_MAX_IDS);

if (empty($ids)) {
    json_response(['items' => (object) []]);
}

$changes = get_recent_product_changes((int) $site['id'], $ids, time() - RECENT_CHANGES_WINDOW_SECONDS);

$items = [];
foreach ($changes as $productId => $info) {
    $items[(string) $productId] = $info;
}

json_response(['items' => (object) $items]);
