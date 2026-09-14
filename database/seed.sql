-- ==========================================================
-- AIR LINK WIFI - SEED DATA
-- Default administrator, standard Wi-Fi packages, and system settings
-- ==========================================================

-- ----------------------------------------------------------
-- 1. DEFAULT ADMINISTRATOR
-- Default credentials:
-- Username: admin
-- Password: password  (or admin123 via setup.php installer)
-- ----------------------------------------------------------
INSERT INTO `admins` (`id`, `username`, `email`, `password_hash`, `full_name`, `role`, `status`) VALUES
(1, 'admin', 'admin@airlinkwifi.co.tz', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Air Link Administrator', 'admin', 'active')
ON DUPLICATE KEY UPDATE `status` = 'active';

-- ----------------------------------------------------------
-- 2. STANDARD WIFI PACKAGES (Tanzania TTCL / Air Link pricing)
-- ----------------------------------------------------------
INSERT INTO `packages` (`id`, `name`, `price`, `duration`, `duration_unit`, `download_speed`, `upload_speed`, `max_devices`, `status`) VALUES
(1, '1 Hour Internet', 500.00, 1, 'hours', 3, 1, 1, 'active'),
(2, '3 Hours Internet', 500.00, 3, 'hours', 3, 1, 1, 'active'),
(3, '6 Hours Internet', 1000.00, 6, 'hours', 4, 2, 1, 'active'),
(4, '12 Hours Internet', 1500.00, 12, 'hours', 5, 2, 1, 'active'),
(5, '24 Hours Internet', 2000.00, 24, 'hours', 5, 2, 1, 'active')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`), `price` = VALUES(`price`);

-- ----------------------------------------------------------
-- 3. DEFAULT SYSTEM & INTEGRATION SETTINGS
-- ----------------------------------------------------------
INSERT INTO `settings` (`setting_key`, `setting_value`, `setting_group`, `description`) VALUES
('business_name', 'Air Link WiFi', 'branding', 'Official business name'),
('brand_name', 'Air Link', 'branding', 'Short brand name'),
('tagline', 'Reliable Internet. Simple Access.', 'branding', 'Marketing tagline'),
('currency', 'TZS', 'localization', 'Currency code'),
('currency_symbol', 'TSh', 'localization', 'Display symbol for currency'),
('timezone', 'Africa/Dar_es_Salaam', 'localization', 'Default system timezone'),
('support_phone', '+255 700 000 000', 'contact', 'Customer support telephone'),
('support_whatsapp', '+255 700 000 000', 'contact', 'Customer support WhatsApp number'),
('wifi_ssid', 'Air Link WiFi', 'network', 'Primary broadcast SSID name'),
('omada_controller_url', 'https://192.168.0.100:8043', 'omada', 'TP-Link Omada Controller URL with port'),
('omada_site', 'default', 'omada', 'Omada Site ID or default'),
('omada_operator_user', 'airlink_operator', 'omada', 'Hotspot operator username on Omada'),
('omada_operator_pass', 'Operator@2026', 'omada', 'Hotspot operator password on Omada'),
('omada_controller_id', '', 'omada', 'Omada Controller ID (if required by v5/v6 URL path)'),
('omada_version', 'v5', 'omada', 'Omada Controller version: v5 or v6'),
('omada_simulation_mode', '1', 'omada', '1 = Simulated testing when hardware offline, 0 = Production live API'),
('sonicpesa_enabled', '1', 'payment', 'SonicPesa enabled status: 1 or 0'),
('sonicpesa_api_key', '', 'payment', 'SonicPesa API Key'),
('sonicpesa_base_url', 'https://api.sonicpesa.com/api/v1', 'payment', 'SonicPesa API Base URL'),
('sonicpesa_webhook_secret', '', 'payment', 'SonicPesa Webhook Signature Secret'),
('swalasms_api_key', '', 'sms', 'SwalaSMS Bearer API Key (swl_live_... or swl_test_...)'),
('swalasms_sender_id', 'AIRLINK', 'sms', 'SwalaSMS Approved Sender ID'),
('swalasms_base_url', 'https://swalasms.com/api/v1', 'sms', 'SwalaSMS API Base URL'),
('swalasms_webhook_secret', '', 'sms', 'SwalaSMS Webhook HMAC Secret'),
('session_inactivity_timeout', '1800', 'security', 'Inactivity timeout in seconds for admin sessions'),
('max_voucher_attempts', '5', 'security', 'Maximum failed voucher attempts before temporary cooldown'),
('lockout_duration_minutes', '15', 'security', 'Duration of IP/MAC lockout in minutes')
ON DUPLICATE KEY UPDATE `description` = VALUES(`description`);
