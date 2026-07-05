# Task Management + Notifications — Design

## Problem

Admins/superadmins need a way to create tasks, assign them to one or more
users, and manually notify assignees by SMS and/or browser push. Nothing
like this exists today — the closest analogue is the activity log
(read-only history) and the OTP SMS flow in `includes/auth.php`.

## Scope

- A new task entity: title, description, due date, priority, status,
  multiple assignees, comments.
- Site-scoped, like products/activity logs — a task belongs to one site,
  visibility follows the existing `user_sites` bridge table.
- Manual, repeatable per-task notify actions: "Send SMS" and "Send push",
  each optional, each clickable any number of times, message auto-generated
  from the task (no free-text compose box).
- Two independent delivery channels:
  - **SMS** via Kavenegar's plain Send API, using a *new* API key/sender
    setting, separate from the existing OTP `kavenegar_api_key`.
  - **Web Push** via the standard browser Push API + VAPID (not Kavenegar's
    proprietary webpush product, whose public docs don't expose the actual
    subscribe/send API surface) — works even when the app isn't open in a
    tab.

## Roles & visibility

- **superadmin / admin**: create tasks, edit any field, assign to multiple
  users, see all tasks for sites they manage (superadmin sees all sites).
- **shop_manager**: sees only tasks assigned to them (within sites they're
  assigned to via `user_sites`), can change status and add comments, cannot
  edit title/description/due date/priority/assignees, cannot create tasks.
- Enforced server-side per endpoint, same shape as existing `require_login_api()`
  / `require_site_api()` checks — no new permission framework.

## Data model

New tables in `includes/Database.php`, following the existing inline
`CREATE TABLE IF NOT EXISTS` convention (no migration framework):

```sql
CREATE TABLE IF NOT EXISTS tasks (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT NULL,
    due_date DATE NULL,
    priority VARCHAR(10) NOT NULL DEFAULT 'medium' CHECK (priority IN ('low','medium','high')),
    status VARCHAR(20) NOT NULL DEFAULT 'todo' CHECK (status IN ('todo','in_progress','done')),
    created_by INT NOT NULL,
    created_at INT NOT NULL,
    updated_at INT NOT NULL,
    CONSTRAINT fk_tasks_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS task_assignees (
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    PRIMARY KEY (task_id, user_id),
    CONSTRAINT fk_ta_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_ta_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS task_comments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    task_id INT NOT NULL,
    user_id INT NOT NULL,
    body TEXT NOT NULL,
    created_at INT NOT NULL,
    CONSTRAINT fk_tc_task FOREIGN KEY (task_id) REFERENCES tasks(id) ON DELETE CASCADE,
    CONSTRAINT fk_tc_user FOREIGN KEY (user_id) REFERENCES users(id)
);

CREATE TABLE IF NOT EXISTS push_subscriptions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    endpoint VARCHAR(500) NOT NULL,
    p256dh VARCHAR(255) NOT NULL,
    auth VARCHAR(255) NOT NULL,
    created_at INT NOT NULL,
    UNIQUE KEY uniq_endpoint (endpoint),
    CONSTRAINT fk_ps_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);
```

`due_date` stores a plain Gregorian date. Persian (`fa`) locale converts
it for display/input only (see UI section) — no dual storage.

**No undo support.** The existing 10s undo window
(`includes/ActivityLog.php`) exists for destructive one-shot mutations
(stock/product edits); tasks are freely re-editable at any time, so the
undo machinery would add complexity without matching real risk.

**Notify actions are logged**, not stored in a new table: each send call
writes an `activity_logs` row (`category = 'task'`, action
`task_notify_sms` / `task_notify_push`, `undo_data = null`) purely as an
audit trail, reusing existing infra instead of inventing a new log.

## Settings additions

`includes/Settings.php` / `SETTINGS_DEFAULTS` + `SETTINGS_FIELDS` (and the
`settings_group_key()` map in `public/api/settings.php`, per the existing
gotcha that group labels need translating there too):

- `kavenegar_sms_api_key` (password) — separate Kavenegar account/token
  from the existing OTP key, group "Kavenegar (SMS notifications)".
- `kavenegar_sms_sender` (text) — sender line number required by
  Kavenegar's plain Send API.
- `vapid_public_key` / `vapid_private_key` — **not** user-facing form
  fields. Lazily generated once (EC P-256 keypair via PHP's `openssl`
  extension) the first time a push is sent or a subscription is requested,
  then stored and reused. No admin UI needed since there's nothing for a
  human to configure.

## SMS delivery

New `send_kavenegar_sms(string $phone, string $message): array` (near
`send_kavenegar_otp()` in `includes/auth.php`, or a new
`includes/Notifications.php` if that file is getting crowded), reusing the
exact same curl-based request/response handling, but hitting Kavenegar's
plain `sms/send.json` endpoint with `sender`/`receptor`/`message` params
instead of `verify/lookup.json`.

## Web Push delivery

`includes/WebPush.php` — a small vendored implementation, since this
project has no Composer and libraries like `minishlink/web-push-php`
aren't installable:

- `get_vapid_keys(): array` — returns `[$publicKey, $privateKey]` (base64url),
  generating and persisting them via `openssl_pkey_new` (curve
  `prime256v1`) on first call if not already in `settings`.
- `send_web_push(array $subscription, array $payload): array` — implements
  RFC 8291 message encryption (ECDH key agreement + HKDF + AES-128-GCM)
  and signs a VAPID JWT (ES256) per RFC 8292, then POSTs the encrypted
  payload to the subscription's `endpoint` with the required
  `Content-Encoding: aes128gcm`, `TTL`, and `Authorization: vapid t=…,k=…`
  headers. On a `404`/`410` response, deletes the subscription row (device
  unsubscribed or expired).

Client side:

- `public/sw.js` gains a `push` event listener (renders the notification
  from `event.data.json()`) and a `notificationclick` listener (focuses or
  opens the app).
- An "Enable push notifications" button (on the Tasks page) calls
  `Notification.requestPermission()`, then
  `registration.pushManager.subscribe({ userVisibleOnly: true,
  applicationServerKey: <VAPID public key> })`, then POSTs the resulting
  subscription to `public/api/push-subscribe.php`.
- The VAPID public key is embedded directly in the Tasks page (it's not
  secret) rather than fetched via a dedicated endpoint.
- Standard Push API needs no third-party script domain, so the existing
  strict CSP (`public/.htaccess`) is untouched.

## New files

- `public/tasks.php` + `public/assets/js/tasks.js` — task list/detail page.
- `public/api/tasks.php` — `GET` list (site-scoped, role-filtered), `POST`
  create (admin/superadmin only).
- `public/api/task-update.php` — `POST` update; field-level permission
  check inline (admin/superadmin: any field; shop_manager: `status` only).
- `public/api/task-comments.php` — `GET`/`POST` comments.
- `public/api/task-notify.php` — `POST` trigger SMS/push send to all
  assignees, logs to `activity_logs`.
- `public/api/push-subscribe.php` — `POST` register/unregister a push
  subscription for the current user.
- `public/api/site-users.php` — `GET` users assignable to the current site
  (admin/superadmin, not just superadmin like `public/api/users.php`) —
  needed because the assignee picker must work for plain admins, and the
  existing `users.php` endpoint is deliberately superadmin-only for user
  *management*; this is a narrower read-only lookup, left as its own file
  rather than loosening `users.php`'s existing access check.
- `includes/Tasks.php` — data access (list/create/update, site-scoped
  queries mirroring `includes/Sites.php`/`ActivityLog.php` patterns).
- `includes/WebPush.php` — VAPID + RFC 8291 send, described above.

## UI / workflow

- Task list (`tasks.php`), filtered per the Roles & visibility section
  above.
- Create/edit form (admin/superadmin only): title, description, due date,
  priority, assignee multi-select (pulled from a site-filtered user list —
  `public/api/users.php` is currently superadmin-only, so task assignment
  needs its own lighter-weight "users on this site" lookup for admins),
  status.
- Detail view: comment thread, status changer, "Send SMS" / "Send push"
  buttons — no confirmation dialog (repeatable, non-destructive), just a
  toast on completion.
- **Jalali dates**: for `fa` locale, `due_date` is displayed and entered
  via a small vendored Gregorian↔Jalali conversion function (no library) —
  a lightweight year/month/day input replaces the native
  `<input type="date">` for `fa`, since that control can't do Jalali
  natively. `en` locale keeps the native date input untouched.

## Error handling

- Missing Kavenegar SMS config, or an assignee push subscription of zero
  → toast error naming the specific reason, not a generic failure.
- Partial failure across multiple assignees (some reachable, some not)
  → toast summarizes per-user success/failure; doesn't block delivery to
  the others.

## i18n / nav

- New keys added to both `includes/i18n.php` and
  `public/assets/js/i18n.js` (en + fa, kept symmetric) — task list/detail
  strings, priority/status labels, notify button labels, push opt-in
  copy.
- New "Tasks" tab added to `includes/nav.php` (`render_bottom_nav` and
  `render_desktop_sidebar` — the one central nav file, not the duplicated
  admin sub-nav rows).

## Out of scope (for this spec)

- Automatic/scheduled notifications (due-date reminders, status-change
  triggers) — explicitly manual-only per requirements.
- Free-text custom notification messages — auto-generated only.
- Kavenegar's proprietary webpush product — using standard Web Push
  instead, since Kavenegar's public docs don't disclose the actual API
  contract needed to integrate it.
