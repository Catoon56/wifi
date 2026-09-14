<?php
/**
 * Air Link WiFi - SonicPesa Webhook Callback Listener
 * 
 * Secure backend-to-backend transaction verification.
 * Automatically generates a digital voucher code upon successful payment.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../services/SonicPesaService.php';
require_once __DIR__ . '/../../services/VoucherService.php';
require_once __DIR__ . '/../../services/LoggerService.php';

$payloadRaw = file_get_contents('php://input');
$headers = getallheaders();

// 1. Signature Verification (if webhook secret or signature is provided)
$webhookSecret = Database::getSetting('sonicpesa_webhook_secret', defined('SONICPESA_WEBHOOK_SECRET') ? SONICPESA_WEBHOOK_SECRET : '');
if (empty($webhookSecret)) {
    $webhookSecret = Database::getSetting('sonicpesa_api_key', defined('SONICPESA_API_KEY') ? SONICPESA_API_KEY : '');
}

$signatureHeader = defined('SONICPESA_WEBHOOK_SIGNATURE_HEADER') ? SONICPESA_WEBHOOK_SIGNATURE_HEADER : 'X-Signature';
$incomingSignature = $headers[$signatureHeader] 
    ?? ($headers[strtolower($signatureHeader)] 
    ?? ($headers['X-API-KEY'] 
    ?? ($headers['x-api-key'] ?? '')));

if (!empty($webhookSecret) && !empty($incomingSignature)) {
    $calculatedSignature = hash_hmac('sha256', $payloadRaw, $webhookSecret);
    // Allow either direct API key match or HMAC-SHA256 signature match
    if (!hash_equals($calculatedSignature, $incomingSignature) && !hash_equals($webhookSecret, $incomingSignature)) {
        LoggerService::fileLog('payment', "Webhook signature mismatch. Payload: $payloadRaw");
        http_response_code(403);
        echo json_encode(["status" => "error", "message" => "Invalid signature"]);
        exit;
    }
}

// 2. Decode Payload
$data = json_decode($payloadRaw, true);
if (json_last_error() !== JSON_ERROR_NONE) {
    LoggerService::fileLog('payment', "Webhook invalid JSON: $payloadRaw");
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Invalid JSON payload"]);
    exit;
}

LoggerService::fileLog('payment', "Webhook received: " . $payloadRaw);

// Extract fields supporting SonicPesa standard naming
$status = strtolower((string)($data['payment_status'] ?? ($data['status'] ?? '')));
$orderTrackingId = (string)($data['order_id'] ?? ($data['order_tracking_id'] ?? ''));
$transactionId = (string)($data['transaction_id'] ?? ($data['reference'] ?? ''));

if (empty($orderTrackingId)) {
    http_response_code(400);
    echo json_encode(["status" => "error", "message" => "Missing order_id"]);
    exit;
}

$pdo = Database::getConnection();

// Locate payment
$stmt = $pdo->prepare("
    SELECT * FROM `payments` 
    WHERE `order_tracking_id` = :trk 
    LIMIT 1
");
$stmt->execute(['trk' => $orderTrackingId]);
$payment = $stmt->fetch();

if (!$payment) {
    LoggerService::fileLog('payment', "Error: Payment record not found for Tracking: $orderTrackingId");
    http_response_code(404);
    echo json_encode(["status" => "error", "message" => "Order not found"]);
    exit;
}

// Ensure idempotency - if already paid, just return 200 OK
if ($payment['payment_status'] === 'completed') {
    http_response_code(200);
    echo json_encode(["status" => "success", "message" => "Already processed"]);
    exit;
}

if (in_array($status, ['paid', 'success', 'completed'])) {
    // AUTOMATIC DIGITAL VOUCHER GENERATION
    
    // Webhooks don't have session, but we can try to get them if this was called directly via a redirect, 
    // although this is typically a backend call.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $clientMac = $_SESSION['omada_params']['clientMac'] ?? null;
    $omadaParams = $_SESSION['omada_params'] ?? [];

    $issueRes = VoucherService::generateAndIssueVoucher(
        (int)$payment['package_id'],
        $payment['phone'],
        $clientMac,
        'SonicPesa',
        $payment['reference'],
        $omadaParams
    );

    if ($issueRes['success']) {
        $voucherCode = $issueRes['voucher_code'];

        // Update payments table with completed status & voucher_id
        $upd = $pdo->prepare("
            UPDATE `payments` 
            SET `payment_status` = 'completed', 
                `voucher_id` = :vid, 
                `transaction_id` = :txid,
                `raw_response` = :raw,
                `updated_at` = NOW() 
            WHERE `id` = :id
        ");
        $upd->execute([
            'vid'  => $issueRes['voucher_id'],
            'txid' => $transactionId,
            'raw'  => json_encode($data),
            'id'   => $payment['id'],
        ]);

        LoggerService::log(
            'system',
            $payment['phone'] ?? 'online_customer',
            'PAYMENT_VERIFIED',
            null,
            'success',
            "Payment {$payment['reference']} confirmed via SonicPesa. Generated voucher $voucherCode automatically."
        );
    }
} else if (in_array($status, ['failed', 'cancelled', 'rejected'])) {
    // Payment failed, cancelled, or rejected
    $upd = $pdo->prepare("UPDATE `payments` SET `payment_status` = 'failed' WHERE `id` = :id");
    $upd->execute(['id' => $payment['id']]);

    LoggerService::log('system', $payment['phone'] ?? 'customer', 'PAYMENT_FAILED', null, 'failure', "Payment status: $status for order $orderTrackingId");
}

// Always return 200 OK to acknowledge receipt of webhook
http_response_code(200);
echo json_encode(["status" => "success", "message" => "Webhook processed"]);
exit;
