<?php
/**
 * Air Link WiFi - Active Wi-Fi Sessions Management
 * Monitor real-time connected clients and terminate unauthorized or delinquent sessions.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../services/SessionService.php';

$pageTitle = "Client Wi-Fi Sessions";
$pdo = Database::getConnection();

$message = null;
$error = null;

// Handle Terminate Session Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();
    $action = trim($_POST['action'] ?? '');
    $sessionId = trim($_POST['session_id'] ?? '');

    if ($action === 'terminate' && !empty($sessionId)) {
        $result = SessionService::terminateSession($sessionId, $currentUser['username'] ?? 'admin');
        if ($result['success']) {
            $message = $result['message'];
        } else {
            $error = $result['error'] ?? 'Failed to terminate session.';
        }
    }
}

$statusFilter = trim($_GET['status'] ?? 'active');
$where = ["1=1"];
$params = [];

if (!empty($statusFilter) && in_array($statusFilter, ['active', 'disconnected', 'expired', 'terminated'])) {
    $where[] = "s.`status` = :st";
    $params['st'] = $statusFilter;
}

$search = trim($_GET['search'] ?? '');
if (!empty($search)) {
    $where[] = "(s.`client_mac` LIKE :s OR s.`client_ip` LIKE :s OR v.`voucher_code` LIKE :s)";
    $params['s'] = "%$search%";
}

$sql = "
    SELECT s.*, v.`voucher_code`, v.`expires_at`, p.`name` as `package_name`
    FROM `wifi_sessions` s
    JOIN `vouchers` v ON s.`voucher_id` = v.`id`
    JOIN `packages` p ON v.`package_id` = p.`id`
    WHERE " . implode(" AND ", $where) . "
    ORDER BY s.`id` DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$sessions = $stmt->fetchAll();

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
        <h3 class="card-title">Wi-Fi Sessions (<?= count($sessions) ?>)</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="sessions.php" class="filter-bar">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search MAC, IP, voucher..." 
                value="<?= ViewHelper::e($search) ?>"
                style="min-width: 220px;"
            >

            <select name="status" class="form-control">
                <option value="active" <?= $statusFilter === 'active' ? 'selected' : '' ?>>Active Connections</option>
                <option value="expired" <?= $statusFilter === 'expired' ? 'selected' : '' ?>>Expired</option>
                <option value="terminated" <?= $statusFilter === 'terminated' ? 'selected' : '' ?>>Terminated</option>
                <option value="" <?= $statusFilter === '' ? 'selected' : '' ?>>All Sessions</option>
            </select>

            <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
            <?php if ($search || $statusFilter !== 'active'): ?>
                <a href="sessions.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Client MAC</th>
                        <th>IP Address</th>
                        <th>Voucher Code</th>
                        <th>Package</th>
                        <th>Login Time</th>
                        <th>Last Seen</th>
                        <th>Remaining</th>
                        <th>Status</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($sessions)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #64748b; padding: 28px;">
                                No sessions match the selected filter.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($sessions as $s): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace;"><?= ViewHelper::e($s['client_mac']) ?></strong>
                                </td>
                                <td><code><?= ViewHelper::e($s['client_ip']) ?></code></td>
                                <td>
                                    <span style="font-family: monospace; font-weight: 600;">
                                        <?= ViewHelper::e($s['voucher_code']) ?>
                                    </span>
                                </td>
                                <td><?= ViewHelper::e($s['package_name']) ?></td>
                                <td><?= ViewHelper::formatDateTime($s['login_time']) ?></td>
                                <td><?= ViewHelper::formatDateTime($s['last_seen'], 'H:i:s') ?></td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <span class="badge badge-success">
                                            <?= ViewHelper::formatRemainingSeconds((int)$s['remaining_time']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">-</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= ViewHelper::statusBadge($s['status']) ?></td>
                                <td>
                                    <?php if ($s['status'] === 'active'): ?>
                                        <form method="POST" action="sessions.php" onsubmit="return confirm('Terminate this session and disconnect user from Wi-Fi?');">
                                            <?= CSRF::field() ?>
                                            <input type="hidden" name="action" value="terminate">
                                            <input type="hidden" name="session_id" value="<?= ViewHelper::e($s['session_id']) ?>">
                                            <button type="submit" class="btn btn-danger btn-sm">Terminate</button>
                                        </form>
                                    <?php else: ?>
                                        <span style="color: #94a3b8; font-size: 12px;">Closed</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
