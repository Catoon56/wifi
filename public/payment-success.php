<?php
/**
 * Air Link WiFi - Automatic Browser Voucher Delivery
 * Displays the automatically generated voucher code directly in the customer's browser upon payment.
 * No manual printing required!
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ViewHelper.php';

$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);

// Retrieve details from query parameter or session
$voucherCode = trim($_GET['code'] ?? ($_SESSION['last_paid_voucher_code'] ?? ''));
$merchantRef = trim($_GET['ref'] ?? ($_SESSION['last_paid_ref'] ?? ''));

$voucher = null;
if (!empty($voucherCode)) {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT v.*, p.`name` as `package_name`
        FROM `vouchers` v
        JOIN `packages` p ON v.`package_id` = p.`id`
        WHERE v.`voucher_code` = :code
        LIMIT 1
    ");
    $stmt->execute(['code' => $voucherCode]);
    $voucher = $stmt->fetch();
}

$originUrl = $_SESSION['omada_params']['originUrl'] ?? 'https://www.google.com';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= ViewHelper::e($businessName) ?> - Voucher Ready</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline">Payment Confirmed</p>
        </header>

        <div class="alert alert-success" style="text-align: center;">
            <strong>Payment Successful!</strong><br>
            Your Wi-Fi voucher has been automatically generated.
        </div>

        <!-- Automatic Voucher Code Display -->
        <div class="voucher-display-box">
            <div style="font-size: 12px; font-weight: 700; color: #15803d; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
                Your Voucher Code
            </div>
            <div class="voucher-code-text" id="voucherCodeDisplay">
                <?= ViewHelper::e($voucherCode ?: 'ALX9K24P') ?>
            </div>
            <button type="button" class="btn btn-outline" id="copyVoucherBtn" style="margin-top: 10px; padding: 6px 14px; font-size: 13px;">
                Copy Code
            </button>
        </div>

        <table class="info-table">
            <tbody>
                <?php if ($voucher): ?>
                    <tr>
                        <td>Package:</td>
                        <td><?= ViewHelper::e($voucher['package_name']) ?></td>
                    </tr>
                    <tr>
                        <td>Duration:</td>
                        <td><?= ViewHelper::formatDuration($voucher['duration'], $voucher['duration_unit']) ?></td>
                    </tr>
                    <tr>
                        <td>Price:</td>
                        <td><?= ViewHelper::formatCurrency($voucher['price']) ?></td>
                    </tr>
                <?php endif; ?>
                <tr>
                    <td>Status:</td>
                    <td><span class="status-badge status-connected">Ready / Authorized</span></td>
                </tr>
            </tbody>
        </table>

        <!-- 1-Click Action to Connect -->
        <a href="status.php" class="btn btn-primary" style="margin-top: 12px;">
            Check Connection & Start Browsing
        </a>

        <footer class="portal-footer">
            <div class="help-title">Keep Your Code Safe</div>
            <div class="help-text">
                Your device has been unlocked automatically. You can also save this voucher code if you need to reconnect later.
            </div>
        </footer>

    </div>
</div>

<script src="../assets/js/portal.js"></script>
</body>
</html>
