#!/usr/bin/env bash
#
# install.sh — Pasang Aplikasi Survei APT Pranoto dari NOL di AKAR DOMAIN
#              (Nginx + PHP-FPM + MySQL/MariaDB, Debian/Ubuntu)
#
# Berbeda dari deploy.sh yang hanya meng-update instalasi lama, skrip ini
# menyiapkan instalasi baru: cek dependensi, siapkan folder, tulis server block
# Nginx untuk akar domain, pasang izin, verifikasi database, lalu uji hasilnya.
#
# Database dianggap SUDAH ADA. Skrip hanya menyambung, memeriksa tabel, dan
# mengimpor database/database.sql kalau ada tabel yang belum terbentuk.
#
# Pemakaian:
#   sudo ./install.sh                       # pasang; berhenti kalau domain dipakai situs lain
#   sudo ./install.sh --replace-existing    # nonaktifkan server block lain di domain yang sama
#   sudo ./install.sh --check               # hanya periksa, tidak mengubah apa pun
#
# Opsi:
#   --domain NAMA      domain situs            (default: aptpairport.id)
#   --project DIR      document root aplikasi  (default: /var/www/DOMAIN/survei)
#   --env-file FILE    lokasi kredensial       (default: survei.env satu level di atas proyek)
#   --repo URL         sumber clone kalau --project masih kosong
#   --php-sock PATH    socket PHP-FPM          (default: dideteksi otomatis)
#
set -euo pipefail

DOMAIN="aptpairport.id"
PROJECT_DIR=""
ENV_FILE=""
REPO_URL="https://github.com/elband/survei-multi-aptp.git"
PHP_SOCK=""
REPLACE_EXISTING=0
CHECK_ONLY=0

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
        --domain)            DOMAIN="${2:?--domain butuh nama domain}"; shift 2 ;;
        --project)           PROJECT_DIR="${2:?--project butuh path}"; shift 2 ;;
        --env-file)          ENV_FILE="${2:?--env-file butuh path}"; shift 2 ;;
        --repo)              REPO_URL="${2:?--repo butuh URL}"; shift 2 ;;
        --php-sock)          PHP_SOCK="${2:?--php-sock butuh path}"; shift 2 ;;
        --replace-existing)  REPLACE_EXISTING=1; shift ;;
        --check)             CHECK_ONLY=1; shift ;;
        -h|--help)           sed -n '2,24p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
        *)                   die "Opsi tidak dikenal: $1  (pakai --help)" ;;
    esac
done

: "${PROJECT_DIR:=/var/www/$DOMAIN/survei}"
: "${ENV_FILE:=$(dirname "$PROJECT_DIR")/survei.env}"
SITE_FILE="/etc/nginx/sites-available/$DOMAIN"
SITE_LINK="/etc/nginx/sites-enabled/$DOMAIN"

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root: sudo $0 $*"

TMP="$(mktemp -d)"
CANARY=""
cleanup() { [[ -n "$CANARY" && -f "$CANARY" ]] && rm -f "$CANARY"; rm -rf "$TMP"; }
trap cleanup EXIT

printf '%s\n' "${BOLD}Instalasi Survei APT Pranoto — akar domain${N}"
info "Domain : https://$DOMAIN/"
info "Proyek : $PROJECT_DIR"
info "Env    : $ENV_FILE"
[[ $CHECK_ONLY -eq 1 ]] && info "Mode   : ${Y}CHECK ONLY — tidak ada yang diubah${N}"

# ========================================================== 1. DEPENDENSI
step "1/8  Dependensi sistem"

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

# ======================================================== 2. SITUS LAMA
step "2/8  Situs lain di $DOMAIN"

DOM_RE="${DOMAIN//./\\.}"
CONFLICTS="$(grep -rlE "^[[:space:]]*server_name[^;]*(^|[[:space:].])${DOM_RE}([[:space:];]|\$)" \
    /etc/nginx/sites-enabled/ /etc/nginx/conf.d/ 2>/dev/null \
    | grep -v "^${SITE_LINK}\$" || true)"

if [[ -z "$CONFLICTS" ]]; then
    ok "Tidak ada server block lain yang memakai $DOMAIN"
else
    printf '\n'
    info "Server block lain yang melayani $DOMAIN:"
    printf '      %s\n' $CONFLICTS
    printf '\n'
    if [[ $CHECK_ONLY -eq 1 ]]; then
        warn "dilewati (--check)"
    elif [[ $REPLACE_EXISTING -eq 1 ]]; then
        for f in $CONFLICTS; do
            if [[ -L "$f" ]]; then
                rm -f "$f"
                ok "Symlink dilepas: $f"
            else
                mv "$f" "${f}.disabled-$(date +%F-%H%M%S)"
                ok "Dinonaktifkan: $f -> ${f}.disabled-*"
            fi
        done
        info "Situs lama tidak lagi dilayani. Foldernya TIDAK dihapus — masih bisa dipulihkan."
    else
        info "Situs lama masih aktif dan akan bentrok dengan instalasi ini."
        die "Ulangi dengan --replace-existing kalau memang mau menggantinya"
    fi
fi

# ===================================================== 3. KODE APLIKASI
step "3/8  Kode aplikasi"

[[ $CHECK_ONLY -eq 0 ]] && mkdir -p "$(dirname "$PROJECT_DIR")"

if [[ -f "$PROJECT_DIR/index.php" && -f "$PROJECT_DIR/db.php" ]]; then
    ok "Kode sudah ada di $PROJECT_DIR"
    [[ -d "$PROJECT_DIR/.git" ]] && info "HEAD: $(git -C "$PROJECT_DIR" log --oneline -1 2>/dev/null || echo '-')"
elif [[ $CHECK_ONLY -eq 1 ]]; then
    die "Kode belum ada di $PROJECT_DIR"
else
    git clone "$REPO_URL" "$PROJECT_DIR"
    ok "Clone selesai: $(git -C "$PROJECT_DIR" log --oneline -1)"
fi

for f in index.php db.php login.php admin.php upload_image.php image_lib.php database/database.sql; do
    [[ -f "$PROJECT_DIR/$f" ]] || die "Berkas wajib hilang: $PROJECT_DIR/$f"
done
ok "Berkas inti lengkap"

# ======================================================== 4. KREDENSIAL
step "4/8  Kredensial (di luar document root)"

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
    info "Salinan di dalam document root sebaiknya dihapus setelah instalasi terbukti jalan."
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

# ========================================================== 5. DATABASE
step "5/8  Database"

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

# ====================================================== 6. FOLDER & IZIN
step "6/8  Folder & izin"

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

sudo -u "$FPM_USER" test -w "$PROJECT_DIR/uploads" \
    && ok "uploads/ writable oleh $FPM_USER" \
    || die "uploads/ TIDAK writable oleh $FPM_USER — semua upload akan gagal"

sudo -u "$FPM_USER" test -r "$ENV_FILE" \
    && ok "$ENV_FILE terbaca oleh $FPM_USER" \
    || die "$ENV_FILE tidak terbaca oleh $FPM_USER — koneksi database akan jatuh ke nilai default"

info "Subfolder illustrations/ dan responses/ dibuat otomatis saat upload pertama."

# ============================================================ 7. NGINX
step "7/8  Server block Nginx"

HAS_CERT=0
[[ -d "/etc/letsencrypt/live/$DOMAIN" ]] && HAS_CERT=1

if [[ $CHECK_ONLY -eq 1 ]]; then
    [[ -f "$SITE_FILE" ]] && ok "Server block ada: $SITE_FILE" || warn "Server block belum dibuat"
    [[ -L "$SITE_LINK" ]] && ok "Site aktif: $SITE_LINK" || warn "Site belum diaktifkan"
elif [[ -f "$SITE_FILE" ]] && grep -q "root  *$PROJECT_DIR;" "$SITE_FILE"; then
    # Sudah menunjuk ke folder yang benar — biasanya karena certbot sudah
    # menyunting berkas ini. Menimpanya akan membuang blok SSL-nya.
    ok "Server block sudah menunjuk ke $PROJECT_DIR — dibiarkan apa adanya"
    info "Mau menulis ulang dari template? Hapus dulu: $SITE_FILE"
else
    [[ -f "$SITE_FILE" ]] && cp -p "$SITE_FILE" "$SITE_FILE.bak-$(date +%F-%H%M%S)"

    if [[ $HAS_CERT -eq 1 ]]; then
        TEMPLATE="$PROJECT_DIR/deploy/nginx-aptpairport.id.conf"
        [[ -f "$TEMPLATE" ]] || die "Template Nginx tidak ada: $TEMPLATE"
        sed -e "s#/var/www/aptpairport\.id/survei#$PROJECT_DIR#g" \
            -e "s#aptpairport\.id#$DOMAIN#g" \
            -e "s#unix:/run/php/php8\.2-fpm\.sock#unix:$PHP_SOCK#" \
            "$TEMPLATE" > "$TMP/site.conf"
        CERT_DIR="/etc/letsencrypt/live/$DOMAIN"
        sed -i "s|^\( *\)# ssl_certificate / ssl_certificate_key diisi certbot|\1ssl_certificate $CERT_DIR/fullchain.pem;\n\1ssl_certificate_key $CERT_DIR/privkey.pem;|" \
            "$TMP/site.conf"
        grep -q 'ssl_certificate ' "$TMP/site.conf" \
            || die "Gagal menyisipkan path sertifikat ke config — periksa template Nginx"
        info "Memasang versi HTTPS (sertifikat ditemukan di $CERT_DIR)"
    else
        # Tanpa sertifikat, blok 'listen 443 ssl' membuat 'nginx -t' gagal.
        # Pasang HTTP dulu; certbot yang menaikkannya ke HTTPS nanti.
        info "Sertifikat belum ada -> memasang versi HTTP dulu, certbot menyusul"
        cat > "$TMP/site.conf" <<HTTPONLY
# Tahap 1: HTTP saja. Jalankan certbot untuk menambahkan HTTPS:
#   certbot --nginx -d $DOMAIN -d www.$DOMAIN
server {
    listen 80;
    listen [::]:80;
    server_name $DOMAIN www.$DOMAIN;

    root  $PROJECT_DIR;
    index index.php;

    access_log /var/log/nginx/$DOMAIN.access.log;
    error_log  /var/log/nginx/$DOMAIN.error.log;

    # Default Nginx cuma 1 MB — upload foto 5 MB kena 413 sebelum PHP melihatnya.
    client_max_body_size 6M;

    error_page 403 /errors/403.html;
    error_page 404 /errors/404.html;
    error_page 413 /errors/413.html;
    error_page 500 502 503 504 /errors/50x.html;
    location ^~ /errors/ { internal; }

    location ^~ /.well-known/acme-challenge/ { root /var/www/html; }

    location ~ /\.(?!well-known) { deny all; }
    location ^~ /database/ { deny all; }
    location ~* \.(sql|md|sh|log|bak|ini|yml|yaml)\$ { deny all; }

    # "^~" wajib: tanpa itu "location ~ \.php\$" tetap menang dan .php yang
    # diselundupkan ke uploads/ akan tereksekusi.
    location ^~ /uploads/ {
        location ~* \.(php|phtml|phar|phps|cgi|pl|py|sh)\$ { deny all; }
        add_header X-Content-Type-Options "nosniff" always;
        try_files \$uri =404;
    }

    location ^~ /assets/ {
        expires 7d;
        add_header Cache-Control "public";
        try_files \$uri =404;
    }

    location / { try_files \$uri \$uri/ =404; }

    location ~ \.php\$ {
        # snippets/fastcgi-php.conf sudah memuat try_files sendiri — jangan duplikat.
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:$PHP_SOCK;
        fastcgi_read_timeout 120s;
    }

    add_header X-Content-Type-Options "nosniff"     always;
    add_header X-Frame-Options        "SAMEORIGIN"  always;
    add_header Referrer-Policy        "same-origin" always;
}
HTTPONLY
    fi

    cp "$TMP/site.conf" "$SITE_FILE"
    chmod 644 "$SITE_FILE"
    ln -sfn "$SITE_FILE" "$SITE_LINK"
    mkdir -p /var/www/html

    if nginx -t 2>"$TMP/nginx.err"; then
        ok "Config lolos 'nginx -t'"
        systemctl reload nginx
        ok "Nginx di-reload"
    else
        rm -f "$SITE_LINK"
        printf '\n'; cat "$TMP/nginx.err"
        die "nginx -t GAGAL — site sudah dinonaktifkan lagi, Nginx tidak berubah"
    fi
fi

if [[ $CHECK_ONLY -eq 0 ]]; then
    systemctl reload "$FPM_SERVICE" 2>/dev/null \
        && ok "$FPM_SERVICE di-reload (OPcache dibersihkan)" \
        || warn "Gagal reload $FPM_SERVICE — jalankan manual: systemctl reload $FPM_SERVICE"
fi

# ======================================================= 8. VERIFIKASI
step "8/8  Verifikasi"

if [[ $HAS_CERT -eq 1 ]]; then
    BASE="https://$DOMAIN"
else
    BASE="http://$DOMAIN"
    warn "Belum ada sertifikat HTTPS"
    info "Pasang sekarang : certbot --nginx -d $DOMAIN -d www.$DOMAIN"
    info "Lalu ulangi     : sudo $0 --check"
fi

if ! command -v curl >/dev/null; then
    warn "curl tidak ada — uji HTTP dilewati"
else
    info "Menguji $BASE"

    CODE="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 20 "$BASE/login.php" 2>/dev/null || echo 000)"
    [[ "$CODE" == "200" ]] && ok "login.php -> HTTP 200" || warn "login.php -> HTTP $CODE (cek $BASE dan error log Nginx)"

    BODY="$(curl -sS -L --max-time 20 "$BASE/upload_image.php" 2>/dev/null || true)"
    if [[ "$BODY" == *'"success":false'* ]]; then
        ok "upload_image.php membalas JSON dengan benar"
    else
        warn "upload_image.php membalas tak terduga: $(printf '%.80s' "$BODY")"
    fi

    # Berkas sensitif tidak boleh bisa diunduh siapa pun.
    for p in /.env /database/database.sql /deploy.sh /DEPLOY.md /.git/config; do
        C="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 "$BASE$p" 2>/dev/null || echo 000)"
        if [[ "$C" == "403" || "$C" == "404" ]]; then
            ok "$p diblokir (HTTP $C)"
        else
            die "BAHAYA: $p bisa diakses (HTTP $C) — jangan biarkan situs seperti ini"
        fi
    done

    # Uji paling penting: skrip di uploads/ tidak boleh tereksekusi.
    CANARY="$PROJECT_DIR/uploads/__install_check_$$.php"
    printf '<?php echo "TEREKSEKUSI"; ?>' > "$CANARY"
    RESP="$(curl -sS -L --max-time 20 "$BASE/uploads/$(basename "$CANARY")" 2>/dev/null || true)"
    rm -f "$CANARY"; CANARY=""
    if [[ "$RESP" == *TEREKSEKUSI* ]]; then
        printf '\n'
        die "BAHAYA: .php di uploads/ TEREKSEKUSI — blok Nginx belum aktif"
    fi
    ok "uploads/ menolak eksekusi PHP"

    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 15 "$BASE/uploads/index.html" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "File statis di uploads/ tetap tersaji" \
        || warn "uploads/index.html -> HTTP $C (blokir hanya ekstensi skrip, bukan semua file)"

    C="$(curl -sS -o /dev/null -w '%{http_code}' -L --max-time 15 "$BASE/assets/images/logo-apt.svg" 2>/dev/null || echo 000)"
    [[ "$C" == "200" ]] && ok "Logo tersaji dari repo (tidak lagi menumpang situs lama)" \
        || warn "assets/images/logo-apt.svg -> HTTP $C"
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
  1. Buka $BASE/login.php dan masuk dengan ADMIN_USER / ADMIN_PASS dari $ENV_FILE
  2. Buat satu survei -> salin tautan publiknya -> buka di jendela penyamaran
  3. Kirim satu jawaban berisi foto -> cek tab Hasil -> Detail -> Export Excel
  4. Hapus salinan kredensial di dalam document root kalau masih ada:
       rm -f $PROJECT_DIR/.env

Rollback ke situs lama:
  rm -f $SITE_LINK
  # aktifkan lagi server block yang tadi dinonaktifkan (berakhiran .disabled-*)
  nginx -t && systemctl reload nginx
EOF
