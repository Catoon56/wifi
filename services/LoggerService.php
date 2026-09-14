<?php
/**
 * Air Link WiFi - Unified Audit & Activity Logging Service
 * Records events to database audit_logs table and rotates text log files.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

class LoggerService {
    /**
     * Record an audit log entry
     */
    public static function log(
        string $actorType,
        ?string $actorIdentifier,
        string $action,
        ?string $ipAddress = null,
        string $result = 'success',
        ?string $details = null
    ): void {
        $ip = $ipAddress ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');

        // 1. Write to database audit_logs table
        try {
            $pdo = Database::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO `audit_logs` 
                (`actor_type`, `actor_identifier`, `action`, `ip_address`, `result`, `details`) 
                VALUES (:actor, :ident, :act, :ip, :res, :det)
            ");
            $stmt->execute([
                'actor' => $actorType,
                'ident' => $actorIdentifier,
                'act'   => strtoupper($action),
                'ip'    => $ip,
                'res'   => $result,
                'det'   => $details,
            ]);
        } catch (Throwable $e) {
            // Fallback: don't let log write failure crash the application
            self::fileLog('app', "DB Audit Log Failure: " . $e->getMessage());
        }

        // 2. Also mirror to app file log
        $msg = sprintf(
            "[%s] [%s:%s] [%s] [%s] %s",
            strtoupper($result),
            $actorType,
            $actorIdentifier ?? 'none',
            strtoupper($action),
            $ip,
            $details ?? ''
        );
        self::fileLog('app', $msg);
    }

    /**
     * Write entry to a specific category log file in /logs/
     */
    public static function fileLog(string $channel, string $message): void {
        $logDir = DIR_LOGS;
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        $filename = $logDir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '', $channel) . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $line = "[$timestamp] $message" . PHP_EOL;

        @file_put_contents($filename, $line, FILE_APPEND | LOCK_EX);
    }
}
