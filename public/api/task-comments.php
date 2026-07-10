<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';

$user = require_login_api();
$site = require_site_api($user);

$taskId = (int) ($_GET['task_id'] ?? 0);
$task = find_task($taskId);

if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

if ($user['role'] === 'shop_manager' && !user_is_task_assignee($taskId, (int) $user['id'])) {
    json_response(['error' => 'Not allowed'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['items' => list_task_comments($taskId)]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$text = trim((string) ($body['body'] ?? ''));

if ($text === '') {
    json_response(['error' => 'Comment cannot be empty'], 422);
}

$comment = add_task_comment($taskId, $user, $text);
json_response(['item' => $comment], 201);
