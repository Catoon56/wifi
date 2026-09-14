<?php
/**
 * SwalaSMS Delivery Webhook Endpoint
 * Air Link Hotspot System
 * 
 * Handles incoming delivery status callbacks from SwalaSMS.
 * Verifies webhook signatures when configured and updates message delivery statuses.
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../services/SwalaSmsService.php';
require_once __DIR__ . '/../../services/LoggerService.php';

// Accept only POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed']);
    exit;
}

$rawPayload = file_get_contents('php://input');
if (empty($rawPayload)) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$signature = $_SERVER['HTTP_X_SWALASMS_SIGNATURE'] ?? '';

$swalaService = new SwalaSmsService();
$result = $swalaService->handleWebhook($rawPayload, $signature);

if ($result['status'] === 'success') {
    http_response_code(200);
    echo json_encode(['status' => 'success', 'message' => $result['message']]);
} elseif ($result['status'] === 'unauthorized') {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => $result['message']]);
} else {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => $result['message']]);
}
