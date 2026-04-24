require('dotenv').config();
const mysql = require('mysql2/promise');

// Buat connection pool agar lebih efisien untuk skala besar
const pool = mysql.createPool({
    host:     process.env.DB_HOST     || 'localhost',
    port:     parseInt(process.env.DB_PORT) || 3306,
    user:     process.env.DB_USER     || 'root',
    password: process.env.DB_PASSWORD || '',
    database: process.env.DB_NAME     || 'survei_apt_pranoto',
    waitForConnections: true,
    connectionLimit: 20,       // Max koneksi paralel
    queueLimit: 0,
    timezone: '+08:00',        // WITA
    charset: 'utf8mb4',        // Mendukung emoji & karakter khusus
});

module.exports = pool;
