<?php

require_once __DIR__ . '/Database.php';

/**
 * All app-wide settings, stored in the `settings` table and managed from
 * the superadmin Settings panel (Admin → Settings). Defaults below are used
 * until a superadmin saves a value.
 */
const SETTINGS_DEFAULTS = [
    'kavenegar_api_key'    => '',
    'kavenegar_template'   => 'verify',
    'otp_length'           => 5,
    'otp_expiry_seconds'   => 120,
    'otp_max_per_window'   => 3,
    'otp_window_seconds'   => 600,
    'otp_max_verify_attempts' => 5,
    'session_name'         => 'wcpm_session',
    'session_lifetime'     => 28800,
    'superadmin_phones'    => '',
];

/**
 * Field metadata for rendering the settings form: type is 'text', 'password',
 * or 'number'; group is used to organize the form into sections.
 */
const SETTINGS_FIELDS = [
    'kavenegar_api_key' => [
        'label' => 'Kavenegar API key',
        'type' => 'password',
        'group' => 'Kavenegar (SMS OTP)',
        'help' => 'API key from your Kavenegar account, used to send login codes.',
    ],
    'kavenegar_template' => [
        'label' => 'Kavenegar template name',
        'type' => 'text',
        'group' => 'Kavenegar (SMS OTP)',
        'help' => 'Verify Lookup template name configured in your Kavenegar panel.',
    ],
    'otp_length' => [
        'label' => 'OTP code length',
        'type' => 'number',
        'group' => 'Login codes (OTP)',
        'help' => 'Number of digits in each login code.',
    ],
    'otp_expiry_seconds' => [
        'label' => 'OTP expiry (seconds)',
        'type' => 'number',
        'group' => 'Login codes (OTP)',
        'help' => 'How long a login code remains valid.',
    ],
    'otp_max_per_window' => [
        'label' => 'Max OTP requests per window',
        'type' => 'number',
        'group' => 'Login codes (OTP)',
        'help' => 'Rate limit: max codes a phone number can request within the window below.',
    ],
    'otp_window_seconds' => [
        'label' => 'OTP rate limit window (seconds)',
        'type' => 'number',
        'group' => 'Login codes (OTP)',
        'help' => 'Time window used for the rate limit above.',
    ],
    'otp_max_verify_attempts' => [
        'label' => 'Max OTP verification attempts',
        'type' => 'number',
        'group' => 'Login codes (OTP)',
        'help' => 'Lockout: max failed code verification attempts a phone number gets within the rate limit window above before further attempts are blocked.',
    ],
    'session_name' => [
        'label' => 'Session cookie name',
        'type' => 'text',
        'group' => 'Session',
        'help' => 'Name of the PHP session cookie.',
    ],
    'session_lifetime' => [
        'label' => 'Session lifetime (seconds)',
        'type' => 'number',
        'group' => 'Session',
        'help' => 'How long a login session lasts (default 28800 = 8 hours).',
    ],
    'superadmin_phones' => [
        'label' => 'Bootstrap superadmin phone numbers',
        'type' => 'text',
        'group' => 'Superadmins',
        'help' => 'Comma-separated phone numbers (e.g. 09121234567, 09129876543) that are automatically granted the superadmin role the first time they log in.',
    ],
];

/**
 * Returns all settings, merging stored values over the defaults.
 */
function get_settings(): array
{
    $pdo = Database::get();
    $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();

    $settings = SETTINGS_DEFAULTS;
    foreach ($rows as $row) {
        if (array_key_exists($row['key'], $settings)) {
            $settings[$row['key']] = $row['value'];
        }
    }

    return $settings;
}

/**
 * Returns a single setting value (falling back to the default).
 */
function get_setting(string $key)
{
    $settings = get_settings();
    return $settings[$key] ?? (SETTINGS_DEFAULTS[$key] ?? null);
}

/**
 * Saves the given settings (only known keys are stored).
 */
function update_settings(array $data): void
{
    $pdo = Database::get();
    $stmt = $pdo->prepare(
        'INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value'
    );

    foreach ($data as $key => $value) {
        if (!array_key_exists($key, SETTINGS_DEFAULTS)) {
            continue;
        }
        $stmt->execute([$key, (string) $value]);
    }
}

/**
 * Returns the list of bootstrap superadmin phone numbers from settings.
 */
function get_superadmin_phones(): array
{
    $raw = (string) get_setting('superadmin_phones');
    $phones = [];
    foreach (explode(',', $raw) as $phone) {
        $phone = normalize_phone(trim($phone));
        if ($phone !== '') {
            $phones[] = $phone;
        }
    }
    return $phones;
}
