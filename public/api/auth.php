<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';

start_app_session();

$action = $_GET['action'] ?? '';
$body = json_body();

switch ($action) {
    case 'request-otp':
        $phone = normalize_phone($body['phone'] ?? '');
        if ($phone === '') {
            json_response(['error' => 'Phone number is required.'], 422);
        }

        $result = request_otp($phone);
        if (!$result['ok']) {
            json_response(['error' => $result['error']], 422);
        }

        $_SESSION['otp_phone'] = $phone;
        json_response(['ok' => true]);
        break;

    case 'verify-otp':
        $phone = normalize_phone($body['phone'] ?? ($_SESSION['otp_phone'] ?? ''));
        $code = trim((string) ($body['code'] ?? ''));

        if ($phone === '' || $code === '') {
            json_response(['error' => 'Phone number and code are required.'], 422);
        }

        $result = verify_otp($phone, $code);
        if (!$result['ok']) {
            json_response(['error' => $result['error']], 422);
        }

        unset($_SESSION['otp_phone']);
        json_response([
            'ok' => true,
            'role' => $result['role'],
            'csrf_token' => csrf_token(),
        ]);
        break;

    case 'logout':
        logout_user();
        json_response(['ok' => true]);
        break;

    case 'me':
        $user = current_user();
        if ($user === null) {
            json_response(['authenticated' => false]);
        }
        json_response([
            'authenticated' => true,
            'id' => $user['id'],
            'phone' => $user['phone'],
            'name' => $user['name'],
            'role' => $user['role'],
            'csrf_token' => csrf_token(),
        ]);
        break;

    default:
        json_response(['error' => 'Unknown action'], 400);
}
