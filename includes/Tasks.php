<?php

require_once __DIR__ . '/Database.php';

/**
 * Task management: creation, assignment, comments. Site-scoped like
 * products/activity logs.
 */

const TASK_PRIORITIES = ['low', 'medium', 'high'];
const TASK_STATUSES = ['todo', 'in_progress', 'done'];

/**
 * Lists tasks for the given site, newest first. shop_managers only see
 * tasks they're assigned to; superadmin/admin see every task on the site.
 */
function list_tasks_for_site(array $user, int $siteId): array
{
    $pdo = Database::get();

    if ($user['role'] === 'shop_manager') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT t.* FROM tasks t
             INNER JOIN task_assignees ta ON ta.task_id = t.id
             WHERE t.site_id = ? AND ta.user_id = ?
             ORDER BY t.id DESC'
        );
        $stmt->execute([$siteId, $user['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE site_id = ? ORDER BY id DESC');
        $stmt->execute([$siteId]);
    }

    return array_map('format_task', $stmt->fetchAll());
}

function find_task(int $id): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_task_assignees(int $taskId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.phone FROM task_assignees ta
         INNER JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id = ?
         ORDER BY u.name ASC'
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll();
}

function user_is_task_assignee(int $taskId, int $userId): bool
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ?');
    $stmt->execute([$taskId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Replaces the full set of assignees for a task.
 */
function set_task_assignees(int $taskId, array $userIds): void
{
    $pdo = Database::get();
    $pdo->beginTransaction();

    $delete = $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?');
    $delete->execute([$taskId]);

    $insert = $pdo->prepare('INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId > 0) {
            $insert->execute([$taskId, $userId]);
        }
    }

    $pdo->commit();
}

function create_task(array $user, int $siteId, array $data): array
{
    $pdo = Database::get();
    $now = time();

    $priority = in_array($data['priority'] ?? '', TASK_PRIORITIES, true) ? $data['priority'] : 'medium';
    $dueDate = !empty($data['due_date']) ? (string) $data['due_date'] : null;

    $stmt = $pdo->prepare(
        "INSERT INTO tasks (site_id, title, description, due_date, priority, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'todo', ?, ?, ?)"
    );
    $stmt->execute([
        $siteId,
        (string) $data['title'],
        (string) ($data['description'] ?? ''),
        $dueDate,
        $priority,
        (int) $user['id'],
        $now,
        $now,
    ]);

    $taskId = (int) $pdo->lastInsertId();

    if (!empty($data['assignee_ids']) && is_array($data['assignee_ids'])) {
        set_task_assignees($taskId, $data['assignee_ids']);
    }

    return get_task_with_assignees($taskId);
}

/**
 * Updates the given fields on a task. Only keys present in $fields are
 * touched. 'assignee_ids' (if present) replaces the assignee set via
 * set_task_assignees() rather than a column update.
 */
function update_task_fields(int $id, array $fields): array
{
    $columns = [];
    $params = [];

    $map = ['title' => 'title', 'description' => 'description', 'due_date' => 'due_date', 'priority' => 'priority', 'status' => 'status'];
    foreach ($map as $key => $column) {
        if (array_key_exists($key, $fields)) {
            $columns[] = "{$column} = ?";
            $params[] = $fields[$key];
        }
    }

    if (!empty($columns)) {
        $columns[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $id;

        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE tasks SET ' . implode(', ', $columns) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    if (array_key_exists('assignee_ids', $fields) && is_array($fields['assignee_ids'])) {
        set_task_assignees($id, $fields['assignee_ids']);
    }

    return get_task_with_assignees($id);
}

function get_task_with_assignees(int $id): ?array
{
    $task = find_task($id);
    return $task !== null ? format_task($task) : null;
}

function format_task(array $task): array
{
    $assignees = get_task_assignees((int) $task['id']);

    return [
        'id' => (int) $task['id'],
        'site_id' => (int) $task['site_id'],
        'title' => $task['title'],
        'description' => $task['description'] ?? '',
        'due_date' => $task['due_date'],
        'priority' => $task['priority'],
        'status' => $task['status'],
        'created_by' => (int) $task['created_by'],
        'created_at' => (int) $task['created_at'],
        'updated_at' => (int) $task['updated_at'],
        'assignees' => array_map(
            fn($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'phone' => $u['phone']],
            $assignees
        ),
    ];
}

function list_task_comments(int $taskId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name AS user_name FROM task_comments c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.task_id = ?
         ORDER BY c.id ASC'
    );
    $stmt->execute([$taskId]);

    return array_map(fn($row) => [
        'id' => (int) $row['id'],
        'task_id' => (int) $row['task_id'],
        'user_id' => (int) $row['user_id'],
        'user_name' => $row['user_name'],
        'body' => $row['body'],
        'created_at' => (int) $row['created_at'],
    ], $stmt->fetchAll());
}

function add_task_comment(int $taskId, array $user, string $body): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)');
    $now = time();
    $stmt->execute([$taskId, (int) $user['id'], $body, $now]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'task_id' => $taskId,
        'user_id' => (int) $user['id'],
        'user_name' => $user['name'],
        'body' => $body,
        'created_at' => $now,
    ];
}
