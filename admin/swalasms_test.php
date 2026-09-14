<?php
/**
 * Air Link WiFi - Test SwalaSMS Connection
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../services/SwalaSmsService.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$swalaService = new SwalaSmsService();
$res = $swalaService->testConnection();

echo json_encode($res);
