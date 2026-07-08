<?php

/**
 * MySQL wrapper for the app database (users, sites, site assignments, OTP
 * codes). Creates the schema on first use. Connection credentials come from
 * includes/config.php (gitignored) — see includes/config.example.php.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $configPath = __DIR__ . '/config.php';
        if (!is_file($configPath)) {
            throw new RuntimeException(
                'Missing includes/config.php. Copy includes/config.example.php to ' .
                'includes/config.php and fill in your MySQL connection details.'
            );
        }
        $config = require $configPath;

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'] ?? 3306,
            $config['database']
        );

        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_codes (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                code VARCHAR(16) NOT NULL,
                created_at INT NOT NULL,
                expires_at INT NOT NULL,
                consumed TINYINT NOT NULL DEFAULT 0,
                KEY idx_otp_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_verify_attempts (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL,
                created_at INT NOT NULL,
                KEY idx_otp_attempts_phone (phone)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                phone VARCHAR(20) NOT NULL UNIQUE,
                name VARCHAR(255) NOT NULL DEFAULT \'\',
                role VARCHAR(20) NOT NULL CHECK (role IN (\'superadmin\', \'admin\', \'shop_manager\')),
                created_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sites (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                store_url VARCHAR(500) NOT NULL,
                consumer_key VARCHAR(255) NOT NULL,
                consumer_secret VARCHAR(255) NOT NULL,
                verify_ssl TINYINT NOT NULL DEFAULT 1,
                wp_username VARCHAR(255) NOT NULL DEFAULT \'\',
                wp_app_password VARCHAR(255) NOT NULL DEFAULT \'\',
                created_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_sites (
                user_id INT NOT NULL,
                site_id INT NOT NULL,
                PRIMARY KEY (user_id, site_id),
                CONSTRAINT fk_user_sites_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
                CONSTRAINT fk_user_sites_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                `key` VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                user_name VARCHAR(255) NOT NULL DEFAULT \'\',
                user_phone VARCHAR(20) NOT NULL DEFAULT \'\',
                site_id INT NULL,
                category VARCHAR(20) NOT NULL DEFAULT \'site\',
                action VARCHAR(50) NOT NULL,
                message_key VARCHAR(100) NOT NULL,
                message_params TEXT NOT NULL,
                undo_data TEXT NULL,
                undone TINYINT NOT NULL DEFAULT 0,
                created_at INT NOT NULL,
                KEY idx_activity_logs_user (user_id),
                KEY idx_activity_logs_site (site_id),
                KEY idx_activity_logs_created (created_at),
                KEY idx_activity_logs_site_category (site_id, category, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS kv_cache (
                `key` VARCHAR(255) PRIMARY KEY,
                value TEXT NOT NULL,
                expires_at INT NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL,
                s3_key VARCHAR(500) NOT NULL DEFAULT \'\',
                size_bytes BIGINT NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at INT NOT NULL,
                KEY idx_backups_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                step_label VARCHAR(50) NOT NULL DEFAULT \'\',
                percent INT NOT NULL DEFAULT 0,
                total_size_bytes BIGINT NOT NULL DEFAULT 0,
                s3_prefix VARCHAR(500) NOT NULL DEFAULT \'\',
                manifest_json TEXT NULL,
                error TEXT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                last_tick_at INT NOT NULL,
                KEY idx_site_backups_site (site_id, id),
                CONSTRAINT fk_site_backups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_restores (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                source_backup_id INT NOT NULL,
                type VARCHAR(20) NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                step_label VARCHAR(50) NOT NULL DEFAULT \'\',
                percent INT NOT NULL DEFAULT 0,
                safety_backup_id INT NULL,
                error TEXT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                last_tick_at INT NOT NULL,
                KEY idx_site_restores_site (site_id, id),
                CONSTRAINT fk_site_restores_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE,
                CONSTRAINT fk_site_restores_backup FOREIGN KEY (source_backup_id) REFERENCES site_backups(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS site_jetbackup_backups (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                jetbackup_backup_id VARCHAR(100) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                size_bytes BIGINT NOT NULL DEFAULT 0,
                error TEXT NULL,
                created_at INT NOT NULL,
                completed_at INT NULL,
                KEY idx_site_jetbackup_backups_site (site_id, id),
                CONSTRAINT fk_site_jetbackup_backups_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS batch_jobs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                site_id INT NOT NULL,
                user_id INT NOT NULL,
                action VARCHAR(20) NOT NULL,
                params_json TEXT NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT \'running\',
                total_items INT NOT NULL DEFAULT 0,
                processed_items INT NOT NULL DEFAULT 0,
                succeeded_items INT NOT NULL DEFAULT 0,
                failed_items INT NOT NULL DEFAULT 0,
                log_id INT NULL,
                started_at INT NOT NULL,
                completed_at INT NULL,
                KEY idx_batch_jobs_site (site_id, id),
                CONSTRAINT fk_batch_jobs_site FOREIGN KEY (site_id) REFERENCES sites(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS batch_job_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                batch_job_id INT NOT NULL,
                product_id INT NOT NULL,
                product_name VARCHAR(255) NOT NULL DEFAULT \'\',
                status VARCHAR(20) NOT NULL DEFAULT \'pending\',
                change_json TEXT NULL,
                error TEXT NULL,
                KEY idx_batch_job_items_job (batch_job_id, status),
                CONSTRAINT fk_batch_job_items_job FOREIGN KEY (batch_job_id) REFERENCES batch_jobs(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
        );

        self::addColumnIfMissing($pdo, 'sites', 'agent_token', "VARCHAR(64) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'sites', 'agent_paired_at', 'INT NULL');
        self::addColumnIfMissing($pdo, 'sites', 'agent_last_seen_at', 'INT NULL');
        self::addColumnIfMissing($pdo, 'sites', 'backup_enabled', 'TINYINT NOT NULL DEFAULT 0');
        self::addColumnIfMissing($pdo, 'sites', 'backup_schedule', "VARCHAR(20) NOT NULL DEFAULT 'off'");
        self::addColumnIfMissing($pdo, 'sites', 'backup_retention_days', 'INT NOT NULL DEFAULT 30');
        self::addColumnIfMissing($pdo, 'activity_logs', 'batch_job_id', 'INT NULL');
        self::addColumnIfMissing($pdo, 'sites', 'da_username', "VARCHAR(100) NOT NULL DEFAULT ''");
        self::addColumnIfMissing($pdo, 'backups', 'external_ref', "VARCHAR(255) NOT NULL DEFAULT ''");

        self::dropColumnIfPresent($pdo, 'sites', 'consumer_key');
        self::dropColumnIfPresent($pdo, 'sites', 'consumer_secret');
        self::dropColumnIfPresent($pdo, 'sites', 'wp_username');
        self::dropColumnIfPresent($pdo, 'sites', 'wp_app_password');

        self::$pdo = $pdo;
        return $pdo;
    }

    private static function addColumnIfMissing(PDO $pdo, string $table, string $column, string $definition): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        if (!$stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        }
    }

    private static function dropColumnIfPresent(PDO $pdo, string $table, string $column): void
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
             WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
        );
        $stmt->execute([$table, $column]);
        if ($stmt->fetchColumn()) {
            $pdo->exec("ALTER TABLE {$table} DROP COLUMN {$column}");
        }
    }
}
