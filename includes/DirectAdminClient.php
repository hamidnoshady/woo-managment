<?php
// includes/DirectAdminClient.php

require_once __DIR__ . '/Settings.php';

/**
 * Talks to the DirectAdmin API (not JetBackup's) purely to enumerate
 * hosting accounts and their primary domains, so DirectAdminSync can match
 * them against managed WooCommerce sites. Authenticated with a DirectAdmin
 * Login Key (HTTP Basic auth), configured in Admin -> Settings.
 */
class DirectAdminClient
{
    private string $apiUrl;
    private string $username;
    private string $loginKey;

    public function __construct(array $config)
    {
        $this->apiUrl = rtrim((string) ($config['api_url'] ?? ''), '/');
        $this->username = (string) ($config['username'] ?? '');
        $this->loginKey = (string) ($config['login_key'] ?? '');
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->username !== '' && $this->loginKey !== '';
    }

    /**
     * Returns every account this DA user can see, as [{username, domain}, ...].
     * DirectAdmin's CMD_API_SHOW_ALL_USERS is admin-level only; reseller-level
     * accounts fall back to CMD_API_SHOW_USERS (their own resold accounts).
     */
    public function listAccounts(): array
    {
        $body = $this->request('CMD_API_SHOW_ALL_USERS');
        if ($body === null) {
            $body = $this->request('CMD_API_SHOW_USERS');
        }
        if ($body === null) {
            throw new RuntimeException('DirectAdmin API request failed for both CMD_API_SHOW_ALL_USERS and CMD_API_SHOW_USERS.');
        }

        $accounts = [];
        foreach ($body as $username => $domain) {
            if (is_string($username) && is_string($domain) && $domain !== '') {
                $accounts[] = ['username' => $username, 'domain' => $domain];
            }
        }
        return $accounts;
    }

    /**
     * @return array<string,string>|null Decoded JSON body, or null on any
     * non-2xx / non-JSON response (caller tries the next candidate command).
     */
    private function request(string $command): ?array
    {
        $url = "{$this->apiUrl}/{$command}?json=yes";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->loginKey}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_TIMEOUT => 20,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status < 200 || $status >= 300) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        return is_array($decoded) ? $decoded : null;
    }
}

/**
 * Builds a DirectAdminClient from the app's configured settings.
 */
function directadmin_client(): DirectAdminClient
{
    return new DirectAdminClient([
        'api_url' => get_setting('da_api_url'),
        'username' => get_setting('da_admin_username'),
        'login_key' => get_setting('da_login_key'),
    ]);
}
