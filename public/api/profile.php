<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Users.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/i18n.php';

$user = require_login_api();
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'PUT') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$name = trim((string) ($body['name'] ?? ''));

if ($name === '') {
    json_response(['error' => t('name_required')], 422);
}

update_user((int) $user['id'], ['name' => $name]);
log_activity($user, null, 'system', 'profile_update', 'log_profile_updated', [$name]);

json_response(['ok' => true, 'name' => $name]);
