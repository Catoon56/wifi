<?php
/**
 * Air Link WiFi - Customer MAC & Device Management
 * Tracks user hardware MAC addresses, total visits, and session history.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';

$pageTitle = "Customer Devices";
$pdo = Database::getConnection();

$search = trim($_GET['search'] ?? '');
$where = ["s.`client_mac` IS NOT NULL AND s.`client_mac` != ''"];
$params = [];

if (!empty($search)) {
    $where[] = "(s.`client_mac` LIKE :s OR s.`client_ip` LIKE :s)";
    $params['s'] = "%$search%";
}

$sql = "
    SELECT 
        s.`client_mac`,
        MAX(s.`client_ip`) as `last_ip`,
        COUNT(DISTINCT s.`id`) as `total_sessions`,
        MAX(s.`last_seen`) as `last_seen`,
        MIN(s.`login_time`) as `first_seen`,
        SUM(CASE WHEN s.`status` = 'active' THEN 1 ELSE 0 END) as `is_active`
    FROM `wifi_sessions` s
    WHERE " . implode(" AND ", $where) . "
    GROUP BY s.`client_mac`
    ORDER BY `last_seen` DESC
    LIMIT 100
";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$customers = $stmt->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Customer Devices (<?= count($customers) ?>)</h3>
    </div>
    <div class="card-body">
        <form method="GET" action="customers.php" class="filter-bar">
            <input 
                type="text" 
                name="search" 
                class="form-control" 
                placeholder="Search MAC or IP address..." 
                value="<?= ViewHelper::e($search) ?>"
                style="min-width: 240px;"
            >
            <button type="submit" class="btn btn-secondary btn-sm">Search</button>
            <?php if ($search): ?>
                <a href="customers.php" class="btn btn-secondary btn-sm">Reset</a>
            <?php endif; ?>
        </form>

        <div class="table-responsive">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Client MAC Address</th>
                        <th>Last Known IP</th>
                        <th>Total Sessions</th>
                        <th>First Seen</th>
                        <th>Last Seen</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($customers)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; color: #64748b; padding: 28px;">
                                No customer devices recorded yet.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($customers as $c): ?>
                            <tr>
                                <td>
                                    <strong style="font-family: monospace;"><?= ViewHelper::e($c['client_mac']) ?></strong>
                                </td>
                                <td><code><?= ViewHelper::e($c['last_ip']) ?></code></td>
                                <td><?= (int)$c['total_sessions'] ?> session(s)</td>
                                <td><?= ViewHelper::formatDateTime($c['first_seen']) ?></td>
                                <td><?= ViewHelper::formatDateTime($c['last_seen']) ?></td>
                                <td>
                                    <?php if ((int)$c['is_active'] > 0): ?>
                                        <span class="badge badge-success">Online</span>
                                    <?php else: ?>
                                        <span class="badge badge-neutral">Offline</span>
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
