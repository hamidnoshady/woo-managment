<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Settings.php';

require_superadmin_api();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $settings = get_settings();
    $fields = [];
    foreach (SETTINGS_FIELDS as $key => $meta) {
        $fields[] = array_merge($meta, [
            'key'   => $key,
            'value' => $settings[$key],
        ]);
    }
    json_response(['fields' => $fields]);
}

if ($method === 'PUT') {
    verify_csrf_api();
    $body = json_body();

    $data = [];
    foreach (SETTINGS_FIELDS as $key => $meta) {
        if (!array_key_exists($key, $body)) {
            continue;
        }

        $value = $body[$key];

        if ($meta['type'] === 'password' && trim((string) $value) === '') {
            // Keep the existing value when a password-type field is left blank.
            continue;
        }

        if ($meta['type'] === 'number') {
            $value = (int) $value;
        } else {
            $value = trim((string) $value);
        }

        $data[$key] = $value;
    }

    update_settings($data);
    json_response(['fields' => array_map(
        fn($key, $meta) => array_merge($meta, ['key' => $key, 'value' => get_setting($key)]),
        array_keys(SETTINGS_FIELDS),
        SETTINGS_FIELDS
    )]);
}

json_response(['error' => 'Method not allowed'], 405);
