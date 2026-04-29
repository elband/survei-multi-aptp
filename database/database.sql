-- ============================================================
--  DATABASE: survei_apt_pranoto_multi
--  APT Pranoto Airport - Multi Survey Platform
--  Versi Aman (Anti-Hack) | dibuat dengan standar keamanan tinggi
-- ============================================================

-- Buat database jika belum ada
CREATE DATABASE IF NOT EXISTS `survei_apt_pranoto_multi`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `survei_apt_pranoto_multi`;

-- ============================================================
-- TABEL: surveys
-- Menyimpan konfigurasi setiap survei dalam format JSON
-- ============================================================
CREATE TABLE IF NOT EXISTS `surveys` (
    `id`          INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(255)    NOT NULL DEFAULT 'Survei Tanpa Judul',
    `description` TEXT,
    `config_json` LONGTEXT        NOT NULL
                  COMMENT 'Konfigurasi pertanyaan survei dalam format JSON',
    `is_active`   TINYINT(1)      NOT NULL DEFAULT 1,
    `created_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP
                  ON UPDATE CURRENT_TIMESTAMP,
    `updated_by`  VARCHAR(100)    NOT NULL DEFAULT 'admin',
    PRIMARY KEY (`id`),
    INDEX `idx_is_active` (`is_active`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Daftar survei dan konfigurasinya';


-- ============================================================
-- TABEL: survey_responses
-- Menyimpan semua jawaban responden secara dinamis (JSON)
-- Security: ON DELETE CASCADE untuk mencegah data orphan
-- ============================================================
CREATE TABLE IF NOT EXISTS `survey_responses` (
    `id`            INT UNSIGNED    NOT NULL AUTO_INCREMENT,
    `survey_id`     INT UNSIGNED    NOT NULL,
    `ip_address`    VARCHAR(45)     DEFAULT NULL
                    COMMENT 'IPv4/IPv6 pengirim (opsional, untuk audit)',
    `submitted_at`  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `raw_data`      JSON            NOT NULL
                    COMMENT 'Seluruh jawaban responden dalam format JSON',
    PRIMARY KEY (`id`),
    INDEX `idx_survey_id`   (`survey_id`),
    INDEX `idx_submitted_at`(`submitted_at`),
    CONSTRAINT `fk_response_survey`
        FOREIGN KEY (`survey_id`)
        REFERENCES `surveys` (`id`)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Jawaban responden per survei';


-- ============================================================
-- TABEL: admin_users (Referensi saja - login via .env)
-- CATATAN KEAMANAN:
--   Kolom `password` kosong karena autentikasi menggunakan
--   variabel ADMIN_USER & ADMIN_PASS di file .env,
--   BUKAN query ke tabel ini.
--   Hal ini mencegah serangan SQL Injection pada login.
-- ============================================================
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`     VARCHAR(100) NOT NULL,
    `role`         ENUM('superadmin', 'admin') NOT NULL DEFAULT 'admin',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_login`   DATETIME     DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Data admin (password dikelola via .env, bukan tabel ini)';

-- Data awal admin (password TIDAK disimpan di sini, lihat .env)
INSERT IGNORE INTO `admin_users` (`username`, `role`)
VALUES ('admin', 'superadmin');


-- ============================================================
-- TABEL: audit_logs
-- Mencatat setiap aksi penting untuk keamanan dan forensik
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_user` VARCHAR(100) DEFAULT NULL
                 COMMENT 'Username yang melakukan aksi',
    `action`     VARCHAR(100) NOT NULL
                 COMMENT 'Contoh: LOGIN, CREATE_SURVEY, DELETE_RESPONSE',
    `target`     VARCHAR(255) DEFAULT NULL
                 COMMENT 'Objek yang dikenai aksi, misal: survey_id=3',
    `ip_address` VARCHAR(45)  DEFAULT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    INDEX `idx_admin_user` (`admin_user`),
    INDEX `idx_created_at` (`created_at`)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Log audit untuk keamanan dan forensik';
