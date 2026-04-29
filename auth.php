<?php
/**
 * Middleware Sederhana untuk Cek Login Admin
 */
function checkLogin() {
    if (!isset($_SESSION['admin_id'])) {
        header("Location: login.php");
        exit;
    }
}

function isLoggedIn() {
    return isset($_SESSION['admin_id']);
}

/**
 * Anti CSRF - HMAC Based (Kompatibel dengan shared hosting & load balancer)
 * Token dihitung dari secret key + session_id + tanggal, tanpa simpan di session.
 */
function csrf_token() {
    // Gunakan ADMIN_PASS dari .env sebagai secret key
    $secret = $_ENV['ADMIN_PASS'] ?? 'csrf-fallback-secret-key';
    return hash_hmac('sha256', session_id() . date('Y-m-d'), $secret);
}

function verify_csrf($token) {
    $secret = $_ENV['ADMIN_PASS'] ?? 'csrf-fallback-secret-key';
    // Izinkan token hari ini DAN kemarin (agar tidak error di tengah malam)
    $valid_today     = hash_hmac('sha256', session_id() . date('Y-m-d'), $secret);
    $valid_yesterday = hash_hmac('sha256', session_id() . date('Y-m-d', strtotime('-1 day')), $secret);

    if (!hash_equals($valid_today, $token) && !hash_equals($valid_yesterday, $token)) {
        showErrorPage(
            "Akses Ditolak",
            "Validasi keamanan (CSRF Token) gagal. Silakan muat ulang halaman dan coba lagi."
        );
    }
}

/**
 * Menulis log audit ke tabel audit_logs
 * @param PDO    $pdo    Koneksi database
 * @param string $action Nama aksi, misal: LOGIN, CREATE_SURVEY, DELETE_RESPONSE
 * @param string $target Objek yang dikenai, misal: "survey_id=3"
 */
function auditLog(PDO $pdo, string $action, string $target = '') {
    try {
        $admin  = $_SESSION['admin_user'] ?? 'system';
        $ip     = $_SERVER['HTTP_X_FORWARDED_FOR']
                  ?? $_SERVER['REMOTE_ADDR']
                  ?? 'unknown';
        // Ambil hanya IP pertama jika ada beberapa (proxy chain)
        $ip = trim(explode(',', $ip)[0]);

        $stmt = $pdo->prepare(
            "INSERT INTO audit_logs (admin_user, action, target, ip_address)
             VALUES (?, ?, ?, ?)"
        );
        $stmt->execute([$admin, strtoupper($action), $target, $ip]);
    } catch (Exception $e) {
        // Jangan crash app jika logging gagal, cukup catat di error_log
        error_log("[AuditLog Error] " . $e->getMessage());
    }
}
?>
