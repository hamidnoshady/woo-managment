<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$id = (int) ($body['id'] ?? 0);
$task = find_task($id);

if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

$isManager = in_array($user['role'], ['superadmin', 'admin'], true);

if (!$isManager) {
    if (!user_is_task_assignee($id, (int) $user['id'])) {
        json_response(['error' => 'Not allowed'], 403);
    }
    foreach (array_keys($body) as $key) {
        if ($key !== 'id' && $key !== 'status') {
            json_response(['error' => 'You can only change the status of this task'], 403);
        }
    }
}

$fields = [];

if ($isManager && isset($body['title'])) {
    $fields['title'] = trim((string) $body['title']);
}
if ($isManager && isset($body['description'])) {
    $fields['description'] = (string) $body['description'];
}
if ($isManager && isset($body['due_date'])) {
    $fields['due_date'] = $body['due_date'] !== '' ? (string) $body['due_date'] : null;
}
if ($isManager && isset($body['priority'])) {
    if (!in_array($body['priority'], TASK_PRIORITIES, true)) {
        json_response(['error' => 'Invalid priority'], 422);
    }
    $fields['priority'] = $body['priority'];
}
if ($isManager && isset($body['assignee_ids']) && is_array($body['assignee_ids'])) {
    $fields['assignee_ids'] = $body['assignee_ids'];
}
if (isset($body['status'])) {
    if (!in_array($body['status'], TASK_STATUSES, true)) {
        json_response(['error' => 'Invalid status'], 422);
    }
    $fields['status'] = $body['status'];
}

$updated = update_task_fields($id, $fields);

json_response(['item' => $updated]);
