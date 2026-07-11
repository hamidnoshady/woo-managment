<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/i18n.php';
require_once __DIR__ . '/../../includes/ActivityLog.php';

$currentUser = require_superadmin_api();
$method = $_SERVER['REQUEST_METHOD'];

/**
 * Maps a SETTINGS_FIELDS group identifier (English label as currently stored
 * in Settings.php) to the corresponding i18n group_* translation key.
 */
function settings_group_key(string $group): string
{
    $map = [
        'Kavenegar (SMS OTP)' => 'group_kavenegar',
        'Login codes (OTP)'   => 'group_otp',
        'Session'             => 'group_session',
        'Superadmins'         => 'group_superadmins',
        'AI (OpenRouter)'     => 'group_ai',
        'Backups (S3)'        => 'group_backup',
        'DirectAdmin / JetBackup' => 'group_jetbackup',
        'Kavenegar (SMS notifications)' => 'group_kavenegar_sms',
    ];

    return $map[$group] ?? $group;
}

/**
 * Returns a field's metadata with label/help/group translated via i18n,
 * falling back to the original English text from SETTINGS_FIELDS.
 */
function translate_field_meta(string $key, array $meta): array
{
    // Some SETTINGS_FIELDS keys don't match the i18n key naming 1:1.
    $i18nKeyMap = [
        'otp_expiry_seconds' => 'otp_expiry',
        'otp_window_seconds' => 'otp_window',
    ];
    $key = $i18nKeyMap[$key] ?? $key;

    $labelKey = $key . '_label';
    $meta['label'] = t($labelKey) !== $labelKey ? t($labelKey) : $meta['label'];

    $helpKey = $key . '_help';
    $meta['help'] = t($helpKey) !== $helpKey ? t($helpKey) : ($meta['help'] ?? '');

    $groupKey = settings_group_key($meta['group']);
    $meta['group'] = t($groupKey) !== $groupKey ? t($groupKey) : $meta['group'];

    return $meta;
}

if ($method === 'GET') {
    $settings = get_settings();
    $fields = [];
    foreach (SETTINGS_FIELDS as $key => $meta) {
        $fields[] = array_merge(translate_field_meta($key, $meta), [
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
    log_activity($currentUser, null, 'system', 'settings_update', 'log_settings_updated');
    json_response(['fields' => array_map(
        fn($key, $meta) => array_merge(translate_field_meta($key, $meta), ['key' => $key, 'value' => get_setting($key)]),
        array_keys(SETTINGS_FIELDS),
        SETTINGS_FIELDS
    )]);
}

json_response(['error' => 'Method not allowed'], 405);
