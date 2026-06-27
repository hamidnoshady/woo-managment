<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === '') {
    $sites = list_sites_for_user($user);
    $current = get_current_site($user);

    json_response([
        'items' => array_map('map_site_summary', $sites),
        'current_site_id' => $current['id'] ?? null,
    ]);
}

if ($method === 'GET' && $action === 'detail') {
    require_superadmin_api();

    $id = (int) ($_GET['id'] ?? 0);
    $site = get_site($id);
    if ($site === null) {
        json_response(['error' => 'Site not found'], 404);
    }

    json_response(['item' => map_site_detail($site)]);
}

// All write operations require CSRF.
verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'select') {
    $id = (int) ($body['site_id'] ?? 0);
    $site = set_current_site($user, $id);

    if ($site === null) {
        json_response(['error' => 'You do not have access to this site.'], 403);
    }

    json_response(['ok' => true, 'item' => map_site_summary($site)]);
}

// Site CRUD is restricted to superadmins.
require_superadmin_api();

if ($method === 'POST' && $action === 'generate_agent_token') {
    $id = (int) ($body['site_id'] ?? 0);
    $site = get_site($id);
    if ($site === null) {
        json_response(['error' => 'Site not found'], 404);
    }
    $token = generate_agent_token($id);
    log_activity($user, $id, 'site', 'site_agent_token', 'log_site_agent_token_generated', [$site['name']]);
    json_response(['token' => $token]);
}

if ($method === 'POST') {
    $errors = validate_site_payload($body, true);
    if (!empty($errors)) {
        json_response(['error' => implode(' ', $errors)], 422);
    }

    $site = create_site($body);
    log_activity($user, null, 'system', 'site_create', 'log_site_created', [$site['name']]);
    json_response(['item' => map_site_detail($site)], 201);
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    if (get_site($id) === null) {
        json_response(['error' => 'Site not found'], 404);
    }

    $errors = validate_site_payload($body, false);
    if (!empty($errors)) {
        json_response(['error' => implode(' ', $errors)], 422);
    }

    $site = update_site($id, $body);
    log_activity($user, null, 'system', 'site_update', 'log_site_updated', [$site['name']]);
    json_response(['item' => map_site_detail($site)]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $existing = get_site($id);
    if ($existing === null) {
        json_response(['error' => 'Site not found'], 404);
    }

    delete_site($id);
    log_activity($user, null, 'system', 'site_delete', 'log_site_deleted', [$existing['name']]);
    json_response(['ok' => true]);
}

json_response(['error' => 'Method not allowed'], 405);

function map_site_summary(array $site): array
{
    return [
        'id' => (int) $site['id'],
        'name' => $site['name'],
        'store_url' => $site['store_url'],
    ];
}

function map_site_detail(array $site): array
{
    return [
        'id' => (int) $site['id'],
        'name' => $site['name'],
        'store_url' => $site['store_url'],
        'verify_ssl' => (bool) $site['verify_ssl'],
        'agent_paired' => !empty($site['agent_token']),
        'agent_paired_at' => $site['agent_paired_at'] !== null ? (int) $site['agent_paired_at'] : null,
        'agent_last_seen_at' => $site['agent_last_seen_at'] !== null ? (int) $site['agent_last_seen_at'] : null,
        'backup_enabled' => (bool) $site['backup_enabled'],
        'backup_schedule' => $site['backup_schedule'],
        'backup_retention_days' => (int) $site['backup_retention_days'],
    ];
}

/**
 * Validates a site create/update payload. $requireAll forces required
 * fields to be present (for creation); updates may omit fields to keep
 * the existing value.
 */
function validate_site_payload(array $body, bool $requireAll): array
{
    $errors = [];

    if ($requireAll && trim((string) ($body['name'] ?? '')) === '') {
        $errors[] = 'Site name is required.';
    }

    if ($requireAll || array_key_exists('store_url', $body)) {
        $url = trim((string) ($body['store_url'] ?? ''));
        if ($requireAll && $url === '') {
            $errors[] = 'Store URL is required.';
        } elseif ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Store URL must be a valid URL.';
        }
    }

    return $errors;
}
