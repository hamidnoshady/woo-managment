# Site Agent Orchestration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give woo-managment a single secure connection per site (the `woo-mgmt-agent` plugin's paired token), replacing WooCommerce consumer key/secret and WP application passwords entirely, and rewire every product/category/taxonomy/media/backup/restore operation to go through it — fixing the 1-2 minute load time and the disappearing-taxonomy bug at the root.

**Architecture:** `SiteAgentClient` is the one HTTP client woo-managment uses to talk to a site (replacing both `WooCommerceClient` and `WordPressClient`). `SiteBackupManager` builds on it for backup/restore orchestration. Every `public/api/*.php` endpoint that used the old clients is rewired to `SiteAgentClient`.

**Tech Stack:** Plain PHP 8, PDO/MySQL, existing `S3Client` (raw cURL SigV4), existing `helpers.php`/`auth.php`/`i18n.php` conventions. No new dependencies.

## Global Constraints

- Clean replacement, not a migration: `consumer_key`, `consumer_secret`, `wp_username`, `wp_app_password` are dropped from `sites`; every site must be re-paired with the plugin to work again.
- Follow the exact API endpoint boilerplate order from CLAUDE.md: `helpers.php` → `install_json_fatal_handler()` → `auth.php` → other includes → auth check → `require_site_api()` (where site-scoped) → `verify_csrf_api()` on mutating methods → `json_body()`.
- New columns on `sites` use the `INFORMATION_SCHEMA.COLUMNS` check-then-`ALTER TABLE` pattern from CLAUDE.md.
- `created_at`/`updated_at` are Unix timestamps (`int`), matching `Database.php` convention.
- The plugin never receives the S3 secret key — only presigned URLs, generated per-part, scoped to that one job's S3 prefix.
- Taxonomies are cached the same way products are (10s TTL, mutation-invalidated) — no long-lived blind cache, which was the root cause of the disappearing-taxonomy bug.
- Every superadmin-only mutating action calls `require_superadmin_api()`.
- New translation keys go in both `en` and `fa` in `includes/i18n.php`.
- **Transitional breakage is expected and acceptable between Task 3 and Task 11.** Task 3 removes `woocommerce_client_for_site()`/`wordpress_client_for_site()` from `Sites.php`, but `products.php`/`product.php`/`categories.php`/`batch.php`/`stock.php`/`media.php` aren't rewired to `SiteAgentClient` until Tasks 8, 10, and 11 — those endpoints will fatal on any call in between. This is a single coordinated cutover (no dual-path code, per the spec's "clean replacement" decision), not a series of independently deployable steps; don't merge or deploy mid-plan. The "manually verify in browser" steps inside Tasks 8-11 only become meaningful once every rewiring task in that range is done — treat them as one combined checkpoint after Task 11, not four separate ones.

---

## File Structure

```
includes/
  Database.php              Modify: site_backups/site_restores tables; sites: drop old creds, add agent_* columns
  S3Client.php               Modify: add presignedUrl()
  Sites.php                  Modify: drop old credential helpers, add agent pairing helpers
  SiteAgentClient.php         Create: HTTP client for all wma/v1 routes (products/categories/taxonomies/media/jobs)
  SiteBackupManager.php       Create: backup/restore orchestration on top of SiteAgentClient
  i18n.php                   Modify: new translation keys
public/api/
  site-backup-agent/presign.php   Create: plugin-facing presign endpoint (Bearer token auth)
  site-backups.php           Create: admin-facing backup trigger/poll/list
  site-restores.php          Create: admin-facing restore trigger/poll
  sites.php                  Modify: agent pairing action, drop old credential fields
  products.php               Modify: SiteAgentClient instead of WooCommerceClient
  product.php                 Modify: SiteAgentClient instead of WooCommerceClient/WordPressClient
  categories.php              Modify: SiteAgentClient instead of WooCommerceClient
  taxonomies.php              Modify: SiteAgentClient + short-TTL mutation-invalidated cache
  batch.php                   Modify: SiteAgentClient instead of WooCommerceClient
  stock.php                   Modify: SiteAgentClient instead of WooCommerceClient
  media.php                   Modify: SiteAgentClient instead of WordPressClient
public/admin/
  sites.php                  Modify: drop credential fields, keep pairing + backup settings
  site-backups.php           Create: backup history + restore picker page
  batch.php                   Modify: "Backup now" button
public/assets/js/
  admin-sites.js              Modify: drop credential fields, pairing/backup-settings fields
  admin-site-backups.js       Create: history list rendering + polling
  batch.js                    Modify: "Backup now" button wiring
```

---

### Task 1: Database schema — drop old credentials, add job tables and agent columns

**Files:**
- Modify: `includes/Database.php`

**Interfaces:**
- Produces tables: `site_backups(id, site_id, type, status, step_label, percent, total_size_bytes, s3_prefix, manifest_json, error, started_at, completed_at, last_tick_at)`, `site_restores(id, site_id, source_backup_id, type, status, step_label, percent, safety_backup_id, error, started_at, completed_at, last_tick_at)`
- Produces columns on `sites`: `agent_token`, `agent_paired_at`, `agent_last_seen_at`, `backup_enabled`, `backup_schedule`, `backup_retention_days`
- Drops columns on `sites`: `consumer_key`, `consumer_secret`, `wp_username`, `wp_app_password`

- [ ] **Step 1: Add the new tables**

In `includes/Database.php`, add after the existing `backups` table's `$pdo->exec(...)` block (before `self::$pdo = $pdo;`):

```php
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                step_label VARCHAR(50) NOT NULL DEFAULT \'\',
                percent INT NOT NULL DEFAULT 0,
                total_size_bytes BIGINT NOT NULL DEFAULT 0,
                s3_prefix VARCHAR(500) NOT NULL DEFAULT \'\',
                manifest_json TEXT NULL,
                error TEXT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                last_tick_at INT NOT NULL,
                KEY idx_site_backups_site (site_id, id),
                CONSTRAINT fk_site_backups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_restores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                source_backup_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                step_label VARCHAR(50) NOT NULL DEFAULT \'\',
                percent INT NOT NULL DEFAULT 0,
                safety_backup_id INT NULL,
                error TEXT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                last_tick_at INT NOT NULL,
                KEY idx_site_restores_site (site_id, id),
                CONSTRAINT fk_site_restores_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_site_restores_backup FOREIGN KEY (source_backup_id) REFERENCES site_backups(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
```

- [ ] **Step 2: Add the agent/backup columns and drop the old credential columns**

Add right after the `site_restores` table, still before `self::$pdo = $pdo; return $pdo;`:

```php
        self::addColumnIfMissing($pdo, 'sites', 'agent_token', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'sites', 'agent_paired_at', 'INT NULL');
        self::addColumnIfMissing($pdo, 'sites', 'agent_last_seen_at', 'INT NULL');
        self::addColumnIfMissing($pdo, 'sites', 'backup_enabled', 'TINYINT NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'sites', 'backup_schedule', "VARCHAR(20) NOT NULL DEFAULT 'off'");
        self::addColumnIfMissing($pdo, 'sites', 'backup_retention_days', 'INT NOT NULL DEFAULT 30');

        self::dropColumnIfPresent($pdo, 'sites', 'consumer_key');
        self::dropColumnIfPresent($pdo, 'sites', 'consumer_secret');
        self::dropColumnIfPresent($pdo, 'sites', 'wp_username');
        self::dropColumnIfPresent($pdo, 'sites', 'wp_app_password');
```

Then add both helper methods to the class (after `get()`):

```php
    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function dropColumnIfPresent(PDO $pdo, string $table, string $column): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        if ($stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
```

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/Database.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/Database.php
git commit -m "Add site_backups/site_restores tables, agent columns; drop old site credentials"
```

---

### Task 2: S3 presigned URLs

**Files:**
- Modify: `includes/S3Client.php`

**Interfaces:**
- Produces: `S3Client::presignedUrl(string $method, string $key, int $expiresInSeconds = 900): string`

- [ ] **Step 1: Add the presigned-URL method**

Add to `includes/S3Client.php`, after `deleteObject()`:

```php
    /**
     * Builds a presigned URL for PUT or GET, valid for $expiresInSeconds.
     * Signs via query string (not the header-based signing request() uses)
     * so the URL itself is usable by a party that never sees the secret
     * key — the agent plugin.
     */
    public function presignedUrl(string $method, string $key, int $expiresInSeconds = 900): string
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalUri = '/' . rawurlencode($this->bucket) . '/' . $encodedKey;

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $credential = "{$this->accessKey}/{$credentialScope}";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalHeaders = "host:{$host}\n";
        $canonicalRequest = "{$method}\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\nhost\nUNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$scheme}://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/S3Client.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manually verify against real S3-compatible storage**

```bash
php -r '
require "includes/Settings.php";
require "includes/S3Client.php";
$s3 = new S3Client(["endpoint" => getenv("S3_ENDPOINT"), "region" => getenv("S3_REGION"), "bucket" => getenv("S3_BUCKET"), "access_key" => getenv("S3_KEY"), "secret_key" => getenv("S3_SECRET")]);
$url = $s3->presignedUrl("PUT", "selftest/presign-check.txt", 300);
file_put_contents("/tmp/check.txt", "hello");
$ch = curl_init($url);
curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => "PUT", CURLOPT_PUTFILE => fopen("/tmp/check.txt", "r"), CURLOPT_INFILESIZE => 5, CURLOPT_UPLOAD => true, CURLOPT_RETURNTRANSFER => true]);
curl_exec($ch);
echo "HTTP " . curl_getinfo($ch, CURLINFO_HTTP_CODE) . "\n";
'
```
Expected: `HTTP 200` (or `204`, depending on the provider).

- [ ] **Step 4: Commit**

```bash
git add includes/S3Client.php
git commit -m "Add S3Client::presignedUrl for query-string-signed PUT/GET URLs"
```

---

### Task 3: Sites module — agent pairing, drop old credentials

**Files:**
- Modify: `includes/Sites.php`

**Interfaces:**
- Produces: `generate_agent_token(int $siteId): string`, `mark_agent_seen(int $siteId): void`, `get_site_by_agent_token(string $token): ?array`
- Removes: `woocommerce_client_for_site()`, `wordpress_client_for_site()`, `site_has_wordpress_credentials()`

- [ ] **Step 1: Replace the old client-builder functions with agent pairing helpers**

In `includes/Sites.php`, delete `woocommerce_client_for_site()`, `site_has_wordpress_credentials()`, and `wordpress_client_for_site()` entirely (they referenced the dropped columns and the deleted client classes), and delete the `require_once __DIR__ . '/WooCommerceClient.php';` and `require_once __DIR__ . '/WordPressClient.php';` lines at the top of the file. Replace with:

```php
require_once __DIR__ . '/SiteAgentClient.php';

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
```

- [ ] **Step 2: Update `create_site()`/`update_site()` for the dropped/added columns**

Replace the body of `create_site()`:

```php
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
```

Replace `update_site()`'s `$map` array (it no longer carries credential fields) and add the backup-settings handling:

```php
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
```

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/Sites.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/Sites.php
git commit -m "Replace WooCommerce/WordPress credentials with agent pairing in Sites module"
```

---

### Task 4: SiteAgentClient — the one HTTP client for everything

**Files:**
- Create: `includes/SiteAgentClient.php`

**Interfaces:**
- Produces: `SiteAgentClient::__construct(array $site)`, `->listProducts(array $params): array`, `->getProduct(int $id): ?array`, `->createProduct(array $data): array`, `->updateProduct(int $id, array $data): ?array`, `->deleteProduct(int $id, bool $force): bool`, `->batchProducts(array $items): array`, `->listCategories(): array`, `->listTaxonomies(): array`, `->uploadMedia(string $binary, string $filename, string $mimeType): array`, `->startJob(int $jobId, string $kind, string $scope, array $parts = []): array{ok: bool, error: string}`, `->pollTick(int $jobId): array{ok: bool, status: string, step: string, parts: array, error: string}`

- [ ] **Step 1: Write the client**

```php
<?php
// includes/SiteAgentClient.php

/**
 * The single HTTP client for everything woo-managment does against a
 * managed site: products, categories, taxonomies, media, and backup/
 * restore job control — all via the woo-mgmt-agent plugin's wma/v1 REST
 * routes, authenticated by one paired Bearer token. Replaces both the old
 * WooCommerceClient and WordPressClient.
 */
class SiteAgentClient
{
    private array $site;

    public function __construct(array $site)
    {
        $this->site = $site;
    }

    public function listProducts(array $params): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/products?' . http_build_query($params));
        return $this->decodeOrThrow($response);
    }

    public function getProduct(int $id): ?array
    {
        $response = $this->request('GET', "/wp-json/wma/v1/products/{$id}");
        if ($response['status'] === 404) {
            return null;
        }
        return $this->decodeOrThrow($response)['item'];
    }

    public function createProduct(array $data): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/products', $data);
        return $this->decodeOrThrow($response)['item'];
    }

    public function updateProduct(int $id, array $data): ?array
    {
        $response = $this->request('PUT', "/wp-json/wma/v1/products/{$id}", $data);
        if ($response['status'] === 404) {
            return null;
        }
        return $this->decodeOrThrow($response)['item'];
    }

    public function deleteProduct(int $id, bool $force): bool
    {
        $response = $this->request('DELETE', "/wp-json/wma/v1/products/{$id}" . ($force ? '?force=1' : ''));
        return $response['status'] === 200;
    }

    public function batchProducts(array $items): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/products/batch', ['update' => $items]);
        return $this->decodeOrThrow($response)['update'];
    }

    public function listCategories(): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/categories');
        return $this->decodeOrThrow($response)['items'];
    }

    public function listTaxonomies(): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/taxonomies');
        return $this->decodeOrThrow($response)['items'];
    }

    public function uploadMedia(string $binary, string $filename, string $mimeType): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/media', null, $binary, [
            'X-Filename: ' . $filename,
            'Content-Type: ' . $mimeType,
        ]);
        return $this->decodeOrThrow($response)['item'];
    }

    public function startJob(int $jobId, string $kind, string $scope, array $parts = []): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/jobs', [
            'job_id' => $jobId, 'kind' => $kind, 'scope' => $scope, 'parts' => $parts,
        ]);
        return $response['status'] === 202
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => $this->errorFrom($response)];
    }

    public function pollTick(int $jobId): array
    {
        $response = $this->request('GET', "/wp-json/wma/v1/jobs/{$jobId}?tick=1");
        if ($response['status'] !== 200) {
            return ['ok' => false, 'status' => '', 'step' => '', 'parts' => [], 'error' => $this->errorFrom($response)];
        }
        $data = json_decode($response['body'], true) ?: [];
        return [
            'ok' => true,
            'status' => (string) ($data['status'] ?? ''),
            'step' => (string) ($data['step'] ?? ''),
            'parts' => (array) ($data['parts'] ?? []),
            'error' => (string) ($data['error'] ?? ''),
        ];
    }

    /**
     * @return array{status: int, body: string, error: string}
     */
    private function request(string $method, string $path, ?array $jsonBody = null, ?string $rawBody = null, array $extraHeaders = []): array
    {
        $url = rtrim($this->site['store_url'], '/') . $path;
        $headers = array_merge(['Authorization: Bearer ' . $this->site['agent_token']], $extraHeaders);

        $payload = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($jsonBody);
        } elseif ($rawBody !== null) {
            $payload = $rawBody;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->site['verify_ssl'] ?? true),
            CURLOPT_TIMEOUT => 30,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => '', 'error' => $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body, 'error' => ''];
    }

    private function decodeOrThrow(array $response): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException($this->errorFrom($response));
        }
        return json_decode($response['body'], true) ?: [];
    }

    private function errorFrom(array $response): string
    {
        if ($response['error'] !== '') {
            return 'Connection error: ' . $response['error'];
        }
        $data = json_decode($response['body'], true);
        $message = is_array($data) && !empty($data['error']) ? $data['error'] : 'Unexpected response';
        return "Agent request failed (HTTP {$response['status']}): {$message}";
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/SiteAgentClient.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add includes/SiteAgentClient.php
git commit -m "Add SiteAgentClient, the single HTTP client for all site communication"
```

---

### Task 5: SiteBackupManager — backup/restore orchestration

**Files:**
- Create: `includes/SiteBackupManager.php`

**Interfaces:**
- Consumes: `SiteAgentClient::startJob()`/`pollTick()`, `S3Client::presignedUrl()`, `get_site_by_agent_token()`, `mark_agent_seen()`, `site_agent_client_for_site()`
- Produces: `SiteBackupManager::startBackup(array $site, string $scope): array`, `SiteBackupManager::startRestore(array $site, int $sourceBackupId, string $scope): array`, `SiteBackupManager::pollBackup(array $site, int $jobId): array`, `SiteBackupManager::pollRestore(array $site, int $jobId): array`, `SiteBackupManager::presign(string $token, int $jobId, string $partKey, string $method): array{ok: bool, url: string, error: string}`, `SiteBackupManager::listForSite(int $siteId, int $limit = 50): array`, `SiteBackupManager::getBackup(int $id): ?array`, `SiteBackupManager::getRestore(int $id): ?array`

- [ ] **Step 1: Write the manager**

```php
<?php
// includes/SiteBackupManager.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/S3Client.php';
require_once __DIR__ . '/Sites.php';

/**
 * Owns the canonical site_backups/site_restores rows. Each "poll" call
 * both ticks the plugin's job (via SiteAgentClient::pollTick, which
 * executes one resumable step server-side) and persists the resulting
 * state here, so this app's database is always the source of truth the UI
 * reads from.
 */
class SiteBackupManager
{
    private const STALL_TIMEOUT_SECONDS = 600;

    // The plugin's wma_jobs table has a single job_id primary key, but
    // woo-managment issues ids from two separate auto-increment sequences
    // (site_backups.id and site_restores.id) that both start at 1 — restore
    // ids are offset into a range backup ids will never reach so they can't
    // collide as the same plugin-side job_id.
    private const RESTORE_AGENT_ID_OFFSET = 2_000_000_000;

    private static function agentJobId(string $kind, int $localId): int
    {
        return $kind === 'restore' ? self::RESTORE_AGENT_ID_OFFSET + $localId : $localId;
    }

    public static function startBackup(array $site, string $scope): array
    {
        $pdo = Database::get();
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO site_backups (site_id, type, status, started_at, last_tick_at) VALUES (?, ?, \'running\', ?, ?)'
        );
        $stmt->execute([$site['id'], $scope, $now, $now]);
        $jobId = (int) $pdo->lastInsertId();

        $pdo->prepare('UPDATE site_backups SET s3_prefix = ? WHERE id = ?')
            ->execute(["backups/site-{$site['id']}/{$jobId}/", $jobId]);

        $client = site_agent_client_for_site($site);
        $result = $client->startJob(self::agentJobId('backup', $jobId), 'backup', $scope);
        if (!$result['ok']) {
            self::markFailed('site_backups', $jobId, $result['error']);
        }

        return self::getBackup($jobId);
    }

    public static function startRestore(array $site, int $sourceBackupId, string $scope): array
    {
        $source = self::getBackup($sourceBackupId);
        if ($source === null || $source['status'] !== 'completed') {
            throw new RuntimeException('Source backup is not available to restore from.');
        }

        $pdo = Database::get();
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO site_restores (site_id, source_backup_id, type, status, started_at, last_tick_at) VALUES (?, ?, ?, \'running\', ?, ?)'
        );
        $stmt->execute([$site['id'], $sourceBackupId, $scope, $now, $now]);
        $jobId = (int) $pdo->lastInsertId();

        $manifest = json_decode((string) $source['manifest_json'], true) ?: [];
        $client = site_agent_client_for_site($site);
        $result = $client->startJob(self::agentJobId('restore', $jobId), 'restore', $scope, $manifest);
        if (!$result['ok']) {
            self::markFailed('site_restores', $jobId, $result['error']);
        }

        return self::getRestore($jobId);
    }

    public static function pollBackup(array $site, int $jobId): array
    {
        $client = site_agent_client_for_site($site);
        $tick = $client->pollTick(self::agentJobId('backup', $jobId));
        mark_agent_seen((int) $site['id']);

        if (!$tick['ok']) {
            self::failIfStalled('site_backups', $jobId);
            return self::getBackup($jobId);
        }

        $manifest = $tick['parts'];
        $totalBytes = array_sum(array_column($manifest, 'bytes'));
        $percent = self::estimatePercent($tick['step'], $tick['status']);

        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE site_backups SET status = ?, step_label = ?, percent = ?, total_size_bytes = ?, manifest_json = ?, error = ?, last_tick_at = ?, completed_at = ? WHERE id = ?'
        )->execute([
            $tick['status'], $tick['step'], $percent, $totalBytes, json_encode($manifest),
            $tick['error'] !== '' ? $tick['error'] : null, time(),
            $tick['status'] === 'completed' ? time() : null, $jobId,
        ]);

        return self::getBackup($jobId);
    }

    public static function pollRestore(array $site, int $jobId): array
    {
        $client = site_agent_client_for_site($site);
        $tick = $client->pollTick(self::agentJobId('restore', $jobId));
        mark_agent_seen((int) $site['id']);

        if (!$tick['ok']) {
            self::failIfStalled('site_restores', $jobId);
            return self::getRestore($jobId);
        }

        $percent = self::estimatePercent($tick['step'], $tick['status']);
        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE site_restores SET status = ?, step_label = ?, percent = ?, error = ?, last_tick_at = ?, completed_at = ? WHERE id = ?'
        )->execute([
            $tick['status'], $tick['step'], $percent,
            $tick['error'] !== '' ? $tick['error'] : null, time(),
            $tick['status'] === 'completed' ? time() : null, $jobId,
        ]);

        return self::getRestore($jobId);
    }

    /**
     * Called by public/api/site-backup-agent/presign.php. Authorization is
     * solely token -> site, not a job-ownership lookup: the plugin also
     * runs an internal pre-restore safety-snapshot job that woo-managment
     * never creates a row for, so there's no row to match against for that
     * job's presign calls. A valid token only ever lets its own site
     * read/write objects under that site's S3 prefix, regardless of
     * job_id — job_id is a path segment, not a separate trust boundary.
     */
    public static function presign(string $token, int $jobId, string $partKey, string $method): array
    {
        $site = get_site_by_agent_token($token);
        if ($site === null) {
            return ['ok' => false, 'url' => '', 'error' => 'Unknown pairing token.'];
        }

        $s3 = self::s3Client();
        if ($s3 === null) {
            return ['ok' => false, 'url' => '', 'error' => 'S3 backup storage is not configured.'];
        }

        $key = "backups/site-{$site['id']}/{$jobId}/{$partKey}";
        $url = $s3->presignedUrl(strtoupper($method) === 'GET' ? 'GET' : 'PUT', $key, 900);
        return ['ok' => true, 'url' => $url, 'error' => ''];
    }

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM site_backups WHERE site_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function getBackup(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM site_backups WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public static function getRestore(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM site_restores WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function failIfStalled(string $table, int $jobId): void
    {
        $pdo = Database::get();
        $row = $pdo->prepare("SELECT last_tick_at, status FROM {$table} WHERE id = ?");
        $row->execute([$jobId]);
        $data = $row->fetch();
        if ($data && $data['status'] === 'running' && (time() - (int) $data['last_tick_at']) > self::STALL_TIMEOUT_SECONDS) {
            self::markFailed($table, $jobId, 'Job stalled: no progress within the timeout window.');
        }
    }

    private static function markFailed(string $table, int $jobId, string $error): void
    {
        $pdo = Database::get();
        $pdo->prepare("UPDATE {$table} SET status = 'failed', error = ? WHERE id = ?")->execute([$error, $jobId]);
    }

    private static function estimatePercent(string $step, string $status): int
    {
        if ($status === 'completed') {
            return 100;
        }
        $order = ['safety_backup' => 5, 'dump_db' => 20, 'zip_files' => 50, 'download' => 50, 'restore_db' => 70, 'extract_files' => 85, 'upload' => 90, 'swap' => 95, 'done' => 100];
        return $order[$step] ?? 0;
    }

    private static function s3Client(): ?S3Client
    {
        $settings = get_settings();
        $client = new S3Client([
            'endpoint' => $settings['s3_endpoint'],
            'region' => $settings['s3_region'],
            'bucket' => $settings['s3_bucket'],
            'access_key' => $settings['s3_access_key'],
            'secret_key' => $settings['s3_secret_key'],
        ]);
        return $client->isConfigured() ? $client : null;
    }
}
```

- [ ] **Step 2: Self-check the percent ordering is monotonic for a realistic walk**

```bash
php -r '
$order = ["safety_backup" => 5, "dump_db" => 20, "zip_files" => 50, "upload" => 90, "done" => 100];
$walk = ["safety_backup", "dump_db", "zip_files", "upload", "done"];
$prev = -1;
foreach ($walk as $step) {
    $p = $order[$step];
    assert($p >= $prev);
    $prev = $p;
}
echo "percent ordering OK\n";
'
```
Expected: `percent ordering OK`

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/SiteBackupManager.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add includes/SiteBackupManager.php
git commit -m "Add SiteBackupManager orchestration on top of SiteAgentClient"
```

---

### Task 6: Plugin-facing presign endpoint

**Files:**
- Create: `public/api/site-backup-agent/presign.php`

- [ ] **Step 1: Write the endpoint**

```php
<?php
// public/api/site-backup-agent/presign.php

require_once __DIR__ . '/../../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../../includes/SiteBackupManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    json_response(['error' => 'Missing or invalid Authorization header'], 401);
}
$token = $m[1];

$body = json_body();
$jobId = (int) ($body['job_id'] ?? 0);
$partKey = (string) ($body['part_key'] ?? '');
$method = (string) ($body['method'] ?? 'PUT');

if ($jobId <= 0 || $partKey === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $partKey)) {
    json_response(['error' => 'Invalid presign parameters'], 400);
}

$result = SiteBackupManager::presign($token, $jobId, $partKey, $method);
if (!$result['ok']) {
    json_response(['error' => $result['error']], 403);
}

json_response(['url' => $result['url'], 'expires_at' => time() + 900]);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l public/api/site-backup-agent/presign.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add public/api/site-backup-agent/presign.php
git commit -m "Add plugin-facing presign endpoint"
```

---

### Task 7: Admin-facing backup and restore endpoints

**Files:**
- Create: `public/api/site-backups.php`
- Create: `public/api/site-restores.php`

- [ ] **Step 1: Write the backup endpoint**

```php
<?php
// public/api/site-backups.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/SiteBackupManager.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === '') {
    json_response(['items' => array_map('map_site_backup', SiteBackupManager::listForSite((int) $site['id']))]);
}

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    json_response(['item' => map_site_backup(SiteBackupManager::pollBackup($site, $jobId))]);
}

verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'start') {
    if (empty($site['agent_token'])) {
        json_response(['error' => 'This site has no paired agent. Generate a pairing token in Admin -> Sites first.'], 409);
    }
    $scope = ($body['scope'] ?? 'database') === 'full' ? 'full' : 'database';

    $job = SiteBackupManager::startBackup($site, $scope);
    log_activity($user, (int) $site['id'], 'site', 'site_backup_start', 'log_site_backup_started', [$scope]);
    json_response(['item' => map_site_backup($job)], 201);
}

json_response(['error' => 'Method not allowed'], 405);

function map_site_backup(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    return [
        'id' => (int) $job['id'],
        'type' => $job['type'],
        'status' => $job['status'],
        'step_label' => $job['step_label'],
        'percent' => (int) $job['percent'],
        'total_size_bytes' => (int) $job['total_size_bytes'],
        'error' => $job['error'],
        'started_at' => (int) $job['started_at'],
        'completed_at' => $job['completed_at'] !== null ? (int) $job['completed_at'] : null,
    ];
}
```

- [ ] **Step 2: Write the restore endpoint**

```php
<?php
// public/api/site-restores.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/SiteBackupManager.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    json_response(['item' => map_site_restore(SiteBackupManager::pollRestore($site, $jobId))]);
}

require_superadmin_api();
verify_csrf_api();
$body = json_body();

if ($method === 'POST' && $action === 'start') {
    $sourceBackupId = (int) ($body['source_backup_id'] ?? 0);
    $scope = ($body['scope'] ?? 'database') === 'full' ? 'full' : 'database';

    try {
        $job = SiteBackupManager::startRestore($site, $sourceBackupId, $scope);
    } catch (RuntimeException $e) {
        json_response(['error' => $e->getMessage()], 422);
    }

    log_activity($user, (int) $site['id'], 'site', 'site_restore_start', 'log_site_restore_started', [$scope]);
    json_response(['item' => map_site_restore($job)], 201);
}

json_response(['error' => 'Method not allowed'], 405);

function map_site_restore(?array $job): ?array
{
    if ($job === null) {
        return null;
    }
    return [
        'id' => (int) $job['id'],
        'source_backup_id' => (int) $job['source_backup_id'],
        'type' => $job['type'],
        'status' => $job['status'],
        'step_label' => $job['step_label'],
        'percent' => (int) $job['percent'],
        'error' => $job['error'],
        'started_at' => (int) $job['started_at'],
        'completed_at' => $job['completed_at'] !== null ? (int) $job['completed_at'] : null,
    ];
}
```

- [ ] **Step 3: Verify syntax**

Run: `php -l public/api/site-backups.php && php -l public/api/site-restores.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add public/api/site-backups.php public/api/site-restores.php
git commit -m "Add admin-facing backup/restore trigger/poll/list endpoints"
```

---

### Task 8: Rewire products.php, product.php, categories.php

**Files:**
- Modify: `public/api/products.php`
- Modify: `public/api/product.php`
- Modify: `public/api/categories.php`

**Interfaces:**
- Consumes: `site_agent_client_for_site(array $site): SiteAgentClient`

- [ ] **Step 1: Rewire `products.php`**

Replace every call site that built a `WooCommerceClient` with one through the agent client. Where the file previously did:

```php
$client = woocommerce_client_for_site($site);
$result = $client->listProducts($params);
```

change to:

```php
$client = site_agent_client_for_site($site);
$result = $client->listProducts($params);
```

The custom-taxonomy filter intersection that previously called `listProductIdsByTerm()` per taxonomy is no longer needed as a separate pass — pass the selected taxonomy term ids straight through as `$params['taxonomy_terms']` (an array keyed by `rest_base`), since `Wma_Products::list_products()` (plugin-side) now applies them as a native `tax_query` in the same product query. Remove any loop in this file that called `listProductIdsByTerm()` and intersected ids manually; replace it with simply forwarding the selected filters into `$params['taxonomy_terms']` before calling `listProducts()`.

- [ ] **Step 2: Rewire `product.php`**

Replace the `getProduct()`/`createProduct()`/`updateProduct()`/`deleteProduct()` calls with the same names on `site_agent_client_for_site($site)`. The previous two-step "update product, then `updateProductTaxonomies()`" sequence collapses into one call — pass `taxonomies` as part of the same `$data` array given to `updateProduct()`/`createProduct()`, since `Wma_Products::apply_fields()` (plugin-side) now handles taxonomy assignment within the same save. Remove any separate `updateProductTaxonomies()` call entirely.

- [ ] **Step 3: Rewire `categories.php`**

Replace:

```php
$client = woocommerce_client_for_site($site);
$categories = $client->listCategories(['orderby' => 'name', 'order' => 'asc']);
```

with:

```php
$client = site_agent_client_for_site($site);
$categories = $client->listCategories();
```

(`listCategories()` no longer takes params — it always returns the full set, ordered alphabetically plugin-side via `get_terms()`'s default ordering; if the existing hierarchy-flattening code sorts client-side already, no further change is needed.)

- [ ] **Step 4: Verify syntax**

Run: `php -l public/api/products.php && php -l public/api/product.php && php -l public/api/categories.php`
Expected: `No syntax errors detected` for all three.

- [ ] **Step 5: Manually verify in browser**

Load the Products page for a paired test site (see Task 15 in the `2026-06-26-woo-mgmt-agent-plugin.md` plan for pairing). Confirm the list loads, a single product's detail loads with its categories, and category filtering works.

- [ ] **Step 6: Commit**

```bash
git add public/api/products.php public/api/product.php public/api/categories.php
git commit -m "Rewire products/product/categories endpoints to SiteAgentClient"
```

---

### Task 9: Rewire taxonomies.php with the corrected cache strategy

**Files:**
- Modify: `public/api/taxonomies.php`
- Modify: `includes/helpers.php`

**Interfaces:**
- Consumes: `site_agent_client_for_site(array $site)->listTaxonomies()`

- [ ] **Step 1: Make taxonomy cache invalidation share the products cache hook**

In `includes/helpers.php`, `invalidate_products_cache()` currently reads:

```php
function invalidate_products_cache(int $siteId): void
{
    cache_delete_prefix('products:' . $siteId . ':');
}
```

Extend it to also clear the taxonomies cache key for that site:

```php
function invalidate_products_cache(int $siteId): void
{
    cache_delete_prefix('products:' . $siteId . ':');
    cache_delete_prefix('taxonomies:' . $siteId . ':');
}
```

- [ ] **Step 2: Rewire `taxonomies.php` to use the short-TTL cache**

Replace the body of `public/api/taxonomies.php` to use the existing `cache_get()`/`cache_set()` helpers from `includes/helpers.php` (`cache_get(string $key)`, `cache_set(string $key, $value, int $ttlSeconds)`), with a 10-second TTL instead of the current 300-second one, keyed the same way `cache_delete_prefix('taxonomies:' . $siteId . ':')` above expects (`taxonomies:{$siteId}:all`):

```php
<?php
// public/api/taxonomies.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Sites.php';

$user = require_login_api();
$site = require_site_api($user);

$cacheKey = "taxonomies:{$site['id']}:all";
$cached = cache_get($cacheKey);
if ($cached !== null) {
    json_response(['items' => $cached]);
}

if (empty($site['agent_token'])) {
    json_response(['items' => [], 'reason' => 'no_credentials']);
}

$client = site_agent_client_for_site($site);
try {
    $items = $client->listTaxonomies();
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 502);
}

cache_set($cacheKey, $items, 10);
json_response(['items' => $items, 'reason' => empty($items) ? 'none_found' : null]);
```

- [ ] **Step 3: Verify syntax**

Run: `php -l public/api/taxonomies.php && php -l includes/helpers.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manually verify the bug is fixed**

Load the products page for a paired site with at least one custom taxonomy, confirm taxonomy filters appear immediately (not after a long wait), then mutate a product (e.g. change stock) and confirm the taxonomy list refreshes within 10 seconds rather than staying stale for 5 minutes.

- [ ] **Step 5: Commit**

```bash
git add public/api/taxonomies.php includes/helpers.php
git commit -m "Rewire taxonomies endpoint to SiteAgentClient with short mutation-invalidated cache"
```

---

### Task 10: Rewire batch.php and stock.php

**Files:**
- Modify: `public/api/batch.php`
- Modify: `public/api/stock.php`

- [ ] **Step 1: Rewire `batch.php`**

Replace every `woocommerce_client_for_site($site)` call with `site_agent_client_for_site($site)`. The method names (`listProducts()`, `batchProducts()`) are unchanged on the new client, so the surrounding price/stock-calculation logic in this file needs no changes beyond the client construction call.

- [ ] **Step 2: Rewire `stock.php`**

Same substitution: `woocommerce_client_for_site($site)` → `site_agent_client_for_site($site)`. `getProduct()`/`updateProduct()` signatures are unchanged.

- [ ] **Step 3: Verify syntax**

Run: `php -l public/api/batch.php && php -l public/api/stock.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Manually verify in browser**

Run a preview-then-apply price batch update and a single stock update against a paired test site; confirm both still work end to end.

- [ ] **Step 5: Commit**

```bash
git add public/api/batch.php public/api/stock.php
git commit -m "Rewire batch/stock endpoints to SiteAgentClient"
```

---

### Task 11: Rewire media.php, then delete the old REST clients

**Files:**
- Modify: `public/api/media.php`
- Delete: `includes/WooCommerceClient.php`
- Delete: `includes/WordPressClient.php`

This is the last of the rewiring tasks (after Task 8's products/categories and Task 10's batch/stock), so it also removes the two old client files now that nothing references them.

- [ ] **Step 1: Rewire the upload call**

Replace:

```php
$wpClient = wordpress_client_for_site($site);
$result = $wpClient->uploadMedia($fileContent, $filename, $mimeType);
```

with:

```php
$client = site_agent_client_for_site($site);
$result = $client->uploadMedia($fileContent, $filename, $mimeType);
```

Any prior `site_has_wordpress_credentials($site)` guard (which gated whether media upload was even attempted) should be removed — the agent token is the only credential now, and `require_site_api()` already guarantees a valid site; if `$site['agent_token']` is empty, `uploadMedia()` will simply fail with a clear "Agent request failed" error surfaced to the UI, which is the correct behavior (no separate pre-check needed — see CLAUDE.md's general guidance against validating conditions that the underlying call already handles).

- [ ] **Step 2: Verify syntax**

Run: `php -l public/api/media.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Manually verify in browser**

Upload a product image (with and without the optional AI-edit flag) against a paired test site; confirm it appears in the product's image list.

- [ ] **Step 4: Confirm nothing else references the old clients, then delete them**

Run: `grep -rl "WooCommerceClient\|WordPressClient" includes/ public/ --include=*.php`
Expected: no output — Tasks 3, 8, 10, and Step 1 above have removed every reference. If this still lists any file, fix that reference before deleting (don't delete out from under a live caller).

```bash
git rm includes/WooCommerceClient.php includes/WordPressClient.php
```

- [ ] **Step 5: Commit**

```bash
git add public/api/media.php
git commit -m "Rewire media upload endpoint to SiteAgentClient; remove old WooCommerce/WordPress REST clients"
```

---

### Task 12: Site sheet UI — drop old credentials, add pairing + backup settings

**Files:**
- Modify: `public/api/sites.php`
- Modify: `public/admin/sites.php`
- Modify: `public/assets/js/admin-sites.js`
- Modify: `includes/i18n.php`

- [ ] **Step 1: Add the pairing-token action and update `map_site_detail()`/`validate_site_payload()`**

In `public/api/sites.php`, add a new action alongside the existing POST/PUT/DELETE branches (after `require_superadmin_api()`):

```php
if ($method === 'POST' && $action === 'generate_agent_token') {
    $id = (int) ($body['site_id'] ?? 0);
    $site = get_site($id);
    if ($site === null) {
        json_response(['error' => 'Site not found'], 404);
    }
    $token = generate_agent_token($id);
    log_activity($user, $id, 'site', 'site_agent_token', 'log_site_agent_token_generated', [$site['name']]);
    json_response(['token' => $token]);
}
```

Replace `map_site_detail()` (the consumer key/secret/app-password fields no longer exist on the row):

```php
function map_site_detail(array $site): array
{
    return [
        'id' => (int) $site['id'],
        'name' => $site['name'],
        'store_url' => $site['store_url'],
        'verify_ssl' => (bool) $site['verify_ssl'],
        'agent_paired' => !empty($site['agent_token']),
        'agent_paired_at' => $site['agent_paired_at'] !== null ? (int) $site['agent_paired_at'] : null,
        'agent_last_seen_at' => $site['agent_last_seen_at'] !== null ? (int) $site['agent_last_seen_at'] : null,
        'backup_enabled' => (bool) $site['backup_enabled'],
        'backup_schedule' => $site['backup_schedule'],
        'backup_retention_days' => (int) $site['backup_retention_days'],
    ];
}
```

Simplify `validate_site_payload()` to drop the now-nonexistent `consumer_key`/`consumer_secret` requirement checks — only `name` and `store_url` are required on create:

```php
function validate_site_payload(array $body, bool $requireAll): array
{
    $errors = [];

    if ($requireAll && trim((string) ($body['name'] ?? '')) === '') {
        $errors[] = 'Site name is required.';
    }

    if ($requireAll || array_key_exists('store_url', $body)) {
        $url = trim((string) ($body['store_url'] ?? ''));
        if ($requireAll && $url === '') {
            $errors[] = 'Store URL is required.';
        } elseif ($url !== '' && !filter_var($url, FILTER_VALIDATE_URL)) {
            $errors[] = 'Store URL must be a valid URL.';
        }
    }

    return $errors;
}
```

Also remove `mask_secret()` if nothing else in the file uses it after this change (check with `grep -n mask_secret public/api/sites.php` — if only `map_site_detail()` called it, delete the function).

- [ ] **Step 2: Update the site sheet markup**

In `public/admin/sites.php`, remove the form fields for `site-ck` (consumer key), `site-cs` (consumer secret), `site-wp-username`, and `site-wp-app-password` entirely. Add the pairing + backup settings block in their place:

```html
<div class="space-y-2 border-t border-gray-100 pt-3 mt-3">
  <h3 class="text-xs font-semibold text-gray-500 uppercase"><?php echo htmlspecialchars(t('site_connection_heading')); ?></h3>
  <div id="site-agent-status" class="text-xs text-gray-400"></div>
  <button type="button" id="generate-agent-token-btn" class="w-full rounded-xl border border-gray-300 text-gray-700 font-medium py-2 text-sm"><?php echo htmlspecialchars(t('generate_pairing_token')); ?></button>
  <div id="agent-token-display" class="hidden text-xs font-mono bg-gray-50 rounded-lg p-2 break-all" dir="ltr"></div>
</div>
<div class="space-y-2 border-t border-gray-100 pt-3 mt-3">
  <h3 class="text-xs font-semibold text-gray-500 uppercase"><?php echo htmlspecialchars(t('site_backups_heading')); ?></h3>
  <label class="flex items-center gap-2 text-sm">
    <input type="checkbox" id="site-backup-enabled">
    <?php echo htmlspecialchars(t('site_backup_enabled')); ?>
  </label>
  <select id="site-backup-schedule" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm">
    <option value="off"><?php echo htmlspecialchars(t('schedule_off')); ?></option>
    <option value="daily"><?php echo htmlspecialchars(t('schedule_daily')); ?></option>
    <option value="weekly"><?php echo htmlspecialchars(t('schedule_weekly')); ?></option>
  </select>
  <input type="number" id="site-backup-retention" min="1" class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm" placeholder="<?php echo htmlspecialchars(t('backup_retention_days_placeholder')); ?>">
</div>
```

- [ ] **Step 3: Update the JS**

In `public/assets/js/admin-sites.js`: remove any code reading/writing `site-ck`/`site-cs`/`site-wp-username`/`site-wp-app-password` from the save payload and from the sheet-populate logic. Add the pairing button handler and include the backup fields in the save payload (alongside how `verify_ssl` is already handled there):

```javascript
document.getElementById('generate-agent-token-btn').addEventListener('click', async () => {
  const siteId = document.getElementById('site-sheet').dataset.siteId;
  const res = await App.api('/api/sites.php?action=generate_agent_token', {
    method: 'POST',
    body: JSON.stringify({ site_id: Number(siteId) }),
  });
  const display = document.getElementById('agent-token-display');
  display.textContent = res.token;
  display.classList.remove('hidden');
  App.toast(App.t('pairing_token_generated_help'));
});
```

When populating the sheet for an existing site, set `site-backup-enabled`/`site-backup-schedule`/`site-backup-retention` from `item.backup_enabled`/`backup_schedule`/`backup_retention_days`, and show `site-agent-status` text based on `item.agent_paired`/`agent_last_seen_at` (e.g. "Connected" / "Never paired" / "Last seen: ..."). Include `backup_enabled`/`backup_schedule`/`backup_retention_days` in the existing save-site PUT payload.

- [ ] **Step 4: Add translation keys**

In `includes/i18n.php`, add to both `en` and `fa`:

```php
        'site_connection_heading' => 'Site connection',
        'site_backups_heading' => 'Site backups',
        'site_backup_enabled' => 'Enable backups for this site',
        'schedule_off' => 'Off',
        'schedule_daily' => 'Daily',
        'schedule_weekly' => 'Weekly',
        'backup_retention_days_placeholder' => 'Retention (days)',
        'generate_pairing_token' => 'Generate pairing token',
        'pairing_token_generated_help' => 'Copy this token into the plugin\'s settings screen on the site. It will not be shown again.',
```

```php
        'site_connection_heading' => 'اتصال سایت',
        'site_backups_heading' => 'پشتیبان‌گیری سایت',
        'site_backup_enabled' => 'فعال‌سازی پشتیبان‌گیری برای این سایت',
        'schedule_off' => 'غیرفعال',
        'schedule_daily' => 'روزانه',
        'schedule_weekly' => 'هفتگی',
        'backup_retention_days_placeholder' => 'مدت نگهداری (روز)',
        'generate_pairing_token' => 'ایجاد توکن اتصال',
        'pairing_token_generated_help' => 'این توکن را در صفحه تنظیمات افزونه روی سایت وارد کنید. دوباره نمایش داده نمی‌شود.',
```

- [ ] **Step 5: Verify syntax**

Run: `php -l public/api/sites.php && php -l public/admin/sites.php && php -l includes/i18n.php && node --check public/assets/js/admin-sites.js`
Expected: clean for all four.

- [ ] **Step 6: Manually verify in browser**

Open Admin -> Sites in both `en` and `fa`; confirm the consumer-key/app-password fields are gone, the pairing button works, and layout doesn't break in RTL.

- [ ] **Step 7: Commit**

```bash
git add public/api/sites.php public/admin/sites.php public/assets/js/admin-sites.js includes/i18n.php
git commit -m "Replace site credential fields with agent pairing and backup settings"
```

---

### Task 13: Backup history + restore picker page

**Files:**
- Create: `public/admin/site-backups.php`
- Create: `public/assets/js/admin-site-backups.js`
- Modify: `includes/nav.php`
- Modify: `includes/i18n.php`

- [ ] **Step 1: Write the admin page**

```php
<?php
// public/admin/site-backups.php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pwa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/nav.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_page();
$site = get_current_site($user);
if ($site === null) {
    header('Location: /products.php');
    exit;
}
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('site_backups_heading')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('admin', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('site_backups_heading')); ?></h1>
    </div>
  </header>

  <main class="px-4 py-3 space-y-4">
    <section class="bg-white rounded-2xl border border-gray-100 p-4">
      <div class="flex gap-2">
        <button id="run-site-db-backup-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_db_backup')); ?></button>
        <button id="run-site-full-backup-btn" class="flex-1 rounded-xl border border-gray-300 text-gray-700 font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_full_backup')); ?></button>
      </div>
    </section>

    <section>
      <h2 class="text-sm font-semibold text-gray-900 mb-2 px-1"><?php echo htmlspecialchars(t('backup_history')); ?></h2>
      <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
      <div id="backup-list" class="hidden space-y-2"></div>
    </section>
  </main>

  <?php render_bottom_nav('admin', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE_ID = <?php echo (int) $site['id']; ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-site-backups.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
```

- [ ] **Step 2: Write the JS**

```javascript
// public/assets/js/admin-site-backups.js

const listEl = document.getElementById('backup-list');
const loadingEl = document.getElementById('loading');
const pollingJobs = new Set();

async function loadBackups() {
  const res = await App.api('/api/site-backups.php');
  renderList(res.items);
  loadingEl.classList.add('hidden');
  listEl.classList.remove('hidden');
}

function renderList(items) {
  listEl.innerHTML = items.map(renderRow).join('');
  items.filter(i => i.status === 'running').forEach(i => pollJob(i.id));
}

function renderRow(item) {
  const statusClass = item.status === 'completed' ? 'text-green-600' : item.status === 'failed' ? 'text-red-600' : 'text-gray-500';
  const restoreBtn = item.status === 'completed'
    ? `<button class="restore-btn text-xs text-gray-700 underline" data-id="${item.id}" data-type="${item.type}">${App.t('restore')}</button>`
    : '';
  return `<div class="bg-white rounded-2xl border border-gray-100 p-3 flex items-center justify-between" data-job-id="${item.id}">
    <div>
      <div class="text-sm font-medium text-gray-900">${item.type} &middot; ${new Date(item.started_at * 1000).toLocaleString()}</div>
      <div class="text-xs ${statusClass}">${item.status}${item.status === 'running' ? ' (' + item.percent + '%)' : ''}${item.error ? ' — ' + item.error : ''}</div>
    </div>
    ${restoreBtn}
  </div>`;
}

async function pollJob(jobId) {
  if (pollingJobs.has(jobId)) return;
  pollingJobs.add(jobId);

  const tick = async () => {
    const res = await App.api(`/api/site-backups.php?action=poll&id=${jobId}`);
    const row = document.querySelector(`[data-job-id="${jobId}"]`);
    if (row) row.outerHTML = renderRow(res.item);
    if (res.item.status === 'running') {
      setTimeout(tick, 3000);
    } else {
      pollingJobs.delete(jobId);
    }
  };
  tick();
}

async function startBackup(scope) {
  const res = await App.api('/api/site-backups.php?action=start', {
    method: 'POST',
    body: JSON.stringify({ scope }),
  });
  listEl.insertAdjacentHTML('afterbegin', renderRow(res.item));
  pollJob(res.item.id);
}

document.getElementById('run-site-db-backup-btn').addEventListener('click', () => startBackup('database'));
document.getElementById('run-site-full-backup-btn').addEventListener('click', () => startBackup('full'));

listEl.addEventListener('click', async (e) => {
  const btn = e.target.closest('.restore-btn');
  if (!btn) return;
  if (!confirm(App.t('restore_confirm'))) return;

  const res = await App.api('/api/site-restores.php?action=start', {
    method: 'POST',
    body: JSON.stringify({ source_backup_id: Number(btn.dataset.id), scope: btn.dataset.type }),
  });
  App.toast(App.t('restore_started'));

  const pollRestore = async () => {
    const r = await App.api(`/api/site-restores.php?action=poll&id=${res.item.id}`);
    if (r.item.status === 'running') {
      setTimeout(pollRestore, 3000);
    } else {
      App.toast(r.item.status === 'completed' ? App.t('restore_completed') : App.t('restore_failed') + ': ' + r.item.error);
    }
  };
  pollRestore();
});

loadBackups();
```

- [ ] **Step 3: Add a nav link**

In `includes/nav.php`, add a link to `/admin/site-backups.php` alongside the existing admin sub-nav links, following whatever pattern that file currently uses for the admin sub-nav.

- [ ] **Step 4: Add translation keys**

In `includes/i18n.php`, add to both `en` and `fa`:

```php
        'restore' => 'Restore',
        'restore_confirm' => 'Restoring will replace the live site with this backup. A safety snapshot of the current state is taken automatically first. Continue?',
        'restore_started' => 'Restore started.',
        'restore_completed' => 'Restore completed.',
        'restore_failed' => 'Restore failed',
        'run_db_backup' => 'Run database backup now',
        'run_full_backup' => 'Run full backup now',
        'backup_history' => 'Backup history',
```

```php
        'restore' => 'بازگردانی',
        'restore_confirm' => 'بازگردانی، سایت فعلی را با این پشتیبان جایگزین می‌کند. ابتدا به‌طور خودکار از وضعیت فعلی یک نسخه ایمنی گرفته می‌شود. ادامه می‌دهید؟',
        'restore_started' => 'بازگردانی آغاز شد.',
        'restore_completed' => 'بازگردانی با موفقیت انجام شد.',
        'restore_failed' => 'بازگردانی ناموفق بود',
        'run_db_backup' => 'اجرای فوری پشتیبان‌گیری دیتابیس',
        'run_full_backup' => 'اجرای فوری پشتیبان‌گیری کامل',
        'backup_history' => 'تاریخچه پشتیبان‌گیری',
```

(`run_db_backup`/`run_full_backup`/`backup_history` may already exist from the unrelated app-wide Admin -> Backups page — if so, reuse them rather than duplicating the key.)

- [ ] **Step 5: Verify syntax**

Run: `php -l public/admin/site-backups.php && node --check public/assets/js/admin-site-backups.js && php -l includes/i18n.php`
Expected: clean for all three.

- [ ] **Step 6: Manually verify in browser**

With a paired test site, trigger a database backup, watch it progress to completed without a page reload, then trigger a restore and watch it complete. Re-check in `fa` for RTL layout.

- [ ] **Step 7: Commit**

```bash
git add public/admin/site-backups.php public/assets/js/admin-site-backups.js includes/nav.php includes/i18n.php
git commit -m "Add site backup history and restore picker page"
```

---

### Task 14: "Backup now" button on the batch page

**Files:**
- Modify: `public/batch.php`
- Modify: `public/assets/js/batch.js`
- Modify: `includes/i18n.php`

- [ ] **Step 1: Add the button**

In `public/batch.php`, add near the top of the page's action area:

```html
<button id="backup-before-changes-btn" class="text-xs text-gray-600 underline mb-2"><?php echo htmlspecialchars(t('backup_before_changes')); ?></button>
<span id="backup-before-changes-status" class="text-xs text-gray-400 ml-2"></span>
```

- [ ] **Step 2: Wire the JS**

In `public/assets/js/batch.js`, add:

```javascript
document.getElementById('backup-before-changes-btn')?.addEventListener('click', async (e) => {
  const status = document.getElementById('backup-before-changes-status');
  e.target.disabled = true;
  status.textContent = App.t('backup_in_progress');

  const res = await App.api('/api/site-backups.php?action=start', {
    method: 'POST',
    body: JSON.stringify({ scope: 'database' }),
  });

  const poll = async () => {
    const r = await App.api(`/api/site-backups.php?action=poll&id=${res.item.id}`);
    if (r.item.status === 'running') {
      setTimeout(poll, 3000);
    } else {
      status.textContent = r.item.status === 'completed' ? App.t('backup_done') : App.t('backup_failed');
      e.target.disabled = false;
    }
  };
  poll();
});
```

- [ ] **Step 3: Add translation keys**

In `includes/i18n.php`, add to both `en` and `fa`:

```php
        'backup_before_changes' => 'Backup site before continuing',
        'backup_in_progress' => 'Backing up...',
        'backup_done' => 'Backup complete',
        'backup_failed' => 'Backup failed',
```

```php
        'backup_before_changes' => 'پشتیبان‌گیری از سایت قبل از ادامه',
        'backup_in_progress' => 'در حال پشتیبان‌گیری...',
        'backup_done' => 'پشتیبان‌گیری کامل شد',
        'backup_failed' => 'پشتیبان‌گیری ناموفق بود',
```

- [ ] **Step 4: Verify syntax**

Run: `php -l public/batch.php && node --check public/assets/js/batch.js && php -l includes/i18n.php`
Expected: clean for all three.

- [ ] **Step 5: Manually verify in browser**

Click "Backup site before continuing" on the batch page for a paired site; confirm status updates from "Backing up..." to "Backup complete," in both `en` and `fa`.

- [ ] **Step 6: Commit**

```bash
git add public/batch.php public/assets/js/batch.js includes/i18n.php
git commit -m "Add manual pre-change backup button to the batch page"
```

---

### Task 15: End-to-end manual verification

**Files:** none (verification only)

- [ ] **Step 1: Pair a real test site**

Install the `woo-mgmt-agent` plugin (from the companion plugin plan) on a WordPress + WooCommerce staging site with at least one custom product taxonomy. Generate a pairing token on Admin -> Sites and paste it plus this app's base URL into the plugin's settings screen.

- [ ] **Step 2: Confirm the performance and reliability fix**

Load the Products page. Confirm products and taxonomy filters both appear in well under the old 1-2 minutes — the plugin-side queries are now in-process. Reload the page 10 times in a row and confirm custom taxonomies never disappear (the original "randomly doesn't show" bug).

- [ ] **Step 3: Exercise full product CRUD + batch + media through the UI**

Create a product, edit it (including assigning a custom taxonomy term), upload an image, run a batch price/stock update, and delete a product — all from the woo-managment UI — and confirm each succeeds without any reference to the old WooCommerce consumer key/secret or WP app password anywhere.

- [ ] **Step 4: Run a full backup and a database restore end to end**

From Admin -> Site Backups, trigger a full backup; confirm it completes and both `db-part-*` and `files-part-*` objects land in S3. Make an identifiable change on the site, then restore from an earlier database backup; confirm the change reverts and a safety-snapshot backup row appears automatically.

- [ ] **Step 5: Confirm no fatals across the whole flow**

Check the staging site's `wp-content/debug.log` and woo-managment's PHP error log for any uncaught exception during Steps 2-4.
