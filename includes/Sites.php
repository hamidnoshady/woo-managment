<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/WooCommerceClient.php';

/**
 * WooCommerce site management. Each site stores its own REST API
 * credentials; users (other than superadmins) are explicitly assigned to
 * the sites they may manage.
 */

function list_all_sites(): array
{
    $pdo = Database::get();
    return $pdo->query('SELECT id, name, store_url, verify_ssl, created_at FROM sites ORDER BY name ASC')->fetchAll();
}

/**
 * Returns the sites a user may access. Superadmins can access every site.
 */
function list_sites_for_user(array $user): array
{
    if ($user['role'] === 'superadmin') {
        return list_all_sites();
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'SELECT s.id, s.name, s.store_url, s.verify_ssl, s.created_at
         FROM sites s
         INNER JOIN user_sites us ON us.site_id = s.id
         WHERE us.user_id = ?
         ORDER BY s.name ASC'
    );
    $stmt->execute([$user['id']]);
    return $stmt->fetchAll();
}

/**
 * Returns the full site row (including API credentials) or null.
 */
function get_site(int $id): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM sites WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Checks whether a user may access the given site.
 */
function user_can_access_site(array $user, int $siteId): bool
{
    if ($user['role'] === 'superadmin') {
        return get_site($siteId) !== null;
    }

    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT 1 FROM user_sites WHERE user_id = ? AND site_id = ?');
    $stmt->execute([$user['id'], $siteId]);
    return (bool) $stmt->fetchColumn();
}

function create_site(array $data): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO sites (name, store_url, consumer_key, consumer_secret, verify_ssl, created_at)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $data['name'],
        rtrim($data['store_url'], '/'),
        $data['consumer_key'],
        $data['consumer_secret'],
        !empty($data['verify_ssl']) ? 1 : 0,
        time(),
    ]);

    return get_site((int) $pdo->lastInsertId());
}

function update_site(int $id, array $data): array
{
    $fields = [];
    $params = [];

    $map = [
        'name' => 'name',
        'store_url' => 'store_url',
        'consumer_key' => 'consumer_key',
        'consumer_secret' => 'consumer_secret',
    ];

    foreach ($map as $key => $column) {
        if (array_key_exists($key, $data) && $data[$key] !== '') {
            $value = $data[$key];
            if ($key === 'store_url') {
                $value = rtrim($value, '/');
            }
            $fields[] = "{$column} = ?";
            $params[] = $value;
        }
    }

    if (array_key_exists('verify_ssl', $data)) {
        $fields[] = 'verify_ssl = ?';
        $params[] = !empty($data['verify_ssl']) ? 1 : 0;
    }

    if (!empty($fields)) {
        $params[] = $id;
        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE sites SET ' . implode(', ', $fields) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    return get_site($id);
}

function delete_site(int $id): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('DELETE FROM sites WHERE id = ?');
    $stmt->execute([$id]);
}

/**
 * Builds a WooCommerceClient for the given site.
 */
function woocommerce_client_for_site(array $site): WooCommerceClient
{
    return new WooCommerceClient([
        'store_url' => $site['store_url'],
        'consumer_key' => $site['consumer_key'],
        'consumer_secret' => $site['consumer_secret'],
        'verify_ssl' => (bool) $site['verify_ssl'],
    ]);
}
