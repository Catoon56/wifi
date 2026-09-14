<?php
/**
 * Air Link WiFi - Client Session Management Service
 * Manages active Wi-Fi sessions, heartbeats, disconnects, and administrator terminations.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/OmadaService.php';
require_once __DIR__ . '/LoggerService.php';

class SessionService {
    /**
     * Record a new or existing Wi-Fi session
     */
    public static function recordSession(
        int $voucherId,
        string $clientMac,
        string $clientIp,
        ?string $apMac = null,
        ?string $ssidName = null,
        int $remainingSeconds = 0,
        ?string $customSessionId = null
    ): string {
        $pdo = Database::getConnection();
        $sessionId = $customSessionId ?: ('SESS-' . strtoupper(bin2hex(random_bytes(8))));

        $stmt = $pdo->prepare("
            INSERT INTO `wifi_sessions` 
            (`session_id`, `voucher_id`, `client_mac`, `client_ip`, `ap_mac`, `ssid_name`, `login_time`, `last_seen`, `remaining_time`, `status`)
            VALUES (:sid, :vid, :mac, :ip, :ap, :ssid, NOW(), NOW(), :rem, 'active')
            ON DUPLICATE KEY UPDATE 
                `client_ip` = VALUES(`client_ip`),
                `ap_mac` = COALESCE(VALUES(`ap_mac`), `ap_mac`),
                `ssid_name` = COALESCE(VALUES(`ssid_name`), `ssid_name`),
                `last_seen` = NOW(),
                `remaining_time` = VALUES(`remaining_time`),
                `status` = 'active',
                `updated_at` = NOW()
        ");

        $stmt->execute([
            'sid'  => $sessionId,
            'vid'  => $voucherId,
            'mac'  => strtoupper(trim($clientMac)),
            'ip'   => $clientIp,
            'ap'   => $apMac ? strtoupper(trim($apMac)) : null,
            'ssid' => $ssidName,
            'rem'  => $remainingSeconds,
        ]);

        return $sessionId;
    }

    /**
     * Heartbeat check to update last_seen and calculate exact remaining time
     */
    public static function heartbeat(string $sessionId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT s.*, v.`expires_at`, v.`status` as `voucher_status`, v.`voucher_code`
            FROM `wifi_sessions` s
            JOIN `vouchers` v ON s.`voucher_id` = v.`id`
            WHERE s.`session_id` = :sid
            LIMIT 1
        ");
        $stmt->execute(['sid' => $sessionId]);
        $session = $stmt->fetch();

        if (!$session) {
            return ['active' => false, 'error' => 'Session not found.'];
        }

        if ($session['status'] !== 'active' || $session['voucher_status'] !== 'active') {
            return [
                'active'  => false,
                'status'  => $session['status'],
                'message' => 'Session is no longer active.',
            ];
        }

        $now = time();
        $expiresAt = strtotime($session['expires_at']);
        $remaining = max(0, $expiresAt - $now);

        if ($remaining <= 0) {
            // Mark session expired
            $upd = $pdo->prepare("UPDATE `wifi_sessions` SET `status` = 'expired', `remaining_time` = 0, `disconnect_time` = NOW() WHERE `id` = :id");
            $upd->execute(['id' => $session['id']]);

            // Deauthorize on Omada
            $omada = new OmadaService();
            $omada->deauthorizeClient($session['client_mac']);

            return ['active' => false, 'remaining_seconds' => 0, 'status' => 'expired'];
        }

        // Update heartbeat in DB
        $upd = $pdo->prepare("UPDATE `wifi_sessions` SET `last_seen` = NOW(), `remaining_time` = :rem WHERE `id` = :id");
        $upd->execute(['rem' => $remaining, 'id' => $session['id']]);

        return [
            'active'            => true,
            'remaining_seconds' => $remaining,
            'expires_at'        => $session['expires_at'],
            'status'            => 'active',
        ];
    }

    /**
     * Terminate an active session (Admin action or manual kick)
     */
    public static function terminateSession(string $sessionId, ?string $adminUser = null): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT s.*, v.`voucher_code` 
            FROM `wifi_sessions` s 
            JOIN `vouchers` v ON s.`voucher_id` = v.`id` 
            WHERE s.`session_id` = :sid LIMIT 1
        ");
        $stmt->execute(['sid' => $sessionId]);
        $sess = $stmt->fetch();

        if (!$sess) {
            return ['success' => false, 'error' => 'Session not found.'];
        }

        // 1. Kick client on Omada Controller
        $omada = new OmadaService();
        $deauthRes = $omada->deauthorizeClient($sess['client_mac']);

        // 2. Update session in DB
        $upd = $pdo->prepare("
            UPDATE `wifi_sessions` 
            SET `status` = 'terminated', `disconnect_time` = NOW(), `remaining_time` = 0 
            WHERE `id` = :id
        ");
        $upd->execute(['id' => $sess['id']]);

        LoggerService::log(
            'admin',
            $adminUser ?? 'admin',
            'SESSION_TERMINATED',
            null,
            'success',
            "Terminated session {$sess['session_id']} for MAC {$sess['client_mac']} (Voucher: {$sess['voucher_code']})."
        );

        return [
            'success'   => true,
            'message'   => "Session successfully terminated for {$sess['client_mac']}.",
            'deauth'    => $deauthRes,
        ];
    }

    /**
     * Background routine to sweep and deauthorize expired sessions
     */
    public static function closeExpiredSessions(): int {
        $pdo = Database::getConnection();

        $stmt = $pdo->query("
            SELECT s.`id`, s.`client_mac`, s.`session_id`
            FROM `wifi_sessions` s
            JOIN `vouchers` v ON s.`voucher_id` = v.`id`
            WHERE s.`status` = 'active' AND v.`expires_at` IS NOT NULL AND v.`expires_at` <= NOW()
        ");

        $expired = $stmt->fetchAll();
        $count = 0;
        $omada = new OmadaService();

        foreach ($expired as $row) {
            $omada->deauthorizeClient($row['client_mac']);
            $upd = $pdo->prepare("UPDATE `wifi_sessions` SET `status` = 'expired', `disconnect_time` = NOW(), `remaining_time` = 0 WHERE `id` = :id");
            $upd->execute(['id' => $row['id']]);
            $count++;
        }

        return $count;
    }
}
