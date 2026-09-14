<?php
/**
 * Air Link WiFi - Database Connection & Abstraction
 * Uses PDO with prepared statements, strict error handling, and connection pooling.
 */

declare(strict_types=1);

require_once __DIR__ . '/config.php';

class Database {
    private static ?PDO $instance = null;
    private static array $settingsCache = [];

    /**
     * Get or initialize the PDO database connection singleton
     */
    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                DB_HOST,
                DB_PORT,
                DB_NAME
            );

            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+03:00'",
            ];

            try {
                self::$instance = new PDO($dsn, DB_USER, DB_PASSWORD, $options);
            } catch (PDOException $e) {
                // Private logging, don't expose DB credentials to client
                if (defined('APP_DEBUG') && APP_DEBUG) {
                    throw new RuntimeException("Database connection error: " . $e->getMessage(), (int)$e->getCode());
                }
                throw new RuntimeException("Unable to connect to the authentication database. Please check system configuration.");
            }
        }

        return self::$instance;
    }

    /**
     * Fetch a system setting by key from `settings` table with cache
     */
    public static function getSetting(string $key, ?string $default = null): ?string {
        if (isset(self::$settingsCache[$key])) {
            return self::$settingsCache[$key];
        }

        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare("SELECT `setting_value` FROM `settings` WHERE `setting_key` = :key LIMIT 1");
            $stmt->execute(['key' => $key]);
            $row = $stmt->fetch();

            $value = $row ? (string)$row['setting_value'] : $default;
            self::$settingsCache[$key] = $value;
            return $value;
        } catch (Throwable) {
            return $default;
        }
    }

    /**
     * Update or insert a system setting
     */
    public static function setSetting(string $key, string $value, string $group = 'general', ?string $desc = null): bool {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`)
                VALUES (:key, :val, :grp, :desc)
                ON DUPLICATE KEY UPDATE `setting_value` = VALUES(`setting_value`), `updated_at` = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                'key'  => $key,
                'val'  => $value,
                'grp'  => $group,
                'desc' => $desc,
            ]);
            self::$settingsCache[$key] = $value;
            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Fetch all settings as key-value pairs
     */
    public static function getAllSettings(): array {
        try {
            $pdo = self::getConnection();
            $stmt = $pdo->query("SELECT `setting_key`, `setting_value`, `setting_group`, `description` FROM `settings`");
            $result = [];
            while ($row = $stmt->fetch()) {
                $result[$row['setting_key']] = $row['setting_value'];
                self::$settingsCache[$row['setting_key']] = $row['setting_value'];
            }
            return $result;
        } catch (Throwable) {
            return [];
        }
    }
}
