<?php
/**
 * Air Link WiFi - Sales & Revenue Ledger
 * Tracks Cash & SonicPesa sales with date filtering and CSV export.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Sales & Revenue Ledger";
$pdo = Database::getConnection();

$message = null;
$error = null;

// Handle Record Manual Cash Sale
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'record_cash_sale') {
    CSRF::enforce();
    $voucherCode = strtoupper(trim($_POST['voucher_code'] ?? ''));
    $customerPhone = trim($_POST['customer_phone'] ?? '');

    $vStmt = $pdo->prepare("SELECT * FROM `vouchers` WHERE `voucher_code` = :c LIMIT 1");
    $vStmt->execute(['c' => $voucherCode]);
    $v = $vStmt->fetch();

    if (!$v) {
        $error = "Voucher code not found.";
    } else {
        $stmt = $pdo->prepare("
            INSERT INTO `sales` 
            (`voucher_id`, `package_id`, `price`, `payment_method`, `transaction_reference`, `customer_phone`, `admin_id`, `sale_date`, `status`)
            VALUES (:vid, :pid, :price, 'Cash', :ref, :phone, :aid, NOW(), 'completed')
        ");
        $stmt->execute([
            'vid'   => $v['id'],
            'pid'   => $v['package_id'],
            'price' => $v['price'],
            'ref'   => 'CASH-' . date('YmdHis'),
            'phone' => $customerPhone,
            'aid'   => $currentUser['id'] ?? 1,
        ]);
        $message = "Cash sale recorded successfully for voucher $voucherCode (" . ViewHelper::formatCurrency($v['price']) . ").";
    }
}

// Filters
$methodFilter = trim($_GET['method'] ?? '');
$dateFilter   = trim($_GET['date_range'] ?? 'all');
$search       = trim($_GET['search'] ?? '');

$where = ["s.`status` = 'completed'"];
$params = [];

if (!empty($methodFilter)) {
    $where[] = "s.`payment_method` = :m";
    $params['m'] = $methodFilter;
}

if ($dateFilter === 'today') {
    $where[] = "DATE(s.`sale_date`) = CURDATE()";
} elseif ($dateFilter === 'week') {
    $where[] = "s.`sale_date` >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($dateFilter === 'month') {
    $where[] = "s.`sale_date` >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
}

if (!empty($search)) {
    $where[] = "(v.`voucher_code` LIKE :s OR s.`customer_phone` LIKE :s OR s.`transaction_reference` LIKE :s)";
    $params['s'] = "%$search%";
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="airlink_sales_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['ID', 'Date', 'Voucher Code', 'Package', 'Method', 'Price (TZS)', 'Reference', 'Customer Phone']);

    $exportSql = "
        SELECT s.*, v.`voucher_code`, p.`name` as `package_name`
        FROM `sales` s
        JOIN `vouchers` v ON s.`voucher_id` = v.`id`
        JOIN `packages` p ON s.`package_id` = p.`id`
        WHERE " . implode(" AND ", $where) . "
        ORDER BY s.`id` DESC
    ";
    $st = $pdo->prepare($exportSql);
    $st->execute($params);

    while ($r = $st->fetch()) {
        fputcsv($out, [
            $r['id'],
            $r['sale_date'],
            $r['voucher_code'],
            $r['package_name'],
            $r['payment_method'],
            $r['price'],
            $r['transaction_reference'],
            $r['customer_phone'] ?? '',
        ]);
    }
    fclose($out);
    exit;
}

// Total revenue in filter
$sumSql = "
    SELECT COALESCE(SUM(s.`price`), 0) 
    FROM `sales` s 
    JOIN `vouchers` v ON s.`voucher_id` = v.`id` 
    WHERE " . implode(" AND ", $where);
$sumStmt = $pdo->prepare($sumSql);
$sumStmt->execute($params);
$totalFilteredRevenue = (float)$sumStmt->fetchColumn();

// Pagination
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countSql = "
    SELECT COUNT(*) 
    FROM `sales` s 
    JOIN `vouchers` v ON s.`voucher_id` = v.`id` 
    WHERE " . implode(" AND ", $where);
$cStmt = $pdo->prepare($countSql);
$cStmt->execute($params);
$totalRecords = (int)$cStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$sql = "
    SELECT s.*, v.`voucher_code`, p.`name` as `package_name`
    FROM `sales` s
    JOIN `vouchers` v ON s.`voucher_id` = v.`id`
    JOIN `packages` p ON s.`package_id` = p.`id`
    WHERE " . implode(" AND ", $where) . "
    ORDER BY s.`id` DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sales = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= ViewHelper::e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
<?php endif; ?>

<!-- Summary Ribbon -->
<div class="card" style="margin-bottom: 20px;">
    <div class="card-body" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px;">
        <div>
            <div style="font-size: 12px; font-weight: 700; text-transform: uppercase; color: #64748b;">Filtered Revenue</div>
            <div style="font-size: 24px; font-weight: 800; color: #0b5ed7;">
                <?= ViewHelper::formatCurrency($totalFilteredRevenue) ?>
            </div>
        </div>
        <div style="display: flex; gap: 10px;">
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary btn-sm">
                Export to CSV
            </a>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Sales Ledger (<?= number_format($totalRecords) ?> records)</h3>
    </div>
    <div class="card-body">
        <!-- Filter Controls -->
        <form method="GET" action="sales.php" class="filter-bar">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search voucher, phone, ref..." 
                value="<?= ViewHelper::e($search) ?>"
                style="min-width: 220px;"
            >

            <select name="method" class="form-control">
                <option value="">All Payment Methods</option>
                <option value="Cash" <?= $methodFilter === 'Cash' ? 'selected' : '' ?>>Cash</option>
                <option value="SonicPesa" <?= $methodFilter === 'SonicPesa' ? 'selected' : '' ?>>SonicPesa (Mobile Money / Card)</option>
                <option value="PesaPal" <?= $methodFilter === 'PesaPal' ? 'selected' : '' ?>>PesaPal (Legacy)</option>
            </select>

            <select name="date_range" class="form-control">
                <option value="all" <?= $dateFilter === 'all' ? 'selected' : '' ?>>All Time</option>
                <option value="today" <?= $dateFilter === 'today' ? 'selected' : '' ?>>Today</option>
                <option value="week" <?= $dateFilter === 'week' ? 'selected' : '' ?>>Past 7 Days</option>
                <option value="month" <?= $dateFilter === 'month' ? 'selected' : '' ?>>Past 30 Days</option>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($search || $methodFilter || $dateFilter !== 'all'): ?>
                <a href="sales.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date & Time</th>
                        <th>Voucher Code</th>
                        <th>Package</th>
                        <th>Method</th>
                        <th>Reference</th>
                        <th>Customer Phone</th>
                        <th>Price</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sales)): ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #64748b; padding: 24px;">
                                No sales records found for this period.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sales as $s): ?>
                            <tr>
                                <td><?= ViewHelper::formatDateTime($s['sale_date']) ?></td>
                                <td>
                                    <strong style="font-family: monospace; letter-spacing: 1px;">
                                        <?= ViewHelper::e($s['voucher_code']) ?>
                                    </strong>
                                </td>
                                <td><?= ViewHelper::e($s['package_name']) ?></td>
                                <td>
                                    <span class="badge <?= in_array($s['payment_method'], ['SonicPesa', 'PesaPal']) ? 'badge-info' : 'badge-neutral' ?>">
                                        <?= ViewHelper::e($s['payment_method']) ?>
                                    </span>
                                </td>
                                <td>
                                    <code style="font-size: 12px;"><?= ViewHelper::e($s['transaction_reference'] ?: '-') ?></code>
                                </td>
                                <td><?= ViewHelper::e($s['customer_phone'] ?: '-') ?></td>
                                <td><strong><?= ViewHelper::formatCurrency($s['price']) ?></strong></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; font-size: 13px;">
                <div>Showing page <?= $page ?> of <?= $totalPages ?></div>
                <div style="display: flex; gap: 6px;">
                    <?php if ($page > 1): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="btn btn-secondary btn-sm">&larr; Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="btn btn-secondary btn-sm">Next &rarr;</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
