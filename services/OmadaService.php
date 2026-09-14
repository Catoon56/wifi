<?php
/**
 * Air Link WiFi - TP-Link Omada SDN Controller External Portal Service
 * 
 * Hardware: TTCL Internet -> TP-Link EAP110 Outdoor -> Omada Software Controller
 * Manages operator login session, CSRF tokens, client authorization, and deauthorization.
 * Follows TP-Link Omada External Web Portal Open API specification (FAQ 3231 / FAQ 2907).
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/LoggerService.php';

class OmadaService {
    private string $controllerUrl;
    private string $site;
    private string $operatorUser;
    private string $operatorPass;
    private string $controllerId;
    private string $version;
    private bool $simulationMode;

    private ?string $token = null;
    private ?string $sessionCookie = null;

    public function __construct() {
        // Load dynamically from database settings, falling back to constants
        $this->controllerUrl = rtrim(Database::getSetting('omada_controller_url', OMADA_CONTROLLER_URL) ?? OMADA_CONTROLLER_URL, '/');
        $this->site          = Database::getSetting('omada_site', OMADA_SITE) ?? OMADA_SITE;
        $this->operatorUser  = Database::getSetting('omada_operator_user', OMADA_OPERATOR_USER) ?? OMADA_OPERATOR_USER;
        $this->operatorPass  = Database::getSetting('omada_operator_pass', OMADA_OPERATOR_PASS) ?? OMADA_OPERATOR_PASS;
        $this->controllerId  = trim(Database::getSetting('omada_controller_id', OMADA_CONTROLLER_ID) ?? OMADA_CONTROLLER_ID);
        $this->version       = strtolower(trim(Database::getSetting('omada_version', OMADA_VERSION) ?? OMADA_VERSION));
        
        $sim = Database::getSetting('omada_simulation_mode', OMADA_SIMULATION_MODE ? '1' : '0');
        $this->simulationMode = ($sim === '1' || $sim === 'true');
    }

    /**
     * Authorize client device on Omada Controller for internet access
     * 
     * @param string $clientMac Client MAC address (e.g. AA-BB-CC-DD-EE-FF)
     * @param string|null $apMac Access Point MAC address (EAP110 Outdoor)
     * @param string|null $ssidName SSID Name
     * @param int $radioId Radio band ID (0 = 2.4GHz, 1 = 5GHz)
     * @param int $durationMinutes Authorized duration in minutes
     * @return array ['success' => bool, 'errorCode' => int, 'message' => string, 'details' => array]
     */
    public function authorizeClient(
        string $clientMac,
        ?string $apMac = null,
        ?string $ssidName = null,
        int $radioId = 0,
        int $durationMinutes = 60
    ): array {
        $cleanClientMac = $this->formatMac($clientMac);
        $cleanApMac     = $apMac ? $this->formatMac($apMac) : '';
        $ssid           = $ssidName ?: (Database::getSetting('wifi_ssid', 'Air Link WiFi') ?? 'Air Link WiFi');

        // Check if running in offline simulation mode
        if ($this->simulationMode) {
            $msg = "SIMULATION MODE: Client $cleanClientMac successfully authorized for $durationMinutes mins on SSID '$ssid'.";
            LoggerService::fileLog('omada', $msg);
            LoggerService::log('system', $cleanClientMac, 'OMADA_AUTH_SIMULATED', null, 'success', $msg);
            return [
                'success'   => true,
                'errorCode' => 0,
                'message'   => 'Client successfully authorized (Simulation Mode).',
                'details'   => ['simulated' => true, 'durationMinutes' => $durationMinutes],
            ];
        }

        // 1. Authenticate with Omada Controller to obtain token & session cookie
        $loginResult = $this->login();
        if (!$loginResult['success']) {
            LoggerService::log('system', $cleanClientMac, 'OMADA_AUTH_FAILED', null, 'failure', 'Controller login failed: ' . $loginResult['message']);
            return [
                'success'   => false,
                'errorCode' => $loginResult['errorCode'] ?? -1,
                'message'   => 'Controller communication error: ' . $loginResult['message'],
            ];
        }

        // 2. Calculate time parameter according to Controller version specification
        // v5.0.15 - v6.2.0 uses microseconds (FAQ 3231)
        // v6.2.10+ uses milliseconds
        // legacy uses minutes
        $timeParam = match ($this->version) {
            'v6'    => $durationMinutes * 60 * 1000,           // milliseconds
            'v5'    => $durationMinutes * 60 * 1000000,        // microseconds
            default => $durationMinutes * 60 * 1000000,        // default to v5 microseconds
        };

        // 3. Build authorization endpoint URL
        // Format: https://{controller}:{port}/{controller_id}/api/v2/hotspot/extPortal/auth
        $endpoint = $this->controllerUrl;
        if (!empty($this->controllerId)) {
            $endpoint .= '/' . rawurlencode($this->controllerId);
        }
        $endpoint .= '/api/v2/hotspot/extPortal/auth';
        if (!empty($this->token)) {
            $endpoint .= '?token=' . urlencode($this->token);
        }

        $payload = [
            'clientMac' => $cleanClientMac,
            'apMac'     => $cleanApMac,
            'ssidName'  => $ssid,
            'radioId'   => $radioId,
            'authType'  => 4,          // 4 = External Web Portal
            'time'      => $timeParam,
        ];

        $headers = [
            'Content-Type: application/json',
        ];
        if (!empty($this->token)) {
            $headers[] = 'Csrf-Token: ' . $this->token;
        }

        LoggerService::fileLog('omada', "POST $endpoint with payload: " . json_encode($payload));

        $response = $this->executeCurl($endpoint, 'POST', $payload, $headers, true);

        if (!$response['success']) {
            $errMsg = "HTTP request failed to Omada Controller: " . ($response['error'] ?? 'Unknown network error');
            LoggerService::fileLog('omada', $errMsg);
            LoggerService::log('system', $cleanClientMac, 'OMADA_AUTH_FAILED', null, 'failure', $errMsg);
            return [
                'success'   => false,
                'errorCode' => -1,
                'message'   => 'Unable to reach the network controller. Please contact support.',
                'details'   => $response,
            ];
        }

        $json = json_decode($response['body'], true);
        $errorCode = $json['errorCode'] ?? -1;

        if ($errorCode === 0) {
            LoggerService::fileLog('omada', "Client $cleanClientMac authorized successfully. Omada response: " . $response['body']);
            LoggerService::log('system', $cleanClientMac, 'OMADA_AUTH_SUCCESS', null, 'success', "Authorized for $durationMinutes mins.");
            return [
                'success'   => true,
                'errorCode' => 0,
                'message'   => 'Client successfully authorized.',
                'details'   => $json,
            ];
        }

        $msg = $json['msg'] ?? "Omada error code: $errorCode";
        LoggerService::fileLog('omada', "Authorization error for $cleanClientMac: $msg");
        LoggerService::log('system', $cleanClientMac, 'OMADA_AUTH_ERROR', null, 'failure', "Omada rejected authorization: $msg");

        return [
            'success'   => false,
            'errorCode' => $errorCode,
            'message'   => "Controller authorization rejected: $msg",
            'details'   => $json,
        ];
    }

    /**
     * Deauthorize / Disconnect a client from the Omada Controller (Kick client)
     */
    public function deauthorizeClient(string $clientMac, ?string $site = null): array {
        $cleanClientMac = $this->formatMac($clientMac);
        $siteId = $site ?: $this->site;

        if ($this->simulationMode) {
            LoggerService::fileLog('omada', "SIMULATION: Deauthorized client $cleanClientMac");
            return ['success' => true, 'message' => 'Simulated deauthorization successful.'];
        }

        $login = $this->login();
        if (!$login['success']) {
            return $login;
        }

        // Endpoint: /api/v2/sites/{site}/cmd/clients/{clientMac}/reconnect or unauth
        $endpoint = $this->controllerUrl;
        if (!empty($this->controllerId)) {
            $endpoint .= '/' . rawurlencode($this->controllerId);
        }
        $endpoint .= '/api/v2/sites/' . rawurlencode($siteId) . '/cmd/clients/' . rawurlencode($cleanClientMac) . '/reconnect';
        if (!empty($this->token)) {
            $endpoint .= '?token=' . urlencode($this->token);
        }

        $headers = ['Content-Type: application/json'];
        if (!empty($this->token)) {
            $headers[] = 'Csrf-Token: ' . $this->token;
        }

        $response = $this->executeCurl($endpoint, 'POST', [], $headers, true);
        LoggerService::fileLog('omada', "Deauth response for $cleanClientMac: " . ($response['body'] ?? ''));

        return [
            'success' => $response['success'],
            'message' => $response['success'] ? 'Client deauthorized.' : ($response['error'] ?? 'Deauth failed'),
        ];
    }

    /**
     * Authenticate Hotspot Operator with Omada Controller
     */
    public function login(): array {
        if ($this->simulationMode) {
            return ['success' => true, 'errorCode' => 0, 'message' => 'Simulated login OK'];
        }

        $loginEndpoint = $this->controllerUrl;
        if (!empty($this->controllerId)) {
            $loginEndpoint .= '/' . rawurlencode($this->controllerId);
        }
        $loginEndpoint .= '/api/v2/login';

        $payload = [
            'name'     => $this->operatorUser,
            'password' => $this->operatorPass,
        ];

        $headers = ['Content-Type: application/json'];

        $res = $this->executeCurl($loginEndpoint, 'POST', $payload, $headers, false);
        if (!$res['success']) {
            return [
                'success'   => false,
                'errorCode' => -1,
                'message'   => 'Could not reach Omada Controller: ' . ($res['error'] ?? 'Connection timed out'),
            ];
        }

        $json = json_decode($res['body'], true);
        $errorCode = $json['errorCode'] ?? -1;

        if ($errorCode === 0) {
            $this->token = $json['result']['token'] ?? null;
            // Parse session cookie from response headers
            if (!empty($res['cookies'])) {
                $this->sessionCookie = $res['cookies'];
            }

            LoggerService::fileLog('omada', "Login to Omada Controller successful. Token acquired.");
            return [
                'success'   => true,
                'errorCode' => 0,
                'message'   => 'Login successful',
                'token'     => $this->token,
            ];
        }

        $errMsg = $json['msg'] ?? "Omada login failed with code $errorCode";
        LoggerService::fileLog('omada', "Login to Omada Controller failed: $errMsg");
        return [
            'success'   => false,
            'errorCode' => $errorCode,
            'message'   => $errMsg,
        ];
    }

    /**
     * Diagnostic connectivity test from admin panel
     */
    public function testConnection(): array {
        if ($this->simulationMode) {
            return [
                'success' => true,
                'message' => 'Omada Service is running in Simulation Mode (Offline Sandbox). Network calls are mocked.',
                'status'  => 'simulated',
            ];
        }

        $login = $this->login();
        if ($login['success']) {
            return [
                'success' => true,
                'message' => "Successfully connected and authenticated with TP-Link Omada Controller at {$this->controllerUrl}.",
                'token'   => substr((string)($login['token'] ?? ''), 0, 8) . '...',
                'status'  => 'connected',
            ];
        }

        return [
            'success' => false,
            'message' => 'Connection to Omada Controller failed: ' . $login['message'],
            'status'  => 'error',
        ];
    }

    /**
     * Internal cURL executor with SSL tolerance and cookie capture
     */
    private function executeCurl(
        string $url,
        string $method = 'GET',
        array $body = [],
        array $headers = [],
        bool $sendCookies = true
    ): array {
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 6);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);

        // Omada Controller often uses self-signed SSL certificates locally
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }

        if ($sendCookies && !empty($this->sessionCookie)) {
            curl_setopt($ch, CURLOPT_COOKIE, $this->sessionCookie);
        }

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $response = curl_exec($ch);
        $err = curl_error($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);

        curl_close($ch);

        if ($response === false) {
            return [
                'success' => false,
                'error'   => $err ?: "cURL failed (HTTP $httpCode)",
                'code'    => $httpCode,
            ];
        }

        $headerStr = substr($response, 0, $headerSize);
        $bodyStr   = substr($response, $headerSize);

        // Extract Set-Cookie headers
        $cookies = [];
        if (preg_match_all('/^Set-Cookie:\s*([^;]+)/mi', $headerStr, $matches)) {
            $cookies = implode('; ', $matches[1]);
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 400),
            'code'    => $httpCode,
            'headers' => $headerStr,
            'body'    => $bodyStr,
            'cookies' => $cookies,
        ];
    }

    /**
     * Standardize MAC address to uppercase format with hyphens (e.g. AA-BB-CC-DD-EE-FF)
     */
    private function formatMac(string $mac): string {
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
        if (strlen($clean) === 12) {
            return strtoupper(implode('-', str_split($clean, 2)));
        }
        return strtoupper(str_replace(':', '-', trim($mac)));
    }
}
