<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/i18n.php';

$lang = (string) ($_GET['lang'] ?? '');
if (in_array($lang, APP_LANGUAGES, true)) {
    set_lang($lang);
}

$redirect = (string) ($_GET['redirect'] ?? '/');
// Only allow same-site, root-relative redirects.
if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')) {
    $redirect = '/';
}

header('Location: ' . $redirect);
exit;
