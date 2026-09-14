<?php
/**
 * Air Link WiFi - Public Error Page
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ViewHelper.php';

$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);
$message      = trim($_GET['msg'] ?? 'An unexpected error occurred. Please try again or contact support.');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title><?= ViewHelper::e($businessName) ?> - Notice</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline"><?= ViewHelper::e($tagline) ?></p>
        </header>

        <div class="alert alert-danger" style="text-align: center;">
            <?= ViewHelper::e($message) ?>
        </div>

        <a href="index.php" class="btn btn-primary" style="margin-top: 16px;">
            Return to Wi-Fi Portal
        </a>

        <footer class="portal-footer">
            <div class="help-title">Air Link Support</div>
            <div class="help-text">
                Call: <?= ViewHelper::e(Database::getSetting('support_phone', '+255 700 000 000')) ?>
            </div>
        </footer>

    </div>
</div>

</body>
</html>
