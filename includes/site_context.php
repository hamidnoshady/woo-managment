<?php

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/Sites.php';

/**
 * Site selection: the currently managed WooCommerce site is stored in the
 * session so product-related API calls don't need to repeat credentials.
 */

/**
 * Sets the active site for the current session after checking access.
 * Returns the site row, or null if the user doesn't have access.
 */
function set_current_site(array $user, int $siteId): ?array
{
    if (!user_can_access_site($user, $siteId)) {
        return null;
    }

    start_app_session();
    $_SESSION['site_id'] = $siteId;

    return get_site($siteId);
}

/**
 * Returns the currently selected site for the user, or null if none is
 * selected or the user no longer has access to it.
 */
function get_current_site(array $user): ?array
{
    start_app_session();

    $siteId = $_SESSION['site_id'] ?? null;
    if ($siteId === null) {
        return null;
    }

    if (!user_can_access_site($user, (int) $siteId)) {
        return null;
    }

    return get_site((int) $siteId);
}

/**
 * For HTML pages: returns the active site, redirecting to the site picker
 * if none is selected yet.
 */
function require_site_page(array $user): array
{
    $site = get_current_site($user);
    if ($site === null) {
        header('Location: /sites.php');
        exit;
    }
    return $site;
}

/**
 * For API endpoints: returns the active site, or sends a JSON error if
 * none is selected.
 */
function require_site_api(array $user): array
{
    $site = get_current_site($user);
    if ($site === null) {
        json_response(['error' => 'No site selected.'], 409);
    }
    return $site;
}
