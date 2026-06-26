<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-db.php

defined('ABSPATH') || exit;

/**
 * One row per backup/restore job. `job_id` is assigned by woo-managment
 * (the primary key of its own site_backups/site_restores row, offset for
 * restores — see SiteBackupManager::agentJobId() on the app side) rather
 * than auto-incremented here, so both sides agree on identity.
 */
class Wma_Db
{
    public static function table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'wma_jobs';
    }

    public static function create_table(): void
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $table = self::table();
        $charset = $wpdb->get_charset_collate();

        dbDelta("CREATE TABLE {$table} (
            job_id BIGINT UNSIGNED NOT NULL,
            kind VARCHAR(20) NOT NULL,
            scope VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'running',
            step VARCHAR(30) NOT NULL DEFAULT 'start',
            cursor_json LONGTEXT NULL,
            parts_json LONGTEXT NULL,
            temp_dir VARCHAR(255) NULL,
            safety_job_id BIGINT UNSIGNED NULL,
            error TEXT NULL,
            created_at INT NOT NULL,
            updated_at INT NOT NULL,
            PRIMARY KEY  (job_id)
        ) {$charset};");
    }

    public static function create_job(int $job_id, string $kind, string $scope, array $parts = []): array
    {
        global $wpdb;
        $now = time();
        $job = [
            'job_id' => $job_id,
            'kind' => $kind,
            'scope' => $scope,
            'status' => 'running',
            'step' => $kind === 'restore' ? 'safety_backup' : 'dump_db',
            'cursor_json' => wp_json_encode(['table_index' => 0, 'offset' => 0, 'file_index' => 0]),
            'parts_json' => wp_json_encode($kind === 'restore' ? $parts : []),
            'temp_dir' => '',
            'safety_job_id' => null,
            'error' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $wpdb->insert(self::table(), $job);
        return $job;
    }

    public static function get_job(int $job_id): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare('SELECT * FROM ' . self::table() . ' WHERE job_id = %d', $job_id),
            ARRAY_A
        );
        return $row ?: null;
    }

    public static function save_job(array $job): void
    {
        global $wpdb;
        $job['updated_at'] = time();
        $wpdb->update(self::table(), $job, ['job_id' => $job['job_id']]);
    }

    public static function delete_job(int $job_id): void
    {
        global $wpdb;
        $wpdb->delete(self::table(), ['job_id' => $job_id]);
    }
}
