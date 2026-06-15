<?php

require_once __DIR__ . '/Settings.php';

/**
 * Minimal client for an OpenAI-compatible chat completions API
 * (e.g. ArvanCloud AI Platform). Used to optionally generate product
 * short/long descriptions. All AI requests are opt-in and only made
 * when the user explicitly asks for them.
 */
class AiClient
{
    private string $baseUrl;
    private string $apiKey;
    private string $model;

    public function __construct(?array $config = null)
    {
        $config = $config ?? get_settings();
        $this->baseUrl = rtrim((string) ($config['ai_base_url'] ?? ''), '/');
        $this->apiKey = (string) ($config['ai_api_key'] ?? '');
        $this->model = (string) ($config['ai_model'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->baseUrl !== '' && $this->model !== '';
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
}
