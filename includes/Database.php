<?php

/**
 * SQLite wrapper for the app database (users, sites, site assignments, OTP codes).
 * Creates the database file and schema on first use.
 */
class Database
{
    private static ?PDO $pdo = null;

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $dir = __DIR__ . '/../data';
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $path = $dir . '/app.sqlite';
        $pdo = new PDO('sqlite:' . $path);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('PRAGMA foreign_keys = ON');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_codes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NOT NULL,
                code TEXT NOT NULL,
                created_at INTEGER NOT NULL,
                expires_at INTEGER NOT NULL,
                consumed INTEGER NOT NULL DEFAULT 0
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_phone ON otp_codes (phone)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS otp_verify_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NOT NULL,
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_otp_attempts_phone ON otp_verify_attempts (phone)');

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                phone TEXT NOT NULL UNIQUE,
                name TEXT NOT NULL DEFAULT \'\',
                role TEXT NOT NULL CHECK (role IN (\'superadmin\', \'admin\', \'shop_manager\')),
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS sites (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                store_url TEXT NOT NULL,
                consumer_key TEXT NOT NULL,
                consumer_secret TEXT NOT NULL,
                verify_ssl INTEGER NOT NULL DEFAULT 1,
                created_at INTEGER NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_sites (
                user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
                site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
                PRIMARY KEY (user_id, site_id)
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            )'
        );

        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS activity_logs (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                user_name TEXT NOT NULL DEFAULT \'\',
                user_phone TEXT NOT NULL DEFAULT \'\',
                site_id INTEGER,
                category TEXT NOT NULL DEFAULT \'site\',
                action TEXT NOT NULL,
                message_key TEXT NOT NULL,
                message_params TEXT NOT NULL DEFAULT \'[]\',
                undo_data TEXT,
                undone INTEGER NOT NULL DEFAULT 0,
                created_at INTEGER NOT NULL
            )'
        );
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_user ON activity_logs (user_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_site ON activity_logs (site_id)');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_activity_logs_created ON activity_logs (created_at)');

        self::$pdo = $pdo;
        return $pdo;
    }
}
