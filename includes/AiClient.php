<?php

require_once __DIR__ . '/Settings.php';

/**
 * Minimal client for an OpenAI-compatible chat completions API
 * (defaults to OpenRouter, https://openrouter.ai). Used to optionally
 * generate product short/long descriptions, and to optionally edit
 * product photos via a separate image-output-capable model. All AI
 * requests are opt-in and only made when the user explicitly asks for them.
 */
class AiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;
    private string $imageModel;

    public function __construct(?array $config = null)
    {
        $config = $config ?? get_settings();
        $this->baseUrl = rtrim((string) ($config['ai_base_url'] ?? ''), '/');
        $this->apiKey = (string) ($config['ai_api_key'] ?? '');
        $this->model = (string) ($config['ai_model'] ?? '');
        $this->imageModel = (string) ($config['ai_image_model'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '' && $this->model !== '';
    }

    /**
     * Whether a separate image-output-capable model is configured for AI
     * photo editing (independent of the text model above).
     */
    public function isImageConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '' && $this->imageModel !== '';
    }

    /**
     * Sends a chat completion request and returns the assistant's text reply.
     *
     * @return array{ok: bool, text: string, error: string}
     */
    public function chat(string $systemPrompt, string $userPrompt): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'text' => '', 'error' => 'AI is not configured.'];
        }

        $payload = [
            'model' => $this->model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
            'temperature' => 0.7,
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'text' => '', 'error' => 'Connection error: ' . $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? 'AI request failed.';
            return ['ok' => false, 'text' => '', 'error' => $message];
        }

        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            return ['ok' => false, 'text' => '', 'error' => 'AI returned an empty response.'];
        }

        return ['ok' => true, 'text' => $text, 'error' => ''];
    }

    /**
     * Sends a product photo to the configured image model with an editing
     * instruction (e.g. "clean up for an e-commerce listing") and returns
     * the edited image bytes. Requires a model that supports image output
     * (most chat models only understand images, they don't return them) -
     * see the "Image model" setting's help text for an example. Uses the
     * modalities + message.images request/response shape OpenRouter's
     * image-output models use.
     *
     * @return array{ok: bool, content: string, mime_type: string, error: string}
     */
    public function editImage(string $imageContent, string $mimeType, string $instruction = ''): array
    {
        if (!$this->isImageConfigured()) {
            return ['ok' => false, 'content' => '', 'mime_type' => '', 'error' => 'AI image editing is not configured.'];
        }

        $dataUrl = 'data:' . $mimeType . ';base64,' . base64_encode($imageContent);
        $prompt = $instruction !== '' ? $instruction
            : 'Edit this product photo for an e-commerce listing: place the product on a clean plain white background, well-lit and sharp, no added text or watermarks. Return only the edited image.';

        $payload = [
            'model' => $this->imageModel,
            'modalities' => ['image', 'text'],
            'messages' => [
                [
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $prompt],
                        ['type' => 'image_url', 'image_url' => ['url' => $dataUrl]],
                    ],
                ],
            ],
        ];

        $ch = curl_init($this->baseUrl . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'content' => '', 'mime_type' => '', 'error' => 'Connection error: ' . $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $data = json_decode($response, true);

        if ($status < 200 || $status >= 300) {
            $message = $data['error']['message'] ?? $data['message'] ?? 'AI image request failed.';
            return ['ok' => false, 'content' => '', 'mime_type' => '', 'error' => $message];
        }

        $images = $data['choices'][0]['message']['images'] ?? [];
        $imageUrl = is_array($images) && isset($images[0]['image_url']['url']) ? $images[0]['image_url']['url'] : null;

        if (!is_string($imageUrl) || !str_starts_with($imageUrl, 'data:')) {
            return ['ok' => false, 'content' => '', 'mime_type' => '', 'error' => 'The configured image model did not return an edited image. Make sure it supports image output.'];
        }

        [$meta, $base64] = array_pad(explode(',', $imageUrl, 2), 2, '');
        $mime = 'image/png';
        if (preg_match('/^data:([^;]+);base64$/', $meta, $m)) {
            $mime = $m[1];
        }

        $decoded = base64_decode($base64, true);
        if ($decoded === false || $decoded === '') {
            return ['ok' => false, 'content' => '', 'mime_type' => '', 'error' => 'AI returned an invalid image.'];
        }

        return ['ok' => true, 'content' => $decoded, 'mime_type' => $mime, 'error' => ''];
    }
}
