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
