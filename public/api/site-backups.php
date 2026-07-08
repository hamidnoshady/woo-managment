<?php
// public/api/site-backups.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/SiteBackupManager.php';
require_once __DIR__ . '/../../includes/JetBackupSiteManager.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === '') {
    $items = $site['da_username'] !== ''
        ? array_map('map_jetbackup_row', JetBackupSiteManager::listForSite((int) $site['id']))
        : array_map('map_site_backup', SiteBackupManager::listForSite((int) $site['id']));
    json_response(['items' => $items, 'source' => $site['da_username'] !== '' ? 'jetbackup' : 'legacy']);
}

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    $item = $site['da_username'] !== ''
        ? map_jetbackup_row(JetBackupSiteManager::pollBackup($site, $jobId))
        : map_site_backup(SiteBackupManager::pollBackup($site, $jobId));
    json_response(['item' => $item]);
}

verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'start') {
    if ($site['da_username'] !== '') {
        $job = JetBackupSiteManager::startBackup($site);
        log_activity($user, (int) $site['id'], 'site', 'site_backup_start', 'log_site_backup_started', ['full']);
        json_response(['item' => map_jetbackup_row($job)], 201);
    }

    if (empty($site['agent_token'])) {
        json_response(['error' => 'This site has no paired agent. Generate a pairing token in Admin -> Sites first.'], 409);
    }
    $scope = ($body['scope'] ?? 'database') === 'full' ? 'full' : 'database';

    $job = SiteBackupManager::startBackup($site, $scope);
    log_activity($user, (int) $site['id'], 'site', 'site_backup_start', 'log_site_backup_started', [$scope]);
    json_response(['item' => map_site_backup($job)], 201);
}

if ($method === 'POST' && $action === 'restore') {
    if ($site['da_username'] === '') {
        json_response(['error' => 'JetBackup restore is only available for DirectAdmin-linked sites.'], 409);
    }
    $sourceId = (int) ($body['source_id'] ?? 0);
    try {
        $restoreId = JetBackupSiteManager::startRestore($site, $sourceId);
    } catch (Throwable $e) {
        json_response(['error' => $e->getMessage()], 502);
    }
    log_activity($user, (int) $site['id'], 'site', 'site_restore_start', 'log_site_restore_started', ['full']);
    json_response(['ok' => true, 'jetbackup_restore_id' => $restoreId], 201);
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

function map_jetbackup_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'size_bytes' => (int) $row['size_bytes'],
        'error' => $row['error'],
        'created_at' => (int) $row['created_at'],
        'completed_at' => $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
    ];
}
