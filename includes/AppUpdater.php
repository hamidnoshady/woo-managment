<?php

require_once __DIR__ . '/Settings.php';
require_once __DIR__ . '/helpers.php';

/**
 * Self-update via GitHub Releases on this private repo, mirroring the WP
 * plugin's Wma_Updater (wp-plugin/woo-mgmt-agent/includes/class-wma-updater.php).
 * CI (.github/workflows/release-webapp.yml) publishes a rolling
 * "webapp-beta" release per pull request and a rolling "webapp-stable"
 * release on every merge to main. Unlike the plugin, there's no build step
 * here to bake a token into - the superadmin enters a GitHub access token
 * once under Admin -> Settings -> Web app updates.
 */

const APP_UPDATE_REPO = 'hamidnoshady/woo-managment';

function app_update_channel(): string
{
    return get_setting('webapp_update_channel') === 'beta' ? 'beta' : 'stable';
}

/**
 * Checks the configured release channel for a version newer than what's on
 * disk. Returns null if no token is set, the request fails, or there's
 * nothing newer to install.
 *
 * @return array{version: string, asset_id: int}|null
 */
function check_app_update(): ?array
{
    $token = get_setting('webapp_update_token');
    if ($token === '') {
        return null;
    }

    $tag = 'webapp-' . app_update_channel();
    $response = app_update_github_request(
        'https://api.github.com/repos/' . APP_UPDATE_REPO . '/releases/tags/' . $tag,
        $token,
        'application/vnd.github+json'
    );
    if ($response['status'] !== 200) {
        return null;
    }

    $data = json_decode($response['body'], true);
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

    $version = ltrim((string) $data['name'], 'v');
    // Beta titles look like "1.0.0-beta.5" - compare only the numeric part
    // so a beta build isn't mistaken for older than the current release.
    $numericVersion = preg_replace('/-.*/', '', $version);
    if (version_compare((string) $numericVersion, app_version(), '<=')) {
        return null;
    }

    return ['version' => $version, 'asset_id' => (int) $asset['id']];
}

/**
 * Downloads the release asset and copies it over the live public/ and
 * includes/ directories in place - the same technique WordPress core uses
 * for its own updates, since this app has no build step or deploy pipeline
 * of its own to hand off to. includes/config.php is left untouched so a
 * deployed site's database credentials survive the update.
 *
 * ponytail: no automatic pre-update backup - this is a manual, superadmin-
 * triggered action; add one (e.g. reuse BackupManager) if updates need to
 * be safely reversible without redeploying from git.
 */
function apply_app_update(array $release): void
{
    if (!extension_loaded('zip')) {
        throw new RuntimeException('The PHP zip extension is required to apply updates.');
    }

    $token = get_setting('webapp_update_token');
    $url = 'https://api.github.com/repos/' . APP_UPDATE_REPO . '/releases/assets/' . $release['asset_id'];

    $tmpZip = tempnam(sys_get_temp_dir(), 'wma_update_');
    $fp = fopen($tmpZip, 'wb');
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: application/octet-stream',
            'User-Agent: woo-managment-updater',
        ],
        CURLOPT_FILE => $fp,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 300,
    ]);
    $ok = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    fclose($fp);

    if (!$ok || $status !== 200) {
        @unlink($tmpZip);
        throw new RuntimeException('Failed to download update package.');
    }

    $tmpDir = sys_get_temp_dir() . '/wma_update_' . uniqid();
    $zip = new ZipArchive();
    if ($zip->open($tmpZip) !== true) {
        @unlink($tmpZip);
        throw new RuntimeException('Downloaded update package is not a valid zip.');
    }
    $zip->extractTo($tmpDir);
    $zip->close();
    @unlink($tmpZip);

    $root = __DIR__ . '/..';
    foreach (['public', 'includes'] as $dir) {
        $src = $tmpDir . '/' . $dir;
        if (is_dir($src)) {
            app_update_copy_dir($src, $root . '/' . $dir);
        }
    }

    app_update_rrmdir($tmpDir);
}

/**
 * Recursively copies $src over $dest, skipping includes/config.php.
 */
function app_update_copy_dir(string $src, string $dest): void
{
    if (!is_dir($dest)) {
        mkdir($dest, 0755, true);
    }

    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        if (basename($dest) === 'includes' && $item === 'config.php') {
            continue;
        }

        $srcPath = $src . '/' . $item;
        $destPath = $dest . '/' . $item;

        if (is_dir($srcPath)) {
            app_update_copy_dir($srcPath, $destPath);
        } else {
            copy($srcPath, $destPath);
        }
    }
}

function app_update_rrmdir(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') {
            continue;
        }
        $path = $dir . '/' . $item;
        is_dir($path) ? app_update_rrmdir($path) : @unlink($path);
    }
    @rmdir($dir);
}

/**
 * @return array{status: int, body: string}
 */
function app_update_github_request(string $url, string $token, string $accept): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $token,
            'Accept: ' . $accept,
            'User-Agent: woo-managment-updater',
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    $body = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $status, 'body' => (string) ($body ?: '')];
}
