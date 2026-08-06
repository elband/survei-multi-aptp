# Aplikasi Survei Dinamis APT Pranoto

Aplikasi survei berbasis web yang dinamis, ringan, dan aman tanpa memerlukan *framework* berat. Dibangun menggunakan arsitektur **PHP Native (Server-Side Rendering)** dengan performa tinggi, database MySQL, dan desain *user interface* premium untuk memberikan pengalaman survei terbaik.

Aplikasi ini dirancang khusus untuk memenuhi kebutuhan instansi dalam mengumpulkan data kuesioner, jajak pendapat opini, maupun *feedback* layanan secara real-time dan terstruktur.

![Tampilan Dashboard Admin](https://img.shields.io/badge/PHP-Native-777BB4?style=flat-square&logo=php)
![MySQL](https://img.shields.io/badge/MySQL-Database-4479A1?style=flat-square&logo=mysql)
![ChartJS](https://img.shields.io/badge/Chart.js-Analytics-FF6384?style=flat-square&logo=chart.js)

---

## ✨ Fitur Unggulan

- 🛠️ **Form Builder Dinamis** - Buat berbagai jenis pertanyaan (Teks, Paragraf, Dropdown, Radio Button, Checkbox, Pilihan Hari) semudah menyusun blok bangunan.
- 📊 **Visualisasi Analytics (Chart.js)** - Merender semua jawaban pilihan ganda responden menjadi bentuk grafik Pie atau Bar Chart secara otomatis.
- 📱 **Mobile Responsive Premium** - Tampilan publik dan admin yang dijamin 100% rapi saat diakses melalui perangkat seluler maupun tablet.
- 🔐 **Keamanan Lapis Ganda** - Dilengkapi *CSRF Token Validation* anti sabotase, PDO Prepared Statements (Anti SQL-Injection), serta *Environment Variable (.env)* untuk menyimpan kredensial dengan aman.
- 📄 **Export Data Lengkap** - Dukungan unduh data responden secara keseluruhan ke dalam format Excel (.xls).
- 🛡️ **Audit Log Sistem** - Melacak setiap rekam jejak aktivitas, perubahan, dan IP pengguna Admin untuk alasan akuntabilitas keamanan.

---

## 💻 Persyaratan Sistem (Prerequisites)

Pastikan lingkungan lokal atau server Anda memiliki:
- **PHP** minimal versi 7.4 (Direkomendasikan PHP 8.x)
- **MySQL** / MariaDB Database Server
- **Web Server** seperti Apache atau Nginx (Cocok menggunakan XAMPP, Laragon, MAMP, dsb.)
- Ekstensi PHP yang aktif: `pdo_mysql`, `session`

---

## 🚀 Cara Instalasi & Penggunaan

Ikuti langkah-langkah mudah di bawah ini untuk menjalankan aplikasi pada PC atau *server* Anda:

### 1. Unduh / Clone Repository
Letakkan folder (atau jalankan *git clone*) proyek ini di dalam folder direktori server lokal Anda.
- Untuk XAMPP: `C:\xampp\htdocs\aplikasi-survei`
- Untuk Laragon: `C:\laragon\www\aplikasi-survei`

### 2. Siapkan Database
1. Buka *phpMyAdmin* (biasanya di `http://localhost/phpmyadmin`).
2. Buat satu database baru, beri nama bebas (misalnya `db_survei`).
3. Temukan file bernama **`database.sql`** (bisa yang berada di root folder atau di dalam folder `database/`).
4. **Import** file `.sql` tersebut ke dalam database `db_survei` yang baru Anda buat. Tabel `surveys`, `survey_responses`, dan `audit_logs` akan otomatis terbuat.

### 3. Konfigurasi Lingkungan (.env)
Aplikasi ini membaca kredensial dari file `.env`. 
1. Jika file `.env` belum ada, buat file baru bernama `.env` sejajar dengan `index.php`.
2. Isi file tersebut dengan baris pengaturan berikut (sesuaikan dengan komputer/server Anda):
   ```env
   # KONEKSI DATABASE
   DB_HOST=127.0.0.1
   DB_NAME=db_survei
   DB_USER=root
   DB_PASS=

   # KREDENSIAL LOGIN ADMIN PANEL
   ADMIN_USER=admin
   ADMIN_PASS=password123
   ```
*(Catatan: Biarkan `DB_PASS=` kosong jika menggunakan XAMPP bawaan).*

### 4. Mulai Jalankan!
Aplikasi siap digunakan.
- **Halaman Utama (Survei Publik):** Akses `http://localhost/aplikasi-survei/index.php` *(Tambahkan parameter ID seperti `?id=1` untuk melihat survei spesifik).*
- **Dashboard Admin:** Akses `http://localhost/aplikasi-survei/admin.php`
- Gunakan `Username` dan `Password` sesuai yang Anda masukkan ke dalam file `.env` di atas.

---

## 📁 Struktur Direktori Penting

```text
├── assets/                 # Folder stylesheet (.css), javascript utama (.js), dan ikon
├── database/               # Kumpulan skema .sql dan riwayat migrasi database
├── .env                    # Variabel keamanan untuk kredensial Login Admin & Database
├── admin.php               # Halaman utama antarmuka Dashboard Admin
├── audit_log.php           # Catatan log aktivitas dan pemantauan Admin
├── auth.php                # Celah penjaga / Keamanan Anti-CSRF
├── db.php                  # Engine koneksi antara PHP dan MySQL (PDO)
├── export_excel.php        # Modul handler untuk mendownload tabel spreadsheet
├── index.php               # Front-End/Tampilan Publik survei bagi responden
├── login.php               # Pintu gerbang sistem panel
├── proses_survei.php       # Prosesor input data (menyimpan JSON jawaban)
└── README.md               # Dokumentasi cara pakai (file ini)
```

---

## 📄 Hak Cipta & Portofolio
Proyek aplikasi ini merupakan karya dan hak cipta penuh dari:
**IT BLU Kantor UPBU Kelas I A.P.T Pranoto**
Pengembangan dirancang khusus dengan menitikberatkan pada kestabilan ekosistem server tanpa bergantung pada library vendor pihak ketiga yang memberatkan *bandwith*.
