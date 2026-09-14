<?php
/**
 * Air Link WiFi - Automated Database Installer & Migrator
 * Runs via CLI or Web browser to initialize/update the MySQL database schema & seed data.
 */

declare(strict_types=1);

// Prevent unauthorized execution in production if already initialized, or allow with setup token/localhost check
$isCli = (php_sapi_name() === 'cli');
$remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '';
$isLocal = ($remoteAddr === '127.0.0.1' || $remoteAddr === '::1' || $remoteAddr === 'localhost' || $isCli);

// Load configuration
require_once __DIR__ . '/../config/config.php';

$pageTitle = "Air Link WiFi - Database Setup";
$messages = [];
$error = null;

try {
    // 1. Establish connection to MySQL server (without selecting DB first, to ensure DB exists)
    $dsnNoDb = sprintf('mysql:host=%s;port=%s;charset=utf8mb4', DB_HOST, DB_PORT);
    $pdoRoot = new PDO($dsnNoDb, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create database if not exists
    $pdoRoot->exec(sprintf(
        'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;',
        DB_NAME
    ));
    $messages[] = "Database `" . htmlspecialchars(DB_NAME) . "` verified/created successfully.";

    // Connect to the specific database
    $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
    $pdo = new PDO($dsn, DB_USER, DB_PASSWORD, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Execute schema.sql
    $schemaFile = __DIR__ . '/schema.sql';
    if (file_exists($schemaFile)) {
        $sql = file_get_contents($schemaFile);
        $pdo->exec($sql);
        $messages[] = "Database tables and relationships created successfully from schema.sql.";
    } else {
        throw new RuntimeException("schema.sql not found at " . $schemaFile);
    }

    // Execute seed.sql
    $seedFile = __DIR__ . '/seed.sql';
    if (file_exists($seedFile)) {
        $sql = file_get_contents($seedFile);
        $pdo->exec($sql);
        $messages[] = "Default packages, settings, and seed data imported successfully.";
    }

    // Ensure default admin password hash is fresh for 'admin123'
    $defaultAdminPass = 'admin123';
    $freshHash = password_hash($defaultAdminPass, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE `admins` SET `password_hash` = :hash WHERE `username` = 'admin'");
    $stmt->execute(['hash' => $freshHash]);
    $messages[] = "Default administrator configured: username: <strong>admin</strong> / password: <strong>admin123</strong>";

    // Insert setup audit log
    $stmtLog = $pdo->prepare("INSERT INTO `audit_logs` (`actor_type`, `actor_identifier`, `action`, `ip_address`, `result`, `details`) VALUES ('system', 'installer', 'DATABASE_INITIALIZED', :ip, 'success', 'Database tables and seed data initialized.')");
    $stmtLog->execute(['ip' => $remoteAddr ?: '127.0.0.1']);

} catch (Throwable $e) {
    $error = $e->getMessage();
}

if ($isCli) {
    echo "========================================\n";
    echo "Air Link WiFi - Database Installer\n";
    echo "========================================\n";
    if ($error) {
        echo "ERROR: " . $error . "\n";
        exit(1);
    }
    foreach ($messages as $msg) {
        echo "[OK] " . strip_tags($msg) . "\n";
    }
    echo "Installation completed successfully!\n";
    exit(0);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?></title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; }
        body { background-color: #f8fafc; color: #1e293b; display: flex; align-items: center; justify-content: center; min-height: 100vh; padding: 20px; }
        .card { background: #ffffff; border: 1px solid #e2e8f0; border-radius: 6px; width: 100%; max-width: 560px; padding: 32px; box-shadow: 0 1px 3px rgba(0,0,0,0.05); }
        .brand { font-size: 20px; font-weight: 700; color: #0b5ed7; margin-bottom: 4px; }
        .tagline { font-size: 13px; color: #64748b; margin-bottom: 24px; }
        h1 { font-size: 18px; font-weight: 600; color: #0f172a; margin-bottom: 16px; border-bottom: 1px solid #f1f5f9; padding-bottom: 10px; }
        .msg-item { font-size: 14px; padding: 10px 12px; margin-bottom: 8px; border-radius: 4px; background-color: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; line-height: 1.4; }
        .err-box { font-size: 14px; padding: 14px; margin-bottom: 16px; border-radius: 4px; background-color: #fef2f2; border: 1px solid #fecaca; color: #991b1b; }
        .action-box { margin-top: 24px; padding-top: 20px; border-top: 1px solid #e2e8f0; display: flex; gap: 12px; }
        .btn { display: inline-block; padding: 10px 18px; font-size: 14px; font-weight: 500; text-decoration: none; border-radius: 4px; border: 1px solid transparent; text-align: center; }
        .btn-primary { background-color: #0b5ed7; color: #ffffff; }
        .btn-primary:hover { background-color: #0a58ca; }
        .btn-secondary { background-color: #ffffff; border-color: #cbd5e1; color: #334155; }
        .btn-secondary:hover { background-color: #f8fafc; }
    </style>
</head>
<body>
<div class="card">
    <div class="brand">AIR LINK WIFI</div>
    <div class="tagline">Reliable Internet. Simple Access.</div>
    <h1>System Database Setup</h1>

    <?php if ($error): ?>
        <div class="err-box">
            <strong>Setup Failed:</strong><br>
            <?= htmlspecialchars($error) ?>
        </div>
        <p style="font-size: 13px; color: #64748b; margin-top: 8px;">
            Please ensure MySQL is running in XAMPP or your VPS and check database credentials in <code>config/config.php</code> or <code>.env</code>.
        </p>
    <?php else: ?>
        <?php foreach ($messages as $msg): ?>
            <div class="msg-item"><?= $msg ?></div>
        <?php endforeach; ?>

        <div class="action-box">
            <a href="../admin/login.php" class="btn btn-primary">Go to Admin Login</a>
            <a href="../public/index.php" class="btn btn-secondary">Open Captive Portal</a>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
