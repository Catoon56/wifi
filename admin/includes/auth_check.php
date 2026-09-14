<?php
/**
 * Air Link WiFi - Admin Session Enforcement & CSRF Guard
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/Auth.php';
require_once __DIR__ . '/../../includes/CSRF.php';
require_once __DIR__ . '/../../includes/ViewHelper.php';

Auth::requireLogin();
$currentUser = Auth::user();
