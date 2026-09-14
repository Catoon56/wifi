<?php
/**
 * Air Link WiFi - Admin Header & Navigation
 */

declare(strict_types=1);

$currentPage = basename($_SERVER['PHP_SELF'] ?? '');
$brand = Database::getSetting('brand_name', APP_BRAND);
$businessName = Database::getSetting('business_name', APP_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= isset($pageTitle) ? ViewHelper::e($pageTitle) . ' - ' : '' ?><?= ViewHelper::e($businessName) ?> Admin</title>
    <link rel="stylesheet" href="../assets/css/admin.css">
</head>
<body>

<div class="admin-layout">
    <!-- Sidebar Navigation -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <div class="sidebar-brand"><?= ViewHelper::e($brand) ?> WIFI</div>
            <div class="sidebar-tagline">Management Console</div>
        </div>

        <nav class="sidebar-nav">
            <div class="nav-label">Core Operations</div>
            <a href="index.php" class="nav-link <?= $currentPage === 'index.php' ? 'active' : '' ?>">Dashboard</a>
            <a href="vouchers.php" class="nav-link <?= in_array($currentPage, ['vouchers.php', 'voucher-print.php']) ? 'active' : '' ?>">Vouchers</a>
            <a href="voucher-create.php" class="nav-link <?= $currentPage === 'voucher-create.php' ? 'active' : '' ?>">Generate Vouchers</a>
            <a href="packages.php" class="nav-link <?= $currentPage === 'packages.php' ? 'active' : '' ?>">Wi-Fi Packages</a>
            <a href="sessions.php" class="nav-link <?= $currentPage === 'sessions.php' ? 'active' : '' ?>">Active Sessions</a>

            <div class="nav-label">Finance & Accounting</div>
            <a href="accounting.php" class="nav-link <?= $currentPage === 'accounting.php' ? 'active' : '' ?>">Accounting Dashboard</a>
            <a href="sales.php" class="nav-link <?= $currentPage === 'sales.php' ? 'active' : '' ?>">Sales Ledger</a>
            <a href="reports.php" class="nav-link <?= $currentPage === 'reports.php' ? 'active' : '' ?>">Reports & Analytics</a>

            <div class="nav-label">CRM & Logs</div>
            <a href="customers.php" class="nav-link <?= $currentPage === 'customers.php' ? 'active' : '' ?>">Customer Devices</a>
            <a href="logs.php" class="nav-link <?= $currentPage === 'logs.php' ? 'active' : '' ?>">Transaction & Audit Logs</a>

            <div class="nav-label">System</div>
            <a href="settings.php" class="nav-link <?= $currentPage === 'settings.php' ? 'active' : '' ?>">Settings & Integrations</a>
            <a href="../public/index.php" target="_blank" class="nav-link">View Captive Portal &nearr;</a>
        </nav>

        <div class="sidebar-footer">
            Logged in as <strong><?= ViewHelper::e($currentUser['username'] ?? 'admin') ?></strong>
        </div>
    </aside>

    <!-- Main Wrapper -->
    <div class="main-wrapper">
        <!-- Top Header Bar -->
        <header class="topbar">
            <div class="topbar-left">
                <button type="button" class="mobile-toggle" id="mobileToggle" aria-label="Toggle Menu">
                    Menu
                </button>
                <h2 class="page-title"><?= isset($pageTitle) ? ViewHelper::e($pageTitle) : 'Overview' ?></h2>
            </div>
            <div class="topbar-right">
                <span class="user-info"><?= ViewHelper::e($currentUser['full_name'] ?? 'Administrator') ?></span>
                <a href="logout.php" class="btn-logout">Logout</a>
            </div>
        </header>

        <!-- Main Body Content Container -->
        <main class="content-body">
