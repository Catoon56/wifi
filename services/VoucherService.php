<?php
/**
 * Air Link WiFi - Voucher Business Logic & Lifecycle Engine
 * 
 * Rules:
 * 1. Timer DOES NOT begin upon generation. Timer starts when customer first activates it.
 * 2. Device locks enforce max_devices per package (prevents code sharing/theft).
 * 3. Safe, unambiguous random code generation (no 0/O, 1/I/L).
 * 4. Automatic generation & issuance for digital payments (SonicPesa).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/OmadaService.php';
require_once __DIR__ . '/SessionService.php';
require_once __DIR__ . '/LoggerService.php';

class VoucherService {
    // Unambiguous characters for readable, unguessable vouchers
    private const CODE_CHARS = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';

    /**
     * Generate a cryptographically secure random voucher code
     */
    public static function generateCode(int $length = 8, string $prefix = 'AL'): string {
        $maxIndex = strlen(self::CODE_CHARS) - 1;
        $code = '';
        for ($i = 0; $i < $length; $i++) {
            $code .= self::CODE_CHARS[random_int(0, $maxIndex)];
        }
        return $prefix . $code;
    }

    /**
     * Create a single voucher
     */
    public static function createVoucher(int $packageId, ?int $adminId = null, ?string $batchId = null): array {
        $pdo = Database::getConnection();

        $stmtPkg = $pdo->prepare("SELECT * FROM `packages` WHERE `id` = :id AND `status` = 'active' LIMIT 1");
        $stmtPkg->execute(['id' => $packageId]);
        $pkg = $stmtPkg->fetch();

        if (!$pkg) {
            return ['success' => false, 'error' => 'Selected package does not exist or is inactive.'];
        }

        // Retry loop to ensure unique voucher code
        $maxAttempts = 10;
        $inserted = false;
        $voucherCode = '';

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $voucherCode = self::generateCode(8, 'AL');

            try {
                $stmt = $pdo->prepare("
                    INSERT INTO `vouchers` 
                    (`voucher_code`, `package_id`, `price`, `duration`, `duration_unit`, `status`, `created_by`, `batch_id`)
                    VALUES (:code, :pkg_id, :price, :duration, :unit, 'unused', :admin_id, :batch_id)
                ");
                $stmt->execute([
                    'code'      => $voucherCode,
                    'pkg_id'    => $pkg['id'],
                    'price'     => $pkg['price'],
                    'duration'  => $pkg['duration'],
                    'unit'      => $pkg['duration_unit'],
                    'admin_id'  => $adminId,
                    'batch_id'  => $batchId,
                ]);
                $voucherId = (int)$pdo->lastInsertId();
                $inserted = true;
                break;
            } catch (PDOException $e) {
                // If duplicate key error (23000 / 1062), retry with another code
                if ($e->getCode() == 23000 || $e->errorInfo[1] == 1062) {
                    continue;
                }
                throw $e;
            }
        }

        if (!$inserted) {
            return ['success' => false, 'error' => 'Failed to generate a unique voucher code. Please try again.'];
        }

        LoggerService::log('admin', (string)$adminId, 'VOUCHER_CREATE', null, 'success', "Created voucher $voucherCode for package {$pkg['name']}.");

        return [
            'success'      => true,
            'voucher_id'   => $voucherId,
            'voucher_code' => $voucherCode,
            'package_name' => $pkg['name'],
            'price'        => $pkg['price'],
            'duration'     => $pkg['duration'],
            'unit'         => $pkg['duration_unit'],
        ];
    }

    /**
     * Generate multiple vouchers in batch
     */
    public static function createBatch(int $packageId, int $quantity, ?int $adminId = null): array {
        if ($quantity < 1 || $quantity > 500) {
            return ['success' => false, 'error' => 'Quantity must be between 1 and 500 vouchers per batch.'];
        }

        $batchId = 'BATCH-' . date('YmdHis') . '-' . strtoupper(bin2hex(random_bytes(3)));
        $createdCodes = [];

        for ($i = 0; $i < $quantity; $i++) {
            $result = self::createVoucher($packageId, $adminId, $batchId);
            if ($result['success']) {
                $createdCodes[] = $result;
            }
        }

        return [
            'success'  => count($createdCodes) > 0,
            'batch_id' => $batchId,
            'count'    => count($createdCodes),
            'vouchers' => $createdCodes,
        ];
    }

    /**
     * Validate and activate a voucher for a customer device
     * 
     * @param string $code Raw voucher code entered by user
     * @param string $clientMac Client device MAC address
     * @param string $clientIp Client device IP address
     * @param array $omadaParams Parameters supplied by Omada redirect (apMac, ssidName, radioId, etc.)
     * @return array
     */
    public static function activateVoucher(
        string $code,
        string $clientMac,
        string $clientIp,
        array $omadaParams = []
    ): array {
        $code = strtoupper(trim($code));
        $clientMac = strtoupper(trim($clientMac));
        $pdo = Database::getConnection();

        if (empty($code)) {
            return ['success' => false, 'error' => 'Please enter your voucher code.'];
        }

        // 1. Fetch voucher and package details
        $stmt = $pdo->prepare("
            SELECT v.*, p.`name` as `package_name`, p.`max_devices`, p.`download_speed`, p.`upload_speed`
            FROM `vouchers` v
            JOIN `packages` p ON v.`package_id` = p.`id`
            WHERE v.`voucher_code` = :code
            LIMIT 1
        ");
        $stmt->execute(['code' => $code]);
        $voucher = $stmt->fetch();

        if (!$voucher) {
            LoggerService::log('customer', $clientMac, 'VOUCHER_INVALID', $clientIp, 'failure', "Code entered: $code");
            return ['success' => false, 'error' => 'Invalid voucher code. Please check and try again.'];
        }

        // 2. Validate Voucher Status
        if ($voucher['status'] === 'disabled') {
            LoggerService::log('customer', $clientMac, 'VOUCHER_DISABLED', $clientIp, 'failure', "Voucher {$voucher['voucher_code']} is disabled.");
            return ['success' => false, 'error' => 'This voucher has been disabled. Please contact Air Link support.'];
        }

        $now = time();

        // 3. Handle Already Active Voucher (Reconnection / Device check)
        if ($voucher['status'] === 'active') {
            $expiresAt = strtotime($voucher['expires_at'] ?? '1970-01-01');

            // Has it expired in the database?
            if ($now >= $expiresAt) {
                self::markExpired((int)$voucher['id']);
                LoggerService::log('customer', $clientMac, 'VOUCHER_EXPIRED', $clientIp, 'failure', "Voucher {$voucher['voucher_code']} has expired.");
                return ['success' => false, 'error' => 'This voucher has expired.'];
            }

            // Check device lock
            if ((int)$voucher['max_devices'] <= 1) {
                $boundMac = strtoupper(trim((string)$voucher['client_mac']));
                if (!empty($boundMac) && !empty($clientMac) && $boundMac !== $clientMac) {
                    LoggerService::log('customer', $clientMac, 'VOUCHER_MAC_MISMATCH', $clientIp, 'failure', "MAC $clientMac tried to use voucher bound to $boundMac");
                    return [
                        'success' => false,
                        'error'   => 'This voucher is already active on another device and cannot be shared.',
                    ];
                }
            }

            // Client is reconnecting: Re-authorize with Omada Controller
            $remainingSeconds = max(0, $expiresAt - $now);
            $remainingMinutes = (int)ceil($remainingSeconds / 60);

            $omadaService = new OmadaService();
            $authRes = $omadaService->authorizeClient(
                $clientMac,
                $omadaParams['apMac'] ?? $voucher['client_mac'] ?? null,
                $omadaParams['ssidName'] ?? null,
                (int)($omadaParams['radioId'] ?? 0),
                $remainingMinutes
            );

            if (!$authRes['success']) {
                return [
                    'success' => false,
                    'error'   => 'Internet access could not be authorized. Please contact Air Link support.',
                    'details' => $authRes['message'],
                ];
            }

            // Record or update session
            $sessionId = SessionService::recordSession(
                (int)$voucher['id'],
                $clientMac,
                $clientIp,
                $omadaParams['apMac'] ?? null,
                $omadaParams['ssidName'] ?? null,
                $remainingSeconds
            );

            LoggerService::log('customer', $clientMac, 'VOUCHER_RECONNECT', $clientIp, 'success', "Reconnected on voucher {$voucher['voucher_code']}.");

            return [
                'success'           => true,
                'status'            => 'active',
                'voucher_code'      => $voucher['voucher_code'],
                'package_name'      => $voucher['package_name'],
                'activated_at'      => $voucher['activated_at'],
                'expires_at'        => $voucher['expires_at'],
                'remaining_seconds' => $remainingSeconds,
                'session_id'        => $sessionId,
            ];
        }

        // 4. Handle Unused Voucher (First-time Activation)
        if ($voucher['status'] === 'unused') {
            // Calculate validity window starting right now
            $duration = (int)$voucher['duration'];
            $unit = strtolower((string)$voucher['duration_unit']);

            $durationMinutes = match ($unit) {
                'minute', 'minutes' => $duration,
                'hour', 'hours'     => $duration * 60,
                'day', 'days'       => $duration * 1440,
                default             => $duration * 60,
            };

            $activatedTime = date('Y-m-d H:i:s', $now);
            $expiresTime   = date('Y-m-d H:i:s', $now + ($durationMinutes * 60));
            $sessionId     = 'SESS-' . strtoupper(bin2hex(random_bytes(8)));

            // Authorize on Omada Controller first
            $omadaService = new OmadaService();
            $authRes = $omadaService->authorizeClient(
                $clientMac,
                $omadaParams['apMac'] ?? null,
                $omadaParams['ssidName'] ?? null,
                (int)($omadaParams['radioId'] ?? 0),
                $durationMinutes
            );

            if (!$authRes['success']) {
                return [
                    'success' => false,
                    'error'   => 'Internet access could not be authorized on the controller. Please contact Air Link support.',
                    'details' => $authRes['message'],
                ];
            }

            // Update voucher in MySQL to active state
            $upd = $pdo->prepare("
                UPDATE `vouchers` 
                SET `status` = 'active',
                    `activated_at` = :act,
                    `expires_at`   = :exp,
                    `client_mac`   = :mac,
                    `client_ip`    = :ip,
                    `session_id`   = :sess
                WHERE `id` = :id
            ");
            $upd->execute([
                'act'  => $activatedTime,
                'exp'  => $expiresTime,
                'mac'  => $clientMac,
                'ip'   => $clientIp,
                'sess' => $sessionId,
                'id'   => $voucher['id'],
            ]);

            // Create initial session entry
            SessionService::recordSession(
                (int)$voucher['id'],
                $clientMac,
                $clientIp,
                $omadaParams['apMac'] ?? null,
                $omadaParams['ssidName'] ?? null,
                $durationMinutes * 60,
                $sessionId
            );

            LoggerService::log('customer', $clientMac, 'VOUCHER_ACTIVATED', $clientIp, 'success', "Voucher {$voucher['voucher_code']} activated for $durationMinutes mins.");

            return [
                'success'           => true,
                'status'            => 'active',
                'voucher_code'      => $voucher['voucher_code'],
                'package_name'      => $voucher['package_name'],
                'activated_at'      => $activatedTime,
                'expires_at'        => $expiresTime,
                'remaining_seconds' => $durationMinutes * 60,
                'session_id'        => $sessionId,
            ];
        }

        return ['success' => false, 'error' => 'This voucher has expired or is invalid.'];
    }

    /**
     * Automatically generate and issue a voucher upon online payment confirmation (SonicPesa)
     * No manual printing required! The voucher code is stored and immediately returned.
     */
    public static function generateAndIssueVoucher(
        int $packageId,
        ?string $customerPhone,
        ?string $clientMac = null,
        string $paymentMethod = 'SonicPesa',
        ?string $transactionRef = null,
        array $omadaParams = []
    ): array {
        $pdo = Database::getConnection();

        // 1. Create the new voucher in DB
        $voucherRes = self::createVoucher($packageId, null, 'ONLINE-' . date('Ymd'));
        if (!$voucherRes['success']) {
            return $voucherRes;
        }

        $voucherId   = $voucherRes['voucher_id'];
        $voucherCode = $voucherRes['voucher_code'];
        $price       = $voucherRes['price'];

        // 2. Record sale entry
        $saleStmt = $pdo->prepare("
            INSERT INTO `sales` 
            (`voucher_id`, `package_id`, `price`, `payment_method`, `transaction_reference`, `customer_phone`, `sale_date`, `status`)
            VALUES (:vid, :pid, :price, :method, :ref, :phone, NOW(), 'completed')
        ");
        $saleStmt->execute([
            'vid'    => $voucherId,
            'pid'    => $packageId,
            'price'  => $price,
            'method' => $paymentMethod,
            'ref'    => $transactionRef,
            'phone'  => $customerPhone,
        ]);

        LoggerService::log('system', $customerPhone ?? 'customer', 'ONLINE_VOUCHER_ISSUED', null, 'success', "Generated voucher $voucherCode via $paymentMethod ref: $transactionRef");

        // 3. If client MAC is provided from the customer's portal session, auto-activate immediately!
        $autoActivated = false;
        $activationData = [];
        if (!empty($clientMac)) {
            $clientIp = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            $actRes = self::activateVoucher($voucherCode, $clientMac, $clientIp, $omadaParams);
            if ($actRes['success']) {
                $autoActivated = true;
                $activationData = $actRes;
            }
        }

        return [
            'success'        => true,
            'voucher_id'     => $voucherId,
            'voucher_code'   => $voucherCode,
            'package_name'   => $voucherRes['package_name'],
            'price'          => $price,
            'duration'       => $voucherRes['duration'],
            'unit'           => $voucherRes['unit'],
            'auto_activated' => $autoActivated,
            'activation'     => $activationData,
        ];
    }

    /**
     * Mark a voucher as expired in MySQL
     */
    public static function markExpired(int $voucherId): void {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("UPDATE `vouchers` SET `status` = 'expired' WHERE `id` = :id");
        $stmt->execute(['id' => $voucherId]);
    }

    /**
     * Background scanner to update expired vouchers
     */
    public static function checkAndExpireVouchers(): int {
        $pdo = Database::getConnection();
        $stmt = $pdo->query("
            UPDATE `vouchers` 
            SET `status` = 'expired' 
            WHERE `status` = 'active' AND `expires_at` IS NOT NULL AND `expires_at` <= NOW()
        ");
        return $stmt->rowCount();
    }
}
