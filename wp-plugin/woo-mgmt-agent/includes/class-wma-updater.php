<?php
// wp-plugin/woo-mgmt-agent/includes/class-wma-updater.php

defined('ABSPATH') || exit;

/**
 * Self-update via GitHub Releases on the private woo-managment repo. CI
 * (.github/workflows/release-plugin.yml) publishes a rolling "beta" release
 * per pull request and a rolling "stable" release on every merge to main,
 * baking WMA_UPDATE_TOKEN into the built zip so this class can read the
 * private repo's release/asset API. Source checkouts have no token, so
 * update checks are silently skipped.
 */
class Wma_Updater
{
    const REPO = 'hamidnoshady/woo-managment';

    public static function register(): void
    {
        add_filter('pre_set_site_transient_update_plugins', [self::class, 'check']);
        add_filter('upgrader_pre_download', [self::class, 'download'], 10, 3);
        add_filter('plugins_api', [self::class, 'plugin_info'], 10, 3);
    }

    private static function plugin_file(): string
    {
        return plugin_basename(WMA_DIR . '/woo-mgmt-agent.php');
    }

    private static function token(): string
    {
        if (!defined('WMA_UPDATE_TOKEN')) {
            return '';
        }
        $token = WMA_UPDATE_TOKEN;
        return strpos($token, '{{') === 0 ? '' : $token;
    }

    private static function current_version(): string
    {
        if (!function_exists('get_file_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_file_data(WMA_DIR . '/woo-mgmt-agent.php', ['Version' => 'Version']);
        return (string) $data['Version'];
    }

    private static function api_headers(string $accept): array
    {
        return [
            'Authorization' => 'Bearer ' . self::token(),
            'Accept' => $accept,
            'User-Agent' => 'woo-mgmt-agent-updater',
        ];
    }

    private static function fetch_release(): ?array
    {
        if (self::token() === '') {
            return null;
        }
        $channel = Wma_Settings::get_channel();
        $url = 'https://api.github.com/repos/' . self::REPO . '/releases/tags/' . $channel;
        $res = wp_remote_get($url, [
            'headers' => self::api_headers('application/vnd.github+json'),
            'timeout' => 15,
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            return null;
        }
        $data = json_decode(wp_remote_retrieve_body($res), true);
        if (!is_array($data) || empty($data['assets'])) {
            return null;
        }
        $asset = null;
        foreach ($data['assets'] as $a) {
            if (substr((string) $a['name'], -4) === '.zip') {
                $asset = $a;
                break;
            }
        }
        if ($asset === null) {
            return null;
        }
        return [
            'version' => ltrim((string) $data['name'], 'v'),
            'asset_id' => $asset['id'],
        ];
    }

    public static function check($transient)
    {
        if (empty($transient->checked)) {
            return $transient;
        }
        $release = self::fetch_release();
        $file = self::plugin_file();
        if ($release === null || version_compare($release['version'], self::current_version(), '<=')) {
            unset($transient->response[$file]);
            return $transient;
        }
        $transient->response[$file] = (object) [
            'slug' => 'woo-mgmt-agent',
            'plugin' => $file,
            'new_version' => $release['version'],
            'url' => 'https://github.com/' . self::REPO,
            'package' => 'wma-asset://' . $release['asset_id'],
        ];
        return $transient;
    }

    public static function download($reply, $package, $upgrader)
    {
        if (strpos((string) $package, 'wma-asset://') !== 0) {
            return $reply;
        }
        $assetId = substr($package, strlen('wma-asset://'));
        $url = 'https://api.github.com/repos/' . self::REPO . '/releases/assets/' . $assetId;
        $tmpFile = wp_tempnam($url);
        $res = wp_remote_get($url, [
            'headers' => self::api_headers('application/octet-stream'),
            'timeout' => 300,
            'stream' => true,
            'filename' => $tmpFile,
        ]);
        if (is_wp_error($res) || wp_remote_retrieve_response_code($res) !== 200) {
            @unlink($tmpFile);
            return is_wp_error($res) ? $res : new WP_Error('wma_download_failed', 'Failed to download update.');
        }
        return $tmpFile;
    }

    public static function plugin_info($result, $action, $args)
    {
        if ($action !== 'plugin_information' || ($args->slug ?? '') !== 'woo-mgmt-agent') {
            return $result;
        }
        $release = self::fetch_release();
        if ($release === null) {
            return $result;
        }
        return (object) [
            'name' => 'Woo Management Agent',
            'slug' => 'woo-mgmt-agent',
            'version' => $release['version'],
            'sections' => ['description' => 'Update channel: ' . Wma_Settings::get_channel()],
        ];
    }
}
