<?php
// public/api/taxonomies.php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/Sites.php';

$user = require_login_api();
$site = require_site_api($user);

$cacheKey = "taxonomies:{$site['id']}:all";
$cached = cache_get($cacheKey);
if ($cached !== null) {
    json_response(['items' => $cached]);
}

if (empty($site['agent_token'])) {
    json_response(['items' => [], 'reason' => 'no_credentials']);
}

$client = site_agent_client_for_site($site);
try {
    $items = $client->listTaxonomies();
} catch (Throwable $e) {
    json_response(['error' => $e->getMessage()], 502);
}

cache_set($cacheKey, $items, TAXONOMIES_CACHE_TTL_SECONDS);
json_response(['items' => $items, 'reason' => empty($items) ? 'none_found' : null]);
