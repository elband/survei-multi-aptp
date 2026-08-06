<?php
// Tampilkan semua error untuk debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';
require_once 'auth.php';
require_once 'image_lib.php';

try {
    checkLogin();
    verify_csrf($_GET['csrf'] ?? '');

    $id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    $survey_id = isset($_GET['survey_id']) ? (int)$_GET['survey_id'] : 0;

    if ($id > 0) {
        // Hapus file foto milik respons ini sebelum barisnya hilang,
        // karena setelah DELETE path-nya tidak bisa ditelusuri lagi.
        $sel = $pdo->prepare("SELECT raw_data FROM survey_responses WHERE id = ?");
        $sel->execute([$id]);
        $rawData = $sel->fetchColumn();
        if ($rawData !== false) {
            deleteResponseImages($rawData);
        }

        $stmt = $pdo->prepare("DELETE FROM survey_responses WHERE id = ?");
        $success = $stmt->execute([$id]);
        
        if (!$success) {
            showErrorPage("Gagal Menghapus", "Terjadi kesalahan saat menghapus data dari database.");
        }

        // Catat ke audit log
        auditLog($pdo, 'DELETE_RESPONSE', "response_id=$id survey_id=$survey_id");
    } else {
        showErrorPage("Data Tidak Valid", "ID Respons tidak ditemukan.");
    }

    $redirectUrl = ($survey_id > 0) ? "admin.php?id=" . $survey_id : "admin.php";
    
    // JS Redirect untuk menghindari error 'Headers already sent' di hosting gratis
    echo "<script>window.location.href = '$redirectUrl';</script>";
    exit;

} catch (Exception $e) {
    showErrorPage("Terjadi Kesalahan", $e->getMessage());
}
?>
