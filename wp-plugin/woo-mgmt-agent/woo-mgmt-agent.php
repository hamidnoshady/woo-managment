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

Wma_Settings::register();

register_activation_hook(__FILE__, function () {
    Wma_Db::create_table();
});
