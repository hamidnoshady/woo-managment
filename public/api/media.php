<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
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

$client = site_agent_client_for_site($site);

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
$aiEdit = !empty($_POST['ai_edit']) && $_POST['ai_edit'] !== '0';

// Image edits rely on the GD extension; without it, fail with a clear
// error instead of an uncaught fatal (which would surface to the browser
// as an opaque 500 with no JSON body).
if (($whiteBg || $enhance || $resizeFrame) && !extension_loaded('gd')) {
    json_response(['error' => 'Image editing is unavailable on this server (the GD PHP extension is not installed). Upload without edit options, or ask your host to enable GD.'], 500);
}

try {
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

    if ($aiEdit) {
        require_once __DIR__ . '/../../includes/AiClient.php';
        $ai = new AiClient();
        if (!$ai->isImageConfigured()) {
            json_response(['error' => t('ai_image_not_configured')], 422);
        }
        $aiResult = $ai->editImage($content, $mimeType, (string) ($_POST['ai_instruction'] ?? ''));
        if (!$aiResult['ok']) {
            json_response(['error' => $aiResult['error']], 502);
        }
        $content = $aiResult['content'];
        $mimeType = $aiResult['mime_type'];
        $filename = preg_replace('/\.[^.]+$/', '', $filename) . '.jpg';
    }
} catch (\Throwable $e) {
    json_response(['error' => 'Failed to process the image: ' . $e->getMessage()], 500);
}

try {
    $result = $client->uploadMedia($content, $filename, $mimeType);
} catch (\Throwable $e) {
    json_response(['error' => 'Failed to upload the image: ' . $e->getMessage()], 500);
}

json_response([
    'item' => [
        'id' => $result['id'] ?? null,
        'src' => $result['src'] ?? '',
    ],
]);
