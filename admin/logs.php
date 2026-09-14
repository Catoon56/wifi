<?php
/**
 * Air Link WiFi - Unified Transaction & Audit Logs
 * Comprehensive chronological history of financial transactions, customer activations,
 * Omada Controller API calls, and administrative operations.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Transaction & System Logs";
$pdo = Database::getConnection();

$category = trim($_GET['cat'] ?? 'transactions');
$search   = trim($_GET['search'] ?? '');
$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;
$offset   = ($page - 1) * $perPage;

if ($category === 'transactions') {
    // Financial Transactions Log
    $where = ["1=1"];
    $params = [];

    if (!empty($search)) {
        $where[] = "(s.`transaction_reference` LIKE :s OR v.`voucher_code` LIKE :s OR s.`customer_phone` LIKE :s)";
        $params['s'] = "%$search%";
    }

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
        SELECT s.*, v.`voucher_code`, v.`client_mac`, p.`name` as `package_name`
        FROM `sales` s
        JOIN `vouchers` v ON s.`voucher_id` = v.`id`
        JOIN `packages` p ON s.`package_id` = p.`id`
        WHERE " . implode(" AND ", $where) . "
        ORDER BY s.`id` DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

} else {
    // System & Audit Logs
    $where = ["1=1"];
    $params = [];

    if ($category === 'omada') {
        $where[] = "a.`action` LIKE 'OMADA_%'";
    } elseif ($category === 'vouchers') {
        $where[] = "a.`action` LIKE 'VOUCHER_%'";
    } elseif ($category === 'security') {
        $where[] = "(a.`action` LIKE 'ADMIN_%' OR a.`action` LIKE '%LOCK%')";
    }

    if (!empty($search)) {
        $where[] = "(a.`action` LIKE :s OR a.`actor_identifier` LIKE :s OR a.`details` LIKE :s OR a.`ip_address` LIKE :s)";
        $params['s'] = "%$search%";
    }

    $countSql = "SELECT COUNT(*) FROM `audit_logs` a WHERE " . implode(" AND ", $where);
    $cStmt = $pdo->prepare($countSql);
    $cStmt->execute($params);
    $totalRecords = (int)$cStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRecords / $perPage));

    $sql = "
        SELECT a.*
        FROM `audit_logs` a
        WHERE " . implode(" AND ", $where) . "
        ORDER BY a.`id` DESC
        LIMIT $perPage OFFSET $offset
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $auditLogs = $stmt->fetchAll();
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="airlink_logs_' . $category . '_' . date('Ymd_His') . '.csv"');
    $out = fopen('php://output', 'w');

    if ($category === 'transactions') {
        fputcsv($out, ['ID', 'Date', 'Transaction Ref', 'Voucher Code', 'Package', 'Payment Method', 'Amount (TZS)', 'Customer Phone', 'Status']);
        $exportStmt = $pdo->query("
            SELECT s.`id`, s.`sale_date`, s.`transaction_reference`, v.`voucher_code`, p.`name`, s.`payment_method`, s.`price`, s.`customer_phone`, s.`status`
            FROM `sales` s
            JOIN `vouchers` v ON s.`voucher_id` = v.`id`
            JOIN `packages` p ON s.`package_id` = p.`id`
            ORDER BY s.`id` DESC
        ");
        while ($r = $exportStmt->fetch()) {
            fputcsv($out, [$r['id'], $r['sale_date'], $r['transaction_reference'], $r['voucher_code'], $r['name'], $r['payment_method'], $r['price'], $r['customer_phone'] ?? '', $r['status']]);
        }
    } else {
        fputcsv($out, ['ID', 'Timestamp', 'Actor Type', 'Actor Identifier', 'Action', 'IP Address', 'Result', 'Details']);
        $exportStmt = $pdo->query("SELECT * FROM `audit_logs` ORDER BY `id` DESC LIMIT 1000");
        while ($r = $exportStmt->fetch()) {
            fputcsv($out, [$r['id'], $r['timestamp'], $r['actor_type'], $r['actor_identifier'], $r['action'], $r['ip_address'], $r['result'], $r['details']]);
        }
    }
    fclose($out);
    exit;
}

include __DIR__ . '/includes/header.php';
?>

<!-- Log Filters -->
<div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 12px;">
    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
        <a href="logs.php?cat=transactions" class="btn <?= $category === 'transactions' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Financial Transactions Log
        </a>
        <a href="logs.php?cat=omada" class="btn <?= $category === 'omada' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Omada Network Events
        </a>
        <a href="logs.php?cat=vouchers" class="btn <?= $category === 'vouchers' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Voucher Activations
        </a>
        <a href="logs.php?cat=security" class="btn <?= $category === 'security' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            Security & Admin
        </a>
        <a href="logs.php?cat=all" class="btn <?= $category === 'all' ? 'btn-primary' : 'btn-secondary' ?> btn-sm">
            All System Logs
        </a>
    </div>

    <div>
        <a href="logs.php?cat=<?= $category ?>&export=csv" class="btn btn-secondary btn-sm">
            Export Logs CSV
        </a>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <?= $category === 'transactions' ? 'Financial Transactions Record' : 'System Activity & Audit Trail' ?>
            (<?= number_format($totalRecords) ?> entries)
        </h3>
    </div>
    <div class="card-body">
        <form method="GET" action="logs.php" class="filter-bar">
            <input type="hidden" name="cat" value="<?= ViewHelper::e($category) ?>">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search by code, ref, MAC, action..." 
                value="<?= ViewHelper::e($search) ?>"
                style="min-width: 260px;"
            >
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="logs.php?cat=<?= ViewHelper::e($category) ?>" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <?php if ($category === 'transactions'): ?>
            <!-- Financial Transactions Table -->
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Date & Time</th>
                            <th>Transaction Reference</th>
                            <th>Voucher Code</th>
                            <th>Package</th>
                            <th>Method</th>
                            <th>Customer Phone</th>
                            <th>Amount</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: #64748b; padding: 28px;">
                                    No transaction records found.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($transactions as $t): ?>
                                <tr>
                                    <td><?= ViewHelper::formatDateTime($t['sale_date']) ?></td>
                                    <td><code><?= ViewHelper::e($t['transaction_reference']) ?></code></td>
                                    <td>
                                        <strong style="font-family: monospace; letter-spacing: 1px;">
                                            <?= ViewHelper::e($t['voucher_code']) ?>
                                        </strong>
                                    </td>
                                    <td><?= ViewHelper::e($t['package_name']) ?></td>
                                    <td>
                                        <span class="badge <?= in_array($t['payment_method'], ['SonicPesa', 'PesaPal']) ? 'badge-info' : 'badge-neutral' ?>">
                                            <?= ViewHelper::e($t['payment_method']) ?>
                                        </span>
                                    </td>
                                    <td><?= ViewHelper::e($t['customer_phone'] ?: '-') ?></td>
                                    <td><strong style="color: #0b5ed7;"><?= ViewHelper::formatCurrency($t['price']) ?></strong></td>
                                    <td><?= ViewHelper::statusBadge($t['status']) ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

        <?php else: ?>
            <!-- System Audit Logs Table -->
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>IP Address</th>
                            <th>Result</th>
                            <th>Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($auditLogs)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #64748b; padding: 28px;">
                                    No audit entries recorded for this category.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($auditLogs as $log): ?>
                                <tr>
                                    <td><?= ViewHelper::formatDateTime($log['timestamp'], 'H:i:s d M') ?></td>
                                    <td>
                                        <strong><?= ViewHelper::e($log['actor_type']) ?></strong>:
                                        <?= ViewHelper::e($log['actor_identifier'] ?: 'system') ?>
                                    </td>
                                    <td><code><?= ViewHelper::e($log['action']) ?></code></td>
                                    <td><?= ViewHelper::e($log['ip_address']) ?></td>
                                    <td><?= ViewHelper::statusBadge($log['result']) ?></td>
                                    <td style="font-size: 12.5px; color: #334155;">
                                        <?= ViewHelper::e($log['details']) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <!-- Pagination -->
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
