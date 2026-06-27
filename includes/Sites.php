<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/SiteAgentClient.php';

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
    $stmt = $pdo->prepare('INSERT INTO sites (name, store_url, verify_ssl, created_at) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $data['name'],
        rtrim($data['store_url'], '/'),
        !empty($data['verify_ssl']) ? 1 : 0,
        time(),
    ]);

    return get_site((int) $pdo->lastInsertId());
}

function update_site(int $id, array $data): array
{
    $fields = [];
    $params = [];

    $map = ['name' => 'name', 'store_url' => 'store_url', 'backup_schedule' => 'backup_schedule'];
    foreach ($map as $key => $column) {
        if (array_key_exists($key, $data) && $data[$key] !== '') {
            $value = $key === 'store_url' ? rtrim($data[$key], '/') : $data[$key];
            $fields[] = "{$column} = ?";
            $params[] = $value;
        }
    }

    if (array_key_exists('verify_ssl', $data)) {
        $fields[] = 'verify_ssl = ?';
        $params[] = !empty($data['verify_ssl']) ? 1 : 0;
    }
    if (array_key_exists('backup_enabled', $data)) {
        $fields[] = 'backup_enabled = ?';
        $params[] = !empty($data['backup_enabled']) ? 1 : 0;
    }
    if (array_key_exists('backup_retention_days', $data)) {
        $fields[] = 'backup_retention_days = ?';
        $params[] = max(1, (int) $data['backup_retention_days']);
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
 * Builds a SiteAgentClient for the given site — the sole connection method
 * for products, categories, taxonomies, media, and backup/restore.
 */
function site_agent_client_for_site(array $site): SiteAgentClient
{
    return new SiteAgentClient($site);
}

/**
 * Generates a new pairing token for the site's woo-mgmt-agent plugin,
 * invalidating any previous one immediately. Returns the plaintext token —
 * shown once to the admin, then only ever used internally for outbound
 * calls to the plugin.
 */
function generate_agent_token(int $siteId): string
{
    $token = bin2hex(random_bytes(32));
    $pdo = Database::get();
    $stmt = $pdo->prepare('UPDATE sites SET agent_token = ?, agent_paired_at = NULL WHERE id = ?');
    $stmt->execute([$token, $siteId]);
    return $token;
}

/**
 * Records that the plugin has successfully responded to a call, for the
 * "agent connected / never connected / stale" indicator in the UI.
 */
function mark_agent_seen(int $siteId): void
{
    $pdo = Database::get();
    $now = time();
    $stmt = $pdo->prepare(
        'UPDATE sites SET agent_last_seen_at = ?, agent_paired_at = COALESCE(agent_paired_at, ?) WHERE id = ?'
    );
    $stmt->execute([$now, $now, $siteId]);
}

/**
 * Resolves a site by its agent pairing token (constant-time comparison
 * against every site's token — the table is small enough that scanning is
 * simpler and safer than indexing a secret column).
 */
function get_site_by_agent_token(string $token): ?array
{
    if ($token === '') {
        return null;
    }
    $pdo = Database::get();
    foreach ($pdo->query("SELECT * FROM sites WHERE agent_token != ''")->fetchAll() as $site) {
        if (hash_equals($site['agent_token'], $token)) {
            return $site;
        }
    }
    return null;
}
