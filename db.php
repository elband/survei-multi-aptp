<?php
/**
 * Database Connection using PDO
 * Konfigurasi Native PHP
 */

session_start();

function loadEnv($path) {
    if (!file_exists($path) || !is_readable($path)) return;
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    
    foreach ($lines as $line) {
        $line = trim($line);
        // Skip komentar (#) atau baris kosong
        if ($line === '' || $line[0] === '#') continue;
        
        // Cari posisi '=' pertama
        $pos = strpos($line, '=');
        if ($pos !== false) {
            $name  = trim(substr($line, 0, $pos));
            $value = trim(substr($line, $pos + 1));
            
            // Hapus tanda kutip jika ada di awal dan akhir nilai
            if (strlen($value) > 1 && (
                ($value[0] === '"' && substr($value, -1) === '"') || 
                ($value[0] === "'" && substr($value, -1) === "'")
            )) {
                $value = substr($value, 1, -1);
            }
            
            // Simpan ke $_ENV dan $_SERVER untuk kompatibilitas maksimal
            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv("$name=$value");
        }
    }
}

// Load .env dari direktori yang sama dengan db.php
loadEnv(__DIR__ . '/.env');

// Database Config
$host = $_ENV['DB_HOST'] ?? 'localhost';
$db   = $_ENV['DB_NAME'] ?? 'survei_apt_pranoto_multi';
$user = $_ENV['DB_USER'] ?? 'root';
$pass = $_ENV['DB_PASSWORD'] ?? '';
$port = $_ENV['DB_PORT'] ?? '3306';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset;port=$port";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Koneksi database gagal: " . $e->getMessage());
}

// Helper: Konversi array ke string untuk disimpan di database
function arrToStr($val) {
    if (!$val) return null;
    return is_array($val) ? implode(', ', $val) : (string)$val;
}

function showErrorPage($title, $message, $redirectUrl = "admin.php", $btnText = "Kembali ke Dashboard") {
    echo '<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>'.$title.'</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: "Outfit", sans-serif; background: #f4f7fe; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; color: #2b2b2b; }
        .error-card { background: #fff; padding: 40px; border-radius: 20px; box-shadow: 0 10px 30px rgba(0,0,0,0.05); text-align: center; max-width: 400px; width: 90%; }
        .error-icon { font-size: 4rem; color: #fa5252; margin-bottom: 20px; }
        .error-title { font-size: 1.5rem; font-weight: 700; margin: 0 0 10px; color: #1a1a2e; }
        .error-msg { font-size: 1rem; color: #666; margin-bottom: 30px; line-height: 1.5; }
        .btn { display: inline-block; padding: 12px 24px; background: #1971c2; color: #fff; text-decoration: none; border-radius: 12px; font-weight: 600; transition: all 0.2s; }
        .btn:hover { background: #1864ab; transform: translateY(-2px); }
    </style>
</head>
<body>
    <div class="error-card">
        <div class="error-icon"><i class="fa-solid fa-triangle-exclamation"></i></div>
        <h2 class="error-title">'.$title.'</h2>
        <p class="error-msg">'.$message.'</p>
        <a href="'.$redirectUrl.'" class="btn"><i class="fa-solid fa-arrow-left"></i> '.$btnText.'</a>
    </div>
</body>
</html>';
    exit;
}
?>
