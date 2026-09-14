<?php
/**
 * Air Link WiFi - System Settings & Integrations
 * Configures TP-Link Omada Controller, SonicPesa, SwalaSMS, Business Branding, and Session Rules.
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../services/SwalaSmsService.php';
require_once __DIR__ . '/../services/SonicPesaService.php';
$pageTitle = "System Settings";
$pdo = Database::getConnection();

$message = null;
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    CSRF::enforce();

    $settingsToUpdate = [
        // Branding & Contact
        'business_name'           => trim($_POST['business_name'] ?? 'Air Link WiFi'),
        'brand_name'              => trim($_POST['brand_name'] ?? 'Air Link'),
        'tagline'                 => trim($_POST['tagline'] ?? 'Reliable Internet. Simple Access.'),
        'support_phone'           => trim($_POST['support_phone'] ?? ''),
        'support_whatsapp'        => trim($_POST['support_whatsapp'] ?? ''),
        'wifi_ssid'               => trim($_POST['wifi_ssid'] ?? 'Air Link WiFi'),
        'currency'                => trim($_POST['currency'] ?? 'TZS'),
        'currency_symbol'         => trim($_POST['currency_symbol'] ?? 'TSh'),
        'timezone'                => trim($_POST['timezone'] ?? 'Africa/Dar_es_Salaam'),

        // Omada Controller
        'omada_controller_url'    => rtrim(trim($_POST['omada_controller_url'] ?? ''), '/'),
        'omada_site'              => trim($_POST['omada_site'] ?? 'default'),
        'omada_operator_user'     => trim($_POST['omada_operator_user'] ?? ''),
        'omada_operator_pass'     => trim($_POST['omada_operator_pass'] ?? ''),
        'omada_controller_id'     => trim($_POST['omada_controller_id'] ?? ''),
        'omada_version'           => strtolower(trim($_POST['omada_version'] ?? 'v5')),
        'omada_simulation_mode'   => isset($_POST['omada_simulation_mode']) ? '1' : '0',

        // SonicPesa Payment Gateway
        'sonicpesa_enabled'       => isset($_POST['sonicpesa_enabled']) ? '1' : '0',
        'sonicpesa_api_key'       => trim($_POST['sonicpesa_api_key'] ?? ''),
        'sonicpesa_base_url'      => rtrim(trim($_POST['sonicpesa_base_url'] ?? 'https://api.sonicpesa.com/api/v1'), '/'),

        // Security & Session
        'session_inactivity_timeout' => (string)max(300, (int)($_POST['session_inactivity_timeout'] ?? 1800)),
                    'max_voucher_attempts'       => (string)max(3, (int)($_POST['max_voucher_attempts'] ?? 5)),
            'lockout_duration_minutes'   => (string)max(5, (int)($_POST['lockout_duration_minutes'] ?? 15)),
            // SwalaSMS Integration
            'swalasms_api_key'           => trim($_POST['swalasms_api_key'] ?? ''),
            'swalasms_sender_id'         => trim($_POST['swalasms_sender_id'] ?? ''),
            'swalasms_base_url'          => rtrim(trim($_POST['swalasms_base_url'] ?? ''), '/'),
    ];

    foreach ($settingsToUpdate as $key => $val) {
        Database::setSetting($key, $val);
    }

    $message = "System settings updated successfully.";
}

$allSettings = Database::getAllSettings();

include __DIR__ . '/includes/header.php';
?>

<?php if ($message): ?>
    <div class="alert alert-success"><?= ViewHelper::e($message) ?></div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= ViewHelper::e($error) ?></div>
<?php endif; ?>

<!-- Omada Connectivity Diagnostic Ribbon -->
<div class="card" style="margin-bottom: 24px;">
    <div class="card-header">
        <h3 class="card-title">TP-Link Omada Controller Connectivity</h3>
        <button type="button" id="testOmadaBtn" class="btn btn-primary btn-sm">
            Test Omada Connection
        </button>
    </div>
    <div class="card-body">
        <p style="font-size: 13.5px; color: #475569; margin-bottom: 12px;">
            Verify that this server can communicate with the TP-Link Omada Software Controller API to authorize and deauthorize clients.
        </p>
        <div id="testOmadaResult" style="display: none; padding: 12px; border-radius: 4px; font-size: 13.5px;"></div>
    </div>
</div>

<form method="POST" action="settings.php">
    <?= CSRF::field() ?>

    <!-- 1. Omada External Web Portal Settings -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3 class="card-title">TP-Link Omada Controller Integration (FAQ 3231)</h3>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="omada_controller_url" class="form-label">Controller URL with HTTPS Port</label>
                    <input 
                        type="text" 
                        name="omada_controller_url" 
                        id="omada_controller_url" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['omada_controller_url'] ?? 'https://192.168.0.100:8043') ?>" 
                        placeholder="https://192.168.0.100:8043" 
                        required
                    >
                    <div class="form-help">IP or domain of your Omada Software Controller (usually port 8043).</div>
                </div>

                <div class="form-group">
                    <label for="omada_site" class="form-label">Omada Site ID</label>
                    <input 
                        type="text" 
                        name="omada_site" 
                        id="omada_site" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['omada_site'] ?? 'default') ?>" 
                        required
                    >
                    <div class="form-help">Default is "default" unless named otherwise in Controller.</div>
                </div>

                <div class="form-group">
                    <label for="omada_operator_user" class="form-label">Hotspot Operator Username</label>
                    <input 
                        type="text" 
                        name="omada_operator_user" 
                        id="omada_operator_user" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['omada_operator_user'] ?? 'airlink_operator') ?>" 
                        required
                    >
                    <div class="form-help">Create in Omada Settings &rarr; Hotspot &rarr; Operators.</div>
                </div>

                <div class="form-group">
                    <label for="omada_operator_pass" class="form-label">Hotspot Operator Password</label>
                    <input 
                        type="password" 
                        name="omada_operator_pass" 
                        id="omada_operator_pass" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['omada_operator_pass'] ?? '') ?>"
                    >
                </div>

                <div class="form-group">
                    <label for="omada_controller_id" class="form-label">Controller ID (Optional for v5/v6)</label>
                    <input 
                        type="text" 
                        name="omada_controller_id" 
                        id="omada_controller_id" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['omada_controller_id'] ?? '') ?>" 
                        placeholder="Leave blank if not required"
                    >
                </div>

                <div class="form-group">
                    <label for="omada_version" class="form-label">Controller Architecture Version</label>
                    <select name="omada_version" id="omada_version" class="form-control">
                        <option value="v5" <?= ($allSettings['omada_version'] ?? 'v5') === 'v5' ? 'selected' : '' ?>>Omada v5.x (Microseconds time unit)</option>
                        <option value="v6" <?= ($allSettings['omada_version'] ?? '') === 'v6' ? 'selected' : '' ?>>Omada v6.x (Milliseconds time unit)</option>
                    </select>
                </div>
            </div>

            <div class="form-group" style="margin-top: 14px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                    <input 
                        type="checkbox" 
                        name="omada_simulation_mode" 
                        value="1" 
                        <?= ($allSettings['omada_simulation_mode'] ?? '0') === '1' ? 'checked' : '' ?>
                    >
                    <span>Enable Offline Simulation Mode (Mock network responses when hardware is offline)</span>
                </label>
            </div>
        </div>
    </div>

    <!-- 2. SonicPesa Payment Gateway Settings -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3 class="card-title">SonicPesa Payment Gateway</h3>
            <button type="button" id="testSonicPesaBtn" class="btn btn-primary btn-sm">
                Test Connection
            </button>
        </div>
        <div class="card-body">
            <div id="testSonicPesaResult" style="display: none; padding: 12px; border-radius: 4px; font-size: 13.5px; margin-bottom: 12px;"></div>
            
            <div class="form-group" style="margin-bottom: 16px;">
                <label style="display: flex; align-items: center; gap: 8px; cursor: pointer; font-weight: 600;">
                    <input 
                        type="checkbox" 
                        name="sonicpesa_enabled" 
                        value="1" 
                        <?= ($allSettings['sonicpesa_enabled'] ?? '1') === '1' ? 'checked' : '' ?>
                    >
                    <span>Enable SonicPesa Payments</span>
                </label>
            </div>
            
            <div class="form-grid">
                <div class="form-group">
                    <label for="sonicpesa_base_url" class="form-label">Base URL</label>
                    <input 
                        type="text" 
                        name="sonicpesa_base_url" 
                        id="sonicpesa_base_url" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['sonicpesa_base_url'] ?? 'https://api.sonicpesa.com/api/v1') ?>" 
                    >
                    <div class="form-help">Production URL is usually https://api.sonicpesa.com/api/v1</div>
                </div>

                <div class="form-group">
                    <label for="sonicpesa_api_key" class="form-label">API Key (X-API-KEY)</label>
                    <input 
                        type="password" 
                        name="sonicpesa_api_key" 
                        id="sonicpesa_api_key" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['sonicpesa_api_key'] ?? '') ?>"
                    >
                </div>
                
                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">Webhook Callback URL</label>
                    <input 
                        type="text" 
                        class="form-control" 
                        value="<?= ViewHelper::e(get_base_url() . '/api/payment/sonicpesa_callback.php') ?>" 
                        readonly
                        style="background-color: #f1f5f9; cursor: not-allowed;"
                    >
                    <div class="form-help">Register this exact URL in your SonicPesa merchant dashboard to receive payment confirmations.</div>
                </div>
            </div>
        </div>
    </div>

    <!-- 3. Business & Localization Settings -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3 class="card-title">Business Branding & Support Contacts</h3>
        </div>
        <div class="card-body">
            <div class="form-grid">
                <div class="form-group">
                    <label for="business_name" class="form-label">Business Name</label>
                    <input type="text" name="business_name" id="business_name" class="form-control" value="<?= ViewHelper::e($allSettings['business_name'] ?? 'Air Link WiFi') ?>" required>
                </div>

                <div class="form-group">
                    <label for="tagline" class="form-label">Tagline</label>
                    <input type="text" name="tagline" id="tagline" class="form-control" value="<?= ViewHelper::e($allSettings['tagline'] ?? 'Reliable Internet. Simple Access.') ?>" required>
                </div>

                <div class="form-group">
                    <label for="wifi_ssid" class="form-label">Wi-Fi SSID Name</label>
                    <input type="text" name="wifi_ssid" id="wifi_ssid" class="form-control" value="<?= ViewHelper::e($allSettings['wifi_ssid'] ?? 'Air Link WiFi') ?>" required>
                </div>

                <div class="form-group">
                    <label for="timezone" class="form-label">Timezone</label>
                    <input type="text" name="timezone" id="timezone" class="form-control" value="<?= ViewHelper::e($allSettings['timezone'] ?? 'Africa/Dar_es_Salaam') ?>" required readonly>
                </div>

                <div class="form-group">
                    <label for="support_phone" class="form-label">Support Phone</label>
                    <input type="text" name="support_phone" id="support_phone" class="form-control" value="<?= ViewHelper::e($allSettings['support_phone'] ?? '+255 700 000 000') ?>">
                </div>

                <div class="form-group">
                    <label for="support_whatsapp" class="form-label">Support WhatsApp</label>
                    <input type="text" name="support_whatsapp" id="support_whatsapp" class="form-control" value="<?= ViewHelper::e($allSettings['support_whatsapp'] ?? '+255 700 000 000') ?>">
                </div>
            </div>
        </div>
    </div>

    <!-- 4. SwalaSMS Integration Settings -->
    <div class="card" style="margin-bottom: 24px;">
        <div class="card-header">
            <h3 class="card-title">SwalaSMS Integration</h3>
            <button type="button" id="testSwalaSmsBtn" class="btn btn-primary btn-sm">
                Test Connection
            </button>
        </div>
        <div class="card-body">
            <div id="testSwalaSmsResult" style="display: none; padding: 12px; border-radius: 4px; font-size: 13.5px; margin-bottom: 12px;"></div>
            <p style="font-size: 13.5px; color: #475569; margin-bottom: 12px;">
                Send SMS voucher codes and notifications to customers via SwalaSMS. Uses Bearer token authentication.
            </p>
            <div class="form-grid">
                <div class="form-group">
                    <label for="swalasms_api_key" class="form-label">API Key (Bearer Token)</label>
                    <input 
                        type="password" 
                        name="swalasms_api_key" 
                        id="swalasms_api_key" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['swalasms_api_key'] ?? '') ?>"
                        placeholder="swl_live_... or swl_test_..."
                    >
                    <div class="form-help">Starts with swl_live_ (production) or swl_test_ (sandbox).</div>
                </div>

                <div class="form-group">
                    <label for="swalasms_sender_id" class="form-label">Sender ID</label>
                    <input 
                        type="text" 
                        name="swalasms_sender_id" 
                        id="swalasms_sender_id" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['swalasms_sender_id'] ?? '') ?>"
                        placeholder="e.g. AirLink"
                    >
                    <div class="form-help">3–11 characters. Must be approved in your SwalaSMS dashboard.</div>
                </div>

                <div class="form-group">
                    <label for="swalasms_base_url" class="form-label">Base URL</label>
                    <input 
                        type="text" 
                        name="swalasms_base_url" 
                        id="swalasms_base_url" 
                        class="form-control" 
                        value="<?= ViewHelper::e($allSettings['swalasms_base_url'] ?? 'https://swalasms.com/api/v1') ?>"
                    >
                    <div class="form-help">Default: https://swalasms.com/api/v1</div>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label class="form-label">SMS Delivery Webhook URL</label>
                    <input 
                        type="text" 
                        class="form-control" 
                        value="<?= ViewHelper::e(get_base_url() . '/api/sms/webhook.php') ?>" 
                        readonly
                        style="background-color: #f1f5f9; cursor: not-allowed;"
                    >
                    <div class="form-help">Register this URL in your SwalaSMS dashboard to receive delivery reports.</div>
                </div>
            </div>
        </div>
    </div>

    <button type="submit" class="btn btn-primary" style="padding: 12px 28px; font-size: 15px;">
        Save All Settings
    </button>
</form>

<?php include __DIR__ . '/includes/footer.php'; ?>
