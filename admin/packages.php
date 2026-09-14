<?php
/**
 * Air Link WiFi - Package Management
 * Create, edit, and configure Wi-Fi plans, rates, durations, and speed limits.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Wi-Fi Packages";
$pdo = Database::getConnection();

$message = null;
$error = null;

// Handle CRUD Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();
    $action = trim($_POST['action'] ?? '');

    if ($action === 'create' || $action === 'update') {
        $name     = trim($_POST['name'] ?? '');
        $price    = (float)($_POST['price'] ?? 0);
        $duration = (int)($_POST['duration'] ?? 1);
        $unit     = strtolower(trim($_POST['duration_unit'] ?? 'hours'));
        $down     = (int)($_POST['download_speed'] ?? 0);
        $up       = (int)($_POST['upload_speed'] ?? 0);
        $devices  = max(1, (int)($_POST['max_devices'] ?? 1));
        $status   = in_array($_POST['status'] ?? '', ['active', 'inactive']) ? $_POST['status'] : 'active';

        if (empty($name) || $duration <= 0) {
            $error = "Package name and a positive duration are required.";
        } else {
            if ($action === 'create') {
                $stmt = $pdo->prepare("
                    INSERT INTO `packages` 
                    (`name`, `price`, `duration`, `duration_unit`, `download_speed`, `upload_speed`, `max_devices`, `status`)
                    VALUES (:name, :price, :dur, :unit, :down, :up, :dev, :status)
                ");
                $stmt->execute([
                    'name'   => $name,
                    'price'  => $price,
                    'dur'    => $duration,
                    'unit'   => $unit,
                    'down'   => $down,
                    'up'     => $up,
                    'dev'    => $devices,
                    'status' => $status,
                ]);
                $message = "New package '$name' created successfully.";
            } else {
                $pkgId = (int)($_POST['package_id'] ?? 0);
                $stmt = $pdo->prepare("
                    UPDATE `packages` 
                    SET `name` = :name, `price` = :price, `duration` = :dur, `duration_unit` = :unit,
                        `download_speed` = :down, `upload_speed` = :up, `max_devices` = :dev, `status` = :status
                    WHERE `id` = :id
                ");
                $stmt->execute([
                    'name'   => $name,
                    'price'  => $price,
                    'dur'    => $duration,
                    'unit'   => $unit,
                    'down'   => $down,
                    'up'     => $up,
                    'dev'    => $devices,
                    'status' => $status,
                    'id'     => $pkgId,
                ]);
                $message = "Package '$name' updated successfully.";
            }
        }
    } elseif ($action === 'delete') {
        $pkgId = (int)($_POST['package_id'] ?? 0);
        // Check if vouchers exist for this package
        $vCount = (int)$pdo->query("SELECT COUNT(*) FROM `vouchers` WHERE `package_id` = $pkgId")->fetchColumn();
        if ($vCount > 0) {
            // Deactivate instead of deleting to preserve historical records
            $pdo->query("UPDATE `packages` SET `status` = 'inactive' WHERE `id` = $pkgId");
            $message = "Package has existing vouchers; marked as Inactive instead of deleting.";
        } else {
            $pdo->query("DELETE FROM `packages` WHERE `id` = $pkgId");
            $message = "Package deleted successfully.";
        }
    }
}

// Edit Mode check
$editPackage = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM `packages` WHERE `id` = :id LIMIT 1");
    $stmt->execute(['id' => $editId]);
    $editPackage = $stmt->fetch();
}

$packages = $pdo->query("SELECT * FROM `packages` ORDER BY `price` ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= ViewHelper::e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
<?php endif; ?>

<div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(360px, 1fr)); gap: 24px;">

    <!-- Package Add/Edit Form -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title"><?= $editPackage ? 'Edit Package: ' . ViewHelper::e($editPackage['name']) : 'Add New Wi-Fi Package' ?></h3>
            <?php if ($editPackage): ?>
                <a href="packages.php" class="btn btn-secondary btn-sm">Cancel Edit</a>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="POST" action="packages.php">
                <?= CSRF::field() ?>
                <input type="hidden" name="action" value="<?= $editPackage ? 'update' : 'create' ?>">
                <?php if ($editPackage): ?>
                    <input type="hidden" name="package_id" value="<?= (int)$editPackage['id'] ?>">
                <?php endif; ?>

                <div class="form-group">
                    <label for="name" class="form-label">Package Name</label>
                    <input 
                        type="text" 
                        name="name" 
                        id="name" 
                        class="form-control" 
                        placeholder="e.g. 1 Hour, 24 Hours" 
                        value="<?= ViewHelper::e($editPackage['name'] ?? '') ?>" 
                        required
                    >
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px;">
                    <div class="form-group">
                        <label for="duration" class="form-label">Duration</label>
                        <input 
                            type="number" 
                            name="duration" 
                            id="duration" 
                            class="form-control" 
                            value="<?= (int)($editPackage['duration'] ?? 1) ?>" 
                            min="1" 
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="duration_unit" class="form-label">Duration Unit</label>
                        <select name="duration_unit" id="duration_unit" class="form-control" required>
                            <option value="minutes" <?= ($editPackage['duration_unit'] ?? '') === 'minutes' ? 'selected' : '' ?>>Minutes</option>
                            <option value="hours" <?= ($editPackage['duration_unit'] ?? 'hours') === 'hours' ? 'selected' : '' ?>>Hours</option>
                            <option value="days" <?= ($editPackage['duration_unit'] ?? '') === 'days' ? 'selected' : '' ?>>Days</option>
                        </select>
                    </div>
                </div>

                <div class="form-group" style="margin-top: 14px;">
                    <label for="price" class="form-label">Price in TZS (TSh)</label>
                    <input 
                        type="number" 
                        name="price" 
                        id="price" 
                        class="form-control" 
                        step="50" 
                        placeholder="e.g. 500, 1000, 2000" 
                        value="<?= (float)($editPackage['price'] ?? 500) ?>" 
                        required
                    >
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px;">
                    <div class="form-group">
                        <label for="download_speed" class="form-label">Download (Mbps)</label>
                        <input 
                            type="number" 
                            name="download_speed" 
                            id="download_speed" 
                            class="form-control" 
                            value="<?= (int)($editPackage['download_speed'] ?? 3) ?>" 
                            min="0"
                        >
                        <div class="form-help">0 = Unlimited</div>
                    </div>

                    <div class="form-group">
                        <label for="upload_speed" class="form-label">Upload (Mbps)</label>
                        <input 
                            type="number" 
                            name="upload_speed" 
                            id="upload_speed" 
                            class="form-control" 
                            value="<?= (int)($editPackage['upload_speed'] ?? 1) ?>" 
                            min="0"
                        >
                        <div class="form-help">0 = Unlimited</div>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px; margin-top: 14px;">
                    <div class="form-group">
                        <label for="max_devices" class="form-label">Max Devices</label>
                        <input 
                            type="number" 
                            name="max_devices" 
                            id="max_devices" 
                            class="form-control" 
                            value="<?= (int)($editPackage['max_devices'] ?? 1) ?>" 
                            min="1" 
                            max="10"
                        >
                    </div>

                    <div class="form-group">
                        <label for="status" class="form-label">Status</label>
                        <select name="status" id="status" class="form-control">
                            <option value="active" <?= ($editPackage['status'] ?? 'active') === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= ($editPackage['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                        </select>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary" style="margin-top: 20px;">
                    <?= $editPackage ? 'Save Package Changes' : 'Create Package' ?>
                </button>
            </form>
        </div>
    </div>

    <!-- Existing Packages Table -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Configured Packages (<?= count($packages) ?>)</h3>
        </div>
        <div class="card-body" style="padding: 0;">
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>Package</th>
                            <th>Price</th>
                            <th>Duration</th>
                            <th>Speed</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packages as $p): ?>
                            <tr>
                                <td><strong><?= ViewHelper::e($p['name']) ?></strong></td>
                                <td><?= ViewHelper::formatCurrency($p['price']) ?></td>
                                <td><?= ViewHelper::formatDuration($p['duration'], $p['duration_unit']) ?></td>
                                <td>
                                    <?= (int)$p['download_speed'] ?>M / <?= (int)$p['upload_speed'] ?>M
                                </td>
                                <td><?= ViewHelper::statusBadge($p['status']) ?></td>
                                <td>
                                    <div style="display: flex; gap: 6px;">
                                        <a href="packages.php?edit=<?= (int)$p['id'] ?>" class="btn btn-secondary btn-sm">Edit</a>
                                        <form method="POST" action="packages.php" onsubmit="return confirm('Delete or deactivate this package?');">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="package_id" value="<?= (int)$p['id'] ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
