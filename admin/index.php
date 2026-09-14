<?php
/**
 * Air Link WiFi - Administrative Dashboard
 * Displays high-level KPIs, real-time transaction logs, accounting highlights, and connected customers.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Dashboard Overview";
$pdo = Database::getConnection();

// 1. Fetch Key Performance Indicators (KPIs)
$totalVouchers = (int)$pdo->query("SELECT COUNT(*) FROM `vouchers`")->fetchColumn();
$unusedVouchers = (int)$pdo->query("SELECT COUNT(*) FROM `vouchers` WHERE `status` = 'unused'")->fetchColumn();
$activeVouchers = (int)$pdo->query("SELECT COUNT(*) FROM `vouchers` WHERE `status` = 'active'")->fetchColumn();
$expiredVouchers = (int)$pdo->query("SELECT COUNT(*) FROM `vouchers` WHERE `status` = 'expired'")->fetchColumn();

// Accounting Aggregates
$todayStr = date('Y-m-d');
$stmtTodaySales = $pdo->prepare("
    SELECT 
        COUNT(*) as `count`,
        COALESCE(SUM(`price`), 0) as `total`,
        COALESCE(SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END), 0) as `cash`,
        COALESCE(SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END), 0) as `digital`
    FROM `sales` 
    WHERE DATE(`sale_date`) = :d AND `status` = 'completed'
");
$stmtTodaySales->execute(['d' => $todayStr]);
$todayStats = $stmtTodaySales->fetch();

$stmtWeekSales = $pdo->query("SELECT COALESCE(SUM(`price`), 0) FROM `sales` WHERE `sale_date` >= DATE_SUB(NOW(), INTERVAL 7 DAY) AND `status` = 'completed'");
$weekTotal = (float)$stmtWeekSales->fetchColumn();

$stmtMonthSales = $pdo->query("SELECT COALESCE(SUM(`price`), 0) FROM `sales` WHERE `sale_date` >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND `status` = 'completed'");
$monthTotal = (float)$stmtMonthSales->fetchColumn();

// Activations Today
$stmtTodayActivations = $pdo->prepare("SELECT COUNT(*) FROM `vouchers` WHERE DATE(`activated_at`) = :d");
$stmtTodayActivations->execute(['d' => $todayStr]);
$todayActivationsCount = (int)$stmtTodayActivations->fetchColumn();

// Active Connected Customers
$activeCustomersCount = (int)$pdo->query("SELECT COUNT(DISTINCT `client_mac`) FROM `wifi_sessions` WHERE `status` = 'active'")->fetchColumn();

// Total Lifetime Revenue
$totalRevenue = (float)$pdo->query("SELECT COALESCE(SUM(`price`), 0) FROM `sales` WHERE `status` = 'completed'")->fetchColumn();

// 2. Fetch Live Transaction Logs (Last 10 transactions)
$recentTransactions = $pdo->query("
    SELECT s.*, v.`voucher_code`, v.`client_mac`, p.`name` as `package_name`
    FROM `sales` s
    JOIN `vouchers` v ON s.`voucher_id` = v.`id`
    JOIN `packages` p ON s.`package_id` = p.`id`
    ORDER BY s.`id` DESC LIMIT 10
")->fetchAll();

// 3. Fetch Currently Connected Clients
$activeSessions = $pdo->query("
    SELECT s.*, v.`voucher_code`, p.`name` as `package_name`
    FROM `wifi_sessions` s
    JOIN `vouchers` v ON s.`voucher_id` = v.`id`
    JOIN `packages` p ON v.`package_id` = p.`id`
    WHERE s.`status` = 'active'
    ORDER BY s.`id` DESC LIMIT 6
")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<!-- KPI Stat Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-label">Total Vouchers</div>
        <div class="stat-value"><?= number_format($totalVouchers) ?></div>
        <div class="stat-subtext">All generated codes</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Unused Vouchers</div>
        <div class="stat-value" style="color: #0369a1;"><?= number_format($unusedVouchers) ?></div>
        <div class="stat-subtext">Ready for sale/use</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Vouchers</div>
        <div class="stat-value" style="color: #15803d;"><?= number_format($activeVouchers) ?></div>
        <div class="stat-subtext">Currently in use</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Active Customers</div>
        <div class="stat-value" style="color: #15803d;"><?= number_format($activeCustomersCount) ?></div>
        <div class="stat-subtext">Connected devices</div>
    </div>

    <div class="stat-card" style="border-top: 3px solid #0b5ed7;">
        <div class="stat-label">Today's Sales (Daily)</div>
        <div class="stat-value" style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($todayStats['total']) ?></div>
        <div class="stat-subtext"><?= (int)$todayStats['count'] ?> transaction(s) today</div>
    </div>

    <div class="stat-card" style="border-top: 3px solid #16a34a;">
        <div class="stat-label">Weekly Sales (7 Days)</div>
        <div class="stat-value" style="color: #16a34a;"><?= ViewHelper::formatCurrency($weekTotal) ?></div>
        <div class="stat-subtext">Past 7 days volume</div>
    </div>

    <div class="stat-card" style="border-top: 3px solid #0284c7;">
        <div class="stat-label">Monthly Sales (30 Days)</div>
        <div class="stat-value" style="color: #0284c7;"><?= ViewHelper::formatCurrency($monthTotal) ?></div>
        <div class="stat-subtext">Past 30 days volume</div>
    </div>

    <div class="stat-card">
        <div class="stat-label">Total Lifetime Revenue</div>
        <div class="stat-value"><?= ViewHelper::formatCurrency($totalRevenue) ?></div>
        <div class="stat-subtext">Cumulative earnings</div>
    </div>
</div>

<!-- Quick Action & Accounting Bar -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-body" style="padding: 16px 20px; display: flex; gap: 12px; flex-wrap: wrap; align-items: center; justify-content: space-between;">
        <div style="display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <span style="font-weight: 700; color: #0f172a; margin-right: 4px;">Quick Actions:</span>
            <a href="voucher-create.php" class="btn btn-primary btn-sm">+ Generate Vouchers</a>
            <a href="accounting.php" class="btn btn-secondary btn-sm">Accounting Dashboard</a>
            <a href="logs.php" class="btn btn-secondary btn-sm">All Logs</a>
            <a href="sessions.php" class="btn btn-secondary btn-sm">Active Sessions (<?= $activeCustomersCount ?>)</a>
        </div>
        <div>
            <?php if (Auth::isSuperAdmin()): ?>
                <a href="stations.php" class="btn btn-primary btn-sm">Manage Stations</a>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- TRANSACTION LOGS SECTION (Main Dashboard Requirement) -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Live Financial Transaction Logs</h3>
        <div style="display: flex; gap: 8px;">
            <a href="accounting.php" class="btn btn-secondary btn-sm">Accounting Dashboard</a>
            <a href="logs.php?cat=transactions" class="btn btn-primary btn-sm">View Full Logs &rarr;</a>
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Transaction Reference</th>
                        <th>Voucher Code</th>
                        <th>Package Plan</th>
                        <th>Payment Method</th>
                        <th>Customer Phone</th>
                        <th>Amount</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recentTransactions)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #64748b; padding: 28px;">
                                No transactions recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recentTransactions as $tx): ?>
                            <tr>
                                <td><?= ViewHelper::formatDateTime($tx['sale_date']) ?></td>
                                <td><code><?= ViewHelper::e($tx['transaction_reference']) ?></code></td>
                                <td>
                                    <strong style="font-family: monospace; letter-spacing: 1px;">
                                        <?= ViewHelper::e($tx['voucher_code']) ?>
                                    </strong>
                                </td>
                                <td><?= ViewHelper::e($tx['package_name']) ?></td>
                                <td>
                                    <span class="badge <?= in_array($tx['payment_method'], ['SonicPesa', 'PesaPal']) ? 'badge-info' : 'badge-neutral' ?>">
                                        <?= ViewHelper::e($tx['payment_method']) ?>
                                    </span>
                                </td>
                                <td><?= ViewHelper::e($tx['customer_phone'] ?: '-') ?></td>
                                <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($tx['price']) ?></strong></td>
                                <td><?= ViewHelper::statusBadge($tx['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- CONNECTED SESSIONS GRID -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Currently Connected Wi-Fi Clients</h3>
        <a href="sessions.php" class="btn btn-secondary btn-sm">Manage All Sessions</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>MAC Address</th>
                        <th>Voucher Code</th>
                        <th>Package</th>
                        <th>Connected Since</th>
                        <th>Remaining Time</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($activeSessions)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">
                                No clients currently connected to the network.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($activeSessions as $sess): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace;"><?= ViewHelper::e($sess['client_mac']) ?></strong>
                                </td>
                                <td>
                                    <span style="font-family: monospace; font-weight: 600;"><?= ViewHelper::e($sess['voucher_code']) ?></span>
                                </td>
                                <td><?= ViewHelper::e($sess['package_name']) ?></td>
                                <td><?= ViewHelper::formatDateTime($sess['login_time'], 'H:i d M') ?></td>
                                <td>
                                    <span class="badge badge-success">
                                        <?= ViewHelper::formatRemainingSeconds((int)$sess['remaining_time']) ?>
                                    </span>
                                </td>
                                <td><?= ViewHelper::statusBadge($sess['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
