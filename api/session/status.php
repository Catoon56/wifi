<?php
/**
 * Air Link WiFi - API: Session Status & Heartbeat
 * Used by portal countdown timer to sync remaining time with the server.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/SessionService.php';

$sessionId = trim((string)($_GET['session_id'] ?? ($_POST['session_id'] ?? '')));

if (empty($sessionId)) {
    echo json_encode(['success' => false, 'error' => 'Missing session identifier.']);
    exit;
}

$status = SessionService::heartbeat($sessionId);

echo json_encode(array_merge(['success' => true], $status));
