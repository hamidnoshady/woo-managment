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

        try {
            $status = self::waitForCompletion($client, $backupId);
        } catch (Throwable $e) {
            return self::recordFailure($backupId, $e->getMessage());
        }
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
