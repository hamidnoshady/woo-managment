<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/WebPush.php';

$user = require_login_api();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    json_response(['public_key' => get_vapid_public_key()]);
}

verify_csrf_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$body = json_body();
$action = (string) ($body['action'] ?? 'subscribe');

if ($action === 'unsubscribe') {
    remove_push_subscription((string) ($body['endpoint'] ?? ''));
    json_response(['ok' => true]);
}

$endpoint = (string) ($body['endpoint'] ?? '');
$keys = $body['keys'] ?? [];
$p256dh = (string) ($keys['p256dh'] ?? '');
$auth = (string) ($keys['auth'] ?? '');

if ($endpoint === '' || $p256dh === '' || $auth === '') {
    json_response(['error' => 'Invalid subscription'], 422);
}

add_push_subscription((int) $user['id'], $endpoint, $p256dh, $auth);
json_response(['ok' => true]);
