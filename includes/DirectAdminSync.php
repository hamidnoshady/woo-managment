<?php
// includes/DirectAdminSync.php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/DirectAdminClient.php';
require_once __DIR__ . '/Sites.php';

/**
 * Matches DirectAdmin accounts on this server against managed WooCommerce
 * sites by domain, so JetBackup-backed sites are discovered automatically
 * instead of requiring manual da_username entry. Read-only against
 * DirectAdmin itself — never creates/modifies/deletes DA accounts.
 */
class DirectAdminSync
{
    /**
     * @return array{linked: int, created: int, skipped_conflicts: array<int, array{domain: string, existing_da_username: string, found_da_username: string}>}
     */
    public static function syncSites(): array
    {
        $client = directadmin_client();
        if (!$client->isConfigured()) {
            throw new RuntimeException('DirectAdmin API is not configured (see Admin -> Settings).');
        }

        $accounts = $client->listAccounts();
        $sites = list_all_sites();

        $linked = 0;
        $created = 0;
        $conflicts = [];

        foreach ($accounts as $account) {
            $domain = strtolower($account['domain']);
            $matchedSite = self::findSiteByDomain($sites, $domain);

            if ($matchedSite !== null) {
                if ($matchedSite['da_username'] === '') {
                    update_site((int) $matchedSite['id'], ['da_username' => $account['username']]);
                    $linked++;
                } elseif ($matchedSite['da_username'] !== $account['username']) {
                    $conflicts[] = [
                        'domain' => $domain,
                        'existing_da_username' => $matchedSite['da_username'],
                        'found_da_username' => $account['username'],
                    ];
                }
                continue;
            }

            create_site([
                'name' => $domain,
                'store_url' => "https://{$domain}",
                'verify_ssl' => true,
                'da_username' => $account['username'],
                'backup_enabled' => true,
            ]);
            $created++;
        }

        return ['linked' => $linked, 'created' => $created, 'skipped_conflicts' => $conflicts];
    }

    private static function findSiteByDomain(array $sites, string $domain): ?array
    {
        foreach ($sites as $site) {
            $host = strtolower((string) parse_url($site['store_url'], PHP_URL_HOST));
            if ($host === $domain) {
                return get_site((int) $site['id']);
            }
        }
        return null;
    }
}
