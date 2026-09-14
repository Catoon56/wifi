-- ==========================================================
-- AIR LINK WIFI - PRODUCTION DATABASE SCHEMA
-- Compatible with MySQL 5.7+ / 8.0+ & MariaDB 10.3+
-- Timezone: Africa/Dar_es_Salaam (UTC+3)
-- ==========================================================

SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `audit_logs`;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `sales`;
DROP TABLE IF EXISTS `wifi_sessions`;
DROP TABLE IF EXISTS `vouchers`;
DROP TABLE IF EXISTS `packages`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `settings`;
SET FOREIGN_KEY_CHECKS = 1;

-- ----------------------------------------------------------
-- 1. SYSTEM SETTINGS
-- ----------------------------------------------------------
CREATE TABLE `settings` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `setting_key` VARCHAR(100) NOT NULL,
  `setting_value` TEXT NULL,
  `setting_group` VARCHAR(50) NOT NULL DEFAULT 'general',
  `description` VARCHAR(255) NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_setting_key` (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 2. ADMINISTRATORS & OPERATORS
-- ----------------------------------------------------------
CREATE TABLE `admins` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `username` VARCHAR(50) NOT NULL,
  `email` VARCHAR(100) NOT NULL,
  `password_hash` VARCHAR(255) NOT NULL,
  `full_name` VARCHAR(100) NOT NULL,
  `role` ENUM('admin', 'operator') NOT NULL DEFAULT 'admin',
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `failed_login_attempts` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `lockout_until` DATETIME NULL,
  `last_login` DATETIME NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_username` (`username`),
  UNIQUE KEY `uk_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 3. WIFI PACKAGES
-- ----------------------------------------------------------
CREATE TABLE `packages` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(100) NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration` INT UNSIGNED NOT NULL,
  `duration_unit` ENUM('minutes', 'hours', 'days') NOT NULL DEFAULT 'hours',
  `download_speed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Mbps (0 = unlimited)',
  `upload_speed` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'Mbps (0 = unlimited)',
  `max_devices` TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `status` ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 4. VOUCHERS
-- ----------------------------------------------------------
CREATE TABLE `vouchers` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_code` VARCHAR(32) NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `duration` INT UNSIGNED NOT NULL,
  `duration_unit` ENUM('minutes', 'hours', 'days') NOT NULL DEFAULT 'hours',
  `status` ENUM('unused', 'active', 'expired', 'disabled') NOT NULL DEFAULT 'unused',
  `created_by` INT UNSIGNED NULL,
  `batch_id` VARCHAR(64) NULL,
  `client_mac` VARCHAR(30) NULL,
  `client_ip` VARCHAR(45) NULL,
  `session_id` VARCHAR(100) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `activated_at` DATETIME NULL,
  `expires_at` DATETIME NULL,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_voucher_code` (`voucher_code`),
  KEY `idx_package_id` (`package_id`),
  KEY `idx_status` (`status`),
  KEY `idx_batch_id` (`batch_id`),
  KEY `idx_client_mac` (`client_mac`),
  KEY `idx_expires_at` (`expires_at`),
  CONSTRAINT `fk_vouchers_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_vouchers_admin` FOREIGN KEY (`created_by`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 5. WIFI CLIENT SESSIONS
-- ----------------------------------------------------------
CREATE TABLE `wifi_sessions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `session_id` VARCHAR(100) NOT NULL,
  `voucher_id` INT UNSIGNED NOT NULL,
  `client_mac` VARCHAR(30) NOT NULL,
  `client_ip` VARCHAR(45) NOT NULL,
  `ap_mac` VARCHAR(30) NULL,
  `ssid_name` VARCHAR(64) NULL,
  `login_time` DATETIME NOT NULL,
  `last_seen` DATETIME NOT NULL,
  `disconnect_time` DATETIME NULL,
  `remaining_time` INT NOT NULL DEFAULT 0 COMMENT 'Remaining seconds',
  `status` ENUM('active', 'disconnected', 'expired', 'terminated') NOT NULL DEFAULT 'active',
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_session_id` (`session_id`),
  KEY `idx_voucher_id` (`voucher_id`),
  KEY `idx_client_mac` (`client_mac`),
  KEY `idx_status` (`status`),
  CONSTRAINT `fk_sessions_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 6. SALES RECORDS
-- ----------------------------------------------------------
CREATE TABLE `sales` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `voucher_id` INT UNSIGNED NOT NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `price` DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  `payment_method` ENUM('Cash', 'SonicPesa', 'PesaPal', 'Other') NOT NULL DEFAULT 'Cash',
  `transaction_reference` VARCHAR(100) NULL,
  `customer_phone` VARCHAR(30) NULL,
  `admin_id` INT UNSIGNED NULL,
  `sale_date` DATETIME NOT NULL,
  `status` ENUM('completed', 'refunded', 'cancelled') NOT NULL DEFAULT 'completed',
  `notes` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_voucher_id` (`voucher_id`),
  KEY `idx_package_id` (`package_id`),
  KEY `idx_sale_date` (`sale_date`),
  KEY `idx_payment_method` (`payment_method`),
  KEY `idx_transaction_ref` (`transaction_reference`),
  CONSTRAINT `fk_sales_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_sales_admin` FOREIGN KEY (`admin_id`) REFERENCES `admins` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 7. ONLINE PAYMENTS (SONICPESA / DIGITAL)
-- ----------------------------------------------------------
CREATE TABLE `payments` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `transaction_id` VARCHAR(100) NULL,
  `reference` VARCHAR(100) NOT NULL,
  `amount` DECIMAL(10,2) NOT NULL,
  `currency` VARCHAR(10) NOT NULL DEFAULT 'TZS',
  `phone` VARCHAR(30) NULL,
  `package_id` INT UNSIGNED NOT NULL,
  `payment_method` VARCHAR(50) NULL,
  `payment_status` ENUM('pending', 'completed', 'failed', 'cancelled') NOT NULL DEFAULT 'pending',
  `order_tracking_id` VARCHAR(100) NULL,
  `merchant_reference` VARCHAR(100) NULL,
  `voucher_id` INT UNSIGNED NULL,
  `raw_response` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_transaction_id` (`transaction_id`),
  KEY `idx_reference` (`reference`),
  KEY `idx_tracking_id` (`order_tracking_id`),
  KEY `idx_payment_status` (`payment_status`),
  CONSTRAINT `fk_payments_package` FOREIGN KEY (`package_id`) REFERENCES `packages` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE,
  CONSTRAINT `fk_payments_voucher` FOREIGN KEY (`voucher_id`) REFERENCES `vouchers` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 8. AUDIT & ACTIVITY LOGS
-- ----------------------------------------------------------
CREATE TABLE `audit_logs` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `timestamp` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `actor_type` ENUM('admin', 'customer', 'system', 'api') NOT NULL DEFAULT 'system',
  `actor_identifier` VARCHAR(100) NULL,
  `action` VARCHAR(100) NOT NULL,
  `ip_address` VARCHAR(45) NULL,
  `result` ENUM('success', 'failure', 'warning') NOT NULL DEFAULT 'success',
  `details` TEXT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_timestamp` (`timestamp`),
  KEY `idx_action` (`action`),
  KEY `idx_actor` (`actor_type`, `actor_identifier`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------
-- 9. SMS MESSAGES (SWALASMS)
-- ----------------------------------------------------------
CREATE TABLE \sms_messages\ (
  \id\ INT UNSIGNED NOT NULL AUTO_INCREMENT,
  \message_id\ VARCHAR(100) NULL,
  \ecipient\ VARCHAR(30) NOT NULL,
  \sender_id\ VARCHAR(20) NOT NULL,
  \ody\ TEXT NOT NULL,
  \status\ ENUM('pending', 'sent', 'delivered', 'failed') NOT NULL DEFAULT 'pending',
  \error_message\ TEXT NULL,
  \created_at\ TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  \updated_at\ TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (\id\),
  KEY \idx_message_id\ (\message_id\),
  KEY \idx_recipient\ (\ecipient\)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

