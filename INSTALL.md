# Instalasi dari Nol — Survei APT Pranoto di `https://aptpairport.id/survei/`

Panduan ini memasang aplikasi **dari awal** sebagai **sub-path** di bawah situs
utama. Situs di `aptpairport.id/` **tidak disentuh sama sekali**.

Untuk sekadar meng-update instalasi yang sudah jalan, pakai
[`DEPLOY.md`](DEPLOY.md) + `deploy.sh` — bukan panduan ini.

---

## ⚠️ Baca ini dulu

**1. Situs utama tetap utuh.** `install.sh` hanya **menyisipkan** satu blok
`location ^~ /survei/` ke server block yang sudah melayani domain ini. Blok
`location /` yang ada — mau `proxy_pass` ke Next.js atau berkas statis — tidak
diubah. Berkas aslinya di-backup dulu, dan kalau `nginx -t` gagal, config
dikembalikan otomatis sebelum Nginx sempat di-reload.

**2. Logo sudah dibawa ke dalam repo.** Sebelumnya semua halaman menarik logo
dari `https://aptpairport.id/assets_landing/img/logo/logo-apt.svg` — URL itu
**sudah 404**. Logo sekarang ada di `assets/images/logo-apt.svg` dan disajikan
dari repo ini lewat path relatif.

**3. Database dianggap sudah ada.** Skrip hanya menyambung, memeriksa tabel,
dan mengimpor `database/database.sql` kalau ada tabel yang belum terbentuk
(semua `CREATE TABLE IF NOT EXISTS` — data lama tidak tersentuh).

**4. Kredensial disimpan di luar document root**, di `/var/www/survei.env`.
`db.php` mencari berkas bernama `survei.env` di folder-folder induk sebelum
jatuh ke `.env` di dalam folder aplikasi.

---

## Tata letak yang dihasilkan

```
/var/www/
├── survei.env                    <- kredensial, 640 root:www-data, di luar semua docroot
└── aptpairport.id/               <- "root" yang dipakai blok /survei/
    ├── (situs utama, tidak disentuh)
    └── survei/                   <- isi repo ini
        ├── index.php
        ├── uploads/              <- satu-satunya folder writable oleh PHP-FPM
        ├── errors/
        └── deploy/nginx-survei-subpath.conf
```

Nama folder terakhir **wajib** `survei`, sama dengan segmen URL-nya. Dengan
begitu `root /var/www/aptpairport.id` memetakan `/survei/x.php` ke
`/var/www/aptpairport.id/survei/x.php` dengan sendirinya — tanpa `alias`, yang
merusak `$document_root` sehingga `SCRIPT_FILENAME` milik PHP jadi salah.

| URL | Isi |
|---|---|
| `https://aptpairport.id/` | Situs utama — **tidak berubah** |
| `https://aptpairport.id/survei/` | Form survei (`?id=N`); tanpa `id` → dialihkan ke login admin |
| `https://aptpairport.id/survei/login.php` | Login admin |
| `https://aptpairport.id/survei/admin.php` | Dashboard admin |
| `https://aptpairport.id/survei/uploads/…` | Gambar; berkas skrip ditolak 403 |

---

## 1. Prasyarat server

```bash
sudo apt update
sudo apt install -y git curl php-fpm php-mysql php-gd php-mbstring mysql-client
```

Nginx dan sertifikatnya sudah ada (situs utama sudah jalan). Yang perlu dicek:

```bash
php -v
ls /run/php/*.sock                                  # catat nama socket-nya
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

> `client_max_body_size 6M` diurus oleh blok yang disisipkan, dan hanya
> berlaku untuk `/survei/` — situs utama tidak ikut berubah batasnya.

---

## 2. Ambil kode

```bash
sudo git clone https://github.com/elband/survei-multi-aptp.git \
     /var/www/aptpairport.id/survei
cd /var/www/aptpairport.id/survei
git log --oneline -1
```

**Kalau folder itu sudah ada** (ada clone lama di sana), perbarui saja:

```bash
cd /var/www/aptpairport.id/survei
git remote -v                 # harus elband/survei-multi-aptp
git status                    # kalau ada perubahan lokal: git stash
git pull --ff-only
git log --oneline -1
```

---

## 3. Kredensial

```bash
sudo tee /var/www/survei.env > /dev/null <<'EOF'
DB_HOST=localhost
DB_PORT=3306
DB_USER=GANTI_USER
DB_PASSWORD=GANTI_PASSWORD
DB_NAME=GANTI_NAMA_DB

ADMIN_USER=admin
ADMIN_PASS=GANTI_PASSWORD_ADMIN

GEMINI_API_KEY=
EOF

sudo chown root:www-data /var/www/survei.env
sudo chmod 640 /var/www/survei.env
```

Nama berkasnya **harus** `survei.env` — itu yang dicari `db.php` di folder
induk. Kalau ingin nama/lokasi lain, set `SURVEI_ENV_PATH` di pool PHP-FPM:

```ini
; /etc/php/8.2/fpm/pool.d/www.conf
env[SURVEI_ENV_PATH] = /etc/survei/env
```

> `ADMIN_PASS` juga jadi bahan token CSRF. Menggantinya memaksa semua admin
> login ulang — itu normal.

---

## 4. Database

Database dianggap sudah ada. Pastikan bisa disambungi:

```bash
mysql -u GANTI_USER -p -e "SHOW TABLES;" GANTI_NAMA_DB
```

Yang dibutuhkan: `surveys`, `survey_responses`, `audit_logs`. Kalau ada yang
belum terbentuk, `install.sh` mengimpornya sendiri dari `database/database.sql`.

Backup dulu sebelum lanjut:

```bash
mysqldump -u GANTI_USER -p --single-transaction GANTI_NAMA_DB \
    > ~/backup-$(date +%F).sql
```

---

## 5. Jalankan installer

Lihat dulu apa yang akan disentuh, tanpa mengubah apa pun:

```bash
cd /var/www/aptpairport.id/survei
sudo ./install.sh --check
```

Perhatikan dua baris ini di keluarannya:

- **Socket PHP-FPM** — menentukan `fastcgi_pass`. Kalau salah, hasilnya `502`.
- **Server block** — berkas yang akan disisipi. Pastikan itu memang server
  block `aptpairport.id`, bukan situs lain.

Lalu pasang:

```bash
sudo ./install.sh
```

Yang dikerjakan skrip:

| Langkah | Isi |
|---|---|
| 1 | Cek php/git, ekstensi PHP, batas upload, socket & user PHP-FPM |
| 2 | Pastikan kode lengkap; clone kalau folder masih kosong |
| 3 | Verifikasi `/var/www/survei.env`, pasang izin `640 root:www-data` |
| 4 | Sambung database, cek 3 tabel, impor skema kalau kurang |
| 5 | Izin berkas: kode read-only bagi PHP-FPM, hanya `uploads/` yang writable |
| 6 | Sisipkan blok `location ^~ /survei/`, `nginx -t`, reload |
| 7 | Uji HTTP: situs utama masih hidup, login, endpoint upload, berkas sensitif terblokir, `uploads/` menolak `.php` |

Skrip **idempoten** — aman dijalankan berulang. Blok lama dikenali lewat
penanda `# ===== survei: mulai =====` dan diganti, bukan ditumpuk.

Kalau server block-nya tidak terdeteksi otomatis:

```bash
sudo ./install.sh --site /etc/nginx/sites-available/NAMA_SITE
```

---

## 6. Verifikasi manual

Yang tidak bisa dicek skrip — lewat browser:

1. Buka `https://aptpairport.id/` → **situs utama harus persis seperti
   sebelumnya**. Ini yang pertama diperiksa.
2. Buka `https://aptpairport.id/survei/` → mendarat di halaman login admin.
3. Login dengan `ADMIN_USER` / `ADMIN_PASS`. **Logo harus tampil** (bukan ikon
   gambar rusak) — itu bukti aset sudah lepas dari URL lama yang 404.
4. Buat satu survei → tab **Editor** → tambah pertanyaan → **Unggah Gambar**
   pada blok Gambar Ilustrasi.
5. **Simpan → muat ulang halaman → gambar harus masih ada.** Simpan sekali
   lagi, muat ulang lagi, masih ada. Ini uji terpenting: editor membangun
   ulang seluruh data pertanyaan tiap kali menyimpan.
6. Ubah satu pertanyaan jadi tipe **Unggah Gambar (Jawaban)** → Simpan.
7. Buka tautan publik survei di jendela penyamaran → isi → unggah foto → kirim.
8. Kembali ke admin → tab **Hasil** → **Detail** → thumbnail muncul.
9. **Export Excel** → kolom foto berisi tautan yang bisa dibuka.
10. Buka `https://aptpairport.id/survei/tidak-ada` → halaman 404 berlogo.

Dan satu pembersihan terakhir:

```bash
sudo rm -f /var/www/aptpairport.id/survei/.env   # kredensial cukup satu, di luar docroot
```

---

## 7. Rollback

Situs utama tidak pernah diubah, jadi rollback cukup membuang blok survei:

```bash
ls /etc/nginx/sites-available/*.bak-*
sudo cp /etc/nginx/sites-available/NAMA.bak-XXXX /etc/nginx/sites-available/NAMA
sudo nginx -t && sudo systemctl reload nginx
```

Database tidak perlu di-rollback: instalasi ini tidak menghapus atau mengubah
tabel apa pun.

---

## Masalah yang sering muncul

| Gejala | Penyebab | Solusi |
|---|---|---|
| `502 Bad Gateway` di `/survei/` | `fastcgi_pass` menunjuk socket PHP-FPM yang salah | `ls /run/php/*.sock`, lalu `sudo ./install.sh --php-sock PATH` |
| `/survei/` malah membuka situs utama / 404 | Blok tersisip ke server block yang salah | Ulangi dengan `--site` yang benar |
| Halaman jadi unduhan berkas `.php` | Blok PHP tidak aktif | Pastikan `include snippets/fastcgi-php.conf` ada di blok yang disisipkan |
| `Koneksi database gagal: …` | `survei.env` tidak terbaca PHP-FPM | `sudo -u www-data cat /var/www/survei.env` |
| Login selalu ditolak | `ADMIN_PASS` kosong di `survei.env` | Isi, lalu `systemctl reload php8.2-fpm` |
| `Validasi keamanan (CSRF) gagal` | `ADMIN_PASS` berubah / sesi kedaluwarsa | Muat ulang halaman, login lagi |
| `Gambar tidak dapat diproses server` | Ekstensi GD tidak aktif | `apt install php8.2-gd && systemctl restart php8.2-fpm` |
| `413 Request Entity Too Large` | `client_max_body_size` belum ikut tersisip | Cek blok `/survei/` di server block; nilainya harus `6M` |
| Gambar ilustrasi hilang setelah Simpan | Kode lama masih di OPcache | `systemctl reload php8.2-fpm` |
| Logo tidak tampil | Aset belum ikut ter-pull | `ls -la assets/images/logo-apt.svg` |

---

## Catatan teknis

**Aturan deny di `uploads/` wajib bersarang.** Blok `location ^~
/survei/uploads/` adalah prefix terpanjang dan bertanda `^~`, sehingga Nginx
**melewati seluruh location regex yang sejajar dengannya** — termasuk aturan
deny. Diuji: versi sejajar menyajikan `.php` di `uploads/` sebagai teks mentah
dengan HTTP 200. Karena itu aturannya diletakkan **di dalam** blok uploads,
dan hasilnya 403. `install.sh` menguji ini otomatis di langkah 7 dan menolak
menyelesaikan instalasi kalau uji itu gagal.

**Blok PHP tidak memasang `try_files` sendiri**, karena
`snippets/fastcgi-php.conf` bawaan Debian sudah memuatnya. Menambahkannya
membuat `nginx -t` gagal dengan `"try_files" directive is duplicate`.

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

**Update berikutnya** cukup lewat `deploy.sh`:

```bash
cd /var/www/aptpairport.id/survei
sudo ./deploy.sh --project /var/www/aptpairport.id/survei
```
