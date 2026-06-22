<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/i18n.php';
require_once __DIR__ . '/Sites.php';

/**
 * Activity logging for site management changes (per-user logs visible to
 * admins/shop managers/superadmins) and system changes (superadmin only).
 */

const ACTIVITY_UNDO_WINDOW_SECONDS = 10;

/**
 * Records an activity log entry. $undoData (if provided) is stored as JSON
 * and can be replayed by undo_activity_log() within the undo window.
 */
function log_activity(array $user, ?int $siteId, string $category, string $action, string $messageKey, array $messageParams = [], ?array $undoData = null): int
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs
            (user_id, user_name, user_phone, site_id, category, action, message_key, message_params, undo_data, undone, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?)'
    );
    $stmt->execute([
        (int) $user['id'],
        (string) ($user['name'] ?? ''),
        (string) ($user['phone'] ?? ''),
        $siteId,
        $category,
        $action,
        $messageKey,
        json_encode($messageParams, JSON_UNESCAPED_UNICODE),
        $undoData !== null ? json_encode($undoData, JSON_UNESCAPED_UNICODE) : null,
        time(),
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Returns a single activity log row by id, or null.
 */
function find_activity_log(int $id): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM activity_logs WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns whether the given user is allowed to undo the given log entry
 * right now (ownership/role, not already undone, within the undo window,
 * and has undo data).
 */
function can_undo_activity_log(array $log, array $user): bool
{
    if ((int) $log['undone'] !== 0) {
        return false;
    }

    if ($log['undo_data'] === null || $log['undo_data'] === '') {
        return false;
    }

    $isOwner = (int) $log['user_id'] === (int) $user['id'];
    if (!$isOwner && $user['role'] !== 'superadmin') {
        return false;
    }

    return (time() - (int) $log['created_at']) <= ACTIVITY_UNDO_WINDOW_SECONDS;
}

/**
 * Marks an activity log entry as undone.
 */
function mark_activity_log_undone(int $id): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('UPDATE activity_logs SET undone = 1 WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Returns a page of activity logs visible to the given user, newest first.
 *
 * - superadmin: sees all logs; pass $scope = 'system' to see only system
 *   logs, 'site' for site-management logs from all users, or 'mine' for
 *   their own logs only. Default (null) returns everything.
 * - admin/shop_manager: sees site-management logs for every site they have
 *   access to (not just their own actions), so they can see who did what.
 */
function get_activity_logs(array $user, ?string $scope, int $page, int $perPage): array
{
    $pdo = Database::get();
    $page = max(1, $page);
    $perPage = max(1, min(100, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($user['role'] === 'superadmin') {
        if ($scope === 'system') {
            $where[] = 'category = ?';
            $params[] = 'system';
        } elseif ($scope === 'site') {
            $where[] = 'category = ?';
            $params[] = 'site';
        } elseif ($scope === 'mine') {
            $where[] = 'user_id = ?';
            $params[] = (int) $user['id'];
        }
    } else {
        $where[] = 'category = ?';
        $params[] = 'site';

        $siteIds = array_map(fn($site) => (int) $site['id'], list_sites_for_user($user));
        if (empty($siteIds)) {
            // No accessible sites - show nothing rather than leaking every
            // user's logs by leaving the site filter off entirely.
            $siteIds = [0];
        }
        $where[] = 'site_id IN (' . implode(',', array_fill(0, count($siteIds), '?')) . ')';
        foreach ($siteIds as $siteId) {
            $params[] = $siteId;
        }
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM activity_logs {$whereSql}");
    $countStmt->execute($params);
    $total = (int) $countStmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT * FROM activity_logs {$whereSql} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $items = array_map(fn($row) => format_activity_log($row, $user), $rows);

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'total_pages' => (int) max(1, ceil($total / $perPage)),
    ];
}

/**
 * Formats a raw activity_logs row for API output: translates the message,
 * and includes whether the requesting user can currently undo it.
 */
function format_activity_log(array $row, array $user): array
{
    $params = json_decode($row['message_params'], true);
    $params = is_array($params) ? $params : [];

    return [
        'id' => (int) $row['id'],
        'user_id' => (int) $row['user_id'],
        'user_name' => $row['user_name'],
        'user_phone' => $row['user_phone'],
        'site_id' => $row['site_id'] !== null ? (int) $row['site_id'] : null,
        'category' => $row['category'],
        'action' => $row['action'],
        'message' => t($row['message_key'], ...$params),
        'undone' => (bool) $row['undone'],
        'can_undo' => can_undo_activity_log($row, $user),
        'created_at' => (int) $row['created_at'],
    ];
}
