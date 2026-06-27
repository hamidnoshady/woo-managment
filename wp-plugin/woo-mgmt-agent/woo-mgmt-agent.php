<?php
// wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php

/**
 * Plugin Name: Woo Management Agent
 * Description: Sole connection method for the woo-managment app: products, categories, custom taxonomies, media, and S3 backup/restore, all over one paired secure channel.
 * Version: 1.0.0
 */

defined('ABSPATH') || exit;

define('WMA_DIR', __DIR__);

require_once WMA_DIR . '/includes/class-wma-db.php';
require_once WMA_DIR . '/includes/class-wma-settings.php';
require_once WMA_DIR . '/includes/class-wma-auth.php';
require_once WMA_DIR . '/includes/class-wma-relay.php';
require_once WMA_DIR . '/includes/class-wma-db-dumper.php';
require_once WMA_DIR . '/includes/class-wma-file-archiver.php';
require_once WMA_DIR . '/includes/class-wma-backup-job.php';
require_once WMA_DIR . '/includes/class-wma-restore-job.php';
require_once WMA_DIR . '/includes/class-wma-products.php';
require_once WMA_DIR . '/includes/class-wma-taxonomies.php';
require_once WMA_DIR . '/includes/class-wma-media.php';
require_once WMA_DIR . '/includes/class-wma-rest.php';

Wma_Settings::register();
Wma_Rest::register();

register_activation_hook(__FILE__, function () {
    Wma_Db::create_table();
    if (!wp_next_scheduled('wma_tick_running_jobs')) {
        wp_schedule_event(time(), 'hourly', 'wma_tick_running_jobs');
    }
});

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('wma_tick_running_jobs');
});

add_action('wma_tick_running_jobs', function () {
    global $wpdb;
    $table = Wma_Db::table();
    $ids = $wpdb->get_col("SELECT job_id FROM {$table} WHERE status = 'running'");
    foreach ($ids as $jobId) {
        $job = Wma_Db::get_job((int) $jobId);
        if ($job === null) {
            continue;
        }
        $job = $job['kind'] === 'backup' ? Wma_Backup_Job::tick($job) : Wma_Restore_Job::tick($job);
        Wma_Db::save_job($job);
    }
});
