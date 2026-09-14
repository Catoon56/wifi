<?php
/**
 * Air Link WiFi - SonicPesa Payment Integration
 * 
 * Handles API calls to SonicPesa for mobile money transactions.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/LoggerService.php';

class SonicPesaService {
    
    private string $apiKey;
    private string $baseUrl;
    private ?PDO $pdo;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->apiKey = Database::getSetting('sonicpesa_api_key', defined('SONICPESA_API_KEY') ? SONICPESA_API_KEY : '');
        $this->baseUrl = rtrim(
            Database::getSetting('sonicpesa_base_url', defined('SONICPESA_BASE_URL') ? SONICPESA_BASE_URL : 'https://api.sonicpesa.com/api/v1'),
            '/'
        );
    }

    /**
     * Submit an order to SonicPesa
     */
    public function createOrder(int $packageId, float $amount, string $phone, string $packageName, string $customerEmail = 'customer@airlinkwifi.co.tz', string $customerName = 'Customer'): array {
        
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error'   => 'Payment gateway is not configured (Missing API Key).',
            ];
        }

        // Clean phone number (e.g. 0712345678 or 255712345678)
        $cleanPhone = preg_replace('/[^0-9]/', '', $phone);
        
        // Generate a local reference for tracking
        $merchantRef = 'ALW-' . strtoupper(bin2hex(random_bytes(4))) . '-' . time();
        
        // Ensure amount is integer for TZS
        $amountInt = (int) ceil($amount);

        // Pre-insert pending payment record
        $stmt = $this->pdo->prepare("
            INSERT INTO `payments` 
            (`transaction_id`, `reference`, `merchant_reference`, `amount`, `currency`, `phone`, `package_id`, `payment_method`, `payment_status`) 
            VALUES 
            (NULL, :ref, :mref, :amt, :curr, :ph, :pkg, 'SonicPesa', 'pending')
        ");
        
        $stmt->execute([
            'ref'  => $merchantRef,
            'mref' => $merchantRef,
            'amt'  => $amountInt,
            'curr' => 'TZS', // SonicPesa typically uses TZS
            'ph'   => $cleanPhone,
            'pkg'  => $packageId,
        ]);
        
        $paymentRecordId = $this->pdo->lastInsertId();

        $payload = [
            'buyer_name'  => $customerName,
            'buyer_email' => $customerEmail,
            'buyer_phone' => $cleanPhone,
            'amount'      => $amountInt,
            'currency'    => 'TZS',
            'description' => "Air Link WiFi - $packageName"
        ];

        $url = $this->baseUrl . '/payment/create_order';
        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
        ];

        $res = $this->request('POST', $url, $payload, $headers);

        if (!$res['success']) {
            LoggerService::fileLog('payment', "Order submission failed: " . ($res['error'] ?? ''));
            return ['success' => false, 'error' => 'Payment gateway error: ' . ($res['error'] ?? 'Unable to initiate payment.')];
        }

        $json = json_decode($res['body'], true);

        if (isset($json['status']) && $json['status'] === 'success' && !empty($json['payment_url'])) {
            $trackingId = $json['order_id'] ?? '';

            // Update record with tracking ID
            $upd = $this->pdo->prepare("
                UPDATE `payments` 
                SET `order_tracking_id` = :trk, `raw_response` = :raw 
                WHERE `id` = :id
            ");
            $upd->execute([
                'trk' => $trackingId,
                'raw' => $res['body'],
                'id'  => $paymentRecordId,
            ]);

            LoggerService::log('customer', $cleanPhone, 'PAYMENT_INITIATED', null, 'success', "Order $merchantRef created. Tracking: $trackingId");

            return [
                'success'      => true,
                'redirect_url' => $json['payment_url'],
                'reference'    => $merchantRef,
                'tracking_id'  => $trackingId,
                'simulated'    => false,
            ];
        }

        $errorMsg = $json['message'] ?? 'Payment provider rejected request.';
        LoggerService::fileLog('payment', "Order rejected by SonicPesa: " . $res['body']);
        return ['success' => false, 'error' => $errorMsg];
    }
    
    /**
     * Get transaction status from SonicPesa
     * Official endpoint: POST /payment/order_status with {"order_id": ...}
     */
    public function getTransactionStatus(string $orderId): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API Key not configured.'];
        }
        
        $url = $this->baseUrl . '/payment/order_status';
        $headers = [
            'X-API-KEY: ' . $this->apiKey,
            'Content-Type: application/json',
        ];
        
        $payload = ['order_id' => $orderId];
        $res = $this->request('POST', $url, $payload, $headers);
        
        if (!$res['success']) {
            // Fallback to GET /payment/status/{orderId}
            $fallbackUrl = $this->baseUrl . '/payment/status/' . urlencode($orderId);
            $res = $this->request('GET', $fallbackUrl, [], $headers);
        }

        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'] ?? 'Failed to fetch status'];
        }
        
        $json = json_decode($res['body'], true);
        return [
            'success' => true,
            'status'  => $json['payment_status'] ?? ($json['status'] ?? 'unknown'),
            'raw'     => $json
        ];
    }

    /**
     * Internal HTTP executor
     */
    private function request(string $method, string $url, array $payload = [], array $headers = ['Content-Type: application/json']): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        }

        $body = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => $err ?: "HTTP request failed ($code)"];
        }

        return [
            'success' => ($code >= 200 && $code < 400),
            'code'    => $code,
            'body'    => $body,
        ];
    }
}
