<?php
/**
 * Air Link WiFi - Test SonicPesa Connection
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json');

if (!isset($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

$apiKey = Database::getSetting('sonicpesa_api_key', defined('SONICPESA_API_KEY') ? SONICPESA_API_KEY : '');
$baseUrl = rtrim(
    Database::getSetting('sonicpesa_base_url', defined('SONICPESA_BASE_URL') ? SONICPESA_BASE_URL : 'https://api.sonicpesa.com/api/v1'),
    '/'
);

if (empty($apiKey)) {
    echo json_encode(['success' => false, 'message' => 'API Key is not configured in settings or .env.']);
    exit;
}

// Perform a test request to /payment/order_status with a probe ID to check authentication
$url = $baseUrl . '/payment/order_status';

$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(['order_id' => 'probe_test_0']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'X-API-KEY: ' . $apiKey,
    'Content-Type: application/json'
]);

$body = curl_exec($ch);
$code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
curl_close($ch);

if ($body === false) {
    echo json_encode(['success' => false, 'message' => 'cURL Error: ' . $err]);
    exit;
}

// Any response indicating auth succeeded or auth failed is enough to verify reachability.
// If the API returns 401 Unauthorized, the API key is wrong.
if ($code === 401 || $code === 403) {
    echo json_encode(['success' => false, 'message' => 'Authentication failed. Please check your API Key.']);
    exit;
}

// Usually looking up order 0 returns 404 or some error, but we reached the server successfully.
if ($code >= 500) {
    echo json_encode(['success' => false, 'message' => 'SonicPesa server returned a 500 error.']);
    exit;
}

echo json_encode(['success' => true, 'message' => 'Successfully connected to SonicPesa API.']);
