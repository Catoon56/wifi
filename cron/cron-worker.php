<?php
/**
 * Air Link WiFi - Automated Background Maintenance Worker
 * 
 * Scheduled Task / Cron Job:
 * 1. Scans and updates expired vouchers (expires_at <= NOW()).
 * 2. Closes expired Wi-Fi client sessions and kicks devices from Omada Controller.
 * 3. Rotates and purges audit logs older than retention policy (90 days).
 * 
 * Usage:
 * CLI: php /var/www/html/airlink/cron/cron-worker.php
 * Web: https://portal.airlinkwifi.co.tz/cron/cron-worker.php?key=YOUR_CRON_KEY
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/VoucherService.php';
require_once __DIR__ . '/../services/SessionService.php';
require_once __DIR__ . '/../services/LoggerService.php';

$isCli = (php_sapi_name() === 'cli');

// Basic web security check if called via HTTP
if (!$isCli) {
    $providedKey = $_GET['key'] ?? '';
    $cronKey = Database::getSetting('cron_security_key', 'airlink_cron_secure_key');
    if (!hash_equals($cronKey, $providedKey)) {
        http_response_code(403);
        die("Forbidden. Invalid cron execution key.");
    }
}

$startTime = microtime(true);
$pdo = Database::getConnection();

// 1. Expire vouchers whose timer has elapsed
$expiredVouchersCount = VoucherService::checkAndExpireVouchers();

// 2. Terminate and kick expired Wi-Fi sessions from Omada Controller
$closedSessionsCount = SessionService::closeExpiredSessions();

// 3. Purge old audit logs older than 90 days
$retentionDays = 90;
$purgeStmt = $pdo->prepare("DELETE FROM `audit_logs` WHERE `timestamp` < DATE_SUB(NOW(), INTERVAL :days DAY)");
$purgeStmt->execute(['days' => $retentionDays]);
$purgedLogsCount = $purgeStmt->rowCount();

$duration = round((microtime(true) - $startTime) * 1000, 2);
$summary = "Cron finished in {$duration}ms. Expired vouchers: $expiredVouchersCount, Closed sessions: $closedSessionsCount, Purged logs: $purgedLogsCount.";

LoggerService::fileLog('app', "[CRON] $summary");

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Air Link Cron: $summary\n";
} else {
    header('Content-Type: application/json');
    echo json_encode([
        'success'               => true,
        'duration_ms'           => $duration,
        'expired_vouchers'      => $expiredVouchersCount,
        'closed_sessions'       => $closedSessionsCount,
        'purged_logs'           => $purgedLogsCount,
    ]);
}
