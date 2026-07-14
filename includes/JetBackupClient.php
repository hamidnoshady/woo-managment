<?php
// includes/JetBackupClient.php

require_once __DIR__ . '/Settings.php';

/**
 * Talks to JetBackup 5's function-dispatch API (JetApi), reached through
 * DirectAdmin at CMD_PLUGINS_ADMIN/jetbackup5/index.raw. Authenticated the
 * same way as DirectAdminClient (Login Key, HTTP Basic auth) since JetBackup
 * rides on the DA panel's own auth rather than a separate key.
 *
 * FUNC_* names are bare (no "Category." prefix) per JetBackup's published
 * JetApi reference (docs.jetbackup.com/.../api/Queues/addQueueSnapshot.html,
 * .../api/Accounts/getAccount.html show the category only as a doc-URL
 * segment, not part of the `-F`/`function` value itself — e.g.
 * `jetapi backup -F addQueueSnapshot -D 'username=acct01'`). The exact
 * queue `status` values are still unconfirmed (JetBackup's getQueue returns
 * a numeric status/percentage pair rather than a status string — see
 * normalizeStatus()), and FUNC_RESTORE's parameter shape (addQueueItems
 * with type=2 plus a snapshot_id and per-item/path selectors) is the least
 * certain of all these calls — restore is the highest-risk operation, so
 * confirm both against a live server before relying on restoreAccountBackup().
 */
class JetBackupClient
{
    private const FUNC_LIST_ACCOUNT_BACKUPS = 'listBackups';
    private const FUNC_CREATE_SNAPSHOT = 'addQueueSnapshot';
    private const FUNC_GET_QUEUE = 'getQueue';
    private const FUNC_RESTORE = 'addQueueItems';

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
     * Lightweight reachability/credentials check, separate from any backup
     * job — lets the admin verify the DirectAdmin Login Key can actually
     * reach JetBackup before relying on it, instead of only finding out when
     * a real backup fails partway through.
     *
     * @return array{ok: bool, error: string}
     */
    public function testConnection(): array
    {
        if (!$this->isConfigured()) {
            return ['ok' => false, 'error' => 'JetBackup is not configured (DirectAdmin API URL/username/Login Key).'];
        }
        try {
            $this->call(self::FUNC_GET_QUEUE, ['id' => '0']);
            return ['ok' => true, 'error' => ''];
        } catch (RuntimeException $e) {
            // A "not found"-style error for the bogus id 0 still proves the
            // API call reached JetBackup and was understood — only a
            // connection/auth-level failure means the connection itself is
            // broken. call() already distinguishes those in its message.
            $message = $e->getMessage();
            if (str_contains($message, 'JetBackup API error for')) {
                return ['ok' => true, 'error' => ''];
            }
            return ['ok' => false, 'error' => $message];
        }
    }

    /**
     * Triggers an on-demand full-account (files+DB) backup and returns
     * JetBackup's queue/job id for polling via getBackupStatus().
     */
    public function createAccountBackup(string $daUsername): string
    {
        $data = $this->call(self::FUNC_CREATE_SNAPSHOT, ['username' => $daUsername]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the new snapshot.');
        }
        return $id;
    }

    /**
     * @return array{status:string, percent:int, size_bytes:int, error:string}
     */
    public function getBackupStatus(string $backupId): array
    {
        $data = $this->call(self::FUNC_GET_QUEUE, ['id' => $backupId]);
        return [
            'status' => self::normalizeStatus($data['status'] ?? ''),
            'percent' => (int) ($data['percentage'] ?? $data['percent'] ?? 0),
            'size_bytes' => (int) ($data['size'] ?? $data['size_bytes'] ?? 0),
            'error' => (string) ($data['error'] ?? ''),
        ];
    }

    /**
     * @return array<int, array{id:string, status:string, size_bytes:int, created_at:int}>
     */
    public function listAccountBackups(string $daUsername): array
    {
        $data = $this->call(self::FUNC_LIST_ACCOUNT_BACKUPS, ['username' => $daUsername]);
        $items = (array) ($data['items'] ?? $data['backups'] ?? []);

        $result = [];
        foreach ($items as $item) {
            $result[] = [
                'id' => (string) ($item['id'] ?? ''),
                'status' => self::normalizeStatus($item['status'] ?? ''),
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
        $data = $this->call(self::FUNC_RESTORE, [
            'type' => 2, // Restore Queue
            'snapshot_id' => $backupId,
            'options' => ['owner' => $daUsername],
        ]);
        $id = (string) ($data['id'] ?? $data['queue_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('JetBackup did not return a queue id for the restore.');
        }
        return $id;
    }

    /**
     * JetBackup's getQueue returns a numeric status alongside a separate
     * `percentage` field rather than a status string, but the exact numeric
     * enum isn't publicly documented — this accepts both a numeric code
     * (best-effort mapping below) and any of the string forms JetBackup's
     * own admin UI uses, so a wrong guess on one form still gets caught by
     * the other. Unrecognized values default to 'running' (never falsely
     * reports success/failure) so a mapping gap fails safe.
     */
    private static function normalizeStatus($raw): string
    {
        if (is_numeric($raw)) {
            $code = (int) $raw;
            if ($code === 2) {
                return 'completed';
            }
            if ($code >= 3) {
                return 'failed';
            }
            return 'running';
        }

        $raw = strtolower((string) $raw);
        if (in_array($raw, ['done', 'completed', 'success', 'finished'], true)) {
            return 'completed';
        }
        if (in_array($raw, ['failed', 'error', 'cancelled', 'canceled'], true)) {
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
