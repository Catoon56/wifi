<?php
/**
 * Air Link WiFi - Diagnostic Omada Connection Test Tool
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/auth_check.php';
require_once __DIR__ . '/../services/OmadaService.php';

$omada = new OmadaService();
$result = $omada->testConnection();

if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
    header('Content-Type: application/json');
    echo json_encode($result);
    exit;
}

$pageTitle = "Omada Diagnostic Test";
include __DIR__ . '/includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">TP-Link Omada Controller Diagnostic Report</h3>
        <a href="settings.php" class="btn btn-secondary btn-sm">&larr; Back to Settings</a>
    </div>
    <div class="card-body">
        <div class="alert <?= $result['success'] ? 'alert-success' : 'alert-danger' ?>">
            <strong>Status: <?= strtoupper($result['status'] ?? 'UNKNOWN') ?></strong><br>
            <?= ViewHelper::e($result['message']) ?>
        </div>

        <table class="info-table" style="max-width: 600px;">
            <tr>
                <td>Controller URL:</td>
                <td><?= ViewHelper::e(Database::getSetting('omada_controller_url', OMADA_CONTROLLER_URL)) ?></td>
            </tr>
            <tr>
                <td>Site:</td>
                <td><?= ViewHelper::e(Database::getSetting('omada_site', OMADA_SITE)) ?></td>
            </tr>
            <tr>
                <td>Hotspot Operator User:</td>
                <td><?= ViewHelper::e(Database::getSetting('omada_operator_user', OMADA_OPERATOR_USER)) ?></td>
            </tr>
            <tr>
                <td>Architecture Version:</td>
                <td><?= ViewHelper::e(strtoupper(Database::getSetting('omada_version', OMADA_VERSION))) ?></td>
            </tr>
            <tr>
                <td>Simulation Mode:</td>
                <td><?= Database::getSetting('omada_simulation_mode', '0') === '1' ? 'Enabled (Offline Sandbox)' : 'Disabled (Live Network Calls)' ?></td>
            </tr>
        </table>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
