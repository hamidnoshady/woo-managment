<?php
// public/api/da-sync.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/DirectAdminSync.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_superadmin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

verify_csrf_api();

try {
    $result = DirectAdminSync::syncSites();
    log_activity($user, null, 'system', 'da_sync', 'log_da_sync_run', [$result['linked'], $result['created']]);
    json_response(['ok' => true] + $result);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 502);
}
