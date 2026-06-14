<?php
/**
 * Copy this file to config.php and fill in your real values.
 * config.php is gitignored and must NEVER be committed.
 *
 * WooCommerce site credentials and user accounts are now managed inside the
 * app (database-backed) rather than here. This file only needs SMS OTP
 * settings and a bootstrap list of superadmin phone numbers.
 */

return [
    // Kavenegar SMS OTP login (https://kavenegar.com)
    'kavenegar' => [
        'api_key'  => 'YOUR_KAVENEGAR_API_KEY',
        // Template name created in Kavenegar panel for OTP messages.
        // The template should accept one token: %token%
        'template' => 'verify',
    ],

    // Bootstrap superadmin(s): phone numbers (digits only, e.g. 09121234567)
    // that are automatically granted the 'superadmin' role the first time
    // they log in. Superadmins can create/manage other admin and shop
    // manager accounts and add WooCommerce sites from within the app.
    'superadmins' => [
        '09120000000',
    ],

    // OTP settings
    'otp' => [
        'length'           => 5,
        'expiry_seconds'   => 120,
        'max_per_window'   => 3,
        'window_seconds'   => 600,
    ],

    // Session
    'session' => [
        'name'     => 'wcpm_session',
        'lifetime' => 60 * 60 * 8, // 8 hours
    ],
];
