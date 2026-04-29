-- ============================================================
--  MIGRATION SCRIPT v2 - Kompatibel MySQL 5.7 & 8.0
--  Jalankan satu per satu di phpMyAdmin jika ada error "Duplicate column"
--  APT Pranoto Airport - Multi Survey Platform
-- ============================================================

USE `survei_apt_pranoto_multi`;

-- ============================================================
-- STEP 1A: Tambah kolom 'role' ke admin_users
--   Jika error "Duplicate column name 'role'" = kolom sudah ada, SKIP saja
-- ============================================================
ALTER TABLE `admin_users`
    ADD COLUMN `role` ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin' AFTER `username`;

-- ============================================================
-- STEP 1B: Tambah kolom 'last_login' ke admin_users
--   Jika error "Duplicate column name 'last_login'" = sudah ada, SKIP saja
-- ============================================================
ALTER TABLE `admin_users`
    ADD COLUMN `last_login` DATETIME DEFAULT NULL AFTER `created_at`;

-- ============================================================
-- STEP 2: Update data admin yang sudah ada
-- ============================================================
INSERT IGNORE INTO `admin_users` (`username`, `role`)
    VALUES ('admin', 'superadmin');

UPDATE `admin_users` SET `role` = 'superadmin'
    WHERE `username` = 'admin';

-- ============================================================
-- STEP 3: Buat tabel audit_logs (jika belum ada)
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_user` VARCHAR(100) DEFAULT NULL,
    `action`     VARCHAR(100) NOT NULL,
    `target`     VARCHAR(255) DEFAULT NULL,
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_admin_user` (`admin_user`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- STEP 4: Tambah kolom ip_address ke survey_responses
--   Jika error "Duplicate column" = sudah ada, SKIP saja
-- ============================================================
ALTER TABLE `survey_responses`
    ADD COLUMN `ip_address` VARCHAR(45) DEFAULT NULL AFTER `survey_id`;

-- ============================================================
-- Verifikasi hasil:
-- SHOW COLUMNS FROM admin_users;
-- SHOW COLUMNS FROM audit_logs;
-- SHOW COLUMNS FROM survey_responses;
-- ============================================================
