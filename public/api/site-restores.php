<?php
// public/api/site-restores.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/SiteBackupManager.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    json_response(['item' => map_site_restore(SiteBackupManager::pollRestore($site, $jobId))]);
}

require_superadmin_api();
verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'start') {
    $sourceBackupId = (int) ($body['source_backup_id'] ?? 0);
    $scope = ($body['scope'] ?? 'database') === 'full' ? 'full' : 'database';

    try {
        $job = SiteBackupManager::startRestore($site, $sourceBackupId, $scope);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }

    log_activity($user, (int) $site['id'], 'site', 'site_restore_start', 'log_site_restore_started', [$scope]);
    json_response(['item' => map_site_restore($job)], 201);
}

json_response(['error' => 'Method not allowed'], 405);

function map_site_restore(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    return [
        'id' => (int) $job['id'],
        'source_backup_id' => (int) $job['source_backup_id'],
        'type' => $job['type'],
        'status' => $job['status'],
        'step_label' => $job['step_label'],
        'percent' => (int) $job['percent'],
        'error' => $job['error'],
        'started_at' => (int) $job['started_at'],
        'completed_at' => $job['completed_at'] !== null ? (int) $job['completed_at'] : null,
    ];
}
