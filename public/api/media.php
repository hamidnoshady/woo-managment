<?php

require_once __DIR__ . '/../../includes/helpers.php';
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Sites.php';
require_once __DIR__ . '/../../includes/site_context.php';
require_once __DIR__ . '/../../includes/ImageProcessor.php';

$user = require_login_api();
$site = require_site_api($user);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

verify_csrf_api();

$wp = wordpress_client_for_site($site);
if ($wp === null) {
    json_response(['error' => t('wp_credentials_missing')], 422);
}

if (empty($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) {
    json_response(['error' => 'No image uploaded.'], 422);
}

$file = $_FILES['image'];
$content = file_get_contents($file['tmp_name']);
if ($content === false) {
    json_response(['error' => 'Failed to read uploaded file.'], 500);
}

$mimeType = $file['type'] ?: 'application/octet-stream';
$filename = $file['name'] ?: 'image';

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
if (!in_array($mimeType, $allowedMimes, true)) {
    json_response(['error' => 'Unsupported image type.'], 422);
}

$whiteBg = !empty($_POST['white_bg']) && $_POST['white_bg'] !== '0';
$enhance = !empty($_POST['enhance']) && $_POST['enhance'] !== '0';
$resizeFrame = !empty($_POST['resize_frame']) && $_POST['resize_frame'] !== '0';

if ($whiteBg || $enhance || $resizeFrame) {
    $image = ImageProcessor::load($content);
    if ($image === null) {
        json_response(['error' => 'Could not process this image.'], 422);
    }

    if ($whiteBg) {
        $image = ImageProcessor::addWhiteBackground($image);
    }
    if ($enhance) {
        $image = ImageProcessor::enhanceQuality($image);
    }
    if ($resizeFrame) {
        $image = ImageProcessor::resizeToFrame($image);
    }

    $content = ImageProcessor::toJpeg($image);
    $mimeType = 'image/jpeg';
    $filename = preg_replace('/\.[^.]+$/', '', $filename) . '.jpg';
}

$result = $wp->uploadMedia($content, $filename, $mimeType);

if ($result['status'] < 200 || $result['status'] >= 300) {
    json_response(['error' => $result['data']['message'] ?? 'Failed to upload image'], $result['status'] ?: 502);
}

json_response([
    'item' => [
        'id' => $result['data']['id'] ?? null,
        'src' => $result['data']['source_url'] ?? '',
    ],
]);
