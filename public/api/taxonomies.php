<?php

require_once __DIR__ . '/../../includes/helpers.php';
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
    json_response(['items' => []]);
}

json_response(['items' => $wp->listCustomProductTaxonomies()]);
