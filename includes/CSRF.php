<?php
/**
 * Air Link WiFi - CSRF Protection Helper
 * Generates and validates cryptographic anti-CSRF tokens for all state-changing requests.
 */

declare(strict_types=1);

class CSRF {
    private const SESSION_KEY = 'airlink_csrf_token';

    /**
     * Get or generate the active CSRF token for the session
     */
    public static function getToken(): string {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_KEY])) {
            $_SESSION[self::SESSION_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::SESSION_KEY];
    }

    /**
     * Render HTML hidden input field with the CSRF token
     */
    public static function field(): string {
        $token = htmlspecialchars(self::getToken(), ENT_QUOTES, 'UTF-8');
        return '<input type="hidden" name="csrf_token" value="' . $token . '">';
    }

    /**
     * Verify the CSRF token from POST body or X-CSRF-Token header
     */
    public static function validate(?string $token = null): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $sessionToken = $_SESSION[self::SESSION_KEY] ?? '';
        if (empty($sessionToken)) {
            return false;
        }

        if ($token === null) {
            $token = $_POST['csrf_token']
                ?? $_SERVER['HTTP_X_CSRF_TOKEN']
                ?? $_SERVER['HTTP_X_XSRF_TOKEN']
                ?? '';
        }

        return hash_equals($sessionToken, (string)$token);
    }

    /**
     * Enforce CSRF check or terminate with HTTP 403
     */
    public static function enforce(): void {
        if (!self::validate()) {
            http_response_code(403);
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'error' => 'Security token mismatch. Please refresh and try again.']);
            } else {
                echo '<!DOCTYPE html><html><body style="font-family:sans-serif;padding:40px;text-align:center;">';
                echo '<h2 style="color:#b91c1c;">403 Forbidden</h2>';
                echo '<p>Security token validation failed. Please refresh the page and try again.</p>';
                echo '<a href="javascript:history.back()" style="display:inline-block;margin-top:16px;padding:8px 16px;background:#0b5ed7;color:#fff;text-decoration:none;border-radius:4px;">Go Back</a>';
                echo '</body></html>';
            }
            exit;
        }
    }
}
