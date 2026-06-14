<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';

/**
 * User account management (superadmin / admin / shop_manager).
 * Users are stored in the SQLite database; superadmins are bootstrapped
 * from config('superadmins') on first login.
 */

const VALID_ROLES = ['superadmin', 'admin', 'shop_manager'];

function find_user_by_phone(string $phone): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE phone = ?');
    $stmt->execute([$phone]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function find_user_by_id(int $id): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Returns the user record for $phone, creating it as a superadmin if the
 * phone number is listed in config('superadmins') and no account exists yet.
 */
function bootstrap_user_by_phone(string $phone): ?array
{
    $existing = find_user_by_phone($phone);
    if ($existing !== null) {
        return $existing;
    }

    $config = app_config();
    $superadmins = $config['superadmins'] ?? [];

    if (!in_array($phone, $superadmins, true)) {
        return null;
    }

    return create_user($phone, '', 'superadmin');
}

function create_user(string $phone, string $name, string $role): array
{
    if (!in_array($role, VALID_ROLES, true)) {
        throw new InvalidArgumentException('Invalid role');
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO users (phone, name, role, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([$phone, $name, $role, time()]);

    return find_user_by_id((int) $pdo->lastInsertId());
}

function update_user(int $id, array $data): array
{
    $fields = [];
    $params = [];

    if (array_key_exists('name', $data)) {
        $fields[] = 'name = ?';
        $params[] = (string) $data['name'];
    }

    if (array_key_exists('role', $data)) {
        if (!in_array($data['role'], VALID_ROLES, true)) {
            throw new InvalidArgumentException('Invalid role');
        }
        $fields[] = 'role = ?';
        $params[] = $data['role'];
    }

    if (!empty($fields)) {
        $params[] = $id;
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    return find_user_by_id($id);
}

function delete_user(int $id): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Lists all users along with the IDs of sites assigned to them.
 */
function list_users(): array
{
    $pdo = Database::get();
    $users = $pdo->query('SELECT * FROM users ORDER BY created_at ASC')->fetchAll();

    $assignments = $pdo->query('SELECT user_id, site_id FROM user_sites')->fetchAll();
    $siteMap = [];
    foreach ($assignments as $row) {
        $siteMap[$row['user_id']][] = (int) $row['site_id'];
    }

    foreach ($users as &$user) {
        $user['site_ids'] = $siteMap[$user['id']] ?? [];
    }

    return $users;
}

/**
 * Replaces the set of sites assigned to a user.
 */
function set_user_sites(int $userId, array $siteIds): void
{
    $pdo = Database::get();
    $pdo->beginTransaction();

    $delete = $pdo->prepare('DELETE FROM user_sites WHERE user_id = ?');
    $delete->execute([$userId]);

    $insert = $pdo->prepare('INSERT INTO user_sites (user_id, site_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $siteIds)) as $siteId) {
        if ($siteId > 0) {
            $insert->execute([$userId, $siteId]);
        }
    }

    $pdo->commit();
}

function get_user_site_ids(int $userId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT site_id FROM user_sites WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}
