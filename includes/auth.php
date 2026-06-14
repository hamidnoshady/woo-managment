<?php

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Users.php';

/**
 * Starts (or resumes) the PHP session using the configured session name/lifetime.
 */
function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $config = app_config();
    $sessionConfig = $config['session'] ?? [];

    session_name($sessionConfig['name'] ?? 'wcpm_session');
    session_set_cookie_params([
        'lifetime' => $sessionConfig['lifetime'] ?? 28800,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

/**
 * Returns the logged-in user (['id' => ..., 'phone' => ..., 'name' => ..., 'role' => ...]) or null.
 */
function current_user(): ?array
{
    start_app_session();

    if (empty($_SESSION['user_id'])) {
        return null;
    }

    $user = find_user_by_id((int) $_SESSION['user_id']);
    if ($user === null) {
        return null;
    }

    return [
        'id'    => (int) $user['id'],
        'phone' => $user['phone'],
        'name'  => $user['name'],
        'role'  => $user['role'],
    ];
}

/**
 * Redirects to the login page if no user is logged in (for HTML pages).
 */
function require_login_page(): array
{
    $user = current_user();
    if ($user === null) {
        header('Location: /login.php');
        exit;
    }
    return $user;
}

/**
 * Redirects to the products page if the logged-in user is not a superadmin (for HTML pages).
 */
function require_superadmin_page(): array
{
    $user = require_login_page();
    if ($user['role'] !== 'superadmin') {
        header('Location: /products.php');
        exit;
    }
    return $user;
}

/**
 * Returns a JSON 401 error if no user is logged in (for API endpoints).
 */
function require_login_api(): array
{
    $user = current_user();
    if ($user === null) {
        json_response(['error' => 'Not authenticated'], 401);
    }
    return $user;
}

/**
 * Returns a JSON 403 error if the current user's role is not in $roles.
 */
function require_role_api(array $roles): array
{
    $user = require_login_api();
    if (!in_array($user['role'], $roles, true)) {
        json_response(['error' => 'Forbidden'], 403);
    }
    return $user;
}

/**
 * Returns a JSON 403 error if the current user is not a superadmin.
 */
function require_superadmin_api(): array
{
    return require_role_api(['superadmin']);
}

/**
 * Returns the CSRF token for the current session, generating one if needed.
 */
function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates the X-CSRF-Token header against the session token. Sends a JSON 403 on failure.
 */
function verify_csrf_api(): void
{
    start_app_session();
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $expected = $_SESSION['csrf_token'] ?? '';

    if ($expected === '' || !hash_equals($expected, $sent)) {
        json_response(['error' => 'Invalid CSRF token'], 403);
    }
}

/**
 * Generates and stores an OTP code for the given phone, applying rate limiting.
 * Returns ['ok' => true] or ['ok' => false, 'error' => string].
 */
function request_otp(string $phone): array
{
    $config = app_config();

    if (bootstrap_user_by_phone($phone) === null) {
        // Avoid revealing whether a number is registered.
        return ['ok' => false, 'error' => 'This phone number is not authorized.'];
    }

    $otpConfig = $config['otp'] ?? [];
    $windowSeconds = $otpConfig['window_seconds'] ?? 600;
    $maxPerWindow = $otpConfig['max_per_window'] ?? 3;
    $length = $otpConfig['length'] ?? 5;
    $expirySeconds = $otpConfig['expiry_seconds'] ?? 120;

    $pdo = Database::get();
    $now = time();

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM otp_codes WHERE phone = ? AND created_at > ?');
    $stmt->execute([$phone, $now - $windowSeconds]);
    if ((int) $stmt->fetchColumn() >= $maxPerWindow) {
        return ['ok' => false, 'error' => 'Too many requests. Please try again later.'];
    }

    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= (string) random_int(0, 9);
    }

    $stmt = $pdo->prepare(
        'INSERT INTO otp_codes (phone, code, created_at, expires_at, consumed) VALUES (?, ?, ?, ?, 0)'
    );
    $stmt->execute([$phone, $code, $now, $now + $expirySeconds]);

    $sent = send_kavenegar_otp($phone, $code);
    if (!$sent['ok']) {
        return ['ok' => false, 'error' => $sent['error'] ?? 'Failed to send verification code.'];
    }

    return ['ok' => true];
}

/**
 * Verifies an OTP code for a phone number and, if valid, logs the user in.
 */
function verify_otp(string $phone, string $code): array
{
    $user = bootstrap_user_by_phone($phone);
    if ($user === null) {
        return ['ok' => false, 'error' => 'This phone number is not authorized.'];
    }

    $pdo = Database::get();
    $now = time();

    $stmt = $pdo->prepare(
        'SELECT id FROM otp_codes WHERE phone = ? AND code = ? AND consumed = 0 AND expires_at >= ? ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$phone, $code, $now]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['ok' => false, 'error' => 'Invalid or expired code.'];
    }

    $update = $pdo->prepare('UPDATE otp_codes SET consumed = 1 WHERE id = ?');
    $update->execute([$row['id']]);

    start_app_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    unset($_SESSION['site_id']);

    return ['ok' => true, 'role' => $user['role']];
}

/**
 * Sends an OTP SMS via Kavenegar's Verify Lookup API.
 */
function send_kavenegar_otp(string $phone, string $code): array
{
    $config = app_config();
    $kavenegar = $config['kavenegar'] ?? [];

    $apiKey = $kavenegar['api_key'] ?? '';
    $template = $kavenegar['template'] ?? 'verify';

    if ($apiKey === '' || $apiKey === 'YOUR_KAVENEGAR_API_KEY') {
        return ['ok' => false, 'error' => 'Kavenegar API key is not configured.'];
    }

    $url = sprintf(
        'https://api.kavenegar.com/v1/%s/verify/lookup.json',
        rawurlencode($apiKey)
    );

    $params = [
        'receptor' => $phone,
        'token'    => $code,
        'template' => $template,
    ];

    $ch = curl_init($url . '?' . http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => 'SMS provider error: ' . $curlError];
    }

    $data = json_decode($response, true);
    $status = $data['return']['status'] ?? 0;

    if ($httpCode !== 200 || $status !== 200) {
        $message = $data['return']['message'] ?? 'Unknown error from SMS provider.';
        return ['ok' => false, 'error' => $message];
    }

    return ['ok' => true];
}

/**
 * Logs the current user out by destroying the session.
 */
function logout_user(): void
{
    start_app_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}
