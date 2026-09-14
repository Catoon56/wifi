<?php
/**
 * Air Link WiFi - Customer Captive Portal
 * Brand: Air Link | Tagline: Reliable Internet. Simple Access.
 * Hardware: TTCL Internet -> TP-Link EAP110 Outdoor -> Omada External Web Portal
 * 
 * Features:
 * - Detects active vouchers for disconnected returning devices (Zero re-payment!)
 * - 1-Click Reconnect to Internet if active voucher exists
 * - Standard voucher entry
 * - Online purchase option via SonicPesa
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CSRF.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../includes/ViewHelper.php';
require_once __DIR__ . '/../services/VoucherService.php';
require_once __DIR__ . '/../services/OmadaService.php';
require_once __DIR__ . '/../services/LoggerService.php';

$pdo = Database::getConnection();

// 1. Capture and preserve Omada redirect parameters
// Omada passes: clientMac, apMac, ssidName, radioId, target, originUrl, site, t
if (!isset($_SESSION['omada_params'])) {
    $_SESSION['omada_params'] = [];
}

$paramKeys = ['clientMac', 'apMac', 'ssidName', 'radioId', 'target', 'originUrl', 'site', 't', 'gatewayMac', 'vid'];
foreach ($paramKeys as $key) {
    if (isset($_GET[$key]) && $_GET[$key] !== '') {
        $_SESSION['omada_params'][$key] = trim((string)$_GET[$key]);
    }
}

$omadaParams = $_SESSION['omada_params'];
$clientIp    = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$clientMac   = !empty($omadaParams['clientMac']) ? ViewHelper::normalizeMac($omadaParams['clientMac']) : '';

// 2. Fetch dynamic business info from settings
$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);
$supportPhone = Database::getSetting('support_phone', '+255 700 000 000');
$supportWa    = Database::getSetting('support_whatsapp', '+255 700 000 000');
$ssidName     = $omadaParams['ssidName'] ?? Database::getSetting('wifi_ssid', 'Air Link WiFi');

$errorMessage = null;
$successMessage = null;

// 3. Check if this device already has an active, unexpired voucher in MySQL
$activeVoucherForDevice = null;
$deviceRemainingSeconds = 0;
if (!empty($clientMac)) {
    $checkStmt = $pdo->prepare("
        SELECT v.*, p.`name` as `package_name`
        FROM `vouchers` v
        JOIN `packages` p ON v.`package_id` = p.`id`
        WHERE v.`client_mac` = :mac 
          AND v.`status` = 'active' 
          AND v.`expires_at` IS NOT NULL 
          AND v.`expires_at` > NOW()
        ORDER BY v.`expires_at` DESC LIMIT 1
    ");
    $checkStmt->execute(['mac' => $clientMac]);
    $activeVoucherForDevice = $checkStmt->fetch();

    if ($activeVoucherForDevice) {
        $deviceRemainingSeconds = max(0, strtotime($activeVoucherForDevice['expires_at']) - time());
    }
}

// 4. Handle Form Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();

    $voucherCode = strtoupper(trim($_POST['voucher_code'] ?? ''));
    $submittedMac = !empty($_POST['client_mac']) ? ViewHelper::normalizeMac($_POST['client_mac']) : $clientMac;

    // Check brute-force rate limiter (by IP and MAC)
    $maxAttempts = (int)Database::getSetting('max_voucher_attempts', defined('MAX_VOUCHER_ATTEMPTS') ? (string)MAX_VOUCHER_ATTEMPTS : '5');
    $lockoutMin  = (int)Database::getSetting('lockout_duration_minutes', defined('LOCKOUT_MINUTES') ? (string)LOCKOUT_MINUTES : '15');
    $rateKey = 'portal_try_' . md5($clientIp . '_' . $submittedMac);
    if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts, $lockoutMin * 60)) {
        $cooldown = ceil(RateLimiter::availableIn($rateKey) / 60);
        $errorMessage = "Too many failed attempts. Please wait $cooldown minute(s) before trying again.";
    } elseif (empty($voucherCode)) {
        $errorMessage = "Please enter your voucher code.";
    } else {
        // Attempt voucher validation & Omada authorization (Reconnecting devices authorized for remaining time)
        $result = VoucherService::activateVoucher(
            $voucherCode,
            $submittedMac ?: 'UNKNOWN-MAC',
            $clientIp,
            $omadaParams
        );

        if ($result['success']) {
            RateLimiter::clear($rateKey);
            $_SESSION['active_session_id']   = $result['session_id'];
            $_SESSION['active_voucher_code'] = $result['voucher_code'];
            $_SESSION['active_package_name'] = $result['package_name'];
            $_SESSION['active_expires_at']   = $result['expires_at'];
            $_SESSION['active_activated_at'] = $result['activated_at'];

            header("Location: status.php");
            exit;
        } else {
            RateLimiter::hit($rateKey, $lockoutMin * 60);
            $errorMessage = $result['error'] ?? 'Invalid voucher code.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= ViewHelper::e($businessName) ?> - Internet Access</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
        .reconnect-card {
            background-color: #f0fdf4;
            border: 1.5px solid #86efac;
            border-radius: 6px;
            padding: 16px;
            margin-bottom: 20px;
            text-align: center;
        }
        .reconnect-badge {
            display: inline-block;
            background: #15803d;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 2px 8px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 8px;
        }
        .reconnect-time {
            font-size: 18px;
            font-weight: 800;
            color: #166534;
            margin: 6px 0;
            font-family: monospace;
        }
    </style>
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <!-- Portal Header -->
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline"><?= ViewHelper::e($tagline) ?></p>
        </header>

        <!-- Dynamic Alert Message -->
        <div id="portalAlert" class="alert <?= $errorMessage ? 'alert-danger' : '' ?>" style="<?= $errorMessage ? 'display:block;' : 'display:none;' ?>">
            <?= ViewHelper::e($errorMessage ?? '') ?>
        </div>

        <?php if ($activeVoucherForDevice && $deviceRemainingSeconds > 0): ?>
            <!-- RETURNING USER AUTO-RECONNECT BANNER -->
            <div class="reconnect-card">
                <span class="reconnect-badge">Active Session Found</span>
                <div style="font-weight: 700; color: #166534; font-size: 15px;">
                    Welcome back!
                </div>
                <div style="font-size: 13px; color: #374151; margin-top: 4px;">
                    Your <strong><?= ViewHelper::e($activeVoucherForDevice['package_name']) ?></strong> voucher is still active.
                </div>
                <div class="reconnect-time">
                    <?= ViewHelper::formatRemainingSeconds($deviceRemainingSeconds) ?> remaining
                </div>
                
                <form method="POST" action="index.php" style="margin-top: 10px;">
                    <?= CSRF::field() ?>
                    <input type="hidden" name="voucher_code" value="<?= ViewHelper::e($activeVoucherForDevice['voucher_code']) ?>">
                    <input type="hidden" name="client_mac" value="<?= ViewHelper::e($clientMac) ?>">
                    <button type="submit" class="btn btn-primary" style="background-color: #15803d; border-color: #15803d;">
                        Reconnect to Internet
                    </button>
                </form>
                <div style="font-size: 11.5px; color: #64748b; margin-top: 8px;">
                    No payment required. Your time continues until expiration.
                </div>
            </div>

            <div class="portal-divider">
                <span>Or Use Another Voucher</span>
            </div>
        <?php endif; ?>

        <!-- Voucher Login Form -->
        <form id="portalLoginForm" class="portal-form" method="POST" action="index.php">
            <?= CSRF::field() ?>
            <input type="hidden" name="client_mac" value="<?= ViewHelper::e($clientMac) ?>">

            <div class="form-group">
                <label for="voucher_code" class="form-label">Voucher Code</label>
                <input 
                    type="text" 
                    id="voucher_code" 
                    name="voucher_code" 
                    class="form-input" 
                    placeholder="Enter Code" 
                    maxlength="20"
                    autocomplete="off" 
                    autocorrect="off" 
                    autocapitalize="characters" 
                    spellcheck="false"
                    required
                >
            </div>

            <button type="submit" id="submitBtn" class="btn btn-primary">
                Connect to Internet
            </button>
        </form>

        <!-- Online Self-Service Purchase Option -->
        <div class="portal-divider">
            <span>Or</span>
        </div>

        <a href="buy.php" class="btn btn-outline">
            Buy Voucher Online (M-Pesa / Tigo / Airtel)
        </a>

        <!-- Portal Footer / Support -->
        <footer class="portal-footer">
            <div class="help-title">Need a voucher?</div>
            <div class="help-text">
                Contact the Air Link WiFi agent.<br>
                Call: <a href="tel:<?= ViewHelper::e($supportPhone) ?>" class="help-phone"><?= ViewHelper::e($supportPhone) ?></a>
                <?php if ($supportWa): ?>
                    | WhatsApp: <a href="https://wa.me/<?= preg_replace('/[^0-9]/', '', $supportWa) ?>" class="help-phone"><?= ViewHelper::e($supportWa) ?></a>
                <?php endif; ?>
            </div>
        </footer>

    </div>
</div>

<script src="../assets/js/portal.js"></script>
</body>
</html>
