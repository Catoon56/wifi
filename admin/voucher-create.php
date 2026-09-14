<?php
/**
 * Air Link WiFi - Voucher Generation
 * Generate single or multiple vouchers with cryptographically secure random codes.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../services/VoucherService.php';

$pageTitle = "Generate Vouchers";
$pdo = Database::getConnection();

$message = null;
$error = null;
$createdBatchId = null;
$createdVouchers = [];

$packages = $pdo->query("SELECT * FROM `packages` WHERE `status` = 'active' ORDER BY `price` ASC")->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();

    $packageId = (int)($_POST['package_id'] ?? 0);
    $quantity = (int)($_POST['quantity'] ?? 1);
    $adminId = (int)($currentUser['id'] ?? 1);

    if ($packageId <= 0) {
        $error = "Please select an active package.";
    } elseif ($quantity < 1 || $quantity > 500) {
        $error = "Quantity must be between 1 and 500.";
    } else {
        if ($quantity === 1) {
            $res = VoucherService::createVoucher($packageId, $adminId);
            if ($res['success']) {
                $message = "Voucher {$res['voucher_code']} generated successfully!";
                $createdVouchers = [$res];
                // Record Cash sale so this voucher appears in financial reports
                $saleStmt = $pdo->prepare("
                    INSERT INTO `sales`
                    (`voucher_id`, `package_id`, `price`, `payment_method`, `admin_id`, `sale_date`, `status`, `notes`)
                    VALUES (:vid, :pid, :price, 'Cash', :admin_id, NOW(), 'completed', 'Admin-generated voucher')
                ");
                $saleStmt->execute([
                    'vid'      => $res['voucher_id'],
                    'pid'      => $packageId,
                    'price'    => $res['price'],
                    'admin_id' => $adminId,
                ]);
            } else {
                $error = $res['error'] ?? "Failed to generate voucher.";
            }
        } else {
            $batchRes = VoucherService::createBatch($packageId, $quantity, $adminId);
            if ($batchRes['success']) {
                $createdBatchId = $batchRes['batch_id'];
                $createdVouchers = $batchRes['vouchers'];
                $message = "Successfully generated {$batchRes['count']} vouchers! Batch ID: $createdBatchId";
                // Record a Cash sale for each voucher in the batch
                $saleStmt = $pdo->prepare("
                    INSERT INTO `sales`
                    (`voucher_id`, `package_id`, `price`, `payment_method`, `admin_id`, `sale_date`, `status`, `notes`)
                    VALUES (:vid, :pid, :price, 'Cash', :admin_id, NOW(), 'completed', 'Admin-generated voucher (batch)')
                ");
                foreach ($createdVouchers as $v) {
                    $saleStmt->execute([
                        'vid'      => $v['voucher_id'],
                        'pid'      => $packageId,
                        'price'    => $v['price'],
                        'admin_id' => $adminId,
                    ]);
                }
            } else {
                $error = $batchRes['error'] ?? "Failed to generate batch.";
            }
        }
    }
}

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= ViewHelper::e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">

    <!-- Generation Form Card -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Voucher Generator</h3>
        </div>
        <div class="card-body">
            <form method="POST" action="voucher-create.php">
                <?= CSRF::field() ?>

                <div class="form-group">
                    <label for="package_id" class="form-label">Select Package</label>
                    <select name="package_id" id="package_id" class="form-control" required>
                        <option value="">-- Choose Package --</option>
                        <?php foreach ($packages as $pkg): ?>
                            <option value="<?= (int)$pkg['id'] ?>">
                                <?= ViewHelper::e($pkg['name']) ?> (<?= ViewHelper::formatCurrency($pkg['price']) ?> - <?= ViewHelper::formatDuration($pkg['duration'], $pkg['duration_unit']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-top: 16px;">
                    <label for="quantity" class="form-label">Quantity to Generate</label>
                    <input 
                        type="number" 
                        name="quantity" 
                        id="quantity" 
                        class="form-control" 
                        value="1" 
                        min="1" 
                        max="500" 
                        required
                    >
                    <div class="form-help">Enter 1 for single voucher or up to 500 for bulk batch printing.</div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 20px;">
                    Generate Vouchers
                </button>
            </form>
        </div>
    </div>

    <!-- Results / Newly Created Vouchers Preview -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Generated Vouchers</h3>
            <?php if (!empty($createdBatchId)): ?>
                <a href="voucher-print.php?batch_id=<?= urlencode($createdBatchId) ?>" target="_blank" class="btn btn-primary btn-sm">
                    Print Batch
                </a>
            <?php elseif (!empty($createdVouchers)): ?>
                <a href="voucher-print.php?code=<?= urlencode($createdVouchers[0]['voucher_code']) ?>" target="_blank" class="btn btn-primary btn-sm">
                    Print Voucher
                </a>
            <?php endif; ?>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive" style="max-height: 400px; overflow-y: auto;">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Package</th>
                            <th>Price</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($createdVouchers)): ?>
                            <tr>
                                <td colspan="4" style="text-align: center; color: #64748b; padding: 28px;">
                                    Fill out the form and click Generate to create vouchers.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($createdVouchers as $v): ?>
                                <tr>
                                    <td>
                                        <strong style="font-family: monospace; font-size: 15px; letter-spacing: 1px;">
                                            <?= ViewHelper::e($v['voucher_code']) ?>
                                        </strong>
                                    </td>
                                    <td><?= ViewHelper::e($v['package_name']) ?></td>
                                    <td><?= ViewHelper::formatCurrency($v['price']) ?></td>
                                    <td>
                                        <a href="voucher-print.php?code=<?= urlencode($v['voucher_code']) ?>" target="_blank" class="btn btn-secondary btn-sm">
                                            Print
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
