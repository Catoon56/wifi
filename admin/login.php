<?php
/**
 * Air Link WiFi - Administrator Login
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CSRF.php';
require_once __DIR__ . '/../includes/Auth.php';
require_once __DIR__ . '/../includes/ViewHelper.php';

// If already logged in, redirect to dashboard
if (Auth::check()) {
    header("Location: index.php");
    exit;
}

$businessName = Database::getSetting('business_name', APP_NAME);
$tagline      = Database::getSetting('tagline', APP_TAGLINE);

$error = null;
$redirect = trim($_GET['redirect'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();

    $username = trim($_POST['username'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    $result = Auth::login($username, $password);

    if ($result['success']) {
        $target = (!empty($redirect) && !str_contains($redirect, 'login.php')) ? $redirect : 'index.php';
        header("Location: " . $target);
        exit;
    } else {
        $error = $result['error'] ?? 'Authentication failed.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ViewHelper::e($businessName) ?> - Admin Login</title>
    <link rel="stylesheet" href="../assets/css/portal.css">
    <style>
        body { background-color: #0f172a; }
        .portal-card { border-color: #334155; }
        .brand-title { color: #0b5ed7; }
        .brand-tagline { color: #64748b; }
        .form-label { color: #334155; }
        .form-input { text-align: left; font-family: inherit; font-size: 15px; letter-spacing: 0; text-transform: none; }
    </style>
</head>
<body>

<div class="portal-container">
    <div class="portal-card">
        
        <header class="portal-header">
            <h1 class="brand-title"><?= ViewHelper::e($businessName) ?></h1>
            <p class="brand-tagline">Administrator Sign In</p>
        </header>

        <?php if ($error): ?>
            <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
        <?php endif; ?>

        <form method="POST" action="login.php<?= $redirect ? '?redirect=' . urlencode($redirect) : '' ?>">
            <?= CSRF::field() ?>

            <div class="form-group">
                <label for="username" class="form-label">Username or Email</label>
                <input 
                    type="text" 
                    id="username" 
                    name="username" 
                    class="form-input" 
                    placeholder="Enter username" 
                    required 
                    autofocus
                >
            </div>

            <div class="form-group" style="margin-top: 14px;">
                <label for="password" class="form-label">Password</label>
                <input 
                    type="password" 
                    id="password" 
                    name="password" 
                    class="form-input" 
                    placeholder="Enter password" 
                    required
                >
            </div>

            <button type="submit" class="btn btn-primary" style="margin-top: 20px;">
                Sign In to Admin
            </button>
        </form>

        <footer class="portal-footer" style="margin-top: 24px; padding-top: 16px;">
            <div class="help-text">
                Air Link WiFi Management System &bull; Version 2.0<br>
                Hardware: TP-Link EAP110 Outdoor &bull; Omada SDN
            </div>
        </footer>

    </div>
</div>

</body>
</html>
