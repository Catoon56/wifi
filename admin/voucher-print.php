<?php
/**
 * Air Link WiFi - Printable Voucher Layout
 * Clean format for thermal receipt printers (58mm/80mm) and A4 grid sheets.
 * Zero gradients, zero emojis, high-contrast, clean typography.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pdo = Database::getConnection();
$businessName = Database::getSetting('business_name', APP_NAME);

$batchId = trim($_GET['batch_id'] ?? '');
$code = trim($_GET['code'] ?? '');
$packageId = (int)($_GET['package_id'] ?? 0);
$limit = (int)($_GET['limit'] ?? 50);

$where = ["1=1"];
$params = [];

if ($batchId !== '') {
    $where[] = "v.`batch_id` = :b";
    $params['b'] = $batchId;
} elseif ($code !== '') {
    $where[] = "v.`voucher_code` = :c";
    $params['c'] = $code;
} elseif ($packageId > 0) {
    $where[] = "v.`package_id` = :p AND v.`status` = 'unused'";
    $params['p'] = $packageId;
} else {
    // Default to last 20 unused vouchers
    $where[] = "v.`status` = 'unused'";
}

$sql = "
    SELECT v.*, p.`name` as `package_name`
    FROM `vouchers` v
    JOIN `packages` p ON v.`package_id` = p.`id`
    WHERE " . implode(" AND ", $where) . "
    ORDER BY v.`id` DESC
    LIMIT $limit
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$vouchers = $stmt->fetchAll();

$isThermal = isset($_GET['mode']) && $_GET['mode'] === 'thermal';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= ViewHelper::e($businessName) ?> - Print Vouchers</title>
    <link rel="stylesheet" href="../assets/css/print.css">
</head>
<body class="<?= $isThermal ? 'thermal-mode' : '' ?>">

<div class="print-actions no-print">
    <div style="font-weight: 700; font-size: 16px;">
        Voucher Print Preview (<?= count($vouchers) ?> vouchers)
    </div>
    <div style="display: flex; gap: 8px;">
        <?php if ($isThermal): ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['mode' => 'a4'])) ?>" class="btn btn-secondary" style="background:#fff; border:1px solid #ccc; padding:6px 12px; text-decoration:none; color:#333; font-size:13px; border-radius:4px;">
                Switch to A4 Grid Mode
            </a>
        <?php else: ?>
            <a href="?<?= http_build_query(array_merge($_GET, ['mode' => 'thermal'])) ?>" class="btn btn-secondary" style="background:#fff; border:1px solid #ccc; padding:6px 12px; text-decoration:none; color:#333; font-size:13px; border-radius:4px;">
                Switch to Thermal Receipt Mode
            </a>
        <?php endif; ?>
        <button type="button" onclick="window.print();" style="background:#0b5ed7; color:#fff; border:none; padding:8px 18px; font-weight:600; font-size:14px; border-radius:4px; cursor:pointer;">
            Print Now
        </button>
    </div>
</div>

<?php if (empty($vouchers)): ?>
    <div style="text-align: center; padding: 40px; color: #64748b;">
        No matching vouchers found to print.
    </div>
<?php else: ?>
    <div class="voucher-grid">
        <?php foreach ($vouchers as $v): ?>
            <div class="voucher-ticket">
                <div class="ticket-header">
                    <?= ViewHelper::e($businessName) ?>
                </div>
                
                <div class="ticket-package">
                    <?= ViewHelper::e($v['package_name']) ?>
                </div>

                <div class="ticket-code-label">Voucher Code</div>
                <div class="ticket-code">
                    <?= ViewHelper::e($v['voucher_code']) ?>
                </div>

                <div class="ticket-details">
                    <span>Price: <strong><?= ViewHelper::formatCurrency($v['price']) ?></strong></span>
                    <span>Duration: <strong><?= ViewHelper::formatDuration($v['duration'], $v['duration_unit']) ?></strong></span>
                </div>

                <div class="ticket-instructions">
                    Connect to "Air Link WiFi" and enter this voucher code on the portal.
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

</body>
</html>
