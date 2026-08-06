<?php
/**
 * Endpoint Upload Gambar
 * -----------------------------------------------------------------
 * Dua mode:
 *   mode=illustration -> gambar ilustrasi soal (admin, butuh login + CSRF)
 *   mode=answer       -> jawaban responden (publik, tanpa sesi)
 *
 * Selalu membalas JSON dengan HTTP 200 (sukses maupun gagal), karena
 * JS di proyek ini tersedak body non-JSON.
 */

ob_start();
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/db.php';        // menyediakan $pdo, session_start(), $_ENV
require_once __DIR__ . '/auth.php';      // isLoggedIn()
require_once __DIR__ . '/image_lib.php'; // collectImageUploadFields()

ob_clean();
header('Content-Type: application/json');

// ---------------------------------------------------------------
// Konfigurasi
// ---------------------------------------------------------------
const UPLOAD_MAX_BYTES = 5 * 1024 * 1024; // 5 MB
const UPLOAD_BASE_DIR  = 'uploads';
// Batas resolusi sebelum re-encode GD. 24 MP ≈ 96 MB bitmap; cukup longgar
// untuk kamera HP (12 MP) tapi menahan gambar raksasa yang memicu OOM.
const MAX_PIXELS       = 24000000;

$ALLOWED_EXT  = ['jpg', 'jpeg', 'png', 'webp'];
$ALLOWED_MIME = ['image/jpeg', 'image/png', 'image/webp'];
// Peta tipe hasil deteksi -> ekstensi final (ekstensi dari user tidak dipakai)
$TYPE_TO_EXT = [
    IMAGETYPE_JPEG => 'jpg',
    IMAGETYPE_PNG  => 'png',
    IMAGETYPE_WEBP => 'webp',
];

// ---------------------------------------------------------------
// Helper
// ---------------------------------------------------------------
function respond($payload) {
    echo json_encode($payload);
    exit;
}

function fail($message) {
    respond(['success' => false, 'message' => $message]);
}

/**
 * Pembanding CSRF versi boolean.
 * Tidak bisa memakai verify_csrf() dari auth.php karena fungsi itu
 * mencetak halaman HTML penuh lalu exit -> merusak respons JSON.
 * Logikanya identik dengan auth.php (token hari ini ATAU kemarin).
 */
function csrf_ok($token) {
    if (!is_string($token) || $token === '') return false;
    $secret = $_ENV['ADMIN_PASS'] ?? 'csrf-fallback-secret-key';
    $today     = hash_hmac('sha256', session_id() . date('Y-m-d'), $secret);
    $yesterday = hash_hmac('sha256', session_id() . date('Y-m-d', strtotime('-1 day')), $secret);
    return hash_equals($today, $token) || hash_equals($yesterday, $token);
}

/**
 * Gambar ulang berkas via GD lalu tulis ke tujuan.
 *
 * Validasi tipe saja tidak cukup: sebuah PNG yang sah dengan payload PHP
 * ditempel di belakangnya tetap lolos finfo/getimagesize. Menggambar ulang
 * membuang semua byte di luar data piksel, sehingga payload apa pun hilang.
 *
 * @return bool false jika GD tidak tersedia atau gagal memproses.
 */
function reencodeImage($srcPath, $destPath, $imageType) {
    if (!extension_loaded('gd')) return false;

    switch ($imageType) {
        case IMAGETYPE_JPEG:
            if (!function_exists('imagecreatefromjpeg')) return false;
            $img = @imagecreatefromjpeg($srcPath);
            break;
        case IMAGETYPE_PNG:
            if (!function_exists('imagecreatefrompng')) return false;
            $img = @imagecreatefrompng($srcPath);
            break;
        case IMAGETYPE_WEBP:
            if (!function_exists('imagecreatefromwebp')) return false;
            $img = @imagecreatefromwebp($srcPath);
            break;
        default:
            return false;
    }
    if (!$img) return false;

    // Pertahankan transparansi untuk PNG & WEBP
    if ($imageType === IMAGETYPE_PNG || $imageType === IMAGETYPE_WEBP) {
        imagealphablending($img, false);
        imagesavealpha($img, true);
    }

    switch ($imageType) {
        case IMAGETYPE_JPEG: $ok = @imagejpeg($img, $destPath, 90); break;
        case IMAGETYPE_PNG:  $ok = @imagepng($img, $destPath, 6);   break;
        case IMAGETYPE_WEBP: $ok = @imagewebp($img, $destPath, 90); break;
        default:             $ok = false;
    }

    imagedestroy($img);

    if (!$ok) {
        if (is_file($destPath)) @unlink($destPath);
        return false;
    }
    return true;
}

/**
 * Pesan yang jelas untuk tiap kode error upload PHP.
 */
function uploadErrorMessage($code) {
    $limit = ini_get('upload_max_filesize');
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return "File melebihi batas server ($limit). Coba gunakan gambar yang lebih kecil.";
        case UPLOAD_ERR_FORM_SIZE:
            return 'File melebihi batas yang diizinkan formulir.';
        case UPLOAD_ERR_PARTIAL:
            return 'Upload terputus di tengah jalan. Silakan coba lagi.';
        case UPLOAD_ERR_NO_FILE:
            return 'Tidak ada file yang dikirim.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server tidak memiliki folder sementara untuk upload. Hubungi administrator.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server gagal menulis file ke disk. Hubungi administrator.';
        case UPLOAD_ERR_EXTENSION:
            return 'Upload dihentikan oleh ekstensi PHP di server.';
        default:
            return 'Upload gagal (kode ' . (int)$code . ').';
    }
}

/**
 * Jika Origin/Referer ada, host-nya harus sama dengan host kita.
 * Jika tidak ada sama sekali, tetap diizinkan (sebagian browser mobile
 * menghilangkannya).
 */
function sameOriginOk() {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($host === '') return true;

    foreach (['HTTP_ORIGIN', 'HTTP_REFERER'] as $key) {
        if (empty($_SERVER[$key])) continue;
        $h = parse_url($_SERVER[$key], PHP_URL_HOST);
        if ($h === null || $h === false) continue;
        if (strcasecmp($h, parse_url('http://' . $host, PHP_URL_HOST)) !== 0) {
            return false;
        }
    }
    return true;
}

// ---------------------------------------------------------------
// 1. Metode
// ---------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    fail('Metode tidak diizinkan.');
}

// ---------------------------------------------------------------
// 2. Deteksi overflow post_max_size
//    Jika body melebihi post_max_size, PHP mengosongkan $_FILES DAN $_POST
//    secara senyap. Harus dicek SEBELUM percabangan mode.
// ---------------------------------------------------------------
if (empty($_FILES) && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail('File terlalu besar untuk diterima server (batas: ' . ini_get('post_max_size') . ').');
}

$mode = $_POST['mode'] ?? '';
if (!in_array($mode, ['illustration', 'answer'], true)) {
    fail('Mode upload tidak dikenali.');
}

$survey_id = isset($_POST['survey_id']) ? (int)$_POST['survey_id'] : 0;
if ($survey_id <= 0) {
    fail('ID survei tidak valid.');
}

// ---------------------------------------------------------------
// 3. Otorisasi per mode
// ---------------------------------------------------------------
if ($mode === 'illustration') {
    if (!isLoggedIn()) {
        fail('Sesi Anda telah habis. Silakan muat ulang halaman dan login kembali.');
    }
    if (!csrf_ok($_POST['csrf'] ?? '')) {
        fail('Validasi keamanan (CSRF) gagal. Muat ulang halaman lalu coba lagi.');
    }
    $targetDir = UPLOAD_BASE_DIR . '/illustrations/' . $survey_id;
} else {
    // Mode publik: tidak ada sesi maupun CSRF yang bisa diandalkan.
    // Kontrol terkuat yang tersedia: field yang dituju harus benar-benar
    // ada di config survei aktif dengan tipe image-upload, sehingga
    // endpoint ini tidak bisa dipakai sebagai tempat buang file sembarangan.
    if (!sameOriginOk()) {
        fail('Permintaan ditolak.');
    }

    $field = $_POST['field'] ?? '';
    if ($field === '') {
        fail('Nama pertanyaan tidak dikirim.');
    }

    try {
        $stmt = $pdo->prepare("SELECT config_json FROM surveys WHERE id = ? AND is_active = 1");
        $stmt->execute([$survey_id]);
        $configJson = $stmt->fetchColumn();
    } catch (PDOException $e) {
        fail('Terjadi kesalahan pada server.');
    }

    if ($configJson === false) {
        fail('Survei tidak ditemukan atau sudah tidak aktif.');
    }

    $allowedFields = collectImageUploadFields(json_decode($configJson, true));
    if (!in_array($field, $allowedFields, true)) {
        fail('Pertanyaan ini tidak menerima unggahan gambar.');
    }

    $targetDir = UPLOAD_BASE_DIR . '/responses/' . $survey_id;
}

// ---------------------------------------------------------------
// 4-6. Validasi berkas dasar
// ---------------------------------------------------------------
if (!isset($_FILES['file'])) {
    fail('Tidak ada file yang dikirim.');
}
$file = $_FILES['file'];

if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
    fail(uploadErrorMessage($file['error'] ?? UPLOAD_ERR_NO_FILE));
}
if (!is_uploaded_file($file['tmp_name'])) {
    fail('Berkas tidak valid.');
}
$size = (int)$file['size'];
if ($size <= 0) {
    fail('File kosong.');
}
if ($size > UPLOAD_MAX_BYTES) {
    fail('Ukuran file melebihi batas ' . (UPLOAD_MAX_BYTES / 1024 / 1024) . ' MB.');
}

// ---------------------------------------------------------------
// 7. Whitelist ekstensi (GIF & SVG ditolak; SVG adalah vektor stored-XSS)
// ---------------------------------------------------------------
$origExt = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
if (!in_array($origExt, $ALLOWED_EXT, true)) {
    fail('Format file tidak didukung. Gunakan JPG, PNG, atau WEBP.');
}

// ---------------------------------------------------------------
// 8. MIME asli berkas (jangan percaya $file['type'] yang dikirim client)
// ---------------------------------------------------------------
$mime = '';
if (function_exists('finfo_open')) {
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    if ($finfo) {
        $mime = (string)finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
    }
}
if ($mime === '' && function_exists('mime_content_type')) {
    $mime = (string)mime_content_type($file['tmp_name']);
}
if (!in_array($mime, $ALLOWED_MIME, true)) {
    fail('Isi file bukan gambar yang didukung. Gunakan JPG, PNG, atau WEBP.');
}

// Ekstensi harus konsisten dengan MIME
$extToMime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
if ($extToMime[$origExt] !== $mime) {
    fail('Ekstensi file tidak sesuai dengan isinya.');
}

// ---------------------------------------------------------------
// 9. Pastikan benar-benar gambar yang bisa dibaca.
//    getimagesize() adalah fungsi core PHP (bukan GD) dan menolak file
//    .jpg yang sebenarnya skrip PHP berheader palsu.
// ---------------------------------------------------------------
$info = @getimagesize($file['tmp_name']);
if (!$info || !isset($info[2]) || !isset($TYPE_TO_EXT[$info[2]])) {
    fail('File tidak dapat dibaca sebagai gambar.');
}

// Pagar dimensi: GD memuat seluruh bitmap ke memori (~4 byte/piksel).
// Tanpa ini, gambar 100 MP akan menghabiskan memory_limit dan membuat
// proses mati tanpa pesan yang berguna.
$pixels = (int)($info[0] ?? 0) * (int)($info[1] ?? 0);
if ($pixels <= 0) {
    fail('Dimensi gambar tidak valid.');
}
if ($pixels > MAX_PIXELS) {
    fail('Resolusi gambar terlalu besar (maks ' . (MAX_PIXELS / 1000000) . ' megapiksel).');
}

// ---------------------------------------------------------------
// 10-12. Simpan
// ---------------------------------------------------------------
$ext      = $TYPE_TO_EXT[$info[2]];          // ekstensi final dari hasil deteksi
$filename = bin2hex(random_bytes(16)) . '.' . $ext;
$absDir   = __DIR__ . '/' . $targetDir;

if (!is_dir($absDir) && !@mkdir($absDir, 0755, true) && !is_dir($absDir)) {
    fail('Direktori upload tidak bisa dibuat. Periksa izin folder uploads/.');
}

$relPath = $targetDir . '/' . $filename;
$absPath = $absDir . '/' . $filename;

// Gambar ulang via GD: membuang byte apa pun di luar data piksel, sehingga
// polyglot (gambar sah + payload skrip menempel) tidak pernah tersimpan utuh.
if (!reencodeImage($file['tmp_name'], $absPath, $info[2])) {
    fail('Gambar tidak dapat diproses server. Coba format atau berkas lain.');
}

respond([
    'success' => true,
    'path'    => $relPath,
    'name'    => basename((string)$file['name']),
    'size'    => filesize($absPath) ?: $size,
    'width'   => (int)($info[0] ?? 0),
    'height'  => (int)($info[1] ?? 0),
]);
