<?php

/**
 * Minimal cURL-based client for the core WordPress REST API (wp/v2),
 * authenticated with a WordPress Application Password. Used for
 * capabilities the WooCommerce REST API doesn't cover: uploading media
 * files and reading/writing custom (e.g. ACF) taxonomies on products.
 */
class WordPressClient
{
    private string $baseUrl;
    private string $username;
    private string $appPassword;
    private bool $verifySsl;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim($config['store_url'], '/') . '/wp-json/wp/v2';
        $this->username = $config['username'];
        $this->appPassword = $config['app_password'];
        $this->verifySsl = $config['verify_ssl'] ?? true;
    }

    /**
     * Uploads a media file and returns the created attachment (id, source_url, ...).
     */
    public function uploadMedia(string $fileContent, string $filename, string $mimeType): array
    {
        $headers = [
            'Content-Type: ' . $mimeType,
            'Content-Disposition: attachment; filename="' . $this->sanitizeFilename($filename) . '"',
        ];

        return $this->request('POST', '/media', [], $fileContent, $headers);
    }

    /**
     * Returns taxonomies registered for the "product" post type, excluding
     * the built-in WooCommerce ones (category/tag), with their terms.
     * Requires each ACF taxonomy to have "Show in REST API" enabled.
     */
    public function listCustomProductTaxonomies(): array
    {
        $result = $this->request('GET', '/taxonomies', ['type' => 'product']);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($result['data'])) {
            return [];
        }

        $exclude = ['product_cat', 'product_tag'];
        $taxonomies = [];

        foreach ($result['data'] as $slug => $tax) {
            if (in_array($slug, $exclude, true)) {
                continue;
            }
            if (empty($tax['rest_base'])) {
                continue;
            }

            $terms = $this->listTerms($tax['rest_base']);
            $taxonomies[] = [
                'slug' => $slug,
                'rest_base' => $tax['rest_base'],
                'name' => $tax['name'] ?? $slug,
                'hierarchical' => !empty($tax['hierarchical']),
                'terms' => $terms,
            ];
        }

        return $taxonomies;
    }

    /**
     * Returns terms for a taxonomy by its REST base (e.g. "brand").
     */
    public function listTerms(string $restBase): array
    {
        $result = $this->request('GET', '/' . $restBase, ['per_page' => 100, 'orderby' => 'name', 'order' => 'asc']);
        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($result['data'])) {
            return [];
        }

        return array_map(fn($term) => ['id' => $term['id'], 'name' => $term['name']], $result['data']);
    }

    /**
     * Returns IDs of products assigned to the given term of a custom
     * taxonomy (identified by its REST base), used for filtering the
     * product list by custom/ACF taxonomies.
     */
    public function listProductIdsByTerm(string $restBase, int $termId): array
    {
        $result = $this->request('GET', '/products', [
            $restBase => $termId,
            'per_page' => 100,
            '_fields' => 'id',
        ]);

        if ($result['status'] < 200 || $result['status'] >= 300 || !is_array($result['data'])) {
            return [];
        }

        return array_map(fn($item) => (int) $item['id'], $result['data']);
    }

    /**
     * Returns the raw product resource via the WordPress REST API
     * (used to read currently-assigned custom taxonomy term IDs).
     */
    public function getProduct(int $id): array
    {
        return $this->request('GET', "/products/{$id}", ['context' => 'edit']);
    }

    /**
     * Updates custom taxonomy term assignments on a product.
     * $taxonomyFields maps taxonomy rest_base => array of term IDs.
     */
    public function updateProductTaxonomies(int $id, array $taxonomyFields): array
    {
        return $this->request('POST', "/products/{$id}", [], json_encode($taxonomyFields, JSON_UNESCAPED_UNICODE), ['Content-Type: application/json']);
    }

    /**
     * Performs an authenticated request to the WordPress REST API.
     *
     * @return array{status:int, data: mixed, headers: array}
     */
    private function request(string $method, string $path, array $query = [], $body = null, array $extraHeaders = []): array
    {
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query);
        }

        $ch = curl_init($url);
        $headers = array_merge(['Accept: application/json'], $extraHeaders);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
            CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            CURLOPT_HEADER => true,
            CURLOPT_USERPWD => $this->username . ':' . $this->appPassword,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        ];

        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
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
            $data = ['message' => 'Invalid response from WordPress', 'raw' => $rawBody];
        }

        return [
            'status' => $status,
            'data' => $data,
            'headers' => $this->parseHeaders($rawHeaders),
        ];
    }

    private function sanitizeFilename(string $filename): string
    {
        $filename = basename($filename);
        return preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);
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
