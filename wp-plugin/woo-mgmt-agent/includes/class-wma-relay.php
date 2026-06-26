<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-relay.php

defined('ABSPATH') || exit;

/**
 * The only outbound calls this plugin makes: asking woo-managment for a
 * presigned S3 URL (it holds the S3 secret key, this plugin never does),
 * then PUTting/GETting that URL directly against S3. Only used by backup
 * and restore jobs — products/categories/taxonomies/media never touch S3.
 */
class Wma_Relay
{
    public static function request_presigned_url(int $job_id, string $part_key, string $method): array
    {
        $base = Wma_Settings::get_base_url();
        if ($base === '') {
            return ['ok' => false, 'url' => '', 'error' => 'woo-managment base URL is not configured.'];
        }

        $response = wp_remote_post($base . '/api/site-backup-agent/presign.php', [
            'timeout' => 30,
            'headers' => [
                'Authorization' => 'Bearer ' . Wma_Settings::get_token(),
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'job_id' => $job_id,
                'part_key' => $part_key,
                'method' => $method,
            ]),
        ]);

        if (is_wp_error($response)) {
            return ['ok' => false, 'url' => '', 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200 || !is_array($data) || empty($data['url'])) {
            return ['ok' => false, 'url' => '', 'error' => 'Presign request failed (HTTP ' . $code . ')'];
        }
        return ['ok' => true, 'url' => $data['url'], 'error' => ''];
    }

    public static function put_file(string $url, string $local_path): array
    {
        $body = file_get_contents($local_path);
        if ($body === false) {
            return ['ok' => false, 'error' => 'Could not read local file for upload.'];
        }
        $response = wp_remote_request($url, ['method' => 'PUT', 'timeout' => 60, 'body' => $body]);
        return self::result($response);
    }

    public static function get_to_file(string $url, string $local_path): array
    {
        $response = wp_remote_get($url, ['timeout' => 60]);
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'Download failed (HTTP ' . $code . ')'];
        }
        $written = file_put_contents($local_path, wp_remote_retrieve_body($response));
        if ($written === false) {
            return ['ok' => false, 'error' => 'Could not write downloaded part to disk.'];
        }
        return ['ok' => true, 'error' => ''];
    }

    private static function result($response): array
    {
        if (is_wp_error($response)) {
            return ['ok' => false, 'error' => $response->get_error_message()];
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'S3 upload failed (HTTP ' . $code . ')'];
        }
        return ['ok' => true, 'error' => ''];
    }
}
