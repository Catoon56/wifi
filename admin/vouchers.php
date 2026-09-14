<?php
/**
 * Air Link WiFi - Voucher Management
 * Search, filter, inspect, disable, and export vouchers.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Vouchers Directory";
$pdo = Database::getConnection();

$message = null;
$error = null;

// Handle Actions: Disable, Delete Unused
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();
    $action = trim($_POST['action'] ?? '');
    $voucherId = (int)($_POST['voucher_id'] ?? 0);

    if ($action === 'disable' && $voucherId > 0) {
        $stmt = $pdo->prepare("UPDATE `vouchers` SET `status` = 'disabled' WHERE `id` = :id");
        $stmt->execute(['id' => $voucherId]);
        $message = "Voucher successfully disabled.";
    } elseif ($action === 'delete_unused' && $voucherId > 0) {
        $stmt = $pdo->prepare("DELETE FROM `vouchers` WHERE `id` = :id AND `status` = 'unused'");
        $stmt->execute(['id' => $voucherId]);
        if ($stmt->rowCount() > 0) {
            $message = "Unused voucher successfully deleted.";
        } else {
            $error = "Only unused vouchers can be deleted.";
        }
    }
}

// Filters & Search Query
$search = trim($_GET['search'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');
$packageFilter = (int)($_GET['package_id'] ?? 0);

$where = ["1=1"];
$params = [];

if ($search !== '') {
    $where[] = "(v.`voucher_code` LIKE :s OR v.`client_mac` LIKE :s OR v.`batch_id` LIKE :s)";
    $params['s'] = "%$search%";
}

if ($statusFilter !== '' && in_array($statusFilter, ['unused', 'active', 'expired', 'disabled'])) {
    $where[] = "v.`status` = :st";
    $params['st'] = $statusFilter;
}

if ($packageFilter > 0) {
    $where[] = "v.`package_id` = :pkg";
    $params['pkg'] = $packageFilter;
}

// Handle CSV Export
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="airlink_vouchers_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['ID', 'Voucher Code', 'Package', 'Price (TZS)', 'Duration', 'Unit', 'Status', 'Created At', 'Activated At', 'Expires At', 'Client MAC', 'Batch ID']);

    $exportSql = "
        SELECT v.*, p.`name` as `package_name`
        FROM `vouchers` v
        JOIN `packages` p ON v.`package_id` = p.`id`
        WHERE " . implode(" AND ", $where) . "
        ORDER BY v.`id` DESC
    ";
    $exportStmt = $pdo->prepare($exportSql);
    $exportStmt->execute($params);

    while ($row = $exportStmt->fetch()) {
        fputcsv($output, [
            $row['id'],
            $row['voucher_code'],
            $row['package_name'],
            $row['price'],
            $row['duration'],
            $row['duration_unit'],
            $row['status'],
            $row['created_at'],
            $row['activated_at'] ?? '',
            $row['expires_at'] ?? '',
            $row['client_mac'] ?? '',
            $row['batch_id'] ?? '',
        ]);
    }
    fclose($output);
    exit;
}

// Pagination setup
$perPage = 25;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$countSql = "SELECT COUNT(*) FROM `vouchers` v WHERE " . implode(" AND ", $where);
$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

$sql = "
    SELECT v.*, p.`name` as `package_name`
    FROM `vouchers` v
    JOIN `packages` p ON v.`package_id` = p.`id`
    WHERE " . implode(" AND ", $where) . "
    ORDER BY v.`id` DESC
    LIMIT $perPage OFFSET $offset
";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

$allPackages = $pdo->query("SELECT `id`, `name` FROM `packages` ORDER BY `name` ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= ViewHelper::e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <div style="display: flex; gap: 12px; align-items: center; flex-wrap: wrap;">
            <h3 class="card-title">Vouchers (<?= number_format($totalRecords) ?>)</h3>
            <a href="voucher-create.php" class="btn btn-primary btn-sm">+ Generate Vouchers</a>
            <a href="voucher-print.php" class="btn btn-secondary btn-sm" target="_blank">Print Vouchers</a>
        </div>
        <div>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-secondary btn-sm">
                Export to CSV
            </a>
        </div>
    </div>

    <div class="card-body">
        <!-- Search & Filter Form -->
        <form method="GET" action="vouchers.php" class="filter-bar">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search code, MAC, batch..." 
                value="<?= ViewHelper::e($search) ?>"
                style="min-width: 220px;"
            >

            <select name="status" class="form-control">
                <option value="">All Statuses</option>
                <option value="unused" <?= $statusFilter === 'unused' ? 'selected' : '' ?>>Unused</option>
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="disabled" <?= $statusFilter === 'disabled' ? 'selected' : '' ?>>Disabled</option>
            </select>

            <select name="package_id" class="form-control">
                <option value="0">All Packages</option>
                <?php foreach ($allPackages as $pkg): ?>
                    <option value="<?= (int)$pkg['id'] ?>" <?= $packageFilter === (int)$pkg['id'] ? 'selected' : '' ?>>
                        <?= ViewHelper::e($pkg['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($search || $statusFilter || $packageFilter): ?>
                <a href="vouchers.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Voucher Code</th>
                        <th>Package</th>
                        <th>Price</th>
                        <th>Status</th>
                        <th>Activated At</th>
                        <th>Expires At</th>
                        <th>Client MAC</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($vouchers)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #64748b; padding: 24px;">
                                No vouchers match the specified criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($vouchers as $v): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace; letter-spacing: 1px;">
                                        <?= ViewHelper::e($v['voucher_code']) ?>
                                    </strong>
                                </td>
                                <td><?= ViewHelper::e($v['package_name']) ?></td>
                                <td><?= ViewHelper::formatCurrency($v['price']) ?></td>
                                <td><?= ViewHelper::statusBadge($v['status']) ?></td>
                                <td><?= ViewHelper::formatDateTime($v['activated_at']) ?></td>
                                <td><?= ViewHelper::formatDateTime($v['expires_at']) ?></td>
                                <td>
                                    <?php if (!empty($v['client_mac'])): ?>
                                        <code style="font-size: 12px;"><?= ViewHelper::e($v['client_mac']) ?></code>
                                    <?php else: ?>
                                        <span style="color: #94a3b8;">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <?php if ($v['status'] === 'unused'): ?>
                                            <form method="POST" action="vouchers.php" onsubmit="return confirm('Delete this unused voucher?');">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="delete_unused">
                                                <input type="hidden" name="voucher_id" value="<?= (int)$v['id'] ?>">
                                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                            </form>
                                        <?php endif; ?>

                                        <?php if ($v['status'] !== 'disabled' && $v['status'] !== 'expired'): ?>
                                            <form method="POST" action="vouchers.php" onsubmit="return confirm('Disable this voucher?');">
                                                <?= CSRF::field() ?>
                                                <input type="hidden" name="action" value="disable">
                                                <input type="hidden" name="voucher_id" value="<?= (int)$v['id'] ?>">
                                                <button type="submit" class="btn btn-secondary btn-sm">Disable</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 16px; font-size: 13px;">
                <div>Showing page <?= $page ?> of <?= $totalPages ?> (Total: <?= number_format($totalRecords) ?>)</div>
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
