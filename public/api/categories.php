<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WooCommerceClient.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';

$user = require_login_api();
$site = require_site_api($user);
$client = woocommerce_client_for_site($site);

$result = $client->listCategories(['orderby' => 'name', 'order' => 'asc']);

if ($result['status'] < 200 || $result['status'] >= 300) {
    json_response(['error' => $result['data']['message'] ?? 'Failed to fetch categories'], $result['status'] ?: 502);
}

$categories = array_map(function ($cat) {
    return [
        'id' => $cat['id'],
        'name' => $cat['name'],
        'count' => $cat['count'],
        'parent' => $cat['parent'] ?? 0,
    ];
}, is_array($result['data']) ? $result['data'] : []);

json_response(['items' => flatten_category_tree($categories)]);

/**
 * Reorders a flat WooCommerce category list into hierarchical (depth-first,
 * parent-before-children) order, alphabetically within each level, and adds
 * a `depth` field the UI uses to indent child categories under their parent.
 */
function flatten_category_tree(array $categories): array
{
    $byParent = [];
    foreach ($categories as $cat) {
        $byParent[(int) $cat['parent']][] = $cat;
    }

    $result = [];
    $walk = function (int $parentId, int $depth) use (&$walk, &$byParent, &$result) {
        foreach ($byParent[$parentId] ?? [] as $cat) {
            $cat['depth'] = $depth;
            $result[] = $cat;
            $walk((int) $cat['id'], $depth + 1);
        }
    };
    $walk(0, 0);

    // Categories whose parent wasn't in the list (e.g. a hidden/private
    // parent) would otherwise be dropped; append them at the top level.
    $seenIds = array_column($result, 'id');
    foreach ($categories as $cat) {
        if (!in_array($cat['id'], $seenIds, true)) {
            $cat['depth'] = 0;
            $result[] = $cat;
        }
    }

    return $result;
}
