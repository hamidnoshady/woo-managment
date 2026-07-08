# JetBackup / DirectAdmin Backup Integration — Design

## Problem

woo-managment currently runs two separate backup systems, both hand-rolled:

1. **App self-backup** ([includes/BackupManager.php](../../../includes/BackupManager.php))
   — dumps the MySQL database with hand-written `SELECT`/`INSERT` SQL,
   optionally zips the whole project directory, and uploads to S3-compatible
   storage. Triggered from Admin → Backups or a cron token URL.
2. **Managed-site backup/restore** ([includes/SiteBackupManager.php](../../../includes/SiteBackupManager.php))
   — a resumable job system driven through the `woo-mgmt-agent` WordPress
   plugin's REST API (`wma/v1/jobs`), with presigned S3 URLs the plugin
   uploads backup parts to directly, ticked to completion by polling.

Both woo-managment and (per the user) most of the WooCommerce sites it
manages are DirectAdmin accounts on the **same physical server**, which
already runs **JetBackup 5** as a DirectAdmin plugin. JetBackup already does
account-level (files + database) backup/restore, scheduling, retention, and
offsite replication — better and more reliably than the hand-rolled system
reimplements for any site that happens to live on this server. This design
replaces the hand-rolled backup path with JetBackup wherever JetBackup can
reach the target (this server), and keeps the existing plugin/S3 path only
for sites that live elsewhere.

## Goals

- Auto-discover which DirectAdmin accounts on this server correspond to
  managed WooCommerce sites, and link them (`sites.da_username`).
- For DA-linked sites: trigger, monitor, and restore JetBackup account
  backups from within woo-managment, instead of the plugin/S3 job system.
- Give woo-managment its **own**, independent JetBackup-based backup path
  for its own DA account — separate trigger, separate history, not mixed
  with site backups.
- Sites without a matching DA account keep working exactly as today
  (plugin + S3). Nothing about product/category/media sync through the
  agent plugin changes.
- woo-managment only *triggers/monitors/restores* JetBackup jobs — backup
  schedule, retention, and destination configuration stay in JetBackup's own
  DirectAdmin UI, not duplicated here.

## Non-goals

- Managing DirectAdmin accounts themselves (create/suspend/delete) — sync is
  read-only against the DA account list.
- Configuring JetBackup schedules/destinations/retention from woo-managment.
- Removing `S3Client`/`BackupManager`/the plugin backup job system — they
  remain the only path for non-DA-linked (external) sites.

## APIs involved

**DirectAdmin API** — well-documented, stable: HTTP Basic auth using a DA
username + a Login Key (created under DA → Login Keys), REST-ish `CMD_API_*`
endpoints. Used only to enumerate accounts/domains
(`CMD_API_SHOW_ALL_USERS`/`CMD_API_SHOW_USERS` depending on whether the
configured account is admin- or reseller-level).

**JetBackup 5 API (JetApi)** — reached through DirectAdmin at
`https://{host}:2222/CMD_PLUGINS_ADMIN/jetbackup5/index.raw?api=1`, same
Basic-auth scheme (`base64(username:login_key)` in the `Authorization`
header). It's a function-dispatch API: requests specify a function name
(`-F`/`func`), a data payload (`-D`/`data`), and desired output format
(`-O json`), grouped into categories (Accounts, Queues/Snapshots,
Destinations, Jobs, ...). JetBackup already tracks its own job/queue state,
so woo-managment doesn't need to reimplement resumable-tick logic — it polls
JetBackup's own account/backup-list and queue-status functions until a
triggered job shows complete.

**Open verification item:** `docs.jetbackup.com` blocks automated fetching
(403 on every direct request during this design), so the exact function
names/parameter shapes for "create an on-demand account backup", "list
backups for an account", and "restore an account from a backup" are not
pinned down yet — only the general shape (Basic auth, function-dispatch,
Accounts/Queues/Snapshots categories exist per search-indexed doc titles
like `Accounts/getAccount`, `Accounts/manageAccount`,
`Queues/addQueueSnapshot`). **Confirm the literal function names against the
live docs (or by testing directly against the user's server) at the start
of implementation**, before writing `JetBackupClient`.

## Data model changes

`sites` table — add:
- `da_username VARCHAR(100) NOT NULL DEFAULT ''` — the DirectAdmin account
  this site maps to, if any. Empty means "not on this server" → keep using
  the plugin/S3 path.

New `site_jetbackup_backups` table — thin local mirror of JetBackup-side
jobs, for the UI history list (JetBackup itself owns the real job state):
```sql
CREATE TABLE IF NOT EXISTS site_jetbackup_backups (
    id INT AUTO_INCREMENT PRIMARY KEY,
    site_id INT NOT NULL,
    jetbackup_backup_id VARCHAR(100) NOT NULL DEFAULT '',
    status VARCHAR(20) NOT NULL DEFAULT 'running',
    size_bytes BIGINT NOT NULL DEFAULT 0,
    error TEXT NULL,
    created_at INT NOT NULL,
    completed_at INT NULL
)
```

`backups` table (app self-backup, existing) — add:
- `external_ref VARCHAR(255) NOT NULL DEFAULT ''` — JetBackup's backup
  identifier, populated instead of `s3_key` when the row came from
  JetBackup rather than the legacy S3 dump path. Lets the existing
  Admin → Backups history UI keep working with a small "source" label
  change rather than a rewrite.

## Settings

New "DirectAdmin / JetBackup" group in [includes/Settings.php](../../../includes/Settings.php)
(`SETTINGS_DEFAULTS`/`SETTINGS_FIELDS`, same pattern as the existing
`s3_*` fields):
- `da_api_url` — e.g. `https://server.example.com:2222`.
- `da_admin_username` — DA account used for account enumeration (must be
  admin or reseller level to see other users' accounts).
- `da_login_key` — the Login Key (password field type).
- `da_self_username` — which DA account *is* woo-managment itself, used
  only for the app self-backup feature.

Remember: any new `settings_group_key()` mapping needed in
[public/api/settings.php](../../../public/api/settings.php) for translated
group labels (per the existing gotcha for untranslated group names).

## Site sync

New `DirectAdminSync::syncSites()` (new `includes/DirectAdminSync.php`),
invoked from a new cron endpoint `public/cron/da-sync.php` (token-authenticated,
same pattern as `cron/backup.php`) plus a manual "Sync now" button on
Admin → Sites:

1. Call `DirectAdminClient::listAccounts()` → `[{username, domain}, ...]`.
2. For each account, match `domain` against existing `sites.store_url`
   host. On match, set that site's `da_username` (only if currently empty,
   to avoid silently relinking a manually-corrected mapping).
3. No match → create a new `sites` row: `name` = domain, `store_url` =
   `https://{domain}`, `da_username` = account username, `backup_enabled`
   = 1. (Product management won't work on it until someone separately pairs
   the agent token — sync only ever claims the backup side.)
4. Never touch a site whose `da_username` is already set to a *different*
   account than the one currently matching by domain — surface a mismatch
   rather than silently reassigning.

## Site backup/restore — JetBackup path

`SiteBackupManager` gains a branch at the top of `startBackup()`/
`startRestore()`/poll methods: if `site['da_username'] !== ''`, route to a
new `JetBackupSiteManager` instead of the existing plugin/S3 logic:

- **Trigger** — `JetBackupClient::createAccountBackup($daUsername)`, record
  a `site_jetbackup_backups` row with `status = 'running'`.
- **Monitor** — poll JetBackup's own job/queue status for that backup id;
  update the local row's `status`/`size_bytes`/`error` until terminal.
  Reuse the existing `tickAllRunning()` cron loop shape
  (`public/cron/site-backups.php`) so backups still progress without a
  browser tab open.
- **Restore** — `JetBackupClient::restoreAccountBackup($daUsername,
  $jetbackupBackupId)`. JetBackup account restores are files+DB together
  (no partial file-only/db-only scope distinction like today's plugin
  system) — the restore UI drops the scope selector for DA-linked sites.
- Sites without `da_username` are completely unaffected — same plugin/S3
  code path as today.

## App self-backup — separate JetBackup path

New `includes/AppJetBackupManager.php`, same public shape as
`BackupManager` (`run(string $type): array`, `listRecent(): array`) so
`admin/backups.php` needs minimal changes:

- `run()` calls `JetBackupClient::createAccountBackup($daSelfUsername)`,
  polls until terminal (bounded wait, since this is triggered synchronously
  from an admin button click — same UX as today's "Run backup now"), and
  records a `backups` row with `external_ref` set and `s3_key` left empty.
- This is a **fully independent** trigger from the site-backup path above —
  different DA account, different button, different history — per the
  explicit requirement to keep app self-backup as its own system rather
  than folding it into site backups.
- Restore of woo-managment's own account is inherently manual (if the app
  is down, it can't restore itself) — no in-app restore button for this;
  link out to the DirectAdmin/JetBackup panel instead.

## What's deprecated vs. kept

**Kept, scoped down to external (non-DA) sites only:**
- `includes/S3Client.php`
- `includes/BackupManager.php`'s DB-dump/zip logic — but only reachable
  when `da_self_username` is unset (JetBackup not configured for the app
  itself), as a fallback.
- `SiteBackupManager`'s plugin/S3 job path — for sites with empty
  `da_username`.
- All `s3_*` / `backup_retention_days` settings.

**Unchanged:** everything in the `woo-mgmt-agent` plugin's product/
category/taxonomy/media REST surface. Only its `kind: backup`/`restore`
job usage stops being called for DA-linked sites.

## UI changes

- **Admin → Settings** — new "DirectAdmin / JetBackup" field group.
- **Admin → Sites** — a DA-linked indicator per site (linked to `{username}`
  / not linked), "Sync now" button, mismatch warning if sync finds a
  conflicting domain match.
- **Admin → Backups** (app self-backup) — history rows show source
  (JetBackup vs legacy S3) via presence of `external_ref`; "Run backup now"
  routes to JetBackup when `da_self_username` is configured, else falls
  back to the legacy path.
- **Admin/Site → Site Backups** — history list merges `site_jetbackup_backups`
  and the legacy `site_backups`/`site_restores` tables per site depending on
  which path that site uses; restore UI drops the scope selector for
  DA-linked sites (JetBackup restores are always full-account).

## i18n

New UI strings (DA-linked status, sync button/results, JetBackup source
label, settings group/fields/help text) need entries in both
`includes/i18n.php` and the hand-maintained mirror
`public/assets/js/i18n.js`, `en` and `fa`, per the existing project
convention.
