<?php
/**
 * Air Link WiFi - Administrator Logout
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/Auth.php';

Auth::logout('Admin signed out.');

header("Location: login.php");
exit;
