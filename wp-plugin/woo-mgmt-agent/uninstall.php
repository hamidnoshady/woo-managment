<?php
// wp-plugin/woo-mgmt-agent/uninstall.php

defined('WP_UNINSTALL_PLUGIN') || exit;

global $wpdb;
$wpdb->query('DROP TABLE IF EXISTS ' . $wpdb->prefix . 'wma_jobs');

delete_option('wma_token');
delete_option('wma_base_url');
wp_clear_scheduled_hook('wma_tick_running_jobs');
