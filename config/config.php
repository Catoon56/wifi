<?php
/**
 * Air Link WiFi - Application Configuration
 * Brand: Air Link | Project: Air Link WiFi
 * Hardware: TTCL Internet -> TP-Link EAP110 Outdoor -> Omada External Web Portal
 */

declare(strict_types=1);

// Set default timezone strictly to Africa/Dar_es_Salaam (Tanzania, UTC+3)
date_default_timezone_set('Africa/Dar_es_Salaam');

// Helper to load .env if available
$envFile = dirname(__DIR__, 2) . '/.env';
if (!file_exists($envFile)) {
    $envFile = __DIR__ . '/../../.env';
}
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        $parts = explode('=', $line, 2);
        if (count($parts) === 2) {
            $key = trim($parts[0]);
            $val = trim($parts[1]);
            // Strip quotes if wrapped
            $val = trim($val, "\"'");
            if (!array_key_exists($key, $_SERVER) && !array_key_exists($key, $_ENV)) {
                putenv("$key=$val");
                $_ENV[$key] = $val;
                $_SERVER[$key] = $val;
            }
        }
    }
}

// Environment helper
function env(string $key, mixed $default = null): mixed {
    $val = getenv($key);
    if ($val === false) {
        return $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }
    return match (strtolower($val)) {
        'true', '(true)' => true,
        'false', '(false)' => false,
        'empty', '(empty)' => '',
        'null', '(null)' => null,
        default => $val,
    };
}

// Application Constants
define('APP_NAME', 'Air Link WiFi');
define('APP_BRAND', 'Air Link');
define('APP_TAGLINE', 'Reliable Internet. Simple Access.');
define('APP_ENV', env('APP_ENV', 'production'));
define('APP_DEBUG', env('APP_DEBUG', false));
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Africa/Dar_es_Salaam'));
define('APP_CURRENCY', env('APP_CURRENCY', 'TZS'));
define('APP_CURRENCY_SYMBOL', env('APP_CURRENCY_SYMBOL', 'TSh'));

// Error handling
if (APP_DEBUG) {
    ini_set('display_errors', '1');
    ini_set('display_startup_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
}

// Database Constants
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_PORT', env('DB_PORT', '3306'));
define('DB_NAME', env('DB_NAME', 'airlink_wifi'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASSWORD', env('DB_PASSWORD', ''));

// Paths
define('DIR_ROOT', dirname(__DIR__));
define('DIR_CONFIG', __DIR__);
define('DIR_SERVICES', DIR_ROOT . '/services');
define('DIR_INCLUDES', DIR_ROOT . '/includes');
define('DIR_LOGS', DIR_ROOT . '/logs');
define('DIR_PUBLIC', DIR_ROOT . '/public');
define('DIR_ADMIN', DIR_ROOT . '/admin');

// Omada Controller Constants
define('OMADA_CONTROLLER_URL', rtrim((string)env('OMADA_CONTROLLER_URL', 'https://192.168.0.100:8043'), '/'));
define('OMADA_SITE', env('OMADA_SITE', 'default'));
define('OMADA_OPERATOR_USER', env('OMADA_OPERATOR_USER', 'airlink_operator'));
define('OMADA_OPERATOR_PASS', env('OMADA_OPERATOR_PASS', 'Operator@2026'));
define('OMADA_CONTROLLER_ID', env('OMADA_CONTROLLER_ID', ''));
define('OMADA_VERSION', env('OMADA_VERSION', 'v5'));
define('OMADA_SIMULATION_MODE', (bool)env('OMADA_SIMULATION_MODE', true));

// SonicPesa Constants
define('SONICPESA_ENABLED', env('SONICPESA_ENABLED', true));
define('SONICPESA_API_KEY', env('SONICPESA_API_KEY', 'YOUR_API_KEY'));
define('SONICPESA_BASE_URL', env('SONICPESA_BASE_URL', 'https://api.sonicpesa.com/api/v1'));
define('SONICPESA_WEBHOOK_SIGNATURE_HEADER', env('SONICPESA_WEBHOOK_SIGNATURE_HEADER', 'X-Signature'));

// Security & Session Settings
define('SESSION_LIFETIME', (int)env('SESSION_LIFETIME', 1800));
define('MAX_VOUCHER_ATTEMPTS', (int)env('MAX_VOUCHER_ATTEMPTS', 5));
define('LOCKOUT_MINUTES', (int)env('LOCKOUT_MINUTES', 15));
define('SUPER_ADMIN_USERNAME', (string)env('SUPER_ADMIN_USERNAME', 'admin'));

// SwalaSMS Constants – fill in via .env or admin UI
define('SWALASMS_API_KEY', env('SWALASMS_API_KEY', 'YOUR_API_KEY'));
define('SWALASMS_SENDER_ID', env('SWALASMS_SENDER_ID', 'YOUR_SENDER_ID'));
define('SWALASMS_BASE_URL', env('SWALASMS_BASE_URL', 'https://swalasms.com/api/v1'));


// Secure Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        ini_set('session.cookie_secure', '1');
    }
    session_start();
}

/**
 * Autoloader for application includes and services
 */
spl_autoload_register(function (string $class) {
    $serviceFile = DIR_SERVICES . '/' . $class . '.php';
    if (file_exists($serviceFile)) {
        require_once $serviceFile;
        return;
    }
    $includeFile = DIR_INCLUDES . '/' . $class . '.php';
    if (file_exists($includeFile)) {
        require_once $includeFile;
        return;
    }
});

/**
 * Helper to get the base web URL of the application
 */
function get_base_url(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '');
    
    // Normalize path to root of airlink
    $normalized = str_replace('\\', '/', $scriptDir);
    $pos = strpos($normalized, '/airlink');
    if ($pos !== false) {
        $path = substr($normalized, 0, $pos + 8);
    } else {
        $path = $normalized;
    }
    return rtrim("$scheme://$host$path", '/');
}
