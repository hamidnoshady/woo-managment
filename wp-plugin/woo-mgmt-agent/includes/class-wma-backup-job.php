<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-backup-job.php

defined('ABSPATH') || exit;

/**
 * Steps: dump_db -> [zip_files if scope=full] -> upload -> done. A 'full'
 * backup has two source files to ship — database.sql ('db') and
 * wp-content.zip ('files') — uploaded one after the other so restore can
 * tell which downloaded bytes belong to which source.
 */
class Wma_Backup_Job
{
    private const PART_BYTES = 5 * 1024 * 1024; // 5MB

    public static function tick(array $job): array
    {
        try {
            switch ($job['step']) {
                case 'dump_db':
                    if (Wma_Db_Dumper::dump_step($job)) {
                        $job['step'] = $job['scope'] === 'full' ? 'zip_files' : 'upload';
                        $job['cursor_json'] = wp_json_encode(['file_index' => 0]);
                    }
                    break;
                case 'zip_files':
                    if (Wma_File_Archiver::zip_step($job)) {
                        $job['step'] = 'upload';
                        $job['cursor_json'] = wp_json_encode(['source_index' => 0, 'byte_offset' => 0, 'part_index' => 0]);
                    }
                    break;
                case 'upload':
                    self::upload_next_part($job);
                    break;
            }

            if ($job['step'] === 'upload' && self::upload_complete($job)) {
                self::cleanup_local_files($job);
                $job['step'] = 'done';
                $job['status'] = 'completed';
            }
        } catch (Throwable $e) {
            $job['status'] = 'failed';
            $job['error'] = $e->getMessage();
        }

        return $job;
    }

    private static function sources(string $scope): array
    {
        return $scope === 'full' ? ['db', 'files'] : ['db'];
    }

    private static function source_path(array $job, string $source): string
    {
        return $source === 'db'
            ? dirname(Wma_File_Archiver::zip_path((int) $job['job_id'])) . '/database.sql'
            : Wma_File_Archiver::zip_path((int) $job['job_id']);
    }

    private static function upload_next_part(array &$job): void
    {
        $sources = self::sources($job['scope']);
        $cursor = json_decode((string) $job['cursor_json'], true)
            ?: ['source_index' => 0, 'byte_offset' => 0, 'part_index' => 0];

        if ($cursor['source_index'] >= count($sources)) {
            return;
        }

        $source = $sources[$cursor['source_index']];
        $sourcePath = self::source_path($job, $source);
        $size = filesize($sourcePath);

        if ($cursor['byte_offset'] >= $size) {
            $job['cursor_json'] = wp_json_encode(['source_index' => $cursor['source_index'] + 1, 'byte_offset' => 0, 'part_index' => 0]);
            return;
        }

        $partKey = $source . '-part-' . str_pad((string) $cursor['part_index'], 5, '0', STR_PAD_LEFT);
        $partPath = sys_get_temp_dir() . '/wma-upload-' . $job['job_id'] . '-' . $partKey;

        $fh = fopen($sourcePath, 'rb');
        fseek($fh, $cursor['byte_offset']);
        $chunk = fread($fh, self::PART_BYTES);
        fclose($fh);
        file_put_contents($partPath, $chunk);

        $presign = Wma_Relay::request_presigned_url((int) $job['job_id'], $partKey, 'PUT');
        if (!$presign['ok']) {
            @unlink($partPath);
            throw new RuntimeException($presign['error']);
        }

        $upload = Wma_Relay::put_file($presign['url'], $partPath);
        if (!$upload['ok']) {
            @unlink($partPath);
            throw new RuntimeException($upload['error']);
        }

        $parts = json_decode((string) $job['parts_json'], true) ?: [];
        $parts[] = ['part_key' => $partKey, 'source' => $source, 'bytes' => strlen($chunk), 'sha256' => hash('sha256', $chunk)];
        $job['parts_json'] = wp_json_encode($parts);

        @unlink($partPath);

        $job['cursor_json'] = wp_json_encode([
            'source_index' => $cursor['source_index'],
            'byte_offset' => $cursor['byte_offset'] + strlen($chunk),
            'part_index' => $cursor['part_index'] + 1,
        ]);
    }

    private static function upload_complete(array $job): bool
    {
        $cursor = json_decode((string) $job['cursor_json'], true) ?: ['source_index' => 0];
        return $cursor['source_index'] >= count(self::sources($job['scope']));
    }

    private static function cleanup_local_files(array $job): void
    {
        $dir = dirname(Wma_File_Archiver::zip_path((int) $job['job_id']));
        if (is_dir($dir)) {
            array_map('unlink', glob($dir . '/*'));
            rmdir($dir);
        }
    }
}
