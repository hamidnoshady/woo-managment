# Woo Management Agent Plugin Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build `woo-mgmt-agent`, a WordPress plugin that becomes the sole connection method between woo-managment and a managed site — one paired bearer token covers products/categories/taxonomies/media (all computed in-process, no outbound HTTP) plus chunked, resumable, S3-direct backup/restore.

**Architecture:** A single REST namespace (`wma/v1`) authenticated by one shared token. Product/category/taxonomy/media routes call WooCommerce/WordPress native PHP APIs directly and return one response per call — no per-taxonomy HTTP round-trips, no silent partial failures. Backup/restore routes drive a resumable job-table state machine, identical in design to the original backup-only plugin, just renamed.

**Tech Stack:** Plain PHP (WordPress plugin conventions: `$wpdb`, `register_rest_route`, `wc_get_products()`, `wp_insert_attachment()`, `ZipArchive`). No build step, no Composer.

## Global Constraints

- This plugin supersedes and replaces the `woo-mgmt-backup-agent` plugin design — same job-table/backup/restore architecture, renamed throughout (`Wmba_*` → `Wma_*`, `wmba/v1` → `wma/v1`, directory `wp-plugin/woo-mgmt-backup-agent/` → `wp-plugin/woo-mgmt-agent/`).
- No `exec()`/`shell_exec()`, no assumption that PHP time/memory limits can be raised arbitrarily, no assumption real WP-Cron fires reliably (same shared-hosting constraints as before).
- Every route is authenticated by the same Bearer pairing token — no separate WooCommerce consumer key/secret or WP application password anywhere in this plugin.
- Product/category/taxonomy reads must be one in-process call each — never a loop of outbound HTTP requests (this is the fix for the original 1-2 minute load and the silent taxonomy-disappearance bug).
- A plugin-side error (invalid data, WooCommerce inactive) must return a clear HTTP 4xx/5xx with a message body — never an empty array standing in for a failure.
- No test suite/linter/build step exists for this kind of code in this project — verify with `php -l` on every file, plus a full manual install+exercise pass at the end.
- `created_at`/`updated_at` use Unix timestamps (`int`), matching the parent project's `Database.php` convention.

---

## File Structure

```
wp-plugin/woo-mgmt-agent/
  woo-mgmt-agent.php              Plugin bootstrap: header, activation hook, hook registration
  includes/
    class-wma-db.php              Job table schema + CRUD (create_job/get_job/save_job)
    class-wma-settings.php        Pairing settings screen (token + base URL + status)
    class-wma-auth.php            Bearer token verification for REST requests
    class-wma-relay.php           Talks to woo-managment for presigned S3 URLs (backup/restore only)
    class-wma-db-dumper.php       Chunked DB dump step
    class-wma-file-archiver.php   Chunked wp-content zip step (full scope only)
    class-wma-backup-job.php      Backup job step machine
    class-wma-restore-job.php     Restore job step machine
    class-wma-products.php        Product list/get/create/update/delete/batch (native WC queries)
    class-wma-taxonomies.php      Categories + custom taxonomies (native WP queries)
    class-wma-media.php           Image upload (native wp_insert_attachment)
    class-wma-rest.php            REST route registration + handlers for all of the above
  uninstall.php                   Drops wma_jobs table, deletes options
```

---

### Task 1: Plugin bootstrap and job table

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-db.php`

**Interfaces:**
- Produces: `Wma_Db::create_table(): void`, `Wma_Db::create_job(int $job_id, string $kind, string $scope, array $parts = []): array`, `Wma_Db::get_job(int $job_id): ?array`, `Wma_Db::save_job(array $job): void`, `Wma_Db::delete_job(int $job_id): void`

- [ ] **Step 1: Write the job-store class**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-db.php

defined('ABSPATH') || exit;

/**
 * One row per backup/restore job. `job_id` is assigned by woo-managment
 * (the primary key of its own site_backups/site_restores row, offset for
 * restores — see SiteBackupManager::agentJobId() on the app side) rather
 * than auto-incremented here, so both sides agree on identity.
 */
class Wma_Db
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wma_jobs';
    }

    public static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            job_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            scope VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            step VARCHAR(30) NOT NULL DEFAULT 'start',
            cursor_json LONGTEXT NULL,
            parts_json LONGTEXT NULL,
            temp_dir VARCHAR(255) NULL,
            safety_job_id BIGINT UNSIGNED NULL,
            error TEXT NULL,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            PRIMARY KEY  (job_id)
        ) {$charset};");
    }

    public static function create_job(int $job_id, string $kind, string $scope, array $parts = []): array
    {
        global $wpdb;
        $now = time();
        $job = [
            'job_id' => $job_id,
            'kind' => $kind,
            'scope' => $scope,
            'status' => 'running',
            'step' => $kind === 'restore' ? 'safety_backup' : 'dump_db',
            'cursor_json' => wp_json_encode(['table_index' => 0, 'offset' => 0, 'file_index' => 0]),
            'parts_json' => wp_json_encode($kind === 'restore' ? $parts : []),
            'temp_dir' => '',
            'safety_job_id' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $wpdb->insert(self::table(), $job);
        return $job;
    }

    public static function get_job(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE job_id = %d', $job_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function save_job(array $job): void
    {
        global $wpdb;
        $job['updated_at'] = time();
        $wpdb->update(self::table(), $job, ['job_id' => $job['job_id']]);
    }

    public static function delete_job(int $job_id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['job_id' => $job_id]);
    }
}
```

- [ ] **Step 2: Write the plugin bootstrap**

```php
<?php
// wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php

/**
 * Plugin Name: Woo Management Agent
 * Description: Sole connection method for the woo-managment app: products, categories, custom taxonomies, media, and S3 backup/restore, all over one paired secure channel.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('WMA_DIR', __DIR__);

require_once WMA_DIR . '/includes/class-wma-db.php';

register_activation_hook(__FILE__, function () {
    Wma_Db::create_table();
});
```

- [ ] **Step 3: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php && php -l wp-plugin/woo-mgmt-agent/includes/class-wma-db.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php wp-plugin/woo-mgmt-agent/includes/class-wma-db.php
git commit -m "Add woo-mgmt-agent plugin bootstrap and job table"
```

---

### Task 2: Pairing settings screen with status indicators

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-settings.php`
- Modify: `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`

**Interfaces:**
- Produces: `Wma_Settings::get_token(): string`, `Wma_Settings::get_base_url(): string`, `Wma_Settings::register(): void`

- [ ] **Step 1: Write the settings class**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-settings.php

defined('ABSPATH') || exit;

/**
 * Pairing screen under Settings -> Woo Management Agent: admin pastes the
 * token and base URL generated by woo-managment. Both are plain options
 * (not hashed) because the plugin must present this same token back to
 * woo-managment on every outbound call (presign requests), not just verify
 * inbound ones.
 */
class Wma_Settings
{
    public static function get_token(): string
    {
        return (string) get_option('wma_token', '');
    }

    public static function get_base_url(): string
    {
        return rtrim((string) get_option('wma_base_url', ''), '/');
    }

    public static function register(): void
    {
        add_action('admin_menu', [self::class, 'add_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function add_menu(): void
    {
        add_options_page(
            'Woo Management Agent',
            'Woo Management Agent',
            'manage_options',
            'wma-settings',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('wma', 'wma_token');
        register_setting('wma', 'wma_base_url');
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $wooActive = class_exists('WooCommerce');

        echo '<div class="wrap"><h1>Woo Management Agent</h1>';
        echo '<p>WooCommerce: ' . ($wooActive ? '<strong style="color:green">detected</strong>' : '<strong style="color:red">not detected</strong>') . '</p>';

        echo '<form method="post" action="options.php">';
        settings_fields('wma');
        echo '<table class="form-table">';
        echo '<tr><th><label for="wma_base_url">woo-managment base URL</label></th><td>';
        echo '<input type="url" id="wma_base_url" name="wma_base_url" class="regular-text" value="' . esc_attr(self::get_base_url()) . '" required></td></tr>';
        echo '<tr><th><label for="wma_token">Pairing token</label></th><td>';
        echo '<input type="text" id="wma_token" name="wma_token" class="regular-text" value="' . esc_attr(self::get_token()) . '" required></td></tr>';
        echo '</table>';
        submit_button('Save pairing');
        echo '</form></div>';
    }
}
```

- [ ] **Step 2: Wire it into the bootstrap**

In `woo-mgmt-agent.php`, add after the `class-wma-db.php` require:

```php
require_once WMA_DIR . '/includes/class-wma-settings.php';

Wma_Settings::register();
```

- [ ] **Step 3: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-settings.php`
Expected: `No syntax errors detected`

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-settings.php wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php
git commit -m "Add pairing settings screen with WooCommerce status indicator"
```

---

### Task 3: Bearer token authentication

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-auth.php`

**Interfaces:**
- Consumes: `Wma_Settings::get_token()`
- Produces: `Wma_Auth::check(WP_REST_Request $request): bool|WP_Error`

- [ ] **Step 1: Write the auth check**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-auth.php

defined('ABSPATH') || exit;

class Wma_Auth
{
    public static function check(WP_REST_Request $request)
    {
        $header = $request->get_header('authorization') ?? '';
        $expected = Wma_Settings::get_token();

        if ($expected === '' || !preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
            return new WP_Error('wma_unauthorized', 'Missing or invalid Authorization header', ['status' => 401]);
        }
        if (!hash_equals($expected, $m[1])) {
            return new WP_Error('wma_unauthorized', 'Invalid token', ['status' => 401]);
        }
        return true;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-auth.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-auth.php
git commit -m "Add bearer token auth check for agent REST routes"
```

---

### Task 4: Relay to woo-managment (presigned URLs, backup/restore only)

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-relay.php`

**Interfaces:**
- Consumes: `Wma_Settings::get_token()`, `Wma_Settings::get_base_url()`
- Produces: `Wma_Relay::request_presigned_url(int $job_id, string $part_key, string $method): array{ok: bool, url: string, error: string}`, `Wma_Relay::put_file(string $url, string $local_path): array{ok: bool, error: string}`, `Wma_Relay::get_to_file(string $url, string $local_path): array{ok: bool, error: string}`

- [ ] **Step 1: Write the relay class**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-relay.php

defined('ABSPATH') || exit;

/**
 * The only outbound calls this plugin makes: asking woo-managment for a
 * presigned S3 URL (it holds the S3 secret key, this plugin never does),
 * then PUTting/GETting that URL directly against S3. Only used by backup
 * and restore jobs — products/categories/taxonomies/media never touch S3.
 */
class Wma_Relay
{
    public static function request_presigned_url(int $job_id, string $part_key, string $method): array
    {
        $base = Wma_Settings::get_base_url();
        if ($base === '') {
            return ['ok' => false, 'url' => '', 'error' => 'woo-managment base URL is not configured.'];
        }

        $response = wp_remote_post($base . '/api/site-backup-agent/presign.php', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . Wma_Settings::get_token(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'job_id' => $job_id,
                'part_key' => $part_key,
                'method' => $method,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'url' => '', 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data) || empty($data['url'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Presign request failed (HTTP ' . $code . ')'];
        }
        return ['ok' => true, 'url' => $data['url'], 'error' => ''];
    }

    public static function put_file(string $url, string $local_path): array
    {
        $body = file_get_contents($local_path);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Could not read local file for upload.'];
        }
        $response = wp_remote_request($url, ['method' => 'PUT', 'timeout' => 60, 'body' => $body]);
        return self::result($response);
    }

    public static function get_to_file(string $url, string $local_path): array
    {
        $response = wp_remote_get($url, ['timeout' => 60]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'Download failed (HTTP ' . $code . ')'];
        }
        $written = file_put_contents($local_path, wp_remote_retrieve_body($response));
        if ($written === false) {
            return ['ok' => false, 'error' => 'Could not write downloaded part to disk.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    private static function result($response): array
    {
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'S3 upload failed (HTTP ' . $code . ')'];
        }
        return ['ok' => true, 'error' => ''];
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-relay.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-relay.php
git commit -m "Add relay class for presigned-URL S3 transfer"
```

---

### Task 5: Chunked database dumper

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-db-dumper.php`

**Interfaces:**
- Produces: `Wma_Db_Dumper::dump_step(array &$job): bool`
- Cursor shape: `cursor_json = {"table_index": int, "offset": int}`

- [ ] **Step 1: Write the dumper**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-db-dumper.php

defined('ABSPATH') || exit;

/**
 * Dumps the live WP database to a local .sql file, BATCH_ROWS rows per
 * tick, resuming from cursor_json's {table_index, offset}. Pure PHP — no
 * mysqldump, since shared hosts often disable shell_exec.
 */
class Wma_Db_Dumper
{
    private const BATCH_ROWS = 200;

    public static function dump_step(array &$job): bool
    {
        global $wpdb;

        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['table_index' => 0, 'offset' => 0];
        $dumpPath = $job['temp_dir'];
        if ($dumpPath === '' || !is_file($dumpPath)) {
            $dumpPath = self::start_dump_file($job['job_id']);
            $job['temp_dir'] = $dumpPath;
        }

        $tables = $wpdb->get_col('SHOW TABLES');
        if ($cursor['table_index'] >= count($tables)) {
            return true;
        }

        $table = $tables[$cursor['table_index']];
        $fh = fopen($dumpPath, 'a');

        if ($cursor['offset'] === 0) {
            $createRow = $wpdb->get_row("SHOW CREATE TABLE `{$table}`", ARRAY_A);
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow['Create Table'] . ";\n\n");
        }

        $rows = $wpdb->get_results(
            $wpdb->prepare("SELECT * FROM `{$table}` LIMIT %d OFFSET %d", self::BATCH_ROWS, $cursor['offset']),
            ARRAY_A
        );

        foreach ($rows as $row) {
            $columns = array_map(fn($c) => "`{$c}`", array_keys($row));
            $values = array_map(fn($v) => $v === null ? 'NULL' : "'" . esc_sql((string) $v) . "'", array_values($row));
            fwrite($fh, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
        }
        fclose($fh);

        if (count($rows) < self::BATCH_ROWS) {
            $cursor = ['table_index' => $cursor['table_index'] + 1, 'offset' => 0];
        } else {
            $cursor['offset'] += self::BATCH_ROWS;
        }
        $job['cursor_json'] = wp_json_encode($cursor);

        return $cursor['table_index'] >= count($tables);
    }

    private static function start_dump_file(int $job_id): string
    {
        $dir = trailingslashit(get_temp_dir()) . 'wma-' . $job_id;
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $path = $dir . '/database.sql';
        file_put_contents($path, "-- Site backup generated " . gmdate('c') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");
        return $path;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-db-dumper.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-db-dumper.php
git commit -m "Add chunked database dumper"
```

---

### Task 6: Chunked file archiver (full scope)

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-file-archiver.php`

**Interfaces:**
- Produces: `Wma_File_Archiver::zip_step(array &$job): bool`, `Wma_File_Archiver::zip_path(int $job_id): string`

- [ ] **Step 1: Write the archiver**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-file-archiver.php

defined('ABSPATH') || exit;

class Wma_File_Archiver
{
    private const BATCH_FILES = 50;
    private const EXCLUDE_DIRS = ['cache', 'wma-restore-staging'];

    public static function zip_step(array &$job): bool
    {
        $cursor = json_decode((string) $job['cursor_json'], true);
        $fileIndex = $cursor['file_index'] ?? 0;

        $files = self::list_files();
        if ($fileIndex >= count($files)) {
            return true;
        }

        $zipPath = self::zip_path((int) $job['job_id']);
        $zip = new ZipArchive();
        $zip->open($zipPath, ZipArchive::CREATE);

        $batch = array_slice($files, $fileIndex, self::BATCH_FILES);
        foreach ($batch as $absolute => $relative) {
            $zip->addFile($absolute, $relative);
        }
        $zip->close();

        $cursor['file_index'] = $fileIndex + count($batch);
        $job['cursor_json'] = wp_json_encode($cursor);

        return $cursor['file_index'] >= count($files);
    }

    public static function zip_path(int $job_id): string
    {
        return trailingslashit(get_temp_dir()) . 'wma-' . $job_id . '/wp-content.zip';
    }

    /** @return array<string, string> absolute path => zip-relative path */
    private static function list_files(): array
    {
        $root = realpath(WP_CONTENT_DIR);
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $segments = explode('/', $relative);
            if (array_intersect($segments, self::EXCLUDE_DIRS)) {
                continue;
            }
            $files[$file->getPathname()] = $relative;
        }
        ksort($files);
        return $files;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-file-archiver.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-file-archiver.php
git commit -m "Add chunked wp-content file archiver"
```

---

### Task 7: Backup job step machine

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-backup-job.php`

**Interfaces:**
- Consumes: `Wma_Db_Dumper::dump_step()`, `Wma_File_Archiver::zip_step()`/`zip_path()`, `Wma_Relay::request_presigned_url()`/`put_file()`
- Produces: `Wma_Backup_Job::tick(array $job): array`

- [ ] **Step 1: Write the step machine**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-backup-job.php

defined('ABSPATH') || exit;

/**
 * Steps: dump_db -> [zip_files if scope=full] -> upload -> done. A 'full'
 * backup has two source files to ship — database.sql ('db') and
 * wp-content.zip ('files') — uploaded one after the other so restore can
 * tell which downloaded bytes belong to which source.
 */
class Wma_Backup_Job
{
    private const PART_BYTES = 5 * 1024 * 1024; // 5MB

    public static function tick(array $job): array
    {
        try {
            switch ($job['step']) {
                case 'dump_db':
                    if (Wma_Db_Dumper::dump_step($job)) {
                        $job['step'] = $job['scope'] === 'full' ? 'zip_files' : 'upload';
                        $job['cursor_json'] = wp_json_encode(['file_index' => 0]);
                    }
                    break;
                case 'zip_files':
                    if (Wma_File_Archiver::zip_step($job)) {
                        $job['step'] = 'upload';
                        $job['cursor_json'] = wp_json_encode(['source_index' => 0, 'byte_offset' => 0, 'part_index' => 0]);
                    }
                    break;
                case 'upload':
                    self::upload_next_part($job);
                    break;
            }

            if ($job['step'] === 'upload' && self::upload_complete($job)) {
                self::cleanup_local_files($job);
                $job['step'] = 'done';
                $job['status'] = 'completed';
            }
        } catch (Throwable $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
        }

        return $job;
    }

    private static function sources(string $scope): array
    {
        return $scope === 'full' ? ['db', 'files'] : ['db'];
    }

    private static function source_path(array $job, string $source): string
    {
        return $source === 'db'
            ? dirname(Wma_File_Archiver::zip_path((int) $job['job_id'])) . '/database.sql'
            : Wma_File_Archiver::zip_path((int) $job['job_id']);
    }

    private static function upload_next_part(array &$job): void
    {
        $sources = self::sources($job['scope']);
        $cursor = json_decode((string) $job['cursor_json'], true)
            ?: ['source_index' => 0, 'byte_offset' => 0, 'part_index' => 0];

        if ($cursor['source_index'] >= count($sources)) {
            return;
        }

        $source = $sources[$cursor['source_index']];
        $sourcePath = self::source_path($job, $source);
        $size = filesize($sourcePath);

        if ($cursor['byte_offset'] >= $size) {
            $job['cursor_json'] = wp_json_encode(['source_index' => $cursor['source_index'] + 1, 'byte_offset' => 0, 'part_index' => 0]);
            return;
        }

        $partKey = $source . '-part-' . str_pad((string) $cursor['part_index'], 5, '0', STR_PAD_LEFT);
        $partPath = sys_get_temp_dir() . '/wma-upload-' . $job['job_id'] . '-' . $partKey;

        $fh = fopen($sourcePath, 'rb');
        fseek($fh, $cursor['byte_offset']);
        $chunk = fread($fh, self::PART_BYTES);
        fclose($fh);
        file_put_contents($partPath, $chunk);

        $presign = Wma_Relay::request_presigned_url((int) $job['job_id'], $partKey, 'PUT');
        if (!$presign['ok']) {
            @unlink($partPath);
            throw new RuntimeException($presign['error']);
        }

        $upload = Wma_Relay::put_file($presign['url'], $partPath);
        if (!$upload['ok']) {
            @unlink($partPath);
            throw new RuntimeException($upload['error']);
        }

        $parts = json_decode((string) $job['parts_json'], true) ?: [];
        $parts[] = ['part_key' => $partKey, 'source' => $source, 'bytes' => strlen($chunk), 'sha256' => hash('sha256', $chunk)];
        $job['parts_json'] = wp_json_encode($parts);

        @unlink($partPath);

        $job['cursor_json'] = wp_json_encode([
            'source_index' => $cursor['source_index'],
            'byte_offset' => $cursor['byte_offset'] + strlen($chunk),
            'part_index' => $cursor['part_index'] + 1,
        ]);
    }

    private static function upload_complete(array $job): bool
    {
        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['source_index' => 0];
        return $cursor['source_index'] >= count(self::sources($job['scope']));
    }

    private static function cleanup_local_files(array $job): void
    {
        $dir = dirname(Wma_File_Archiver::zip_path((int) $job['job_id']));
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-backup-job.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-backup-job.php
git commit -m "Add backup job step machine with chunked dual-source upload"
```

---

### Task 8: Restore job step machine

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-restore-job.php`

**Interfaces:**
- Consumes: `Wma_Backup_Job::tick()`, `Wma_Db::get_job()`/`save_job()`/`create_job()`, `Wma_Relay::request_presigned_url()`/`get_to_file()`
- Produces: `Wma_Restore_Job::tick(array $job): array`

- [ ] **Step 1: Write the step machine**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-restore-job.php

defined('ABSPATH') || exit;

/**
 * Steps: safety_backup -> download -> restore_db -> [extract_files if
 * scope=full] -> swap -> done. safety_backup runs a full, same-scope
 * backup of the *current* state first (reusing Wma_Backup_Job) so a
 * failed restore always has something to recover to.
 */
class Wma_Restore_Job
{
    private const DB_BATCH_STATEMENTS = 50;

    public static function tick(array $job): array
    {
        try {
            switch ($job['step']) {
                case 'safety_backup':
                    self::tick_safety_backup($job);
                    break;
                case 'download':
                    if (self::download_step($job)) {
                        $job['step'] = 'restore_db';
                        $job['cursor_json'] = wp_json_encode(['statement_index' => 0]);
                    }
                    break;
                case 'restore_db':
                    if (self::restore_db_step($job)) {
                        $job['step'] = $job['scope'] === 'full' ? 'extract_files' : 'swap';
                        $job['cursor_json'] = wp_json_encode(['file_index' => 0]);
                    }
                    break;
                case 'extract_files':
                    if (self::extract_step($job)) {
                        $job['step'] = 'swap';
                    }
                    break;
                case 'swap':
                    self::swap_step($job);
                    $job['step'] = 'done';
                    $job['status'] = 'completed';
                    break;
            }
        } catch (Throwable $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
        }

        return $job;
    }

    private static function tick_safety_backup(array &$job): void
    {
        if (empty($job['safety_job_id'])) {
            // ponytail: offset into a range woo-managment's own autoincrement
            // ids will never reach, so this can't collide with a real job_id.
            $safetyId = 9_000_000_000 + (int) $job['job_id'];
            Wma_Db::create_job($safetyId, 'backup', $job['scope']);
            $job['safety_job_id'] = $safetyId;
        }

        $safetyJob = Wma_Db::get_job((int) $job['safety_job_id']);
        $safetyJob = Wma_Backup_Job::tick($safetyJob);
        Wma_Db::save_job($safetyJob);

        if ($safetyJob['status'] === 'failed') {
            throw new RuntimeException('Pre-restore safety backup failed: ' . $safetyJob['error']);
        }
        if ($safetyJob['status'] === 'completed') {
            $job['step'] = 'download';
            $job['cursor_json'] = wp_json_encode(['part_index' => 0]);
        }
    }

    private static function download_step(array &$job): bool
    {
        $parts = json_decode((string) $job['parts_json'], true) ?: [];
        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['part_index' => 0];

        if ($cursor['part_index'] >= count($parts)) {
            return true;
        }

        $dir = trailingslashit(get_temp_dir()) . 'wma-restore-' . $job['job_id'];
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $job['temp_dir'] = $dir;

        $part = $parts[$cursor['part_index']];
        $presign = Wma_Relay::request_presigned_url((int) $job['job_id'], $part['part_key'], 'GET');
        if (!$presign['ok']) {
            throw new RuntimeException($presign['error']);
        }

        $localPath = $dir . '/' . $part['part_key'];
        $download = Wma_Relay::get_to_file($presign['url'], $localPath);
        if (!$download['ok']) {
            throw new RuntimeException($download['error']);
        }
        if (hash_file('sha256', $localPath) !== $part['sha256']) {
            @unlink($localPath);
            throw new RuntimeException('Downloaded part ' . $part['part_key'] . ' failed checksum verification.');
        }

        $assembled = $part['source'] === 'db' ? $dir . '/restore.sql' : $dir . '/restore.zip';
        file_put_contents($assembled, file_get_contents($localPath), FILE_APPEND);
        unlink($localPath);

        $cursor['part_index']++;
        $job['cursor_json'] = wp_json_encode($cursor);
        return $cursor['part_index'] >= count($parts);
    }

    private static function restore_db_step(array &$job): bool
    {
        global $wpdb;

        $sqlPath = $job['temp_dir'] . '/restore.sql';
        if (!is_file($sqlPath)) {
            throw new RuntimeException('Expected database dump was not downloaded.');
        }

        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['statement_index' => 0];
        $statements = self::split_statements($sqlPath);

        $batch = array_slice($statements, $cursor['statement_index'], self::DB_BATCH_STATEMENTS);
        if (!$batch) {
            return true;
        }

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($batch as $statement) {
                if (trim($statement) === '') {
                    continue;
                }
                $result = $wpdb->query($statement);
                if ($result === false) {
                    throw new RuntimeException('Restore statement failed: ' . $wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $cursor['statement_index'] += count($batch);
        $job['cursor_json'] = wp_json_encode($cursor);
        return $cursor['statement_index'] >= count($statements);
    }

    private static function extract_step(array &$job): bool
    {
        $zipPath = $job['temp_dir'] . '/restore.zip';
        $stagingDir = $job['temp_dir'] . '/extracted';
        if (!is_dir($stagingDir)) {
            wp_mkdir_p($stagingDir);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open downloaded restore archive.');
        }
        $zip->extractTo($stagingDir);
        $zip->close();

        return true;
    }

    private static function swap_step(array &$job): void
    {
        if ($job['scope'] === 'full') {
            // Wma_File_Archiver zips paths relative to WP_CONTENT_DIR
            // directly, so the staging dir mirrors wp-content's layout
            // with no extra wrapping folder.
            $stagingContent = $job['temp_dir'] . '/extracted';
            foreach (['uploads', 'themes', 'plugins'] as $folder) {
                $source = $stagingContent . '/' . $folder;
                $target = WP_CONTENT_DIR . '/' . $folder;
                if (!is_dir($source)) {
                    continue;
                }
                $backupOld = $target . '-pre-restore-' . $job['job_id'];
                rename($target, $backupOld);
                rename($source, $target);
                self::recursive_delete($backupOld);
            }
        }
        self::recursive_delete($job['temp_dir']);
    }

    private static function split_statements(string $path): array
    {
        $sql = file_get_contents($path);
        return array_filter(array_map('trim', explode(";\n", $sql)));
    }

    private static function recursive_delete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-restore-job.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-restore-job.php
git commit -m "Add restore job step machine with safety snapshot and atomic swap"
```

---

### Task 9: Products module (native WooCommerce queries)

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`

**Interfaces:**
- Produces: `Wma_Products::list_products(array $params): array{items: array, page: int, per_page: int, total: int, total_pages: int}`, `Wma_Products::get_product(int $id): ?array`, `Wma_Products::create_product(array $data): array`, `Wma_Products::update_product(int $id, array $data): ?array`, `Wma_Products::delete_product(int $id, bool $force): bool`, `Wma_Products::batch_update(array $items): array`

- [ ] **Step 1: Write the products module**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-products.php

defined('ABSPATH') || exit;

/**
 * All product reads/writes via native WooCommerce PHP APIs
 * (wc_get_products()/WC_Product) — no outbound HTTP, no WooCommerce REST
 * API involved, even though this plugin runs on the same site.
 */
class Wma_Products
{
    private const DEFAULT_PER_PAGE = 20;

    public static function list_products(array $params): array
    {
        $page = max(1, (int) ($params['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($params['per_page'] ?? self::DEFAULT_PER_PAGE)));

        $args = [
            'limit' => $perPage,
            'offset' => ($page - 1) * $perPage,
            'paginate' => true,
        ];
        if (!empty($params['search'])) {
            $args['s'] = (string) $params['search'];
        }
        if (!empty($params['category'])) {
            $args['category'] = [(string) $params['category']];
        }
        if (!empty($params['stock_status'])) {
            $args['stock_status'] = (string) $params['stock_status'];
        }
        if (!empty($params['taxonomy_terms']) && is_array($params['taxonomy_terms'])) {
            $args['tax_query'] = self::build_tax_query($params['taxonomy_terms']);
        }

        $result = wc_get_products($args);
        $products = $result->products;

        if (!empty($params['on_sale'])) {
            $products = array_values(array_filter($products, fn($p) => $p->is_on_sale()));
        }

        return [
            'items' => array_map([self::class, 'product_to_array'], $products),
            'page' => $page,
            'per_page' => $perPage,
            'total' => (int) $result->total,
            'total_pages' => (int) $result->max_num_pages,
        ];
    }

    public static function get_product(int $id): ?array
    {
        $product = wc_get_product($id);
        return $product ? self::product_to_array($product) : null;
    }

    public static function create_product(array $data): array
    {
        $product = new WC_Product_Simple();
        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }

    public static function update_product(int $id, array $data): ?array
    {
        $product = wc_get_product($id);
        if (!$product) {
            return null;
        }
        self::apply_fields($product, $data);
        $product->save();
        return self::product_to_array($product);
    }

    public static function delete_product(int $id, bool $force): bool
    {
        $product = wc_get_product($id);
        if (!$product) {
            return false;
        }
        $product->delete($force);
        return true;
    }

    /** @param array<int, array> $items each with an 'id' plus fields to change */
    public static function batch_update(array $items): array
    {
        $results = [];
        foreach ($items as $item) {
            $id = (int) ($item['id'] ?? 0);
            $updated = $id > 0 ? self::update_product($id, $item) : null;
            if ($updated !== null) {
                $results[] = $updated;
            }
        }
        return $results;
    }

    private static function apply_fields(WC_Product $product, array $data): void
    {
        $map = [
            'name' => 'set_name',
            'sku' => 'set_sku',
            'regular_price' => 'set_regular_price',
            'sale_price' => 'set_sale_price',
            'stock_quantity' => 'set_stock_quantity',
            'stock_status' => 'set_stock_status',
            'short_description' => 'set_short_description',
            'description' => 'set_description',
            'status' => 'set_status',
        ];
        foreach ($map as $key => $setter) {
            if (array_key_exists($key, $data)) {
                $product->{$setter}($data[$key]);
            }
        }
        if (array_key_exists('manage_stock', $data)) {
            $product->set_manage_stock((bool) $data['manage_stock']);
        }
        if (array_key_exists('categories', $data)) {
            $product->set_category_ids(array_map('intval', (array) $data['categories']));
        }
        if (array_key_exists('images', $data) && !empty($data['images'])) {
            $imageIds = array_map('intval', (array) $data['images']);
            $product->set_image_id($imageIds[0]);
            $product->set_gallery_image_ids(array_slice($imageIds, 1));
        }
        if (array_key_exists('taxonomies', $data) && is_array($data['taxonomies'])) {
            self::apply_taxonomies($product, $data['taxonomies']);
        }
    }

    /** @param array<string, array<int>> $taxonomyFields rest_base => term ids */
    private static function apply_taxonomies(WC_Product $product, array $taxonomyFields): void
    {
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            if (array_key_exists($taxonomy->rest_base, $taxonomyFields)) {
                wp_set_object_terms(
                    $product->get_id(),
                    array_map('intval', (array) $taxonomyFields[$taxonomy->rest_base]),
                    $taxonomy->name
                );
            }
        }
    }

    private static function build_tax_query(array $taxonomyTerms): array
    {
        $clauses = [];
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            if (!empty($taxonomyTerms[$taxonomy->rest_base])) {
                $clauses[] = [
                    'taxonomy' => $taxonomy->name,
                    'field' => 'term_id',
                    'terms' => array_map('intval', (array) $taxonomyTerms[$taxonomy->rest_base]),
                ];
            }
        }
        return $clauses;
    }

    /** @return array<\WP_Taxonomy> */
    public static function custom_product_taxonomies(): array
    {
        $taxonomies = get_object_taxonomies('product', 'objects');
        return array_values(array_filter($taxonomies, fn($t) => !in_array($t->name, ['product_cat', 'product_tag'], true)));
    }

    private static function product_to_array(WC_Product $product): array
    {
        $taxonomies = [];
        foreach (self::custom_product_taxonomies() as $taxonomy) {
            $terms = wp_get_post_terms($product->get_id(), $taxonomy->name, ['fields' => 'ids']);
            $taxonomies[$taxonomy->rest_base] = array_map('intval', is_array($terms) ? $terms : []);
        }

        return [
            'id' => $product->get_id(),
            'name' => $product->get_name(),
            'sku' => $product->get_sku(),
            'regular_price' => $product->get_regular_price(),
            'sale_price' => $product->get_sale_price(),
            'price' => $product->get_price(),
            'stock_quantity' => $product->get_stock_quantity(),
            'manage_stock' => $product->get_manage_stock(),
            'stock_status' => $product->get_stock_status(),
            'short_description' => $product->get_short_description(),
            'description' => $product->get_description(),
            'categories' => array_map(
                fn($id) => ['id' => $id, 'name' => get_term($id)?->name ?? ''],
                $product->get_category_ids()
            ),
            'images' => array_map(
                fn($id) => ['id' => $id, 'src' => wp_get_attachment_url($id) ?: ''],
                array_filter([$product->get_image_id(), ...$product->get_gallery_image_ids()])
            ),
            'status' => $product->get_status(),
            'permalink' => $product->get_permalink(),
            'taxonomies' => $taxonomies,
        ];
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-products.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-products.php
git commit -m "Add native product CRUD/batch module"
```

---

### Task 10: Categories and custom taxonomies module

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-taxonomies.php`

**Interfaces:**
- Consumes: `Wma_Products::custom_product_taxonomies()`
- Produces: `Wma_Taxonomies::list_categories(): array`, `Wma_Taxonomies::list_custom_taxonomies(): array`

- [ ] **Step 1: Write the taxonomies module**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-taxonomies.php

defined('ABSPATH') || exit;

/**
 * Categories and custom product taxonomies via native get_terms() — every
 * taxonomy's terms in one in-process pass, not one HTTP round-trip per
 * taxonomy. This is the direct fix for the old N-sequential-request
 * slowness and the silent-failure flakiness: a real error here surfaces as
 * one clean exception/HTTP failure, not a swallowed empty array.
 */
class Wma_Taxonomies
{
    public static function list_categories(): array
    {
        $terms = get_terms(['taxonomy' => 'product_cat', 'hide_empty' => false]);
        if (is_wp_error($terms)) {
            throw new RuntimeException($terms->get_error_message());
        }
        return array_map(fn($t) => [
            'id' => $t->term_id,
            'name' => $t->name,
            'count' => $t->count,
            'parent' => $t->parent,
        ], $terms);
    }

    public static function list_custom_taxonomies(): array
    {
        $result = [];
        foreach (Wma_Products::custom_product_taxonomies() as $taxonomy) {
            $terms = get_terms(['taxonomy' => $taxonomy->name, 'hide_empty' => false]);
            if (is_wp_error($terms)) {
                throw new RuntimeException("Failed to load terms for taxonomy {$taxonomy->name}: " . $terms->get_error_message());
            }
            $result[] = [
                'slug' => $taxonomy->name,
                'rest_base' => $taxonomy->rest_base,
                'name' => $taxonomy->label,
                'hierarchical' => $taxonomy->hierarchical,
                'terms' => array_map(fn($t) => ['id' => $t->term_id, 'name' => $t->name], $terms),
            ];
        }
        return $result;
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-taxonomies.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-taxonomies.php
git commit -m "Add native categories/custom-taxonomies module"
```

---

### Task 11: Media module

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-media.php`

**Interfaces:**
- Produces: `Wma_Media::upload(string $binary, string $filename, string $mimeType): array{id: int, src: string}`

- [ ] **Step 1: Write the media module**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-media.php

defined('ABSPATH') || exit;

/**
 * Native media upload via wp_upload_bits()/wp_insert_attachment() — no
 * outbound call to the WordPress REST media endpoint, even internally;
 * this plugin has direct filesystem access already.
 */
class Wma_Media
{
    public static function upload(string $binary, string $filename, string $mimeType): array
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_upload_bits(sanitize_file_name($filename), null, $binary);
        if (!empty($upload['error'])) {
            throw new RuntimeException('Upload failed: ' . $upload['error']);
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mimeType,
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachmentId)) {
            throw new RuntimeException($attachmentId->get_error_message());
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        wp_update_attachment_metadata($attachmentId, $metadata);

        return ['id' => $attachmentId, 'src' => wp_get_attachment_url($attachmentId) ?: ''];
    }
}
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-media.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-media.php
git commit -m "Add native media upload module"
```

---

### Task 12: REST routes (all of them)

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php`
- Modify: `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`

**Interfaces:**
- Consumes: every class from Tasks 3-11
- Produces REST contract under `wma/v1`:
  - `POST /jobs`, `GET /jobs/{id}?tick=1` (backup/restore)
  - `GET /products`, `GET /products/{id}`, `POST /products`, `PUT /products/{id}`, `DELETE /products/{id}`, `POST /products/batch`
  - `GET /categories`, `GET /taxonomies`
  - `POST /media`

- [ ] **Step 1: Write the REST controller**

```php
<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php

defined('ABSPATH') || exit;

class Wma_Rest
{
    public static function register(): void
    {
        add_action('rest_api_init', function () {
            register_rest_route('wma/v1', '/jobs', [
                'methods' => 'POST',
                'callback' => [self::class, 'create_job'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/jobs/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [self::class, 'get_job_status'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);

            register_rest_route('wma/v1', '/products', [
                ['methods' => 'GET', 'callback' => [self::class, 'list_products'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'POST', 'callback' => [self::class, 'create_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);
            register_rest_route('wma/v1', '/products/batch', [
                'methods' => 'POST',
                'callback' => [self::class, 'batch_products'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/products/(?P<id>\d+)', [
                ['methods' => 'GET', 'callback' => [self::class, 'get_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'PUT', 'callback' => [self::class, 'update_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
                ['methods' => 'DELETE', 'callback' => [self::class, 'delete_product'], 'permission_callback' => [Wma_Auth::class, 'check']],
            ]);

            register_rest_route('wma/v1', '/categories', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_categories'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/taxonomies', [
                'methods' => 'GET',
                'callback' => [self::class, 'list_taxonomies'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
            register_rest_route('wma/v1', '/media', [
                'methods' => 'POST',
                'callback' => [self::class, 'upload_media'],
                'permission_callback' => [Wma_Auth::class, 'check'],
            ]);
        });
    }

    private static function require_woocommerce(): ?WP_REST_Response
    {
        if (!class_exists('WooCommerce')) {
            return new WP_REST_Response(['error' => 'WooCommerce is not active on this site.'], 503);
        }
        return null;
    }

    public static function create_job(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (int) $request->get_param('job_id');
        $kind = (string) $request->get_param('kind');
        $scope = (string) $request->get_param('scope');
        $parts = (array) ($request->get_param('parts') ?? []);

        if ($jobId <= 0 || !in_array($kind, ['backup', 'restore'], true) || !in_array($scope, ['database', 'full'], true)) {
            return new WP_REST_Response(['error' => 'Invalid job parameters'], 400);
        }

        Wma_Db::create_job($jobId, $kind, $scope, $parts);
        return new WP_REST_Response(['accepted' => true], 202);
    }

    public static function get_job_status(WP_REST_Request $request): WP_REST_Response
    {
        $jobId = (int) $request->get_param('id');
        $job = Wma_Db::get_job($jobId);
        if ($job === null) {
            return new WP_REST_Response(['error' => 'Job not found'], 404);
        }

        if ($request->get_param('tick') && $job['status'] === 'running') {
            $job = $job['kind'] === 'backup' ? Wma_Backup_Job::tick($job) : Wma_Restore_Job::tick($job);
            Wma_Db::save_job($job);
        }

        return new WP_REST_Response([
            'status' => $job['status'],
            'step' => $job['step'],
            'parts' => json_decode((string) $job['parts_json'], true) ?: [],
            'error' => $job['error'],
        ], 200);
    }

    public static function list_products(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(Wma_Products::list_products($request->get_params()), 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function get_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        $product = Wma_Products::get_product((int) $request->get_param('id'));
        return $product === null
            ? new WP_REST_Response(['error' => 'Product not found'], 404)
            : new WP_REST_Response(['item' => $product], 200);
    }

    public static function create_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['item' => Wma_Products::create_product($request->get_json_params())], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function update_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            $product = Wma_Products::update_product((int) $request->get_param('id'), $request->get_json_params());
            return $product === null
                ? new WP_REST_Response(['error' => 'Product not found'], 404)
                : new WP_REST_Response(['item' => $product], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function delete_product(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        $force = (bool) $request->get_param('force');
        $ok = Wma_Products::delete_product((int) $request->get_param('id'), $force);
        return $ok ? new WP_REST_Response(['ok' => true], 200) : new WP_REST_Response(['error' => 'Product not found'], 404);
    }

    public static function batch_products(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        $body = $request->get_json_params();
        $items = (array) ($body['update'] ?? []);
        return new WP_REST_Response(['update' => Wma_Products::batch_update($items)], 200);
    }

    public static function list_categories(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['items' => Wma_Taxonomies::list_categories()], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function list_taxonomies(WP_REST_Request $request): WP_REST_Response
    {
        if ($err = self::require_woocommerce()) {
            return $err;
        }
        try {
            return new WP_REST_Response(['items' => Wma_Taxonomies::list_custom_taxonomies()], 200);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }

    public static function upload_media(WP_REST_Request $request): WP_REST_Response
    {
        $filename = $request->get_header('x-filename') ?: 'upload.jpg';
        $mimeType = $request->get_header('content-type') ?: 'application/octet-stream';
        try {
            return new WP_REST_Response(['item' => Wma_Media::upload($request->get_body(), $filename, $mimeType)], 201);
        } catch (Throwable $e) {
            return new WP_REST_Response(['error' => $e->getMessage()], 500);
        }
    }
}
```

- [ ] **Step 2: Wire it into the bootstrap**

In `woo-mgmt-agent.php`, add (after the existing requires):

```php
require_once WMA_DIR . '/includes/class-wma-auth.php';
require_once WMA_DIR . '/includes/class-wma-relay.php';
require_once WMA_DIR . '/includes/class-wma-db-dumper.php';
require_once WMA_DIR . '/includes/class-wma-file-archiver.php';
require_once WMA_DIR . '/includes/class-wma-backup-job.php';
require_once WMA_DIR . '/includes/class-wma-restore-job.php';
require_once WMA_DIR . '/includes/class-wma-products.php';
require_once WMA_DIR . '/includes/class-wma-taxonomies.php';
require_once WMA_DIR . '/includes/class-wma-media.php';
require_once WMA_DIR . '/includes/class-wma-rest.php';

Wma_Rest::register();
```

- [ ] **Step 3: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php && php -l wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/includes/class-wma-rest.php wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php
git commit -m "Wire up all agent REST routes"
```

---

### Task 13: WP-Cron fallback tick

**Files:**
- Modify: `wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`

- [ ] **Step 1: Add the cron hook**

```php
register_activation_hook(__FILE__, function () {
    Wma_Db::create_table();
    if (!wp_next_scheduled('wma_tick_running_jobs')) {
        wp_schedule_event(time(), 'hourly', 'wma_tick_running_jobs');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wma_tick_running_jobs');
});

add_action('wma_tick_running_jobs', function () {
    global $wpdb;
    $table = Wma_Db::table();
    $ids = $wpdb->get_col("SELECT job_id FROM {$table} WHERE status = 'running'");
    foreach ($ids as $jobId) {
        $job = Wma_Db::get_job((int) $jobId);
        if ($job === null) {
            continue;
        }
        $job = $job['kind'] === 'backup' ? Wma_Backup_Job::tick($job) : Wma_Restore_Job::tick($job);
        Wma_Db::save_job($job);
    }
});
```

This replaces the earlier `register_activation_hook` call added in Task 1 — merge the table-creation line into this one rather than registering the hook twice.

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php
git commit -m "Add WP-Cron fallback tick for running jobs"
```

---

### Task 14: Uninstall cleanup

**Files:**
- Create: `wp-plugin/woo-mgmt-agent/uninstall.php`

- [ ] **Step 1: Write the cleanup script**

```php
<?php
// wp-plugin/woo-mgmt-agent/uninstall.php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wma_jobs');

delete_option('wma_token');
delete_option('wma_base_url');
wp_clear_scheduled_hook('wma_tick_running_jobs');
```

- [ ] **Step 2: Verify syntax**

Run: `php -l wp-plugin/woo-mgmt-agent/uninstall.php`
Expected: `No syntax errors detected`

- [ ] **Step 3: Commit**

```bash
git add wp-plugin/woo-mgmt-agent/uninstall.php
git commit -m "Add agent plugin uninstall cleanup"
```

---

### Task 15: Manual end-to-end verification

**Files:** none (verification only)

- [ ] **Step 1: Install on a test WordPress + WooCommerce site**

Zip `wp-plugin/woo-mgmt-agent/`, install and activate on a disposable/staging site with WooCommerce active and at least one custom product taxonomy registered. Confirm Settings -> Woo Management Agent shows "WooCommerce: detected".

- [ ] **Step 2: Pair and exercise products/categories/taxonomies with curl**

```bash
curl "https://staging-site.example/wp-json/wma/v1/categories" -H "Authorization: Bearer <token>"
curl "https://staging-site.example/wp-json/wma/v1/taxonomies" -H "Authorization: Bearer <token>"
curl "https://staging-site.example/wp-json/wma/v1/products?per_page=5" -H "Authorization: Bearer <token>"
```
Expected: each returns in well under a second (versus the old 1-2 minutes), `taxonomies` lists every custom taxonomy with terms attached, no empty arrays standing in for errors.

- [ ] **Step 3: Exercise product create/update/delete and media upload**

```bash
curl -X POST "https://staging-site.example/wp-json/wma/v1/products" \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"name": "Test Product", "regular_price": "10.00"}'
```
Expected: `201` with the created product, including an `id`. Repeat with `PUT /products/{id}` to update, `DELETE /products/{id}` to remove, and a binary `POST /media` upload with `X-Filename` header — confirm the attachment appears in the site's Media Library.

- [ ] **Step 4: Re-run the backup/restore job lifecycle (Phase-1-equivalent check)**

```bash
curl -X POST "https://staging-site.example/wp-json/wma/v1/jobs" \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"job_id": 1, "kind": "backup", "scope": "database"}'
curl "https://staging-site.example/wp-json/wma/v1/jobs/1?tick=1" -H "Authorization: Bearer <token>"
```
Expected: same behavior as the original backup-only plugin design — job progresses through `dump_db` to `upload`, failing cleanly (not fatally) once it reaches the relay step if woo-managment's presign endpoint isn't deployed yet.

- [ ] **Step 5: Confirm no fatals in the site's PHP error log across all of the above**

Check `wp-content/debug.log` (with `WP_DEBUG_LOG` enabled) for any uncaught exception or notice from `Wma_*` classes during Steps 2-4.
