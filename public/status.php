<?php
/**
 * Air Link WiFi - Customer Status Page
 * Displays active Wi-Fi connection status, package details, and live countdown.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ViewHelper.php';
require_once __DIR__ . '/../services/SessionService.php';

$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);

// Retrieve active session details from PHP session
$sessionId    = $_SESSION['active_session_id'] ?? '';
$voucherCode  = $_SESSION['active_voucher_code'] ?? '';
$packageName  = $_SESSION['active_package_name'] ?? 'Internet Voucher';
$activatedAt  = $_SESSION['active_activated_at'] ?? '';
$expiresAt    = $_SESSION['active_expires_at'] ?? '';
$originUrl    = $_SESSION['omada_params']['originUrl'] ?? 'https://www.google.com';

// Fallback: If session variables are missing, check if client MAC has active session in DB
if (empty($sessionId) && !empty($_SESSION['omada_params']['clientMac'])) {
    $pdo = Database::getConnection();
    $mac = ViewHelper::normalizeMac($_SESSION['omada_params']['clientMac']);
    $stmt = $pdo->prepare("
        SELECT s.`session_id`, v.`voucher_code`, p.`name` as `package_name`, v.`activated_at`, v.`expires_at`, s.`remaining_time`
        FROM `wifi_sessions` s
        JOIN `vouchers` v ON s.`voucher_id` = v.`id`
        JOIN `packages` p ON v.`package_id` = p.`id`
        WHERE s.`client_mac` = :mac AND s.`status` = 'active' AND v.`status` = 'active'
        ORDER BY s.`id` DESC LIMIT 1
    ");
    $stmt->execute(['mac' => $mac]);
    $found = $stmt->fetch();

    if ($found) {
        $sessionId   = $found['session_id'];
        $voucherCode = $found['voucher_code'];
        $packageName = $found['package_name'];
        $activatedAt = $found['activated_at'];
        $expiresAt   = $found['expires_at'];
    }
}

// Calculate remaining seconds
$now = time();
$expTs = !empty($expiresAt) ? strtotime($expiresAt) : 0;
$remainingSeconds = max(0, $expTs - $now);

if ($remainingSeconds <= 0 && !empty($expiresAt)) {
    $statusText = "Expired";
    $isConnected = false;
} else {
    $statusText = "Connected";
    $isConnected = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= ViewHelper::e($businessName) ?> - Connection Status</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline"><?= ViewHelper::e($tagline) ?></p>
        </header>

        <div class="countdown-box">
            <span class="status-badge <?= $isConnected ? 'status-connected' : 'status-danger' ?>" id="statusBadge">
                <?= ViewHelper::e($statusText) ?>
            </span>
            <div 
                id="countdownTimer" 
                class="countdown-digits" 
                data-remaining="<?= $remainingSeconds ?>"
                data-session-id="<?= ViewHelper::e($sessionId) ?>"
            >
                --:--:--
            </div>
            <div style="font-size: 12px; color: #64748b; text-transform: uppercase; letter-spacing: 0.5px;">Remaining Access Time</div>
        </div>

        <table class="info-table">
            <tbody>
                <tr>
                    <td>Package:</td>
                    <td><?= ViewHelper::e($packageName) ?></td>
                </tr>
                <?php if ($voucherCode): ?>
                <tr>
                    <td>Voucher Code:</td>
                    <td><strong style="font-family: monospace; letter-spacing: 1px;"><?= ViewHelper::e($voucherCode) ?></strong></td>
                </tr>
                <?php endif; ?>
                <tr>
                    <td>Activated:</td>
                    <td><?= ViewHelper::formatDateTime($activatedAt) ?></td>
                </tr>
                <tr>
                    <td>Expires:</td>
                    <td><?= ViewHelper::formatDateTime($expiresAt) ?></td>
                </tr>
            </tbody>
        </table>

        <?php if ($isConnected): ?>
            <a href="<?= ViewHelper::e($originUrl) ?>" class="btn btn-primary" style="margin-top: 12px;">
                Continue to Internet
            </a>
        <?php else: ?>
            <a href="index.php" class="btn btn-primary" style="margin-top: 12px;">
                Enter New Voucher
            </a>
        <?php endif; ?>

        <footer class="portal-footer">
            <div class="help-title">Air Link WiFi Support</div>
            <div class="help-text">
                Need help? Call <a href="tel:<?= ViewHelper::e(Database::getSetting('support_phone', '+255 700 000 000')) ?>" class="help-phone"><?= ViewHelper::e(Database::getSetting('support_phone', '+255 700 000 000')) ?></a>
            </div>
        </footer>

    </div>
</div>

<script src="../assets/js/portal.js"></script>
</body>
</html>
