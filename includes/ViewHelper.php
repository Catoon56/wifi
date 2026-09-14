<?php
/**
 * Air Link WiFi - View & Presentation Helper
 * XSS mitigation, currency, date, MAC address, and status formatting.
 */

declare(strict_types=1);

class ViewHelper {
    /**
     * Escape output for safe HTML rendering (XSS mitigation)
     */
    public static function e(?string $value): string {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }

    /**
     * Format currency amount in Tanzanian Shillings (TZS / TSh)
     */
    public static function formatCurrency(float|int|string|null $amount): string {
        $num = (float)($amount ?? 0);
        return number_format($num, 0, '.', ',') . ' ' . APP_CURRENCY_SYMBOL;
    }

    /**
     * Format date and time for Africa/Dar_es_Salaam
     */
    public static function formatDateTime(?string $datetime, string $format = 'd M Y H:i'): string {
        if (empty($datetime)) {
            return '-';
        }
        $ts = strtotime($datetime);
        if ($ts === false || $ts <= 0) {
            return '-';
        }
        return date($format, $ts);
    }

    /**
     * Format duration into human readable string (e.g. "24 Hours", "1 Hour")
     */
    public static function formatDuration(int|string $duration, string $unit): string {
        $d = (int)$duration;
        $u = strtolower(trim($unit));

        return match ($u) {
            'minute', 'minutes' => $d === 1 ? "1 Minute" : "$d Minutes",
            'hour', 'hours'     => $d === 1 ? "1 Hour" : "$d Hours",
            'day', 'days'       => $d === 1 ? "1 Day" : "$d Days",
            default             => "$d " . ucfirst($u),
        };
    }

    /**
     * Format remaining seconds into "X hours Y mins"
     */
    public static function formatRemainingSeconds(int $seconds): string {
        if ($seconds <= 0) {
            return 'Expired';
        }

        $days = floor($seconds / 86400);
        $hours = floor(($seconds % 86400) / 3600);
        $minutes = floor(($seconds % 3600) / 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days === 1 ? "1 day" : "$days days";
        }
        if ($hours > 0) {
            $parts[] = $hours === 1 ? "1 hr" : "$hours hrs";
        }
        if ($minutes > 0 || empty($parts)) {
            $parts[] = $minutes === 1 ? "1 min" : "$minutes mins";
        }

        return implode(' ', $parts);
    }

    /**
     * Standardize and normalize MAC address (e.g. AA:BB:CC:DD:EE:FF or AA-BB-CC-DD-EE-FF)
     */
    public static function normalizeMac(?string $mac, string $delimiter = '-'): string {
        if (empty($mac)) {
            return '';
        }
        $clean = preg_replace('/[^0-9A-Fa-f]/', '', $mac);
        if (strlen($clean) !== 12) {
            return strtoupper(trim($mac));
        }
        return strtoupper(implode($delimiter, str_split($clean, 2)));
    }

    /**
     * Render clean, professional status pill (no emojis, no gradients)
     */
    public static function statusBadge(string $status): string {
        $statusLower = strtolower(trim($status));

        $classes = match ($statusLower) {
            'unused'       => 'badge-info',
            'active', 'completed' => 'badge-success',
            'expired'      => 'badge-neutral',
            'disabled', 'terminated', 'failed' => 'badge-danger',
            'disconnected', 'pending' => 'badge-warning',
            default        => 'badge-neutral',
        };

        return sprintf(
            '<span class="badge %s">%s</span>',
            self::e($classes),
            self::e(ucfirst($statusLower))
        );
    }
}
