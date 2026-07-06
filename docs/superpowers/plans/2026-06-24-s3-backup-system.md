# S3 Backup System Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Automatic database and full-app backups uploaded to S3-compatible storage, triggerable on a schedule (cPanel cron) or on demand from a new Admin → Backups page, with old backups pruned after a configurable retention period.

**Architecture:** Two new classes follow the existing `AiClient`/`WooCommerceClient` pattern (a plain PHP class wrapping a third-party HTTP API via cURL, configured from the `settings` table): `S3Client` signs and sends raw `PUT`/`DELETE` requests to an S3-compatible endpoint using AWS Signature Version 4 (no AWS SDK — this app has no Composer), and `BackupManager` builds a database dump (pure PHP `SHOW CREATE TABLE`/`SELECT *` export — no `mysqldump` binary, which shared hosts often disable) or a full app archive (`ZipArchive` over the project directory + the same DB dump), uploads it via `S3Client`, and records the result in a new `backups` table. A new cron-friendly endpoint (`public/cron/backup.php`, token-authenticated instead of session-authenticated, since cron has no browser session) and a new admin page (`public/admin/backups.php`) both call `BackupManager::run()`.

**Tech Stack:** PHP `curl`, `zip` extensions (guard `zip` the same way `gd` is guarded elsewhere in this codebase — see CLAUDE.md Gotchas). Plain HTTP + AWS Signature V4, no SDK.

## Global Constraints

- **Depends on** `docs/superpowers/plans/2026-06-24-mysql-migration.md` being implemented first — the database dump logic in this plan uses MySQL-specific `SHOW CREATE TABLE` and `SHOW TABLES`.
- No Composer, no build step (per [CLAUDE.md](../../../CLAUDE.md)) — `S3Client` is hand-rolled, not an SDK.
- No test suite/linter — verify every change with `php -l <file>` and by manually exercising the page/endpoint, per CLAUDE.md's Verification section. Real S3 verification needs real credentials (yours) — there's no way to mock this; the plan calls that out explicitly where it matters.
- New settings follow the existing `SETTINGS_FIELDS` pattern in `includes/Settings.php` exactly (so they render in the existing generic Admin → Settings form for free) — except the cron token, which is system-generated and intentionally left out of that generic form (see Task 1).
- Both `en` and `fa` translations must be added for every new user-facing string — this app is bilingual and RTL-tested (per CLAUDE.md's i18n/RTL section).
- Follow the existing duplication convention: the 4-tab admin header row is already copy-pasted across `users.php`/`sites.php`/`settings.php` (no shared helper) — add the 5th "Backups" tab the same way rather than introducing a new abstraction.

---

## File Structure

- Create: `includes/S3Client.php` — AWS SigV4 PUT/DELETE client.
- Create: `includes/BackupManager.php` — dump, archive, upload, record, prune.
- Create: `public/cron/backup.php` — token-authenticated cron trigger.
- Create: `public/api/backups.php` — list + on-demand trigger (superadmin, session-authenticated).
- Create: `public/admin/backups.php` — admin page.
- Create: `public/assets/js/admin-backups.js` — page behavior.
- Modify: `includes/Database.php` — add the `backups` table.
- Modify: `includes/Settings.php` — add S3/retention settings.
- Modify: `public/api/settings.php` — add the new settings group's translation mapping.
- Modify: `includes/i18n.php` — add new `en`/`fa` strings.
- Modify: `public/admin/users.php`, `public/admin/sites.php`, `public/admin/settings.php` — add the 5th "Backups" tab to the existing tab row.
- Modify: `README.md` — document S3 backup setup and the cPanel cron job.

---

### Task 1: Schema, settings, and translations

**Files:**
- Modify: `includes/Database.php`
- Modify: `includes/Settings.php`
- Modify: `public/api/settings.php`
- Modify: `includes/i18n.php`

**Interfaces:**
- Produces: a `backups` MySQL table (`id, type, status, s3_key, size_bytes, error, created_at`); `SETTINGS_DEFAULTS`/`SETTINGS_FIELDS` keys `s3_endpoint`, `s3_region`, `s3_bucket`, `s3_access_key`, `s3_secret_key`, `backup_retention_days` (rendered in the generic settings form) and `backup_cron_token` (default-only, not in `SETTINGS_FIELDS` — read via `get_setting('backup_cron_token')`, written via `update_settings(['backup_cron_token' => ...])`, exactly like every other setting).

- [ ] **Step 1: Add the `backups` table**

In `includes/Database.php`, add this right after the existing `kv_cache` table block (before `self::$pdo = $pdo;`):

```php
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                s3_key VARCHAR(500) NOT NULL DEFAULT \'\',
                size_bytes BIGINT NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at INT NOT NULL,
                KEY idx_backups_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
```

- [ ] **Step 2: Add the new settings**

In `includes/Settings.php`, add to `SETTINGS_DEFAULTS` (after `'ai_image_model' => '',`):

```php
    'backup_cron_token'    => '',
    's3_endpoint'          => '',
    's3_region'            => '',
    's3_bucket'            => '',
    's3_access_key'        => '',
    's3_secret_key'        => '',
    'backup_retention_days' => 30,
```

Add to `SETTINGS_FIELDS` (after the `ai_image_model` entry, before the closing `];`). Note `backup_cron_token` is deliberately **not** added here — it's system-generated (Task 2) and only ever shown on the Admin → Backups page, not the generic settings form:

```php
    's3_endpoint' => [
        'label' => 'S3 endpoint URL',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Base URL of your S3-compatible storage, e.g. https://s3.us-east-1.amazonaws.com or a custom/self-hosted endpoint.',
    ],
    's3_region' => [
        'label' => 'S3 region',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Region name required for request signing, e.g. us-east-1 (use any value your provider expects if it is not AWS).',
    ],
    's3_bucket' => [
        'label' => 'S3 bucket name',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Bucket backups are uploaded to. Create it with your storage provider first.',
    ],
    's3_access_key' => [
        'label' => 'S3 access key',
        'type' => 'password',
        'group' => 'Backups (S3)',
        'help' => 'Access key ID for the bucket above.',
    ],
    's3_secret_key' => [
        'label' => 'S3 secret key',
        'type' => 'password',
        'group' => 'Backups (S3)',
        'help' => 'Secret access key for the bucket above.',
    ],
    'backup_retention_days' => [
        'label' => 'Backup retention (days)',
        'type' => 'number',
        'group' => 'Backups (S3)',
        'help' => 'Backups older than this are deleted from S3 automatically after each run.',
    ],
```

- [ ] **Step 3: Map the new group's translation key**

In `public/api/settings.php`, in `settings_group_key()`'s `$map`, add:

```php
        'Backups (S3)'        => 'group_backup',
```

- [ ] **Step 4: Add translations**

In `includes/i18n.php`, `en` block: add after `'group_ai' => 'AI (OpenRouter)',`:

```php
        'group_backup' => 'Backups (S3)',
        's3_endpoint_label' => 'S3 endpoint URL',
        's3_endpoint_help' => 'Base URL of your S3-compatible storage, e.g. https://s3.us-east-1.amazonaws.com or a custom/self-hosted endpoint.',
        's3_region_label' => 'S3 region',
        's3_region_help' => 'Region name required for request signing, e.g. us-east-1.',
        's3_bucket_label' => 'S3 bucket name',
        's3_bucket_help' => 'Bucket backups are uploaded to. Create it with your storage provider first.',
        's3_access_key_label' => 'S3 access key',
        's3_access_key_help' => 'Access key ID for the bucket above.',
        's3_secret_key_label' => 'S3 secret key',
        's3_secret_key_help' => 'Secret access key for the bucket above.',
        'backup_retention_days_label' => 'Backup retention (days)',
        'backup_retention_days_help' => 'Backups older than this are deleted from S3 automatically after each run.',
        'backups' => 'Backups',
        'backup_history' => 'Backup history',
        'run_db_backup' => 'Run database backup now',
        'run_full_backup' => 'Run full backup now',
        'running_backup' => 'Running...',
        'backup_succeeded' => 'Backup completed',
        'backup_failed' => 'Backup failed',
        'no_backups_yet' => 'No backups yet.',
        'backup_type_database' => 'Database',
        'backup_type_full' => 'Full',
        'backup_status_success' => 'Success',
        'backup_status_failed' => 'Failed',
        'cron_setup_heading' => 'Scheduled backups',
        'cron_setup_help' => 'Add these URLs as cPanel Cron Jobs (e.g. daily for database, weekly for full) so backups run automatically:',
```

`fa` block: add after `'group_ai' => 'هوش مصنوعی (OpenRouter)',`:

```php
        'group_backup' => 'پشتیبان‌گیری (S3)',
        's3_endpoint_label' => 'آدرس Endpoint اس‌تری',
        's3_endpoint_help' => 'آدرس پایه فضای ذخیره‌سازی S3-compatible شما، مثلاً https://s3.us-east-1.amazonaws.com یا یک endpoint اختصاصی.',
        's3_region_label' => 'منطقه (Region) اس‌تری',
        's3_region_help' => 'نام منطقه لازم برای امضای درخواست‌ها، مثلاً us-east-1.',
        's3_bucket_label' => 'نام Bucket',
        's3_bucket_help' => 'باکتی که پشتیبان‌ها در آن آپلود می‌شوند. ابتدا آن را نزد سرویس ذخیره‌سازی خود بسازید.',
        's3_access_key_label' => 'کلید دسترسی S3',
        's3_access_key_help' => 'Access Key مربوط به باکت بالا.',
        's3_secret_key_label' => 'کلید محرمانه S3',
        's3_secret_key_help' => 'Secret Key مربوط به باکت بالا.',
        'backup_retention_days_label' => 'مدت نگه‌داری پشتیبان (روز)',
        'backup_retention_days_help' => 'پشتیبان‌های قدیمی‌تر از این مدت پس از هر اجرا به‌طور خودکار از S3 حذف می‌شوند.',
        'backups' => 'پشتیبان‌گیری',
        'backup_history' => 'تاریخچه پشتیبان‌گیری',
        'run_db_backup' => 'اجرای پشتیبان دیتابیس',
        'run_full_backup' => 'اجرای پشتیبان کامل',
        'running_backup' => 'در حال اجرا...',
        'backup_succeeded' => 'پشتیبان‌گیری با موفقیت انجام شد',
        'backup_failed' => 'پشتیبان‌گیری ناموفق بود',
        'no_backups_yet' => 'هنوز پشتیبانی ثبت نشده است.',
        'backup_type_database' => 'دیتابیس',
        'backup_type_full' => 'کامل',
        'backup_status_success' => 'موفق',
        'backup_status_failed' => 'ناموفق',
        'cron_setup_heading' => 'پشتیبان‌گیری زمان‌بندی‌شده',
        'cron_setup_help' => 'این آدرس‌ها را به‌عنوان Cron Job در cPanel ثبت کنید (مثلاً دیتابیس روزانه، کامل هفتگی) تا پشتیبان‌گیری به‌طور خودکار اجرا شود:',
```

- [ ] **Step 5: Verify**

Run: `php -l includes/Database.php && php -l includes/Settings.php && php -l public/api/settings.php && php -l includes/i18n.php`
Expected: `No syntax errors detected` for all four.

```bash
php -r "require 'includes/Database.php'; Database::get(); echo \"ok\n\";"
php -r "
require 'includes/Database.php';
\$pdo = Database::get();
print_r(\$pdo->query(\"SHOW TABLES LIKE 'backups'\")->fetchAll());
"
```
Expected: second command prints one row (the `backups` table exists).

- [ ] **Step 6: Commit**

```bash
git add includes/Database.php includes/Settings.php public/api/settings.php includes/i18n.php
git commit -m "Add backups table, S3 settings, and translations"
```

---

### Task 2: `S3Client` — AWS Signature V4 PUT/DELETE

**Files:**
- Create: `includes/S3Client.php`

**Interfaces:**
- Consumes: nothing from earlier tasks (standalone, like `AiClient`).
- Produces: `S3Client::__construct(array $config)` (`endpoint`, `region`, `bucket`, `access_key`, `secret_key`), `isConfigured(): bool`, `putObject(string $key, string $body, string $contentType): array{ok: bool, error: string}`, `deleteObject(string $key): array{ok: bool, error: string}` — Task 3's `BackupManager` calls exactly these.

- [ ] **Step 1: Write the file**

```php
<?php

/**
 * Minimal S3-compatible storage client (AWS Signature Version 4, raw cURL —
 * no AWS SDK, since this app has no Composer/build step). Works against AWS
 * S3 and S3-compatible services (MinIO, DigitalOcean Spaces, Backblaze B2's
 * S3-compatible endpoint, etc.) via path-style addressing:
 * {endpoint}/{bucket}/{key}.
 */
class S3Client
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;

    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->region = (string) ($config['region'] ?? '');
        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->accessKey = (string) ($config['access_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->region !== '' && $this->bucket !== ''
            && $this->accessKey !== '' && $this->secretKey !== '';
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function putObject(string $key, string $body, string $contentType): array
    {
        return $this->request('PUT', $key, $body, ['Content-Type: ' . $contentType]);
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function deleteObject(string $key): array
    {
        return $this->request('DELETE', $key, '');
    }

    /**
     * Signs and sends a request to a single object using AWS Signature
     * Version 4. PUT/DELETE object both need no query string, which keeps
     * the canonical request simple.
     *
     * @return array{ok: bool, error: string}
     */
    private function request(string $method, string $key, string $body, array $extraHeaders = []): array
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalUri = '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        $url = "{$scheme}://{$host}{$canonicalUri}";

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "{$method}\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = array_merge([
            "Host: {$host}",
            "X-Amz-Date: {$amzDate}",
            "X-Amz-Content-Sha256: {$payloadHash}",
            "Authorization: {$authorization}",
        ], $extraHeaders);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
        ];
        if ($method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'Connection error: ' . $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $message = "S3 request failed (HTTP {$status})";
            if (preg_match('/<Message>(.*?)<\/Message>/s', (string) $response, $m)) {
                $message .= ': ' . $m[1];
            }
            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'error' => ''];
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/S3Client.php`
Expected: `No syntax errors detected in includes/S3Client.php`

- [ ] **Step 3: Verify against a real bucket**

This step needs real credentials — there's no way to fake an S3-compatible
service. Using the bucket/credentials you intend to configure in the app:

```bash
php -r "
require 'includes/S3Client.php';
\$s3 = new S3Client([
    'endpoint' => 'https://YOUR-ENDPOINT',
    'region' => 'YOUR-REGION',
    'bucket' => 'YOUR-BUCKET',
    'access_key' => 'YOUR-ACCESS-KEY',
    'secret_key' => 'YOUR-SECRET-KEY',
]);
var_dump(\$s3->putObject('wcpm-test/hello.txt', 'hello world', 'text/plain'));
var_dump(\$s3->deleteObject('wcpm-test/hello.txt'));
"
```
Expected: both calls print `array(2) { ["ok"]=> bool(true) ["error"]=> string(0) "" }`.
Confirm the `hello.txt` object briefly appeared in the bucket (e.g. via your
provider's web console) and is gone after the delete call.

If `ok` is `false`, the `error` string includes the S3 service's own
`<Message>` (e.g. `SignatureDoesNotMatch`, `AccessDenied`, `NoSuchBucket`) —
use that to fix the config rather than guessing.

- [ ] **Step 4: Commit**

```bash
git add includes/S3Client.php
git commit -m "Add S3Client (AWS SigV4 PUT/DELETE)"
```

---

### Task 3: `BackupManager` — dump, archive, upload, prune

**Files:**
- Create: `includes/BackupManager.php`

**Interfaces:**
- Consumes: `Database::get()`, `get_settings()`/`get_setting()`/`update_settings()` (Task 1), `S3Client` (Task 2).
- Produces: `BackupManager::run(string $type): array{ok: bool, type: string, s3_key: string, size_bytes: int, error: string}`, `BackupManager::listRecent(int $limit = 50): array`, `BackupManager::ensureCronToken(): string` — Tasks 4-6 (cron endpoint, API endpoint, admin page) call exactly these three.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/S3Client.php';

/**
 * Builds and uploads database/full backups to S3-compatible storage, and
 * tracks each run in the `backups` table. Triggered either from
 * public/cron/backup.php (cron, token-authenticated) or the "Run backup
 * now" buttons on Admin -> Backups (session-authenticated).
 */
class BackupManager
{
    /**
     * Runs a backup of the given type ('database' or 'full'), uploads it to
     * S3, records the result, and prunes backups older than the configured
     * retention period. Always returns a result array; never throws.
     *
     * @return array{ok: bool, type: string, s3_key: string, size_bytes: int, error: string}
     */
    public static function run(string $type): array
    {
        $type = $type === 'full' ? 'full' : 'database';

        $s3 = self::s3Client();
        if ($s3 === null) {
            return self::recordFailure($type, 'S3 backup storage is not configured (see Admin -> Settings).');
        }

        try {
            $dbDumpPath = self::writeDatabaseDumpToTempFile();
            if ($type === 'database') {
                $localPath = $dbDumpPath;
                $contentType = 'application/sql';
                $extension = '.sql';
            } else {
                $localPath = self::buildFullArchive($dbDumpPath);
                @unlink($dbDumpPath);
                $contentType = 'application/zip';
                $extension = '.zip';
            }
        } catch (Throwable $e) {
            return self::recordFailure($type, $e->getMessage());
        }

        $key = 'backups/' . $type . '-' . gmdate('Y-m-d_H-i-s') . $extension;
        $size = (int) filesize($localPath);
        $body = (string) file_get_contents($localPath);
        @unlink($localPath);

        $result = $s3->putObject($key, $body, $contentType);
        if (!$result['ok']) {
            return self::recordFailure($type, $result['error'], $key, $size);
        }

        self::recordResult($type, 'success', $key, $size, '');
        self::pruneOldBackups($s3);

        return ['ok' => true, 'type' => $type, 's3_key' => $key, 'size_bytes' => $size, 'error' => ''];
    }

    /**
     * Returns the most recent backups (newest first), for the Admin -> Backups list.
     */
    public static function listRecent(int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM backups ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Returns the secret token used to authenticate public/cron/backup.php
     * requests, generating and persisting one the first time it's needed.
     */
    public static function ensureCronToken(): string
    {
        $token = (string) get_setting('backup_cron_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            update_settings(['backup_cron_token' => $token]);
        }
        return $token;
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

    /**
     * Dumps every table's schema and data to a plain .sql file. Pure PHP —
     * no mysqldump binary, since shared hosts often disable shell_exec.
     */
    private static function writeDatabaseDumpToTempFile(): string
    {
        $pdo = Database::get();
        $path = tempnam(sys_get_temp_dir(), 'wcpm_db_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the database dump.');
        }

        $fh = fopen($path, 'w');
        fwrite($fh, '-- Database backup generated ' . gmdate('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow['Create Table'] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            while ($row = $stmt->fetch()) {
                $columns = array_map(fn($c) => "`{$c}`", array_keys($row));
                $values = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
                fwrite($fh, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $path;
    }

    /**
     * Zips the whole project directory (excluding .git) plus the given
     * database dump file into one archive, so a "full" backup can restore
     * the app from scratch.
     */
    private static function buildFullArchive(string $dbDumpPath): string
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The PHP zip extension is not enabled; full backups are unavailable (database-only backups still work).');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'wcpm_zip_');
        if ($zipPath === false) {
            throw new RuntimeException('Could not create a temporary file for the backup archive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup archive.');
        }

        $projectRoot = (string) realpath(__DIR__ . '/..');
        $exclude = ['.git'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $localPath = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));

            $skip = false;
            foreach ($exclude as $excluded) {
                if ($localPath === $excluded || str_starts_with($localPath, $excluded . '/')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }

        $zip->addFile($dbDumpPath, 'database.sql');
        $zip->close();

        return $zipPath;
    }

    private static function recordResult(string $type, string $status, string $s3Key, int $size, string $error): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO backups (type, status, s3_key, size_bytes, error, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $status, $s3Key, $size, $error !== '' ? $error : null, time()]);
    }

    /**
     * @return array{ok: bool, type: string, s3_key: string, size_bytes: int, error: string}
     */
    private static function recordFailure(string $type, string $error, string $s3Key = '', int $size = 0): array
    {
        self::recordResult($type, 'failed', $s3Key, $size, $error);
        return ['ok' => false, 'type' => $type, 's3_key' => $s3Key, 'size_bytes' => $size, 'error' => $error];
    }

    /**
     * Deletes backups (both the S3 object and the local record) older than
     * the configured retention period, so storage cost doesn't grow
     * unbounded. Best-effort: a failed S3 delete just leaves that row for
     * the next run to retry.
     */
    private static function pruneOldBackups(S3Client $s3): void
    {
        $retentionDays = max(1, (int) get_setting('backup_retention_days'));
        $cutoff = time() - ($retentionDays * 86400);

        $pdo = Database::get();
        $stmt = $pdo->prepare("SELECT id, s3_key FROM backups WHERE status = 'success' AND created_at < ?");
        $stmt->execute([$cutoff]);

        foreach ($stmt->fetchAll() as $row) {
            $result = $s3->deleteObject($row['s3_key']);
            if ($result['ok']) {
                $del = $pdo->prepare('DELETE FROM backups WHERE id = ?');
                $del->execute([$row['id']]);
            }
        }
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/BackupManager.php`
Expected: `No syntax errors detected in includes/BackupManager.php`

- [ ] **Step 3: Verify the database dump in isolation**

```bash
php -r "
require 'includes/BackupManager.php';
\$ref = new ReflectionClass('BackupManager');
\$method = \$ref->getMethod('writeDatabaseDumpToTempFile');
\$method->setAccessible(true);
\$path = \$method->invoke(null);
echo \"dump written to: \$path (\" . filesize(\$path) . \" bytes)\n\";
echo substr(file_get_contents(\$path), 0, 400) . \"\n\";
unlink(\$path);
"
```
Expected: prints a path and byte size, then the dump's header comment and
the first `CREATE TABLE` statement (for `otp_codes`, the first table
created in `Database.php`).

- [ ] **Step 4: Verify a full run end-to-end (needs your real S3 settings)**

With `s3_endpoint`/`s3_region`/`s3_bucket`/`s3_access_key`/`s3_secret_key`
already saved via `update_settings()` (or set them directly with the same
`php -r` one-liner pattern as Task 1 Step 5):

```bash
php -r "
require 'includes/BackupManager.php';
var_dump(BackupManager::run('database'));
var_dump(BackupManager::run('full'));
print_r(BackupManager::listRecent(5));
"
```
Expected: both `run()` calls return `'ok' => true` with a non-empty
`s3_key` and `size_bytes > 0`; `listRecent()` shows both rows with
`status = 'success'`. Confirm both objects actually landed in your bucket
via your provider's console.

- [ ] **Step 5: Commit**

```bash
git add includes/BackupManager.php
git commit -m "Add BackupManager (database/full backup, upload, retention)"
```

---

### Task 4: Cron-triggered backup endpoint

**Files:**
- Create: `public/cron/backup.php`

**Interfaces:**
- Consumes: `BackupManager::run()` (Task 3), `get_setting('backup_cron_token')` (Task 1/3).

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/BackupManager.php';

// No session here — cron jobs don't carry a browser session, so this
// endpoint is authenticated with a long random token instead (generated by
// BackupManager::ensureCronToken(), shown on Admin -> Backups).
$token = (string) ($_GET['token'] ?? '');
$expected = (string) get_setting('backup_cron_token');

if ($expected === '' || !hash_equals($expected, $token)) {
    json_response(['error' => 'Invalid token'], 403);
}

$type = ($_GET['type'] ?? 'database') === 'full' ? 'full' : 'database';
$result = BackupManager::run($type);

json_response($result, $result['ok'] ? 200 : 502);
```

- [ ] **Step 2: Verify syntax**

Run: `php -l public/cron/backup.php`
Expected: `No syntax errors detected in public/cron/backup.php`

- [ ] **Step 3: Verify with a real request**

Start a local server and hit the endpoint with both a wrong and the real token:
```bash
php -S localhost:8080 -t public
```
```bash
curl -s "http://localhost:8080/cron/backup.php?type=database&token=wrong"
curl -s "http://localhost:8080/cron/backup.php?type=database&token=$(php -r "require 'includes/BackupManager.php'; echo BackupManager::ensureCronToken();")"
```
Expected: first call returns `{"error":"Invalid token"}`; second returns
`{"ok":true,"type":"database","s3_key":"...","size_bytes":...,"error":""}`
(requires S3 settings to already be configured, same as Task 3 Step 4).

- [ ] **Step 4: Commit**

```bash
git add public/cron/backup.php
git commit -m "Add cron-triggered backup endpoint"
```

---

### Task 5: Admin API endpoint (list + on-demand trigger)

**Files:**
- Create: `public/api/backups.php`

**Interfaces:**
- Consumes: `BackupManager::run()`, `BackupManager::listRecent()` (Task 3); `require_superadmin_api()`, `verify_csrf_api()`, `json_body()` (existing `includes/auth.php`/`includes/helpers.php`, same as every other `public/api/*.php` file).
- Produces: `GET /api/backups.php` → `{items: [...]}`; `POST /api/backups.php` with `{"type": "database"|"full"}` → `{ok: true, item: {...}}` — Task 6's `admin-backups.js` calls exactly this shape.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/BackupManager.php';

$user = require_superadmin_api();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    json_response(['items' => array_map('format_backup_row', BackupManager::listRecent())]);
}

if ($method === 'POST') {
    verify_csrf_api();
    $body = json_body();
    $type = ($body['type'] ?? 'database') === 'full' ? 'full' : 'database';

    $result = BackupManager::run($type);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['ok' => true, 'item' => $result]);
}

json_response(['error' => 'Method not allowed'], 405);

function format_backup_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => $row['type'],
        'status' => $row['status'],
        's3_key' => $row['s3_key'],
        'size_bytes' => (int) $row['size_bytes'],
        'error' => $row['error'],
        'created_at' => (int) $row['created_at'],
    ];
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l public/api/backups.php`
Expected: `No syntax errors detected in public/api/backups.php`

- [ ] **Step 3: Verify with a logged-in superadmin session**

Start the local server (`php -S localhost:8080 -t public` if not already
running) and log in as a superadmin in a browser, then in the same
browser's dev console (so cookies are sent):
```js
fetch('/api/backups.php').then(r => r.json()).then(console.log)
```
Expected: `{items: [...]}` matching whatever Task 3/4's verification runs
already produced.

- [ ] **Step 4: Commit**

```bash
git add public/api/backups.php
git commit -m "Add backups API endpoint (list + on-demand trigger)"
```

---

### Task 6: Admin page

**Files:**
- Create: `public/admin/backups.php`
- Create: `public/assets/js/admin-backups.js`
- Modify: `public/admin/users.php:32-37`
- Modify: `public/admin/sites.php:32-37`
- Modify: `public/admin/settings.php:29-34`

**Interfaces:**
- Consumes: `/api/backups.php` (Task 5), `BackupManager::ensureCronToken()` (Task 3), `App.api`/`App.toast`/`App.csrfToken`/`App.setCsrfToken`/`App.logout` (existing `public/assets/js/app.js`, same as every other admin JS file), `t()` (existing `public/assets/js/i18n.js`).

- [ ] **Step 1: Write `public/admin/backups.php`**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/pwa.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/nav.php';
require_once __DIR__ . '/../../includes/BackupManager.php';

$user = require_superadmin_page();

$cronToken = BackupManager::ensureCronToken();
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
$cronUrlDb = $scheme . $_SERVER['HTTP_HOST'] . '/cron/backup.php?type=database&token=' . $cronToken;
$cronUrlFull = $scheme . $_SERVER['HTTP_HOST'] . '/cron/backup.php?type=full&token=' . $cronToken;
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('backups')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('settings', $user); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('backups')); ?></h1>
    </div>
    <div class="px-4 pb-3 flex gap-2 text-sm">
      <a href="/admin/users.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('users')); ?></a>
      <a href="/admin/sites.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t("sites")); ?></a>
      <a href="/admin/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('settings')); ?></a>
      <a href="/admin/backups.php" class="flex-1 text-center rounded-xl bg-gray-900 text-white py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
      <a href="/settings.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('account_section')); ?></a>
    </div>
  </header>

  <main class="px-4 py-3 space-y-4">
    <section class="bg-white rounded-2xl border border-gray-100 p-4">
      <div class="flex gap-2">
        <button id="run-db-backup-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_db_backup')); ?></button>
        <button id="run-full-backup-btn" class="flex-1 rounded-xl border border-gray-300 text-gray-700 font-medium py-2.5 text-sm"><?php echo htmlspecialchars(t('run_full_backup')); ?></button>
      </div>
    </section>

    <section class="bg-white rounded-2xl border border-gray-100 p-4 space-y-2">
      <h2 class="text-sm font-semibold text-gray-900"><?php echo htmlspecialchars(t('cron_setup_heading')); ?></h2>
      <p class="text-xs text-gray-400"><?php echo htmlspecialchars(t('cron_setup_help')); ?></p>
      <p class="text-xs font-mono bg-gray-50 rounded-lg p-2 break-all" dir="ltr"><?php echo htmlspecialchars($cronUrlDb); ?></p>
      <p class="text-xs font-mono bg-gray-50 rounded-lg p-2 break-all" dir="ltr"><?php echo htmlspecialchars($cronUrlFull); ?></p>
    </section>

    <section>
      <h2 class="text-sm font-semibold text-gray-900 mb-2 px-1"><?php echo htmlspecialchars(t('backup_history')); ?></h2>
      <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
      <div id="backup-list" class="hidden space-y-2"></div>
    </section>
  </main>

  <?php render_bottom_nav('settings', $user); ?>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/admin-backups.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
```

- [ ] **Step 2: Write `public/assets/js/admin-backups.js`**

```js
/**
 * Superadmin: trigger and review database/full backups to S3.
 */

init();

async function init() {
  await ensureSession();
  document.getElementById('logout-btn').addEventListener('click', () => App.logout());
  document.getElementById('run-db-backup-btn').addEventListener('click', () => runBackup('database'));
  document.getElementById('run-full-backup-btn').addEventListener('click', () => runBackup('full'));
  await loadBackups();
}

async function ensureSession() {
  if (App.csrfToken()) return;
  try {
    const me = await App.api('/api/auth.php?action=me');
    if (me.authenticated) {
      App.setCsrfToken(me.csrf_token);
    }
  } catch (e) {
    // ignore
  }
}

async function loadBackups() {
  try {
    const data = await App.api('/api/backups.php');
    renderBackups(data.items);
  } catch (e) {
    App.toast(e.message, 'error');
  } finally {
    document.getElementById('loading').classList.add('hidden');
  }
}

function renderBackups(items) {
  const list = document.getElementById('backup-list');
  list.classList.remove('hidden');
  list.innerHTML = '';

  if (items.length === 0) {
    list.innerHTML = `<p class="text-center text-sm text-gray-400 py-8">${escapeHtml(t('no_backups_yet'))}</p>`;
    return;
  }

  items.forEach((item) => {
    const row = document.createElement('div');
    row.className = 'bg-white rounded-2xl border border-gray-100 p-3 flex items-center justify-between text-sm';
    const typeLabel = item.type === 'full' ? t('backup_type_full') : t('backup_type_database');
    const statusLabel = item.status === 'success' ? t('backup_status_success') : t('backup_status_failed');
    const statusClass = item.status === 'success' ? 'text-green-600' : 'text-red-600';
    const sizeKb = (item.size_bytes / 1024).toFixed(1);
    const date = new Date(item.created_at * 1000).toLocaleString();

    row.innerHTML = `
      <div>
        <div class="font-medium text-gray-900">${escapeHtml(typeLabel)} &middot; <span class="${statusClass}">${escapeHtml(statusLabel)}</span></div>
        <div class="text-xs text-gray-400 mt-0.5">${escapeHtml(date)} &middot; ${sizeKb} KB</div>
        ${item.error ? `<div class="text-xs text-red-500 mt-0.5">${escapeHtml(item.error)}</div>` : ''}
      </div>
    `;
    list.appendChild(row);
  });
}

async function runBackup(type) {
  const btn = document.getElementById(type === 'full' ? 'run-full-backup-btn' : 'run-db-backup-btn');
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = t('running_backup');

  try {
    await App.api('/api/backups.php', { method: 'POST', body: JSON.stringify({ type }) });
    App.toast(t('backup_succeeded'), 'success');
    await loadBackups();
  } catch (err) {
    App.toast(err.message || t('backup_failed'), 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}

function escapeHtml(str) {
  const div = document.createElement('div');
  div.textContent = str == null ? '' : String(str);
  return div.innerHTML;
}
```

- [ ] **Step 3: Add the 5th tab to the three existing admin pages**

In `public/admin/users.php`, `public/admin/sites.php`, and
`public/admin/settings.php`, find the 4-link tab row (4 lines starting with
`<a href="/admin/users.php"` / `<a href="/admin/sites.php"` /
`<a href="/admin/settings.php"` / `<a href="/settings.php"`) and insert a new
link for Backups right before the `/settings.php` (Account) link:

```php
      <a href="/admin/backups.php" class="flex-1 text-center rounded-xl border border-gray-300 text-gray-700 py-2 font-medium"><?php echo htmlspecialchars(t('backups')); ?></a>
```

(The new `backups.php` page itself already has this link styled as the
active/highlighted tab — see Step 1.)

- [ ] **Step 4: Verify syntax**

Run: `php -l public/admin/backups.php && node --check public/assets/js/admin-backups.js && php -l public/admin/users.php && php -l public/admin/sites.php && php -l public/admin/settings.php`
Expected: `No syntax errors detected`/no output (success) for all five.

- [ ] **Step 5: Manually verify in a browser**

With the local server running and logged in as superadmin, visit
`/admin/backups.php`. Confirm:
- The 5-tab row appears on this page and on Users/Sites/Settings/Account,
  with "Backups" highlighted only on this page.
- The two cron URLs are shown, each containing a real (non-empty) token.
- Clicking "Run database backup now" disables the button, shows
  "Running...", then shows a success toast and a new row in the history
  list below (requires S3 settings configured, same as earlier tasks).
- Switch to Persian (فا) and confirm the page renders RTL with translated
  labels, and the cron URLs stay left-to-right (`dir="ltr"`) since URLs
  shouldn't be reversed.

- [ ] **Step 6: Commit**

```bash
git add public/admin/backups.php public/assets/js/admin-backups.js public/admin/users.php public/admin/sites.php public/admin/settings.php
git commit -m "Add Admin -> Backups page"
```

---

### Task 7: Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add a new section**

After the "Optional: AI-generated descriptions" section in `README.md`, add:

```markdown
## Optional: Automated backups (S3)

To enable database and full-app backups to S3-compatible storage, go to
*Admin → Settings* and fill in the **Backups (S3)** section:

- **S3 endpoint URL**: e.g. `https://s3.us-east-1.amazonaws.com` for AWS, or
  your provider's endpoint for MinIO/DigitalOcean Spaces/Backblaze B2/etc.
- **S3 region**, **S3 bucket name**, **S3 access key**, **S3 secret key**:
  from your storage provider. Create the bucket yourself first.
- **Backup retention (days)**: backups older than this are deleted from S3
  automatically after each run (default 30).

Then go to *Admin → Backups* to:

- Trigger a database-only or full (code + database) backup immediately with
  the "Run backup now" buttons.
- Copy the two pre-built cron URLs (each includes an auto-generated secret
  token) into cPanel → *Cron Jobs*, e.g.:
  - Database backup daily: `0 3 * * *` → `wget -q -O /dev/null "https://yourdomain.com/cron/backup.php?type=database&token=..."`
  - Full backup weekly: `0 4 * * 0` → `wget -q -O /dev/null "https://yourdomain.com/cron/backup.php?type=full&token=..."`
- Review backup history (type, status, size, and any error) on the same page.

This feature is entirely optional: if no S3 settings are configured, backup
attempts simply fail with a clear error on the Admin → Backups page, and the
rest of the app is unaffected.
```

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "Document the S3 backup system"
```
