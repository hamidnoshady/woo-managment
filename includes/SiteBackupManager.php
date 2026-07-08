<?php
// includes/SiteBackupManager.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/S3Client.php';
require_once __DIR__ . '/Sites.php';
require_once __DIR__ . '/JetBackupSiteManager.php';

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
        if ($site['da_username'] !== '') {
            return JetBackupSiteManager::startBackup($site);
        }

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

    public static function pollBackup(array $site, int $jobId): ?array
    {
        if ($site['da_username'] !== '') {
            return JetBackupSiteManager::pollBackup($site, $jobId);
        }

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

        $effectiveJobId = self::resolveS3JobId($site, $jobId);
        $key = "backups/site-{$site['id']}/{$effectiveJobId}/{$partKey}";
        $url = $s3->presignedUrl(strtoupper($method) === 'GET' ? 'GET' : 'PUT', $key, 900);
        return ['ok' => true, 'url' => $url, 'error' => ''];
    }

    /**
     * Restore jobs are ticked using an offset job_id (RESTORE_AGENT_ID_OFFSET
     * + restore local id), but the backup parts they download were uploaded
     * under the *source backup's* own id as the S3 prefix (see startBackup).
     * Resolve restore-range ids to that source backup id before building the
     * key. The safety-snapshot range (9_000_000_000+) is plugin-internal only
     * and already uses the raw offset id consistently for both its uploads
     * and reads, so it must NOT be resolved here.
     */
    private static function resolveS3JobId(array $site, int $jobId): int
    {
        if ($jobId >= self::RESTORE_AGENT_ID_OFFSET && $jobId < 9_000_000_000) {
            $restoreLocalId = $jobId - self::RESTORE_AGENT_ID_OFFSET;
            $restore = self::getRestore($restoreLocalId);
            if ($restore !== null && (int) $restore['site_id'] === (int) $site['id']) {
                return (int) $restore['source_backup_id'];
            }
        }
        return $jobId;
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

    /**
     * Ticks every running site_backups/site_restores row across all sites,
     * looping until nothing advances or the time budget runs out. Without
     * this, jobs only progress while a browser tab is open polling
     * pollBackup()/pollRestore() directly — closing the tab orphans them at
     * whatever percent they last reached. Called from public/cron/site-backups.php.
     */
    public static function tickAllRunning(int $budgetSeconds = 20): void
    {
        $deadline = time() + $budgetSeconds;
        do {
            $progressed = false;
            foreach (self::runningJobs('site_backups') as $row) {
                $site = get_site((int) $row['site_id']);
                if ($site !== null) {
                    self::pollBackup($site, (int) $row['id']);
                    $progressed = true;
                }
            }
            foreach (self::runningJobs('site_restores') as $row) {
                $site = get_site((int) $row['site_id']);
                if ($site !== null) {
                    self::pollRestore($site, (int) $row['id']);
                    $progressed = true;
                }
            }
            foreach (self::runningJobs('site_jetbackup_backups') as $row) {
                $site = get_site((int) $row['site_id']);
                if ($site !== null) {
                    JetBackupSiteManager::pollBackup($site, (int) $row['id']);
                    $progressed = true;
                }
            }
        } while ($progressed && time() < $deadline);
    }

    private static function runningJobs(string $table): array
    {
        return Database::get()->query("SELECT id, site_id FROM {$table} WHERE status = 'running'")->fetchAll();
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
