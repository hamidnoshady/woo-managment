<?php
// wp-plugin/woo-mgmt-agent/woo-mgmt-agent.php

/**
 * Plugin Name: Woo Management Agent
 * Description: Sole connection method for the woo-managment app: products, categories, custom taxonomies, media, and S3 backup/restore, all over one paired secure channel.
 * Version: 1.1.0
 */

defined('ABSPATH') || exit;

define('WMA_DIR', __DIR__);
// Replaced with the real UPDATE_FEED_TOKEN secret at build time by
// .github/workflows/release-plugin.yml; stays a placeholder in source
// checkouts, which disables self-update (see Wma_Updater::token()).
define('WMA_UPDATE_TOKEN', '{{WMA_UPDATE_TOKEN}}');

require_once WMA_DIR . '/includes/class-wma-db.php';
require_once WMA_DIR . '/includes/class-wma-settings.php';
require_once WMA_DIR . '/includes/class-wma-auth.php';
require_once WMA_DIR . '/includes/class-wma-updater.php';
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
Wma_Updater::register();
Wma_Rest::register();

// wc_get_products() uses WC_Product_Query whose data-store layer only
// passes a whitelist of known args to the underlying WP_Query. Custom
// tax_query clauses (used for ACF/CPT-based product taxonomies) are NOT
// on that whitelist and get silently dropped. This filter bridges the gap
// so that Wma_Products::build_tax_query() actually reaches WP_Query.
add_filter('woocommerce_product_data_store_cpt_get_products_query', function ($wp_query_args, $query_vars) {
    if (!empty($query_vars['tax_query'])) {
        // Merge rather than overwrite: WooCommerce may have already added
        // its own tax_query clauses (e.g. for product_visibility).
        $existing = $wp_query_args['tax_query'] ?? [];
        $wp_query_args['tax_query'] = array_merge($existing, $query_vars['tax_query']);
    }
    return $wp_query_args;
}, 10, 2);

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
