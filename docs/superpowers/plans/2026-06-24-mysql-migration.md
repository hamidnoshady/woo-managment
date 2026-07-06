# MySQL Migration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the SQLite datastore (`data/app.sqlite`) with MySQL, so the app's database lives on a MySQL server (via cPanel) instead of a file that can be silently overwritten by a deploy/file-upload.

**Architecture:** `includes/Database.php` currently opens a SQLite file and creates its schema with SQLite-specific SQL (`AUTOINCREMENT`, `PRAGMA`, `INSERT ... ON CONFLICT`). Swap the PDO DSN to `mysql:...`, rewrite every `CREATE TABLE` to MySQL syntax (`AUTO_INCREMENT`, `ENGINE=InnoDB`, inline `KEY` clauses instead of separate `CREATE INDEX`), and fix the handful of call sites elsewhere in `includes/` that use SQLite-only syntax (`ON CONFLICT`, the unquoted `key` column — `KEY` is a reserved word in MySQL). Connection credentials can't live in the app's own `settings` table (chicken-and-egg: you need a DB connection to read it), so they move to a new gitignored `includes/config.php`.

**Tech Stack:** PHP 8 `pdo_mysql` extension (built into PHP, no Composer needed). MySQL 5.7+/MariaDB 10.2+ (matches typical cPanel hosting).

## Global Constraints

- No Composer, no build step — this must keep working by uploading plain PHP files (per [CLAUDE.md](../../../CLAUDE.md)).
- No test suite/linter in this repo. Verify every change with `php -l <file>` and by manually exercising the affected page in a browser, per CLAUDE.md's Verification section.
- This is a pre-launch/testing-phase app with no production data to preserve — schema can be written directly in its final MySQL form, no data-migration script needed.
- Keep the existing `Database::get()` singleton-PDO-connection pattern; every other file in `includes/`/`public/` calls `Database::get()` and must keep working unmodified except where it uses SQLite-only SQL syntax.
- Persian (RTL) text is stored throughout (names, descriptions) — all tables must use `utf8mb4`, not plain `utf8` (which doesn't cover the full Unicode range).

---

## File Structure

- Create: `includes/config.example.php` — checked into git, template for MySQL credentials.
- Create: `includes/config.php` — gitignored, the real credentials (created locally by you during testing, and on the server during deploy).
- Modify: `includes/Database.php` — swap SQLite for MySQL: connection, schema, drop the now-unnecessary SQLite-install migration shim.
- Modify: `includes/Settings.php` — `key` column needs backticks (reserved word), `ON CONFLICT` → `ON DUPLICATE KEY UPDATE`.
- Modify: `includes/helpers.php` — same two fixes in `cache_get`/`cache_set`/`cache_delete_prefix`.
- Modify: `.gitignore` — stop ignoring `data/*.sqlite*` (no longer used), start ignoring `includes/config.php`.
- Modify: `README.md` — replace every SQLite-specific setup instruction with the MySQL equivalent.
- Modify: `CLAUDE.md` — update the "SQLite is the only datastore... no separate config file" claims.

---

### Task 1: Add the MySQL config file mechanism

**Files:**
- Create: `includes/config.example.php`
- Modify: `.gitignore`

**Interfaces:**
- Produces: a file `includes/config.php` that returns an array with keys `host`, `port`, `database`, `username`, `password` — this is what Task 2's `Database.php` rewrite consumes.

- [ ] **Step 1: Create the example config**

```php
<?php

// Copy this file to includes/config.php and fill in the MySQL database
// you created via cPanel -> MySQL Databases (or phpMyAdmin). includes/config.php
// is gitignored — never commit real credentials.
//
// This file's existence (and includes/config.php's absence from git) is the
// reason database credentials live here instead of in the app's own
// settings table: the app needs a database connection before it can read
// anything out of that table.

return [
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'your_db_name',
    'username' => 'your_db_user',
    'password' => 'your_db_password',
];
```

Save as `includes/config.example.php`.

- [ ] **Step 2: Create your real local config**

Copy it: `cp includes/config.example.php includes/config.php` and fill in
credentials for a MySQL database you can reach right now (a local MySQL/MariaDB
install, or directly the cPanel one if you're testing against it). The database
itself must already exist — this app creates tables, not the database — so
create an empty database first (cPanel → MySQL Databases, or
`CREATE DATABASE your_db_name CHARACTER SET utf8mb4;` in phpMyAdmin).

- [ ] **Step 3: Update `.gitignore`**

```
includes/config.php
```

Replace the current contents of `.gitignore` (`data/*.sqlite`,
`data/*.sqlite-journal`) with the line above — the `data/` directory and its
SQLite file are no longer used by the app after Task 2.

- [ ] **Step 4: Verify**

Run: `php -l includes/config.example.php` (and `includes/config.php` if you created it)
Expected: `No syntax errors detected` for both.

- [ ] **Step 5: Commit**

```bash
git add includes/config.example.php .gitignore
git commit -m "Add MySQL config file mechanism"
```

(Do not `git add includes/config.php` — it's gitignored on purpose.)

---

### Task 2: Rewrite `Database.php` for MySQL

**Files:**
- Modify: `includes/Database.php`

**Interfaces:**
- Consumes: `includes/config.php` (array with `host`/`port`/`database`/`username`/`password`, from Task 1).
- Produces: `Database::get(): PDO` — same signature every other file already calls; the PDO instance now talks to MySQL with `PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC` set explicitly (every caller already does `$row['column']` access, so this is a no-op for them, just removes reliance on PDO's default `FETCH_BOTH`).

- [ ] **Step 1: Replace the whole file**

```php
<?php

/**
 * MySQL wrapper for the app database (users, sites, site assignments, OTP
 * codes). Creates the schema on first use. Connection credentials come from
 * includes/config.php (gitignored) — see includes/config.example.php.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $configPath = __DIR__ . '/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Missing includes/config.php. Copy includes/config.example.php to ' .
                'includes/config.php and fill in your MySQL connection details.'
            );
        }
        $config = require $configPath;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                code VARCHAR(16) NOT NULL,
                created_at INT NOT NULL,
                expires_at INT NOT NULL,
                consumed TINYINT NOT NULL DEFAULT 0,
                KEY idx_otp_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_verify_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                created_at INT NOT NULL,
                KEY idx_otp_attempts_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL DEFAULT \'\',
                role VARCHAR(20) NOT NULL CHECK (role IN (\'superadmin\', \'admin\', \'shop_manager\')),
                created_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                store_url VARCHAR(500) NOT NULL,
                consumer_key VARCHAR(255) NOT NULL,
                consumer_secret VARCHAR(255) NOT NULL,
                verify_ssl TINYINT NOT NULL DEFAULT 1,
                wp_username VARCHAR(255) NOT NULL DEFAULT \'\',
                wp_app_password VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_sites (
                user_id INT NOT NULL,
                site_id INT NOT NULL,
                PRIMARY KEY (user_id, site_id),
                CONSTRAINT fk_user_sites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_sites_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_name VARCHAR(255) NOT NULL DEFAULT \'\',
                user_phone VARCHAR(20) NOT NULL DEFAULT \'\',
                site_id INT NULL,
                category VARCHAR(20) NOT NULL DEFAULT \'site\',
                action VARCHAR(50) NOT NULL,
                message_key VARCHAR(100) NOT NULL,
                message_params TEXT NOT NULL,
                undo_data TEXT NULL,
                undone TINYINT NOT NULL DEFAULT 0,
                created_at INT NOT NULL,
                KEY idx_activity_logs_user (user_id),
                KEY idx_activity_logs_site (site_id),
                KEY idx_activity_logs_created (created_at),
                KEY idx_activity_logs_site_category (site_id, category, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS kv_cache (
                `key` VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::$pdo = $pdo;
        return $pdo;
    }
}
```

Notes on what changed from the SQLite version, for context if you're
reviewing this diff:
- `AUTOINCREMENT` → `AUTO_INCREMENT`, with `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4` on every table (InnoDB for foreign-key support, utf8mb4 for full Unicode incl. Persian text and emoji).
- Indexes that were separate `CREATE INDEX IF NOT EXISTS` statements are now inline `KEY name (cols)` clauses inside the `CREATE TABLE` — simpler, and avoids MySQL/MariaDB version differences around `CREATE INDEX IF NOT EXISTS` support.
- Columns that had `TEXT ... DEFAULT '...'` (`name`, `user_name`, `user_phone`, `wp_username`, `wp_app_password`) became `VARCHAR` — MySQL/MariaDB versions before 8.0.13/10.2 don't allow a `DEFAULT` on `TEXT` columns. `message_params`/`undo_data`/`value` stay `TEXT` since the application code always supplies them explicitly (no `DEFAULT` needed).
- `key` is a reserved word in MySQL, so it's backtick-quoted in `settings`/`kv_cache`.
- The old `wp_username`/`wp_app_password` "ALTER TABLE if column missing" shim is gone — that only existed to upgrade pre-existing SQLite installs that predated those columns; a fresh MySQL schema just includes them from the start.
- `PRAGMA` lines (foreign keys, WAL, synchronous) are SQLite-only and removed; MySQL's InnoDB engine enforces foreign keys and handles concurrent readers/writers without configuration.

- [ ] **Step 2: Verify syntax**

Run: `php -l includes/Database.php`
Expected: `No syntax errors detected in includes/Database.php`

- [ ] **Step 3: Verify it actually connects and creates tables**

Run (from the project root, with `includes/config.php` pointing at a real, empty, already-created MySQL database):
```bash
php -r "require 'includes/Database.php'; Database::get(); echo \"connected and schema created\n\";"
```
Expected output: `connected and schema created` with no PHP errors/warnings above it.

Then check the tables landed correctly, e.g. via phpMyAdmin or:
```bash
php -r "
require 'includes/Database.php';
\$pdo = Database::get();
print_r(\$pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN));
"
```
Expected: an array containing `otp_codes`, `otp_verify_attempts`, `users`, `sites`, `user_sites`, `settings`, `activity_logs`, `kv_cache`.

- [ ] **Step 4: Commit**

```bash
git add includes/Database.php
git commit -m "Migrate Database.php from SQLite to MySQL"
```

---

### Task 3: Fix the SQLite-only SQL in `Settings.php` and `helpers.php`

**Files:**
- Modify: `includes/Settings.php:131-163`
- Modify: `includes/helpers.php` (the `cache_get`, `cache_set`, `cache_delete_prefix` functions added in the products-cache change)

**Interfaces:**
- Consumes: `Database::get()` from Task 2.
- Produces: no interface change — `get_settings()`, `update_settings()`, `cache_get()`, `cache_set()`, `cache_delete_prefix()` keep their exact existing signatures; only the SQL text inside them changes.

- [ ] **Step 1: Fix `includes/Settings.php`**

Find this in `get_settings()`:
```php
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
```
Replace with:
```php
    $rows = $pdo->query('SELECT `key`, value FROM settings')->fetchAll();
```

Find this in `update_settings()`:
```php
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );
```
Replace with:
```php
    $stmt = $pdo->prepare(
        'INSERT INTO settings (`key`, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );
```

- [ ] **Step 2: Fix `includes/helpers.php`**

Find `cache_get()`:
```php
    $stmt = $pdo->prepare('SELECT value FROM kv_cache WHERE key = ? AND expires_at > ?');
```
Replace with:
```php
    $stmt = $pdo->prepare('SELECT value FROM kv_cache WHERE `key` = ? AND expires_at > ?');
```

Find `cache_set()`:
```php
    $stmt = $pdo->prepare(
        'INSERT INTO kv_cache (key, value, expires_at) VALUES (?, ?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value, expires_at = excluded.expires_at'
    );
```
Replace with:
```php
    $stmt = $pdo->prepare(
        'INSERT INTO kv_cache (`key`, value, expires_at) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)'
    );
```

Find `cache_delete_prefix()`:
```php
    $stmt = $pdo->prepare('DELETE FROM kv_cache WHERE key LIKE ? ESCAPE \'\\\'');
```
Replace with:
```php
    $stmt = $pdo->prepare('DELETE FROM kv_cache WHERE `key` LIKE ? ESCAPE \'\\\'');
```

- [ ] **Step 3: Verify syntax**

Run: `php -l includes/Settings.php && php -l includes/helpers.php`
Expected: `No syntax errors detected` for both.

- [ ] **Step 4: Verify behavior against the real MySQL database**

```bash
php -r "
require 'includes/Settings.php';
update_settings(['ai_model' => 'test-model']);
echo get_setting('ai_model') . \"\n\";
update_settings(['ai_model' => 'test-model-2']);
echo get_setting('ai_model') . \"\n\";
"
```
Expected: prints `test-model` then `test-model-2` — confirming both the insert and the update-on-conflict path work (the second call hits the `ON DUPLICATE KEY UPDATE` branch since the row already exists).

```bash
php -r "
require 'includes/helpers.php';
cache_set('test:foo', ['a' => 1], 30);
var_dump(cache_get('test:foo'));
cache_delete_prefix('test:');
var_dump(cache_get('test:foo'));
"
```
Expected: first `var_dump` shows `array(1) { ["a"]=> int(1) }`, second shows `NULL`.

- [ ] **Step 5: Commit**

```bash
git add includes/Settings.php includes/helpers.php
git commit -m "Fix SQLite-only SQL (ON CONFLICT, unquoted key column) for MySQL"
```

---

### Task 4: End-to-end manual verification through the app

**Files:** none (verification only)

- [ ] **Step 1: Fresh install flow**

With an empty MySQL database (drop and recreate it, or `TRUNCATE`/drop all tables, if you ran Task 2/3's CLI checks against it already), start a local PHP server pointed at `public/`:
```bash
php -S localhost:8080 -t public
```
Visit `http://localhost:8080/` in a browser. Confirm you're redirected to `/install.php` (proves `users` table reads/writes work against MySQL), submit the form with a test phone number, confirm it redirects to `/login.php`.

- [ ] **Step 2: Settings round-trip**

Log in (OTP requires Kavenegar configured — if you don't have that yet, skip to manually setting a superadmin role in phpMyAdmin: `UPDATE users SET role='superadmin' WHERE id=1;`, then use the session cookie from the install step, or temporarily stub login for this check). In *Admin → Settings*, change a field (e.g. the AI model name), save, reload the page — confirm the saved value persists (exercises `update_settings()`'s `ON DUPLICATE KEY UPDATE` path against real MySQL).

- [ ] **Step 3: Sites + users**

In *Admin → Sites*, add a test site (any values — this just needs to write to the `sites` table). In *Admin → Users*, create a second account and assign it to that site (exercises `user_sites`, including its foreign keys).

- [ ] **Step 4: Products list + cache**

With a real WooCommerce site connected, open `/products.php` twice in a row. The second load should be visibly faster (hits `kv_cache` via the products-cache feature) — confirms `kv_cache` works end-to-end against MySQL, including the `ON DUPLICATE KEY UPDATE` and backtick-quoted `key` column fixes from Task 3.

- [ ] **Step 5: Both languages**

Switch to Persian (فا) via the language toggle and repeat steps 2-4 briefly — confirms RTL text written through these same code paths round-trips correctly with `utf8mb4`.

---

### Task 5: Update documentation

**Files:**
- Modify: `README.md`
- Modify: `CLAUDE.md`

- [ ] **Step 1: Update `README.md`**

In the **Requirements** section, replace:
```
- PHP 8.0+ with `curl` and `pdo_sqlite` extensions enabled (both are included
  by default on virtually all shared hosts).
```
with:
```
- PHP 8.0+ with `curl` and `pdo_mysql` extensions enabled (both are included
  by default on virtually all shared hosts).
- A MySQL 5.7+/MariaDB 10.2+ database (most shared hosts provide this via
  cPanel -> MySQL Databases).
```

In **Directory layout**, replace the `data/` line:
```
  data/          # SQLite database for users/sites/settings/OTP codes (created automatically)
```
with nothing (delete the line — there's no `data/` directory anymore) and update the paragraph below the tree that currently reads "There is **no config file to create or edit**...", replacing it with:
```
Everything except database connection details (Kavenegar API key, OTP
settings, session settings, superadmin phone numbers, WooCommerce sites, and
users) is stored in the MySQL database and managed from the app itself.
Database connection details live in `includes/config.php` (copy it from
`includes/config.example.php` and fill in the MySQL database you created via
cPanel/phpMyAdmin) — this one file can't live in the database itself, since
the app needs a database connection before it can read anything out of it.
```

Replace **Setup** step 3/4 (currently about uploading and `chmod`-ing `data/`)
with:
```
### 3. Create a MySQL database

Via cPanel -> MySQL Databases (or phpMyAdmin), create a new database and a
database user with full privileges on it. Note the database name, username,
password, and host (usually `localhost` or `127.0.0.1` on shared hosting).

### 4. Upload to your host and configure the database connection

Upload the entire project directory to your server, and point your
domain/subdomain's document root at the `public` folder inside it (see the
cPanel-specific steps below if you can't change the document root).

Copy `includes/config.example.php` to `includes/config.php` and fill in the
database details from step 3.
```
Renumber the remaining steps (old step 5 "Run the first-run setup wizard"
becomes step 5, old step 6 stays step 6).

In **Installing on cPanel**, replace step 3's extension check:
```
   - cPanel → *MultiPHP INI Editor* → confirm `curl` and `pdo_sqlite` (or
     `sqlite3`) extensions are enabled (they're on by default on most hosts).
```
with:
```
   - cPanel → *MultiPHP INI Editor* → confirm `curl` and `pdo_mysql`
     extensions are enabled (they're on by default on most hosts).
```
Replace step 4 ("Make `data/` writable"):
```
4. **Create a MySQL database.**
   - cPanel → *MySQL Databases* → create a database and a user with full
     privileges on it. Note the database name, username, password, and host.
   - Copy `includes/config.example.php` to `includes/config.php` (e.g. via
     File Manager) and fill in those details.
```

Finally, update the "Adding more superadmins" section's last paragraph
(currently mentions opening `data/app.sqlite` with a SQLite client):
```
If you ever lose access to all superadmin accounts, you can restore access by
opening phpMyAdmin and updating a user's `role` column to `superadmin` in the
`users` table (or deleting all rows from the `users` table so `/install.php`
runs again).
```

- [ ] **Step 2: Update `CLAUDE.md`**

Replace:
```
Plain PHP 8 (no Composer, no build step) so it can be deployed by uploading
files to any shared host. SQLite (`data/app.sqlite`) is the only datastore;
there's no separate config file — everything is managed in-app. See
[README.md](README.md) for setup/deployment and feature details.
```
with:
```
Plain PHP 8 (no Composer, no build step) so it can be deployed by uploading
files to any shared host. MySQL is the datastore; connection credentials live
in `includes/config.php` (gitignored, copy from `includes/config.example.php`)
since the app needs a database connection before it can read its own settings
table — everything else is managed in-app. See [README.md](README.md) for
setup/deployment and feature details.
```

Replace the directory layout bullet:
```
- `data/` — SQLite DB, outside the web root, created automatically.
```
with nothing (remove it — superseded by the `includes/config.php` mention above).

Replace the **Database** section:
```
## Database

Schema lives entirely in [includes/Database.php](includes/Database.php) as
inline `CREATE TABLE IF NOT EXISTS` — there is no migration framework. To
add a column to an existing table, follow the existing pattern: check
`PRAGMA table_info($table)` for the column and `ALTER TABLE ... ADD COLUMN`
if missing, so older installs upgrade in place on next request.
```
with:
```
## Database

MySQL. Schema lives entirely in [includes/Database.php](includes/Database.php)
as inline `CREATE TABLE IF NOT EXISTS` — there is no migration framework. To
add a column to an existing table, check
`INFORMATION_SCHEMA.COLUMNS` for the column (`WHERE table_schema = DATABASE()
AND table_name = ? AND column_name = ?`) and `ALTER TABLE ... ADD COLUMN` if
missing, so existing installs upgrade in place on next request. `key` is a
reserved word in MySQL — always backtick-quote it (`` `key` ``) in the
`settings`/`kv_cache` tables. Use `INSERT ... ON DUPLICATE KEY UPDATE`, not
SQLite's `ON CONFLICT` syntax.
```

- [ ] **Step 3: Verify**

Re-read both files end to end and confirm no remaining mentions of `sqlite`,
`PRAGMA`, `data/app.sqlite`, or "no config file" survived the edits:
```bash
grep -in "sqlite\|pragma\|no config file" README.md CLAUDE.md
```
Expected: no output.

- [ ] **Step 4: Commit**

```bash
git add README.md CLAUDE.md
git commit -m "Update docs for MySQL migration"
```
