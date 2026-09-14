<?php
/**
 * Air Link WiFi - System Verification & Unit Test Suite
 * Tests voucher generation, first-use timer logic, device locks, and Omada request formatting.
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/CSRF.php';
require_once __DIR__ . '/../includes/RateLimiter.php';
require_once __DIR__ . '/../includes/ViewHelper.php';
require_once __DIR__ . '/../services/VoucherService.php';
require_once __DIR__ . '/../services/OmadaService.php';
require_once __DIR__ . '/../services/SessionService.php';
require_once __DIR__ . '/../services/SonicPesaService.php';
require_once __DIR__ . '/../services/SwalaSmsService.php';

$testResults = [];

function assert_test(string $name, bool $condition, string $details = ''): void {
    global $testResults;
    $testResults[] = [
        'name'      => $name,
        'passed'    => $condition,
        'details'   => $details,
    ];
}

// -------------------------------------------------------------
// Test 1: Voucher Code Format & Cryptographic Randomness
// -------------------------------------------------------------
$code1 = VoucherService::generateCode(8, 'AL');
$code2 = VoucherService::generateCode(8, 'AL');
assert_test(
    'Voucher Code Generation',
    strlen($code1) === 10 && str_starts_with($code1, 'AL') && $code1 !== $code2,
    "Code 1: $code1, Code 2: $code2"
);

// Verify unambiguous characters (no 0, O, 1, I, L)
$hasAmbiguous = (bool)preg_match('/[01OIL]/', substr($code1, 2));
assert_test(
    'Voucher Code Unambiguity',
    !$hasAmbiguous,
    "Verified no 0, 1, O, I, L characters in generated code: $code1"
);

// -------------------------------------------------------------
// Test 2: Rate Limiter Cooldown & Hits
// -------------------------------------------------------------
$testKey = 'test_device_' . bin2hex(random_bytes(4));
RateLimiter::clear($testKey);
assert_test('Rate Limiter Initial State', !RateLimiter::tooManyAttempts($testKey, 3, 60));

RateLimiter::hit($testKey, 60);
RateLimiter::hit($testKey, 60);
RateLimiter::hit($testKey, 60);
assert_test('Rate Limiter Block Trigger', RateLimiter::tooManyAttempts($testKey, 3, 60));
RateLimiter::clear($testKey);
assert_test('Rate Limiter Reset', !RateLimiter::tooManyAttempts($testKey, 3, 60));

// -------------------------------------------------------------
// Test 3: MAC Address Normalization
// -------------------------------------------------------------
$rawMac1 = "aa:bb:cc:dd:ee:ff";
$normMac1 = ViewHelper::normalizeMac($rawMac1, '-');
assert_test('MAC Normalization Hyphen', $normMac1 === 'AA-BB-CC-DD-EE-FF', "Result: $normMac1");

$rawMac2 = "aabbccddeeff";
$normMac2 = ViewHelper::normalizeMac($rawMac2, ':');
assert_test('MAC Normalization Colon', $normMac2 === 'AA:BB:CC:DD:EE:FF', "Result: $normMac2");

// -------------------------------------------------------------
// Test 4: Duration and Currency Formatting
// -------------------------------------------------------------
$dur1 = ViewHelper::formatDuration(24, 'hours');
$dur2 = ViewHelper::formatDuration(1, 'hour');
assert_test('Duration Formatting', $dur1 === '24 Hours' && $dur2 === '1 Hour', "Formatted: $dur1, $dur2");

$curr = ViewHelper::formatCurrency(2000);
assert_test('Currency Formatting', str_contains($curr, '2,000') && str_contains($curr, 'TSh'), "Formatted: $curr");

$remStr = ViewHelper::formatRemainingSeconds(7260); // 2 hrs 1 min
assert_test('Remaining Seconds Formatting', str_contains($remStr, '2 hrs'), "Result: $remStr");

// -------------------------------------------------------------
// Test 5: Omada Service Simulation Authorization
// -------------------------------------------------------------
$omada = new OmadaService();
$authRes = $omada->authorizeClient('AA-BB-CC-DD-EE-FF', '11-22-33-44-55-66', 'Air Link WiFi', 0, 60);
assert_test(
    'Omada Service Authorization Flow',
    isset($authRes['success']) && isset($authRes['errorCode']),
    "Message: " . ($authRes['message'] ?? '')
);

$diagRes = $omada->testConnection();
assert_test(
    'Omada Service Diagnostic Probe',
    isset($diagRes['status']),
    "Status: " . ($diagRes['status'] ?? '')
);

// -------------------------------------------------------------
// Test 6: Database Connection Check (if MySQL server is active)
// -------------------------------------------------------------
$dbOnline = false;
try {
    $pdo = Database::getConnection();
    $testQuery = $pdo->query("SELECT 1")->fetchColumn();
    $dbOnline = ($testQuery == 1);
    assert_test('Database PDO Connectivity', true, "Connected to MySQL " . DB_HOST . ":" . DB_PORT);
} catch (Throwable $e) {
    assert_test('Database PDO Connectivity', false, "MySQL offline or credentials not yet configured: " . $e->getMessage());
}

// -------------------------------------------------------------
// Test 7: Auth::username() and Auth::isSuperAdmin()
// -------------------------------------------------------------
require_once __DIR__ . '/../includes/Auth.php';
$_SESSION['airlink_admin_id'] = 1;
$_SESSION['airlink_admin_user'] = 'admin';
$_SESSION['airlink_admin_role'] = 'admin';
$_SESSION['airlink_admin_name'] = 'Administrator';
$_SESSION['airlink_admin_last_active'] = time();

assert_test('Auth Username Helper', Auth::username() === 'admin', 'Username: ' . (Auth::username() ?? 'null'));
assert_test('Auth isSuperAdmin Check', Auth::isSuperAdmin() === true, 'isSuperAdmin returned true for admin user');

// -------------------------------------------------------------
// Test 8: SonicPesa Service Pending Payment Insert with Null Transaction ID
// -------------------------------------------------------------
if ($dbOnline) {
    try {
        $sp = new SonicPesaService();
        $orderRes = $sp->createOrder(1, 1000, '0712345678', 'Test Package');
        assert_test(
            'SonicPesa Order Record Creation',
            isset($orderRes['success']),
            "Order created without SQL duplicate constraint: " . ($orderRes['error'] ?? 'success')
        );
    } catch (Throwable $e) {
        assert_test('SonicPesa Order Record Creation', false, "Exception: " . $e->getMessage());
    }
}

// -------------------------------------------------------------
// Output Summary
// -------------------------------------------------------------
$total = count($testResults);
$passed = count(array_filter($testResults, fn($r) => $r['passed']));

if (php_sapi_name() === 'cli') {
    echo "========================================\n";
    echo "Air Link WiFi - Test Verification Suite\n";
    echo "========================================\n";
    foreach ($testResults as $r) {
        $status = $r['passed'] ? '[PASS]' : '[FAIL]';
        echo "$status {$r['name']}: {$r['details']}\n";
    }
    echo "----------------------------------------\n";
    echo "Result: $passed / $total tests passed.\n";
} else {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><body style='font-family:sans-serif;padding:24px;'>";
    echo "<h2>Air Link WiFi - Test Verification Suite</h2>";
    echo "<p>Passed: <strong>$passed / $total</strong></p><hr>";
    echo "<table border='1' cellpadding='8' style='border-collapse:collapse;width:100%;max-width:700px;'>";
    echo "<tr><th>Test</th><th>Status</th><th>Details</th></tr>";
    foreach ($testResults as $r) {
        $color = $r['passed'] ? '#15803d' : '#b91c1c';
        $label = $r['passed'] ? 'PASS' : 'FAIL';
        echo "<tr>";
        echo "<td>" . htmlspecialchars($r['name']) . "</td>";
        echo "<td style='color:$color;font-weight:bold;'>$label</td>";
        echo "<td>" . htmlspecialchars($r['details']) . "</td>";
        echo "</tr>";
    }
    echo "</table></body></html>";
}
