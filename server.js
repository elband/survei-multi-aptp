require('dotenv').config();
const express  = require('express');
const cors     = require('cors');
const fs       = require('fs');
const path     = require('path');
const pool     = require('./db');
const migrate  = require('./migrate');

const app  = express();
const PORT = process.env.PORT || 3000;

app.use(cors());
app.use(express.json({ limit: '5mb' }));
app.use(express.static(__dirname));

const QUESTIONS_FILE = path.join(__dirname, 'questions.js');
const ADMIN_TOKEN    = process.env.ADMIN_TOKEN || 'apt-admin-secret-token-2024';

// ============================================================
// Middleware: cek token admin
// ============================================================
const requireAdmin = (req, res, next) => {
    if (req.headers.authorization !== ADMIN_TOKEN) {
        return res.status(401).json({ success: false, message: 'Unauthorized' });
    }
    next();
};

// ============================================================
// Helper: konversi array → string CSV untuk MySQL
// ============================================================
const arrToStr = (val) => {
    if (!val) return null;
    return Array.isArray(val) ? val.join(', ') : String(val);
};

// ============================================================
// API: LOGIN
// ============================================================
app.post('/api/login', async (req, res) => {
    const { username, password } = req.body;

    try {
        const [rows] = await pool.query(
            'SELECT id FROM admin_users WHERE username = ? AND password = ?',
            [username, password]
        );

        if (rows.length > 0) {
            res.json({ success: true, token: ADMIN_TOKEN });
        } else {
            res.status(401).json({ success: false, message: 'Username atau password salah' });
        }
    } catch (err) {
        console.error('[Login Error]', err.message);
        res.status(500).json({ success: false, message: 'Terjadi kesalahan server' });
    }
});

// ============================================================
// API: SUBMIT SURVEI (Public)
// ============================================================
app.post('/api/results', async (req, res) => {
    const data = req.body;

    try {
        await pool.query(`
            INSERT INTO survey_responses (
                nama, usia, domisili, pekerjaan, pendapatan,
                domestik_destinasi, domestik_tujuan, domestik_frekuensi,
                domestik_harga, domestik_pesawat, domestik_jam,
                domestik_hari, domestik_saran,
                int_destinasi, int_tujuan, int_frekuensi,
                int_harga, int_pesawat, int_jam,
                int_hari, int_saran,
                freq_rute, freq_tujuan, freq_frekuensi,
                freq_harga, freq_hari, freq_saran,
                raw_data
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        `, [
            data.nama         || null,
            data.usia         || null,
            data.domisili     || null,
            data.pekerjaan    || null,
            data.pendapatan   || null,

            arrToStr(data.domestik_destinasi),
            data.domestik_tujuan    || null,
            data.domestik_frekuensi || null,
            data.domestik_harga     || null,
            data.domestik_pesawat   || null,
            data.domestik_jam       || null,
            arrToStr(data.domestik_hari),
            data.domestik_saran     || null,

            arrToStr(data.int_destinasi),
            data.int_tujuan    || null,
            data.int_frekuensi || null,
            data.int_harga     || null,
            data.int_pesawat   || null,
            data.int_jam       || null,
            arrToStr(data.int_hari),
            data.int_saran     || null,

            arrToStr(data.freq_rute),
            data.freq_tujuan    || null,
            data.freq_frekuensi || null,
            data.freq_harga     || null,
            arrToStr(data.freq_hari),
            data.freq_saran     || null,

            JSON.stringify(data)
        ]);

        res.json({ success: true, message: 'Survei berhasil disimpan' });
    } catch (err) {
        console.error('[Submit Error]', err.message);
        res.status(500).json({ success: false, message: 'Gagal menyimpan survei: ' + err.message });
    }
});

// ============================================================
// API: AMBIL SEMUA HASIL (Admin)
// ============================================================
app.get('/api/results', requireAdmin, async (req, res) => {
    try {
        const page     = parseInt(req.query.page)  || 1;
        const limit    = parseInt(req.query.limit) || 50;
        const offset   = (page - 1) * limit;
        const search   = req.query.search || '';

        let where = '';
        let params = [];

        if (search) {
            where = 'WHERE nama LIKE ? OR domisili LIKE ? OR pekerjaan LIKE ?';
            const s = `%${search}%`;
            params = [s, s, s];
        }

        const [[{ total }]] = await pool.query(
            `SELECT COUNT(*) AS total FROM survey_responses ${where}`,
            params
        );

        const [rows] = await pool.query(
            `SELECT id, submitted_at, nama, usia, domisili, pekerjaan, pendapatan,
                    domestik_destinasi, domestik_tujuan, domestik_jam,
                    int_destinasi, int_tujuan,
                    freq_rute, freq_tujuan,
                    raw_data
             FROM survey_responses ${where}
             ORDER BY submitted_at DESC
             LIMIT ? OFFSET ?`,
            [...params, limit, offset]
        );

        // Parse raw_data JSON string dari MySQL
        const data = rows.map(row => ({
            ...row,
            raw_data: row.raw_data ? (typeof row.raw_data === 'string' ? JSON.parse(row.raw_data) : row.raw_data) : {}
        }));

        res.json({ success: true, data, total, page, limit });
    } catch (err) {
        console.error('[Get Results Error]', err.message);
        res.status(500).json({ success: false, message: err.message });
    }
});

// ============================================================
// API: STATISTIK (Admin)
// ============================================================
app.get('/api/stats', requireAdmin, async (req, res) => {
    try {
        const [[{ total }]] = await pool.query('SELECT COUNT(*) AS total FROM survey_responses');

        const [[{ today }]] = await pool.query(
            "SELECT COUNT(*) AS today FROM survey_responses WHERE DATE(submitted_at) = CURDATE()"
        );

        const [domisiliRows] = await pool.query(`
            SELECT domisili, COUNT(*) AS count
            FROM survey_responses
            WHERE domisili IS NOT NULL
            GROUP BY domisili
            ORDER BY count DESC
            LIMIT 5
        `);

        const [usiaRows] = await pool.query(`
            SELECT usia, COUNT(*) AS count
            FROM survey_responses
            WHERE usia IS NOT NULL
            GROUP BY usia
            ORDER BY count DESC
        `);

        const [weeklyRows] = await pool.query(`
            SELECT DATE(submitted_at) AS date, COUNT(*) AS count
            FROM survey_responses
            WHERE submitted_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
            GROUP BY DATE(submitted_at)
            ORDER BY date ASC
        `);

        res.json({
            success: true,
            stats: { total, today, domisili: domisiliRows, usia: usiaRows, weekly: weeklyRows }
        });
    } catch (err) {
        res.status(500).json({ success: false, message: err.message });
    }
});

// ============================================================
// API: UPDATE KONFIGURASI PERTANYAAN (Admin)
// ============================================================
app.post('/api/questions', requireAdmin, async (req, res) => {
    const newConfig = req.body;

    try {
        // Simpan ke database
        const [existing] = await pool.query('SELECT id FROM survey_config ORDER BY id DESC LIMIT 1');
        if (existing.length > 0) {
            await pool.query(
                'UPDATE survey_config SET config_json = ?, updated_by = ? WHERE id = ?',
                [JSON.stringify(newConfig), 'admin', existing[0].id]
            );
        } else {
            await pool.query(
                'INSERT INTO survey_config (config_json, updated_by) VALUES (?, ?)',
                [JSON.stringify(newConfig), 'admin']
            );
        }

        // Juga update file questions.js (agar frontend bisa load tanpa API)
        const fileContent = `const surveyConfig = ${JSON.stringify(newConfig, null, 4)};`;
        fs.writeFileSync(QUESTIONS_FILE, fileContent);

        res.json({ success: true, message: 'Konfigurasi berhasil disimpan ke database & file' });
    } catch (err) {
        console.error('[Save Config Error]', err.message);
        res.status(500).json({ success: false, message: err.message });
    }
});

// ============================================================
// API: HAPUS SATU RESPONSE (Admin)
// ============================================================
app.delete('/api/results/:id', requireAdmin, async (req, res) => {
    try {
        await pool.query('DELETE FROM survey_responses WHERE id = ?', [req.params.id]);
        res.json({ success: true, message: 'Data berhasil dihapus' });
    } catch (err) {
        res.status(500).json({ success: false, message: err.message });
    }
});

// ============================================================
// START SERVER — Jalankan migrasi dulu
// ============================================================
migrate()
    .then(() => {
        app.listen(PORT, () => {
            console.log(`✅ Server berjalan di http://localhost:${PORT}`);
            console.log(`   Database: ${process.env.DB_NAME || 'survei_apt_pranoto'} @ ${process.env.DB_HOST || 'localhost'}`);
        });
    })
    .catch(err => {
        console.error('❌ Gagal inisialisasi database:', err.message);
        console.error('   Pastikan MySQL berjalan dan konfigurasi .env sudah benar.');
        process.exit(1);
    });
