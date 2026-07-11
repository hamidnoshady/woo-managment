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
function log_activity(array $user, ?int $siteId, string $category, string $action, string $messageKey, array $messageParams = [], ?array $undoData = null, ?int $batchJobId = null): int
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO activity_logs
            (user_id, user_name, user_phone, site_id, category, action, message_key, message_params, undo_data, undone, created_at, batch_job_id)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, ?, ?)'
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
        $batchJobId,
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
 *
 * $siteId, if given, narrows to a single site (a no-op if the user doesn't
 * have access to it - the base site filter above already excludes it for
 * non-superadmins). $filterUserId, if given, narrows to a single user.
 */
function get_activity_logs(array $user, ?string $scope, int $page, int $perPage, ?int $siteId = null, ?int $filterUserId = null): array
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
        foreach ($siteIds as $sid) {
            $params[] = $sid;
        }
    }

    if ($siteId !== null) {
        $where[] = 'site_id = ?';
        $params[] = $siteId;
    }

    if ($filterUserId !== null) {
        $where[] = 'user_id = ?';
        $params[] = $filterUserId;
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
        'batch_job_id' => isset($row['batch_job_id']) && $row['batch_job_id'] !== null ? (int) $row['batch_job_id'] : null,
    ];
}

/**
 * Buckets recent product mutations into highlight categories for the
 * products list UI: for each of $productIds, returns the most recent
 * qualifying change (if any) within the last $sinceTimestamp seconds,
 * excluding undone changes. Categories: 'price', 'stock', 'batch_price',
 * 'batch_stock', 'new_product', 'new_variation'.
 *
 * @return array<int, array{category: string, changed_at: int}>
 */
function get_recent_product_changes(int $siteId, array $productIds, int $sinceTimestamp): array
{
    if (empty($productIds)) {
        return [];
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare(
        "SELECT * FROM activity_logs
         WHERE site_id = ? AND category = 'site' AND undone = 0 AND created_at > ?
           AND action IN ('product_update', 'stock_update', 'batch_price', 'batch_stock', 'product_create', 'variation_create')
         ORDER BY created_at ASC"
    );
    $stmt->execute([$siteId, $sinceTimestamp]);
    $rows = $stmt->fetchAll();

    // Iterating oldest-to-newest and simply overwriting each product's map
    // entry means the last write for a given id is always its most recent
    // change - "most recent wins" falls out of the loop order for free.
    $map = [];

    foreach ($rows as $row) {
        $undoData = json_decode((string) $row['undo_data'], true);
        if (!is_array($undoData)) {
            continue;
        }
        $createdAt = (int) $row['created_at'];

        switch ($row['action']) {
            case 'stock_update':
                $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'stock', 'changed_at' => $createdAt];
                break;

            case 'product_update':
                $changedFields = is_array($undoData['data'] ?? null) ? array_keys($undoData['data']) : [];
                if (array_intersect($changedFields, ['regular_price', 'sale_price'])) {
                    $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'price', 'changed_at' => $createdAt];
                }
                break;

            case 'product_create':
                $map[(int) ($undoData['product_id'] ?? 0)] = ['category' => 'new_product', 'changed_at' => $createdAt];
                break;

            case 'variation_create':
                if (isset($undoData['parent_id'])) {
                    $map[(int) $undoData['parent_id']] = ['category' => 'new_variation', 'changed_at' => $createdAt];
                }
                break;

            case 'batch_price':
            case 'batch_stock':
                foreach ((array) ($undoData['updates'] ?? []) as $update) {
                    if (isset($update['id'])) {
                        $map[(int) $update['id']] = ['category' => $row['action'], 'changed_at' => $createdAt];
                    }
                }
                break;
        }
    }

    unset($map[0]);

    $wantedIds = array_flip(array_map('intval', $productIds));
    return array_intersect_key($map, $wantedIds);
}
