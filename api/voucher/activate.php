<?php
/**
 * Air Link WiFi - API: Voucher Activate
 * Activates an unused or reconnects an active voucher, talks to Omada Controller, and returns JSON.
 */

declare(strict_types=1);

header('Content-Type: application/json');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/CSRF.php';
require_once __DIR__ . '/../../includes/RateLimiter.php';
require_once __DIR__ . '/../../includes/ViewHelper.php';
require_once __DIR__ . '/../../services/VoucherService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!CSRF::validate()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security token invalid. Please refresh the page.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

$code       = strtoupper(trim($input['voucher_code'] ?? ''));
$clientMac  = trim($input['client_mac'] ?? ($_SESSION['omada_params']['clientMac'] ?? ''));
$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$normalizedMac = ViewHelper::normalizeMac($clientMac);

// Brute-force rate limiter
$maxAttempts = (int)Database::getSetting('max_voucher_attempts', defined('MAX_VOUCHER_ATTEMPTS') ? (string)MAX_VOUCHER_ATTEMPTS : '5');
$lockoutMin  = (int)Database::getSetting('lockout_duration_minutes', defined('LOCKOUT_MINUTES') ? (string)LOCKOUT_MINUTES : '15');
$rateKey = 'api_voucher_try_' . md5($clientIp . '_' . $normalizedMac);
if (RateLimiter::tooManyAttempts($rateKey, $maxAttempts, $lockoutMin * 60)) {
    $cooldown = ceil(RateLimiter::availableIn($rateKey) / 60);
    echo json_encode([
        'success' => false,
        'error'   => "Too many attempts. Please wait $cooldown minute(s) before trying again."
    ]);
    exit;
}

$omadaParams = $_SESSION['omada_params'] ?? [];
$result = VoucherService::activateVoucher($code, $normalizedMac ?: 'UNKNOWN-MAC', $clientIp, $omadaParams);

if ($result['success']) {
    RateLimiter::clear($rateKey);

    $_SESSION['active_session_id']   = $result['session_id'];
    $_SESSION['active_voucher_code'] = $result['voucher_code'];
    $_SESSION['active_package_name'] = $result['package_name'];
    $_SESSION['active_expires_at']   = $result['expires_at'];
    $_SESSION['active_activated_at'] = $result['activated_at'];

    echo json_encode([
        'success'           => true,
        'redirect'          => 'status.php',
        'voucher_code'      => $result['voucher_code'],
        'package_name'      => $result['package_name'],
        'remaining_seconds' => $result['remaining_seconds'],
        'session_id'        => $result['session_id'],
    ]);
} else {
    RateLimiter::hit($rateKey, $lockoutMin * 60);
    echo json_encode([
        'success' => false,
        'error'   => $result['error'] ?? 'Voucher activation failed.',
    ]);
}
