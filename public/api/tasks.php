<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['items' => list_tasks_for_site($user, (int) $site['id'])]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

$body = json_body();
$title = trim((string) ($body['title'] ?? ''));

if ($title === '') {
    json_response(['error' => 'Title is required'], 422);
}

$body['title'] = $title;
$task = create_task($user, (int) $site['id'], $body);

log_activity($user, (int) $site['id'], 'task', 'task_create', 'log_task_created', [$title]);

json_response(['item' => $task], 201);
