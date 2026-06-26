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
        self::$pdo = $pdo;
        return $pdo;
    }
}
