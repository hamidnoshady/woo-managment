<?php
// public/api/da-sync.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/DirectAdminSync.php';
require_once __DIR__ . '/../../includes/JetBackupClient.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_superadmin_api();

// Verifies both legs of the integration independently, so a failure clearly
// says which credential/service is broken instead of the admin only finding
// out when a real site sync or backup fails partway through.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'test') {
    $da = directadmin_client();
    $daResult = ['ok' => true, 'error' => ''];
    if (!$da->isConfigured()) {
        $daResult = ['ok' => false, 'error' => 'DirectAdmin API is not configured.'];
    } else {
        try {
            $da->listAccounts();
        } catch (Throwable $e) {
            $daResult = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    $jetbackupResult = jetbackup_client()->testConnection();

    json_response(['directadmin' => $daResult, 'jetbackup' => $jetbackupResult]);
}

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
