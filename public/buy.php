<?php
/**
 * Air Link WiFi - Self-Service Online Voucher Purchase
 * Customer selects a package, enters mobile number, and pays via SonicPesa.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CSRF.php';
require_once __DIR__ . '/../includes/ViewHelper.php';
require_once __DIR__ . '/../services/SonicPesaService.php';

$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);
$pdo          = Database::getConnection();

// Fetch active Wi-Fi packages
$packages = $pdo->query("SELECT * FROM `packages` WHERE `status` = 'active' ORDER BY `price` ASC")->fetchAll();

$clientMac = $_SESSION['omada_params']['clientMac'] ?? '';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();

    $isSonicEnabled = Database::getSetting('sonicpesa_enabled', defined('SONICPESA_ENABLED') && SONICPESA_ENABLED ? '1' : '0');
    if ($isSonicEnabled !== '1') {
        $error = "Online payments are currently disabled.";
    } else {
        $packageId = (int)($_POST['package_id'] ?? 0);
        $phone = trim($_POST['phone'] ?? '');

        // Validate package
        $stmt = $pdo->prepare("SELECT * FROM `packages` WHERE `id` = :id AND `status` = 'active' LIMIT 1");
        $stmt->execute(['id' => $packageId]);
        $selectedPkg = $stmt->fetch();

        if (!$selectedPkg) {
            $error = "Please select a valid Wi-Fi package.";
        } elseif (empty($phone) || strlen(preg_replace('/[^0-9]/', '', $phone)) < 9) {
            $error = "Please enter a valid mobile phone number.";
        } else {
            $sonicpesa = new SonicPesaService();
            $orderRes = $sonicpesa->createOrder(
                (int)$selectedPkg['id'],
                (float)$selectedPkg['price'],
                $phone,
                $selectedPkg['name']
            );

            if ($orderRes['success'] && !empty($orderRes['redirect_url'])) {
                header("Location: " . $orderRes['redirect_url']);
                exit;
            } else {
                $error = $orderRes['error'] ?? "Unable to initialize payment. Please try again or contact support.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= ViewHelper::e($businessName) ?> - Buy Voucher Online</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
        .pkg-radio-group {
            display: flex;
            flex-direction: column;
            gap: 10px;
            margin: 14px 0;
        }
        .pkg-option {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border: 1.5px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            background: #ffffff;
            transition: border-color 0.15s ease;
        }
        .pkg-option:hover {
            border-color: #cbd5e1;
        }
        .pkg-option input[type="radio"]:checked + .pkg-details {
            color: #0b5ed7;
        }
        .pkg-option.selected {
            border-color: #0b5ed7;
            background-color: #f0f7ff;
        }
        .pkg-title {
            font-weight: 700;
            font-size: 15px;
        }
        .pkg-speed {
            font-size: 12px;
            color: #64748b;
        }
        .pkg-price {
            font-size: 16px;
            font-weight: 800;
            color: #0f172a;
        }
    </style>
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline">Select a package to pay via M-Pesa, Tigo Pesa, Airtel Money, or Card</p>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="buy.php">
            <?= CSRF::field() ?>

            <div class="form-group">
                <label class="form-label">Choose Package</label>
                <div class="pkg-radio-group">
                    <?php foreach ($packages as $idx => $pkg): ?>
                        <label class="pkg-option <?= $idx === 0 ? 'selected' : '' ?>">
                            <div style="display: flex; align-items: center; gap: 10px;">
                                <input 
                                    type="radio" 
                                    name="package_id" 
                                    value="<?= (int)$pkg['id'] ?>" 
                                    <?= $idx === 0 ? 'checked' : '' ?>
                                    onchange="document.querySelectorAll('.pkg-option').forEach(el => el.classList.remove('selected')); this.closest('.pkg-option').classList.add('selected');"
                                >
                                <div class="pkg-details">
                                    <div class="pkg-title"><?= ViewHelper::e($pkg['name']) ?></div>
                                    <div class="pkg-speed">
                                        <?= ViewHelper::formatDuration($pkg['duration'], $pkg['duration_unit']) ?>
                                        <?php if ($pkg['download_speed'] > 0): ?>
                                            &bull; Up to <?= (int)$pkg['download_speed'] ?> Mbps
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="pkg-price"><?= ViewHelper::formatCurrency($pkg['price']) ?></div>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="form-group" style="margin-top: 16px;">
                <label for="phone" class="form-label">Phone Number (M-Pesa / Tigo / Airtel)</label>
                <input 
                    type="tel" 
                    id="phone" 
                    name="phone" 
                    class="form-input" 
                    style="text-align: left; font-family: inherit; font-size: 16px; letter-spacing: 0;"
                    placeholder="e.g. 0712345678" 
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 16px;">
                Proceed to Pay
            </button>

            <a href="index.php" class="btn btn-outline" style="margin-top: 10px;">
                Back to Voucher Login
            </a>
        </form>

        <footer class="portal-footer">
            <div class="help-title">Instant Digital Voucher Delivery</div>
            <div class="help-text">
                Your voucher is generated automatically and shown right on your screen upon payment. No printing needed!
            </div>
        </footer>

    </div>
</div>

</body>
</html>
