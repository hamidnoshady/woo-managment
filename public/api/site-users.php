<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Users.php';

$user = require_login_api();
$site = require_site_api($user);

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$siteId = (int) $site['id'];
$users = array_values(array_filter(list_users(), fn($u) => in_array($siteId, $u['site_ids'], true)));

json_response(['items' => array_map(fn($u) => [
    'id' => (int) $u['id'],
    'name' => $u['name'],
    'phone' => $u['phone'],
    'role' => $u['role'],
], $users)]);
