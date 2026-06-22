<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    json_response(['error' => 'Method not allowed'], 405);
}

$wp = wordpress_client_for_site($site);
if ($wp === null) {
    // No WordPress Application Password configured for this site - the
    // UI needs this distinguished from "no ACF taxonomies exist" so it can
    // tell the user what to actually do about it.
    json_response(['items' => [], 'reason' => 'no_credentials']);
}

$items = $wp->listCustomProductTaxonomies();
json_response(['items' => $items, 'reason' => empty($items) ? 'none_found' : null]);
