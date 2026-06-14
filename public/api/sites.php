<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

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

if ($method === 'POST') {
    $errors = validate_site_payload($body, true);
    if (!empty($errors)) {
        json_response(['error' => implode(' ', $errors)], 422);
    }

    $site = create_site($body);
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
    json_response(['item' => map_site_detail($site)]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    if (get_site($id) === null) {
        json_response(['error' => 'Site not found'], 404);
    }

    delete_site($id);
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
        'consumer_key' => mask_secret($site['consumer_key']),
        'consumer_secret' => mask_secret($site['consumer_secret']),
        'verify_ssl' => (bool) $site['verify_ssl'],
    ];
}

function mask_secret(string $value): string
{
    if (strlen($value) <= 6) {
        return str_repeat('*', strlen($value));
    }
    return substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
}

/**
 * Validates a site create/update payload. $requireAll forces credential
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

    if ($requireAll) {
        if (trim((string) ($body['consumer_key'] ?? '')) === '') {
            $errors[] = 'Consumer key is required.';
        }
        if (trim((string) ($body['consumer_secret'] ?? '')) === '') {
            $errors[] = 'Consumer secret is required.';
        }
    }

    return $errors;
}
