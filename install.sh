#!/usr/bin/env bash
#
# install.sh — Pasang Aplikasi Survei APT Pranoto dari NOL di SUB-PATH
#              https://aptpairport.id/survei/   (Nginx + PHP-FPM + MySQL)
#
# Situs utama di "/" TIDAK disentuh. Skrip hanya MENYISIPKAN satu blok
# "location ^~ /survei/" ke server block yang sudah melayani domain itu.
#
# Berbeda dari deploy.sh yang meng-update instalasi yang sudah jalan, skrip ini
# menyiapkan instalasi baru: cek dependensi, siapkan folder & izin, verifikasi
# database, sisipkan config Nginx, lalu uji hasilnya termasuk uji keamanan.
#
# Database dianggap SUDAH ADA. Skrip hanya menyambung, memeriksa tabel, dan
# mengimpor database/database.sql kalau ada tabel yang belum terbentuk.
#
# Skrip ini idempoten: aman dijalankan berulang kali.
#
# Pemakaian:
#   sudo ./install.sh --check      # hanya periksa & laporkan, tidak mengubah apa pun
#   sudo ./install.sh              # pasang
#
# Opsi:
#   --domain NAMA      domain situs             (default: aptpairport.id)
#   --url-path P       sub-path URL             (default: /survei)
#   --project DIR      folder aplikasi          (default: /var/www/DOMAIN/survei)
#   --env-file FILE    lokasi kredensial        (default: /var/www/survei.env)
#   --site FILE        server block Nginx       (default: dideteksi dari server_name)
#   --repo URL         sumber clone kalau folder masih kosong
#   --php-sock PATH    socket PHP-FPM           (default: dideteksi otomatis)
#
set -euo pipefail

DOMAIN="aptpairport.id"
URL_PATH="/survei"
PROJECT_DIR=""
ENV_FILE="/var/www/survei.env"
SITE_FILE=""
REPO_URL="https://github.com/elband/survei-multi-aptp.git"
PHP_SOCK=""
CHECK_ONLY=0

MARK_START="    # ===== survei: mulai ====="
MARK_END="    # ===== survei: selesai ====="

if [[ -t 1 ]]; then
    R=$'\e[31m'; G=$'\e[32m'; Y=$'\e[33m'; B=$'\e[34m'; BOLD=$'\e[1m'; N=$'\e[0m'
else
    R=""; G=""; Y=""; B=""; BOLD=""; N=""
fi

WARNINGS=0
step() { printf '\n%s==> %s%s\n' "$BOLD$B" "$*" "$N"; }
ok()   { printf '  %s\xe2\x9c\x93%s %s\n' "$G" "$N" "$*"; }
warn() { printf '  %s!%s %s\n' "$Y" "$N" "$*"; WARNINGS=$((WARNINGS + 1)); }
die()  { printf '\n  %s\xe2\x9c\x97 %s%s\n\n' "$R" "$*" "$N" >&2; exit 1; }
info() { printf '    %s\n' "$*"; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --domain)    DOMAIN="${2:?--domain butuh nama domain}"; shift 2 ;;
        --url-path)  URL_PATH="/${2#/}"; URL_PATH="${URL_PATH%/}"; shift 2 ;;
        --project)   PROJECT_DIR="${2:?--project butuh path}"; shift 2 ;;
        --env-file)  ENV_FILE="${2:?--env-file butuh path}"; shift 2 ;;
        --site)      SITE_FILE="${2:?--site butuh path}"; shift 2 ;;
        --repo)      REPO_URL="${2:?--repo butuh URL}"; shift 2 ;;
        --php-sock)  PHP_SOCK="${2:?--php-sock butuh path}"; shift 2 ;;
        --check)     CHECK_ONLY=1; shift ;;
        -h|--help)   sed -n '2,30p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)           die "Opsi tidak dikenal: $1  (pakai --help)" ;;
    esac
done

[[ -n "$URL_PATH" ]] || die "--url-path tidak boleh kosong — skrip ini untuk instalasi sub-path"
URL_SEG="${URL_PATH#/}"
: "${PROJECT_DIR:=/var/www/$DOMAIN/$URL_SEG}"
NGINX_ROOT="$(dirname "$PROJECT_DIR")"

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo $0 $*"

# "root NGINX_ROOT" memetakan /survei/x.php -> NGINX_ROOT/survei/x.php, jadi nama
# folder terakhir WAJIB sama dengan segmen URL. Kalau tidak, harus pakai "alias"
# yang merusak SCRIPT_FILENAME milik PHP.
[[ "$(basename "$PROJECT_DIR")" == "$URL_SEG" ]] \
    || die "Nama folder proyek harus '$URL_SEG' agar cocok dengan URL $URL_PATH/ (sekarang: $(basename "$PROJECT_DIR"))"

case "$ENV_FILE" in
    "$NGINX_ROOT"/*) die "$ENV_FILE berada di dalam document root ($NGINX_ROOT) — pindahkan ke luar, mis. /var/www/survei.env" ;;
esac

TMP="$(mktemp -d)"
CANARY=""
cleanup() { [[ -n "$CANARY" && -f "$CANARY" ]] && rm -f "$CANARY"; rm -rf "$TMP"; }
trap cleanup EXIT

printf '%s\n' "${BOLD}Instalasi Survei APT Pranoto — sub-path${N}"
info "URL    : https://$DOMAIN$URL_PATH/"
info "Proyek : $PROJECT_DIR"
info "Root   : $NGINX_ROOT   (dipakai direktif 'root' di dalam location)"
info "Env    : $ENV_FILE"
[[ $CHECK_ONLY -eq 1 ]] && info "Mode   : ${Y}CHECK ONLY — tidak ada yang diubah${N}"

# ========================================================== 1. DEPENDENSI
step "1/7  Dependensi sistem"

command -v nginx >/dev/null || die "nginx tidak ada — apt install nginx"
command -v php   >/dev/null || die "php CLI tidak ada — apt install php-fpm php-mysql php-gd"
command -v git   >/dev/null || die "git tidak ada — apt install git"
ok "nginx, php, git tersedia"

PHP_VER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
ok "PHP $PHP_VER"

for ext in pdo_mysql gd mbstring json; do
    php -m | grep -qix "$ext" \
        || die "Ekstensi PHP '$ext' tidak ada. Pasang: apt install -y php${PHP_VER}-${ext} && systemctl restart php${PHP_VER}-fpm"
done
ok "Ekstensi pdo_mysql, gd, mbstring, json aktif"

GD_MISSING="$(php -r '
    $i = gd_info(); $miss = [];
    foreach (["JPEG Support", "PNG Support", "WebP Support"] as $k) { if (empty($i[$k])) $miss[] = $k; }
    echo implode(", ", $miss);
')"
if [[ -n "$GD_MISSING" ]]; then
    warn "GD aktif tapi format belum lengkap: $GD_MISSING"
else
    ok "GD lengkap (JPEG, PNG, WebP)"
fi

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
check_ini upload_max_filesize 5
check_ini post_max_size       6
check_ini memory_limit        128

if [[ -z "$PHP_SOCK" ]]; then
    PHP_SOCK="$(ls -1 /run/php/php*-fpm.sock 2>/dev/null | sort -V | tail -1 || true)"
fi
[[ -S "$PHP_SOCK" ]] || die "Socket PHP-FPM tidak ketemu. Cek 'ls /run/php/*.sock' lalu ulangi dengan --php-sock PATH"
ok "Socket PHP-FPM: $PHP_SOCK"

FPM_SERVICE="$(systemctl list-units --type=service --state=running --no-legend 2>/dev/null \
    | awk '{print $1}' | grep -E '^php.*fpm\.service$' | head -1 || true)"
[[ -z "$FPM_SERVICE" ]] && FPM_SERVICE="php${PHP_VER}-fpm.service"
ok "Service PHP-FPM: $FPM_SERVICE"

FPM_USER="$(ps -eo user:32,comm | awk '$2 ~ /^php-fpm/ && $1 != "root" {print $1; exit}' || true)"
[[ -z "$FPM_USER" ]] && FPM_USER="www-data"
id "$FPM_USER" >/dev/null 2>&1 || die "User PHP-FPM '$FPM_USER' tidak ada di sistem"
ok "User PHP-FPM: $FPM_USER"

# ===================================================== 2. KODE APLIKASI
step "2/7  Kode aplikasi"

[[ $CHECK_ONLY -eq 0 ]] && mkdir -p "$NGINX_ROOT"

if [[ -f "$PROJECT_DIR/index.php" && -f "$PROJECT_DIR/db.php" ]]; then
    ok "Kode sudah ada di $PROJECT_DIR"
    [[ -d "$PROJECT_DIR/.git" ]] && info "HEAD: $(git -C "$PROJECT_DIR" log --oneline -1 2>/dev/null || echo '-')"
elif [[ $CHECK_ONLY -eq 1 ]]; then
    die "Kode belum ada di $PROJECT_DIR"
else
    git clone "$REPO_URL" "$PROJECT_DIR"
    ok "Clone selesai: $(git -C "$PROJECT_DIR" log --oneline -1)"
fi

for f in index.php db.php login.php admin.php upload_image.php image_lib.php \
         database/database.sql deploy/nginx-survei-subpath.conf; do
    [[ -f "$PROJECT_DIR/$f" ]] || die "Berkas wajib hilang: $PROJECT_DIR/$f"
done
ok "Berkas inti lengkap"

# ======================================================== 3. KREDENSIAL
step "3/7  Kredensial (di luar document root)"

# db.php mencari berkas bernama persis 'survei.env' di folder-folder induk.
if [[ "$(basename "$ENV_FILE")" != "survei.env" ]]; then
    warn "db.php mencari berkas bernama 'survei.env' di folder induk"
    info "Nama '$(basename "$ENV_FILE")' hanya terbaca kalau SURVEI_ENV_PATH di-set di pool PHP-FPM."
fi

if [[ -f "$ENV_FILE" ]]; then
    ok "Ditemukan: $ENV_FILE"
elif [[ $CHECK_ONLY -eq 1 ]]; then
    die "$ENV_FILE belum ada"
elif [[ -f "$PROJECT_DIR/.env" ]]; then
    cp "$PROJECT_DIR/.env" "$ENV_FILE"
    ok "Disalin dari $PROJECT_DIR/.env -> $ENV_FILE"
    info "Salinan di dalam folder aplikasi sebaiknya dihapus setelah terbukti jalan."
else
    umask 077
    cat > "$ENV_FILE" <<'ENVTPL'
# === DATABASE (WAJIB DIISI) ===
DB_HOST=localhost
DB_PORT=3306
DB_USER=
DB_PASSWORD=
DB_NAME=

# === LOGIN ADMIN ===
ADMIN_USER=admin
ADMIN_PASS=

# === GEMINI AI (opsional) ===
GEMINI_API_KEY=
ENVTPL
    printf '\n'
    info "Template kredensial dibuat: $ENV_FILE"
    die "Isi DB_USER / DB_PASSWORD / DB_NAME / ADMIN_PASS dulu, lalu jalankan ulang skrip ini"
fi

if [[ $CHECK_ONLY -eq 0 ]]; then
    chown root:"$FPM_USER" "$ENV_FILE"
    chmod 640 "$ENV_FILE"
    ok "Izin 640 root:$FPM_USER — hanya PHP-FPM yang boleh membacanya"
fi

env_get() {
    sed -n "s/^[[:space:]]*$1[[:space:]]*=[[:space:]]*//p" "$ENV_FILE" \
        | head -1 | sed -e 's/^["'\'']//' -e 's/["'\'']$//'
}
DB_NAME="$(env_get DB_NAME)"
DB_USER="$(env_get DB_USER)"
DB_PASS="$(env_get DB_PASSWORD)"
DB_HOST="$(env_get DB_HOST)"; : "${DB_HOST:=localhost}"
DB_PORT="$(env_get DB_PORT)"; : "${DB_PORT:=3306}"

[[ -n "$DB_NAME" ]] || die "DB_NAME kosong di $ENV_FILE"
[[ -n "$(env_get ADMIN_PASS)" ]] || warn "ADMIN_PASS kosong — login admin tidak akan bisa dipakai"
ok "DB_NAME=$DB_NAME  DB_USER=$DB_USER  DB_HOST=$DB_HOST:$DB_PORT"

# ========================================================== 4. DATABASE
step "4/7  Database"

if ! command -v mysql >/dev/null; then
    warn "klien mysql tidak ada — verifikasi database dilewati"
else
    MYCNF="$TMP/my.cnf"
    umask 077
    cat > "$MYCNF" <<EOF
[client]
host=$DB_HOST
port=$DB_PORT
user=$DB_USER
password=$DB_PASS
EOF

    if mysql --defaults-extra-file="$MYCNF" -e "USE \`$DB_NAME\`;" 2>"$TMP/db.err"; then
        ok "Koneksi database berhasil"
    else
        printf '\n'; cat "$TMP/db.err"
        die "Tidak bisa menyambung ke database '$DB_NAME' — periksa kredensial di $ENV_FILE"
    fi

    MISSING=""
    for t in surveys survey_responses audit_logs; do
        mysql --defaults-extra-file="$MYCNF" -N -e \
            "SELECT 1 FROM information_schema.tables WHERE table_schema='$DB_NAME' AND table_name='$t';" \
            2>/dev/null | grep -q 1 || MISSING="$MISSING $t"
    done

    if [[ -z "$MISSING" ]]; then
        ROWS="$(mysql --defaults-extra-file="$MYCNF" -N -e "SELECT COUNT(*) FROM \`$DB_NAME\`.surveys;" 2>/dev/null || echo '?')"
        ok "Tabel lengkap (surveys, survey_responses, audit_logs) — $ROWS survei tersimpan"
    elif [[ $CHECK_ONLY -eq 1 ]]; then
        warn "Tabel belum ada:$MISSING"
    else
        info "Tabel belum ada:$MISSING -> mengimpor database/database.sql"
        mysql --defaults-extra-file="$MYCNF" "$DB_NAME" < "$PROJECT_DIR/database/database.sql" \
            || die "Impor skema gagal"
        ok "Skema diimpor (CREATE TABLE IF NOT EXISTS — data lama tidak tersentuh)"
    fi
fi

# ====================================================== 5. FOLDER & IZIN
step "5/7  Folder & izin"

if [[ $CHECK_ONLY -eq 0 ]]; then
    mkdir -p "$PROJECT_DIR/uploads"

    # Kode hanya perlu dibaca web server, tidak ditulis. Hanya uploads/ yang
    # dimiliki PHP-FPM — kalau ada celah upload, sisa aplikasi tetap read-only.
    chown -R root:"$FPM_USER" "$PROJECT_DIR"
    find "$PROJECT_DIR" -type d -exec chmod 755 {} +
    find "$PROJECT_DIR" -type f -exec chmod 644 {} +
    chown -R "$FPM_USER":"$FPM_USER" "$PROJECT_DIR/uploads"
    ok "Kode read-only untuk $FPM_USER; uploads/ dimiliki $FPM_USER"
fi

# Dalam mode --check tidak ada yang diperbaiki, jadi kedua temuan di bawah cukup
# jadi peringatan: jalan normal memang memperbaikinya sendiri. Menghentikan
# pemeriksaan di sini justru menyembunyikan langkah 6 dan 7 dari pengguna.
if [[ ! -d "$PROJECT_DIR/uploads" ]]; then
    warn "uploads/ belum ada — akan dibuat saat dijalankan tanpa --check"
elif sudo -u "$FPM_USER" test -w "$PROJECT_DIR/uploads"; then
    ok "uploads/ writable oleh $FPM_USER"
elif [[ $CHECK_ONLY -eq 1 ]]; then
    warn "uploads/ belum writable oleh $FPM_USER — diperbaiki saat dijalankan tanpa --check"
else
    die "uploads/ TIDAK writable oleh $FPM_USER — semua upload akan gagal"
fi

if sudo -u "$FPM_USER" test -r "$ENV_FILE"; then
    ok "$ENV_FILE terbaca oleh $FPM_USER"
elif [[ $CHECK_ONLY -eq 1 ]]; then
    warn "$ENV_FILE belum terbaca oleh $FPM_USER — izinnya diperbaiki saat dijalankan tanpa --check"
else
    die "$ENV_FILE tidak terbaca oleh $FPM_USER — koneksi database akan jatuh ke nilai default"
fi

info "Subfolder illustrations/ dan responses/ dibuat otomatis saat upload pertama."

# ============================================================ 6. NGINX
step "6/7  Sisipkan blok Nginx"

if [[ -z "$SITE_FILE" ]]; then
    SITE_FILE="$(grep -rlE "^[[:space:]]*server_name[^;]*(^|[[:space:].])${DOMAIN//./\\.}([[:space:];]|$)" \
        /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null | head -1 || true)"
fi
[[ -n "$SITE_FILE" && -f "$SITE_FILE" ]] \
    || die "Server block untuk $DOMAIN tidak terdeteksi. Tentukan manual: --site /etc/nginx/sites-available/NAMA"

# Symlink di sites-enabled/ -> sunting berkas aslinya di sites-available/.
[[ -L "$SITE_FILE" ]] && SITE_FILE="$(readlink -f "$SITE_FILE")"
ok "Server block: $SITE_FILE"

# Siapkan blok dari template, sesuaikan path dan socket.
SNIPPET="$TMP/survei.conf"
sed -e "s#/var/www/aptpairport\.id#$NGINX_ROOT#g" \
    -e "s#/survei/#$URL_PATH/#g" \
    -e "s#= /survei #= $URL_PATH #g" \
    -e "s#unix:/run/php/php8\.2-fpm\.sock#unix:$PHP_SOCK#" \
    "$PROJECT_DIR/deploy/nginx-survei-subpath.conf" \
    | sed '/^#/d' \
    | awk '
        # Buang baris kosong di awal & akhir. Tanpa ini setiap kali skrip
        # dijalankan ulang, satu baris kosong menumpuk di server block.
        { a[NR] = $0 }
        END {
            s = 1; while (s <= NR && a[s] ~ /^[[:space:]]*$/) s++
            e = NR; while (e >= s && a[e] ~ /^[[:space:]]*$/) e--
            for (i = s; i <= e; i++) print a[i]
        }
    ' > "$SNIPPET"

if [[ $CHECK_ONLY -eq 1 ]]; then
    if grep -qF "$MARK_START" "$SITE_FILE"; then
        ok "Blok survei sudah tersisip di $SITE_FILE"
    else
        warn "Blok survei BELUM tersisip di $SITE_FILE"
    fi
else
    NGINX_BAK="$SITE_FILE.bak-$(date +%F-%H%M%S)"
    cp -p "$SITE_FILE" "$NGINX_BAK"

    # Buang blok lama (kalau ada) supaya idempoten, lalu sisipkan yang baru
    # tepat sebelum kurung tutup server block yang memuat server_name domain.
    awk -v s="$MARK_START" -v e="$MARK_END" '
        index($0, s) { skip = 1 }
        !skip { print }
        index($0, e) { skip = 0 }
    ' "$SITE_FILE" > "$TMP/site.stripped"

    awk -v snippet_file="$SNIPPET" -v dom="$DOMAIN" '
        function emit_snippet(   line) {
            while ((getline line < snippet_file) > 0) print line
            close(snippet_file)
        }
        {
            line = $0

            # Hitung kedalaman kurung untuk mengenali batas server block.
            open = gsub(/\{/, "{", line)
            close_n = gsub(/\}/, "}", line)

            if (depth == 0 && open > 0 && $0 ~ /server[[:space:]]*\{/) {
                in_server = 1; found = 0
            }
            if (in_server && $0 ~ /^[[:space:]]*server_name/ && index($0, dom)) found = 1

            newdepth = depth + open - close_n

            # Baris yang menutup server block: sisipkan tepat sebelumnya.
            if (in_server && found && depth > 0 && newdepth == 0) {
                emit_snippet()
                inserted = 1
            }

            print $0
            depth = newdepth
            if (depth == 0) in_server = 0
        }
        END { exit(inserted ? 0 : 1) }
    ' "$TMP/site.stripped" > "$TMP/site.new" \
        || die "Tidak menemukan penutup server block untuk $DOMAIN di $SITE_FILE — sisipkan manual dari deploy/nginx-survei-subpath.conf"

    cat "$TMP/site.new" > "$SITE_FILE"

    if nginx -t 2>"$TMP/nginx.err"; then
        ok "Config lolos 'nginx -t' (backup: $NGINX_BAK)"
        systemctl reload nginx
        ok "Nginx di-reload"
    else
        cp -p "$NGINX_BAK" "$SITE_FILE"
        printf '\n'; cat "$TMP/nginx.err"
        die "nginx -t GAGAL — config dikembalikan ke semula, Nginx tidak disentuh"
    fi

    systemctl reload "$FPM_SERVICE" 2>/dev/null \
        && ok "$FPM_SERVICE di-reload (OPcache dibersihkan)" \
        || warn "Gagal reload $FPM_SERVICE — jalankan manual: systemctl reload $FPM_SERVICE"
fi

# ======================================================= 7. VERIFIKASI
step "7/7  Verifikasi"

BASE="https://$DOMAIN"
if ! command -v curl >/dev/null; then
    warn "curl tidak ada — uji HTTP dilewati"
else
    info "Menguji $BASE$URL_PATH/"

    # Situs utama harus tetap hidup — instalasi ini tidak boleh merusaknya.
    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 20 "$BASE/" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "Situs utama $BASE/ masih HTTP 200" \
        || warn "Situs utama $BASE/ -> HTTP $C — periksa, seharusnya tidak berubah"

    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 20 "$BASE$URL_PATH/login.php" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "$URL_PATH/login.php -> HTTP 200" \
        || warn "$URL_PATH/login.php -> HTTP $C (cek error log Nginx)"

    BODY="$(curl -sS -L --max-time 20 "$BASE$URL_PATH/upload_image.php" 2>/dev/null || true)"
    if [[ "$BODY" == *'"success":false'* ]]; then
        ok "upload_image.php membalas JSON dengan benar"
    else
        warn "upload_image.php membalas tak terduga: $(printf '%.80s' "$BODY")"
    fi

    # Berkas sensitif tidak boleh bisa diunduh siapa pun.
    for p in /.env /database/database.sql /deploy.sh /install.sh /DEPLOY.md /.git/config; do
        C="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$BASE$URL_PATH$p" 2>/dev/null || echo 000)"
        if [[ "$C" == "403" || "$C" == "404" ]]; then
            ok "$URL_PATH$p diblokir (HTTP $C)"
        else
            die "BAHAYA: $URL_PATH$p bisa diakses (HTTP $C) — jangan biarkan situs seperti ini"
        fi
    done

    # Uji paling penting: berkas .php di uploads/ tidak boleh disajikan maupun
    # dieksekusi. Aturan deny WAJIB bersarang di dalam blok uploads — versi
    # sejajar dilewati Nginx karena blok uploads memakai "^~".
    CANARY="$PROJECT_DIR/uploads/__install_check_$$.php"
    if printf '<?php echo "TEREKSEKUSI"; ?>' > "$CANARY" 2>/dev/null; then
        RESP="$(curl -sS -L --max-time 20 "$BASE$URL_PATH/uploads/$(basename "$CANARY")" 2>/dev/null || true)"
        rm -f "$CANARY"; CANARY=""
        if [[ "$RESP" == *TEREKSEKUSI* ]]; then
            printf '\n'
            die "BAHAYA: .php di uploads/ tersaji/tereksekusi — blok deny bersarang belum aktif"
        fi
        ok "uploads/ menolak berkas .php"
    else
        # uploads/ belum writable (biasanya saat --check sebelum instalasi).
        # Jangan biarkan "set -e" mematikan skrip hanya karena uji ini.
        CANARY=""
        warn "Tidak bisa menulis berkas uji ke uploads/ — uji eksekusi .php DILEWATI"
        info "Uji ini wajib lulus sebelum situs dianggap aman. Ulangi setelah instalasi."
    fi

    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 15 "$BASE$URL_PATH/uploads/index.html" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "Berkas statis di uploads/ tetap tersaji" \
        || warn "uploads/index.html -> HTTP $C (blokir hanya ekstensi skrip, bukan semua berkas)"

    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 15 "$BASE$URL_PATH/assets/images/logo-apt.svg" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "Logo tersaji dari repo" \
        || warn "$URL_PATH/assets/images/logo-apt.svg -> HTTP $C"
fi

printf '\n%s' "$BOLD"
if [[ $WARNINGS -eq 0 ]]; then
    printf '%s\xe2\x9c\x93 Instalasi selesai tanpa peringatan.%s\n' "$G" "$N"
else
    printf '%s\xe2\x9c\x93 Instalasi selesai dengan %d peringatan di atas — periksa dulu.%s\n' "$Y" "$WARNINGS" "$N"
fi
printf '%s' "$N"

cat <<EOF

Berikutnya:
  1. Buka $BASE$URL_PATH/login.php, masuk dengan ADMIN_USER / ADMIN_PASS dari $ENV_FILE
  2. Buat satu survei -> salin tautan publiknya -> buka di jendela penyamaran
  3. Kirim satu jawaban berisi foto -> cek tab Hasil -> Detail -> Export Excel
  4. Hapus salinan kredensial di dalam folder aplikasi kalau masih ada:
       rm -f $PROJECT_DIR/.env

Rollback (situs utama tidak pernah disentuh, cukup buang blok survei):
  ls $SITE_FILE.bak-*
  cp $SITE_FILE.bak-XXXX $SITE_FILE
  nginx -t && systemctl reload nginx
EOF
