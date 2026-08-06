# Panduan Update Server — Fitur Upload Gambar

Panduan ini khusus untuk menerapkan fitur **upload gambar pada pertanyaan survei**
(commit `b357823`) ke server produksi.

Fitur yang ditambahkan:

1. **Gambar ilustrasi soal** — admin melampirkan gambar pada sebuah pertanyaan,
   responden hanya melihatnya.
2. **Tipe pertanyaan "Unggah Gambar"** — responden mengunggah foto sebagai jawaban.

---

## ⚠️ Baca ini dulu

### 1. Ada dependensi baru: ekstensi PHP **GD**

Setiap gambar digambar ulang lewat GD sebelum disimpan. Ini yang membuang byte
berbahaya yang mungkin ditempelkan di belakang file gambar yang sah. **Kalau GD
tidak aktif, semua upload akan gagal** dengan pesan
*"Gambar tidak dapat diproses server"*.

### 2. Kalau memakai **Nginx**, `uploads/.htaccess` tidak berfungsi

File `uploads/.htaccess` ikut ter-pull, tapi Nginx **mengabaikannya total**.
Proteksi anti-eksekusi skrip harus dipasang di konfigurasi Nginx
(lihat [Langkah 4](#4-konfigurasi-web-server)). Jangan dilewati.

Di Apache, `.htaccess` langsung aktif dan tidak perlu langkah tambahan.

### 3. Yang **tidak** perlu dilakukan

| Hal | Status |
|---|---|
| Migrasi database | ❌ Tidak perlu — skema tabel tidak berubah sama sekali |
| Key `.env` baru | ❌ Tidak perlu — tidak ada variabel lingkungan baru |
| Composer / npm install | ❌ Tidak perlu — proyek ini PHP native tanpa dependensi |
| Perubahan pada survei lama | ❌ Tidak ada — survei & respons lama tetap berjalan normal |

---

## 1. Pre-flight — periksa sebelum pull

```bash
# Ekstensi GD (WAJIB)
php -m | grep -i '^gd$' || echo ">>> GD TIDAK ADA — jangan lanjut, install dulu"

# Pastikan format gambar yang dibutuhkan didukung
php -r 'print_r(array_intersect_key(gd_info(), array_flip(["JPEG Support","PNG Support","WebP Support"])));'

# Batas upload PHP
php -i | grep -E '^(upload_max_filesize|post_max_size|memory_limit|file_uploads)'

# User yang menjalankan PHP (dibutuhkan di langkah 3)
ps aux | grep -E '[p]hp-fpm' | head -3
```

Kalau GD belum ada — sesuaikan versi PHP-nya:

```bash
sudo apt update && sudo apt install -y php8.2-gd
sudo systemctl restart php8.2-fpm
```

**Nilai minimum yang dibutuhkan:**

| Setting | Minimum | Alasan |
|---|---|---|
| `file_uploads` | `On` | — |
| `upload_max_filesize` | `5M` | Batas ukuran foto di aplikasi |
| `post_max_size` | `6M` | **Harus lebih besar** dari `upload_max_filesize` |
| `memory_limit` | `128M` | GD memuat bitmap penuh ke memori saat re-encode |

> Aplikasi sudah mengecilkan foto di sisi browser (maks 1600 px, JPEG 85%)
> sebelum dikirim, jadi foto HP 6 MB biasanya menyusut jadi ~300 KB. Batas di
> atas adalah jaring pengaman, bukan ukuran yang biasa terjadi.

---

## 2. Backup

```bash
cd /path/ke/proyek        # ganti dengan path sebenarnya

# Catat versi sekarang untuk rollback
git rev-parse HEAD > ~/rollback-commit.txt

# Backup database
mysqldump -u USER -p NAMA_DB > ~/backup-$(date +%F).sql

# .env tidak ikut git — jangan sampai hilang
cp .env ~/env-backup
```

---

## 3. Pull & siapkan folder upload

```bash
cd /path/ke/proyek

git status                # pastikan bersih; kalau ada perubahan lokal, `git stash` dulu
git pull origin main
git log --oneline -1      # harus menampilkan b357823
```

```bash
mkdir -p uploads

# Ganti www-data dengan user php-fpm hasil pengecekan di langkah 1
sudo chown -R www-data:www-data uploads
sudo chmod -R 755 uploads
```

Subfolder `uploads/illustrations/` dan `uploads/responses/` **tidak perlu dibuat
manual** — endpoint membuatnya otomatis saat upload pertama.

**File baru yang masuk:** `image_lib.php`, `upload_image.php`,
`uploads/.htaccess`, `uploads/index.html`.

---

## 4. Konfigurasi web server

### Kalau memakai Nginx (wajib dikerjakan)

Buka server block Anda (`/etc/nginx/sites-available/NAMA_SITE`), lalu tambahkan
**di dalam blok `server { ... }`**, sebelum `location ~ \.php$` yang sudah ada:

```nginx
    # Default Nginx hanya 1 MB — upload 5 MB akan ditolak dengan
    # error 413 sebelum PHP sempat melihatnya sama sekali.
    client_max_body_size 6M;

    # Folder upload: sajikan file statis, TOLAK eksekusi skrip apa pun.
    # Tanda "^~" penting. Tanpa itu, "location ~ \.php$" yang global
    # tetap menang dan file .php di dalam uploads/ akan tereksekusi.
    location ^~ /uploads/ {
        location ~* \.(php|phtml|phar|phps|cgi|pl|py|sh)$ {
            deny all;
        }
        add_header X-Content-Type-Options "nosniff" always;
        try_files $uri =404;
    }
```

```bash
sudo nginx -t                     # harus "syntax is ok" + "test is successful"
sudo systemctl reload nginx
```

### Kalau memakai Apache

`uploads/.htaccess` sudah aktif otomatis. Pastikan saja `AllowOverride`
mengizinkannya:

```bash
sudo apache2ctl -S                # lihat file konfigurasi vhost yang aktif
grep -r "AllowOverride" /etc/apache2/sites-enabled/
```

Nilainya harus `All` (atau minimal `Limit Options FileInfo`) untuk direktori
proyek. Kalau `None`, `.htaccess` diabaikan dan Anda perlu memindahkan isinya
ke konfigurasi vhost.

---

## 5. Reload PHP (jangan dilewati)

```bash
sudo systemctl reload php8.2-fpm      # sesuaikan versi PHP
```

Wajib dilakukan kalau OPcache aktif — tanpa ini PHP masih menjalankan versi
kode yang lama dan Anda akan mengira update-nya gagal.

---

## 6. Verifikasi

```bash
cd /path/ke/proyek
DOMAIN="https://domain-anda.com"      # ganti
```

### a. File baru ada

```bash
ls -la image_lib.php upload_image.php uploads/
```

### b. Folder upload bisa ditulis

```bash
sudo -u www-data test -w uploads && echo "OK writable" || echo ">>> TIDAK writable, perbaiki chown"
```

### c. Endpoint hidup dan membalas JSON

```bash
curl -s "$DOMAIN/upload_image.php" | head -c 200; echo
```

Harus menghasilkan:
```json
{"success":false,"message":"Metode tidak diizinkan."}
```

Kalau yang muncul HTML, berarti ada error PHP — cek log error server.

### d. 🔒 Proteksi folder upload — uji paling penting

```bash
echo '<?php echo "TEREKSEKUSI"; ?>' > uploads/cek.php
curl -s "$DOMAIN/uploads/cek.php"
rm uploads/cek.php
```

| Hasil | Arti |
|---|---|
| `403 Forbidden` | ✅ Aman |
| Teks mentah `<?php echo ...` | ✅ Aman |
| Muncul kata `TEREKSEKUSI` | ❌ **BAHAYA** — konfigurasi langkah 4 belum benar, ulangi |

### e. Gambar statis tetap tersaji

```bash
curl -s -o /dev/null -w "%{http_code} %{content_type}\n" "$DOMAIN/uploads/index.html"
```

### f. Uji lewat browser

1. Login admin → buka sebuah survei → tab **Editor**.
2. Tambah pertanyaan → pada blok **Gambar Ilustrasi**, klik **Unggah Gambar**.
3. Klik **Simpan Perubahan** → **muat ulang halaman** → gambar harus masih ada.
   Simpan sekali lagi → muat ulang lagi → masih ada.
   > Ini uji paling penting. Editor membangun ulang seluruh data pertanyaan
   > dari tampilan setiap kali menyimpan, jadi kalau gambar hilang di sini
   > berarti ada yang salah — gejalanya mirip "upload gagal" padahal
   > uploadnya sendiri berhasil.
4. Ubah tipe salah satu pertanyaan jadi **Unggah Gambar (Jawaban)** → Simpan.
5. Buka form publik → isi → unggah foto → kirim.
6. Kembali ke admin → tab **Hasil** → tombol **Detail** → thumbnail foto muncul.
7. Klik **Export Excel** → kolom foto berisi tautan yang bisa dibuka.

---

## 7. Rollback bila bermasalah

```bash
cd /path/ke/proyek
git reset --hard $(cat ~/rollback-commit.txt)
sudo systemctl reload php8.2-fpm
```

Konfigurasi web server dari langkah 4 aman ditinggalkan — tidak berpengaruh
apa pun kalau folder `uploads/` kosong.

---

## Masalah yang sering muncul

| Gejala | Penyebab | Solusi |
|---|---|---|
| `Gambar tidak dapat diproses server` | Ekstensi GD tidak aktif | Langkah 1 |
| `413 Request Entity Too Large` | `client_max_body_size` Nginx masih 1 MB | Langkah 4 |
| `Direktori upload tidak bisa dibuat` | `uploads/` tidak writable oleh php-fpm | Langkah 3 |
| `Server tidak memiliki folder sementara untuk upload` | `upload_tmp_dir` tidak writable | Cek `php -i \| grep upload_tmp_dir`, pastikan foldernya ada & writable |
| Gambar ilustrasi hilang setelah Simpan | Kode lama masih di OPcache | Langkah 5 |
| `Validasi keamanan (CSRF) gagal` | `ADMIN_PASS` di `.env` berubah, atau sesi kedaluwarsa | Muat ulang halaman dan login lagi |
| Upload sukses tapi gambar tidak tampil | Web server memblokir seluruh isi `uploads/` | Pastikan hanya ekstensi skrip yang di-`deny`, bukan semua file |

---

## Catatan

**Jangan hapus `uploads/.htaccess`** meski server Anda memakai Nginx. Kalau
suatu saat pindah ke Apache atau cPanel, file itu langsung aktif dengan
sendirinya.

**File `uploads/` tidak masuk git.** `.gitignore` mengecualikan seluruh isinya
kecuali `.htaccess` dan `index.html`. Jadi `git pull` tidak akan pernah
menimpa foto yang sudah diunggah pengguna — tapi juga berarti foto tidak
ikut ter-backup lewat git. Sertakan folder `uploads/` dalam skema backup
rutin Anda, terpisah dari database.

**Pembersihan file otomatis.** Menghapus respons akan menghapus fotonya;
menghapus survei akan menghapus seluruh direktori gambarnya; mengganti gambar
ilustrasi lalu menyimpan akan menghapus file lamanya. Tidak ada langkah
pembersihan manual yang perlu dijadwalkan.

**Kalau ada CDN/Cloudflare di depan**, batas ukuran unggahannya juga berlaku.
Cloudflare paket gratis membatasi 100 MB, jadi 5 MB aman.
