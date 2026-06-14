<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Users.php';
require_once __DIR__ . '/../../includes/Sites.php';

$currentUser = require_superadmin_api();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['items' => array_map('map_user', list_users())]);
}

verify_csrf_api();
$body = json_body();

if ($method === 'POST') {
    $phone = normalize_phone($body['phone'] ?? '');
    $name = trim((string) ($body['name'] ?? ''));
    $role = (string) ($body['role'] ?? '');

    if ($phone === '') {
        json_response(['error' => 'A valid phone number is required.'], 422);
    }

    if (!in_array($role, VALID_ROLES, true)) {
        json_response(['error' => 'Invalid role.'], 422);
    }

    if (find_user_by_phone($phone) !== null) {
        json_response(['error' => 'A user with this phone number already exists.'], 422);
    }

    $user = create_user($phone, $name, $role);

    if (isset($body['site_ids']) && is_array($body['site_ids'])) {
        set_user_sites((int) $user['id'], $body['site_ids']);
    }

    json_response(['item' => map_user(find_user_with_sites((int) $user['id']))], 201);
}

if ($method === 'PUT') {
    $id = (int) ($_GET['id'] ?? 0);
    $target = find_user_by_id($id);
    if ($target === null) {
        json_response(['error' => 'User not found'], 404);
    }

    $data = [];

    if (isset($body['name'])) {
        $data['name'] = trim((string) $body['name']);
    }

    if (isset($body['role'])) {
        $role = (string) $body['role'];
        if (!in_array($role, VALID_ROLES, true)) {
            json_response(['error' => 'Invalid role.'], 422);
        }

        // Prevent a superadmin from demoting themselves and getting locked out.
        if ($id === $currentUser['id'] && $role !== 'superadmin') {
            json_response(['error' => 'You cannot change your own role.'], 422);
        }

        $data['role'] = $role;
    }

    if (!empty($data)) {
        update_user($id, $data);
    }

    if (isset($body['site_ids']) && is_array($body['site_ids'])) {
        set_user_sites($id, $body['site_ids']);
    }

    json_response(['item' => map_user(find_user_with_sites($id))]);
}

if ($method === 'DELETE') {
    $id = (int) ($_GET['id'] ?? 0);
    $target = find_user_by_id($id);
    if ($target === null) {
        json_response(['error' => 'User not found'], 404);
    }

    if ($id === $currentUser['id']) {
        json_response(['error' => 'You cannot delete your own account.'], 422);
    }

    delete_user($id);
    json_response(['ok' => true]);
}

json_response(['error' => 'Method not allowed'], 405);

function find_user_with_sites(int $id): array
{
    $user = find_user_by_id($id);
    $user['site_ids'] = get_user_site_ids($id);
    return $user;
}

function map_user(array $user): array
{
    return [
        'id' => (int) $user['id'],
        'phone' => $user['phone'],
        'name' => $user['name'],
        'role' => $user['role'],
        'site_ids' => $user['site_ids'] ?? [],
    ];
}
