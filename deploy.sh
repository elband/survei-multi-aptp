#!/usr/bin/env bash
#
# deploy.sh — Deploy fitur upload gambar ke server produksi (Nginx + PHP-FPM)
#
# Menjalankan langkah-langkah di DEPLOY.md secara otomatis: pre-flight check,
# backup, pull, penyiapan folder uploads, konfigurasi Nginx, reload PHP-FPM,
# lalu verifikasi — termasuk uji keamanan anti-eksekusi skrip di uploads/.
#
# Skrip ini idempoten: aman dijalankan berulang kali.
#
# Pemakaian:
#   sudo ./deploy.sh                          # jalankan semua, config Nginx hanya diperiksa
#   sudo ./deploy.sh --patch-nginx            # sekalian tulis config Nginx otomatis
#   sudo ./deploy.sh --skip-pull              # kalau git pull sudah dilakukan manual
#   sudo ./deploy.sh --check                  # hanya periksa & verifikasi, tanpa mengubah apa pun
#
# Opsi lain:
#   --project DIR     path proyek yang dilayani Nginx
#                     (default: /var/www/aptpairport.id/survei)
#   --url-path P      sub-path URL aplikasi (default: /survei)
#   --domain URL      base URL verifikasi (default: dibaca dari server_name Nginx)
#   --site FILE       file server block Nginx (default: dideteksi dari location ^~)
#   --skip-backup     lewati backup tar + mysqldump
#
set -euo pipefail

# ---------------------------------------------------------------- konfigurasi
# Folder aplikasi. install.sh memasang tata letak ini: Nginx memakai
# "root /var/www/aptpairport.id" di dalam blok "location ^~ /survei/",
# jadi nama folder terakhir harus sama dengan segmen URL-nya.
PROJECT_DIR="/var/www/aptpairport.id/survei"

# Path URL aplikasi — aplikasi berada di sub-path, bukan di akar domain.
URL_PATH="/survei"

DOMAIN=""
SITE_FILE=""
PATCH_NGINX=0
SKIP_PULL=0
SKIP_BACKUP=0
CHECK_ONLY=0
BACKUP_DIR="/root/survei-backup"

MIN_UPLOAD_MB=5      # upload_max_filesize minimum
MIN_POST_MB=6        # post_max_size minimum
MIN_MEMORY_MB=128    # memory_limit minimum

# ------------------------------------------------------------------- tampilan
if [[ -t 1 ]]; then
    R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[34m'; BOLD=$'\e[1m'; N=$'\e[0m'
else
    R=""; G=""; Y=""; B=""; BOLD=""; N=""
fi

WARNINGS=0
step() { printf '\n%s==> %s%s\n' "$BOLD$B" "$*" "$N"; }
ok()   { printf '  %s✓%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; WARNINGS=$((WARNINGS + 1)); }
die()  { printf '\n  %s✗ %s%s\n\n' "$R" "$*" "$N" >&2; exit 1; }
info() { printf '    %s\n' "$*"; }

# --------------------------------------------------------------------- argumen
while [[ $# -gt 0 ]]; do
    case "$1" in
        --project)      PROJECT_DIR="${2:?--project butuh path}"; shift 2 ;;
        --domain)       DOMAIN="${2:?--domain butuh URL}"; shift 2 ;;
        --url-path)     URL_PATH="/${2#/}"; URL_PATH="${URL_PATH%/}"; shift 2 ;;
        --site)         SITE_FILE="${2:?--site butuh path}"; shift 2 ;;
        --patch-nginx)  PATCH_NGINX=1; shift ;;
        --skip-pull)    SKIP_PULL=1; shift ;;
        --skip-backup)  SKIP_BACKUP=1; shift ;;
        --check)        CHECK_ONLY=1; SKIP_PULL=1; SKIP_BACKUP=1; shift ;;
        -h|--help)      sed -n '2,25p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)              die "Opsi tidak dikenal: $1  (pakai --help)" ;;
    esac
done

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo $0 $*"
[[ -d "$PROJECT_DIR" ]] || die "Direktori proyek tidak ada: $PROJECT_DIR"
cd "$PROJECT_DIR"

TMPDIR_DEPLOY="$(mktemp -d)"
CANARY=""
cleanup() {
    [[ -n "$CANARY" && -f "$CANARY" ]] && rm -f "$CANARY"
    rm -rf "$TMPDIR_DEPLOY"
}
trap cleanup EXIT

printf '%s\n' "$BOLD┌───────────────────────────────────────────────┐$N"
printf '%s\n' "$BOLD│  Deploy Survei APT Pranoto — Nginx + PHP-FPM  │$N"
printf '%s\n' "$BOLD└───────────────────────────────────────────────┘$N"
info "Proyek : $PROJECT_DIR"
info "URL    : ${URL_PATH}/"
[[ $CHECK_ONLY -eq 1 ]] && info "Mode   : ${Y}CHECK ONLY — tidak ada yang diubah${N}"

# ============================================================ 1. PRE-FLIGHT
step "1/7  Pre-flight"

command -v php   >/dev/null || die "php CLI tidak ditemukan"
command -v nginx >/dev/null || die "nginx tidak ditemukan — skrip ini khusus Nginx"
command -v git   >/dev/null || die "git tidak ditemukan"

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
ok "PHP CLI $PHP_VER"

# Ekstensi GD — tanpa ini semua upload gagal
if php -m | grep -qix gd; then
    GD_MISSING="$(php -r '
        $i = gd_info();
        $need = ["JPEG Support", "PNG Support", "WebP Support"];
        $miss = [];
        foreach ($need as $k) { if (empty($i[$k])) $miss[] = $k; }
        echo implode(", ", $miss);
    ')"
    if [[ -n "$GD_MISSING" ]]; then
        warn "GD aktif tapi format belum lengkap: $GD_MISSING"
    else
        ok "GD aktif (JPEG, PNG, WebP)"
    fi
else
    printf '\n'
    info "GD belum aktif. Pasang dulu, lalu jalankan ulang skrip ini:"
    info "  sudo apt update && sudo apt install -y php${PHP_VER}-gd"
    info "  sudo systemctl restart php${PHP_VER}-fpm"
    die "Ekstensi PHP GD WAJIB ada — semua upload akan gagal tanpa ini"
fi

# Batas upload PHP. CLI dan FPM bisa beda php.ini, jadi keduanya diperiksa.
to_mb() {
    local v="${1,,}" n="${1//[!0-9]/}"
    case "$v" in
        *g) echo $((n * 1024)) ;;
        *m) echo "$n" ;;
        *k) echo $((n / 1024)) ;;
        -1) echo 999999 ;;
        *)  echo $((n / 1048576)) ;;
    esac
}
check_ini() {
    local key="$1" min="$2" val cur
    val="$(php -r "echo ini_get('$key');")"
    cur="$(to_mb "$val")"
    if [[ "$cur" -lt "$min" ]]; then
        warn "$key = $val (minimum ${min}M) — perbaiki di php.ini FPM lalu reload"
    else
        ok "$key = $val"
    fi
}
[[ "$(php -r 'echo ini_get("file_uploads") ? 1 : 0;')" == "1" ]] \
    || warn "file_uploads = Off — upload akan selalu gagal"
check_ini upload_max_filesize "$MIN_UPLOAD_MB"
check_ini post_max_size       "$MIN_POST_MB"
check_ini memory_limit        "$MIN_MEMORY_MB"

# Service & user PHP-FPM
FPM_SERVICE="$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null \
    | awk '{print $1}' | grep -E '^php.*fpm\.service$' | head -1 || true)"
[[ -z "$FPM_SERVICE" ]] && FPM_SERVICE="php${PHP_VER}-fpm.service"
if systemctl list-unit-files "$FPM_SERVICE" >/dev/null 2>&1; then
    ok "Service PHP-FPM: $FPM_SERVICE"
else
    warn "Service $FPM_SERVICE tidak terdaftar — reload di langkah 6 mungkin gagal"
fi

FPM_USER="$(ps -eo user:32,comm | awk '$2 ~ /^php-fpm/ && $1 != "root" {print $1; exit}' || true)"
[[ -z "$FPM_USER" ]] && FPM_USER="www-data"
id "$FPM_USER" >/dev/null 2>&1 || die "User PHP-FPM '$FPM_USER' tidak ada di sistem"
ok "User PHP-FPM: $FPM_USER"

# .env — tidak ikut git, jangan sampai hilang
[[ -f .env ]] && ok ".env ditemukan" || warn ".env tidak ada — koneksi database akan pakai nilai default"

# ============================================================== 2. BACKUP
step "2/7  Backup"

if [[ $SKIP_BACKUP -eq 1 ]]; then
    info "dilewati (--skip-backup / --check)"
else
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    STAMP="$(date +%F-%H%M%S)"

    git rev-parse HEAD > "$BACKUP_DIR/rollback-commit.txt"
    ok "Commit sekarang dicatat: $(cut -c1-8 < "$BACKUP_DIR/rollback-commit.txt") → $BACKUP_DIR/rollback-commit.txt"

    tar czf "$BACKUP_DIR/files-$STAMP.tar.gz" \
        -C "$(dirname "$PROJECT_DIR")" "$(basename "$PROJECT_DIR")"
    ok "Backup file: $BACKUP_DIR/files-$STAMP.tar.gz ($(du -h "$BACKUP_DIR/files-$STAMP.tar.gz" | cut -f1))"

    [[ -f .env ]] && { cp .env "$BACKUP_DIR/env-$STAMP"; chmod 600 "$BACKUP_DIR/env-$STAMP"; ok "Backup .env"; }

    # Kredensial dibaca dari .env, bukan diketik manual
    env_get() {
        [[ -f .env ]] || return 0
        sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" .env \
            | head -1 | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
    }
    DB_NAME="$(env_get DB_NAME)"; : "${DB_NAME:=survei_apt_pranoto_multi}"
    DB_USER="$(env_get DB_USER)"; : "${DB_USER:=root}"
    DB_PASS="$(env_get DB_PASSWORD)"
    DB_HOST="$(env_get DB_HOST)"; : "${DB_HOST:=localhost}"
    DB_PORT="$(env_get DB_PORT)"; : "${DB_PORT:=3306}"

    if command -v mysqldump >/dev/null; then
        # Password lewat file, bukan argumen — argumen terlihat di `ps`
        MYCNF="$TMPDIR_DEPLOY/my.cnf"
        umask 077
        cat > "$MYCNF" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF
        if mysqldump --defaults-extra-file="$MYCNF" \
             --single-transaction --quick "$DB_NAME" > "$BACKUP_DIR/db-$STAMP.sql" 2>"$TMPDIR_DEPLOY/dump.err"; then
            ok "Backup database: $BACKUP_DIR/db-$STAMP.sql ($(du -h "$BACKUP_DIR/db-$STAMP.sql" | cut -f1))"
        else
            rm -f "$BACKUP_DIR/db-$STAMP.sql"
            warn "mysqldump gagal: $(head -1 "$TMPDIR_DEPLOY/dump.err")"
            info "Update ini tidak mengubah skema database, jadi tidak fatal — tapi sebaiknya backup manual."
        fi
    else
        warn "mysqldump tidak ada — backup database dilewati"
    fi
fi

# ================================================================ 3. PULL
step "3/7  Ambil kode terbaru"

if [[ $SKIP_PULL -eq 1 ]]; then
    info "dilewati (--skip-pull / --check)"
    ok "HEAD saat ini: $(git log --oneline -1)"
else
    if [[ -n "$(git status --porcelain --untracked-files=no)" ]]; then
        printf '\n'
        git status --short
        printf '\n'
        info "Ada perubahan lokal yang belum di-commit. Amankan dulu:"
        info "  git stash push -m 'sebelum deploy'   # simpan"
        info "  git diff > /root/editan-lokal.patch  # atau catat ke file"
        die "Working tree tidak bersih — git pull dibatalkan"
    fi
    git pull --ff-only origin main
    ok "HEAD sekarang: $(git log --oneline -1)"
fi

for f in image_lib.php upload_image.php uploads/.htaccess; do
    [[ -f "$f" ]] || die "File '$f' tidak ada — kode fitur upload belum masuk, cek git pull"
done
ok "File fitur upload lengkap"

# ====================================================== 4. FOLDER UPLOADS
step "4/7  Folder uploads"

if [[ $CHECK_ONLY -eq 0 ]]; then
    mkdir -p uploads
    chown -R "$FPM_USER":"$FPM_USER" uploads
    find uploads -type d -exec chmod 755 {} +
    find uploads -type f -exec chmod 644 {} +
    ok "uploads/ → owner $FPM_USER, dir 755, file 644"
fi

if sudo -u "$FPM_USER" test -w uploads; then
    ok "uploads/ writable oleh $FPM_USER"
else
    die "uploads/ TIDAK writable oleh $FPM_USER — upload pasti gagal"
fi
info "Subfolder illustrations/ dan responses/ dibuat otomatis saat upload pertama."

# ======================================================= 5. KONFIG NGINX
step "5/7  Konfigurasi Nginx"

# Cari server block yang memuat 'location ^~ /survei/' (aplikasi ada di sub-path,
# jadi 'root' menunjuk ke folder induk, bukan ke folder proyek).
if [[ -z "$SITE_FILE" ]]; then
    SITE_FILE="$(grep -rlE "location[[:space:]]*\^~[[:space:]]*${URL_PATH}/" \
        /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null | head -1 || true)"
fi

UPLOADS_LOC="${URL_PATH}/uploads/"
HAS_UPLOADS_BLOCK=0
HAS_BODY_SIZE=0
if [[ -n "$SITE_FILE" && -f "$SITE_FILE" ]]; then
    ok "Server block: $SITE_FILE"
    grep -qE "location[[:space:]]*\^~[[:space:]]*${UPLOADS_LOC}" "$SITE_FILE" && HAS_UPLOADS_BLOCK=1
    grep -qE 'client_max_body_size' "$SITE_FILE" && HAS_BODY_SIZE=1
else
    warn "Server block yang melayani ${URL_PATH}/ tidak terdeteksi otomatis"
    info "Tentukan manual dengan: --site /etc/nginx/sites-available/NAMA_SITE"
fi

NGINX_SNIPPET="$TMPDIR_DEPLOY/snippet.conf"
# Heredoc dikutip agar $uri tetap literal; URL_PATH disisipkan setelahnya.
cat > "$NGINX_SNIPPET" <<'SNIPPET'

    # ===== survei: fitur upload gambar (ditambahkan oleh deploy.sh) =====
    # Sajikan file statis di uploads/, TOLAK eksekusi skrip apa pun.
    #
    # Blok ini diletakkan di level server, sejajar dengan "location ^~ @URLPATH@/".
    # Nginx memilih prefix terpanjang, dan "@URLPATH@/uploads/" lebih panjang dari
    # "@URLPATH@/" — jadi blok ini menang tanpa bergantung pada urutan penulisan.
    # Tanda "^~" wajib: tanpa itu "location ~ \.php$" tetap menang dan file .php
    # yang berhasil diselundupkan ke uploads/ akan tereksekusi.
    location ^~ @URLPATH@/uploads/ {
        location ~* \.(php|phtml|phar|phps|cgi|pl|py|sh)$ {
            deny all;
        }
        add_header X-Content-Type-Options "nosniff" always;
        try_files $uri =404;
    }
    # ===== end survei =====
SNIPPET
sed -i "s#@URLPATH@#${URL_PATH}#g" "$NGINX_SNIPPET"

if [[ $HAS_UPLOADS_BLOCK -eq 1 ]]; then
    ok "location ^~ ${UPLOADS_LOC} sudah ada"
    [[ $HAS_BODY_SIZE -eq 1 ]] && ok "client_max_body_size sudah ada" \
        || warn "client_max_body_size belum ada → upload >1 MB akan kena 413"
elif [[ $CHECK_ONLY -eq 1 || $PATCH_NGINX -eq 0 || -z "$SITE_FILE" ]]; then
    warn "location ^~ ${UPLOADS_LOC} belum ada → LUBANG KEAMANAN: skrip di uploads/ bisa tereksekusi"
    [[ $HAS_BODY_SIZE -eq 0 ]] && warn "client_max_body_size belum ada → upload >1 MB akan kena 413"
    printf '\n'
    info "Tambahkan blok berikut ke dalam server { ... } di ${SITE_FILE:-file server block Anda}:"
    printf '%s\n' "$Y"
    cat "$NGINX_SNIPPET"
    printf '%s\n' "$N"
    info "Atau biarkan skrip yang menuliskannya: sudo $0 --patch-nginx"
else
    # Sisipkan tepat sebelum 'location ^~ /survei/'; kalau tidak ketemu,
    # sebelum kurung tutup terakhir.
    NGINX_BAK="$SITE_FILE.bak-$(date +%F-%H%M%S)"
    cp -p "$SITE_FILE" "$NGINX_BAK"

    # Pencocokan pakai index(), bukan regex: "^~" sulit di-escape lewat awk -v
    # ("\^" berubah jadi jangkar regex dan polanya tidak pernah cocok).
    awk -v marker="${URL_PATH}/" '
        FNR == NR { snippet = snippet $0 ORS; next }
        !inserted && index($0, "location") && index($0, "^~") && index($0, marker) {
            printf "%s", snippet; inserted = 1
        }
        { print }
    ' "$NGINX_SNIPPET" "$SITE_FILE" > "$TMPDIR_DEPLOY/site.new" 2>/dev/null || true

    # awk di atas hanya menyisipkan bila pola ketemu; verifikasi hasilnya
    if ! grep -qE "location[[:space:]]*\^~[[:space:]]*${UPLOADS_LOC}" "$TMPDIR_DEPLOY/site.new" 2>/dev/null; then
        head -n -1 "$SITE_FILE" > "$TMPDIR_DEPLOY/site.new"
        cat "$NGINX_SNIPPET" >> "$TMPDIR_DEPLOY/site.new"
        tail -n 1 "$SITE_FILE" >> "$TMPDIR_DEPLOY/site.new"
    fi

    cat "$TMPDIR_DEPLOY/site.new" > "$SITE_FILE"

    if nginx -t 2>"$TMPDIR_DEPLOY/nginx.err"; then
        ok "Config ditulis + lolos 'nginx -t' (backup: $NGINX_BAK)"
        systemctl reload nginx
        ok "Nginx di-reload"
    else
        cp -p "$NGINX_BAK" "$SITE_FILE"
        printf '\n'
        cat "$TMPDIR_DEPLOY/nginx.err"
        die "nginx -t GAGAL — config sudah dikembalikan ke semula, Nginx tidak disentuh"
    fi
fi

# ========================================================== 6. RELOAD PHP
step "6/7  Reload PHP-FPM"

if [[ $CHECK_ONLY -eq 1 ]]; then
    info "dilewati (--check)"
elif systemctl reload "$FPM_SERVICE" 2>/dev/null; then
    ok "$FPM_SERVICE di-reload (OPcache dibersihkan)"
else
    warn "Gagal reload $FPM_SERVICE — jalankan manual: sudo systemctl reload $FPM_SERVICE"
    info "Tanpa ini OPcache masih menjalankan kode lama dan update terlihat seperti gagal."
fi

# ========================================================== 7. VERIFIKASI
step "7/7  Verifikasi"

if [[ -z "$DOMAIN" && -n "$SITE_FILE" && -f "$SITE_FILE" ]]; then
    SN="$(awk '/^[[:space:]]*server_name/ {for (i = 2; i <= NF; i++) {gsub(/;/, "", $i); if ($i != "_" && $i !~ /^\*/) {print $i; exit}}}' "$SITE_FILE")"
    [[ -n "$SN" ]] && DOMAIN="https://$SN"
fi

if [[ -z "$DOMAIN" ]]; then
    warn "Domain tidak diketahui — uji HTTP dilewati. Ulangi dengan: --domain https://domain-anda.com"
elif ! command -v curl >/dev/null; then
    warn "curl tidak ada — uji HTTP dilewati"
else
    info "Menguji $DOMAIN"

    # a. Endpoint hidup dan membalas JSON
    BODY="$(curl -sS --max-time 15 "$DOMAIN$URL_PATH/upload_image.php" 2>/dev/null || true)"
    if [[ "$BODY" == *'"success":false'* ]]; then
        ok "upload_image.php membalas JSON dengan benar"
    elif [[ -z "$BODY" ]]; then
        warn "upload_image.php tidak merespons — cek error log Nginx/PHP"
    else
        warn "upload_image.php membalas hal tak terduga: $(printf '%.80s' "$BODY")"
    fi

    # b. UJI KEAMANAN — skrip di uploads/ tidak boleh tereksekusi
    CANARY="uploads/__deploy_check_$$.php"
    printf '<?php echo "TEREKSEKUSI"; ?>' > "$CANARY"
    RESP="$(curl -sS --max-time 15 "$DOMAIN$URL_PATH/uploads/$(basename "$CANARY")" 2>/dev/null || true)"
    rm -f "$CANARY"; CANARY=""

    if [[ "$RESP" == *TEREKSEKUSI* ]]; then
        printf '\n'
        die "BAHAYA: file .php di uploads/ TEREKSEKUSI. Blok Nginx langkah 5 belum aktif — jangan biarkan situs seperti ini."
    else
        ok "uploads/ menolak eksekusi PHP (aman)"
    fi

    # c. File statis tetap tersaji
    CODE="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$DOMAIN$URL_PATH/uploads/index.html" 2>/dev/null || echo 000)"
    if [[ "$CODE" == "200" ]]; then
        ok "File statis di uploads/ tetap bisa diakses (HTTP 200)"
    else
        warn "uploads/index.html → HTTP $CODE — pastikan hanya ekstensi skrip yang di-deny, bukan semua file"
    fi
fi

# ================================================================ RINGKASAN
printf '\n%s' "$BOLD"
if [[ $WARNINGS -eq 0 ]]; then
    printf '%s✓ Deploy selesai tanpa peringatan.%s\n' "$G" "$N"
else
    printf '%s✓ Deploy selesai dengan %d peringatan di atas — periksa sebelum dianggap beres.%s\n' "$Y" "$WARNINGS" "$N"
fi
printf '%s' "$N"

cat <<EOF

Uji manual lewat browser (belum tercakup skrip ini):
  1. Admin → buka survei → tab Editor → tambah pertanyaan → Unggah Gambar
  2. Simpan → muat ulang halaman → gambar harus masih ada. Simpan & muat ulang
     sekali lagi. Ini uji terpenting: editor membangun ulang data tiap simpan.
  3. Ubah tipe pertanyaan jadi "Unggah Gambar (Jawaban)" → Simpan
  4. Buka form publik → isi → unggah foto → kirim
  5. Admin → tab Hasil → Detail → thumbnail muncul; Export Excel → tautan foto valid

Rollback bila bermasalah:
  cd $PROJECT_DIR
  git reset --hard \$(cat $BACKUP_DIR/rollback-commit.txt)
  sudo systemctl reload $FPM_SERVICE

Catatan: isi uploads/ tidak ikut git. Masukkan folder itu ke backup rutin,
terpisah dari database.
EOF
