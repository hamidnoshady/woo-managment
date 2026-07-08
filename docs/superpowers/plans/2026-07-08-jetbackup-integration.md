# JetBackup / DirectAdmin Backup Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the hand-rolled S3 backup system with JetBackup 5 (via DirectAdmin) for woo-managment's own account and for any managed WooCommerce site that shares the same DirectAdmin server, while keeping the existing plugin/S3 path for sites hosted elsewhere.

**Architecture:** Two new HTTP clients (`DirectAdminClient` for account enumeration, `JetBackupClient` for backup/restore) plumb into a site-sync job (`DirectAdminSync`) that links `sites.da_username`, a routing layer in `SiteBackupManager` that sends DA-linked sites through a new `JetBackupSiteManager` instead of the plugin job system, and a fully separate `AppJetBackupManager` for woo-managment's own backup button. Non-DA-linked sites and the legacy app-backup path are untouched.

**Tech Stack:** Plain PHP 8, MySQL (via `Database::get()` PDO singleton), cURL for outbound HTTP, vanilla JS (`App` global helpers), Tailwind via CDN.

## Global Constraints

- No Composer, no build step — every new file is plain PHP included via `require_once`, following the exact path style already used (`__DIR__ . '/../../includes/X.php'`).
- No test framework exists in this repo. "Test" steps in this plan mean: `php -l <file>` for syntax, `node --check <file>` for JS, and a concrete manual verification (curl command or browser steps) — per [CLAUDE.md](../../../CLAUDE.md)'s Verification section.
- `key` is a reserved MySQL word — backtick it if ever used as a column name (not needed in this plan's new tables, but applies to any `settings`/`kv_cache` touches).
- Every new user-facing string needs an entry in **both** `includes/i18n.php` (server) and `public/assets/js/i18n.js` (client), in both `en` and `fa`, per the project's hand-maintained-mirror convention.
- New `SETTINGS_FIELDS` entries need a `settings_group_key()` mapping in `public/api/settings.php` or their group label leaks through untranslated.
- API endpoints follow the exact boilerplate order documented in CLAUDE.md: `helpers.php` → `install_json_fatal_handler()` → `auth.php` → other includes → `require_login_api()`/`require_superadmin_api()` → (`require_site_api()` if site-scoped) → `verify_csrf_api()` on mutating methods.
- **Verify-first item:** JetBackup 5's exact API function names/payload shapes for creating/listing/restoring an account backup are not confirmed (docs.jetbackup.com blocks automated fetching). Task 4 starts with a concrete step to confirm these against the live server before the rest of that task's code is trusted as final — the constants are isolated at the top of `JetBackupClient.php` specifically so a wrong guess is a one-place fix.

---

### Task 1: Database schema — `da_username`, `site_jetbackup_backups`, `backups.external_ref`

**Files:**
- Modify: `includes/Database.php`

**Interfaces:**
- Produces: `sites.da_username` (string column), `site_jetbackup_backups` table (id, site_id, jetbackup_backup_id, status, size_bytes, error, created_at, completed_at), `backups.external_ref` (string column).

- [ ] **Step 1: Add the new table and columns**

In `includes/Database.php`, add a new `CREATE TABLE IF NOT EXISTS` block immediately after the existing `site_restores` table block (after line 181, before the `batch_jobs` block):

```php
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_jetbackup_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                jetbackup_backup_id VARCHAR(100) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                size_bytes BIGINT NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at INT NOT NULL,
                completed_at INT NULL,
                KEY idx_site_jetbackup_backups_site (site_id, id),
                CONSTRAINT fk_site_jetbackup_backups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
```

Then, in the `addColumnIfMissing` block (around line 217-223), add two new lines:

```php
        self::addColumnIfMissing($pdo, 'sites', 'da_username', "VARCHAR(100) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'backups', 'external_ref', "VARCHAR(255) NOT NULL DEFAULT ''");
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/Database.php`
Expected: `No syntax errors detected in includes/Database.php`

- [ ] **Step 3: Verify the migration runs**

Run: `php -r "require 'includes/Database.php'; Database::get(); echo \"ok\n\";"`
Expected: prints `ok` with no errors (this triggers `Database::get()`'s inline migration path against your configured MySQL instance — same mechanism every other column addition in this file already uses).

- [ ] **Step 4: Commit**

```bash
git add includes/Database.php
git commit -m "Add DirectAdmin/JetBackup schema: sites.da_username, site_jetbackup_backups, backups.external_ref"
```

---

### Task 2: Settings — DirectAdmin / JetBackup fields

**Files:**
- Modify: `includes/Settings.php`
- Modify: `public/api/settings.php`

**Interfaces:**
- Consumes: `SETTINGS_DEFAULTS`, `SETTINGS_FIELDS`, `settings_group_key()` (existing patterns in both files).
- Produces: `get_setting('da_api_url')`, `get_setting('da_admin_username')`, `get_setting('da_login_key')`, `get_setting('da_self_username')` — consumed by Tasks 3, 4, 5, 9.

- [ ] **Step 1: Add settings defaults**

In `includes/Settings.php`, add to `SETTINGS_DEFAULTS` (after the `backup_retention_days` line):

```php
    'da_api_url'           => '',
    'da_admin_username'    => '',
    'da_login_key'         => '',
    'da_self_username'     => '',
```

- [ ] **Step 2: Add field metadata**

In the same file, add to `SETTINGS_FIELDS` (after the `backup_retention_days` entry):

```php
    'da_api_url' => [
        'label' => 'DirectAdmin API URL',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'Base URL of this server\'s DirectAdmin panel, e.g. https://server.example.com:2222.',
    ],
    'da_admin_username' => [
        'label' => 'DirectAdmin admin/reseller username',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'A DirectAdmin account (admin or reseller level) used to list hosting accounts and trigger JetBackup jobs via its Login Key.',
    ],
    'da_login_key' => [
        'label' => 'DirectAdmin Login Key',
        'type' => 'password',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'A Login Key generated under DirectAdmin -> Login Keys for the account above.',
    ],
    'da_self_username' => [
        'label' => 'This app\'s own DirectAdmin username',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'The DirectAdmin account woo-managment itself runs under, used only for its own "Run backup now" button on Admin -> Backups.',
    ],
```

- [ ] **Step 3: Add the group translation mapping**

In `public/api/settings.php`, add to the `$map` array inside `settings_group_key()` (after `'Backups (S3)' => 'group_backup',`):

```php
        'DirectAdmin / JetBackup' => 'group_jetbackup',
```

- [ ] **Step 4: Syntax check both files**

Run: `php -l includes/Settings.php && php -l public/api/settings.php`
Expected: both report `No syntax errors detected`

- [ ] **Step 5: Manual verification**

Log in as superadmin, open Admin -> Settings, confirm a new "DirectAdmin / JetBackup" section (untranslated group label is fine until Task 10 adds i18n keys — the fallback is the raw English string, not a crash) with the four new fields renders and saves.

- [ ] **Step 6: Commit**

```bash
git add includes/Settings.php public/api/settings.php
git commit -m "Add DirectAdmin/JetBackup settings fields"
```

---

### Task 3: `DirectAdminClient` — account enumeration

**Files:**
- Create: `includes/DirectAdminClient.php`

**Interfaces:**
- Consumes: `get_setting('da_api_url')`, `get_setting('da_admin_username')`, `get_setting('da_login_key')`.
- Produces: `DirectAdminClient::isConfigured(): bool`, `DirectAdminClient::listAccounts(): array` returning `[{username: string, domain: string}, ...]`, throwing `RuntimeException` on a request failure.

DirectAdmin's own API (distinct from JetBackup's) is stable and well-documented: `CMD_API_SHOW_ALL_USERS` (admin-level) returns every account's primary domain in a `username=domain` line-delimited body when called with `json=yes` it returns JSON instead — using `json=yes` avoids hand-rolling the legacy query-string parser.

- [ ] **Step 1: Write the client**

```php
<?php
// includes/DirectAdminClient.php

require_once __DIR__ . '/Settings.php';

/**
 * Talks to the DirectAdmin API (not JetBackup's) purely to enumerate
 * hosting accounts and their primary domains, so DirectAdminSync can match
 * them against managed WooCommerce sites. Authenticated with a DirectAdmin
 * Login Key (HTTP Basic auth), configured in Admin -> Settings.
 */
class DirectAdminClient
{
    private string $apiUrl;
    private string $username;
    private string $loginKey;

    public function __construct(array $config)
    {
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? ''), '/');
        $this->username = (string) ($config['username'] ?? '');
        $this->loginKey = (string) ($config['login_key'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->username !== '' && $this->loginKey !== '';
    }

    /**
     * Returns every account this DA user can see, as [{username, domain}, ...].
     * DirectAdmin's CMD_API_SHOW_ALL_USERS is admin-level only; reseller-level
     * accounts fall back to CMD_API_SHOW_USERS (their own resold accounts).
     */
    public function listAccounts(): array
    {
        $body = $this->request('CMD_API_SHOW_ALL_USERS');
        if ($body === null) {
            $body = $this->request('CMD_API_SHOW_USERS');
        }
        if ($body === null) {
            throw new RuntimeException('DirectAdmin API request failed for both CMD_API_SHOW_ALL_USERS and CMD_API_SHOW_USERS.');
        }

        $accounts = [];
        foreach ($body as $username => $domain) {
            if (is_string($username) && is_string($domain) && $domain !== '') {
                $accounts[] = ['username' => $username, 'domain' => $domain];
            }
        }
        return $accounts;
    }

    /**
     * @return array<string,string>|null Decoded JSON body, or null on any
     * non-2xx / non-JSON response (caller tries the next candidate command).
     */
    private function request(string $command): ?array
    {
        $url = "{$this->apiUrl}/{$command}?json=yes";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->loginKey}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * Builds a DirectAdminClient from the app's configured settings.
 */
function directadmin_client(): DirectAdminClient
{
    return new DirectAdminClient([
        'api_url' => get_setting('da_api_url'),
        'username' => get_setting('da_admin_username'),
        'login_key' => get_setting('da_login_key'),
    ]);
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/DirectAdminClient.php`
Expected: `No syntax errors detected in includes/DirectAdminClient.php`

- [ ] **Step 3: Manual verification against the real server**

With `da_api_url`/`da_admin_username`/`da_login_key` filled in via Admin -> Settings:

```bash
php -r "
require 'includes/Settings.php';
require 'includes/DirectAdminClient.php';
require 'includes/Database.php';
Database::get();
var_dump(directadmin_client()->listAccounts());
"
```
Expected: an array of `['username' => ..., 'domain' => ...]` pairs matching the accounts you can see in the DirectAdmin panel. If `CMD_API_SHOW_ALL_USERS` 403s (non-admin-level key), confirm it falls through to `CMD_API_SHOW_USERS` and still returns your resold accounts.

- [ ] **Step 4: Commit**

```bash
git add includes/DirectAdminClient.php
git commit -m "Add DirectAdminClient for DA account enumeration"
```

---

### Task 4: `JetBackupClient` — backup/restore via JetApi

**Files:**
- Create: `includes/JetBackupClient.php`

**Interfaces:**
- Consumes: `get_setting('da_api_url')`, `get_setting('da_admin_username')`, `get_setting('da_login_key')`.
- Produces: `JetBackupClient::isConfigured(): bool`, `JetBackupClient::createAccountBackup(string $daUsername): string` (returns a JetBackup backup/queue id), `JetBackupClient::listAccountBackups(string $daUsername): array` (returns `[{id, status, size_bytes, created_at}, ...]`), `JetBackupClient::getBackupStatus(string $backupId): array` (returns `{status, size_bytes, error}`), `JetBackupClient::restoreAccountBackup(string $daUsername, string $backupId): string` (returns a JetBackup restore/queue id) — all throwing `RuntimeException` on failure. Consumed by Tasks 5, 7, 9.

- [ ] **Step 1: Confirm the real function names before trusting this task's code**

The function names below (`Accounts.getAccounts`, `Queues.addQueueSnapshot`, `Queues.getQueue`, `Queues.addQueueRestore`) are the best match found in JetBackup 5's indexed doc titles (`Accounts/getAccount`, `Accounts/manageAccount`, `Queues/addQueueSnapshot`) but were **not** read from the live reference tables — `docs.jetbackup.com` blocked every automated fetch attempt during design. Before relying on this task's output:

1. Open DirectAdmin -> Extra Features -> JetBackup in a browser, with devtools Network tab open.
2. Manually trigger "Create Snapshot" for one account, watch the outgoing request to `CMD_PLUGINS_ADMIN/jetbackup5/index.raw`, and note the actual `func`/`data` values sent.
3. Do the same for viewing an account's backup list, and for restoring one.
4. Update the `FUNC_*` constants at the top of `JetBackupClient.php` (written in Step 2 below) to match what you observed. Everything else in this class only depends on those constants — no other code changes needed if the names differ.

This step has no pass/fail check beyond "the constants now match what the browser actually sent" — confirm that visually against the Network tab before moving on.

- [ ] **Step 2: Write the client**

```php
<?php
// includes/JetBackupClient.php

require_once __DIR__ . '/Settings.php';

/**
 * Talks to JetBackup 5's function-dispatch API (JetApi), reached through
 * DirectAdmin at CMD_PLUGINS_ADMIN/jetbackup5/index.raw. Authenticated the
 * same way as DirectAdminClient (Login Key, HTTP Basic auth) since JetBackup
 * rides on the DA panel's own auth rather than a separate key.
 *
 * FUNC_* names below are a best guess pending confirmation against the live
 * server (see Task 4 Step 1 in the implementation plan) — this file is the
 * single place to correct them if they're wrong.
 */
class JetBackupClient
{
    private const FUNC_LIST_ACCOUNT_BACKUPS = 'Accounts.getAccounts';
    private const FUNC_CREATE_SNAPSHOT = 'Queues.addQueueSnapshot';
    private const FUNC_GET_QUEUE = 'Queues.getQueue';
    private const FUNC_RESTORE = 'Queues.addQueueRestore';

    private string $apiUrl;
    private string $username;
    private string $loginKey;

    public function __construct(array $config)
    {
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? ''), '/');
        $this->username = (string) ($config['username'] ?? '');
        $this->loginKey = (string) ($config['login_key'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->username !== '' && $this->loginKey !== '';
    }

    /**
     * Triggers an on-demand full-account (files+DB) backup and returns
     * JetBackup's queue/job id for polling via getBackupStatus().
     */
    public function createAccountBackup(string $daUsername): string
    {
        $data = $this->call(self::FUNC_CREATE_SNAPSHOT, ['account' => $daUsername]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the new snapshot.');
        }
        return $id;
    }

    /**
     * @return array{status:string, size_bytes:int, error:string}
     */
    public function getBackupStatus(string $backupId): array
    {
        $data = $this->call(self::FUNC_GET_QUEUE, ['id' => $backupId]);
        return [
            'status' => self::normalizeStatus((string) ($data['status'] ?? '')),
            'size_bytes' => (int) ($data['size'] ?? $data['size_bytes'] ?? 0),
            'error' => (string) ($data['error'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{id:string, status:string, size_bytes:int, created_at:int}>
     */
    public function listAccountBackups(string $daUsername): array
    {
        $data = $this->call(self::FUNC_LIST_ACCOUNT_BACKUPS, ['account' => $daUsername]);
        $items = (array) ($data['items'] ?? $data['backups'] ?? []);

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id' => (string) ($item['id'] ?? ''),
                'status' => self::normalizeStatus((string) ($item['status'] ?? '')),
                'size_bytes' => (int) ($item['size'] ?? $item['size_bytes'] ?? 0),
                'created_at' => (int) ($item['time'] ?? $item['created_at'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Triggers a full-account restore from the given JetBackup backup id and
     * returns the restore queue id for polling via getBackupStatus().
     */
    public function restoreAccountBackup(string $daUsername, string $backupId): string
    {
        $data = $this->call(self::FUNC_RESTORE, ['account' => $daUsername, 'backup_id' => $backupId]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the restore.');
        }
        return $id;
    }

    private static function normalizeStatus(string $raw): string
    {
        $raw = strtolower($raw);
        if (in_array($raw, ['done', 'completed', 'success', 'finished'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error', 'cancelled'], true)) {
            return 'failed';
        }
        return 'running';
    }

    /**
     * @return array<string,mixed>
     */
    private function call(string $func, array $data): array
    {
        $url = "{$this->apiUrl}/CMD_PLUGINS_ADMIN/jetbackup5/index.raw?api=1";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->loginKey}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'func' => $func,
                'data' => json_encode($data),
                'output' => 'json',
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("JetBackup API request for {$func} failed to connect.");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("JetBackup API request for {$func} failed (HTTP {$status}).");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("JetBackup API request for {$func} returned a non-JSON response.");
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException("JetBackup API error for {$func}: " . $decoded['error']);
        }
        return (array) ($decoded['data'] ?? $decoded);
    }
}

/**
 * Builds a JetBackupClient from the app's configured settings (same
 * DirectAdmin credentials as DirectAdminClient).
 */
function jetbackup_client(): JetBackupClient
{
    return new JetBackupClient([
        'api_url' => get_setting('da_api_url'),
        'username' => get_setting('da_admin_username'),
        'login_key' => get_setting('da_login_key'),
    ]);
}
```

- [ ] **Step 3: Syntax check**

Run: `php -l includes/JetBackupClient.php`
Expected: `No syntax errors detected in includes/JetBackupClient.php`

- [ ] **Step 4: Manual verification against the real server**

```bash
php -r "
require 'includes/Settings.php';
require 'includes/JetBackupClient.php';
require 'includes/Database.php';
Database::get();
\$c = jetbackup_client();
\$id = \$c->createAccountBackup('YOUR_DA_USERNAME');
echo \"queue id: \$id\n\";
var_dump(\$c->getBackupStatus(\$id));
"
```
Expected: a queue id is returned and `getBackupStatus()` reflects real progress (confirm against the JetBackup panel's own queue view). If this throws, the function names from Step 1 are wrong — fix the `FUNC_*` constants and retry before moving to Task 5.

- [ ] **Step 5: Commit**

```bash
git add includes/JetBackupClient.php
git commit -m "Add JetBackupClient for account backup/restore via JetApi"
```

---

### Task 5: `DirectAdminSync` — site auto-discovery

**Files:**
- Create: `includes/DirectAdminSync.php`
- Create: `public/cron/da-sync.php`
- Create: `public/api/da-sync.php`

**Interfaces:**
- Consumes: `directadmin_client()` (Task 3), `list_all_sites()`/`create_site()`/`update_site()`/`get_site()` (existing `includes/Sites.php`).
- Produces: `DirectAdminSync::syncSites(): array` returning `{linked: int, created: int, skipped_conflicts: array}`, consumed by the cron and API endpoints and, in Task 6, the Admin -> Sites "Sync now" button.

- [ ] **Step 1: Write the sync logic**

```php
<?php
// includes/DirectAdminSync.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DirectAdminClient.php';
require_once __DIR__ . '/Sites.php';

/**
 * Matches DirectAdmin accounts on this server against managed WooCommerce
 * sites by domain, so JetBackup-backed sites are discovered automatically
 * instead of requiring manual da_username entry. Read-only against
 * DirectAdmin itself — never creates/modifies/deletes DA accounts.
 */
class DirectAdminSync
{
    /**
     * @return array{linked: int, created: int, skipped_conflicts: array<int, array{domain: string, existing_da_username: string, found_da_username: string}>}
     */
    public static function syncSites(): array
    {
        $client = directadmin_client();
        if (!$client->isConfigured()) {
            throw new RuntimeException('DirectAdmin API is not configured (see Admin -> Settings).');
        }

        $accounts = $client->listAccounts();
        $sites = list_all_sites();

        $linked = 0;
        $created = 0;
        $conflicts = [];

        foreach ($accounts as $account) {
            $domain = strtolower($account['domain']);
            $matchedSite = self::findSiteByDomain($sites, $domain);

            if ($matchedSite !== null) {
                if ($matchedSite['da_username'] === '') {
                    update_site((int) $matchedSite['id'], ['da_username' => $account['username']]);
                    $linked++;
                } elseif ($matchedSite['da_username'] !== $account['username']) {
                    $conflicts[] = [
                        'domain' => $domain,
                        'existing_da_username' => $matchedSite['da_username'],
                        'found_da_username' => $account['username'],
                    ];
                }
                continue;
            }

            create_site([
                'name' => $domain,
                'store_url' => "https://{$domain}",
                'verify_ssl' => true,
                'da_username' => $account['username'],
                'backup_enabled' => true,
            ]);
            $created++;
        }

        return ['linked' => $linked, 'created' => $created, 'skipped_conflicts' => $conflicts];
    }

    private static function findSiteByDomain(array $sites, string $domain): ?array
    {
        foreach ($sites as $site) {
            $host = strtolower((string) parse_url($site['store_url'], PHP_URL_HOST));
            if ($host === $domain) {
                return get_site((int) $site['id']);
            }
        }
        return null;
    }
}
```

- [ ] **Step 2: Extend `update_site()`/`create_site()` to accept `da_username`**

`includes/Sites.php`'s `update_site()` field map (around line 85) needs `da_username` added so `DirectAdminSync` can write it:

```php
    $map = ['name' => 'name', 'store_url' => 'store_url', 'backup_schedule' => 'backup_schedule', 'da_username' => 'da_username'];
```

And `create_site()` (around line 66-78) needs to accept and store it:

```php
function create_site(array $data): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO sites (name, store_url, verify_ssl, da_username, backup_enabled, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $data['name'],
        rtrim($data['store_url'], '/'),
        !empty($data['verify_ssl']) ? 1 : 0,
        $data['da_username'] ?? '',
        !empty($data['backup_enabled']) ? 1 : 0,
        time(),
    ]);

    return get_site((int) $pdo->lastInsertId());
}
```

- [ ] **Step 3: Add the cron endpoint**

```php
<?php
// public/cron/da-sync.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/DirectAdminSync.php';

// Same cron secret as public/cron/backup.php — all cron triggers here are
// superadmin-only, no need for a distinct token per feature.
$token = (string) ($_GET['token'] ?? '');
$expected = (string) get_setting('backup_cron_token');

if ($expected === '' || !hash_equals($expected, $token)) {
    json_response(['error' => 'Invalid token'], 403);
}

try {
    $result = DirectAdminSync::syncSites();
    json_response(['ok' => true] + $result);
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 502);
}
```

- [ ] **Step 4: Add the manual-trigger API endpoint**

```php
<?php
// public/api/da-sync.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/DirectAdminSync.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_superadmin_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

verify_csrf_api();

try {
    $result = DirectAdminSync::syncSites();
    log_activity($user, null, 'system', 'da_sync', 'log_da_sync_run', [$result['linked'], $result['created']]);
    json_response(['ok' => true] + $result);
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 502);
}
```

- [ ] **Step 5: Syntax check all four files**

Run: `php -l includes/DirectAdminSync.php && php -l includes/Sites.php && php -l public/cron/da-sync.php && php -l public/api/da-sync.php`
Expected: all four report `No syntax errors detected`

- [ ] **Step 6: Manual verification**

With DA/JetBackup settings configured, POST to `/api/da-sync.php` as a logged-in superadmin (e.g. via the browser devtools console using the app's existing session/CSRF token, or temporarily wire the button from Task 6 first). Confirm: sites matching a DA account's domain get `da_username` set, and a brand-new `sites` row appears for any DA account with no matching site.

- [ ] **Step 7: Commit**

```bash
git add includes/DirectAdminSync.php includes/Sites.php public/cron/da-sync.php public/api/da-sync.php
git commit -m "Add DirectAdminSync: auto-discover and link DA accounts to sites"
```

---

### Task 6: Admin -> Sites UI — DA-linked status and Sync button

**Files:**
- Modify: `public/api/sites.php`
- Modify: `public/admin/sites.php`
- Modify: `public/assets/js/admin-sites.js`
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Consumes: `/api/da-sync.php` (Task 5), `da_username` field on site rows.

- [ ] **Step 1: Expose `da_username` in the site detail API**

In `public/api/sites.php`'s `map_site_detail()` (around line 115-129), add one line:

```php
        'da_username' => $site['da_username'],
```

- [ ] **Step 2: Add the Sync button to the page**

In `public/admin/sites.php`, add a sync button next to the existing `add-btn` in the header (around line 28-31):

```php
      <div class="flex items-center gap-2">
        <button id="sync-da-btn" class="text-sm font-medium text-gray-600 active:text-gray-900"><?php echo htmlspecialchars(t('sync_directadmin')); ?></button>
        <button id="add-btn" class="text-sm font-medium text-gray-600 active:text-gray-900"><?php echo htmlspecialchars(t('add_btn')); ?></button>
      </div>
```

- [ ] **Step 3: Wire the button and show DA-linked status per site**

In `public/assets/js/admin-sites.js`, find the `init()` function's event-listener block and add:

```javascript
  document.getElementById('sync-da-btn').addEventListener('click', syncDirectAdmin);
```

Add a new function (near the other action handlers):

```javascript
async function syncDirectAdmin() {
  const btn = document.getElementById('sync-da-btn');
  btn.disabled = true;
  const originalText = btn.textContent;
  btn.textContent = t('syncing');

  try {
    const result = await App.api('/api/da-sync.php', { method: 'POST', body: JSON.stringify({}) });
    App.toast(t('sync_result', { linked: result.linked, created: result.created }), 'success');
    await loadSites();
  } catch (err) {
    App.toast(err.message || t('sync_failed'), 'error');
  } finally {
    btn.disabled = false;
    btn.textContent = originalText;
  }
}
```

Find the function that renders each site row (search for where `site.store_url` is rendered into the list) and add a DA-linked badge next to it, e.g.:

```javascript
    const daLabel = site.da_username
      ? `<span class="text-xs text-green-600">${escapeHtml(t('da_linked', { username: site.da_username }))}</span>`
      : '';
```

...and include `${daLabel}` in that row's template string, near where `store_url` is already shown.

- [ ] **Step 4: Add i18n keys**

In `includes/i18n.php`, add to both the `en` and `fa` arrays (near the existing `sites`/`manage_sites_title` keys):

```php
        'sync_directadmin' => 'Sync from DirectAdmin',
        'syncing' => 'Syncing…',
        'sync_failed' => 'Sync failed.',
        'da_linked' => 'DA: {username}',
        'log_da_sync_run' => 'Synced DirectAdmin accounts ({0} linked, {1} created).',
```
(fa)
```php
        'sync_directadmin' => 'همگام‌سازی از DirectAdmin',
        'syncing' => 'در حال همگام‌سازی…',
        'sync_failed' => 'همگام‌سازی ناموفق بود.',
        'da_linked' => 'DA: {username}',
        'log_da_sync_run' => 'همگام‌سازی حساب‌های DirectAdmin انجام شد ({0} پیوند، {1} ایجاد).',
```

In `public/assets/js/i18n.js`, add the same `en`/`fa` keys used by JS (`syncing`, `sync_failed`, `da_linked`, plus a `sync_result` key with a `{linked}`/`{created}` placeholder since `log_da_sync_run` is server-side activity-log copy, not the toast text):

```javascript
    sync_directadmin: 'Sync from DirectAdmin',
    syncing: 'Syncing…',
    sync_failed: 'Sync failed.',
    sync_result: '{linked} linked, {created} new sites created.',
    da_linked: 'DA: {username}',
```
(fa)
```javascript
    sync_directadmin: 'همگام‌سازی از DirectAdmin',
    syncing: 'در حال همگام‌سازی…',
    sync_failed: 'همگام‌سازی ناموفق بود.',
    sync_result: '{linked} پیوند، {created} فروشگاه جدید ایجاد شد.',
    da_linked: 'DA: {username}',
```

- [ ] **Step 5: Syntax check**

Run: `php -l public/api/sites.php && php -l public/admin/sites.php && php -l includes/i18n.php && node --check public/assets/js/admin-sites.js && node --check public/assets/js/i18n.js`
Expected: all report success / no errors.

- [ ] **Step 6: Manual browser verification**

Open Admin -> Sites as superadmin in both `en` and `fa` (language cookie toggle). Click "Sync from DirectAdmin" — confirm the toast shows a count, the site list refreshes, and any DA-linked site shows its `da_username` badge. Confirm RTL layout isn't broken in `fa` (per CLAUDE.md's recurring RTL-bug note).

- [ ] **Step 7: Commit**

```bash
git add public/api/sites.php public/admin/sites.php public/assets/js/admin-sites.js includes/i18n.php public/assets/js/i18n.js
git commit -m "Add DA-linked status and Sync from DirectAdmin button to Admin -> Sites"
```

---

### Task 7: `JetBackupSiteManager` and `SiteBackupManager` routing

**Files:**
- Create: `includes/JetBackupSiteManager.php`
- Modify: `includes/SiteBackupManager.php`

**Interfaces:**
- Consumes: `jetbackup_client()` (Task 4), `site_jetbackup_backups` table (Task 1).
- Produces: `JetBackupSiteManager::startBackup(array $site): array`, `JetBackupSiteManager::pollBackup(array $site, int $localId): array`, `JetBackupSiteManager::startRestore(array $site, int $sourceLocalId): string` (returns the JetBackup restore queue id), `JetBackupSiteManager::listForSite(int $siteId): array` — same row shape as `site_jetbackup_backups` — consumed by Task 8's API layer.

- [ ] **Step 1: Write `JetBackupSiteManager`**

```php
<?php
// includes/JetBackupSiteManager.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/JetBackupClient.php';

/**
 * Backup/restore for sites whose sites.da_username is set — routed through
 * JetBackup instead of the woo-mgmt-agent plugin/S3 job system. JetBackup
 * owns the real job state; site_jetbackup_backups is only a local mirror
 * for the UI history list, refreshed by polling.
 */
class JetBackupSiteManager
{
    public static function startBackup(array $site): array
    {
        $pdo = Database::get();
        $now = time();
        $stmt = $pdo->prepare(
            'INSERT INTO site_jetbackup_backups (site_id, status, created_at) VALUES (?, \'running\', ?)'
        );
        $stmt->execute([$site['id'], $now]);
        $localId = (int) $pdo->lastInsertId();

        try {
            $jetbackupId = jetbackup_client()->createAccountBackup($site['da_username']);
            $pdo->prepare('UPDATE site_jetbackup_backups SET jetbackup_backup_id = ? WHERE id = ?')
                ->execute([$jetbackupId, $localId]);
        } catch (Throwable $e) {
            self::markFailed($localId, $e->getMessage());
        }

        return self::getBackup($localId);
    }

    public static function pollBackup(array $site, int $localId): array
    {
        $row = self::getBackup($localId);
        if ($row === null || $row['status'] !== 'running' || $row['jetbackup_backup_id'] === '') {
            return $row;
        }

        try {
            $status = jetbackup_client()->getBackupStatus($row['jetbackup_backup_id']);
        } catch (Throwable $e) {
            self::markFailed($localId, $e->getMessage());
            return self::getBackup($localId);
        }

        $pdo = Database::get();
        $pdo->prepare(
            'UPDATE site_jetbackup_backups SET status = ?, size_bytes = ?, error = ?, completed_at = ? WHERE id = ?'
        )->execute([
            $status['status'], $status['size_bytes'],
            $status['error'] !== '' ? $status['error'] : null,
            $status['status'] === 'completed' ? time() : null,
            $localId,
        ]);

        return self::getBackup($localId);
    }

    /**
     * Full-account restore only — JetBackup restores files+DB together, so
     * there is no scope selector like the legacy plugin/S3 restore path.
     */
    public static function startRestore(array $site, int $sourceLocalId): string
    {
        $source = self::getBackup($sourceLocalId);
        if ($source === null || $source['status'] !== 'completed') {
            throw new RuntimeException('Source backup is not available to restore from.');
        }

        return jetbackup_client()->restoreAccountBackup($site['da_username'], $source['jetbackup_backup_id']);
    }

    public static function listForSite(int $siteId, int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM site_jetbackup_backups WHERE site_id = ? ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute([$siteId]);
        return $stmt->fetchAll();
    }

    public static function getBackup(int $id): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM site_jetbackup_backups WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    private static function markFailed(int $localId, string $error): void
    {
        $pdo = Database::get();
        $pdo->prepare("UPDATE site_jetbackup_backups SET status = 'failed', error = ? WHERE id = ?")
            ->execute([$error, $localId]);
    }
}
```

- [ ] **Step 2: Route `SiteBackupManager` to JetBackup for DA-linked sites**

In `includes/SiteBackupManager.php`, add the include near the top (after the existing `require_once` block, before the class):

```php
require_once __DIR__ . '/JetBackupSiteManager.php';
```

Then add a routing check as the very first line inside `startBackup()`:

```php
    public static function startBackup(array $site, string $scope): array
    {
        if ($site['da_username'] !== '') {
            return JetBackupSiteManager::startBackup($site);
        }

        $pdo = Database::get();
        // ... existing body unchanged
```

And the same pattern at the top of `pollBackup()`:

```php
    public static function pollBackup(array $site, int $jobId): array
    {
        if ($site['da_username'] !== '') {
            return JetBackupSiteManager::pollBackup($site, $jobId);
        }

        $client = site_agent_client_for_site($site);
        // ... existing body unchanged
```

`startRestore()`/`pollRestore()` are left untouched in this task — Task 8 wires JetBackup restore through a distinct API action instead of reusing the `site_restores`-table-based flow, since a JetBackup restore has no local job row to tick (see Task 8).

- [ ] **Step 3: Syntax check**

Run: `php -l includes/JetBackupSiteManager.php && php -l includes/SiteBackupManager.php`
Expected: both report `No syntax errors detected`

- [ ] **Step 4: Manual verification**

For a site with `da_username` set (from Task 5/6), call `SiteBackupManager::startBackup($site, 'full')` via a one-off script and confirm it returns a `site_jetbackup_backups` row with a `jetbackup_backup_id` populated, and that polling moves it to `completed`. For a site with empty `da_username`, confirm the existing plugin/S3 path is unaffected (unchanged behavior — no regression).

- [ ] **Step 5: Commit**

```bash
git add includes/JetBackupSiteManager.php includes/SiteBackupManager.php
git commit -m "Route DA-linked sites' backups through JetBackup instead of the plugin/S3 job system"
```

---

### Task 8: Site Backups UI — JetBackup path wiring

**Files:**
- Modify: `public/api/site-backups.php`
- Modify: `public/assets/js/admin-site-backups.js`
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Consumes: `JetBackupSiteManager::listForSite()`/`startRestore()` (Task 7).

- [ ] **Step 1: Merge JetBackup history into the list endpoint, add a restore action**

In `public/api/site-backups.php`, replace the `GET` (no action) handler (lines 16-18) to merge both sources when the site is DA-linked:

```php
require_once __DIR__ . '/../../includes/JetBackupSiteManager.php';

if ($method === 'GET' && $action === '') {
    $items = $site['da_username'] !== ''
        ? array_map('map_jetbackup_row', JetBackupSiteManager::listForSite((int) $site['id']))
        : array_map('map_site_backup', SiteBackupManager::listForSite((int) $site['id']));
    json_response(['items' => $items, 'source' => $site['da_username'] !== '' ? 'jetbackup' : 'legacy']);
}
```

Update the `poll` action (lines 20-23) to route the same way:

```php
if ($method === 'GET' && $action === 'poll') {
    $jobId = (int) ($_GET['id'] ?? 0);
    $item = $site['da_username'] !== ''
        ? map_jetbackup_row(JetBackupSiteManager::pollBackup($site, $jobId))
        : map_site_backup(SiteBackupManager::pollBackup($site, $jobId));
    json_response(['item' => $item]);
}
```

Add a `restore` action (after the existing `start` action, before the final 405 fallthrough):

```php
if ($method === 'POST' && $action === 'restore') {
    if ($site['da_username'] === '') {
        json_response(['error' => 'JetBackup restore is only available for DirectAdmin-linked sites.'], 409);
    }
    $sourceId = (int) ($body['source_id'] ?? 0);
    try {
        $restoreId = JetBackupSiteManager::startRestore($site, $sourceId);
    } catch (Throwable $e) {
        json_response(['error' => $e->getMessage()], 502);
    }
    log_activity($user, (int) $site['id'], 'site', 'site_restore_start', 'log_site_restore_started', []);
    json_response(['ok' => true, 'jetbackup_restore_id' => $restoreId], 201);
}
```

Add the mapping function near `map_site_backup()`:

```php
function map_jetbackup_row(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    return [
        'id' => (int) $row['id'],
        'status' => $row['status'],
        'size_bytes' => (int) $row['size_bytes'],
        'error' => $row['error'],
        'created_at' => (int) $row['created_at'],
        'completed_at' => $row['completed_at'] !== null ? (int) $row['completed_at'] : null,
    ];
}
```

- [ ] **Step 2: Adjust the JS to drop the scope selector for JetBackup-sourced history**

In `public/assets/js/admin-site-backups.js`, find where the list response is rendered and read the new top-level `source` field from `loadBackups()`'s response, storing it (e.g. `let backupSource = 'legacy';` at module scope, set inside `loadBackups()`). Where the restore/scope UI is built, guard scope-selector markup with `if (backupSource !== 'jetbackup') { ... }` — JetBackup restores are always full-account, so that selector simply doesn't render for DA-linked sites. Wire a restore button (if one exists per source row) to `POST /api/site-backups.php?action=restore` with `{ source_id }` instead of whatever scope-carrying payload the legacy path uses.

- [ ] **Step 3: Add i18n keys**

`includes/i18n.php` (`en`/`fa`, both):

```php
        'log_site_restore_started' => 'Site restore started.',
```
(fa)
```php
        'log_site_restore_started' => 'بازیابی فروشگاه آغاز شد.',
```

(No new client-facing strings beyond what Task 6/9 already add — the restore button and status labels reuse existing `backup_status_*`/`site_backups_heading` keys already in both files.)

- [ ] **Step 4: Syntax check**

Run: `php -l public/api/site-backups.php && node --check public/assets/js/admin-site-backups.js`
Expected: both succeed.

- [ ] **Step 5: Manual verification**

For a DA-linked site: run a backup, confirm it completes, then trigger a restore and confirm the API call succeeds and no scope selector appears. For a non-DA-linked site: confirm the legacy flow (scope selector included) still works exactly as before.

- [ ] **Step 6: Commit**

```bash
git add public/api/site-backups.php public/assets/js/admin-site-backups.js includes/i18n.php
git commit -m "Wire JetBackup backup/restore into the Site Backups UI for DA-linked sites"
```

---

### Task 9: `AppJetBackupManager` — woo-managment's own backup

**Files:**
- Create: `includes/AppJetBackupManager.php`
- Modify: `public/api/backups.php`
- Modify: `public/cron/backup.php`

**Interfaces:**
- Consumes: `jetbackup_client()` (Task 4), `get_setting('da_self_username')`.
- Produces: `AppJetBackupManager::run(): array` (same result shape as `BackupManager::run()`), `AppJetBackupManager::listRecent(): array`, `AppJetBackupManager::isConfigured(): bool`.

- [ ] **Step 1: Write the manager**

```php
<?php
// includes/AppJetBackupManager.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/JetBackupClient.php';

/**
 * woo-managment's own backup, fully independent from managed-site backups
 * (JetBackupSiteManager) — separate DA account, separate trigger, separate
 * history — per the explicit requirement to keep app self-backup as its own
 * system. Reuses the existing `backups` table (external_ref instead of
 * s3_key) so Admin -> Backups needs only a small UI change, not a rewrite.
 */
class AppJetBackupManager
{
    public static function isConfigured(): bool
    {
        return trim((string) get_setting('da_self_username')) !== '' && jetbackup_client()->isConfigured();
    }

    /**
     * Triggers a JetBackup snapshot of this app's own DA account and waits
     * (bounded) for it to finish, since this is called synchronously from
     * an admin button click — same UX as the legacy "Run backup now".
     *
     * @return array{ok: bool, type: string, external_ref: string, size_bytes: int, error: string}
     */
    public static function run(): array
    {
        $daUsername = (string) get_setting('da_self_username');
        if ($daUsername === '') {
            return self::recordFailure('', 'DirectAdmin self-account username is not configured (see Admin -> Settings).');
        }

        try {
            $client = jetbackup_client();
            $backupId = $client->createAccountBackup($daUsername);
        } catch (Throwable $e) {
            return self::recordFailure('', $e->getMessage());
        }

        $status = self::waitForCompletion($client, $backupId);
        if ($status['status'] !== 'completed') {
            return self::recordFailure($backupId, $status['error'] !== '' ? $status['error'] : 'JetBackup snapshot did not complete in time.', $status['size_bytes']);
        }

        self::recordResult('success', $backupId, $status['size_bytes'], '');
        return ['ok' => true, 'type' => 'jetbackup', 'external_ref' => $backupId, 'size_bytes' => $status['size_bytes'], 'error' => ''];
    }

    public static function listRecent(int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare("SELECT * FROM backups WHERE external_ref != '' ORDER BY id DESC LIMIT " . max(1, min(200, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Polls JetBackup for up to 5 minutes (10s interval) — long enough for
     * a typical account snapshot, bounded so a stuck job doesn't hang the
     * admin's request forever.
     */
    private static function waitForCompletion(JetBackupClient $client, string $backupId): array
    {
        $deadline = time() + 300;
        do {
            $status = $client->getBackupStatus($backupId);
            if ($status['status'] !== 'running') {
                return $status;
            }
            sleep(10);
        } while (time() < $deadline);

        return ['status' => 'running', 'size_bytes' => 0, 'error' => 'Timed out waiting for JetBackup to finish.'];
    }

    private static function recordResult(string $status, string $externalRef, int $size, string $error): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO backups (type, status, s3_key, external_ref, size_bytes, error, created_at) VALUES (\'jetbackup\', ?, \'\', ?, ?, ?, ?)'
        );
        $stmt->execute([$status, $externalRef, $size, $error !== '' ? $error : null, time()]);
    }

    private static function recordFailure(string $externalRef, string $error, int $size = 0): array
    {
        self::recordResult('failed', $externalRef, $size, $error);
        return ['ok' => false, 'type' => 'jetbackup', 'external_ref' => $externalRef, 'size_bytes' => $size, 'error' => $error];
    }
}
```

- [ ] **Step 2: Route the API endpoint to JetBackup when configured**

In `public/api/backups.php`, add the include and a branch inside the `POST` handler:

```php
require_once __DIR__ . '/../../includes/AppJetBackupManager.php';
```

```php
if ($method === 'POST') {
    verify_csrf_api();
    $body = json_body();
    $type = ($body['type'] ?? 'database') === 'full' ? 'full' : 'database';

    $result = AppJetBackupManager::isConfigured()
        ? AppJetBackupManager::run()
        : BackupManager::run($type);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['ok' => true, 'item' => $result]);
}
```

Update the `GET` handler to merge both sources and the `format_backup_row()` helper to include `external_ref`:

```php
if ($method === 'GET') {
    json_response(['items' => array_map('format_backup_row', BackupManager::listRecent())]);
}
```
stays as-is structurally (both JetBackup and legacy rows live in the same `backups` table now — `BackupManager::listRecent()` already does `SELECT * FROM backups ORDER BY id DESC`, so it naturally includes JetBackup rows). Only `format_backup_row()` needs one more field:

```php
function format_backup_row(array $row): array
{
    return [
        'id' => (int) $row['id'],
        'type' => $row['type'],
        'status' => $row['status'],
        's3_key' => $row['s3_key'],
        'external_ref' => $row['external_ref'],
        'size_bytes' => (int) $row['size_bytes'],
        'error' => $row['error'],
        'created_at' => (int) $row['created_at'],
    ];
}
```

- [ ] **Step 3: Route the cron endpoint the same way**

In `public/cron/backup.php`, add the include and branch:

```php
require_once __DIR__ . '/../../includes/AppJetBackupManager.php';
```

```php
$type = ($_GET['type'] ?? 'database') === 'full' ? 'full' : 'database';
$result = AppJetBackupManager::isConfigured() ? AppJetBackupManager::run() : BackupManager::run($type);

json_response($result, $result['ok'] ? 200 : 502);
```

- [ ] **Step 4: Syntax check**

Run: `php -l includes/AppJetBackupManager.php && php -l public/api/backups.php && php -l public/cron/backup.php`
Expected: all three report `No syntax errors detected`

- [ ] **Step 5: Manual verification**

With `da_self_username` configured, click "Run backup now" (database button — both buttons now trigger the same full-account JetBackup snapshot since JetBackup doesn't distinguish db-only/full the way the legacy path did) on Admin -> Backups. Confirm it blocks briefly, then shows success with a JetBackup-sourced row in the history list. Clear `da_self_username` and confirm the legacy S3 path still runs unchanged.

- [ ] **Step 6: Commit**

```bash
git add includes/AppJetBackupManager.php public/api/backups.php public/cron/backup.php
git commit -m "Add AppJetBackupManager: independent JetBackup path for woo-managment's own backup"
```

---

### Task 10: App Backups UI — source label

**Files:**
- Modify: `public/assets/js/admin-backups.js`
- Modify: `includes/i18n.php`
- Modify: `public/assets/js/i18n.js`

**Interfaces:**
- Consumes: `external_ref` field on backup rows (Task 9).

- [ ] **Step 1: Show the backup source in each history row**

In `public/assets/js/admin-backups.js`, inside `renderBackups()`'s `items.forEach()` block, add a source label alongside the existing `typeLabel`/`statusLabel`:

```javascript
    const sourceLabel = item.external_ref ? t('backup_source_jetbackup') : t('backup_source_legacy');
```

and include `${escapeHtml(sourceLabel)}` in the row's template string (e.g. appended after `statusLabel`, separated by ` · ` like the existing `typeLabel`/`statusLabel` pairing).

- [ ] **Step 2: Add i18n keys**

`includes/i18n.php` (`en`, near `backup_history`):

```php
        'backup_source_jetbackup' => 'JetBackup',
        'backup_source_legacy' => 'S3',
```
(fa)
```php
        'backup_source_jetbackup' => 'جت‌بکاپ',
        'backup_source_legacy' => 'اس‌تری',
```

`public/assets/js/i18n.js` (`en`):

```javascript
    backup_source_jetbackup: 'JetBackup',
    backup_source_legacy: 'S3',
```
(fa)
```javascript
    backup_source_jetbackup: 'جت‌بکاپ',
    backup_source_legacy: 'اس‌تری',
```

- [ ] **Step 3: Syntax check**

Run: `node --check public/assets/js/admin-backups.js && php -l includes/i18n.php`
Expected: both succeed.

- [ ] **Step 4: Manual verification**

Open Admin -> Backups with at least one JetBackup-sourced row and one legacy row in history (or fake one via a manual DB insert if you don't have both yet); confirm each shows the correct source label, in both `en` and `fa`.

- [ ] **Step 5: Commit**

```bash
git add public/assets/js/admin-backups.js includes/i18n.php public/assets/js/i18n.js
git commit -m "Label backup history rows by source (JetBackup vs legacy S3)"
```

---

### Task 11: Final sweep

**Files:** none new — verification only.

- [ ] **Step 1: Full syntax sweep**

Run:
```bash
for f in includes/DirectAdminClient.php includes/JetBackupClient.php includes/DirectAdminSync.php includes/JetBackupSiteManager.php includes/AppJetBackupManager.php includes/Sites.php includes/SiteBackupManager.php includes/Settings.php includes/Database.php includes/i18n.php public/api/sites.php public/api/settings.php public/api/site-backups.php public/api/backups.php public/api/da-sync.php public/cron/da-sync.php public/cron/backup.php public/admin/sites.php; do php -l "$f" || echo "FAILED: $f"; done
node --check public/assets/js/admin-sites.js
node --check public/assets/js/admin-site-backups.js
node --check public/assets/js/admin-backups.js
node --check public/assets/js/i18n.js
```
Expected: every line reports success, no `FAILED:` lines.

- [ ] **Step 2: End-to-end manual walkthrough**

As superadmin, in both `en` and `fa`:
1. Configure DirectAdmin/JetBackup settings.
2. Sync from DirectAdmin on Admin -> Sites; confirm linked/created counts and badges.
3. Run a JetBackup site backup + restore on a DA-linked site.
4. Confirm an external (non-DA) site's backup/restore still works via the legacy path.
5. Run woo-managment's own JetBackup backup from Admin -> Backups; confirm it's independent of the site-backup history.
6. Confirm the legacy S3 app-backup still works when `da_self_username` is cleared.

- [ ] **Step 3: Commit** (only if Step 1/2 surfaced fixes; otherwise nothing to commit)
