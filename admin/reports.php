<?php
/**
 * Air Link WiFi - Reports & Business Analytics
 * Sales breakdowns, package performance, voucher usage metrics, and CSV exports.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Reports & Analytics";
$pdo = Database::getConnection();

// 1. Package Performance Breakdown
$pkgPerformance = $pdo->query("
    SELECT 
        p.`name` as `package_name`,
        p.`duration`,
        p.`duration_unit`,
        p.`price` as `unit_price`,
        COUNT(s.`id`) as `total_sales_count`,
        COALESCE(SUM(s.`price`), 0) as `total_revenue`,
        COUNT(DISTINCT v.`client_mac`) as `unique_buyers`
    FROM `packages` p
    LEFT JOIN `vouchers` v ON p.`id` = v.`package_id`
    LEFT JOIN `sales` s ON v.`id` = s.`voucher_id` AND s.`status` = 'completed'
    GROUP BY p.`id`, p.`name`, p.`duration`, p.`duration_unit`, p.`price`
    ORDER BY `total_revenue` DESC
")->fetchAll();

// 2. Daily Sales (Past 14 Days)
$dailySales = $pdo->query("
    SELECT 
        DATE(`sale_date`) as `sale_day`,
        COUNT(`id`) as `sales_count`,
        SUM(`price`) as `day_total`,
        SUM(CASE WHEN `payment_method` = 'Cash' THEN `price` ELSE 0 END) as `cash_total`,
        SUM(CASE WHEN `payment_method` IN ('SonicPesa', 'PesaPal') THEN `price` ELSE 0 END) as `digital_total`
    FROM `sales`
    WHERE `status` = 'completed' AND `sale_date` >= DATE_SUB(NOW(), INTERVAL 14 DAY)
    GROUP BY DATE(`sale_date`)
    ORDER BY `sale_day` DESC
")->fetchAll();

// 3. Overall Voucher Usage Stats
$voucherStats = $pdo->query("
    SELECT 
        COUNT(*) as `total_vouchers`,
        SUM(CASE WHEN `status` = 'unused' THEN 1 ELSE 0 END) as `unused_count`,
        SUM(CASE WHEN `status` = 'active' THEN 1 ELSE 0 END) as `active_count`,
        SUM(CASE WHEN `status` = 'expired' THEN 1 ELSE 0 END) as `expired_count`,
        SUM(CASE WHEN `status` = 'disabled' THEN 1 ELSE 0 END) as `disabled_count`
    FROM `vouchers`
")->fetch();

// Handle CSV Export for Package Performance
if (isset($_GET['export']) && $_GET['export'] === 'packages') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="airlink_package_performance_' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Package Name', 'Duration', 'Price (TZS)', 'Total Sales Count', 'Total Revenue (TZS)', 'Unique Devices']);
    foreach ($pkgPerformance as $row) {
        fputcsv($out, [
            $row['package_name'],
            $row['duration'] . ' ' . $row['duration_unit'],
            $row['unit_price'],
            $row['total_sales_count'],
            $row['total_revenue'],
            $row['unique_buyers'],
        ]);
    }
    fclose($out);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Voucher Lifecycle Overview -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Voucher Inventory Health</h3>
    </div>
    <div class="card-body">
        <div class="stats-grid" style="margin-bottom: 0;">
            <div class="stat-card">
                <div class="stat-label">Total Generated</div>
                <div class="stat-value"><?= number_format((int)($voucherStats['total_vouchers'] ?? 0)) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Unused Inventory</div>
                <div class="stat-value" style="color: #0284c7;"><?= number_format((int)($voucherStats['unused_count'] ?? 0)) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Active / Live</div>
                <div class="stat-value" style="color: #16a34a;"><?= number_format((int)($voucherStats['active_count'] ?? 0)) ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Expired</div>
                <div class="stat-value" style="color: #64748b;"><?= number_format((int)($voucherStats['expired_count'] ?? 0)) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Package Performance Table -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">Package Performance & Revenue</h3>
        <a href="reports.php?export=packages" class="btn btn-secondary btn-sm">Export Packages CSV</a>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Package Plan</th>
                        <th>Duration</th>
                        <th>Rate</th>
                        <th>Total Vouchers Sold</th>
                        <th>Unique Clients</th>
                        <th>Total Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($pkgPerformance)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #64748b; padding: 24px;">
                                No package data available.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($pkgPerformance as $pkg): ?>
                            <tr>
                                <td><strong><?= ViewHelper::e($pkg['package_name']) ?></strong></td>
                                <td><?= ViewHelper::formatDuration($pkg['duration'], $pkg['duration_unit']) ?></td>
                                <td><?= ViewHelper::formatCurrency($pkg['unit_price']) ?></td>
                                <td><?= number_format((int)$pkg['total_sales_count']) ?></td>
                                <td><?= number_format((int)$pkg['unique_buyers']) ?></td>
                                <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($pkg['total_revenue']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Daily Sales Breakdown (Past 14 Days) -->
<div class="card">
    <div class="card-header">
        <h3 class="card-title">Daily Sales Trend (Last 14 Days)</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Sales Transactions</th>
                        <th>Cash Sales</th>
                        <th>SonicPesa / Digital</th>
                        <th>Total Daily Revenue</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($dailySales)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; color: #64748b; padding: 24px;">
                                No sales recorded in the past 14 days.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($dailySales as $day): ?>
                            <tr>
                                <td><strong><?= date('D, d M Y', strtotime($day['sale_day'])) ?></strong></td>
                                <td><?= number_format((int)$day['sales_count']) ?> sale(s)</td>
                                <td><?= ViewHelper::formatCurrency($day['cash_total']) ?></td>
                                <td><?= ViewHelper::formatCurrency($day['digital_total']) ?></td>
                                <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($day['day_total']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
