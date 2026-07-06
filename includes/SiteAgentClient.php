<?php
// includes/SiteAgentClient.php

/**
 * The single HTTP client for everything woo-managment does against a
 * managed site: products, categories, taxonomies, media, and backup/
 * restore job control — all via the woo-mgmt-agent plugin's wma/v1 REST
 * routes, authenticated by one paired Bearer token. Replaces both the old
 * WooCommerceClient and WordPressClient.
 */
class SiteAgentClient
{
    private array $site;

    public function __construct(array $site)
    {
        $this->site = $site;
    }

    public function listProducts(array $params): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/products?' . http_build_query($params));
        return $this->decodeOrThrow($response);
    }

    public function getProduct(int $id): ?array
    {
        $response = $this->request('GET', "/wp-json/wma/v1/products/{$id}");
        if ($response['status'] === 404) {
            return null;
        }
        return $this->decodeOrThrow($response)['item'];
    }

    public function createProduct(array $data): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/products', $data);
        return $this->decodeOrThrow($response)['item'];
    }

    public function updateProduct(int $id, array $data): ?array
    {
        $response = $this->request('PUT', "/wp-json/wma/v1/products/{$id}", $data);
        if ($response['status'] === 404) {
            return null;
        }
        return $this->decodeOrThrow($response)['item'];
    }

    public function deleteProduct(int $id, bool $force): bool
    {
        $response = $this->request('DELETE', "/wp-json/wma/v1/products/{$id}" . ($force ? '?force=1' : ''));
        return $response['status'] === 200;
    }

    public function batchProducts(array $items): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/products/batch', ['update' => $items]);
        return $this->decodeOrThrow($response)['update'];
    }

    public function listCategories(): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/categories');
        return $this->decodeOrThrow($response)['items'];
    }

    public function listTaxonomies(): array
    {
        $response = $this->request('GET', '/wp-json/wma/v1/taxonomies');
        return $this->decodeOrThrow($response)['items'];
    }

    public function uploadMedia(string $binary, string $filename, string $mimeType): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/media', null, $binary, [
            'X-Filename: ' . $filename,
            'Content-Type: ' . $mimeType,
        ]);
        return $this->decodeOrThrow($response)['item'];
    }

    public function startJob(int $jobId, string $kind, string $scope, array $parts = []): array
    {
        $response = $this->request('POST', '/wp-json/wma/v1/jobs', [
            'job_id' => $jobId, 'kind' => $kind, 'scope' => $scope, 'parts' => $parts,
        ]);
        return $response['status'] === 202
            ? ['ok' => true, 'error' => '']
            : ['ok' => false, 'error' => $this->errorFrom($response)];
    }

    public function pollTick(int $jobId): array
    {
        // A tick is one bounded batch (e.g. 200 DB rows or 50 files) and
        // should always finish in a couple of seconds. A short timeout here
        // means an abnormally slow tick fails fast and gets retried on the
        // next 3s poll, instead of hanging until the host's own gateway
        // timeout kills the request with a 502/504 (seen in production).
        $response = $this->request('GET', "/wp-json/wma/v1/jobs/{$jobId}?tick=1", null, null, [], 12);
        if ($response['status'] !== 200) {
            return ['ok' => false, 'status' => '', 'step' => '', 'parts' => [], 'error' => $this->errorFrom($response)];
        }
        $data = json_decode($response['body'], true) ?: [];
        return [
            'ok' => true,
            'status' => (string) ($data['status'] ?? ''),
            'step' => (string) ($data['step'] ?? ''),
            'parts' => (array) ($data['parts'] ?? []),
            'error' => (string) ($data['error'] ?? ''),
        ];
    }

    /**
     * @return array{status: int, body: string, error: string}
     */
    private function request(string $method, string $path, ?array $jsonBody = null, ?string $rawBody = null, array $extraHeaders = [], int $timeoutSeconds = 30): array
    {
        $url = rtrim($this->site['store_url'], '/') . $path;
        $headers = array_merge(['Authorization: Bearer ' . $this->site['agent_token']], $extraHeaders);

        $payload = null;
        if ($jsonBody !== null) {
            $headers[] = 'Content-Type: application/json';
            $payload = json_encode($jsonBody);
        } elseif ($rawBody !== null) {
            $payload = $rawBody;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->site['verify_ssl'] ?? true),
            CURLOPT_TIMEOUT => $timeoutSeconds,
        ]);
        if ($payload !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        }

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'body' => '', 'error' => $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $body, 'error' => ''];
    }

    private function decodeOrThrow(array $response): array
    {
        if ($response['status'] < 200 || $response['status'] >= 300) {
            throw new RuntimeException($this->errorFrom($response));
        }
        return json_decode($response['body'], true) ?: [];
    }

    private function errorFrom(array $response): string
    {
        if ($response['error'] !== '') {
            return 'Connection error: ' . $response['error'];
        }
        $data = json_decode($response['body'], true);
        $message = is_array($data) && !empty($data['error']) ? $data['error'] : 'Unexpected response';
        return "Agent request failed (HTTP {$response['status']}): {$message}";
    }
}
