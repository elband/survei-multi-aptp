# Instalasi dari Nol — Survei APT Pranoto di `https://aptpairport.id/`

Panduan ini memasang aplikasi **dari awal** di **akar domain**, menggantikan
situs yang sekarang ada di `aptpairport.id/`.

Untuk sekadar meng-update instalasi yang sudah jalan, pakai
[`DEPLOY.md`](DEPLOY.md) + `deploy.sh` — bukan panduan ini.

---

## ⚠️ Baca ini dulu

**1. Situs lama akan berhenti tersaji.** `aptpairport.id/` saat ini melayani
sebuah aplikasi Next.js (landing + dashboard). Setelah instalasi ini, akar
domain berisi aplikasi survei. Folder dan proses Node situs lama **tidak
dihapus** — hanya server block Nginx-nya yang dinonaktifkan, jadi bisa
dipulihkan kapan saja (lihat [Rollback](#8-rollback)).

**2. Logo sudah dibawa ke dalam repo.** Sebelumnya semua halaman menarik logo
dari `https://aptpairport.id/assets_landing/img/logo/logo-apt.svg` — URL itu
sudah 404 sekarang, dan tetap akan mati setelah situs lama dicabut. Logo
sekarang ada di `assets/images/logo-apt.svg` dan disajikan dari repo ini.

**3. Database dianggap sudah ada.** Skrip hanya menyambung, memeriksa tabel,
dan mengimpor `database/database.sql` kalau ada tabel yang belum terbentuk
(semua `CREATE TABLE IF NOT EXISTS` — data lama tidak tersentuh).

**4. Kredensial disimpan di luar document root.** `db.php` mencari berkas
bernama `survei.env` di folder-folder induk sebelum jatuh ke `.env` di dalam
folder aplikasi. Instalasi ini memakai jalur yang aman itu.

---

## Tata letak yang dihasilkan

```
/var/www/aptpairport.id/
├── survei.env          <- kredensial, DI LUAR document root, 640 root:www-data
└── survei/             <- document root Nginx (isi repo ini)
    ├── index.php
    ├── uploads/        <- satu-satunya folder yang writable oleh PHP-FPM
    ├── errors/
    └── deploy/nginx-aptpairport.id.conf
```

| URL | Isi |
|---|---|
| `https://aptpairport.id/` | Form survei (`?id=N`); tanpa `id` → dialihkan ke login admin |
| `https://aptpairport.id/login.php` | Login admin |
| `https://aptpairport.id/admin.php` | Dashboard admin |
| `https://aptpairport.id/uploads/…` | Gambar; eksekusi skrip ditolak |

---

## 1. Prasyarat server

```bash
sudo apt update
sudo apt install -y nginx git curl certbot python3-certbot-nginx \
                    php-fpm php-mysql php-gd php-mbstring mysql-client
```

Pastikan versinya cocok dengan yang berjalan:

```bash
php -v
ls /run/php/*.sock          # catat nama socket-nya
php -m | grep -iE '^(pdo_mysql|gd|mbstring|json)$'
```

Naikkan batas upload di `php.ini` milik **FPM** (bukan CLI) — biasanya
`/etc/php/8.2/fpm/php.ini`:

```ini
file_uploads = On
upload_max_filesize = 5M
post_max_size = 6M          ; harus lebih besar dari upload_max_filesize
memory_limit = 128M         ; GD memuat bitmap penuh saat re-encode
```

```bash
sudo systemctl restart php8.2-fpm     # sesuaikan versi
```

Terakhir, pastikan DNS memang menunjuk ke server ini:

```bash
dig +short aptpairport.id
curl -sS -o /dev/null -w '%{http_code}\n' http://aptpairport.id/
```

---

## 2. Ambil kode

```bash
sudo mkdir -p /var/www/aptpairport.id
sudo git clone https://github.com/elband/survei-multi-aptp.git \
     /var/www/aptpairport.id/survei
cd /var/www/aptpairport.id/survei
git log --oneline -1
```

> `install.sh` juga bisa melakukan clone ini sendiri kalau foldernya masih
> kosong, tapi meng-clone lebih dulu membuat langkah berikutnya lebih jelas.

---

## 3. Kredensial

```bash
sudo tee /var/www/aptpairport.id/survei.env > /dev/null <<'EOF'
DB_HOST=localhost
DB_PORT=3306
DB_USER=GANTI_USER
DB_PASSWORD=GANTI_PASSWORD
DB_NAME=GANTI_NAMA_DB

ADMIN_USER=admin
ADMIN_PASS=GANTI_PASSWORD_ADMIN

GEMINI_API_KEY=
EOF

sudo chown root:www-data /var/www/aptpairport.id/survei.env
sudo chmod 640 /var/www/aptpairport.id/survei.env
```

Nama berkasnya **harus** `survei.env` — itu yang dicari `db.php` di folder
induk. Kalau ingin nama/lokasi lain, set `SURVEI_ENV_PATH` di pool PHP-FPM:

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
env[SURVEI_ENV_PATH] = /etc/survei/env
```

> `ADMIN_PASS` juga jadi bahan token CSRF. Menggantinya akan memaksa semua
> admin login ulang — itu normal.

---

## 4. Database

Database dianggap sudah ada. Cukup pastikan bisa disambungi:

```bash
mysql -u GANTI_USER -p -e "SHOW TABLES;" GANTI_NAMA_DB
```

Yang dibutuhkan aplikasi: `surveys`, `survey_responses`, `audit_logs`.
Kalau ada yang belum terbentuk, `install.sh` akan mengimpornya sendiri dari
`database/database.sql`.

Backup dulu sebelum lanjut:

```bash
mysqldump -u GANTI_USER -p --single-transaction GANTI_NAMA_DB \
    > ~/backup-$(date +%F).sql
```

---

## 5. Jalankan installer

Kenali dulu apa yang akan disentuh, tanpa mengubah apa pun:

```bash
cd /var/www/aptpairport.id/survei
sudo ./install.sh --check
```

Lalu pasang. Karena akar domain masih dipakai situs lama, `--replace-existing`
wajib — tanpa itu skrip sengaja berhenti:

```bash
sudo ./install.sh --replace-existing
```

Yang dikerjakan skrip:

| Langkah | Isi |
|---|---|
| 1 | Cek nginx/php/git, ekstensi PHP, batas upload, socket & user PHP-FPM |
| 2 | Nonaktifkan server block lain yang memakai `aptpairport.id` (di-`mv` ke `*.disabled-*`, tidak dihapus) |
| 3 | Pastikan kode lengkap; clone kalau folder masih kosong |
| 4 | Verifikasi `survei.env`, pasang izin `640 root:www-data` |
| 5 | Sambung database, cek 3 tabel, impor skema kalau kurang |
| 6 | Izin berkas: kode read-only bagi PHP-FPM, hanya `uploads/` yang writable |
| 7 | Tulis server block Nginx untuk akar domain, `nginx -t`, reload |
| 8 | Uji HTTP: login, endpoint upload, berkas sensitif terblokir, `uploads/` tidak mengeksekusi PHP |

Kalau `nginx -t` gagal, skrip melepas symlink-nya lagi — Nginx tidak pernah
ditinggal dalam keadaan rusak.

---

## 6. HTTPS

Karena sertifikat belum ada, langkah 5 sengaja memasang server block
**HTTP dulu** — blok `listen 443 ssl` tanpa sertifikat membuat `nginx -t`
gagal. Naikkan ke HTTPS:

```bash
sudo certbot --nginx -d aptpairport.id -d www.aptpairport.id
sudo nginx -t && sudo systemctl reload nginx
```

Lalu ulangi verifikasi, kali ini lewat HTTPS:

```bash
sudo ./install.sh --check
```

> Sertifikat lama milik situs Next.js tetap dipakai kalau domainnya sama —
> certbot hanya memasangkannya ke server block yang baru.
>
> Server block referensi versi HTTPS penuh (redirect `www` → non-`www`,
> header keamanan, cache aset) ada di
> [`deploy/nginx-aptpairport.id.conf`](deploy/nginx-aptpairport.id.conf).
> `install.sh` memakainya otomatis kalau sertifikat sudah ada saat dijalankan.

---

## 7. Verifikasi manual

Yang tidak bisa dicek skrip — lewat browser:

1. Buka `https://aptpairport.id/` → harus mendarat di halaman login admin.
2. Login dengan `ADMIN_USER` / `ADMIN_PASS`. **Logo harus tampil** (bukan
   ikon gambar rusak) — itu bukti aset sudah lepas dari situs lama.
3. Buat satu survei → tab **Editor** → tambah pertanyaan → **Unggah Gambar**
   pada blok Gambar Ilustrasi.
4. **Simpan → muat ulang halaman → gambar harus masih ada.** Simpan sekali
   lagi, muat ulang lagi, masih ada. Ini uji terpenting: editor membangun
   ulang seluruh data pertanyaan tiap kali menyimpan.
5. Ubah satu pertanyaan jadi tipe **Unggah Gambar (Jawaban)** → Simpan.
6. Buka tautan publik survei di jendela penyamaran → isi → unggah foto → kirim.
7. Kembali ke admin → tab **Hasil** → **Detail** → thumbnail muncul.
8. **Export Excel** → kolom foto berisi tautan yang bisa dibuka.
9. Buka URL ngawur, mis. `https://aptpairport.id/tidak-ada` → halaman 404
   berlogo, bukan 404 polos bawaan Nginx.

Dan satu pembersihan terakhir:

```bash
sudo rm -f /var/www/aptpairport.id/survei/.env    # kredensial cukup satu, di luar docroot
```

---

## 8. Rollback

Kembalikan situs lama:

```bash
sudo rm -f /etc/nginx/sites-enabled/aptpairport.id

# aktifkan lagi server block yang tadi dinonaktifkan
ls /etc/nginx/sites-available/*.disabled-*
sudo mv /etc/nginx/sites-available/NAMA.disabled-XXXX \
        /etc/nginx/sites-available/NAMA
sudo ln -s ../sites-available/NAMA /etc/nginx/sites-enabled/

sudo nginx -t && sudo systemctl reload nginx
```

Database tidak perlu di-rollback: instalasi ini tidak menghapus atau mengubah
tabel apa pun.

---

## Masalah yang sering muncul

| Gejala | Penyebab | Solusi |
|---|---|---|
| `502 Bad Gateway` | `fastcgi_pass` menunjuk socket PHP-FPM yang salah | `ls /run/php/*.sock`, lalu ulangi `install.sh --php-sock PATH` |
| Semua halaman jadi unduhan file `.php` | Blok `location ~ \.php$` tidak aktif | Cek `include snippets/fastcgi-php.conf` ada di server block |
| `Koneksi database gagal: …` | `survei.env` tidak terbaca PHP-FPM | `sudo -u www-data cat /var/www/aptpairport.id/survei.env` |
| Login selalu ditolak | `ADMIN_PASS` kosong di `survei.env` | Isi, lalu `systemctl reload php8.2-fpm` |
| `Validasi keamanan (CSRF) gagal` | `ADMIN_PASS` berubah / sesi kedaluwarsa | Muat ulang halaman, login lagi |
| `Gambar tidak dapat diproses server` | Ekstensi GD tidak aktif | `apt install php8.2-gd && systemctl restart php8.2-fpm` |
| `413 Request Entity Too Large` | `client_max_body_size` masih 1 MB | Sudah ada di template; pastikan server block yang aktif memang yang ini |
| Gambar ilustrasi hilang setelah Simpan | Kode lama masih di OPcache | `systemctl reload php8.2-fpm` |
| Perubahan kode tidak terlihat | OPcache | sama seperti di atas |
| Logo tidak tampil | Aset belum ikut ter-clone | `ls -la assets/images/logo-apt.svg` |

---

## Catatan

**Isi `uploads/` tidak masuk git.** `.gitignore` mengecualikan seluruh isinya
kecuali `.htaccess` dan `index.html`, jadi `git pull` tidak akan pernah menimpa
foto pengguna — tapi juga berarti foto tidak ikut ter-backup lewat git.
Masukkan folder itu ke backup rutin, terpisah dari database.

**Jangan hapus `uploads/.htaccess`** meski server memakai Nginx (yang
mengabaikannya). Kalau suatu saat pindah ke Apache atau cPanel, berkas itu
langsung aktif dengan sendirinya.

**Pembersihan berkas otomatis.** Menghapus respons menghapus fotonya;
menghapus survei menghapus seluruh direktori gambarnya; mengganti gambar
ilustrasi lalu menyimpan menghapus berkas lamanya. Tidak ada pembersihan
manual yang perlu dijadwalkan.

**Update berikutnya** cukup lewat `deploy.sh` — defaultnya kini sudah
menunjuk ke tata letak akar domain di atas:

```bash
cd /var/www/aptpairport.id/survei
sudo ./deploy.sh
```
