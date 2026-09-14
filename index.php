<?php
/**
 * Air Link WiFi - Root Entrypoint Router
 * Forwards captive portal requests and Omada query parameters to the public portal.
 */

declare(strict_types=1);

$queryString = $_SERVER['QUERY_STRING'] ?? '';
$target = 'airlink/public/index.php' . ($queryString ? '?' . $queryString : '');

// If accessed directly or redirected by Omada, route to public portal
if (file_exists(__DIR__ . '/airlink/public/index.php')) {
    header("Location: $target");
    exit;
}

die("Air Link WiFi syststem is Down");
