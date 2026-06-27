<?php

/**
 * Minimal S3-compatible storage client (AWS Signature Version 4, raw cURL —
 * no AWS SDK, since this app has no Composer/build step). Works against AWS
 * S3 and S3-compatible services (MinIO, DigitalOcean Spaces, Backblaze B2's
 * S3-compatible endpoint, etc.) via path-style addressing:
 * {endpoint}/{bucket}/{key}.
 */
class S3Client
{
    private string $endpoint;
    private string $region;
    private string $bucket;
    private string $accessKey;
    private string $secretKey;

    public function __construct(array $config)
    {
        $this->endpoint = rtrim((string) ($config['endpoint'] ?? ''), '/');
        $this->region = (string) ($config['region'] ?? '');
        $this->bucket = (string) ($config['bucket'] ?? '');
        $this->accessKey = (string) ($config['access_key'] ?? '');
        $this->secretKey = (string) ($config['secret_key'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->endpoint !== '' && $this->region !== '' && $this->bucket !== ''
            && $this->accessKey !== '' && $this->secretKey !== '';
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function putObject(string $key, string $body, string $contentType): array
    {
        return $this->request('PUT', $key, $body, ['Content-Type: ' . $contentType]);
    }

    /**
     * @return array{ok: bool, error: string}
     */
    public function deleteObject(string $key): array
    {
        return $this->request('DELETE', $key, '');
    }

    /**
     * Builds a presigned URL for PUT or GET, valid for $expiresInSeconds.
     * Signs via query string (not the header-based signing request() uses)
     * so the URL itself is usable by a party that never sees the secret
     * key — the agent plugin.
     */
    public function presignedUrl(string $method, string $key, int $expiresInSeconds = 900): string
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalUri = '/' . rawurlencode($this->bucket) . '/' . $encodedKey;

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $credential = "{$this->accessKey}/{$credentialScope}";

        $queryParams = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $credential,
            'X-Amz-Date' => $amzDate,
            'X-Amz-Expires' => (string) $expiresInSeconds,
            'X-Amz-SignedHeaders' => 'host',
        ];
        ksort($queryParams);
        $canonicalQueryString = http_build_query($queryParams, '', '&', PHP_QUERY_RFC3986);

        $canonicalHeaders = "host:{$host}\n";
        $canonicalRequest = "{$method}\n{$canonicalUri}\n{$canonicalQueryString}\n{$canonicalHeaders}\nhost\nUNSIGNED-PAYLOAD";

        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        return "{$scheme}://{$host}{$canonicalUri}?{$canonicalQueryString}&X-Amz-Signature={$signature}";
    }

    /**
     * Signs and sends a request to a single object using AWS Signature
     * Version 4. PUT/DELETE object both need no query string, which keeps
     * the canonical request simple.
     *
     * @return array{ok: bool, error: string}
     */
    private function request(string $method, string $key, string $body, array $extraHeaders = []): array
    {
        $host = (string) parse_url($this->endpoint, PHP_URL_HOST);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';

        $encodedKey = implode('/', array_map('rawurlencode', explode('/', $key)));
        $canonicalUri = '/' . rawurlencode($this->bucket) . '/' . $encodedKey;
        $url = "{$scheme}://{$host}{$canonicalUri}";

        $amzDate = gmdate('Ymd\THis\Z');
        $dateStamp = gmdate('Ymd');
        $payloadHash = hash('sha256', $body);

        $canonicalHeaders = "host:{$host}\nx-amz-content-sha256:{$payloadHash}\nx-amz-date:{$amzDate}\n";
        $signedHeaders = 'host;x-amz-content-sha256;x-amz-date';

        $canonicalRequest = "{$method}\n{$canonicalUri}\n\n{$canonicalHeaders}\n{$signedHeaders}\n{$payloadHash}";

        $credentialScope = "{$dateStamp}/{$this->region}/s3/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        $kDate = hash_hmac('sha256', $dateStamp, 'AWS4' . $this->secretKey, true);
        $kRegion = hash_hmac('sha256', $this->region, $kDate, true);
        $kService = hash_hmac('sha256', 's3', $kRegion, true);
        $signingKey = hash_hmac('sha256', 'aws4_request', $kService, true);
        $signature = hash_hmac('sha256', $stringToSign, $signingKey);

        $authorization = "AWS4-HMAC-SHA256 Credential={$this->accessKey}/{$credentialScope}, "
            . "SignedHeaders={$signedHeaders}, Signature={$signature}";

        $headers = array_merge([
            "Host: {$host}",
            "X-Amz-Date: {$amzDate}",
            "X-Amz-Content-Sha256: {$payloadHash}",
            "Authorization: {$authorization}",
        ], $extraHeaders);

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 120,
        ];
        if ($method === 'PUT') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            return ['ok' => false, 'error' => 'Connection error: ' . $error];
        }

        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status < 200 || $status >= 300) {
            $message = "S3 request failed (HTTP {$status})";
            if (preg_match('/<Message>(.*?)<\/Message>/s', (string) $response, $m)) {
                $message .= ': ' . $m[1];
            }
            return ['ok' => false, 'error' => $message];
        }

        return ['ok' => true, 'error' => ''];
    }
}
