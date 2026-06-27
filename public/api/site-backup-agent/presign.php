<?php
// public/api/site-backup-agent/presign.php

require_once __DIR__ . '/../../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../../includes/SiteBackupManager.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

$header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Bearer\s+(.+)$/i', $header, $m)) {
    json_response(['error' => 'Missing or invalid Authorization header'], 401);
}
$token = $m[1];

$body = json_body();
$jobId = (int) ($body['job_id'] ?? 0);
$partKey = (string) ($body['part_key'] ?? '');
$method = (string) ($body['method'] ?? 'PUT');

if ($jobId <= 0 || $partKey === '' || !preg_match('/^[a-zA-Z0-9_-]+$/', $partKey)) {
    json_response(['error' => 'Invalid presign parameters'], 400);
}

$result = SiteBackupManager::presign($token, $jobId, $partKey, $method);
if (!$result['ok']) {
    json_response(['error' => $result['error']], 403);
}

json_response(['url' => $result['url'], 'expires_at' => time() + 900]);
