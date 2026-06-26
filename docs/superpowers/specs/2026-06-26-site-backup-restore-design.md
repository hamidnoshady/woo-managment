# Per-Site WooCommerce Backup & Restore — Design

## Problem

The existing S3 backup system ([includes/BackupManager.php](../../../includes/BackupManager.php)) only
backs up woo-managment's own MySQL database/files. It has no way to back up
or restore the actual remote WooCommerce/WordPress sites this app manages —
the app only holds WooCommerce/WP REST API credentials per site (`sites`
table: `store_url`, `consumer_key`, `consumer_secret`, `wp_username`,
`wp_app_password`), which expose product/taxonomy data only, not the site's
filesystem or database.

Goal: real, verifiable per-site backups (database-only or full
database+files) to S3, and real, non-destructive restores from them — with
an explicit "back up the site before I make risky changes, then make the
changes" workflow. "Real" means: a backup is never marked complete unless
every byte is verified in S3, and a restore can never leave the site
half-old/half-new, regardless of shared-hosting resource limits.

## Why a companion plugin

REST API access alone cannot read a remote site's filesystem or database.
SSH/SFTP credentials per site were considered but rejected — they're
invasive to collect/secure and not guaranteed available on shared hosting.
A small WordPress plugin installed on each managed site, which already runs
inside the WP process with full local DB/filesystem access, is the only
option that gets a real backup without extra infra per host.

## Architecture

Two new pieces, built in two phases against one agreed API contract:

- **`woo-mgmt-backup-agent`** (Phase 1) — a standalone WordPress plugin,
  distributed as its own zip/codebase, installed on each managed WP site.
  Owns: pairing, local DB dump / wp-content zip, direct-to-S3 upload and
  download via presigned URLs, chunked job execution, restore staging.
- **woo-managment app changes** (Phase 2) — per-site backup settings, a
  job-orchestration API (issues presigned S3 URLs, tracks job state),
  backup history + restore admin UI.

### Pairing

1. On a site's settings page, admin clicks "Generate backup agent token".
   woo-managment generates a long random token, stores its hash against the
   site row, and shows the token once alongside the woo-managment API base
   URL.
2. Admin installs the plugin on the WP site and pastes the token + base URL
   into the plugin's settings screen.
3. The plugin sends the token as a Bearer header on every call back to
   woo-managment. Regenerating the token from woo-managment immediately
   invalidates the old one.

### Driving execution without relying on real WP-Cron

Shared hosting often doesn't fire WP-Cron reliably. Rather than trust
`wp_schedule_event` alone, woo-managment polls the plugin's status endpoint,
and each poll carries `?tick=1` — the plugin executes exactly one
chunk-step synchronously within that request and returns progress.
`wp_schedule_event` runs the same tick as a fallback for when nobody's
watching the admin page, but the primary driver is woo-managment's poll
loop, since woo-managment is what's actually waiting on the result.

```
woo-managment                          WP site (plugin)                 S3
     |--- POST start backup job ----------->|
     |<-- 202 job_id ------------------------|
     |--- GET status?tick=1 (loop) --------->|
     |                                       |--- request presigned PUT --->|
     |<-- progress JSON ---------------------|<------------------------------|
     |--- issues presigned URL for chunk --->|
     |                                       |--- PUTs chunk directly ----->|
     |<-- progress: done ---------------------|
```

## Backup flow & data model

### Per-site settings

New tab on the site's settings page, following the existing
`SETTINGS_FIELDS` pattern but scoped per-site rather than global:

- `backup_enabled` (bool)
- `backup_schedule` (off / daily / weekly)
- `backup_retention_days`
- `backup_agent_token_hash`
- `backup_agent_paired_at`, `backup_agent_last_seen_at` (drives an
  "agent connected / never connected / stale" indicator in the UI)

S3 destination reuses the app's existing global `s3_endpoint` /
`s3_bucket` / `s3_access_key` / `s3_secret_key` settings — no per-site S3
credentials. Each site's objects live under `backups/site-{id}/`.

### New tables

```sql
site_backups (
  id, site_id, type ENUM('database','full'),
  status ENUM('running','completed','failed'),
  step_label, percent, total_size_bytes,
  s3_prefix,              -- backups/site-{id}/{job_id}/
  manifest_json,          -- [{part_key, sha256, bytes}, ...] once complete
  error, started_at, completed_at, last_tick_at
)

site_restores (
  id, site_id, source_backup_id,
  type ENUM('database','full'),
  status ENUM('running','completed','failed'),
  step_label, percent,
  safety_backup_id,       -- auto-snapshot taken before this restore started
  error, started_at, completed_at, last_tick_at
)
```

`last_tick_at` lets a stalled job (no tick within a timeout, e.g. 10
minutes) be marked `failed` by the next status poll instead of sitting in
`running` indefinitely — the UI must never show a job as in-progress when
nothing is actually advancing it.

### Backup job steps (chunked, resumable — one step per tick)

1. **Dump DB in batches** — plugin iterates tables (pure PHP, no
   `mysqldump` dependency, since `exec()` is often blocked on shared
   hosting — same approach the existing `BackupManager` already uses for
   woo-managment's own DB), writing N rows per tick to a local temp SQL
   file, advancing a cursor stored on the job row.
2. **Zip wp-content in batches** (`full` only) — adds N files per tick to a
   local temp zip, excluding cache/temp directories.
3. **Upload temp file to S3 in parts** — for each part, the plugin asks
   woo-managment for a presigned PUT URL scoped to
   `backups/site-{id}/{job_id}/part-N`, uploads directly to S3, records the
   returned ETag and a locally-computed sha256 in the job's manifest, then
   deletes the local temp part. At most one part's worth of temp data sits
   on the WP host's disk at any time.
4. **Finalize** — once every part is confirmed, woo-managment writes the
   manifest JSON to S3 (also via presigned PUT) and marks the backup
   `completed`. A backup is never `completed` until every part's sha256 is
   recorded and confirmed.

## Restore flow & safety

Restore is the highest-risk operation and gets extra guardrails:

1. **Auto safety snapshot first** — before touching anything, the plugin
   runs a full backup of the *current* state (same chunked flow as above)
   and records it as `safety_backup_id` on the restore row. A failed
   restore is never a dead end — the admin can always restore that snapshot
   to get back to the pre-restore state.
2. **Staging, never overwrite live in place:**
   - *Database*: download the SQL manifest's parts via presigned GET,
     replay statements in batches inside transactions against the live DB.
     A batch failure rolls back just that batch and the restore is marked
     `failed` — not silently half-applied.
   - *Files*: download each zip part to a staging directory
     (`wp-content/.wmb-restore-{job_id}/`), extract and verify there, and
     only `rename()` the finished staging folder over the real `wp-content`
     subfolder (`uploads`, `themes`, `plugins`) as one atomic filesystem
     operation per folder, once that folder's data is 100% verified on
     disk. Nothing is ever extracted on top of a live directory mid-stream.
3. **Resumable, same tick model as backup** — each download-batch /
   replay-batch / extract-batch is one tick; a host that kills a long
   request just resumes on the next poll instead of corrupting a partial
   state.
4. **Scope choice** — restore UI lets the admin pick database-only or full,
   and which historical backup to restore from.
5. **Stall/failure handling** — identical to backup: no tick within the
   timeout window marks the job `failed`; the safety snapshot remains
   available; nothing is left ambiguously "in progress."

## Pre-change backup workflow

Manual, not automatic. The site's batch/bulk-edit pages get a "Backup now"
button + status indicator. The admin triggers it, waits for confirmed
completion, then proceeds with changes at their own judgment. No mutating
action is automatically blocked or gated on a backup — this keeps the
feature additive rather than adding latency/complexity to every bulk
action.

## Error handling

- Every part upload/download is checksummed (sha256); a mismatch fails that
  part's tick and retries on the next tick rather than silently accepting
  corrupt data.
- Jobs that stall (no tick within timeout) are marked `failed` by the next
  status check, never left in an ambiguous `running` state.
- Restore failures always leave a usable safety snapshot in place.
- All plugin REST endpoints require the Bearer pairing token; a missing or
  invalid token returns 401 and no job state changes.

## Phasing

- **Phase 1 — `woo-mgmt-backup-agent` plugin**: pairing settings screen,
  REST endpoints (`start backup`, `status?tick=1`, `start restore`,
  `request presigned URL` proxy calls back to woo-managment), chunked
  backup job runner, chunked restore job runner with staging/atomic swap,
  WP-Cron fallback tick.
- **Phase 2 — woo-managment app**: per-site settings tab, `site_backups` /
  `site_restores` tables, presigned-URL-issuing API, poll-driven job
  orchestration, backup history list UI, restore UI (scope + source
  picker), "Backup now" button on batch/bulk-edit pages.

## Out of scope

- Per-site S3 credentials/buckets (reuses the app's global S3 config).
- Automatic backup gating before mutating actions (manual only, see above).
- Backing up anything via SSH/SFTP or `mysqldump`/`exec()` (shared-hosting
  compatibility requirement).
