# Task Management + Notifications Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let superadmin/admin create tasks, assign them to one or more users, and manually notify assignees by SMS (Kavenegar) or browser push (standard Web Push/VAPID), following [docs/superpowers/specs/2026-07-05-task-management-design.md](../specs/2026-07-05-task-management-design.md).

**Architecture:** Site-scoped task/comment/assignee tables following the existing `activity_logs`/`user_sites` patterns; a vendored pure-PHP Web Push (RFC 8291/8292) sender since there's no Composer; SMS reuses the existing Kavenegar curl pattern with a second API key. Frontend follows the existing bottom-sheet-modal + `App.api()`/`App.toast()` conventions used by `public/admin/users.php`/`admin-users.js`.

**Tech Stack:** PHP 8 (no Composer), MySQL, vanilla JS, Tailwind (CDN), PHP's built-in `openssl`/`hash_hkdf` for Web Push crypto, browser `Intl.DateTimeFormat` (Persian calendar) for Jalali dates.

## Global Constraints

- No test framework exists in this project and none should be added — verification is `php -l` / `node --check` plus manual browser exercise, per `CLAUDE.md`'s Verification section. Tasks below adapt the usual write-test/run-test cycle accordingly: PHP/JS tasks verify with a syntax check (and, for Task 6, a one-off crypto self-check against a published test vector, run from the scratchpad and not committed); full functional verification happens once the UI exists (Task 17's manual QA pass).
- `key` is a reserved word in MySQL and is always backtick-quoted; use `INSERT ... ON DUPLICATE KEY UPDATE`, never SQLite syntax (per `CLAUDE.md`).
- Every new mutating API file follows the exact boilerplate order from `CLAUDE.md`: `helpers.php` + `install_json_fatal_handler()` → `auth.php` → other includes → `require_login_api()` → `require_site_api()` (if site-scoped) → `verify_csrf_api()` → method check → `json_body()`. `install_json_fatal_handler()` is only called from files under `public/api/`, never from HTML page files.
- Any new client-side `t(...)` key must be added to **both** `includes/i18n.php` and `public/assets/js/i18n.js`, symmetric between `en`/`fa` (per `CLAUDE.md`'s i18n gotcha).
- A new `SETTINGS_FIELDS` group label must also be added to `settings_group_key()`'s map in `public/api/settings.php`, or its English label leaks through regardless of language (per `CLAUDE.md`'s gotcha).
- The admin sub-nav tab row is duplicated per-page and is NOT touched by this feature (Tasks is not an admin-only page); only `includes/nav.php`'s shared bottom-nav/sidebar needs a new entry.
- Run `php -l <file>` on every new/modified PHP file and `node --check <file>` on every new/modified JS file before committing.

---

### Task 1: Database schema — tasks, task_assignees, task_comments, push_subscriptions

**Files:**
- Modify: `includes/Database.php:181-197` (insert new `CREATE TABLE IF NOT EXISTS` blocks right after the existing `site_restores` block and before `self::$pdo = $pdo;`)

**Interfaces:**
- Produces: four new MySQL tables (`tasks`, `task_assignees`, `task_comments`, `push_subscriptions`) that every later task's SQL relies on by name/column.

- [ ] **Step 1: Add the four `CREATE TABLE IF NOT EXISTS` blocks**

Insert this block into `includes/Database.php`, immediately after the `site_restores` block (currently ending at line 181, `);`) and before line 183 (`self::addColumnIfMissing($pdo, 'sites', 'agent_token', ...)`):

```php
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS tasks (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                title VARCHAR(255) NOT NULL,
                description TEXT NULL,
                due_date DATE NULL,
                priority VARCHAR(10) NOT NULL DEFAULT \'medium\' CHECK (priority IN (\'low\', \'medium\', \'high\')),
                status VARCHAR(20) NOT NULL DEFAULT \'todo\' CHECK (status IN (\'todo\', \'in_progress\', \'done\')),
                created_by INT NOT NULL,
                created_at INT NOT NULL,
                updated_at INT NOT NULL,
                KEY idx_tasks_site (site_id, id),
                CONSTRAINT fk_tasks_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS task_assignees (
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                PRIMARY KEY (task_id, user_id),
                CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS task_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                task_id INT NOT NULL,
                user_id INT NOT NULL,
                body TEXT NOT NULL,
                created_at INT NOT NULL,
                KEY idx_task_comments_task (task_id, id),
                CONSTRAINT fk_tc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
                CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS push_subscriptions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                endpoint VARCHAR(500) NOT NULL,
                p256dh VARCHAR(255) NOT NULL,
                auth VARCHAR(255) NOT NULL,
                created_at INT NOT NULL,
                UNIQUE KEY uniq_push_endpoint (endpoint),
                KEY idx_push_subscriptions_user (user_id),
                CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/Database.php`
Expected: `No syntax errors detected in includes/Database.php`

- [ ] **Step 3: Verify the tables get created**

Run: `php -r "require 'includes/Database.php'; Database::get(); echo 'ok';"`
Expected: prints `ok` with no errors (this executes `Database::get()`, which runs every `CREATE TABLE IF NOT EXISTS` against your local MySQL). If it errors, read the message — likely a missing `includes/config.php` (copy from `includes/config.example.php` first) or a real SQL typo.

- [ ] **Step 4: Commit**

```bash
git add includes/Database.php
git commit -m "Add tasks/task_assignees/task_comments/push_subscriptions tables"
```

---

### Task 2: Settings — Kavenegar SMS notification key + VAPID key storage slots

**Files:**
- Modify: `includes/Settings.php:10-32` (`SETTINGS_DEFAULTS`), `includes/Settings.php:38-159` (`SETTINGS_FIELDS`)
- Modify: `public/api/settings.php:17-29` (`settings_group_key()`)

**Interfaces:**
- Consumes: `get_setting()`/`update_settings()` from `includes/Settings.php` (unchanged signatures).
- Produces: `kavenegar_sms_api_key`, `kavenegar_sms_sender` settings (shown in the Settings UI, translated via Task 3's i18n keys), and `vapid_public_key`/`vapid_private_key_pem` settings (storage slots only — deliberately **not** added to `SETTINGS_FIELDS`, so they never appear in the Settings UI; Task 6's `webpush_vapid_keypair()` reads/writes them directly).

- [ ] **Step 1: Add the new keys to `SETTINGS_DEFAULTS`**

In `includes/Settings.php`, add these four lines inside the `SETTINGS_DEFAULTS` array (after `'backup_retention_days' => 30,` on line 31):

```php
    'kavenegar_sms_api_key' => '',
    'kavenegar_sms_sender'  => '',
    'vapid_public_key'      => '',
    'vapid_private_key_pem' => '',
```

- [ ] **Step 2: Add the two visible fields to `SETTINGS_FIELDS`**

Add this block inside `SETTINGS_FIELDS` (after the `backup_retention_days` entry, before the closing `];` on line 159):

```php
    'kavenegar_sms_api_key' => [
        'label' => 'Kavenegar SMS API key',
        'type' => 'password',
        'group' => 'Kavenegar (SMS notifications)',
        'help' => 'A separate Kavenegar account/token used to send task notification SMS (distinct from the login-code API key above).',
    ],
    'kavenegar_sms_sender' => [
        'label' => 'Kavenegar sender line number',
        'type' => 'text',
        'group' => 'Kavenegar (SMS notifications)',
        'help' => 'Sender line number configured in your Kavenegar panel, required by the plain Send API.',
    ],
```

Note: `vapid_public_key`/`vapid_private_key_pem` are intentionally **not** added here — they have no admin UI, they're generated automatically (Task 6).

- [ ] **Step 3: Map the new group in `public/api/settings.php`**

In `public/api/settings.php`, add a line to the `$map` array inside `settings_group_key()` (after `'Backups (S3)' => 'group_backup',`):

```php
        'Kavenegar (SMS notifications)' => 'group_kavenegar_sms',
```

- [ ] **Step 4: Syntax check**

Run: `php -l includes/Settings.php && php -l public/api/settings.php`
Expected: `No syntax errors detected` for both files.

- [ ] **Step 5: Commit**

```bash
git add includes/Settings.php public/api/settings.php
git commit -m "Add Kavenegar SMS notification key and VAPID key storage slots"
```

---

### Task 3: i18n keys for the whole feature

**Files:**
- Modify: `includes/i18n.php` (add a new block to both the `en` and `fa` arrays inside `TRANSLATIONS`)
- Modify: `public/assets/js/i18n.js` (add the matching block to both `en` and `fa` inside `I18N`)

**Interfaces:**
- Produces: every `t('...')` key used by Tasks 2 and 12–17. Adding them all up front means no later task needs to touch these two files again.

- [ ] **Step 1: Add keys to `includes/i18n.php`**

Add this block inside `TRANSLATIONS['en']` (anywhere after the `'nav_settings' => 'Settings',` line):

```php
        // Tasks
        'nav_tasks' => 'Tasks',
        'tasks_title' => 'Tasks · Product Manager',
        'tasks_heading' => 'Tasks',
        'add_task' => 'Add task',
        'edit_task' => 'Edit task',
        'task_title_label' => 'Title',
        'task_description_label' => 'Description',
        'task_due_date' => 'Due date',
        'task_priority' => 'Priority',
        'task_priority_low' => 'Low',
        'task_priority_medium' => 'Medium',
        'task_priority_high' => 'High',
        'task_status_todo' => 'To do',
        'task_status_in_progress' => 'In progress',
        'task_status_done' => 'Done',
        'task_assignees_label' => 'Assignees',
        'task_comments' => 'Comments',
        'task_comment_placeholder' => 'Write a comment...',
        'task_send_comment' => 'Send',
        'task_send_sms' => 'Send SMS',
        'task_send_push' => 'Send push',
        'task_notify_sent' => 'Notification sent',
        'task_notify_failed' => 'Some notifications failed',
        'task_no_tasks' => 'No tasks yet',
        'task_enable_push' => 'Enable push notifications',
        'task_push_enabled' => 'Push notifications enabled',
        'task_push_unsupported' => 'This browser does not support push notifications',
        'task_notify_prefix' => 'New task',
        'task_notify_push_title' => 'New task assigned',

        // Kavenegar SMS notifications settings group
        'group_kavenegar_sms' => 'Kavenegar (SMS notifications)',
        'kavenegar_sms_api_key_label' => 'Kavenegar SMS API key',
        'kavenegar_sms_api_key_help' => 'A separate Kavenegar account/token used to send task notification SMS (distinct from the login-code API key above).',
        'kavenegar_sms_sender_label' => 'Kavenegar sender line number',
        'kavenegar_sms_sender_help' => 'Sender line number configured in your Kavenegar panel, required by the plain Send API.',

        // Activity log messages
        'log_task_created' => 'Task "%s" created',
        'log_task_notify' => 'Notified %s assignee(s) for task "%s"',
```

Add this block inside `TRANSLATIONS['fa']` (mirroring the same keys, same position):

```php
        // Tasks
        'nav_tasks' => 'وظایف',
        'tasks_title' => 'وظایف · مدیریت محصولات',
        'tasks_heading' => 'وظایف',
        'add_task' => 'افزودن وظیفه',
        'edit_task' => 'ویرایش وظیفه',
        'task_title_label' => 'عنوان',
        'task_description_label' => 'توضیحات',
        'task_due_date' => 'تاریخ سررسید',
        'task_priority' => 'اولویت',
        'task_priority_low' => 'کم',
        'task_priority_medium' => 'متوسط',
        'task_priority_high' => 'بالا',
        'task_status_todo' => 'انجام‌نشده',
        'task_status_in_progress' => 'در حال انجام',
        'task_status_done' => 'انجام‌شده',
        'task_assignees_label' => 'مسئولان',
        'task_comments' => 'نظرات',
        'task_comment_placeholder' => 'نظر خود را بنویسید...',
        'task_send_comment' => 'ارسال',
        'task_send_sms' => 'ارسال پیامک',
        'task_send_push' => 'ارسال اعلان',
        'task_notify_sent' => 'اعلان ارسال شد',
        'task_notify_failed' => 'ارسال برخی اعلان‌ها ناموفق بود',
        'task_no_tasks' => 'هنوز وظیفه‌ای ثبت نشده است',
        'task_enable_push' => 'فعال‌سازی اعلان‌های مرورگر',
        'task_push_enabled' => 'اعلان‌های مرورگر فعال شد',
        'task_push_unsupported' => 'این مرورگر از اعلان‌های وب پشتیبانی نمی‌کند',
        'task_notify_prefix' => 'وظیفه جدید',
        'task_notify_push_title' => 'وظیفه جدید به شما محول شد',

        // Kavenegar SMS notifications settings group
        'group_kavenegar_sms' => 'کاوه‌نگار (پیامک اعلان‌ها)',
        'kavenegar_sms_api_key_label' => 'کلید API پیامک کاوه‌نگار',
        'kavenegar_sms_api_key_help' => 'یک حساب/توکن جداگانه کاوه‌نگار برای ارسال پیامک اعلان وظایف (متفاوت از کلید API کد ورود بالا).',
        'kavenegar_sms_sender_label' => 'شماره خط فرستنده کاوه‌نگار',
        'kavenegar_sms_sender_help' => 'شماره خط فرستنده تنظیم‌شده در پنل کاوه‌نگار، مورد نیاز سرویس ارسال ساده.',

        // Activity log messages
        'log_task_created' => 'وظیفه «%s» ایجاد شد',
        'log_task_notify' => '%s مسئول برای وظیفه «%s» مطلع شدند',
```

- [ ] **Step 2: Add the same keys to `public/assets/js/i18n.js`**

Add this block inside `I18N.en` (anywhere convenient, e.g. after `"nav_settings"` if present, or after `"nav_logout"`):

```js
        "nav_tasks": "Tasks",
        "tasks_heading": "Tasks",
        "add_task": "Add task",
        "edit_task": "Edit task",
        "task_title_label": "Title",
        "task_description_label": "Description",
        "task_due_date": "Due date",
        "task_priority": "Priority",
        "task_priority_low": "Low",
        "task_priority_medium": "Medium",
        "task_priority_high": "High",
        "task_status_todo": "To do",
        "task_status_in_progress": "In progress",
        "task_status_done": "Done",
        "task_assignees_label": "Assignees",
        "task_comments": "Comments",
        "task_comment_placeholder": "Write a comment...",
        "task_send_comment": "Send",
        "task_send_sms": "Send SMS",
        "task_send_push": "Send push",
        "task_notify_sent": "Notification sent",
        "task_notify_failed": "Some notifications failed",
        "task_no_tasks": "No tasks yet",
        "task_enable_push": "Enable push notifications",
        "task_push_enabled": "Push notifications enabled",
        "task_push_unsupported": "This browser does not support push notifications",
```

Add the matching Persian block inside `I18N.fa` (same keys, same position):

```js
        "nav_tasks": "وظایف",
        "tasks_heading": "وظایف",
        "add_task": "افزودن وظیفه",
        "edit_task": "ویرایش وظیفه",
        "task_title_label": "عنوان",
        "task_description_label": "توضیحات",
        "task_due_date": "تاریخ سررسید",
        "task_priority": "اولویت",
        "task_priority_low": "کم",
        "task_priority_medium": "متوسط",
        "task_priority_high": "بالا",
        "task_status_todo": "انجام‌نشده",
        "task_status_in_progress": "در حال انجام",
        "task_status_done": "انجام‌شده",
        "task_assignees_label": "مسئولان",
        "task_comments": "نظرات",
        "task_comment_placeholder": "نظر خود را بنویسید...",
        "task_send_comment": "ارسال",
        "task_send_sms": "ارسال پیامک",
        "task_send_push": "ارسال اعلان",
        "task_notify_sent": "اعلان ارسال شد",
        "task_notify_failed": "ارسال برخی اعلان‌ها ناموفق بود",
        "task_no_tasks": "هنوز وظیفه‌ای ثبت نشده است",
        "task_enable_push": "فعال‌سازی اعلان‌های مرورگر",
        "task_push_enabled": "اعلان‌های مرورگر فعال شد",
        "task_push_unsupported": "این مرورگر از اعلان‌های وب پشتیبانی نمی‌کند",
```

- [ ] **Step 3: Syntax check**

Run: `php -l includes/i18n.php && node --check public/assets/js/i18n.js`
Expected: `No syntax errors detected` / no output from `node --check` (silence = success).

- [ ] **Step 4: Commit**

```bash
git add includes/i18n.php public/assets/js/i18n.js
git commit -m "Add i18n keys for task management feature"
```

---

### Task 4: `includes/Tasks.php` — data access layer

**Files:**
- Create: `includes/Tasks.php`

**Interfaces:**
- Consumes: `Database::get()` (from `includes/Database.php`).
- Produces (used by Tasks 7–12, 15–16):
  - `const TASK_PRIORITIES = ['low', 'medium', 'high']`
  - `const TASK_STATUSES = ['todo', 'in_progress', 'done']`
  - `list_tasks_for_site(array $user, int $siteId): array`
  - `find_task(int $id): ?array` (raw row, no `assignees` key)
  - `get_task_assignees(int $taskId): array` (rows: `id`, `name`, `phone`)
  - `user_is_task_assignee(int $taskId, int $userId): bool`
  - `set_task_assignees(int $taskId, array $userIds): void`
  - `create_task(array $user, int $siteId, array $data): array` (formatted task)
  - `update_task_fields(int $id, array $fields): array` (formatted task)
  - `get_task_with_assignees(int $id): ?array` (formatted task or null)
  - `format_task(array $task): array` (shape: `id`, `site_id`, `title`, `description`, `due_date`, `priority`, `status`, `created_by`, `created_at`, `updated_at`, `assignees` (array of `{id, name, phone}`))
  - `list_task_comments(int $taskId): array` (shape: `id`, `task_id`, `user_id`, `user_name`, `body`, `created_at`)
  - `add_task_comment(int $taskId, array $user, string $body): array` (same shape as one comment above)

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/Database.php';

/**
 * Task management: creation, assignment, comments. Site-scoped like
 * products/activity logs.
 */

const TASK_PRIORITIES = ['low', 'medium', 'high'];
const TASK_STATUSES = ['todo', 'in_progress', 'done'];

/**
 * Lists tasks for the given site, newest first. shop_managers only see
 * tasks they're assigned to; superadmin/admin see every task on the site.
 */
function list_tasks_for_site(array $user, int $siteId): array
{
    $pdo = Database::get();

    if ($user['role'] === 'shop_manager') {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT t.* FROM tasks t
             INNER JOIN task_assignees ta ON ta.task_id = t.id
             WHERE t.site_id = ? AND ta.user_id = ?
             ORDER BY t.id DESC'
        );
        $stmt->execute([$siteId, $user['id']]);
    } else {
        $stmt = $pdo->prepare('SELECT * FROM tasks WHERE site_id = ? ORDER BY id DESC');
        $stmt->execute([$siteId]);
    }

    return array_map('format_task', $stmt->fetchAll());
}

function find_task(int $id): ?array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM tasks WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function get_task_assignees(int $taskId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'SELECT u.id, u.name, u.phone FROM task_assignees ta
         INNER JOIN users u ON u.id = ta.user_id
         WHERE ta.task_id = ?
         ORDER BY u.name ASC'
    );
    $stmt->execute([$taskId]);
    return $stmt->fetchAll();
}

function user_is_task_assignee(int $taskId, int $userId): bool
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT 1 FROM task_assignees WHERE task_id = ? AND user_id = ?');
    $stmt->execute([$taskId, $userId]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Replaces the full set of assignees for a task.
 */
function set_task_assignees(int $taskId, array $userIds): void
{
    $pdo = Database::get();
    $pdo->beginTransaction();

    $delete = $pdo->prepare('DELETE FROM task_assignees WHERE task_id = ?');
    $delete->execute([$taskId]);

    $insert = $pdo->prepare('INSERT INTO task_assignees (task_id, user_id) VALUES (?, ?)');
    foreach (array_unique(array_map('intval', $userIds)) as $userId) {
        if ($userId > 0) {
            $insert->execute([$taskId, $userId]);
        }
    }

    $pdo->commit();
}

function create_task(array $user, int $siteId, array $data): array
{
    $pdo = Database::get();
    $now = time();

    $priority = in_array($data['priority'] ?? '', TASK_PRIORITIES, true) ? $data['priority'] : 'medium';
    $dueDate = !empty($data['due_date']) ? (string) $data['due_date'] : null;

    $stmt = $pdo->prepare(
        "INSERT INTO tasks (site_id, title, description, due_date, priority, status, created_by, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, 'todo', ?, ?, ?)"
    );
    $stmt->execute([
        $siteId,
        (string) $data['title'],
        (string) ($data['description'] ?? ''),
        $dueDate,
        $priority,
        (int) $user['id'],
        $now,
        $now,
    ]);

    $taskId = (int) $pdo->lastInsertId();

    if (!empty($data['assignee_ids']) && is_array($data['assignee_ids'])) {
        set_task_assignees($taskId, $data['assignee_ids']);
    }

    return get_task_with_assignees($taskId);
}

/**
 * Updates the given fields on a task. Only keys present in $fields are
 * touched. 'assignee_ids' (if present) replaces the assignee set via
 * set_task_assignees() rather than a column update.
 */
function update_task_fields(int $id, array $fields): array
{
    $columns = [];
    $params = [];

    $map = ['title' => 'title', 'description' => 'description', 'due_date' => 'due_date', 'priority' => 'priority', 'status' => 'status'];
    foreach ($map as $key => $column) {
        if (array_key_exists($key, $fields)) {
            $columns[] = "{$column} = ?";
            $params[] = $fields[$key];
        }
    }

    if (!empty($columns)) {
        $columns[] = 'updated_at = ?';
        $params[] = time();
        $params[] = $id;

        $pdo = Database::get();
        $stmt = $pdo->prepare('UPDATE tasks SET ' . implode(', ', $columns) . ' WHERE id = ?');
        $stmt->execute($params);
    }

    if (array_key_exists('assignee_ids', $fields) && is_array($fields['assignee_ids'])) {
        set_task_assignees($id, $fields['assignee_ids']);
    }

    return get_task_with_assignees($id);
}

function get_task_with_assignees(int $id): ?array
{
    $task = find_task($id);
    return $task !== null ? format_task($task) : null;
}

function format_task(array $task): array
{
    $assignees = get_task_assignees((int) $task['id']);

    return [
        'id' => (int) $task['id'],
        'site_id' => (int) $task['site_id'],
        'title' => $task['title'],
        'description' => $task['description'] ?? '',
        'due_date' => $task['due_date'],
        'priority' => $task['priority'],
        'status' => $task['status'],
        'created_by' => (int) $task['created_by'],
        'created_at' => (int) $task['created_at'],
        'updated_at' => (int) $task['updated_at'],
        'assignees' => array_map(
            fn($u) => ['id' => (int) $u['id'], 'name' => $u['name'], 'phone' => $u['phone']],
            $assignees
        ),
    ];
}

function list_task_comments(int $taskId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'SELECT c.*, u.name AS user_name FROM task_comments c
         INNER JOIN users u ON u.id = c.user_id
         WHERE c.task_id = ?
         ORDER BY c.id ASC'
    );
    $stmt->execute([$taskId]);

    return array_map(fn($row) => [
        'id' => (int) $row['id'],
        'task_id' => (int) $row['task_id'],
        'user_id' => (int) $row['user_id'],
        'user_name' => $row['user_name'],
        'body' => $row['body'],
        'created_at' => (int) $row['created_at'],
    ], $stmt->fetchAll());
}

function add_task_comment(int $taskId, array $user, string $body): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('INSERT INTO task_comments (task_id, user_id, body, created_at) VALUES (?, ?, ?, ?)');
    $now = time();
    $stmt->execute([$taskId, (int) $user['id'], $body, $now]);

    return [
        'id' => (int) $pdo->lastInsertId(),
        'task_id' => $taskId,
        'user_id' => (int) $user['id'],
        'user_name' => $user['name'],
        'body' => $body,
        'created_at' => $now,
    ];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/Tasks.php`
Expected: `No syntax errors detected in includes/Tasks.php`

- [ ] **Step 3: Commit**

```bash
git add includes/Tasks.php
git commit -m "Add Tasks.php data access layer"
```

---

### Task 5: `includes/Notifications.php` — Kavenegar plain SMS sender

**Files:**
- Create: `includes/Notifications.php`

**Interfaces:**
- Consumes: `get_setting()` (from `includes/Settings.php`).
- Produces: `send_kavenegar_sms(string $phone, string $message): array` — returns `['ok' => bool, 'error' => ?string]`, same shape as the existing `send_kavenegar_otp()` in `includes/auth.php:256`.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/Settings.php';

/**
 * Sends a plain (non-OTP) SMS via Kavenegar's Send API, using a separate
 * API key/sender line from the OTP flow in auth.php (send_kavenegar_otp()).
 */
function send_kavenegar_sms(string $phone, string $message): array
{
    $apiKey = (string) get_setting('kavenegar_sms_api_key');
    $sender = (string) get_setting('kavenegar_sms_sender');

    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Kavenegar SMS API key is not configured.'];
    }

    $url = sprintf('https://api.kavenegar.com/v1/%s/sms/send.json', rawurlencode($apiKey));

    $params = [
        'receptor' => $phone,
        'message'  => $message,
    ];
    if ($sender !== '') {
        $params['sender'] = $sender;
    }

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'SMS provider error: ' . $curlError];
    }

    $data = json_decode($response, true);
    $status = $data['return']['status'] ?? 0;

    if ($httpCode !== 200 || $status !== 200) {
        $message = $data['return']['message'] ?? 'Unknown error from SMS provider.';
        return ['ok' => false, 'error' => $message];
    }

    return ['ok' => true];
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/Notifications.php`
Expected: `No syntax errors detected in includes/Notifications.php`

- [ ] **Step 3: Commit**

```bash
git add includes/Notifications.php
git commit -m "Add Kavenegar plain SMS sender for task notifications"
```

---

### Task 6: `includes/WebPush.php` — VAPID keys, RFC 8291 encryption, send, subscriptions

This is the most technically risky task in the plan (hand-written crypto, no library). Follow it exactly and do not skip Step 5's verification — it's the one place in this whole feature where a real automated correctness check is possible.

**Files:**
- Create: `includes/WebPush.php`

**Interfaces:**
- Consumes: `get_setting()`/`update_settings()` (`includes/Settings.php`), `Database::get()` (`includes/Database.php`).
- Produces (used by Tasks 11–12):
  - `get_vapid_public_key(): string`
  - `send_web_push(array $subscription, array $payload): array` — `$subscription` has keys `endpoint`, `p256dh`, `auth` (all from the `push_subscriptions` table row); `$payload` is a JSON-encodable array (e.g. `['title' => ..., 'body' => ..., 'url' => ...]`). Returns `['ok' => bool, 'error' => ?string, 'gone' => bool]` — `gone` is true on a 404/410 (caller should delete the subscription).
  - `add_push_subscription(int $userId, string $endpoint, string $p256dh, string $auth): void`
  - `remove_push_subscription(string $endpoint): void`
  - `get_push_subscriptions_for_user(int $userId): array` (raw `push_subscriptions` rows)

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';

/**
 * Standard browser Web Push (RFC 8291 message encryption + RFC 8292 VAPID),
 * implemented with PHP's built-in openssl/hash extensions only — there's no
 * Composer in this project, so a library like minishlink/web-push-php isn't
 * installable.
 */

function webpush_b64url_encode(string $data): string
{
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function webpush_b64url_decode(string $data): string
{
    $padded = str_pad($data, strlen($data) + (4 - strlen($data) % 4) % 4, '=');
    return base64_decode(strtr($padded, '-_', '+/'));
}

/**
 * Builds an openssl public key resource for a raw uncompressed P-256 point
 * (0x04 || X(32) || Y(32), 65 bytes) — the format browsers send as the
 * subscription's `p256dh` key. Wraps it in the fixed SubjectPublicKeyInfo
 * DER prefix for id-ecPublicKey + prime256v1.
 */
function webpush_ec_public_key_resource(string $rawPoint)
{
    $prefix = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200');
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($prefix . $rawPoint), 64, "\n") . "-----END PUBLIC KEY-----\n";
    return openssl_pkey_get_public($pem);
}

/**
 * Returns [publicKeyB64Url, privateKeyPem], generating and persisting a
 * VAPID EC keypair the first time this is called. The private key is
 * stored as a full PEM (from openssl_pkey_export) rather than a raw
 * scalar, so it can be reloaded with openssl_pkey_get_private() directly
 * with no hand-rolled ASN.1 on the private-key side.
 */
function webpush_vapid_keypair(): array
{
    $publicKey = (string) get_setting('vapid_public_key');
    $privatePem = (string) get_setting('vapid_private_key_pem');

    if ($publicKey !== '' && $privatePem !== '') {
        return [$publicKey, $privatePem];
    }

    $res = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($res === false) {
        throw new RuntimeException('Failed to generate VAPID keypair.');
    }

    $details = openssl_pkey_get_details($res);
    $publicKey = webpush_b64url_encode("\x04" . $details['ec']['x'] . $details['ec']['y']);

    openssl_pkey_export($res, $privatePem);

    update_settings(['vapid_public_key' => $publicKey, 'vapid_private_key_pem' => $privatePem]);

    return [$publicKey, $privatePem];
}

function get_vapid_public_key(): string
{
    [$publicKey, ] = webpush_vapid_keypair();
    return $publicKey;
}

/**
 * Converts an ASN.1 DER ECDSA-Sig-Value (SEQUENCE of two INTEGERs) into
 * the raw r||s format (64 bytes) required by JOSE/JWS ES256 signatures.
 */
function webpush_der_int_to_fixed(string $bytes, int $size = 32): string
{
    if (strlen($bytes) > $size && ord($bytes[0]) === 0) {
        $bytes = substr($bytes, 1);
    }
    return str_pad($bytes, $size, "\x00", STR_PAD_LEFT);
}

function webpush_der_to_raw_signature(string $der): string
{
    $offset = 3; // SEQUENCE tag+length (short-form) + INTEGER tag for r
    $rLen = ord($der[$offset]);
    $offset += 1;
    $r = substr($der, $offset, $rLen);
    $offset += $rLen;

    $offset += 1; // INTEGER tag for s
    $sLen = ord($der[$offset]);
    $offset += 1;
    $s = substr($der, $offset, $sLen);

    return webpush_der_int_to_fixed($r) . webpush_der_int_to_fixed($s);
}

/**
 * Builds a VAPID JWT (ES256) for the given push service origin.
 */
function webpush_vapid_jwt(string $audience, string $privatePem): string
{
    $header = webpush_b64url_encode(json_encode(['typ' => 'JWT', 'alg' => 'ES256'], JSON_UNESCAPED_SLASHES));
    $payload = webpush_b64url_encode(json_encode([
        'aud' => $audience,
        'exp' => time() + 12 * 3600,
        'sub' => 'mailto:admin@' . parse_url($audience, PHP_URL_HOST),
    ], JSON_UNESCAPED_SLASHES));

    $signingInput = $header . '.' . $payload;

    $privRes = openssl_pkey_get_private($privatePem);
    openssl_sign($signingInput, $derSignature, $privRes, OPENSSL_ALGO_SHA256);

    return $signingInput . '.' . webpush_b64url_encode(webpush_der_to_raw_signature($derSignature));
}

/**
 * Derives the content-encryption key and nonce per RFC 8291 section 3.4,
 * given the ECDH shared secret, the subscription's auth secret, this
 * message's random salt, and both parties' raw public key points.
 *
 * @return array{0: string, 1: string} [cek (16 bytes), nonce (12 bytes)]
 */
function webpush_derive_content_encryption_key(string $ecdhSecret, string $authSecret, string $salt, string $uaPublic, string $asPublic): array
{
    $keyInfo = "WebPush: info\x00" . $uaPublic . $asPublic;
    $ikm = hash_hkdf('sha256', $ecdhSecret, 32, $keyInfo, $authSecret);

    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\x00", $salt);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\x00", $salt);

    return [$cek, $nonce];
}

/**
 * Sends one Web Push message to one subscription. Returns
 * ['ok' => bool, 'error' => ?string, 'gone' => bool] — 'gone' is true on a
 * 404/410 from the push service, meaning the caller should delete the
 * subscription (device unsubscribed or expired).
 */
function send_web_push(array $subscription, array $payload): array
{
    [$vapidPublic, $vapidPrivatePem] = webpush_vapid_keypair();

    $endpoint = $subscription['endpoint'];
    $uaPublic = webpush_b64url_decode($subscription['p256dh']);
    $authSecret = webpush_b64url_decode($subscription['auth']);

    $asRes = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if ($asRes === false) {
        return ['ok' => false, 'error' => 'Failed to generate ephemeral key.', 'gone' => false];
    }
    $asDetails = openssl_pkey_get_details($asRes);
    $asPublic = "\x04" . $asDetails['ec']['x'] . $asDetails['ec']['y'];

    $uaPubRes = webpush_ec_public_key_resource($uaPublic);
    $sharedSecret = openssl_pkey_derive($uaPubRes, $asRes, 32);
    if ($sharedSecret === false) {
        return ['ok' => false, 'error' => 'ECDH key derivation failed.', 'gone' => false];
    }

    $salt = random_bytes(16);
    [$cek, $nonce] = webpush_derive_content_encryption_key($sharedSecret, $authSecret, $salt, $uaPublic, $asPublic);

    $plaintext = json_encode($payload, JSON_UNESCAPED_UNICODE) . "\x02";
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
    if ($ciphertext === false) {
        return ['ok' => false, 'error' => 'Payload encryption failed.', 'gone' => false];
    }

    $recordSize = 4096;
    $body = $salt . pack('N', $recordSize) . chr(strlen($asPublic)) . $asPublic . $ciphertext . $tag;

    $audience = parse_url($endpoint, PHP_URL_SCHEME) . '://' . parse_url($endpoint, PHP_URL_HOST);
    $jwt = webpush_vapid_jwt($audience, $vapidPrivatePem);

    $ch = curl_init($endpoint);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/octet-stream',
        'Content-Encoding: aes128gcm',
        'TTL: 60',
        'Authorization: vapid t=' . $jwt . ', k=' . $vapidPublic,
    ]);
    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode === 404 || $httpCode === 410) {
        return ['ok' => false, 'error' => 'Subscription expired.', 'gone' => true];
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        return ['ok' => false, 'error' => $curlError !== '' ? $curlError : "Push service returned HTTP {$httpCode}.", 'gone' => false];
    }

    return ['ok' => true, 'error' => null, 'gone' => false];
}

function add_push_subscription(int $userId, string $endpoint, string $p256dh, string $auth): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, created_at) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth)'
    );
    $stmt->execute([$userId, $endpoint, $p256dh, $auth, time()]);
}

function remove_push_subscription(string $endpoint): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?');
    $stmt->execute([$endpoint]);
}

function get_push_subscriptions_for_user(int $userId): array
{
    $pdo = Database::get();
    $stmt = $pdo->prepare('SELECT * FROM push_subscriptions WHERE user_id = ?');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}
```

- [ ] **Step 2: Syntax check**

Run: `php -l includes/WebPush.php`
Expected: `No syntax errors detected in includes/WebPush.php`

- [ ] **Step 3: Commit the module**

```bash
git add includes/WebPush.php
git commit -m "Add WebPush.php: VAPID keys, RFC 8291 encryption, send, subscriptions"
```

- [ ] **Step 4: Write the one-off crypto self-check**

This validates the shared HKDF/AES-GCM pipeline (`webpush_derive_content_encryption_key` + the aes128gcm envelope assembly in `send_web_push`) against the **exact worked example published in RFC 8291 Appendix A** — the single highest-value correctness check in this whole feature, since a subtly wrong HKDF info string or envelope byte order would otherwise fail silently (real push services just return an opaque 400/401, with no way to tell "your crypto is wrong" from "your JWT is wrong").

Save this to the scratchpad (not the repo — this project deliberately has no test suite, per `CLAUDE.md`) as `webpush_selfcheck.php`:

```php
<?php
// One-off verification against RFC 8291 Appendix A. Not part of the app —
// run once during implementation, then discard.

require_once 'includes/WebPush.php';

function b64u(string $s): string
{
    return webpush_b64url_decode($s);
}

// SEC1 EC PRIVATE KEY DER builder, used only here to import the RFC's
// fixed application-server private key for this test (production code
// never needs this — real VAPID/ephemeral keys come from openssl_pkey_new
// and are exported/reloaded as PEM, see webpush_vapid_keypair()).
function test_ec_private_key_resource(string $privateRaw, string $publicRaw)
{
    $der = "\x30\x77\x02\x01\x01\x04\x20" . $privateRaw
        . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
        . "\xa1\x44\x03\x42\x00" . $publicRaw;
    $pem = "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    return openssl_pkey_get_private($pem);
}

$asPrivateRaw = b64u('yfWPiYE-n46HLnH0KqZOF1fJJU3MYrct3AELtAQ-oRw');
$asPublicRaw  = b64u('BP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A8');
$uaPublicRaw  = b64u('BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4');
$authSecret   = b64u('BTBZMqHH6r4Tts7J_aSIgg');
$salt         = b64u('DGv6ra1nlYgDCS1FRnbzlw');
$plaintext    = 'When I grow up, I want to be a watermelon';
$expected     = 'DGv6ra1nlYgDCS1FRnbzlwAAEABBBP4z9KsN6nGRTbVYI_c7VJSPQTBtkgcy27mlmlMoZIIgDll6e3vCYLocInmYWAmS6TlzAC8wEqKK6PBru3jl7A_yl95bQpu6cVPTpK4Mqgkf1CXztLVBSt2Ks3oZwbuwXPXLWyouBWLVWGNWQexSgSxsj_Qulcy4a-fN';

$asPrivRes = test_ec_private_key_resource($asPrivateRaw, $asPublicRaw);
$uaPubRes = webpush_ec_public_key_resource($uaPublicRaw);

$shared = openssl_pkey_derive($uaPubRes, $asPrivRes, 32);
if ($shared === false) {
    fwrite(STDERR, "FAIL: ECDH derivation failed\n");
    exit(1);
}

[$cek, $nonce] = webpush_derive_content_encryption_key($shared, $authSecret, $salt, $uaPublicRaw, $asPublicRaw);

$paddedPlaintext = $plaintext . "\x02";
$tag = '';
$ciphertext = openssl_encrypt($paddedPlaintext, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);

$body = $salt . pack('N', 4096) . chr(strlen($asPublicRaw)) . $asPublicRaw . $ciphertext . $tag;
$actual = webpush_b64url_encode($body);

if ($actual === $expected) {
    echo "PASS: aes128gcm output matches RFC 8291 Appendix A test vector\n";
    exit(0);
}

echo "FAIL\nExpected: {$expected}\nActual:   {$actual}\n";
exit(1);
```

- [ ] **Step 5: Run the self-check from the project root**

Run: `php C:\Users\hamid\AppData\Local\Temp\claude\C--Users-hamid-Documents-Development-woo-managment\4f207979-e42e-4585-90ed-ba9f00621038\scratchpad\webpush_selfcheck.php`
(adjust the path/working directory so the `require_once 'includes/WebPush.php';` line resolves — run it with the project root as the current directory, or change that line to an absolute path.)

Expected: `PASS: aes128gcm output matches RFC 8291 Appendix A test vector`

If it prints `FAIL`, do not proceed to Task 11/12 — the bug is in `webpush_derive_content_encryption_key()`, `webpush_ec_public_key_resource()`, or the envelope assembly in `send_web_push()` (the same envelope-building line is duplicated in this script), and every push notification sent afterward would silently fail. Delete `webpush_selfcheck.php` once it passes — it's not part of the app.

---

### Task 7: `public/api/site-users.php` — assignable users for the current site

**Files:**
- Create: `public/api/site-users.php`

**Interfaces:**
- Consumes: `require_login_api()`, `require_site_api()` (`includes/auth.php`, `includes/site_context.php`), `list_users()` (`includes/Users.php`).
- Produces: `GET /api/site-users.php` → `{"items": [{"id", "name", "phone", "role"}, ...]}`, restricted to users assigned to the current site, for superadmin/admin only (403 for shop_manager — they don't assign tasks).

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Users.php';

$user = require_login_api();
$site = require_site_api($user);

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$siteId = (int) $site['id'];
$users = array_values(array_filter(list_users(), fn($u) => in_array($siteId, $u['site_ids'], true)));

json_response(['items' => array_map(fn($u) => [
    'id' => (int) $u['id'],
    'name' => $u['name'],
    'phone' => $u['phone'],
    'role' => $u['role'],
], $users)]);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/site-users.php`
Expected: `No syntax errors detected in public/api/site-users.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/site-users.php
git commit -m "Add site-users API endpoint for the task assignee picker"
```

---

### Task 8: `public/api/tasks.php` — list + create

**Files:**
- Create: `public/api/tasks.php`

**Interfaces:**
- Consumes: `require_login_api()`, `require_site_api()`, `verify_csrf_api()`, `list_tasks_for_site()`, `create_task()` (`includes/Tasks.php`), `log_activity()` (`includes/ActivityLog.php`).
- Produces: `GET /api/tasks.php` → `{"items": [<formatted task>, ...]}`; `POST /api/tasks.php` (superadmin/admin only, body: `title` (required), `description?`, `due_date?`, `priority?`, `assignee_ids?`) → `{"item": <formatted task>}`, 201.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['items' => list_tasks_for_site($user, (int) $site['id'])]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

$body = json_body();
$title = trim((string) ($body['title'] ?? ''));

if ($title === '') {
    json_response(['error' => 'Title is required'], 422);
}

$body['title'] = $title;
$task = create_task($user, (int) $site['id'], $body);

log_activity($user, (int) $site['id'], 'task', 'task_create', 'log_task_created', [$title]);

json_response(['item' => $task], 201);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/tasks.php`
Expected: `No syntax errors detected in public/api/tasks.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/tasks.php
git commit -m "Add tasks list/create API endpoint"
```

---

### Task 9: `public/api/task-update.php` — field-gated update (incl. status)

**Files:**
- Create: `public/api/task-update.php`

**Interfaces:**
- Consumes: `require_login_api()`, `require_site_api()`, `verify_csrf_api()`, `find_task()`, `user_is_task_assignee()`, `update_task_fields()`, `TASK_PRIORITIES`, `TASK_STATUSES` (`includes/Tasks.php`).
- Produces: `POST /api/task-update.php` (body: `id` (required) + any of `title`/`description`/`due_date`/`priority`/`assignee_ids`/`status`) → `{"item": <formatted task>}`. superadmin/admin may change any field; shop_manager may only send `status` (and only if they're an assignee) — any other field in the body from a shop_manager is a 403.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$id = (int) ($body['id'] ?? 0);
$task = find_task($id);

if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

$isManager = in_array($user['role'], ['superadmin', 'admin'], true);

if (!$isManager) {
    if (!user_is_task_assignee($id, (int) $user['id'])) {
        json_response(['error' => 'Not allowed'], 403);
    }
    foreach (array_keys($body) as $key) {
        if ($key !== 'id' && $key !== 'status') {
            json_response(['error' => 'You can only change the status of this task'], 403);
        }
    }
}

$fields = [];

if ($isManager && isset($body['title'])) {
    $fields['title'] = trim((string) $body['title']);
}
if ($isManager && isset($body['description'])) {
    $fields['description'] = (string) $body['description'];
}
if ($isManager && isset($body['due_date'])) {
    $fields['due_date'] = $body['due_date'] !== '' ? (string) $body['due_date'] : null;
}
if ($isManager && isset($body['priority'])) {
    if (!in_array($body['priority'], TASK_PRIORITIES, true)) {
        json_response(['error' => 'Invalid priority'], 422);
    }
    $fields['priority'] = $body['priority'];
}
if ($isManager && isset($body['assignee_ids']) && is_array($body['assignee_ids'])) {
    $fields['assignee_ids'] = $body['assignee_ids'];
}
if (isset($body['status'])) {
    if (!in_array($body['status'], TASK_STATUSES, true)) {
        json_response(['error' => 'Invalid status'], 422);
    }
    $fields['status'] = $body['status'];
}

$updated = update_task_fields($id, $fields);

json_response(['item' => $updated]);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/task-update.php`
Expected: `No syntax errors detected in public/api/task-update.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/task-update.php
git commit -m "Add task-update API endpoint with field-level role gating"
```

---

### Task 10: `public/api/task-comments.php` — list + add comments

**Files:**
- Create: `public/api/task-comments.php`

**Interfaces:**
- Consumes: `find_task()`, `user_is_task_assignee()`, `list_task_comments()`, `add_task_comment()` (`includes/Tasks.php`).
- Produces: `GET /api/task-comments.php?task_id=<id>` → `{"items": [<comment>, ...]}`; `POST` (body: `body` (required)) → `{"item": <comment>}`, 201. shop_manager may only access if they're an assignee.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';

$user = require_login_api();
$site = require_site_api($user);

$taskId = (int) ($_GET['task_id'] ?? 0);
$task = find_task($taskId);

if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

if ($user['role'] === 'shop_manager' && !user_is_task_assignee($taskId, (int) $user['id'])) {
    json_response(['error' => 'Not allowed'], 403);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['items' => list_task_comments($taskId)]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$text = trim((string) ($body['body'] ?? ''));

if ($text === '') {
    json_response(['error' => 'Comment cannot be empty'], 422);
}

$comment = add_task_comment($taskId, $user, $text);
json_response(['item' => $comment], 201);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/task-comments.php`
Expected: `No syntax errors detected in public/api/task-comments.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/task-comments.php
git commit -m "Add task-comments API endpoint"
```

---

### Task 11: `public/api/push-subscribe.php` — subscribe/unsubscribe + public key

**Files:**
- Create: `public/api/push-subscribe.php`

**Interfaces:**
- Consumes: `get_vapid_public_key()`, `add_push_subscription()`, `remove_push_subscription()` (`includes/WebPush.php`).
- Produces: `GET /api/push-subscribe.php` → `{"public_key": "<base64url>"}` (any logged-in user); `POST` body `{"action": "subscribe", "endpoint", "keys": {"p256dh", "auth"}}` or `{"action": "unsubscribe", "endpoint"}` → `{"ok": true}`.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WebPush.php';

$user = require_login_api();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['public_key' => get_vapid_public_key()]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$action = (string) ($body['action'] ?? 'subscribe');

if ($action === 'unsubscribe') {
    remove_push_subscription((string) ($body['endpoint'] ?? ''));
    json_response(['ok' => true]);
}

$endpoint = (string) ($body['endpoint'] ?? '');
$keys = $body['keys'] ?? [];
$p256dh = (string) ($keys['p256dh'] ?? '');
$auth = (string) ($keys['auth'] ?? '');

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    json_response(['error' => 'Invalid subscription'], 422);
}

add_push_subscription((int) $user['id'], $endpoint, $p256dh, $auth);
json_response(['ok' => true]);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/push-subscribe.php`
Expected: `No syntax errors detected in public/api/push-subscribe.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/push-subscribe.php
git commit -m "Add push subscription API endpoint"
```

---

### Task 12: `public/api/task-notify.php` — trigger SMS/push send

**Files:**
- Create: `public/api/task-notify.php`

**Interfaces:**
- Consumes: `find_task()`, `get_task_assignees()` (`includes/Tasks.php`), `send_kavenegar_sms()` (`includes/Notifications.php`), `send_web_push()`, `get_push_subscriptions_for_user()`, `remove_push_subscription()` (`includes/WebPush.php`), `log_activity()` (`includes/ActivityLog.php`).
- Produces: `POST /api/task-notify.php` (superadmin/admin only, body: `id`, `channel` (`"sms"` or `"push"`)) → `{"ok": true, "results": [{"user_id", "ok", "error"}, ...]}`.

- [ ] **Step 1: Write the file**

```php
<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Tasks.php';
require_once __DIR__ . '/../../includes/Notifications.php';
require_once __DIR__ . '/../../includes/WebPush.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';
require_once __DIR__ . '/../../includes/i18n.php';

$user = require_login_api();
$site = require_site_api($user);
verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

if (!in_array($user['role'], ['superadmin', 'admin'], true)) {
    json_response(['error' => 'Not allowed'], 403);
}

$body = json_body();
$taskId = (int) ($body['id'] ?? 0);
$channel = (string) ($body['channel'] ?? '');

if (!in_array($channel, ['sms', 'push'], true)) {
    json_response(['error' => 'channel must be "sms" or "push"'], 422);
}

$task = find_task($taskId);
if ($task === null || (int) $task['site_id'] !== (int) $site['id']) {
    json_response(['error' => 'Task not found'], 404);
}

$assignees = get_task_assignees($taskId);
if (empty($assignees)) {
    json_response(['error' => 'This task has no assignees'], 422);
}

$dueText = $task['due_date'] ?: '-';
$message = sprintf(
    '%s: %s (%s: %s, %s: %s)',
    t('task_notify_prefix'),
    $task['title'],
    t('task_due_date'),
    $dueText,
    t('task_priority'),
    t('task_priority_' . $task['priority'])
);

$results = [];
foreach ($assignees as $assignee) {
    if ($channel === 'sms') {
        $result = send_kavenegar_sms($assignee['phone'], $message);
        $results[] = ['user_id' => (int) $assignee['id'], 'ok' => $result['ok'], 'error' => $result['error'] ?? null];
        continue;
    }

    $subscriptions = get_push_subscriptions_for_user((int) $assignee['id']);
    if (empty($subscriptions)) {
        $results[] = ['user_id' => (int) $assignee['id'], 'ok' => false, 'error' => 'No push subscription'];
        continue;
    }

    $anyOk = false;
    foreach ($subscriptions as $subscription) {
        $result = send_web_push($subscription, [
            'title' => t('task_notify_push_title'),
            'body' => $message,
            'url' => '/tasks.php',
        ]);
        if ($result['gone']) {
            remove_push_subscription($subscription['endpoint']);
        }
        if ($result['ok']) {
            $anyOk = true;
        }
    }
    $results[] = ['user_id' => (int) $assignee['id'], 'ok' => $anyOk, 'error' => $anyOk ? null : 'Push delivery failed'];
}

$successCount = count(array_filter($results, fn($r) => $r['ok']));
log_activity(
    $user,
    (int) $site['id'],
    'task',
    $channel === 'sms' ? 'task_notify_sms' : 'task_notify_push',
    'log_task_notify',
    [(string) $successCount, $task['title']]
);

json_response(['ok' => true, 'results' => $results]);
```

- [ ] **Step 2: Syntax check**

Run: `php -l public/api/task-notify.php`
Expected: `No syntax errors detected in public/api/task-notify.php`

- [ ] **Step 3: Commit**

```bash
git add public/api/task-notify.php
git commit -m "Add task-notify API endpoint (SMS/push trigger)"
```

---

### Task 13: `public/sw.js` — push + notificationclick listeners

**Files:**
- Modify: `public/sw.js` (append after the existing `fetch` listener, currently ending at line 46)

**Interfaces:**
- Consumes: nothing new — pure browser Service Worker API.
- Produces: the service worker now displays a system notification when a push message arrives, and focuses/opens `/tasks.php` when the user clicks it.

- [ ] **Step 1: Append the listeners**

Add this to the end of `public/sw.js` (after the closing `});` of the existing `fetch` listener):

```js

self.addEventListener('push', (event) => {
  let payload = { title: 'Notification', body: '' };
  try {
    payload = event.data.json();
  } catch (e) {
    // Non-JSON push payload: fall back to the default above.
  }

  event.waitUntil(
    self.registration.showNotification(payload.title || 'Notification', {
      body: payload.body || '',
      icon: '/assets/icons/icon.svg',
      data: { url: payload.url || '/' },
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const url = (event.notification.data && event.notification.data.url) || '/';

  event.waitUntil(
    self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if (client.url.endsWith(url) && 'focus' in client) {
          return client.focus();
        }
      }
      if (self.clients.openWindow) {
        return self.clients.openWindow(url);
      }
    })
  );
});
```

- [ ] **Step 2: Syntax check**

Run: `node --check public/sw.js`
Expected: no output (silence = success).

- [ ] **Step 3: Commit**

```bash
git add public/sw.js
git commit -m "Add push notification listeners to the service worker"
```

---

### Task 14: `includes/nav.php` — add the Tasks tab

**Files:**
- Modify: `includes/nav.php:11-32` (`render_bottom_nav`), `includes/nav.php:56-96` (`render_desktop_sidebar`)

**Interfaces:**
- Consumes: `t('nav_tasks')` (added in Task 3).
- Produces: a "Tasks" link visible to every role in both nav renderers, between Products and Logs.

- [ ] **Step 1: Add the link to `render_bottom_nav`**

In `includes/nav.php`, inside `render_bottom_nav()`, add this right after the Products `<a>` block (after line 20's closing `</a>`, before the Logs `<a>`):

```php
      <a href="/tasks.php" class="flex-1 py-3 text-center text-xs font-medium <?php echo $cls('tasks'); ?>">
        <div class="text-lg leading-none mb-0.5">&#10003;</div><?php echo htmlspecialchars(t('nav_tasks')); ?>
      </a>
```

- [ ] **Step 2: Add the link to `render_desktop_sidebar`**

Inside `render_desktop_sidebar()`, add this right after the Products `<a>` block (after line 79's closing `</a>`, before the Logs `<a>`):

```php
        <a href="/tasks.php" class="flex items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium <?php echo $cls('tasks'); ?>">
          <span class="text-base leading-none">&#10003;</span><?php echo htmlspecialchars(t('nav_tasks')); ?>
        </a>
```

- [ ] **Step 3: Update the docblocks**

Both `render_bottom_nav()` (line 8) and `render_desktop_sidebar()` (line 53) have a `@param string $active One of: products, logs, settings` docblock comment — update both to `One of: products, tasks, logs, settings`.

- [ ] **Step 4: Syntax check**

Run: `php -l includes/nav.php`
Expected: `No syntax errors detected in includes/nav.php`

- [ ] **Step 5: Commit**

```bash
git add includes/nav.php
git commit -m "Add Tasks tab to the shared bottom nav and desktop sidebar"
```

---

### Task 15: `public/tasks.php` + `public/assets/js/tasks.js` — page skeleton, list, create/edit

**Files:**
- Create: `public/tasks.php`
- Create: `public/assets/js/tasks.js`

**Interfaces:**
- Consumes: `require_login_page()`/`require_site_page()` (`includes/auth.php`/`site_context.php`), `render_desktop_sidebar()`/`render_bottom_nav()`/`render_site_switcher()` (`includes/nav.php`), `render_pwa_head()`/`render_pwa_register_script()` (`includes/pwa.php`); client-side `App.api()`/`App.toast()` (`app.js`), `t()` (`i18n.js`); `GET/POST /api/tasks.php`, `GET /api/site-users.php`.
- Produces: the Tasks page — task list (role-filtered server-side by the API) + a bottom-sheet form to create/edit a task (title, description, due date, priority, assignees). `window.CURRENT_USER`/`window.CURRENT_SITE` globals, matching every other page.

- [ ] **Step 1: Write `public/tasks.php`**

```php
<?php

require_once __DIR__ . '/../includes/helpers.php';
require_once __DIR__ . '/../includes/pwa.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/site_context.php';
require_once __DIR__ . '/../includes/nav.php';

$user = require_login_page();
$site = require_site_page($user);
$isManager = in_array($user['role'], ['superadmin', 'admin'], true);
?>
<!DOCTYPE html>
<html <?php echo html_attrs(); ?>>
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
  <title><?php echo htmlspecialchars(t('tasks_title')); ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <link rel="stylesheet" href="/assets/css/app.css">
  <?php render_pwa_head(); ?>
</head>
<body class="bg-gray-50 min-h-screen has-bottom-nav has-sidebar">

  <?php render_desktop_sidebar('tasks', $user, $site); ?>

  <header class="sticky top-0 z-30 bg-white border-b border-gray-100">
    <?php render_site_switcher($site); ?>
    <div class="px-4 pt-4 pb-3 flex items-center justify-between">
      <h1 class="text-lg font-semibold text-gray-900"><?php echo htmlspecialchars(t('tasks_heading')); ?></h1>
      <button id="push-enable-btn" class="text-sm font-medium text-gray-600"><?php echo htmlspecialchars(t('task_enable_push')); ?></button>
    </div>
  </header>

  <main class="px-4 py-3">
    <div id="loading" class="text-center py-16 text-gray-400 text-sm"><?php echo htmlspecialchars(t('loading')); ?></div>
    <div id="task-list" class="hidden space-y-3"></div>
    <div id="empty-state" class="hidden text-center py-16 text-gray-400">
      <p class="text-sm"><?php echo htmlspecialchars(t('task_no_tasks')); ?></p>
    </div>
  </main>

  <?php if ($isManager): ?>
  <button id="add-fab"
     class="fixed right-4 bottom-20 z-30 flex h-14 w-14 items-center justify-center rounded-full bg-gray-900 text-white text-2xl shadow-lg active:scale-95 transition">
    +
  </button>
  <?php endif; ?>

  <?php render_bottom_nav('tasks', $user); ?>

  <!-- Task create/edit sheet -->
  <div id="task-sheet" class="hidden fixed inset-0 z-40">
    <div id="task-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[85vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="task-sheet-title" class="text-base font-semibold text-gray-900"><?php echo htmlspecialchars(t('add_task')); ?></h2>
        <button id="task-sheet-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <form id="task-form" class="space-y-4">
        <input type="hidden" id="task-id">

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_title_label')); ?></label>
          <input id="task-title" type="text" required
                 class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none">
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_description_label')); ?></label>
          <textarea id="task-description" rows="3"
                    class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm focus:border-gray-900 focus:ring-1 focus:ring-gray-900 outline-none"></textarea>
        </div>

        <div id="task-due-date-field">
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_due_date')); ?></label>
          <!-- Populated by tasks.js: native <input type=date> for en, a Jalali y/m/d select trio for fa (see Task 17). -->
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_priority')); ?></label>
          <select id="task-priority" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
            <option value="low"><?php echo htmlspecialchars(t('task_priority_low')); ?></option>
            <option value="medium" selected><?php echo htmlspecialchars(t('task_priority_medium')); ?></option>
            <option value="high"><?php echo htmlspecialchars(t('task_priority_high')); ?></option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_assignees_label')); ?></label>
          <div id="task-assignees-list" class="space-y-2 max-h-40 overflow-y-auto"></div>
        </div>

        <div class="flex gap-2 pt-2">
          <button type="submit" id="task-save-btn" class="flex-1 rounded-xl bg-gray-900 text-white font-medium py-3 text-sm"><?php echo htmlspecialchars(t('save')); ?></button>
        </div>
      </form>
    </div>
  </div>

  <script>
    window.CURRENT_USER = <?php echo json_encode(['id' => $user['id'], 'phone' => $user['phone'], 'name' => $user['name'], 'role' => $user['role']]); ?>;
    window.CURRENT_SITE = <?php echo json_encode(['id' => $site['id'], 'name' => $site['name']]); ?>;
    window.IS_TASK_MANAGER = <?php echo json_encode($isManager); ?>;
  </script>
  <script src="/assets/js/i18n.js"></script>
  <script src="/assets/js/app.js"></script>
  <script src="/assets/js/tasks.js"></script>
  <?php render_pwa_register_script(); ?>
</body>
</html>
```

- [ ] **Step 2: Write `public/assets/js/tasks.js`**

```js
/**
 * Tasks page: list, create/edit sheet. Detail/comments/notify (Task 16) and
 * push opt-in/Jalali date input (Task 17) extend this same file.
 */

let currentTasks = [];
let assignableUsers = [];

async function loadAssignableUsers() {
  if (!window.IS_TASK_MANAGER) return;
  try {
    const data = await App.api('/api/site-users.php');
    assignableUsers = data.items;
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function renderAssigneeCheckboxes(selectedIds = []) {
  const container = document.getElementById('task-assignees-list');
  container.innerHTML = '';
  assignableUsers.forEach((u) => {
    const label = document.createElement('label');
    label.className = 'flex items-center gap-2 text-sm text-gray-700';
    const checked = selectedIds.includes(u.id) ? 'checked' : '';
    label.innerHTML = `<input type="checkbox" class="task-assignee-checkbox h-4 w-4 rounded border-gray-300" value="${u.id}" ${checked}> ${u.name || u.phone}`;
    container.appendChild(label);
  });
}

function priorityBadgeClass(priority) {
  if (priority === 'high') return 'bg-red-100 text-red-700';
  if (priority === 'low') return 'bg-gray-100 text-gray-600';
  return 'bg-amber-100 text-amber-700';
}

function renderTaskCard(task) {
  const div = document.createElement('div');
  div.className = 'task-card bg-white rounded-2xl border border-gray-100 p-4 cursor-pointer';
  div.dataset.id = task.id;

  const assigneeNames = task.assignees.map((a) => a.name || a.phone).join(', ');

  div.innerHTML = `
    <div class="flex items-start justify-between gap-2">
      <h3 class="text-sm font-semibold text-gray-900">${task.title}</h3>
      <span class="text-xs px-2 py-0.5 rounded-full ${priorityBadgeClass(task.priority)}">${t('task_priority_' + task.priority)}</span>
    </div>
    <p class="text-xs text-gray-500 mt-1">${t('task_status_' + task.status)}${task.due_date ? ' · ' + task.due_date : ''}</p>
    <p class="text-xs text-gray-400 mt-1">${assigneeNames}</p>
  `;

  div.addEventListener('click', () => openTaskDetail(task.id));
  return div;
}

async function loadTasks() {
  document.getElementById('loading').classList.remove('hidden');
  document.getElementById('task-list').classList.add('hidden');
  document.getElementById('empty-state').classList.add('hidden');

  try {
    const data = await App.api('/api/tasks.php');
    currentTasks = data.items;

    const list = document.getElementById('task-list');
    list.innerHTML = '';
    currentTasks.forEach((task) => list.appendChild(renderTaskCard(task)));

    document.getElementById('loading').classList.add('hidden');
    if (currentTasks.length === 0) {
      document.getElementById('empty-state').classList.remove('hidden');
    } else {
      list.classList.remove('hidden');
    }
  } catch (e) {
    document.getElementById('loading').classList.add('hidden');
    App.toast(e.message, 'error');
  }
}

function openTaskSheet(task = null) {
  document.getElementById('task-sheet-title').textContent = task ? t('edit_task') : t('add_task');
  document.getElementById('task-id').value = task ? task.id : '';
  document.getElementById('task-title').value = task ? task.title : '';
  document.getElementById('task-description').value = task ? task.description : '';
  document.getElementById('task-priority').value = task ? task.priority : 'medium';
  renderAssigneeCheckboxes(task ? task.assignees.map((a) => a.id) : []);
  document.getElementById('task-sheet').classList.remove('hidden');
}

function closeTaskSheet() {
  document.getElementById('task-sheet').classList.add('hidden');
}

function bindEvents() {
  const addFab = document.getElementById('add-fab');
  if (addFab) {
    addFab.addEventListener('click', () => openTaskSheet());
  }

  document.getElementById('task-sheet-close').addEventListener('click', closeTaskSheet);
  document.getElementById('task-overlay')?.addEventListener('click', closeTaskSheet);

  document.getElementById('task-form').addEventListener('submit', async (event) => {
    event.preventDefault();

    const id = document.getElementById('task-id').value;
    const assigneeIds = Array.from(document.querySelectorAll('.task-assignee-checkbox:checked')).map((el) => parseInt(el.value, 10));

    const payload = {
      title: document.getElementById('task-title').value,
      description: document.getElementById('task-description').value,
      priority: document.getElementById('task-priority').value,
      assignee_ids: assigneeIds,
    };

    try {
      if (id) {
        payload.id = parseInt(id, 10);
        await App.api('/api/task-update.php', { method: 'POST', body: JSON.stringify(payload) });
      } else {
        await App.api('/api/tasks.php', { method: 'POST', body: JSON.stringify(payload) });
      }
      closeTaskSheet();
      loadTasks();
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });
}

document.addEventListener('DOMContentLoaded', () => {
  bindEvents();
  loadAssignableUsers();
  loadTasks();
});
```

- [ ] **Step 3: Syntax check**

Run: `php -l public/tasks.php && node --check public/assets/js/tasks.js`
Expected: `No syntax errors detected in public/tasks.php`, no output from `node --check`.

- [ ] **Step 4: Manual browser check**

Start the app against your local MySQL, log in as a superadmin, select a site, and go to `/tasks.php`. Confirm: the empty state shows, the "+" button opens the sheet, the assignee list is populated from `/api/site-users.php`, and submitting the form creates a task that appears in the list after closing the sheet.

- [ ] **Step 5: Commit**

```bash
git add public/tasks.php public/assets/js/tasks.js
git commit -m "Add Tasks page: list, create/edit"
```

---

### Task 16: `tasks.js` — detail panel (comments, status, notify buttons)

**Files:**
- Modify: `public/assets/js/tasks.js` (add detail-sheet functions; extend `bindEvents()`)
- Modify: `public/tasks.php` (add the detail sheet markup, after the create/edit sheet)

**Interfaces:**
- Consumes: `openTaskDetail(taskId)` becomes callable from `renderTaskCard()`'s click handler added in Task 15 (already wired). Consumes `GET/POST /api/task-comments.php`, `POST /api/task-update.php`, `POST /api/task-notify.php`.
- Produces: clicking a task card opens a detail sheet showing full task info, a status `<select>` (editable by anyone who can see the task), a comment thread + add-comment form, and (managers only) "Send SMS"/"Send push" buttons.

- [ ] **Step 1: Add the detail sheet markup to `public/tasks.php`**

Add this right after the closing `</div>` of the `task-sheet` block (before the `<script>` block):

```php
  <!-- Task detail sheet -->
  <div id="task-detail-sheet" class="hidden fixed inset-0 z-40">
    <div id="task-detail-overlay" class="absolute inset-0 bg-black/40"></div>
    <div class="absolute bottom-0 left-0 right-0 bg-white rounded-t-2xl p-4 max-h-[90vh] overflow-y-auto">
      <div class="flex items-center justify-between mb-4">
        <h2 id="task-detail-title" class="text-base font-semibold text-gray-900"></h2>
        <button id="task-detail-close" class="text-gray-400 text-xl leading-none">&times;</button>
      </div>

      <p id="task-detail-description" class="text-sm text-gray-600 mb-3"></p>
      <p id="task-detail-due" class="text-xs text-gray-500 mb-1"></p>
      <p id="task-detail-assignees" class="text-xs text-gray-500 mb-3"></p>

      <div class="mb-4">
        <label class="block text-sm font-medium text-gray-700 mb-1"><?php echo htmlspecialchars(t('task_status_todo')); ?> / <?php echo htmlspecialchars(t('task_status_done')); ?></label>
        <select id="task-detail-status" class="w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
          <option value="todo"><?php echo htmlspecialchars(t('task_status_todo')); ?></option>
          <option value="in_progress"><?php echo htmlspecialchars(t('task_status_in_progress')); ?></option>
          <option value="done"><?php echo htmlspecialchars(t('task_status_done')); ?></option>
        </select>
      </div>

      <div id="task-detail-notify" class="hidden flex gap-2 mb-4">
        <button id="task-notify-sms-btn" class="flex-1 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('task_send_sms')); ?></button>
        <button id="task-notify-push-btn" class="flex-1 rounded-xl border border-gray-300 py-2.5 text-sm font-medium text-gray-700"><?php echo htmlspecialchars(t('task_send_push')); ?></button>
      </div>

      <h3 class="text-sm font-semibold text-gray-900 mb-2"><?php echo htmlspecialchars(t('task_comments')); ?></h3>
      <div id="task-detail-comments" class="space-y-2 mb-3"></div>

      <form id="task-comment-form" class="flex gap-2">
        <input id="task-comment-input" type="text" placeholder="<?php echo htmlspecialchars(t('task_comment_placeholder')); ?>"
               class="flex-1 rounded-xl border border-gray-300 px-3 py-2.5 text-sm">
        <button type="submit" class="rounded-xl bg-gray-900 text-white px-4 py-2.5 text-sm font-medium"><?php echo htmlspecialchars(t('task_send_comment')); ?></button>
      </form>
    </div>
  </div>
```

- [ ] **Step 2: Add detail-sheet logic to `public/assets/js/tasks.js`**

Add this before the final `document.addEventListener('DOMContentLoaded', ...)` block:

```js
let currentDetailTaskId = null;

async function loadComments(taskId) {
  const container = document.getElementById('task-detail-comments');
  container.innerHTML = '';
  const data = await App.api(`/api/task-comments.php?task_id=${taskId}`);
  data.items.forEach((c) => {
    const div = document.createElement('div');
    div.className = 'text-xs bg-gray-50 rounded-xl px-3 py-2';
    div.innerHTML = `<span class="font-medium text-gray-700">${c.user_name}</span>: <span class="text-gray-600">${c.body}</span>`;
    container.appendChild(div);
  });
}

async function openTaskDetail(taskId) {
  const task = currentTasks.find((t2) => t2.id === taskId);
  if (!task) return;

  currentDetailTaskId = taskId;

  document.getElementById('task-detail-title').textContent = task.title;
  document.getElementById('task-detail-description').textContent = task.description;
  document.getElementById('task-detail-due').textContent = task.due_date ? `${t('task_due_date')}: ${task.due_date}` : '';
  document.getElementById('task-detail-assignees').textContent = `${t('task_assignees_label')}: ${task.assignees.map((a) => a.name || a.phone).join(', ')}`;
  document.getElementById('task-detail-status').value = task.status;
  document.getElementById('task-detail-notify').classList.toggle('hidden', !window.IS_TASK_MANAGER);

  await loadComments(taskId);

  document.getElementById('task-detail-sheet').classList.remove('hidden');
}

function closeTaskDetail() {
  document.getElementById('task-detail-sheet').classList.add('hidden');
  currentDetailTaskId = null;
}

async function sendNotify(channel) {
  try {
    const data = await App.api('/api/task-notify.php', {
      method: 'POST',
      body: JSON.stringify({ id: currentDetailTaskId, channel }),
    });
    const allOk = data.results.every((r) => r.ok);
    App.toast(allOk ? t('task_notify_sent') : t('task_notify_failed'), allOk ? 'success' : 'error');
  } catch (e) {
    App.toast(e.message, 'error');
  }
}

function bindDetailEvents() {
  document.getElementById('task-detail-close').addEventListener('click', closeTaskDetail);
  document.getElementById('task-detail-overlay').addEventListener('click', closeTaskDetail);

  document.getElementById('task-detail-status').addEventListener('change', async (event) => {
    try {
      await App.api('/api/task-update.php', {
        method: 'POST',
        body: JSON.stringify({ id: currentDetailTaskId, status: event.target.value }),
      });
      loadTasks();
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });

  document.getElementById('task-notify-sms-btn').addEventListener('click', () => sendNotify('sms'));
  document.getElementById('task-notify-push-btn').addEventListener('click', () => sendNotify('push'));

  document.getElementById('task-comment-form').addEventListener('submit', async (event) => {
    event.preventDefault();
    const input = document.getElementById('task-comment-input');
    const body = input.value.trim();
    if (!body) return;

    try {
      await App.api('/api/task-comments.php', {
        method: 'POST',
        body: JSON.stringify({ task_id: currentDetailTaskId, body }),
      });
      input.value = '';
      await loadComments(currentDetailTaskId);
    } catch (e) {
      App.toast(e.message, 'error');
    }
  });
}
```

Then extend `bindEvents()` (from Task 15) by adding a call to `bindDetailEvents();` as its first line.

Also fix `/api/task-comments.php`'s `GET` call above: it's a query-string GET (`?task_id=...`), not a POST, so `App.api()`'s CSRF header (only added for non-GET methods, per `app.js:29`) is correctly skipped — no change needed there, just confirming this during review.

- [ ] **Step 3: Syntax check**

Run: `php -l public/tasks.php && node --check public/assets/js/tasks.js`
Expected: no errors.

- [ ] **Step 4: Manual browser check**

Click a task card. Confirm the detail sheet shows description/due date/assignees, changing status updates it (reflected in the list after closing), adding a comment appears immediately, and (as superadmin/admin) the SMS/push buttons are visible and clicking one shows a toast (SMS will error until `kavenegar_sms_api_key` is configured in Settings — that's expected without real credentials).

- [ ] **Step 5: Commit**

```bash
git add public/tasks.php public/assets/js/tasks.js
git commit -m "Add task detail sheet: comments, status change, notify buttons"
```

---

### Task 17: `tasks.js` — push opt-in + Jalali due-date input

**Files:**
- Modify: `public/assets/js/tasks.js` (push subscribe logic; due-date field rendering)

**Interfaces:**
- Consumes: `GET/POST /api/push-subscribe.php`; browser `Notification`, `navigator.serviceWorker`, `PushManager` APIs; `Intl.DateTimeFormat('fa-IR-u-ca-persian-nu-latn', ...)` for Jalali conversion (no library — native platform feature).
- Produces: the "Enable push notifications" header button subscribes the browser and posts the subscription to the server; the due-date field in the create/edit sheet renders a native `<input type="date">` for `en` and a Jalali year/month/day `<select>` trio for `fa`, both ultimately producing/consuming a plain Gregorian `YYYY-MM-DD` string for the API.

- [ ] **Step 1: Add push opt-in logic to `public/assets/js/tasks.js`**

Add this before the final `document.addEventListener('DOMContentLoaded', ...)` block:

```js
function urlBase64ToUint8Array(base64String) {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = atob(base64);
  return Uint8Array.from(rawData, (c) => c.charCodeAt(0));
}

async function enablePushNotifications() {
  if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
    App.toast(t('task_push_unsupported'), 'error');
    return;
  }

  try {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') return;

    const { public_key: publicKey } = await App.api('/api/push-subscribe.php');
    const registration = await navigator.serviceWorker.ready;
    const subscription = await registration.pushManager.subscribe({
      userVisibleOnly: true,
      applicationServerKey: urlBase64ToUint8Array(publicKey),
    });

    const json = subscription.toJSON();
    await App.api('/api/push-subscribe.php', {
      method: 'POST',
      body: JSON.stringify({ action: 'subscribe', endpoint: json.endpoint, keys: json.keys }),
    });

    App.toast(t('task_push_enabled'), 'success');
  } catch (e) {
    App.toast(e.message, 'error');
  }
}
```

Then extend `bindEvents()` (from Task 15) by adding:

```js
  document.getElementById('push-enable-btn')?.addEventListener('click', enablePushNotifications);
```

- [ ] **Step 2: Add the Jalali due-date field**

Add this to `public/assets/js/tasks.js` (used by `openTaskSheet()`):

```js
const FA_CALENDAR = 'fa-IR-u-ca-persian-nu-latn';
const currentLocale = document.documentElement.lang === 'fa' ? 'fa' : 'en';

function gregorianToJalaliParts(dateStr) {
  const date = new Date(`${dateStr}T00:00:00Z`);
  const parts = new Intl.DateTimeFormat(FA_CALENDAR, { year: 'numeric', month: '2-digit', day: '2-digit', timeZone: 'UTC' }).formatToParts(date);
  const get = (type) => parseInt(parts.find((p) => p.type === type).value, 10);
  return { year: get('year'), month: get('month'), day: get('day') };
}

function jalaliPartsToGregorian(jy, jm, jd) {
  const estimate = new Date(Date.UTC(jy + 621, 2, 21));
  estimate.setUTCDate(estimate.getUTCDate() + (jm - 1) * 30 + (jd - 1));

  for (let offset = -10; offset <= 10; offset += 1) {
    const candidate = new Date(estimate);
    candidate.setUTCDate(candidate.getUTCDate() + offset);
    const isoDate = candidate.toISOString().slice(0, 10);
    const parts = gregorianToJalaliParts(isoDate);
    if (parts.year === jy && parts.month === jm && parts.day === jd) {
      return isoDate;
    }
  }
  return null;
}

function renderDueDateField(dueDate) {
  const field = document.getElementById('task-due-date-field');
  const label = field.querySelector('label');
  field.innerHTML = '';
  if (label) field.appendChild(label);

  if (currentLocale === 'en') {
    const input = document.createElement('input');
    input.type = 'date';
    input.id = 'task-due-date';
    input.className = 'w-full rounded-xl border border-gray-300 px-3 py-2.5 text-sm';
    if (dueDate) input.value = dueDate;
    field.appendChild(input);
    return;
  }

  const parts = dueDate ? gregorianToJalaliParts(dueDate) : null;
  const wrap = document.createElement('div');
  wrap.className = 'flex gap-2';
  wrap.innerHTML = `
    <input id="task-due-jy" type="number" placeholder="سال" class="w-1/3 rounded-xl border border-gray-300 px-2 py-2.5 text-sm" value="${parts ? parts.year : ''}">
    <input id="task-due-jm" type="number" min="1" max="12" placeholder="ماه" class="w-1/3 rounded-xl border border-gray-300 px-2 py-2.5 text-sm" value="${parts ? parts.month : ''}">
    <input id="task-due-jd" type="number" min="1" max="31" placeholder="روز" class="w-1/3 rounded-xl border border-gray-300 px-2 py-2.5 text-sm" value="${parts ? parts.day : ''}">
  `;
  field.appendChild(wrap);
}

function readDueDateField() {
  if (currentLocale === 'en') {
    return document.getElementById('task-due-date').value || null;
  }

  const jy = parseInt(document.getElementById('task-due-jy').value, 10);
  const jm = parseInt(document.getElementById('task-due-jm').value, 10);
  const jd = parseInt(document.getElementById('task-due-jd').value, 10);
  if (!jy || !jm || !jd) return null;

  return jalaliPartsToGregorian(jy, jm, jd);
}
```

- [ ] **Step 3: Wire the due-date field into the create/edit sheet**

In `openTaskSheet(task)` (Task 15), add a call right after `document.getElementById('task-priority').value = ...;`:

```js
  renderDueDateField(task ? task.due_date : null);
```

In the `task-form` submit handler (Task 15), add to the `payload` object:

```js
      due_date: readDueDateField(),
```

- [ ] **Step 4: Syntax check**

Run: `node --check public/assets/js/tasks.js`
Expected: no output.

- [ ] **Step 5: Full manual QA pass (both roles, both languages)**

Per `CLAUDE.md`'s verification section, this feature has no automated UI tests — exercise it directly in a browser:

1. As **superadmin**, `en` locale: create a task with a due date (native date input), assign 2 users, save, confirm it appears in the list with the right priority badge.
2. Switch language to `fa` via the language switcher: open the same task for edit, confirm the due date now shows as a Jalali y/m/d trio with the correct converted values, change it, save, switch back to `en`, and confirm the stored Gregorian date shifted correctly.
3. As a **shop_manager** assigned to that task: confirm you only see tasks assigned to you, can change status and add a comment, and that attempting to edit title/assignees isn't possible from the UI (the fields simply aren't shown to non-managers — but also re-confirm `public/api/task-update.php` rejects a manually-crafted request with a non-status field, per Task 9).
4. Click "Enable push notifications" in a real browser (not headless), grant permission, then as superadmin click "Send push" on a task assigned to that same account — confirm a system notification appears, and clicking it opens/focuses `/tasks.php`.
5. Click "Send SMS" without `kavenegar_sms_api_key` configured — confirm a clear error toast (not a fatal), then configure it in Settings and confirm a real SMS is delivered.

- [ ] **Step 6: Commit**

```bash
git add public/assets/js/tasks.js
git commit -m "Add push opt-in and Jalali due-date input to the Tasks page"
```
