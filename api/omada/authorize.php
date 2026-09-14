<?php
/**
 * Air Link WiFi - API: Omada Authorize
 * Authorizes a client device directly with the Omada SDN Controller.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/ViewHelper.php';
require_once __DIR__ . '/../../services/OmadaService.php';

// Allow only authenticated administrators or internal verified calls
if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$clientMac = trim((string)($input['client_mac'] ?? ''));
$durationMinutes = (int)($input['duration_minutes'] ?? 60);
$apMac = trim((string)($input['ap_mac'] ?? ''));
$ssidName = trim((string)($input['ssid_name'] ?? ''));

if (empty($clientMac)) {
    echo json_encode(['success' => false, 'error' => 'Client MAC address is required.']);
    exit;
}

$omada = new OmadaService();
$res = $omada->authorizeClient(
    ViewHelper::normalizeMac($clientMac),
    $apMac ? ViewHelper::normalizeMac($apMac) : null,
    $ssidName ?: null,
    0,
    $durationMinutes
);

echo json_encode($res);
