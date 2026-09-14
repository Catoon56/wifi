<?php
/**
 * Air Link WiFi - Administrator Authentication & Session Security
 * Implements bcrypt verification, brute-force lockout, session regeneration, and idle timeout.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/LoggerService.php';

class Auth {
    private const SESSION_ADMIN_ID = 'airlink_admin_id';
    private const SESSION_ADMIN_USER = 'airlink_admin_user';
    private const SESSION_ADMIN_ROLE = 'airlink_admin_role';
    private const SESSION_ADMIN_NAME = 'airlink_admin_name';
    private const SESSION_LAST_ACTIVE = 'airlink_admin_last_active';

    /**
     * Authenticate admin credentials with rate-limiting & lockout
     */
    public static function login(string $username, string $password): array {
        $username = trim($username);
        $pdo = Database::getConnection();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        if (empty($username) || empty($password)) {
            return ['success' => false, 'error' => 'Please enter both username and password.'];
        }

        $stmt = $pdo->prepare("SELECT * FROM `admins` WHERE `username` = :u OR `email` = :e LIMIT 1");
        $stmt->execute(['u' => $username, 'e' => $username]);
        $admin = $stmt->fetch();

        if (!$admin) {
            LoggerService::log('admin', $username, 'ADMIN_LOGIN_FAIL', $ip, 'failure', 'User account does not exist.');
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // Check if account is inactive
        if ($admin['status'] !== 'active') {
            LoggerService::log('admin', $username, 'ADMIN_LOGIN_INACTIVE', $ip, 'failure', 'Account is disabled.');
            return ['success' => false, 'error' => 'This account has been disabled. Please contact the administrator.'];
        }

        // Check account lockout
        if (!empty($admin['lockout_until'])) {
            $lockoutTime = strtotime($admin['lockout_until']);
            $currentTime = time();
            if ($lockoutTime > $currentTime) {
                $remainingMin = ceil(($lockoutTime - $currentTime) / 60);
                return [
                    'success' => false,
                    'error'   => "Account temporarily locked due to repeated failed attempts. Please try again in $remainingMin minute(s)."
                ];
            }
        }

        // Verify password hash
        if (!password_verify($password, $admin['password_hash'])) {
            $newAttempts = (int)$admin['failed_login_attempts'] + 1;
            $maxAttempts = (int)Database::getSetting('max_voucher_attempts', '5');
            $lockoutMin = (int)Database::getSetting('lockout_duration_minutes', '15');

            if ($newAttempts >= $maxAttempts) {
                $lockoutUntil = date('Y-m-d H:i:s', strtotime("+$lockoutMin minutes"));
                $updateStmt = $pdo->prepare("UPDATE `admins` SET `failed_login_attempts` = :a, `lockout_until` = :l WHERE `id` = :id");
                $updateStmt->execute(['a' => $newAttempts, 'l' => $lockoutUntil, 'id' => $admin['id']]);

                LoggerService::log('admin', $username, 'ADMIN_LOCKOUT', $ip, 'failure', "Account locked until $lockoutUntil after $newAttempts failed attempts.");
                return ['success' => false, 'error' => "Account locked due to too many failed attempts. Try again in $lockoutMin minutes."];
            }

            $updateStmt = $pdo->prepare("UPDATE `admins` SET `failed_login_attempts` = :a WHERE `id` = :id");
            $updateStmt->execute(['a' => $newAttempts, 'id' => $admin['id']]);

            LoggerService::log('admin', $username, 'ADMIN_LOGIN_FAIL', $ip, 'failure', "Invalid password attempt $newAttempts.");
            return ['success' => false, 'error' => 'Invalid username or password.'];
        }

        // Reset failed login counter and update last login time
        $resetStmt = $pdo->prepare("
            UPDATE `admins` 
            SET `failed_login_attempts` = 0, `lockout_until` = NULL, `last_login` = NOW() 
            WHERE `id` = :id
        ");
        $resetStmt->execute(['id' => $admin['id']]);

        // Re-hash password if algorithm or cost improved
        if (password_needs_rehash($admin['password_hash'], PASSWORD_BCRYPT)) {
            $newHash = password_hash($password, PASSWORD_BCRYPT);
            $rehashStmt = $pdo->prepare("UPDATE `admins` SET `password_hash` = :h WHERE `id` = :id");
            $rehashStmt->execute(['h' => $newHash, 'id' => $admin['id']]);
        }

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION[self::SESSION_ADMIN_ID] = (int)$admin['id'];
        $_SESSION[self::SESSION_ADMIN_USER] = $admin['username'];
        $_SESSION[self::SESSION_ADMIN_ROLE] = $admin['role'];
        $_SESSION[self::SESSION_ADMIN_NAME] = $admin['full_name'];
        $_SESSION[self::SESSION_LAST_ACTIVE] = time();

        LoggerService::log('admin', $admin['username'], 'ADMIN_LOGIN_SUCCESS', $ip, 'success', 'Admin authenticated successfully.');

        return ['success' => true, 'admin' => $admin];
    }

    /**
     * Check if administrator is currently logged in with active session
     */
    public static function check(): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (empty($_SESSION[self::SESSION_ADMIN_ID])) {
            return false;
        }

        // Enforce session inactivity timeout
        $lastActive = $_SESSION[self::SESSION_LAST_ACTIVE] ?? 0;
        $timeout = (int)Database::getSetting('session_inactivity_timeout', (string)SESSION_LIFETIME);

        if ($lastActive > 0 && (time() - $lastActive > $timeout)) {
            self::logout('Session expired due to inactivity.');
            return false;
        }

        // Update last activity
        $_SESSION[self::SESSION_LAST_ACTIVE] = time();
        return true;
    }

    /**
     * Ensure user is logged in, or redirect to login page
     */
    public static function requireLogin(): void {
        if (!self::check()) {
            $currentUrl = $_SERVER['REQUEST_URI'] ?? '';
            $loginUrl = get_base_url() . '/admin/login.php';
            if (!empty($currentUrl) && !str_contains($currentUrl, 'login.php')) {
                $loginUrl .= '?redirect=' . urlencode($currentUrl);
            }
            header("Location: $loginUrl");
            exit;
        }
    }

    /**
     * Get authenticated admin user information
     */
    public static function user(): ?array {
        if (!self::check()) {
            return null;
        }

        return [
            'id'        => $_SESSION[self::SESSION_ADMIN_ID],
            'username'  => $_SESSION[self::SESSION_ADMIN_USER],
            'role'      => $_SESSION[self::SESSION_ADMIN_ROLE],
            'full_name' => $_SESSION[self::SESSION_ADMIN_NAME],
        ];
    }

    /**
     * Get the authenticated admin username
     */
    public static function username(): ?string {
        if (!self::check()) {
            return null;
        }
        return $_SESSION[self::SESSION_ADMIN_USER] ?? null;
    }

    /**
     * Determine if current admin is super admin.
     */
    public static function isSuperAdmin(): bool {
        $user = self::user();
        if ($user === null) {
            return false;
        }
        if (($user['role'] ?? '') === 'admin') {
            return true;
        }
        $username = self::username();
        return $username !== null && defined('SUPER_ADMIN_USERNAME') && $username === SUPER_ADMIN_USERNAME;
    }

    /**
     * Destroy admin session securely
     */
    public static function logout(?string $reason = null): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $user = $_SESSION[self::SESSION_ADMIN_USER] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        LoggerService::log('admin', $user, 'ADMIN_LOGOUT', $ip, 'success', $reason ?? 'User logged out.');

        $_SESSION = [];

        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params["path"],
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }

        session_destroy();
    }
}
