<?php

require_once __DIR__ . '/../../includes/helpers.php';
install_json_fatal_handler();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../includes/Settings.php';
require_once __DIR__ . '/../../includes/AiClient.php';
require_once __DIR__ . '/../../includes/i18n.php';

$user = require_login_api();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['error' => 'Method not allowed'], 405);
}

verify_csrf_api();

$ai = new AiClient();
if (!$ai->isConfigured()) {
    json_response(['error' => t('ai_not_configured')], 422);
}

$body = json_body();
$action = $_GET['action'] ?? '';

// 'describe' (legacy) generates both fields in one call; 'describe_short'
// and 'describe_long' generate just one field, so each has its own
// regenerate button in the UI without touching the other field.
if (!in_array($action, ['describe', 'describe_short', 'describe_long'], true)) {
    json_response(['error' => 'Unknown action'], 422);
}

$name = trim((string) ($body['name'] ?? ''));
if ($name === '') {
    json_response(['error' => 'Product name is required.'], 422);
}

$categories = is_array($body['categories'] ?? null) ? $body['categories'] : [];
$attributes = is_array($body['attributes'] ?? null) ? $body['attributes'] : [];

$lang = current_lang() === 'fa' ? 'Persian (Farsi)' : 'English';

$details = "Product name: {$name}\n";
if (!empty($categories)) {
    $details .= 'Categories: ' . implode(', ', $categories) . "\n";
}
foreach ($attributes as $label => $value) {
    if ($value === '' || $value === null) {
        continue;
    }
    $details .= "{$label}: {$value}\n";
}

if ($action === 'describe_short') {
    $systemPrompt = "You are an e-commerce copywriter. Given product details, write a short product summary in {$lang}: "
        . '1-2 sentences, plain text, no markdown, no quotes around it. Respond with ONLY the summary text and nothing else.';

    $result = $ai->chat($systemPrompt, $details);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['short_description' => trim($result['text'])]);
}

if ($action === 'describe_long') {
    $systemPrompt = "You are an e-commerce copywriter. Given product details, write a long product description in {$lang}: "
        . '3-5 paragraphs as basic HTML using <p> tags, no markdown, no surrounding commentary. Respond with ONLY the HTML.';

    $result = $ai->chat($systemPrompt, $details);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['description' => trim($result['text'])]);
}

// Legacy combined action, kept for compatibility.
$systemPrompt = "You are an e-commerce copywriter. Given product details, write marketing copy in {$lang}. "
    . 'Respond with ONLY a JSON object with two keys: "short_description" (1-2 sentences, plain text) '
    . 'and "description" (3-5 paragraphs as basic HTML using <p> tags, no markdown). Do not include any other text.';

$result = $ai->chat($systemPrompt, $details);

if (!$result['ok']) {
    json_response(['error' => $result['error']], 502);
}

$text = trim($result['text']);
// Some models wrap JSON in a markdown code fence; strip it if present.
$text = preg_replace('/^```(?:json)?\s*|\s*```$/', '', $text);

$parsed = json_decode($text, true);
if (!is_array($parsed) || !isset($parsed['short_description']) || !isset($parsed['description'])) {
    json_response(['error' => t('ai_invalid_response')], 502);
}

json_response([
    'short_description' => (string) $parsed['short_description'],
    'description' => (string) $parsed['description'],
]);
