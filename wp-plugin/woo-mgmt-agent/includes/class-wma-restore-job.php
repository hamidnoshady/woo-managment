<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-restore-job.php

defined('ABSPATH') || exit;

/**
 * Steps: safety_backup -> download -> restore_db -> [extract_files if
 * scope=full] -> swap -> done. safety_backup runs a full, same-scope
 * backup of the *current* state first (reusing Wma_Backup_Job) so a
 * failed restore always has something to recover to.
 */
class Wma_Restore_Job
{
    private const DB_BATCH_STATEMENTS = 50;

    public static function tick(array $job): array
    {
        try {
            switch ($job['step']) {
                case 'safety_backup':
                    self::tick_safety_backup($job);
                    break;
                case 'download':
                    if (self::download_step($job)) {
                        $job['step'] = 'restore_db';
                        $job['cursor_json'] = wp_json_encode(['statement_index' => 0]);
                    }
                    break;
                case 'restore_db':
                    if (self::restore_db_step($job)) {
                        $job['step'] = $job['scope'] === 'full' ? 'extract_files' : 'swap';
                        $job['cursor_json'] = wp_json_encode(['file_index' => 0]);
                    }
                    break;
                case 'extract_files':
                    if (self::extract_step($job)) {
                        $job['step'] = 'swap';
                    }
                    break;
                case 'swap':
                    self::swap_step($job);
                    $job['step'] = 'done';
                    $job['status'] = 'completed';
                    break;
            }
        } catch (Throwable $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
        }

        return $job;
    }

    private static function tick_safety_backup(array &$job): void
    {
        if (empty($job['safety_job_id'])) {
            // ponytail: offset into a range woo-managment's own autoincrement
            // ids will never reach, so this can't collide with a real job_id.
            $safetyId = 9_000_000_000 + (int) $job['job_id'];
            Wma_Db::create_job($safetyId, 'backup', $job['scope']);
            $job['safety_job_id'] = $safetyId;
        }

        $safetyJob = Wma_Db::get_job((int) $job['safety_job_id']);
        $safetyJob = Wma_Backup_Job::tick($safetyJob);
        Wma_Db::save_job($safetyJob);

        if ($safetyJob['status'] === 'failed') {
            throw new RuntimeException('Pre-restore safety backup failed: ' . $safetyJob['error']);
        }
        if ($safetyJob['status'] === 'completed') {
            $job['step'] = 'download';
            $job['cursor_json'] = wp_json_encode(['part_index' => 0]);
        }
    }

    private static function download_step(array &$job): bool
    {
        $parts = json_decode((string) $job['parts_json'], true) ?: [];
        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['part_index' => 0];

        if ($cursor['part_index'] >= count($parts)) {
            return true;
        }

        $dir = trailingslashit(get_temp_dir()) . 'wma-restore-' . $job['job_id'];
        if (!is_dir($dir)) {
            wp_mkdir_p($dir);
        }
        $job['temp_dir'] = $dir;

        $part = $parts[$cursor['part_index']];
        $presign = Wma_Relay::request_presigned_url((int) $job['job_id'], $part['part_key'], 'GET');
        if (!$presign['ok']) {
            throw new RuntimeException($presign['error']);
        }

        $localPath = $dir . '/' . $part['part_key'];
        $download = Wma_Relay::get_to_file($presign['url'], $localPath);
        if (!$download['ok']) {
            throw new RuntimeException($download['error']);
        }
        if (hash_file('sha256', $localPath) !== $part['sha256']) {
            @unlink($localPath);
            throw new RuntimeException('Downloaded part ' . $part['part_key'] . ' failed checksum verification.');
        }

        $assembled = $part['source'] === 'db' ? $dir . '/restore.sql' : $dir . '/restore.zip';
        file_put_contents($assembled, file_get_contents($localPath), FILE_APPEND);
        unlink($localPath);

        $cursor['part_index']++;
        $job['cursor_json'] = wp_json_encode($cursor);
        return $cursor['part_index'] >= count($parts);
    }

    private static function restore_db_step(array &$job): bool
    {
        global $wpdb;

        $sqlPath = $job['temp_dir'] . '/restore.sql';
        if (!is_file($sqlPath)) {
            throw new RuntimeException('Expected database dump was not downloaded.');
        }

        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['statement_index' => 0];
        $statements = self::split_statements($sqlPath);

        $batch = array_slice($statements, $cursor['statement_index'], self::DB_BATCH_STATEMENTS);
        if (!$batch) {
            return true;
        }

        $wpdb->query('START TRANSACTION');
        try {
            foreach ($batch as $statement) {
                if (trim($statement) === '') {
                    continue;
                }
                $result = $wpdb->query($statement);
                if ($result === false) {
                    throw new RuntimeException('Restore statement failed: ' . $wpdb->last_error);
                }
            }
            $wpdb->query('COMMIT');
        } catch (Throwable $e) {
            $wpdb->query('ROLLBACK');
            throw $e;
        }

        $cursor['statement_index'] += count($batch);
        $job['cursor_json'] = wp_json_encode($cursor);
        return $cursor['statement_index'] >= count($statements);
    }

    private static function extract_step(array &$job): bool
    {
        $zipPath = $job['temp_dir'] . '/restore.zip';
        $stagingDir = $job['temp_dir'] . '/extracted';
        if (!is_dir($stagingDir)) {
            wp_mkdir_p($stagingDir);
        }

        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException('Could not open downloaded restore archive.');
        }
        $zip->extractTo($stagingDir);
        $zip->close();

        return true;
    }

    private static function swap_step(array &$job): void
    {
        if ($job['scope'] === 'full') {
            // Wma_File_Archiver zips paths relative to WP_CONTENT_DIR
            // directly, so the staging dir mirrors wp-content's layout
            // with no extra wrapping folder.
            $stagingContent = $job['temp_dir'] . '/extracted';
            foreach (['uploads', 'themes', 'plugins'] as $folder) {
                $source = $stagingContent . '/' . $folder;
                $target = WP_CONTENT_DIR . '/' . $folder;
                if (!is_dir($source)) {
                    continue;
                }
                $backupOld = $target . '-pre-restore-' . $job['job_id'];
                rename($target, $backupOld);
                rename($source, $target);
                self::recursive_delete($backupOld);
            }
        }
        self::recursive_delete($job['temp_dir']);
    }

    private static function split_statements(string $path): array
    {
        $sql = file_get_contents($path);
        return array_filter(array_map('trim', explode(";\n", $sql)));
    }

    private static function recursive_delete(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
