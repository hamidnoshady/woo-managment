<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/BackupManager.php';
require_once __DIR__ . '/../../includes/SiteBackupManager.php';

// Same cron secret as public/cron/backup.php — both are superadmin-only
// cron triggers, no need for a second token.
$token = (string) ($_GET['token'] ?? '');
$expected = (string) get_setting('backup_cron_token');

if ($expected === '' || !hash_equals($expected, $token)) {
    json_response(['error' => 'Invalid token'], 403);
}

SiteBackupManager::tickAllRunning();

json_response(['ok' => true]);
