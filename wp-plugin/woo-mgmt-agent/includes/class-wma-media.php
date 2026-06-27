<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-media.php

defined('ABSPATH') || exit;

/**
 * Native media upload via wp_upload_bits()/wp_insert_attachment() — no
 * outbound call to the WordPress REST media endpoint, even internally;
 * this plugin has direct filesystem access already.
 */
class Wma_Media
{
    public static function upload(string $binary, string $filename, string $mimeType): array
    {
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $upload = wp_upload_bits(sanitize_file_name($filename), null, $binary);
        if (!empty($upload['error'])) {
            throw new RuntimeException('Upload failed: ' . $upload['error']);
        }

        $attachmentId = wp_insert_attachment([
            'post_mime_type' => $mimeType,
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit',
        ], $upload['file']);

        if (is_wp_error($attachmentId)) {
            throw new RuntimeException($attachmentId->get_error_message());
        }

        $metadata = wp_generate_attachment_metadata($attachmentId, $upload['file']);
        wp_update_attachment_metadata($attachmentId, $metadata);

        return ['id' => $attachmentId, 'src' => wp_get_attachment_url($attachmentId) ?: ''];
    }
}
