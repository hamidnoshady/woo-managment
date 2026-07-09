<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';
require_once __DIR__ . '/../../includes/Notifications.php';
require_once __DIR__ . '/../../includes/WebPush.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/i18n.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

$body = json_body();
$taskId = (int) ($body['id'] ?? 0);
$channel = (string) ($body['channel'] ?? '');

if (!in_array($channel, ['sms', 'push'], true)) {
    json_response(['error' => 'channel must be "sms" or "push"'], 422);
}

$task = find_task($taskId);
if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

$assignees = get_task_assignees($taskId);
if (empty($assignees)) {
    json_response(['error' => 'This task has no assignees'], 422);
}

$dueText = $task['due_date'] ?: '-';
$message = sprintf(
    '%s: %s (%s: %s, %s: %s)',
    t('task_notify_prefix'),
    $task['title'],
    t('task_due_date'),
    $dueText,
    t('task_priority'),
    t('task_priority_' . $task['priority'])
);

$results = [];
foreach ($assignees as $assignee) {
    if ($channel === 'sms') {
        $result = send_kavenegar_sms($assignee['phone'], $message);
        $results[] = ['user_id' => (int) $assignee['id'], 'ok' => $result['ok'], 'error' => $result['error'] ?? null];
        continue;
    }

    $subscriptions = get_push_subscriptions_for_user((int) $assignee['id']);
    if (empty($subscriptions)) {
        $results[] = ['user_id' => (int) $assignee['id'], 'ok' => false, 'error' => 'No push subscription'];
        continue;
    }

    $anyOk = false;
    foreach ($subscriptions as $subscription) {
        $result = send_web_push($subscription, [
            'title' => t('task_notify_push_title'),
            'body' => $message,
            'url' => '/tasks.php',
        ]);
        if ($result['gone']) {
            remove_push_subscription($subscription['endpoint']);
        }
        if ($result['ok']) {
            $anyOk = true;
        }
    }
    $results[] = ['user_id' => (int) $assignee['id'], 'ok' => $anyOk, 'error' => $anyOk ? null : 'Push delivery failed'];
}

$successCount = count(array_filter($results, fn($r) => $r['ok']));
log_activity(
    $user,
    (int) $site['id'],
    'task',
    $channel === 'sms' ? 'task_notify_sms' : 'task_notify_push',
    'log_task_notify',
    [(string) $successCount, $task['title']]
);

json_response(['ok' => true, 'results' => $results]);
