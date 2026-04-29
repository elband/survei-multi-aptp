require('dotenv').config();
const mysql = require('mysql2/promise');

/**
 * Script migrasi database.
 * Dijalankan otomatis saat server start, atau manual: node migrate.js
 * Akan membuat database & tabel bila belum ada.
 */
async function migrate() {
    // Konek dulu tanpa pilih database untuk bisa buat DB-nya
    const conn = await mysql.createConnection({
        host:     process.env.DB_HOST     || 'localhost',
        port:     parseInt(process.env.DB_PORT) || 3306,
        user:     process.env.DB_USER     || 'root',
        password: process.env.DB_PASSWORD || '',
        charset:  'utf8mb4',
    });

    const dbName = process.env.DB_NAME || 'survei_apt_pranoto';

    console.log(`[DB] Memastikan database '${dbName}' ada...`);
    await conn.query(
        `CREATE DATABASE IF NOT EXISTS \`${dbName}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci`
    );
    await conn.query(`USE \`${dbName}\``);

    // ============================================================
    // Tabel: survey_responses
    // Kolom utama yang sering di-query dipisah untuk performa.
    // Seluruh data mentah disimpan di kolom 'raw_data' (JSON).
    // ============================================================
    console.log('[DB] Membuat tabel survey_responses...');
    await conn.query(`
        CREATE TABLE IF NOT EXISTS survey_responses (
            id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            submitted_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,

            -- Kolom utama (untuk tabel & filter cepat)
            nama            VARCHAR(255),
            usia            VARCHAR(100),
            domisili        VARCHAR(255),
            pekerjaan       VARCHAR(255),
            pendapatan      VARCHAR(100),

            -- Rute domestik
            domestik_destinasi  TEXT,
            domestik_tujuan     VARCHAR(100),
            domestik_frekuensi  VARCHAR(100),
            domestik_harga      VARCHAR(100),
            domestik_pesawat    VARCHAR(100),
            domestik_jam        VARCHAR(100),
            domestik_hari       TEXT,
            domestik_saran      TEXT,

            -- Rute internasional
            int_destinasi       TEXT,
            int_tujuan          VARCHAR(100),
            int_frekuensi       VARCHAR(100),
            int_harga           VARCHAR(100),
            int_pesawat         VARCHAR(100),
            int_jam             VARCHAR(100),
            int_hari            TEXT,
            int_saran           TEXT,

            -- Frekuensi rute existing
            freq_rute           TEXT,
            freq_tujuan         VARCHAR(100),
            freq_frekuensi      VARCHAR(100),
            freq_harga          VARCHAR(100),
            freq_hari           TEXT,
            freq_saran          TEXT,

            -- Semua data JSON mentah (untuk future-proofing)
            raw_data        JSON,

            INDEX idx_submitted_at (submitted_at),
            INDEX idx_domisili (domisili),
            INDEX idx_usia (usia)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);

    // ============================================================
    // Tabel: survey_config
    // Menyimpan konfigurasi pertanyaan survei yang diedit admin
    // ============================================================
    console.log('[DB] Membuat tabel survey_config...');
    await conn.query(`
        CREATE TABLE IF NOT EXISTS survey_config (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            config_json LONGTEXT NOT NULL,
            updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            updated_by  VARCHAR(100) DEFAULT 'admin'
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);

    // ============================================================
    // Tabel: admin_users
    // ============================================================
    console.log('[DB] Membuat tabel admin_users...');
    await conn.query(`
        CREATE TABLE IF NOT EXISTS admin_users (
            id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            username    VARCHAR(100) NOT NULL UNIQUE,
            password    VARCHAR(255) NOT NULL,
            created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);

    // Insert default admin jika belum ada
    const [[existing]] = await conn.query(
        'SELECT id FROM admin_users WHERE username = ?',
        [process.env.ADMIN_USERNAME || 'admin']
    );
    if (!existing) {
        await conn.query(
            'INSERT INTO admin_users (username, password) VALUES (?, ?)',
            [
                process.env.ADMIN_USERNAME || 'admin',
                process.env.ADMIN_PASSWORD || 'admin123'
            ]
        );
        console.log('[DB] Default admin user dibuat.');
    }

    await conn.end();
    console.log('[DB] ✅ Migrasi selesai!');
}

module.exports = migrate;

// Jalankan jika langsung: node migrate.js
if (require.main === module) {
    migrate().catch(err => {
        console.error('[DB] ❌ Migrasi gagal:', err.message);
        process.exit(1);
    });
}
