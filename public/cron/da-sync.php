<?php
// public/cron/da-sync.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/DirectAdminSync.php';

// Same cron secret as public/cron/backup.php — all cron triggers here are
// superadmin-only, no need for a distinct token per feature.
$token = (string) ($_GET['token'] ?? '');
$expected = (string) get_setting('backup_cron_token');

if ($expected === '' || !hash_equals($expected, $token)) {
    json_response(['error' => 'Invalid token'], 403);
}

try {
    $result = DirectAdminSync::syncSites();
    json_response(['ok' => true] + $result);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 502);
}
