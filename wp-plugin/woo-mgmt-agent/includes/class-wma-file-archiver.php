<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-file-archiver.php

defined('ABSPATH') || exit;

class Wma_File_Archiver
{
    private const BATCH_FILES = 50;
    private const EXCLUDE_DIRS = ['cache', 'wma-restore-staging'];

    public static function zip_step(array &$job): bool
    {
        $cursor = json_decode((string) $job['cursor_json'], true) ?: [];
        $fileIndex = $cursor['file_index'] ?? 0;

        $files = self::list_files();
        if ($fileIndex >= count($files)) {
            return true;
        }

        $zipPath = self::zip_path((int) $job['job_id']);
        wp_mkdir_p(dirname($zipPath));
        $zip = new ZipArchive();
        $result = $zip->open($zipPath, ZipArchive::CREATE);
        if ($result !== true) {
            throw new RuntimeException('Could not open the backup archive for writing.');
        }

        $batch = array_slice($files, $fileIndex, self::BATCH_FILES);
        foreach ($batch as $absolute => $relative) {
            $zip->addFile($absolute, $relative);
        }
        $zip->close();

        $cursor['file_index'] = $fileIndex + count($batch);
        $job['cursor_json'] = wp_json_encode($cursor);

        return $cursor['file_index'] >= count($files);
    }

    public static function zip_path(int $job_id): string
    {
        return trailingslashit(get_temp_dir()) . 'wma-' . $job_id . '/wp-content.zip';
    }

    /** @return array<string, string> absolute path => zip-relative path */
    private static function list_files(): array
    {
        $root = realpath(WP_CONTENT_DIR);
        $files = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isDir()) {
                continue;
            }
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $segments = explode('/', $relative);
            if (array_intersect($segments, self::EXCLUDE_DIRS)) {
                continue;
            }
            $files[$file->getPathname()] = $relative;
        }
        ksort($files);
        return $files;
    }
}
