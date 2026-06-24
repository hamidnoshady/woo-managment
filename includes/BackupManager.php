<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/S3Client.php';

/**
 * Builds and uploads database/full backups to S3-compatible storage, and
 * tracks each run in the `backups` table. Triggered either from
 * public/cron/backup.php (cron, token-authenticated) or the "Run backup
 * now" buttons on Admin -> Backups (session-authenticated).
 */
class BackupManager
{
    /**
     * Runs a backup of the given type ('database' or 'full'), uploads it to
     * S3, records the result, and prunes backups older than the configured
     * retention period. Always returns a result array; never throws.
     *
     * @return array{ok: bool, type: string, s3_key: string, size_bytes: int, error: string}
     */
    public static function run(string $type): array
    {
        $type = $type === 'full' ? 'full' : 'database';

        try {
            $s3 = self::s3Client();
            if ($s3 === null) {
                return self::recordFailure($type, 'S3 backup storage is not configured (see Admin -> Settings).');
            }

            $dbDumpPath = null;
            try {
                $dbDumpPath = self::writeDatabaseDumpToTempFile();
                if ($type === 'database') {
                    $localPath = $dbDumpPath;
                    $contentType = 'application/sql';
                    $extension = '.sql';
                } else {
                    $localPath = self::buildFullArchive($dbDumpPath);
                    @unlink($dbDumpPath);
                    $contentType = 'application/zip';
                    $extension = '.zip';
                }
            } catch (Throwable $e) {
                if ($dbDumpPath !== null) {
                    @unlink($dbDumpPath);
                }
                return self::recordFailure($type, $e->getMessage());
            }

            $key = 'backups/' . $type . '-' . gmdate('Y-m-d_H-i-s') . $extension;
            $size = (int) filesize($localPath);
            $body = (string) file_get_contents($localPath);
            @unlink($localPath);

            $result = $s3->putObject($key, $body, $contentType);
            if (!$result['ok']) {
                return self::recordFailure($type, $result['error'], $key, $size);
            }

            self::recordResult($type, 'success', $key, $size, '');
            self::pruneOldBackups($s3);

            return ['ok' => true, 'type' => $type, 's3_key' => $key, 'size_bytes' => $size, 'error' => ''];
        } catch (Throwable $e) {
            try {
                return self::recordFailure($type, $e->getMessage());
            } catch (Throwable $inner) {
                return ['ok' => false, 'type' => $type, 's3_key' => '', 'size_bytes' => 0, 'error' => $e->getMessage()];
            }
        }
    }

    /**
     * Returns the most recent backups (newest first), for the Admin -> Backups list.
     */
    public static function listRecent(int $limit = 50): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM backups ORDER BY id DESC LIMIT ' . max(1, min(200, $limit)));
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Returns the secret token used to authenticate public/cron/backup.php
     * requests, generating and persisting one the first time it's needed.
     */
    public static function ensureCronToken(): string
    {
        $token = (string) get_setting('backup_cron_token');
        if ($token === '') {
            $token = bin2hex(random_bytes(24));
            update_settings(['backup_cron_token' => $token]);
        }
        return $token;
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

    /**
     * Dumps every table's schema and data to a plain .sql file. Pure PHP —
     * no mysqldump binary, since shared hosts often disable shell_exec.
     */
    private static function writeDatabaseDumpToTempFile(): string
    {
        $pdo = Database::get();
        $path = tempnam(sys_get_temp_dir(), 'wcpm_db_');
        if ($path === false) {
            throw new RuntimeException('Could not create a temporary file for the database dump.');
        }

        $fh = fopen($path, 'w');
        fwrite($fh, '-- Database backup generated ' . gmdate('c') . "\n");
        fwrite($fh, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        foreach ($tables as $table) {
            $createRow = $pdo->query("SHOW CREATE TABLE `{$table}`")->fetch();
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n" . $createRow['Create Table'] . ";\n\n");

            $stmt = $pdo->query("SELECT * FROM `{$table}`");
            while ($row = $stmt->fetch()) {
                $columns = array_map(fn($c) => "`{$c}`", array_keys($row));
                $values = array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string) $v), array_values($row));
                fwrite($fh, "INSERT INTO `{$table}` (" . implode(', ', $columns) . ') VALUES (' . implode(', ', $values) . ");\n");
            }
            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        return $path;
    }

    /**
     * Zips the whole project directory (excluding .git) plus the given
     * database dump file into one archive, so a "full" backup can restore
     * the app from scratch.
     */
    private static function buildFullArchive(string $dbDumpPath): string
    {
        if (!extension_loaded('zip')) {
            throw new RuntimeException('The PHP zip extension is not enabled; full backups are unavailable (database-only backups still work).');
        }

        $zipPath = tempnam(sys_get_temp_dir(), 'wcpm_zip_');
        if ($zipPath === false) {
            throw new RuntimeException('Could not create a temporary file for the backup archive.');
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::OVERWRITE) !== true) {
            @unlink($zipPath);
            throw new RuntimeException('Could not create the backup archive.');
        }

        $projectRoot = (string) realpath(__DIR__ . '/..');
        $exclude = ['.git'];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($projectRoot, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $localPath = str_replace('\\', '/', substr($file->getPathname(), strlen($projectRoot) + 1));

            $skip = false;
            foreach ($exclude as $excluded) {
                if ($localPath === $excluded || str_starts_with($localPath, $excluded . '/')) {
                    $skip = true;
                    break;
                }
            }
            if ($skip) {
                continue;
            }

            if ($file->isDir()) {
                $zip->addEmptyDir($localPath);
            } else {
                $zip->addFile($file->getPathname(), $localPath);
            }
        }

        $zip->addFile($dbDumpPath, 'database.sql');
        $zip->close();

        return $zipPath;
    }

    private static function recordResult(string $type, string $status, string $s3Key, int $size, string $error): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare(
            'INSERT INTO backups (type, status, s3_key, size_bytes, error, created_at) VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$type, $status, $s3Key, $size, $error !== '' ? $error : null, time()]);
    }

    /**
     * @return array{ok: bool, type: string, s3_key: string, size_bytes: int, error: string}
     */
    private static function recordFailure(string $type, string $error, string $s3Key = '', int $size = 0): array
    {
        self::recordResult($type, 'failed', $s3Key, $size, $error);
        return ['ok' => false, 'type' => $type, 's3_key' => $s3Key, 'size_bytes' => $size, 'error' => $error];
    }

    /**
     * Deletes backups (both the S3 object and the local record) older than
     * the configured retention period, so storage cost doesn't grow
     * unbounded. Best-effort: a failed S3 delete just leaves that row for
     * the next run to retry.
     */
    private static function pruneOldBackups(S3Client $s3): void
    {
        $retentionDays = max(1, (int) get_setting('backup_retention_days'));
        $cutoff = time() - ($retentionDays * 86400);

        $pdo = Database::get();
        $stmt = $pdo->prepare("SELECT id, s3_key FROM backups WHERE status = 'success' AND created_at < ?");
        $stmt->execute([$cutoff]);

        foreach ($stmt->fetchAll() as $row) {
            $result = $s3->deleteObject($row['s3_key']);
            if ($result['ok']) {
                $del = $pdo->prepare('DELETE FROM backups WHERE id = ?');
                $del->execute([$row['id']]);
            }
        }
    }
}
