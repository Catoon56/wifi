<?php
/**
 * Air Link WiFi - API: Terminate Session
 * Terminates an active Wi-Fi session and immediately deauthorizes client from Omada Controller.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/CSRF.php';
require_once __DIR__ . '/../../services/SessionService.php';

if (!Auth::check()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Unauthorized access.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!CSRF::validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid security token.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;
$sessionId = trim((string)($input['session_id'] ?? ''));

if (empty($sessionId)) {
    echo json_encode(['success' => false, 'error' => 'Session ID required.']);
    exit;
}

$admin = Auth::user();
$result = SessionService::terminateSession($sessionId, $admin['username'] ?? 'admin');

echo json_encode($result);
