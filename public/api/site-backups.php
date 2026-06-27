<?php
// public/api/site-backups.php

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

if ($method === 'GET' && $action === '') {
    json_response(['items' => array_map('map_site_backup', SiteBackupManager::listForSite((int) $site['id']))]);
}

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    json_response(['item' => map_site_backup(SiteBackupManager::pollBackup($site, $jobId))]);
}

verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'start') {
    if (empty($site['agent_token'])) {
        json_response(['error' => 'This site has no paired agent. Generate a pairing token in Admin -> Sites first.'], 409);
    }
    $scope = ($body['scope'] ?? 'database') === 'full' ? 'full' : 'database';

    $job = SiteBackupManager::startBackup($site, $scope);
    log_activity($user, (int) $site['id'], 'site', 'site_backup_start', 'log_site_backup_started', [$scope]);
    json_response(['item' => map_site_backup($job)], 201);
}

json_response(['error' => 'Method not allowed'], 405);

function map_site_backup(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    return [
        'id' => (int) $job['id'],
        'type' => $job['type'],
        'status' => $job['status'],
        'step_label' => $job['step_label'],
        'percent' => (int) $job['percent'],
        'total_size_bytes' => (int) $job['total_size_bytes'],
        'error' => $job['error'],
        'started_at' => (int) $job['started_at'],
        'completed_at' => $job['completed_at'] !== null ? (int) $job['completed_at'] : null,
    ];
}
