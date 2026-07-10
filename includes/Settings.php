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
    'ai_base_url'          => 'https://openrouter.ai/api/v1',
    'ai_api_key'           => '',
    'ai_model'             => '',
    'ai_image_model'       => '',
    'backup_cron_token'    => '',
    's3_endpoint'          => '',
    's3_region'            => '',
    's3_bucket'            => '',
    's3_access_key'        => '',
    's3_secret_key'        => '',
    'backup_retention_days' => 30,
    'da_api_url'           => '',
    'da_admin_username'    => '',
    'da_login_key'         => '',
    'da_self_username'     => '',
    'kavenegar_sms_api_key' => '',
    'kavenegar_sms_sender'  => '',
    'vapid_public_key'      => '',
    'vapid_private_key_pem' => '',
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
    'ai_base_url' => [
        'label' => 'AI API base URL',
        'type' => 'text',
        'group' => 'AI (OpenRouter)',
        'help' => 'Base URL of an OpenAI-compatible chat completions API. Defaults to OpenRouter (https://openrouter.ai/api/v1).',
    ],
    'ai_api_key' => [
        'label' => 'AI API key',
        'type' => 'password',
        'group' => 'AI (OpenRouter)',
        'help' => 'Your OpenRouter API key (https://openrouter.ai/keys). Leave empty to disable AI features.',
    ],
    'ai_model' => [
        'label' => 'Text model (descriptions)',
        'type' => 'text',
        'group' => 'AI (OpenRouter)',
        'help' => 'Model used to generate short and long product descriptions, e.g. "openai/gpt-4o-mini" or "anthropic/claude-3.5-haiku". See openrouter.ai/models.',
    ],
    'ai_image_model' => [
        'label' => 'Image model (photo editing)',
        'type' => 'text',
        'group' => 'AI (OpenRouter)',
        'help' => 'Optional. A separate, image-output-capable model used for the "Edit with AI" product photo option, e.g. "google/gemini-2.5-flash-image-preview". Leave empty to disable AI image editing (the basic white-background/enhance/resize options still work without this).',
    ],
    's3_endpoint' => [
        'label' => 'S3 endpoint URL',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Base URL of your S3-compatible storage, e.g. https://s3.us-east-1.amazonaws.com or a custom/self-hosted endpoint.',
    ],
    's3_region' => [
        'label' => 'S3 region',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Region name required for request signing, e.g. us-east-1 (use any value your provider expects if it is not AWS).',
    ],
    's3_bucket' => [
        'label' => 'S3 bucket name',
        'type' => 'text',
        'group' => 'Backups (S3)',
        'help' => 'Bucket backups are uploaded to. Create it with your storage provider first.',
    ],
    's3_access_key' => [
        'label' => 'S3 access key',
        'type' => 'password',
        'group' => 'Backups (S3)',
        'help' => 'Access key ID for the bucket above.',
    ],
    's3_secret_key' => [
        'label' => 'S3 secret key',
        'type' => 'password',
        'group' => 'Backups (S3)',
        'help' => 'Secret access key for the bucket above.',
    ],
    'backup_retention_days' => [
        'label' => 'Backup retention (days)',
        'type' => 'number',
        'group' => 'Backups (S3)',
        'help' => 'Backups older than this are deleted from S3 automatically after each run.',
    ],
    'da_api_url' => [
        'label' => 'DirectAdmin API URL',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'Base URL of this server\'s DirectAdmin panel, e.g. https://server.example.com:2222.',
    ],
    'da_admin_username' => [
        'label' => 'DirectAdmin admin/reseller username',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'A DirectAdmin account (admin or reseller level) used to list hosting accounts and trigger JetBackup jobs via its Login Key.',
    ],
    'da_login_key' => [
        'label' => 'DirectAdmin Login Key',
        'type' => 'password',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'A Login Key generated under DirectAdmin -> Login Keys for the account above.',
    ],
    'da_self_username' => [
        'label' => 'This app\'s own DirectAdmin username',
        'type' => 'text',
        'group' => 'DirectAdmin / JetBackup',
        'help' => 'The DirectAdmin account woo-managment itself runs under, used only for its own "Run backup now" button on Admin -> Backups.',
    ],
    'kavenegar_sms_api_key' => [
        'label' => 'Kavenegar SMS API key',
        'type' => 'password',
        'group' => 'Kavenegar (SMS notifications)',
        'help' => 'A separate Kavenegar account/token used to send task notification SMS (distinct from the login-code API key above).',
    ],
    'kavenegar_sms_sender' => [
        'label' => 'Kavenegar sender line number',
        'type' => 'text',
        'group' => 'Kavenegar (SMS notifications)',
        'help' => 'Sender line number configured in your Kavenegar panel, required by the plain Send API.',
    ],
];

/**
 * Returns all settings, merging stored values over the defaults.
 */
function get_settings(bool $forceReload = false): array
{
    static $cached = null;
    if ($forceReload) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }

    $pdo = Database::get();
    $rows = $pdo->query('SELECT `key`, value FROM settings')->fetchAll();

    $settings = SETTINGS_DEFAULTS;
    foreach ($rows as $row) {
        if (array_key_exists($row['key'], $settings)) {
            $settings[$row['key']] = $row['value'];
        }
    }

    $cached = $settings;
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
        'INSERT INTO settings (`key`, value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE value = VALUES(value)'
    );

    foreach ($data as $key => $value) {
        if (!array_key_exists($key, SETTINGS_DEFAULTS)) {
            continue;
        }
        $stmt->execute([$key, (string) $value]);
    }

    get_settings(true);
}

/**
 * Returns true if AI features are configured (an API key is set).
 */
function ai_is_configured(): bool
{
    return trim((string) get_setting('ai_api_key')) !== '';
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
