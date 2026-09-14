<?php
/**
 * Air Link WiFi - SwalaSMS Integration Service
 *
 * Sends SMS notifications via the SwalaSMS REST API.
 * API Docs: https://swalasms.com/sms/developer/docs
 *
 * Authentication: Bearer token (Authorization: Bearer swl_live_... or swl_test_...)
 * Send SMS:  POST https://swalasms.com/api/v1/sms/messages
 * Bulk SMS:  POST https://swalasms.com/api/v1/sms/send-bulk
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/LoggerService.php';

class SwalaSmsService {

    private string $apiKey;
    private string $senderId;
    private string $baseUrl;
    private ?PDO $pdo;

    public function __construct() {
        // Load from DB settings first, fall back to constants
        $this->apiKey   = Database::getSetting('swalasms_api_key', defined('SWALASMS_API_KEY') ? SWALASMS_API_KEY : '');
        $this->senderId = Database::getSetting('swalasms_sender_id', defined('SWALASMS_SENDER_ID') ? SWALASMS_SENDER_ID : '');
        $this->baseUrl  = rtrim(
            Database::getSetting('swalasms_base_url', defined('SWALASMS_BASE_URL') ? SWALASMS_BASE_URL : 'https://swalasms.com/api/v1'),
            '/'
        );
        $this->pdo = Database::getConnection();
    }

    /**
     * Send a single SMS message.
     *
     * @param string $recipient  Phone number in E.164 format (e.g. +255712345678)
     * @param string $body       Message text (up to 1600 chars)
     * @param string|null $idempotencyKey  Optional UUID to prevent duplicate sends
     * @return array  ['success' => bool, 'message_id' => string|null, 'error' => string|null]
     */
    public function sendSms(string $recipient, string $body, ?string $idempotencyKey = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'message_id' => null, 'error' => 'SwalaSMS API key is not configured.'];
        }
        if (empty($this->senderId)) {
            return ['success' => false, 'message_id' => null, 'error' => 'SwalaSMS sender ID is not configured.'];
        }

        // Normalize phone to E.164 if it isn't already
        $cleanRecipient = $this->normalizePhone($recipient);

        $payload = [
            'recipient' => $cleanRecipient,
            'sender_id' => $this->senderId,
            'body'      => $body,
        ];

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        if ($idempotencyKey) {
            $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
        }

        $url = $this->baseUrl . '/sms/messages';

        // Pre-insert SMS record
        $smsRecordId = null;
        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO `sms_messages` (`recipient`, `sender_id`, `body`, `status`)
                VALUES (:recipient, :sender_id, :body, 'pending')
            ");
            $stmt->execute([
                'recipient' => $cleanRecipient,
                'sender_id' => $this->senderId,
                'body'      => $body,
            ]);
            $smsRecordId = $this->pdo->lastInsertId();
        } catch (\Throwable $e) {
            LoggerService::fileLog('sms', "Failed to insert SMS record: " . $e->getMessage());
            // Continue anyway — sending the SMS is more important than the DB record
        }

        $res = $this->request('POST', $url, $payload, $headers);

        if (!$res['success']) {
            $errorMsg = $res['error'] ?? 'HTTP request to SwalaSMS failed.';
            LoggerService::fileLog('sms', "SMS send failed to $cleanRecipient: $errorMsg");

            if ($smsRecordId) {
                $this->updateSmsRecord($smsRecordId, 'failed', null, $errorMsg);
            }

            return ['success' => false, 'message_id' => null, 'error' => $errorMsg];
        }

        $json = json_decode($res['body'], true);
        $messageId = $json['message_id'] ?? $json['id'] ?? null;

        if ($smsRecordId) {
            $this->updateSmsRecord($smsRecordId, 'sent', $messageId);
        }

        LoggerService::fileLog('sms', "SMS sent to $cleanRecipient, message_id: $messageId");

        return ['success' => true, 'message_id' => $messageId, 'error' => null];
    }

    /**
     * Test connectivity to the SwalaSMS API.
     * Attempts a lightweight request to verify credentials.
     */
    public function testConnection(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'message' => 'SwalaSMS API key is not configured.'];
        }

        // We'll try a GET to the base URL or a known endpoint
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ];

        // Try sending to the messages endpoint with an empty body to test auth
        // This should return a validation error (422) if auth works, or 401/403 if it doesn't
        $url = $this->baseUrl . '/sms/messages';
        $res = $this->request('POST', $url, ['recipient' => '', 'sender_id' => '', 'body' => ''], $headers);

        $code = $res['code'] ?? 0;

        if ($code === 401 || $code === 403) {
            return ['success' => false, 'message' => 'Authentication failed. Check your API key.'];
        }

        if ($code === 0) {
            return ['success' => false, 'message' => 'Could not reach SwalaSMS. Error: ' . ($res['error'] ?? 'Unknown')];
        }

        // 422 (validation error) or 429 (rate limited) means auth worked
        return ['success' => true, 'message' => "Connected to SwalaSMS API successfully (HTTP $code)."];
    }

    /**
     * Process an incoming SwalaSMS webhook delivery report.
     *
     * @param string $rawPayload  Raw JSON body from the webhook
     * @param string $signatureHeader  Value of X-SwalaSMS-Signature header
     * @return bool  True if processed successfully
     */
    public function handleWebhook(string $rawPayload, string $signatureHeader): bool {
        // Verify HMAC-SHA256 signature
        $calculatedSignature = hash_hmac('sha256', $rawPayload, $this->apiKey);
        if (!hash_equals($calculatedSignature, $signatureHeader)) {
            LoggerService::fileLog('sms', "Webhook signature mismatch.");
            return false;
        }

        $data = json_decode($rawPayload, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            LoggerService::fileLog('sms', "Webhook invalid JSON.");
            return false;
        }

        $event     = $data['event'] ?? '';
        $messageId = $data['message_id'] ?? '';
        $status    = $data['status'] ?? '';
        $error     = $data['error'] ?? null;

        LoggerService::fileLog('sms', "Webhook received: event=$event, message_id=$messageId, status=$status");

        if (empty($messageId)) {
            return false;
        }

        // Map SwalaSMS events to our status
        $dbStatus = match ($event) {
            'sms.delivered' => 'delivered',
            'sms.failed'    => 'failed',
            default         => null,
        };

        if ($dbStatus) {
            $stmt = $this->pdo->prepare("
                UPDATE `sms_messages`
                SET `status` = :status, `error_message` = :error, `updated_at` = NOW()
                WHERE `message_id` = :mid
            ");
            $stmt->execute([
                'status' => $dbStatus,
                'error'  => $error,
                'mid'    => $messageId,
            ]);
        }

        return true;
    }

    /**
     * Normalize a Tanzanian phone number to E.164 format.
     */
    private function normalizePhone(string $phone): string {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        // Already starts with 255 (country code)
        if (str_starts_with($digits, '255') && strlen($digits) === 12) {
            return '+' . $digits;
        }

        // Starts with 0 (local format)
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '+255' . substr($digits, 1);
        }

        // If it already has +, return as-is
        if (str_starts_with($phone, '+')) {
            return $phone;
        }

        return '+' . $digits;
    }

    /**
     * Update an SMS record in the database.
     */
    private function updateSmsRecord(string $id, string $status, ?string $messageId = null, ?string $error = null): void {
        try {
            $stmt = $this->pdo->prepare("
                UPDATE `sms_messages`
                SET `status` = :status, `message_id` = :mid, `error_message` = :error, `updated_at` = NOW()
                WHERE `id` = :id
            ");
            $stmt->execute([
                'status' => $status,
                'mid'    => $messageId,
                'error'  => $error,
                'id'     => $id,
            ]);
        } catch (\Throwable $e) {
            LoggerService::fileLog('sms', "Failed to update SMS record $id: " . $e->getMessage());
        }
    }

    /**
     * Internal HTTP executor
     */
    private function request(string $method, string $url, array $payload = [], array $headers = []): array {
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
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($body === false) {
            return ['success' => false, 'error' => $err ?: "HTTP request failed ($code)", 'code' => $code];
        }

        return [
            'success' => ($code >= 200 && $code < 400),
            'code'    => $code,
            'body'    => $body,
        ];
    }
}
