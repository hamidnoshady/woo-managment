<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);

$cacheKey = 'attribute-suggestions:' . $site['id'];
$cached = cache_get($cacheKey);
if ($cached !== null) {
    json_response(['items' => $cached]);
}

$client = site_agent_client_for_site($site);

try {
    $items = $client->listAttributeSuggestions();
} catch (RuntimeException $e) {
    json_response(['error' => $e->getMessage()], 502);
}

cache_set($cacheKey, $items, 120);

json_response(['items' => $items]);
