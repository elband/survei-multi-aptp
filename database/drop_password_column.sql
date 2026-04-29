-- ============================================================
--  HAPUS PASSWORD PLAIN TEXT dari tabel admin_users
--  Jalankan ini di phpMyAdmin tab SQL
--  Login sekarang menggunakan .env, bukan database!
-- ============================================================

USE `survei_apt_pranoto_multi`;

-- Hapus kolom password yang menyimpan teks biasa (berbahaya!)
ALTER TABLE `admin_users` DROP COLUMN `password`;

-- Verifikasi hasilnya:
SHOW COLUMNS FROM `admin_users`;
