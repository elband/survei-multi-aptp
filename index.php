<?php
require_once 'db.php';
require_once 'auth.php';
$status = $_GET['status'] ?? '';
$survey_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Membuka /survei/ tanpa ID survei bukan halaman untuk responden — arahkan ke
// panel admin. Kalau sesi sudah ada, langsung ke dashboard tanpa login ulang.
if ($status !== 'success' && $survey_id === 0) {
    header('Location: ' . (isLoggedIn() ? 'admin.php' : 'login.php'));
    exit;
}

$survey = null;
if ($survey_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ? AND is_active = 1");
    $stmt->execute([$survey_id]);
    $survey = $stmt->fetch();

    if (!$survey && $status !== 'success') {
        showErrorPage("Tidak Ditemukan", "Survei tidak ditemukan atau sudah tidak aktif.", "/", "Kembali ke Beranda");
    }
}
?>
<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>
        <?php echo htmlspecialchars($survey['title'] ?? 'Survei Bandara'); ?>
    </title>
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <!-- FontAwesome for Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Custom CSS -->
    <link rel="stylesheet" href="assets/css/style.css?v=2">
</head>

<body>
    <div class="background-container">
        <div class="circle circle-1"></div>
        <div class="circle circle-2"></div>
        <div class="circle circle-3"></div>
    </div>

    <div class="app-container">
        <!-- Header -->
        <header class="app-header">
            <img src="assets/images/logo-apt.svg" alt="APT Pranoto Logo" class="logo">
            <h1>
                <?php echo htmlspecialchars($survey['title'] ?? 'Survei'); ?>
            </h1>
            <p>Bandar Udara Aji Pangeran Tumenggung Pranoto - Samarinda</p>

            <div class="progress-container <?php echo ($status === 'success') ? 'hidden' : ''; ?>">
                <div class="progress-bar" id="progress-bar"></div>
                <div class="steps-wrapper">
                    <!-- Indicators rendered by JS -->
                </div>
            </div>
        </header>

        <div id="success-message" class="success-message <?php echo ($status === 'success') ? '' : 'hidden'; ?>">
            <div class="icon-wrapper">
                <i class="fa-solid fa-circle-check"></i>
            </div>
            <h2>Terima Kasih!</h2>
            <p>Tanggapan Anda telah berhasil dikirimkan. Masukan Anda sangat berarti bagi kami.</p>
            <a href="index.php?id=<?php echo $survey_id; ?>" class="btn btn-primary mt-4"
                style="text-decoration:none; display:inline-block;">Isi Survei Lagi</a>
        </div>

        <form id="surveyForm" class="survey-form <?php echo ($status === 'success') ? 'hidden' : ''; ?>"
            action="proses_survei.php" method="POST">
            <input type="hidden" name="survey_id" value="<?php echo $survey_id; ?>">
            <!-- Dynamic Form Steps Container -->
            <div id="dynamic-form-container"></div>

            <!-- Footer Controls -->
            <div class="form-footer">
                <button type="button" id="btn-prev" class="btn btn-secondary hidden"><i
                        class="fa-solid fa-arrow-left"></i> Sebelumnya</button>
                <div class="spacer"></div>
                <button type="button" id="btn-next" class="btn btn-primary">Selanjutnya <i
                        class="fa-solid fa-arrow-right"></i></button>
                <button type="submit" id="btn-submit" class="btn btn-success hidden"><i
                        class="fa-solid fa-paper-plane"></i> Kirim Survei</button>
            </div>
        </form>
    </div>

    <!-- Copyright Footer Portofolio -->
    <div style="width: 100%; text-align: center; margin-top: 25px; margin-bottom: 25px; color: rgba(255, 255, 255, 0.9); font-size: 0.95rem; font-weight: 500; z-index: 10; position: relative;">
        &copy; <?php echo date('Y'); ?> Aplikasi Survei Dinamis. <br>
        Dibuat oleh <a href="#" style="color: #ffd43b; text-decoration: none; font-weight: 700;">IT BLU Kantor UPBU Kelas I A.P.T Pranoto</a>
    </div>

    <!-- Script -->
    <script>
        // Memastikan JSON valid dan aman dikirim ke JavaScript
        const surveyConfig = <?php
        $config = !empty($survey['config_json']) ? trim($survey['config_json']) : '[]';
        // Validasi sederhana: Jika bukan diawali [, paksa jadi array kosong
        echo (strpos($config, '[') === 0) ? $config : '[]';
        ?>;

        // Debugging untuk Anda (Hanya muncul di F12 Console)
        console.log("Survey Config Loaded:", surveyConfig);
    </script>
    <!-- Sedikit penyesuaian script.js untuk versi PHP Native (tanpa fetch api) -->
    <!-- Kita hanya perlu JS untuk render form dan navigasi step. -->
    <script src="assets/js/regions.js"></script>
    <script src="assets/js/script.js"></script>
</body>

</html>