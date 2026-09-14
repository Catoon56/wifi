<?php
/**
 * Air Link WiFi - Financial & Accounting Dashboard
 * Comprehensive financial reports: Daily, Weekly, and Monthly breakdowns.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Accounting & Revenue";
$pdo = Database::getConnection();

$tab = trim($_GET['tab'] ?? 'daily');
if (!in_array($tab, ['daily', 'weekly', 'monthly'])) {
    $tab = 'daily';
}

// -------------------------------------------------------------
// 1. Core Financial Aggregates
// -------------------------------------------------------------
// Today
$todayDate = date('Y-m-d');
$todayQuery = $pdo->query("
    SELECT 
        COUNT(*) as `count`,
        COALESCE(SUM(`price`), 0) as `total`,
        COALESCE(SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END), 0) as `cash`,
        COALESCE(SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END), 0) as `digital`
    FROM `sales`
    WHERE `status` = 'completed' AND DATE(`sale_date`) = '$todayDate'
")->fetch();

// This Week (Past 7 Days)
$weekQuery = $pdo->query("
    SELECT 
        COUNT(*) as `count`,
        COALESCE(SUM(`price`), 0) as `total`,
        COALESCE(SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END), 0) as `cash`,
        COALESCE(SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END), 0) as `digital`
    FROM `sales`
    WHERE `status` = 'completed' AND `sale_date` >= DATE_SUB(NOW(), INTERVAL 7 DAY)
")->fetch();

// This Month (Past 30 Days)
$monthQuery = $pdo->query("
    SELECT 
        COUNT(*) as `count`,
        COALESCE(SUM(`price`), 0) as `total`,
        COALESCE(SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END), 0) as `cash`,
        COALESCE(SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END), 0) as `digital`
    FROM `sales`
    WHERE `status` = 'completed' AND `sale_date` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
")->fetch();

// -------------------------------------------------------------
// 2. Tab-Specific Data
// -------------------------------------------------------------
if ($tab === 'daily') {
    // Today's Sales Line Items
    $dailyTransactions = $pdo->query("
        SELECT s.*, v.`voucher_code`, p.`name` as `package_name`
        FROM `sales` s
        JOIN `vouchers` v ON s.`voucher_id` = v.`id`
        JOIN `packages` p ON s.`package_id` = p.`id`
        WHERE s.`status` = 'completed' AND DATE(s.`sale_date`) = '$todayDate'
        ORDER BY s.`id` DESC
    ")->fetchAll();

    // Hourly Distribution for Today
    $hourlyBreakdown = $pdo->query("
        SELECT 
            HOUR(`sale_date`) as `hr`,
            COUNT(*) as `count`,
            SUM(`price`) as `amount`
        FROM `sales`
        WHERE `status` = 'completed' AND DATE(`sale_date`) = '$todayDate'
        GROUP BY HOUR(`sale_date`)
        ORDER BY `hr` ASC
    ")->fetchAll();
} elseif ($tab === 'weekly') {
    // Past 7 Days Day-by-Day Table
    $weeklyBreakdown = $pdo->query("
        SELECT 
            DATE(`sale_date`) as `day`,
            COUNT(*) as `transactions`,
            SUM(`price`) as `gross_total`,
            SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END) as `cash_amount`,
            SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END) as `digital_amount`
        FROM `sales`
        WHERE `status` = 'completed' AND `sale_date` >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        GROUP BY DATE(`sale_date`)
        ORDER BY `day` DESC
    ")->fetchAll();
} elseif ($tab === 'monthly') {
    // Monthly Breakdown by Package
    $monthlyPackages = $pdo->query("
        SELECT 
            p.`name` as `package_name`,
            COUNT(s.`id`) as `units_sold`,
            SUM(s.`price`) as `revenue`,
            SUM(CASE WHEN s.`payment_method` = 'Cash' THEN s.`price` ELSE 0 END) as `cash_rev`,
            SUM(CASE WHEN s.`payment_method` IN ('SonicPesa', 'PesaPal') THEN s.`price` ELSE 0 END) as `digital_rev`
        FROM `sales` s
        JOIN `packages` p ON s.`package_id` = p.`id`
        WHERE s.`status` = 'completed' AND s.`sale_date` >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY p.`id`, p.`name`
        ORDER BY `revenue` DESC
    ")->fetchAll();

    // Past 12 Weeks Trend
    $weeksTrend = $pdo->query("
        SELECT 
            YEARWEEK(`sale_date`, 1) as `yw`,
            MIN(DATE(`sale_date`)) as `start_date`,
            COUNT(*) as `transactions`,
            SUM(`price`) as `weekly_total`
        FROM `sales`
        WHERE `status` = 'completed' AND `sale_date` >= DATE_SUB(NOW(), INTERVAL 12 WEEK)
        GROUP BY YEARWEEK(`sale_date`, 1)
        ORDER BY `yw` DESC
    ")->fetchAll();
}

// Handle Export
if (isset($_GET['export']) && $_GET['export'] === 'accounting_csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="airlink_accounting_' . $tab . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($tab === 'daily') {
        fputcsv($out, ['Time', 'Voucher Code', 'Package', 'Method', 'Reference', 'Customer Phone', 'Amount (TZS)']);
        $stmt = $pdo->query("
            SELECT s.`sale_date`, v.`voucher_code`, p.`name`, s.`payment_method`, s.`transaction_reference`, s.`customer_phone`, s.`price`
            FROM `sales` s
            JOIN `vouchers` v ON s.`voucher_id` = v.`id`
            JOIN `packages` p ON s.`package_id` = p.`id`
            WHERE s.`status` = 'completed' AND DATE(s.`sale_date`) = '$todayDate'
            ORDER BY s.`id` DESC
        ");
        while ($r = $stmt->fetch()) {
            fputcsv($out, [$r['sale_date'], $r['voucher_code'], $r['name'], $r['payment_method'], $r['transaction_reference'], $r['customer_phone'] ?? '', $r['price']]);
        }
    } elseif ($tab === 'weekly') {
        fputcsv($out, ['Date', 'Transactions', 'Cash (TZS)', 'SonicPesa / Digital (TZS)', 'Gross Total (TZS)']);
        foreach ($weeklyBreakdown as $r) {
            fputcsv($out, [$r['day'], $r['transactions'], $r['cash_amount'], $r['digital_amount'], $r['gross_total']]);
        }
    } else {
        fputcsv($out, ['Package Plan', 'Units Sold', 'Cash (TZS)', 'SonicPesa / Digital (TZS)', 'Total Revenue (TZS)']);
        foreach ($monthlyPackages as $r) {
            fputcsv($out, [$r['package_name'], $r['units_sold'], $r['cash_rev'], $r['digital_rev'], $r['revenue']]);
        }
    }

    fclose($out);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Financial Period KPI Cards -->
<div class="stats-grid">
    <!-- Today -->
    <div class="stat-card" style="border-top: 3px solid #0b5ed7;">
        <div class="stat-label">Daily Revenue (Today)</div>
        <div class="stat-value" style="color: #0b5ed7;">
            <?= ViewHelper::formatCurrency($todayQuery['total']) ?>
        </div>
        <div class="stat-subtext">
            Cash: <?= ViewHelper::formatCurrency($todayQuery['cash']) ?> &bull; Digital (SonicPesa): <?= ViewHelper::formatCurrency($todayQuery['digital']) ?>
        </div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
            <?= number_format((int)$todayQuery['count']) ?> transaction(s) today
        </div>
    </div>

    <!-- Weekly -->
    <div class="stat-card" style="border-top: 3px solid #16a34a;">
        <div class="stat-label">Weekly Revenue (Past 7 Days)</div>
        <div class="stat-value" style="color: #16a34a;">
            <?= ViewHelper::formatCurrency($weekQuery['total']) ?>
        </div>
        <div class="stat-subtext">
            Cash: <?= ViewHelper::formatCurrency($weekQuery['cash']) ?> &bull; Digital (SonicPesa): <?= ViewHelper::formatCurrency($weekQuery['digital']) ?>
        </div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
            <?= number_format((int)$weekQuery['count']) ?> transaction(s) this week
        </div>
    </div>

    <!-- Monthly -->
    <div class="stat-card" style="border-top: 3px solid #0284c7;">
        <div class="stat-label">Monthly Revenue (Past 30 Days)</div>
        <div class="stat-value" style="color: #0284c7;">
            <?= ViewHelper::formatCurrency($monthQuery['total']) ?>
        </div>
        <div class="stat-subtext">
            Cash: <?= ViewHelper::formatCurrency($monthQuery['cash']) ?> &bull; Digital (SonicPesa): <?= ViewHelper::formatCurrency($monthQuery['digital']) ?>
        </div>
        <div style="font-size: 11px; color: #64748b; margin-top: 4px;">
            <?= number_format((int)$monthQuery['count']) ?> transaction(s) this month
        </div>
    </div>
</div>

<!-- Accounting Tabs Navigation -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; gap: 8px;">
        <a href="accounting.php?tab=daily" class="btn <?= $tab === 'daily' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Daily Report
        </a>
        <a href="accounting.php?tab=weekly" class="btn <?= $tab === 'weekly' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Weekly Report
        </a>
        <a href="accounting.php?tab=monthly" class="btn <?= $tab === 'monthly' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Monthly Report
        </a>
    </div>

    <div>
        <a href="accounting.php?tab=<?= $tab ?>&export=accounting_csv" class="btn btn-secondary btn-sm">
            Export <?= ucfirst($tab) ?> Accounting CSV
        </a>
    </div>
</div>

<?php if ($tab === 'daily'): ?>
    <!-- DAILY REPORT CONTENT -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Daily Accounting Report - <?= date('l, d M Y') ?></h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Voucher Code</th>
                            <th>Package</th>
                            <th>Payment Method</th>
                            <th>Transaction Ref</th>
                            <th>Phone</th>
                            <th>Amount</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($dailyTransactions)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; color: #64748b; padding: 28px;">
                                    No sales transactions recorded today yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($dailyTransactions as $sale): ?>
                                <tr>
                                    <td><strong><?= date('H:i:s', strtotime($sale['sale_date'])) ?></strong></td>
                                    <td>
                                        <span style="font-family: monospace; font-weight: 600;"><?= ViewHelper::e($sale['voucher_code']) ?></span>
                                    </td>
                                    <td><?= ViewHelper::e($sale['package_name']) ?></td>
                                    <td>
                                        <span class="badge <?= in_array($sale['payment_method'], ['SonicPesa', 'PesaPal']) ? 'badge-info' : 'badge-neutral' ?>">
                                            <?= ViewHelper::e($sale['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td><code><?= ViewHelper::e($sale['transaction_reference']) ?></code></td>
                                    <td><?= ViewHelper::e($sale['customer_phone'] ?: '-') ?></td>
                                    <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($sale['price']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'weekly'): ?>
    <!-- WEEKLY REPORT CONTENT -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Weekly Revenue Breakdown (Last 7 Days)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Sales Volume</th>
                            <th>Cash Receipts</th>
                            <th>SonicPesa / Digital</th>
                            <th>Gross Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($weeklyBreakdown)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 28px;">
                                    No sales recorded in the past 7 days.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($weeklyBreakdown as $row): ?>
                                <tr>
                                    <td><strong><?= date('D, d M Y', strtotime($row['day'])) ?></strong></td>
                                    <td><?= number_format((int)$row['transactions']) ?> sales</td>
                                    <td><?= ViewHelper::formatCurrency($row['cash_amount']) ?></td>
                                    <td><?= ViewHelper::formatCurrency($row['digital_amount']) ?></td>
                                    <td><strong style="color: #16a34a;"><?= ViewHelper::formatCurrency($row['gross_total']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php elseif ($tab === 'monthly'): ?>
    <!-- MONTHLY REPORT CONTENT -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3 class="card-title">Monthly Revenue by Package Plan (Last 30 Days)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Package Plan</th>
                            <th>Units Sold</th>
                            <th>Cash Revenue</th>
                            <th>SonicPesa / Digital</th>
                            <th>Gross Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($monthlyPackages)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #64748b; padding: 28px;">
                                    No sales recorded in the past 30 days.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($monthlyPackages as $p): ?>
                                <tr>
                                    <td><strong><?= ViewHelper::e($p['package_name']) ?></strong></td>
                                    <td><?= number_format((int)$p['units_sold']) ?></td>
                                    <td><?= ViewHelper::formatCurrency($p['cash_rev']) ?></td>
                                    <td><?= ViewHelper::formatCurrency($p['digital_rev']) ?></td>
                                    <td><strong style="color: #0284c7;"><?= ViewHelper::formatCurrency($p['revenue']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h3 class="card-title">12-Week Revenue Trajectory</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Week Starting</th>
                            <th>Transactions</th>
                            <th>Total Revenue</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($weeksTrend)): ?>
                            <tr>
                                <td colspan="3" style="text-align: center; color: #64748b; padding: 28px;">
                                    No multi-week history available yet.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($weeksTrend as $w): ?>
                                <tr>
                                    <td><strong>Week of <?= date('d M Y', strtotime($w['start_date'])) ?></strong></td>
                                    <td><?= number_format((int)$w['transactions']) ?> transactions</td>
                                    <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($w['weekly_total']) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

<?php endif; ?>

<?php include __DIR__ . '/includes/footer.php'; ?>
