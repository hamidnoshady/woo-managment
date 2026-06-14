<?php

/**
 * Minimal cURL-based client for the WooCommerce REST API (v3).
 * Credentials are read from config/config.php and never exposed to the browser.
 */
class WooCommerceClient
{
    private string $baseUrl;
    private string $consumerKey;
    private string $consumerSecret;
    private bool $verifySsl;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['store_url'], '/') . '/wp-json/wc/v3';
        $this->consumerKey = $config['consumer_key'];
        $this->consumerSecret = $config['consumer_secret'];
        $this->verifySsl = $config['verify_ssl'] ?? true;
    }

    public function listProducts(array $params = []): array
    {
        return $this->request('GET', '/products', $params);
    }

    public function getProduct(int $id): array
    {
        return $this->request('GET', "/products/{$id}");
    }

    public function createProduct(array $data): array
    {
        return $this->request('POST', '/products', [], $data);
    }

    public function updateProduct(int $id, array $data): array
    {
        return $this->request('PUT', "/products/{$id}", [], $data);
    }

    public function deleteProduct(int $id, bool $force = true): array
    {
        return $this->request('DELETE', "/products/{$id}", ['force' => $force ? 'true' : 'false']);
    }

    public function batchProducts(array $batch): array
    {
        return $this->request('POST', '/products/batch', [], $batch);
    }

    public function listCategories(array $params = []): array
    {
        $params = array_merge(['per_page' => 100], $params);
        return $this->request('GET', '/products/categories', $params);
    }

    /**
     * Performs an authenticated request to the WooCommerce REST API.
     *
     * @return array{status:int, data: mixed, headers: array}
     */
    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->baseUrl . $path;

        // Use query-string auth so it works even if the host strips Authorization headers.
        $query['consumer_key'] = $this->consumerKey;
        $query['consumer_secret'] = $this->consumerSecret;
        $url .= '?' . http_build_query($query);

        $ch = curl_init($url);
        $headers = ['Accept: application/json'];

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADER => true,
        ];

        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_UNICODE);
            $options[CURLOPT_POSTFIELDS] = $payload;
            $headers[] = 'Content-Type: application/json';
        }

        $options[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['status' => 0, 'data' => ['message' => 'Connection error: ' . $error], 'headers' => []];
        }

        $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $rawHeaders = substr($response, 0, $headerSize);
        $rawBody = substr($response, $headerSize);

        $data = json_decode($rawBody, true);
        if ($data === null && $rawBody !== '') {
            $data = ['message' => 'Invalid response from WooCommerce', 'raw' => $rawBody];
        }

        return [
            'status' => $status,
            'data' => $data,
            'headers' => $this->parseHeaders($rawHeaders),
        ];
    }

    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        foreach (explode("\r\n", $rawHeaders) as $line) {
            if (strpos($line, ':') === false) {
                continue;
            }
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
        return $headers;
    }
}
