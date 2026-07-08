<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/BackupManager.php';
require_once __DIR__ . '/../../includes/AppJetBackupManager.php';

$user = require_superadmin_api();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['items' => array_map('format_backup_row', BackupManager::listRecent())]);
}

if ($method === 'POST') {
    verify_csrf_api();
    $body = json_body();
    $type = ($body['type'] ?? 'database') === 'full' ? 'full' : 'database';

    $result = AppJetBackupManager::isConfigured()
        ? AppJetBackupManager::run()
        : BackupManager::run($type);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['ok' => true, 'item' => $result]);
}

json_response(['error' => 'Method not allowed'], 405);

function format_backup_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => $row['type'],
        'status' => $row['status'],
        's3_key' => $row['s3_key'],
        'external_ref' => $row['external_ref'],
        'size_bytes' => (int) $row['size_bytes'],
        'error' => $row['error'],
        'created_at' => (int) $row['created_at'],
    ];
}
