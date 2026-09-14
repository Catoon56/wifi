<?php
/**
 * Air Link WiFi - API: Voucher Validate
 * Checks if a voucher code exists and is eligible for use without activating it.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/ViewHelper.php';

$code = strtoupper(trim($_GET['code'] ?? ($_POST['code'] ?? '')));

if (empty($code)) {
    echo json_encode(['success' => false, 'error' => 'Please provide a voucher code.']);
    exit;
}

try {
    $pdo = Database::getConnection();
    $stmt = $pdo->prepare("
        SELECT v.`id`, v.`voucher_code`, v.`status`, v.`duration`, v.`duration_unit`, v.`price`, p.`name` as `package_name`
        FROM `vouchers` v
        JOIN `packages` p ON v.`package_id` = p.`id`
        WHERE v.`voucher_code` = :code
        LIMIT 1
    ");
    $stmt->execute(['code' => $code]);
    $voucher = $stmt->fetch();

    if (!$voucher) {
        echo json_encode(['success' => false, 'error' => 'Invalid voucher code.']);
        exit;
    }

    if ($voucher['status'] === 'disabled') {
        echo json_encode(['success' => false, 'error' => 'This voucher has been disabled.']);
        exit;
    }

    if ($voucher['status'] === 'expired') {
        echo json_encode(['success' => false, 'error' => 'This voucher has expired.']);
        exit;
    }

    echo json_encode([
        'success'      => true,
        'voucher_code' => $voucher['voucher_code'],
        'status'       => $voucher['status'],
        'package_name' => $voucher['package_name'],
        'duration'     => ViewHelper::formatDuration($voucher['duration'], $voucher['duration_unit']),
        'price'        => ViewHelper::formatCurrency($voucher['price']),
    ]);
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'error' => 'System error validating voucher.']);
}
