<?php
/**
 * Air Link WiFi - IP & MAC Rate Limiter
 * Protects against voucher guessing, brute-forcing, and endpoint flooding.
 */

declare(strict_types=1);

class RateLimiter {
    private const SESSION_PREFIX = 'airlink_rate_';

    /**
     * Determine if too many attempts have been made for the given key
     */
    public static function tooManyAttempts(string $key, int $maxAttempts = 5, int $decaySeconds = 300): bool {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $bucketKey = self::SESSION_PREFIX . hash('sha256', $key);
        $data = $_SESSION[$bucketKey] ?? null;

        if (!$data || !is_array($data)) {
            return false;
        }

        $now = time();
        if ($now > $data['reset_at']) {
            unset($_SESSION[$bucketKey]);
            return false;
        }

        return $data['attempts'] >= $maxAttempts;
    }

    /**
     * Record a failed hit for the key
     */
    public static function hit(string $key, int $decaySeconds = 300): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $bucketKey = self::SESSION_PREFIX . hash('sha256', $key);
        $now = time();

        if (!isset($_SESSION[$bucketKey]) || $now > $_SESSION[$bucketKey]['reset_at']) {
            $_SESSION[$bucketKey] = [
                'attempts' => 1,
                'reset_at' => $now + $decaySeconds,
            ];
            return 1;
        }

        $_SESSION[$bucketKey]['attempts']++;
        return $_SESSION[$bucketKey]['attempts'];
    }

    /**
     * Get remaining cooldown seconds
     */
    public static function availableIn(string $key): int {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $bucketKey = self::SESSION_PREFIX . hash('sha256', $key);
        $data = $_SESSION[$bucketKey] ?? null;

        if (!$data || !is_array($data)) {
            return 0;
        }

        $remaining = $data['reset_at'] - time();
        return max(0, $remaining);
    }

    /**
     * Clear the rate limit counter for the key (e.g. after successful validation)
     */
    public static function clear(string $key): void {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $bucketKey = self::SESSION_PREFIX . hash('sha256', $key);
        unset($_SESSION[$bucketKey]);
    }
}
