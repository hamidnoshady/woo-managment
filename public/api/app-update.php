<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/AppUpdater.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_superadmin_api();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response([
        'current_version' => app_version(),
        'channel' => app_update_channel(),
        'latest' => check_app_update(),
    ]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$release = check_app_update();
if ($release === null) {
    json_response(['error' => 'No update available'], 409);
}

try {
    apply_app_update($release);
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 502);
}

log_activity($user, null, 'system', 'app_update', 'log_app_updated', [$release['version']]);

json_response(['ok' => true, 'version' => $release['version']]);
