<?php
// includes/JetBackupClient.php

require_once __DIR__ . '/Settings.php';

/**
 * Talks to JetBackup 5's function-dispatch API (JetApi), reached through
 * DirectAdmin at CMD_PLUGINS_ADMIN/jetbackup5/index.raw. Authenticated the
 * same way as DirectAdminClient (Login Key, HTTP Basic auth) since JetBackup
 * rides on the DA panel's own auth rather than a separate key.
 *
 * FUNC_* names below are a best guess pending confirmation against the live
 * server (see Task 4 Step 1 in the implementation plan) — this file is the
 * single place to correct them if they're wrong.
 */
class JetBackupClient
{
    private const FUNC_LIST_ACCOUNT_BACKUPS = 'Accounts.getAccounts';
    private const FUNC_CREATE_SNAPSHOT = 'Queues.addQueueSnapshot';
    private const FUNC_GET_QUEUE = 'Queues.getQueue';
    private const FUNC_RESTORE = 'Queues.addQueueRestore';

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
     * Triggers an on-demand full-account (files+DB) backup and returns
     * JetBackup's queue/job id for polling via getBackupStatus().
     */
    public function createAccountBackup(string $daUsername): string
    {
        $data = $this->call(self::FUNC_CREATE_SNAPSHOT, ['account' => $daUsername]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the new snapshot.');
        }
        return $id;
    }

    /**
     * @return array{status:string, size_bytes:int, error:string}
     */
    public function getBackupStatus(string $backupId): array
    {
        $data = $this->call(self::FUNC_GET_QUEUE, ['id' => $backupId]);
        return [
            'status' => self::normalizeStatus((string) ($data['status'] ?? '')),
            'size_bytes' => (int) ($data['size'] ?? $data['size_bytes'] ?? 0),
            'error' => (string) ($data['error'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{id:string, status:string, size_bytes:int, created_at:int}>
     */
    public function listAccountBackups(string $daUsername): array
    {
        $data = $this->call(self::FUNC_LIST_ACCOUNT_BACKUPS, ['account' => $daUsername]);
        $items = (array) ($data['items'] ?? $data['backups'] ?? []);

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id' => (string) ($item['id'] ?? ''),
                'status' => self::normalizeStatus((string) ($item['status'] ?? '')),
                'size_bytes' => (int) ($item['size'] ?? $item['size_bytes'] ?? 0),
                'created_at' => (int) ($item['time'] ?? $item['created_at'] ?? 0),
            ];
        }
        return $result;
    }

    /**
     * Triggers a full-account restore from the given JetBackup backup id and
     * returns the restore queue id for polling via getBackupStatus().
     */
    public function restoreAccountBackup(string $daUsername, string $backupId): string
    {
        $data = $this->call(self::FUNC_RESTORE, ['account' => $daUsername, 'backup_id' => $backupId]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the restore.');
        }
        return $id;
    }

    private static function normalizeStatus(string $raw): string
    {
        $raw = strtolower($raw);
        if (in_array($raw, ['done', 'completed', 'success', 'finished'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error', 'cancelled'], true)) {
            return 'failed';
        }
        return 'running';
    }

    /**
     * @return array<string,mixed>
     */
    private function call(string $func, array $data): array
    {
        $url = "{$this->apiUrl}/CMD_PLUGINS_ADMIN/jetbackup5/index.raw?api=1";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_USERPWD => "{$this->username}:{$this->loginKey}",
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'func' => $func,
                'data' => json_encode($data),
                'output' => 'json',
            ]),
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException("JetBackup API request for {$func} failed to connect.");
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException("JetBackup API request for {$func} failed (HTTP {$status}).");
        }

        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException("JetBackup API request for {$func} returned a non-JSON response.");
        }
        if (!empty($decoded['error'])) {
            throw new RuntimeException("JetBackup API error for {$func}: " . $decoded['error']);
        }
        return (array) ($decoded['data'] ?? $decoded);
    }
}

/**
 * Builds a JetBackupClient from the app's configured settings (same
 * DirectAdmin credentials as DirectAdminClient).
 */
function jetbackup_client(): JetBackupClient
{
    return new JetBackupClient([
        'api_url' => get_setting('da_api_url'),
        'username' => get_setting('da_admin_username'),
        'login_key' => get_setting('da_login_key'),
    ]);
}
