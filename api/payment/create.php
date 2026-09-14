<?php
/**
 * Air Link WiFi - API: Payment Create
 * Submits a new order request to SonicPesa.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRF.php';
require_once __DIR__ . '/../../services/SonicPesaService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!CSRF::validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid.']);
    exit;
}

$isSonicEnabled = Database::getSetting('sonicpesa_enabled', defined('SONICPESA_ENABLED') && SONICPESA_ENABLED ? '1' : '0');
if ($isSonicEnabled !== '1') {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Online payments are currently disabled.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$packageId = (int)($input['package_id'] ?? 0);
$phone     = trim((string)($input['phone'] ?? ''));
$clientMac = trim((string)($input['client_mac'] ?? ($_SESSION['omada_params']['clientMac'] ?? '')));

$pdo = Database::getConnection();
$stmt = $pdo->prepare("SELECT * FROM `packages` WHERE `id` = :id AND `status` = 'active' LIMIT 1");
$stmt->execute(['id' => $packageId]);
$pkg = $stmt->fetch();

if (!$pkg) {
    echo json_encode(['success' => false, 'error' => 'Invalid Wi-Fi package.']);
    exit;
}

$sonicpesa = new SonicPesaService();
$res = $sonicpesa->createOrder(
    (int)$pkg['id'],
    (float)$pkg['price'],
    $phone,
    $pkg['name']
);

echo json_encode($res);
