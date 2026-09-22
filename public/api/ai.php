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

// Shared writing-style guidance used by both the short and long prompts.
$styleGuide = "Writing Style:\n"
    . "- Write in natural Persian.\n"
    . "- Use a professional beauty and cosmetics tone.\n"
    . "- Focus on benefits rather than technical claims.\n"
    . "- Avoid exaggerated marketing language.\n"
    . "- Make the text suitable for an online store product page.\n"
    . "- Use clear headings.\n"
    . '- Emphasize hydration, nourishment, repair, shine, softness, strength, anti-frizz effects, and hair health when relevant.';

if ($action === 'describe_short') {
    $systemPrompt = "You are a professional Persian e-commerce copywriter. Given the product details, write a Short Description for Website:\n"
        . "- Exactly 2 sentences\n"
        . "- 40-60 words total\n"
        . "- Summarize the product, ingredients, and main benefits\n\n"
        . $styleGuide . "\n\n"
        . 'Respond with ONLY the short description as plain text (no markdown, no quotes, no headings).';

    $result = $ai->chat($systemPrompt, $details);
    if (!$result['ok']) {
        json_response(['error' => $result['error']], 502);
    }

    json_response(['short_description' => trim($result['text'])]);
}

if ($action === 'describe_long') {
    $systemPrompt = "You are a professional Persian e-commerce copywriter. Write a professional Persian e-commerce product description for the product using this structure:\n\n"
        . "1. Product Title\n"
        . "   - Product name\n"
        . "   - Main benefit(s)\n\n"
        . "2. Introduction Paragraph (80-120 words)\n"
        . "   - Explain who the product is for\n"
        . "   - Highlight the main problem it solves\n"
        . "   - Mention the most important active ingredients\n"
        . "   - Describe the overall result on the hair\n\n"
        . "3. Features & Benefits\n"
        . "   - 5-8 bullet points\n"
        . "   - Focus on performance and visible results\n\n"
        . "4. Key Ingredients\n"
        . "   - List each important ingredient\n"
        . "   - Explain its role and benefit in 1 sentence\n\n"
        . "5. Results of Use\n"
        . "   - 4-6 bullet points\n"
        . "   - Describe expected improvements after regular use\n\n"
        . $styleGuide . "\n\n"
        . 'Output the description as basic HTML: use <h2>/<h3> for headings, <p> for paragraphs, and <ul>/<li> for bullet lists. '
        . 'No markdown, no surrounding commentary. Respond with ONLY the HTML.';

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
