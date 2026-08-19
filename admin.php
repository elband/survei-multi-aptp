<?php
require_once 'db.php';
require_once 'auth.php';
require_once 'image_lib.php';
checkLogin();

$survey_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    verify_csrf($_POST['csrf'] ?? '');
    
    if ($_POST['action'] === 'create_survey') {
        $title = trim($_POST['title'] ?? 'Survei Baru');
        $defaultConfig = json_encode([[ 'id' => 'step-1', 'title' => 'Bagian 1', 'description' => '', 'questions' => [] ]]);
        $stmt = $pdo->prepare("INSERT INTO surveys (title, config_json) VALUES (?, ?)");
        $stmt->execute([$title, $defaultConfig]);
        $newId = $pdo->lastInsertId();
        auditLog($pdo, 'CREATE_SURVEY', "survey_id=$newId title=$title");
        header("Location: admin.php?id=" . $newId);
        exit;
    }
    if ($_POST['action'] === 'save_config' && $survey_id > 0) {
        $json = $_POST['config_json'];
        $title = $_POST['title'] ?? 'Survei';

        // Hapus gambar ilustrasi yang sudah tidak dipakai lagi.
        // save_config adalah satu-satunya hook andal: editor sepenuhnya
        // client-side, jadi admin bisa saja membatalkan perubahannya.
        $selOld = $pdo->prepare("SELECT config_json FROM surveys WHERE id = ?");
        $selOld->execute([$survey_id]);
        $oldJson = $selOld->fetchColumn();
        if ($oldJson !== false) {
            $oldUrls = collectIllustrationUrls(json_decode($oldJson, true));
            $newUrls = collectIllustrationUrls(json_decode($json, true));
            foreach (array_diff($oldUrls, $newUrls) as $gone) {
                deleteUploadFile($gone);
            }
        }

        $stmt = $pdo->prepare("UPDATE surveys SET title = ?, config_json = ? WHERE id = ?");
        $stmt->execute([$title, $json, $survey_id]);
        auditLog($pdo, 'SAVE_CONFIG', "survey_id=$survey_id");
        echo json_encode(['success' => true]);
        exit;
    }
}
if (isset($_GET['delete_survey'])) {
    verify_csrf($_GET['csrf'] ?? '');
    $delId = (int)$_GET['delete_survey'];

    // ON DELETE CASCADE menghapus baris respons di dalam MySQL, sehingga PHP
    // tidak pernah melihatnya. Karena itu file dibersihkan per-direktori di sini
    // — inilah alasan tata letak folder memakai lapisan {survey_id}.
    removeUploadDir("uploads/responses/$delId");
    removeUploadDir("uploads/illustrations/$delId");

    $stmt = $pdo->prepare("DELETE FROM surveys WHERE id = ?");
    $stmt->execute([$delId]);
    auditLog($pdo, 'DELETE_SURVEY', "survey_id=$delId");
    header("Location: admin.php");
    exit;
}
if (isset($_GET['toggle_status'])) {
    verify_csrf($_GET['csrf'] ?? '');
    $tId = (int)$_GET['toggle_status'];
    $stmt = $pdo->prepare("UPDATE surveys SET is_active = NOT is_active WHERE id = ?");
    $stmt->execute([$tId]);
    auditLog($pdo, 'TOGGLE_STATUS', "survey_id=$tId");
    header("Location: admin.php");
    exit;
}
if (isset($_GET['duplicate_survey'])) {
    verify_csrf($_GET['csrf'] ?? '');
    $dId = (int)$_GET['duplicate_survey'];
    $stmt = $pdo->prepare("INSERT INTO surveys (title, config_json, is_active) SELECT CONCAT(title, ' (Copy)'), config_json, 0 FROM surveys WHERE id = ?");
    $stmt->execute([$dId]);
    $newId = $pdo->lastInsertId();

    // Salin file ilustrasi ke direktori survei baru agar tidak ada file yang
    // dipakai bersama dua survei — kalau dibiarkan, menyimpan salah satu survei
    // bisa menghapus gambar yang masih dipakai survei lainnya.
    $selDup = $pdo->prepare("SELECT config_json FROM surveys WHERE id = ?");
    $selDup->execute([$newId]);
    $dupJson = $selDup->fetchColumn();
    if ($dupJson !== false) {
        $dupCfg = json_decode($dupJson, true);
        if (is_array($dupCfg)) {
            $rewritten = duplicateIllustrations($dupCfg, $newId);
            $updDup = $pdo->prepare("UPDATE surveys SET config_json = ? WHERE id = ?");
            $updDup->execute([json_encode($rewritten), $newId]);
        }
    }
    auditLog($pdo, 'DUPLICATE_SURVEY', "from_id=$dId to_id=$newId");
    header("Location: admin.php");
    exit;
}

$activeSurvey = null;
if ($survey_id > 0) {
    $stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
    $stmt->execute([$survey_id]);
    $activeSurvey = $stmt->fetch();
    if (!$activeSurvey) { header("Location: admin.php"); exit; }
}

$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 25;
$search = isset($_GET['search']) ? $_GET['search'] : '';
$offset = ($page - 1) * $limit;

$where = "";
$params = [];
$totalResponden = 0;
$todayResponden = 0;
$results = [];
$totalPages = 1;
$allSurveys = [];

if ($survey_id > 0) {
    $where = "WHERE survey_id = ?";
    $params = [$survey_id];
    if (!empty($search)) {
        $where .= " AND LOWER(raw_data) LIKE LOWER(?)";
        $params[] = "%$search%";
    }
    $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM survey_responses $where");
    $stmtCount->execute($params);
    $total = $stmtCount->fetchColumn();
    $totalPages = max(1, ceil($total / $limit));

    $stmtData = $pdo->prepare("SELECT * FROM survey_responses $where ORDER BY submitted_at DESC LIMIT $limit OFFSET $offset");
    $stmtData->execute($params);
    $results = $stmtData->fetchAll();

    $stmtAllData = $pdo->prepare("SELECT raw_data FROM survey_responses $where");
    $stmtAllData->execute($params);
    $allRawDataForAnalytics = $stmtAllData->fetchAll(PDO::FETCH_COLUMN);

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ?");
    $stmt->execute([$survey_id]);
    $totalResponden = $stmt->fetchColumn();
    
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ? AND DATE(submitted_at) = CURDATE()");
    $stmt->execute([$survey_id]);
    $todayResponden = $stmt->fetchColumn();

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM survey_responses WHERE survey_id = ? AND MONTH(submitted_at) = MONTH(CURDATE()) AND YEAR(submitted_at) = YEAR(CURDATE())");
    $stmt->execute([$survey_id]);
    $monthResponden = $stmt->fetchColumn();

    $totalPertanyaan = 0;
    if (!empty($activeSurvey['config_json'])) {
        $cfg = json_decode($activeSurvey['config_json'], true);
        if ($cfg && is_array($cfg)) {
            foreach ($cfg as $step) {
                if (!empty($step['questions']) && is_array($step['questions'])) {
                    $totalPertanyaan += count($step['questions']);
                }
            }
        }
    }
} else {
    $stmt = $pdo->query("SELECT s.*, 
        (SELECT COUNT(*) FROM survey_responses sr WHERE sr.survey_id = s.id) as total_responses,
        (SELECT MAX(submitted_at) FROM survey_responses sr WHERE sr.survey_id = s.id) as last_response
        FROM surveys s ORDER BY created_at DESC");
    $allSurveys = $stmt->fetchAll();

    // Fetch Global Stats
    $globalActiveSurveys = $pdo->query("SELECT COUNT(*) FROM surveys WHERE is_active = 1")->fetchColumn();
    $globalTotalResponses = $pdo->query("SELECT COUNT(*) FROM survey_responses")->fetchColumn();
    
    // Top Survey This Month
    $topSurveyStmt = $pdo->query("
        SELECT s.title, COUNT(sr.id) as monthly_count 
        FROM survey_responses sr 
        JOIN surveys s ON sr.survey_id = s.id 
        WHERE MONTH(sr.submitted_at) = MONTH(CURDATE()) AND YEAR(sr.submitted_at) = YEAR(CURDATE())
        GROUP BY s.id 
        ORDER BY monthly_count DESC 
        LIMIT 1
    ");
    $topSurveyData = $topSurveyStmt->fetch();
    $topSurveyName = $topSurveyData ? $topSurveyData['title'] : '-';
    $topSurveyCount = $topSurveyData ? $topSurveyData['monthly_count'] : 0;
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin Premium - PHP Native</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        /* ===================== RESPONSIVE - ALL DEVICES ===================== */

        /* Drag & Drop */
        .question-card.dragging { opacity: 0.5; border: 2px dashed #1971c2; background: #f8f9fa; }
        .q-drag-handle { cursor: grab; }
        .q-drag-handle:active { cursor: grabbing; }

        /* Modal Detail Styling */
        .detail-section { margin-bottom: 18px; }
        .detail-section-title {
            font-weight: 700; font-size: 0.85rem; text-transform: uppercase;
            letter-spacing: 0.05em; color: #1971c2; padding: 6px 0;
            border-bottom: 2px solid #dbe4ff; margin-bottom: 10px;
        }
        .detail-item {
            display: flex; justify-content: space-between; align-items: flex-start;
            padding: 8px 0; border-bottom: 1px solid #f1f3f5; gap: 12px; font-size: 0.9rem;
        }
        .detail-key { color: #666; flex: 1; font-weight: 500; min-width: 140px; }
        .detail-val { color: #1a1a2e; font-weight: 600; flex: 1.5; text-align: right; }
        .detail-tags { display: flex; flex-wrap: wrap; gap: 5px; justify-content: flex-end; }
        .detail-tag {
            background: #dbe4ff; color: #1971c2; padding: 3px 10px;
            border-radius: 20px; font-size: 0.8rem; font-weight: 600;
        }
        .timestamp-badge {
            background: #f1f3f5; border-radius: 8px; padding: 8px 14px;
            font-size: 0.85rem; color: #555; margin-bottom: 16px; display: inline-block;
        }

        /* === TABLET (max 1024px) === */
        @media (max-width: 1024px) {
            .sidebar { width: 200px; }
            .main-content { padding: 20px; }
            .stats-row { grid-template-columns: repeat(2, 1fr); }
            .page-header { top: -20px; padding: 20px 20px 15px; margin: -20px -20px 20px -20px; }
        }

        /* === MOBILE (max 768px) - OFF-CANVAS SIDEBAR === */
        .mobile-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 990; backdrop-filter: blur(2px); opacity: 0; transition: opacity 0.3s;
        }
        .mobile-overlay.show { display: block; opacity: 1; }
        .btn-mobile-menu { display: none; margin-right: 15px; border: none; background: transparent; font-size: 1.4rem; color: var(--primary); cursor: pointer; }

        @media (max-width: 768px) {
            body { font-size: 14px; }
            .btn-mobile-menu { display: block; }
            
            /* Sidebar becomes a sliding drawer */
            .sidebar { 
                position: fixed; top: 0; left: -280px; width: 260px; height: 100vh; 
                z-index: 1000; transition: left 0.3s cubic-bezier(0.4, 0, 0.2, 1); 
            }
            .sidebar.open { left: 0; }
            
            .main-content { padding: 15px; }
            .page-header { display: flex; flex-direction: column; align-items: flex-start; gap: 12px; padding: 15px; margin: -15px -15px 20px -15px; }
            .header-actions { width: 100%; flex-wrap: wrap; }
            .header-actions input[type=text] { width: 100% !important; }
            .header-actions form { flex-direction: column; width: 100%; }
            .header-actions button, .header-actions .btn { width: 100%; justify-content: center; }

            /* Survey Editor Responsive */
            .step-header { flex-direction: column; align-items: stretch; padding: 15px; }
            .step-header-fields { flex-direction: column; }
            .step-num { align-self: flex-start; }
            .step-header-actions { justify-content: flex-end; }
            
            .question-card-header { flex-wrap: wrap; }
            .q-type-select { width: 100%; flex: 1 1 100%; margin-top: 5px; }
            
            .q-row { flex-direction: row; flex-wrap: wrap; gap: 8px; align-items: flex-end; margin-bottom: 8px; }
            .q-field { min-width: calc(50% - 4px); flex: 1 1 calc(50% - 4px); }
            .q-field-xs { flex: 0 0 100%; min-width: 100%; }
            .q-field-sm { flex: 1 1 calc(50% - 4px); min-width: calc(50% - 4px); }
            .question-card-body { padding: 10px; }
            .q-field label { font-size: 0.72rem; margin-bottom: 4px; }
            .stats-row { grid-template-columns: 1fr 1fr; gap: 10px; }
            .stat-card { padding: 12px; }
            .stat-icon { width: 38px; height: 38px; font-size: 1rem; }
            .stat-info h3 { font-size: 1.3rem; }

            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; top: -14px; padding: 14px 10px 10px; margin: -14px -10px 20px -10px; }
            .header-actions { width: 100%; flex-wrap: wrap; gap: 8px; }
            .header-actions input[type=text] { width: 100% !important; }
            .header-actions button, .header-actions .btn { flex: 1; font-size: 0.8rem; }

            /* Card table → list view on mobile */
            #results-table thead { display: none; }
            #results-table, #results-table tbody,
            #results-table tr, #results-table td { display: block; width: 100%; box-sizing: border-box; }
            #results-table tr {
                margin-bottom: 14px;
                border: 1px solid #e9ecef;
                border-radius: 10px;
                padding: 10px 12px;
                background: #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            #results-table td {
                text-align: right;
                padding: 6px 0;
                border: none;
                font-size: 0.88rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            #results-table td::before {
                content: attr(data-label);
                font-weight: 700;
                font-size: 0.78rem;
                color: #888;
                text-align: left;
                text-transform: uppercase;
                letter-spacing: 0.04em;
            }
            #results-table td:first-child { border-bottom: 1px solid #f1f3f5; padding-bottom: 8px; }

            /* Modal on mobile */
            .modal-content { margin: 10px; width: calc(100% - 20px); max-height: 90vh; }
            .detail-item { flex-direction: column; gap: 4px; }
            .detail-val { text-align: left; }
            .detail-tags { justify-content: flex-start; }
        }

        /* === SMALL MOBILE (max 480px) === */
        @media (max-width: 480px) {
            .stats-row { grid-template-columns: 1fr; }
            .stat-card { flex-direction: row; align-items: center; gap: 12px; }
        }
    </style>
</head>
<body>
    <div id="mobile-overlay" class="mobile-overlay" onclick="toggleSidebar()"></div>
    <script>
        function toggleSidebar() {
            document.querySelector('.sidebar').classList.toggle('open');
            document.getElementById('mobile-overlay').classList.toggle('show');
        }
    </script>
    <div class="sidebar">
        <div class="logo-area">
            <img src="assets/images/logo-apt.svg" alt="Logo">
            <h2>Admin Panel</h2>
            <p class="sidebar-sub">Survei APT Pranoto</p>
        </div>
                <nav>
            <?php if ($survey_id === 0): ?>
                <a href="admin.php" class="active">
                    <i class="fa-solid fa-layer-group"></i>
                    <span>Daftar Survei</span>
                </a>
                <a href="audit_log.php">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Audit Log</span>
                </a>
            <?php else: ?>
                <a href="admin.php" style="background:#f1f3f5; color:#333;">
                    <i class="fa-solid fa-arrow-left"></i>
                    <span>Kembali</span>
                </a>
                <a href="#" class="active" data-tab="results">
                    <i class="fa-solid fa-chart-bar"></i>
                    <span>Hasil Survei</span>
                </a>
                <a href="#" data-tab="analytics">
                    <i class="fa-solid fa-chart-pie"></i>
                    <span>Analytics</span>
                </a>
                <a href="#" data-tab="editor">
                    <i class="fa-solid fa-pen-to-square"></i>
                    <span>Edit Pertanyaan</span>
                </a>
                <a href="audit_log.php">
                    <i class="fa-solid fa-shield-halved"></i>
                    <span>Audit Log</span>
                </a>
            <?php endif; ?>
        </nav>
        <div class="logout-area">
            <a href="logout.php" id="btn-logout-native">
                <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
            </a>
        </div>
    </div>

    <div class="main-content">

        <?php if ($survey_id === 0): ?>
        <!-- ======================== DASHBOARD SURVEI ======================== -->
        <div class="page-header" style="margin-bottom: 20px;">
            <div style="display:flex; align-items:center; gap:12px;">
                <button class="btn-mobile-menu" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1>Daftar Survei</h1>
                    <p class="page-sub">Pilih survei untuk diedit atau buat baru</p>
                </div>
            </div>
            <div class="header-actions">
                <form action="admin.php" method="POST" style="display:flex; gap:10px;">
                    <input type="hidden" name="csrf" value="<?php echo csrf_token(); ?>">
                    <input type="hidden" name="action" value="create_survey">
                    <input type="text" name="title" placeholder="Nama Survei Baru..." required style="padding:8px; border:1px solid #ddd; border-radius:10px;">
                    <button type="submit" class="btn btn-primary"><i class="fa-solid fa-plus"></i> Buat Survei</button>
                </form>
            </div>
        </div>

        <div class="stats-row" style="margin-bottom: 20px;">
            <div class="stat-card">
                <div class="stat-icon" style="background:#e7f5ff;color:#1971c2"><i class="fa-solid fa-layer-group"></i></div>
                <div class="stat-info"><p>Survei Aktif</p><h3><?php echo isset($globalActiveSurveys) ? $globalActiveSurveys : 0; ?></h3></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#ebfbee;color:#2b8a3e"><i class="fa-solid fa-users"></i></div>
                <div class="stat-info"><p>Total Keseluruhan Responden</p><h3><?php echo isset($globalTotalResponses) ? $globalTotalResponses : 0; ?></h3></div>
            </div>
            <div class="stat-card">
                <div class="stat-icon" style="background:#fff3bf;color:#e67700"><i class="fa-solid fa-fire"></i></div>
                <div class="stat-info">
                    <p>Terpopuler Bulan Ini</p>
                    <h3 style="font-size: 1.1rem; margin-top:4px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; max-width:180px;" title="<?php echo htmlspecialchars(isset($topSurveyName) ? $topSurveyName : '-'); ?>">
                        <?php echo htmlspecialchars(isset($topSurveyName) ? $topSurveyName : '-'); ?>
                    </h3>
                </div>
            </div>
        </div>

        <div class="card" style="overflow-x:auto;">
            <table id="results-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Judul Survei</th>
                        <th>Dibuat Tanggal</th>
                        <th>Status</th>
                        <th>Jumlah Responden</th>
                        <th>Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($allSurveys as $s): ?>
                        <tr>
                            <td data-label="ID">#<?php echo $s['id']; ?></td>
                            <td data-label="Judul"><strong><?php echo htmlspecialchars($s['title']); ?></strong></td>
                            <td data-label="Dibuat" style="font-size:0.85rem;color:#888"><?php echo date('d/m/Y', strtotime($s['created_at'])); ?></td>
                            
                            <td data-label="Status">
                                <a href="admin.php?toggle_status=<?php echo $s['id']; ?>&csrf=<?php echo csrf_token(); ?>" style="text-decoration:none;">
                                    <?php if(isset($s['is_active']) && $s['is_active'] == 1): ?>
                                        <span class="badge" style="background:#ebfbee; color:#2b8a3e; padding:4px 8px;"><i class="fa-solid fa-circle" style="font-size:0.6rem;"></i> Aktif</span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#fff5f5; color:#fa5252; padding:4px 8px;"><i class="fa-solid fa-circle" style="font-size:0.6rem;"></i> Ditutup</span>
                                    <?php endif; ?>
                                </a>
                            </td>

                            <td data-label="Responden">
                                <span class="badge" style="background:#eef3fc;color:#1971c2"><?php echo $s['total_responses']; ?> Respons</span>
                                <?php if(!empty($s['last_response'])): ?>
                                    <div style="font-size:0.75rem; color:#888; margin-top:4px;"><i class="fa-regular fa-clock"></i> Terakhir: <?php echo date('d/m/y H:i', strtotime($s['last_response'])); ?></div>
                                <?php else: ?>
                                    <div style="font-size:0.75rem; color:#bbb; margin-top:4px;">Belum ada respons</div>
                                <?php endif; ?>
                            </td>
                            <td data-label="Aksi" style="text-align:right;">
                                <div style="display:inline-flex; align-items:center; gap:6px;">
                                    <a href="admin.php?id=<?php echo $s['id']; ?>" class="btn-icon" style="background:#eef3fc;color:#1971c2;border-radius:8px;" title="Buka Dashboard"><i class="fa-solid fa-folder-open"></i></a>
                                    <a href="index.php?id=<?php echo $s['id']; ?>" target="_blank" class="btn-icon" style="background:#fff3bf;color:#e67700;border-radius:8px;" title="Lihat Form Survei"><i class="fa-solid fa-eye"></i></a>
                                    <a href="#" onclick="copyLink('index.php?id=<?php echo $s['id']; ?>'); return false;" class="btn-icon" style="background:#ebfbee;color:#2b8a3e;border-radius:8px;" title="Salin Link"><i class="fa-solid fa-link"></i></a>
                                    <a href="#" onclick="showQRModal('index.php?id=<?php echo $s['id']; ?>', <?php echo htmlspecialchars(json_encode($s['title'])); ?>); return false;" class="btn-icon" style="background:#f3f0ff;color:#6741d9;border-radius:8px;" title="QR Code"><i class="fa-solid fa-qrcode"></i></a>
                                    <a href="admin.php?duplicate_survey=<?php echo $s['id']; ?>&csrf=<?php echo csrf_token(); ?>" class="btn-icon" style="background:#fff4e6;color:#fd7e14;border-radius:8px;" title="Duplikat"><i class="fa-regular fa-copy"></i></a>
                                    <a href="#" onclick="showDeleteSurveyModal(event, <?php echo $s['id']; ?>, <?php echo htmlspecialchars(json_encode($s['title'])); ?>); return false;" class="btn-icon" style="background:#fff5f5;color:#fa5252;border-radius:8px;" title="Hapus"><i class="fa-solid fa-trash"></i></a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if(empty($allSurveys)): ?>
                        <tr><td colspan="6" style="text-align:center;padding:30px;">Belum ada survei.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <?php else: ?>
        <!-- ======================== TAB: HASIL SURVEI ======================== -->
        <div id="tab-results" class="tab-content active">
            <div class="page-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <button class="btn-mobile-menu" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h1>Hasil Survei</h1>
                        <p class="page-sub">Daftar respons responden (PHP Native SSR)</p>
                    </div>
                </div>
                <div class="header-actions">
                    <form action="admin.php" method="GET" style="display:flex; gap:10px; align-items:center;">
                        <input type="hidden" name="id" value="<?php echo $survey_id; ?>">
                        <input type="text" name="search" placeholder="Cari nama..." value="<?php echo htmlspecialchars($search); ?>" style="padding:8px; border:1px solid #ddd; border-radius:10px;">
                        <button type="submit" class="btn btn-primary">Cari</button>
                        <button type="button" class="btn" style="background:#f3f0ff; color:#6741d9; border:1px solid #6741d9; padding:8px 16px; border-radius:10px; font-weight:600;" onclick="document.getElementById('spreadsheet-modal').style.display='flex';"><i class="fa-solid fa-table"></i> Tabel Lengkap</button>
                        <a href="export_excel.php?id=<?php echo $survey_id; ?>" class="btn" style="background:#fff; color:#2b8a3e; border:1px solid #2b8a3e; padding:8px 16px; border-radius:10px; text-decoration:none; font-weight:600;"><i class="fa-solid fa-file-excel"></i> Export Excel</a>
                        <a href="index.php?id=<?php echo $survey_id; ?>" target="_blank" class="btn" style="background:#1e3a8a; color:#fff; border:none; padding:8px 16px; border-radius:10px; text-decoration:none; font-weight:600;"><i class="fa-solid fa-eye"></i> Lihat Form</a>
                    </form>
                </div>
            </div>

            <div class="stats-row">
                <div class="stat-card">
                    <div class="stat-icon" style="background:#e7f5ff;color:#1971c2"><i class="fa-solid fa-users"></i></div>
                    <div class="stat-info"><p>Total Responden</p><h3><?php echo $totalResponden; ?></h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#fff3bf;color:#e67700"><i class="fa-solid fa-calendar-day"></i></div>
                    <div class="stat-info"><p>Hari Ini</p><h3><?php echo $todayResponden; ?></h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#ebfbee;color:#2b8a3e"><i class="fa-solid fa-calendar-week"></i></div>
                    <div class="stat-info"><p>Bulan Ini</p><h3><?php echo isset($monthResponden) ? $monthResponden : 0; ?></h3></div>
                </div>
                <div class="stat-card">
                    <div class="stat-icon" style="background:#f3f0ff;color:#6741d9"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div class="stat-info"><p>Pertanyaan Aktif</p><h3><?php echo isset($totalPertanyaan) ? $totalPertanyaan : 0; ?></h3></div>
                </div>
            </div>

            <?php
            // Kumpulkan semua kolom/pertanyaan dari config_json
            $tableHeaders = [];
            $tableKeys = [];
            if (!empty($activeSurvey['config_json'])) {
                $cfg = json_decode($activeSurvey['config_json'], true);
                if ($cfg && is_array($cfg)) {
                    foreach ($cfg as $step) {
                        if (!empty($step['questions'])) {
                            $walk = function($qs) use (&$walk, &$tableHeaders, &$tableKeys) {
                                foreach ($qs as $q) {
                                    if ($q['type'] === 'row') {
                                        $walk($q['questions']);
                                    } else {
                                        if (!empty($q['name'])) {
                                            $tableHeaders[] = $q['label'] ?: $q['name'];
                                            $tableKeys[] = $q['name'];
                                        }
                                    }
                                }
                            };
                            $walk($step['questions']);
                        }
                    }
                }
            }

            // Fallback jika kosong, ambil dari raw_data row pertama
            if (empty($tableKeys) && count($results) > 0) {
                $firstData = json_decode($results[0]['raw_data'], true) ?: [];
                foreach ($firstData as $k => $v) {
                    $tableHeaders[] = $k;
                    $tableKeys[] = $k;
                }
            }
            ?>
            <div class="card" style="overflow-x:auto;">
                <table id="results-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Waktu Pengisian</th>
                            <th>Data Utama</th>
                            <th>Detail</th>
                            <th>Hapus</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $idx => $row): 
                            $data = json_decode($row['raw_data'], true) ?: [];
                            $preview = "";
                            $count = 0;
                            $hasPhoto = false;
                            foreach($data as $k => $v) {
                                if(isResponseImage($v)) { $hasPhoto = true; continue; }
                                if($count >= 2) continue;
                                if(is_string($v) && strlen($v) > 0 && strlen($v) < 30) {
                                    $preview .= htmlspecialchars($v) . " | ";
                                    $count++;
                                }
                            }
                            $preview = rtrim($preview, " | ");
                            if(empty($preview)) $preview = "Data Tersimpan";
                        ?>
                            <tr>
                                <td data-label="#"><?php echo $offset + $idx + 1; ?></td>
                                <td data-label="Waktu" style="font-size:.85rem;color:#888"><?php echo date('d/m/Y H:i', strtotime($row['submitted_at'])); ?></td>
                                <td data-label="Data"><strong><?php echo $preview; ?></strong><?php if ($hasPhoto): ?> <i class="fa-solid fa-camera" style="color:#1971c2" title="Berisi foto"></i><?php endif; ?></td>
                                <td data-label="Detail">
                                    <button class="btn" style="background:#eef3fc;color:#1971c2;border:none;padding:6px 14px;border-radius:8px;cursor:pointer;font-size:.85rem" onclick="showDetail(<?php echo htmlspecialchars(json_encode($row), ENT_QUOTES); ?>)"><i class="fa-solid fa-eye"></i> Detail</button>
                                </td>
                                <td data-label="Hapus">
                                    <a href="hapus_data.php?id=<?php echo $row['id']; ?>&survey_id=<?php echo $survey_id; ?>" class="btn-icon" style="color:#e03131" onclick="showDeleteResponseModal(event, <?php echo $row['id']; ?>, <?php echo $survey_id; ?>, <?php echo htmlspecialchars(json_encode($preview), ENT_QUOTES, 'UTF-8'); ?>)"><i class="fa-solid fa-trash"></i></a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($results)): ?>
                            <tr><td colspan="5" style="text-align:center;padding:20px;">Belum ada respons.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <div class="pagination" style="display:flex; justify-content:center; gap:8px; margin-top:20px;">
                <?php for($i=1;$i<=$totalPages;$i++): ?>
                    <a href="admin.php?id=<?php echo $survey_id; ?>&page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>" class="btn <?php echo ($i==$page)?'btn-primary':''; ?>"><?php echo $i; ?></a>
                <?php endfor; ?>
            </div>
        </div>

        <!-- ======================== TAB: ANALYTICS (Chart.js) ======================== -->
        <div id="tab-analytics" class="tab-content">
            <div class="page-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <button class="btn-mobile-menu" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h1>Analytics Survei</h1>
                        <p class="page-sub">Visualisasi data dari pilihan ganda, dropdown, dan checkbox (menyesuaikan filter tanggal di tab hasil)</p>
                    </div>
                </div>
            </div>
            <div id="charts-container" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; padding: 20px 0;">
                <!-- Charts will be rendered here by JS -->
            </div>
        </div>

        <!-- ======================== TAB: EDITOR SURVEI (Interaktif JS) ======================== -->
        <div id="tab-editor" class="tab-content">
            <div class="page-header">
                <div style="display:flex; align-items:center; gap:12px;">
                    <button class="btn-mobile-menu" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                    <div>
                        <h1>Edit Pertanyaan</h1>
                        <p class="page-sub">Kelola pertanyaan secara interaktif (Google Forms Style)</p>
                    </div>
                </div>
                <div class="header-actions">
                    <button id="btn-add-step" class="btn btn-outline">
                        <i class="fa-solid fa-plus"></i> Tambah Bagian
                    </button>
                    <button id="btn-save-config" class="btn btn-primary" data-survey-id="<?php echo $survey_id; ?>">
                        <i class="fa-solid fa-floppy-disk"></i> Simpan Perubahan
                    </button>
                    <a href="index.php?id=<?php echo $survey_id; ?>" target="_blank" class="btn" style="background:#1e3a8a; color:#fff; border:none; padding:10px 20px; border-radius:10px; text-decoration:none; font-weight:600; display:flex; align-items:center; gap:7px;">
                        <i class="fa-solid fa-eye"></i> Lihat Form
                    </a>
                </div>
            </div>

            <div class="survey-title-bar">
                <div class="survey-title-inner">
                    <span class="survey-title-label"><i class="fa-solid fa-pen-nib"></i> Judul Survei</span>
                    <input type="text" id="survey-main-title" value="<?php echo htmlspecialchars($activeSurvey['title']); ?>" placeholder="Nama survei...">
                </div>
            </div>

            <div id="steps-editor-container">
                <!-- Rendered by JS -->
            </div>
        </div>

        <?php endif; ?>
        
        <!-- Copyright Footer Admin Portofolio -->
        <div style="text-align: center; margin-top: 40px; margin-bottom: 20px; padding-top: 20px; border-top: 1px solid #e9ecef; color: #888; font-size: 0.9rem;">
            &copy; <?php echo date('Y'); ?> Dashboard Admin Survei.<br>
            Developed with <i class="fa-solid fa-heart" style="color: #fa5252;"></i> by <strong> IT BLU Kantor UPBU Kelas I A.P.T Pranoto</strong>
        </div>
    </div>

    <!-- Modal Detail (Optional) -->
    <div id="detail-modal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fa-solid fa-user-circle"></i> Detail Responden</h2>
                <button class="close-modal"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div id="detail-body"></div>
        </div>
    </div>

    <!-- Template untuk satu pertanyaan (disembunyikan) -->
    <template id="question-tpl">
        <div class="question-card" data-qindex="">
            <div class="question-card-header">
                <div class="q-drag-handle"><i class="fa-solid fa-grip-vertical"></i></div>
                <select class="q-type-select">
                    <option value="text">Teks Singkat</option>
                    <option value="textarea">Paragraf / Saran</option>
                    <option value="radio">Pilihan Ganda (Radio)</option>
                    <option value="checkbox">Kotak Centang (Checkbox)</option>
                    <option value="select">Dropdown</option>
                    <option value="day-selector">Pilih Hari</option>
                    <option value="image-upload">Unggah Gambar (Jawaban)</option>
                </select>
                <button class="btn-icon btn-delete-question" title="Hapus Pertanyaan">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </div>
            <div class="question-card-body">
                <div class="q-row">
                    <div class="q-field">
                        <label>Label Pertanyaan</label>
                        <input type="text" class="q-label" placeholder="Masukkan teks pertanyaan...">
                    </div>
                    <div class="q-field q-field-sm">
                        <label>Nama Variabel (name)</label>
                        <input type="text" class="q-name" placeholder="contoh: nama, usia">
                    </div>
                    <div class="q-field q-field-xs">
                        <label>Wajib?</label>
                        <label class="toggle-switch">
                            <input type="checkbox" class="q-required">
                            <span class="toggle-knob"></span>
                        </label>
                    </div>
                </div>
                <div class="q-illus-section">
                    <label>Gambar Ilustrasi <span class="q-illus-hint">(opsional, maks <?php echo htmlspecialchars(ini_get('upload_max_filesize')); ?>)</span></label>
                    <!-- Hidden input = pembawa nilai saat syncAllFromDOM membaca ulang DOM -->
                    <input type="hidden" class="q-image-url" value="">
                    <div class="q-illus-preview" style="display:none;">
                        <img class="q-illus-img" src="" alt="Ilustrasi pertanyaan">
                        <button type="button" class="btn-link q-illus-remove"><i class="fa-solid fa-trash"></i> Hapus Gambar</button>
                    </div>
                    <input type="file" class="q-illus-file" accept="image/jpeg,image/png,image/webp" style="display:none;">
                    <div class="q-illus-actions">
                        <button type="button" class="btn-link q-illus-pick"><i class="fa-solid fa-image"></i> Unggah Gambar</button>
                        <span class="q-illus-status"></span>
                    </div>
                </div>
                <div class="q-options-section" style="display:none;">
                    <label>Opsi Jawaban <button class="btn-add-option btn-link"><i class="fa-solid fa-plus"></i> Tambah Opsi</button></label>
                    <div class="q-options-list"></div>
                    <label class="has-other-toggle">
                        <input type="checkbox" class="q-has-other">
                        <span>Sertakan opsi "Lainnya" dengan isian teks</span>
                    </label>
                </div>
            </div>
        </div>
    </template>

    <script>
        // Load the config directly from the active survey
        const surveyConfig = <?php echo ($survey_id > 0 && !empty($activeSurvey['config_json'])) ? $activeSurvey['config_json'] : '[]'; ?>;
        const surveyId = <?php echo $survey_id; ?>;
        const csrfToken = "<?php echo csrf_token(); ?>";
    </script>
    <script>
        // Tab Navigation
        const savedTab = localStorage.getItem('adminActiveTab') || 'results';
        
        const activateTab = (tab) => {
            if (!tab) return;
            document.querySelectorAll('nav a[data-tab]').forEach(l => l.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
            const link = document.querySelector(`nav a[data-tab="${tab}"]`);
            if (link) link.classList.add('active');
            const content = document.getElementById(`tab-${tab}`);
            if (content) content.classList.add('active');
        };

        // Activate the saved tab immediately
        activateTab(savedTab);

        document.querySelectorAll('nav a[data-tab]').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const tab = link.getAttribute('data-tab');
                activateTab(tab);
                localStorage.setItem('adminActiveTab', tab);
            });
        });

        // Editor logic dipisah ke editor.js (lebih bersih + AI toolbar)
        // editor.js akan di-load di bawah setelah questions.js

    </script>

    <?php if ($survey_id > 0): ?>
    <script src="assets/js/editor.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <script>
        // Analytics Logic
        const allRawData = <?php echo isset($allRawDataForAnalytics) ? json_encode(array_map(fn($r) => json_decode($r, true), $allRawDataForAnalytics)) : '[]'; ?>;
        
        function renderCharts() {
            const container = document.getElementById('charts-container');
            if (!container || !surveyConfig || surveyConfig.length === 0) return;
            
            if (allRawData.length === 0) {
                container.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 40px; color:#888;">Belum ada data responden untuk divisualisasikan.</div>';
                return;
            }
            
            let chartsAdded = false;
            
            const getColors = (count) => {
                const colors = ['#1971c2', '#e03131', '#2f9e44', '#f59f00', '#6741d9', '#c2255c', '#0ca678', '#f06595', '#fd7e14', '#1c7ed6'];
                return Array.from({length: count}, (_, i) => colors[i % colors.length]);
            };

            const walk = (qs) => {
                qs.forEach(q => {
                    if (q.type === 'row') { walk(q.questions); return; }
                    
                    if (['radio', 'select', 'checkbox'].includes(q.type) && q.name) {
                        chartsAdded = true;
                        
                        const freqs = {};
                        allRawData.forEach(res => {
                            if (!res) return;
                            let val = res[q.name];
                            if (!val) return;
                            if (Array.isArray(val)) {
                                val.forEach(v => { freqs[v] = (freqs[v] || 0) + 1; });
                            } else {
                                freqs[val] = (freqs[val] || 0) + 1;
                            }
                        });
                        
                        const card = document.createElement('div');
                        card.className = 'card';
                        card.style.padding = '20px';
                        card.style.background = '#fff';
                        card.style.borderRadius = '12px';
                        card.style.boxShadow = '0 2px 8px rgba(0,0,0,0.05)';
                        
                        const title = document.createElement('h3');
                        title.textContent = q.label || q.name;
                        title.style.fontSize = '1rem';
                        title.style.marginBottom = '15px';
                        title.style.color = '#1a1a2e';
                        
                        const canvasWrap = document.createElement('div');
                        canvasWrap.style.position = 'relative';
                        canvasWrap.style.height = '250px';
                        
                        const canvas = document.createElement('canvas');
                        canvasWrap.appendChild(canvas);
                        card.appendChild(title);
                        card.appendChild(canvasWrap);
                        container.appendChild(card);
                        
                        const labels = Object.keys(freqs);
                        const data = Object.values(freqs);
                        
                        if(labels.length > 0) {
                            new Chart(canvas, {
                                type: labels.length > 4 ? 'bar' : 'pie',
                                data: {
                                    labels: labels,
                                    datasets: [{
                                        label: 'Jumlah Jawaban',
                                        data: data,
                                        backgroundColor: getColors(labels.length),
                                        borderWidth: 1
                                    }]
                                },
                                options: {
                                    responsive: true,
                                    maintainAspectRatio: false,
                                    plugins: {
                                        legend: { display: labels.length <= 4, position: 'right' }
                                    }
                                }
                            });
                        } else {
                            canvasWrap.innerHTML = '<p style="color:#888; font-size:0.9rem; text-align:center; padding-top:100px;">Tidak ada data untuk pertanyaan ini</p>';
                        }
                    }
                });
            };
            
            surveyConfig.forEach(step => {
                if (step.questions) walk(step.questions);
            });
            
            if (!chartsAdded) {
                container.innerHTML = '<div style="grid-column: 1/-1; text-align:center; padding: 40px; color:#888;">Tidak ada pertanyaan pilihan ganda/checkbox untuk divisualisasikan.</div>';
            }
        }
        
        document.addEventListener('DOMContentLoaded', () => {
            renderCharts();
        });
    </script>
    <?php endif; ?>

    <script>

        // Path gambar jawaban — hanya pola yang diproduksi upload_image.php
        const IMG_RE = /^uploads\/responses\/\d+\/[a-f0-9]{32}\.(jpg|png|webp)$/;

        // Nilai di bawah berasal dari responden dan masuk ke innerHTML,
        // jadi wajib di-escape sebelum dirangkai.
        const esc = (s) => String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));

        // Render satu nilai jawaban: gambar jadi thumbnail, sisanya teks biasa
        const renderVal = (v) => IMG_RE.test(v)
            ? `<a href="${esc(v)}" target="_blank" rel="noopener"><img src="${esc(v)}" class="detail-thumb" alt="Foto jawaban"></a>`
            : `<span class="detail-val">${esc(v)}</span>`;

        // ================================================================
        // DETAIL MODAL - Tampilkan seluruh jawaban responden
        // ================================================================
        window.showDetail = (rowData) => {
            const modal = document.getElementById('detail-modal');
            const body = document.getElementById('detail-body');

            // Parse raw_data jika ada, fallback ke rowData itu sendiri
            let allData = rowData;
            if (rowData.raw_data) {
                try {
                    allData = { ...rowData, ...JSON.parse(rowData.raw_data) };
                } catch(e) { allData = rowData; }
            }

            const ts = rowData.submitted_at
                ? new Date(rowData.submitted_at).toLocaleString('id-ID', { dateStyle: 'long', timeStyle: 'short' })
                : '-';

            let html = `<div class="timestamp-badge"><i class="fa-regular fa-clock"></i>&nbsp; ${ts}</div>`;

            // Gunakan surveyConfig jika tersedia untuk tampilan terstruktur per bagian
            const skip = ['id', 'submitted_at', 'raw_data', 'created_at'];
            if (typeof surveyConfig !== 'undefined' && surveyConfig.length) {
                surveyConfig.forEach(step => {
                    html += `<div class="detail-section">
                        <div class="detail-section-title"><i class="fa-solid fa-list"></i> ${step.title}</div>`;
                    const walk = (qs) => qs.forEach(q => {
                        if (q.type === 'row') { walk(q.questions); return; }
                        if (!q.name) return;
                        const val = allData[q.name];
                        if (val === undefined || val === null || val === '') return;
                        const display = Array.isArray(val)
                            ? `<div class="detail-tags">${val.map(v => `<span class="detail-tag">${esc(v)}</span>`).join('')}</div>`
                            : renderVal(val);
                        html += `<div class="detail-item"><span class="detail-key">${esc(q.label)}</span>${display}</div>`;
                    });
                    walk(step.questions);
                    html += `</div>`;
                });
            } else {
                // Fallback: tampilkan semua key-value dari data
                html += `<div class="detail-section">`;
                Object.entries(allData).forEach(([k, v]) => {
                    if (skip.includes(k) || v === null || v === '') return;
                    const display = Array.isArray(v)
                        ? `<div class="detail-tags">${v.map(i => `<span class="detail-tag">${esc(i)}</span>`).join('')}</div>`
                        : renderVal(v);
                    html += `<div class="detail-item"><span class="detail-key">${esc(k)}</span>${display}</div>`;
                });
                html += `</div>`;
            }

            body.innerHTML = html;
            modal.style.display = 'flex';
        };

        // Tutup modal detail
        document.querySelector('.close-modal').addEventListener('click', () => {
            document.getElementById('detail-modal').style.display = 'none';
        });
    </script>

    <?php if ($survey_id > 0 && isset($tableHeaders)): ?>
    <!-- Modal Spreadsheet -->
    <div id="spreadsheet-modal" class="modal" style="z-index: 10000;">
        <div class="modal-content" style="max-width: 95vw; width: 100%; max-height: 90vh; display: flex; flex-direction: column; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 15px 50px rgba(0,0,0,0.2);">
            <div class="modal-header" style="display:flex; justify-content:space-between; align-items:center; padding:16px 20px; border-bottom:1px solid #eee; background:#fafafa;">
                <h2 style="margin:0; font-size:1.2rem; color:#212529;"><i class="fa-solid fa-table"></i> Tabel Data Lengkap</h2>
                <button class="close-spreadsheet" style="background:none; border:none; font-size:1.5rem; cursor:pointer; color:#888;">&times;</button>
            </div>
            <div class="modal-body" style="overflow: auto; flex: 1; padding: 0;">
                <table style="width: 100%; border-collapse: collapse; min-width: max-content; white-space: nowrap;">
                    <thead style="position:sticky; top:0; z-index:10; background:#f1f3f5; box-shadow:0 2px 5px rgba(0,0,0,0.05);">
                        <tr>
                            <th style="padding:12px; border-bottom:1px solid #ddd; text-align:left; color:#495057;">#</th>
                            <th style="padding:12px; border-bottom:1px solid #ddd; text-align:left; color:#495057;">Waktu</th>
                            <?php foreach ($tableHeaders as $header): ?>
                                <th style="padding:12px; border-bottom:1px solid #ddd; text-align:left; color:#495057;"><?php echo htmlspecialchars(strlen($header) > 40 ? substr($header, 0, 40) . '...' : $header); ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $idx => $row): 
                            $data = json_decode($row['raw_data'], true) ?: [];
                        ?>
                            <tr style="border-bottom:1px solid #eee;">
                                <td style="padding:12px;"><?php echo $offset + $idx + 1; ?></td>
                                <td style="padding:12px; font-size:0.85rem; color:#666;"><?php echo date('d/m/Y H:i', strtotime($row['submitted_at'])); ?></td>
                                <?php foreach ($tableKeys as $key):
                                    $val = isset($data[$key]) ? $data[$key] : '-';
                                    if (is_array($val)) $val = implode(', ', $val);
                                    $isPhoto = isResponseImage($val);
                                    $valStr = $isPhoto ? '' : htmlspecialchars(mb_strlen($val) > 100 ? mb_substr($val, 0, 100) . '...' : $val);
                                ?>
                                    <td style="padding:12px; max-width:300px; overflow:hidden; text-overflow:ellipsis;"><?php
                                        if ($isPhoto) {
                                            echo '<a href="' . htmlspecialchars($val) . '" target="_blank" rel="noopener"><img src="' . htmlspecialchars($val) . '" alt="Foto" style="height:40px;border-radius:4px"></a>';
                                        } else {
                                            echo $valStr;
                                        }
                                    ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($results)): ?>
                            <tr><td colspan="<?php echo count($tableHeaders) + 2; ?>" style="text-align:center;padding:20px;">Belum ada respons.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        const _closeSpreadsheet = document.querySelector('.close-spreadsheet');
        if (_closeSpreadsheet) {
            _closeSpreadsheet.addEventListener('click', () => {
                document.getElementById('spreadsheet-modal').style.display = 'none';
            });
        }
    </script>
    <?php endif; ?>

    <!-- Modal Konfirmasi Hapus (Premium) -->
    <div id="delete-confirm-modal" class="modal">
        <div class="modal-content" style="max-width: 400px; text-align: center; padding: 30px;">
            <div style="width: 70px; height: 70px; background: #fff5f5; color: #fa5252; border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; font-size: 30px; border: 4px solid #fff0f0;">
                <i class="fa-solid fa-trash-can"></i>
            </div>
            <h2 id="delete-modal-title" style="color: #212529; margin-bottom: 10px;">Hapus?</h2>
            <p id="delete-modal-msg" style="color: #868e96; margin-bottom: 25px; line-height: 1.5;"></p>
            
            <div style="display: flex; gap: 12px; justify-content: center;">
                <button class="btn" onclick="closeDeleteModal()" style="background: #f1f3f5; color: #495057; flex: 1; padding: 12px; border-radius: 12px; font-weight: 600; cursor: pointer; border: none;">Batal</button>
                <a id="btn-confirm-delete" href="#" class="btn" style="background: #fa5252; color: #fff; flex: 1; padding: 12px; border-radius: 12px; font-weight: 600; text-decoration: none; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 12px rgba(250, 82, 82, 0.2);">Hapus</a>
            </div>
        </div>
    </div>

    <!-- Modal QR Code -->
    <div id="qr-modal" class="modal" style="z-index: 10001;">
        <div class="modal-content" style="max-width: 350px; text-align: center; padding: 30px;">
            <div class="modal-header" style="justify-content:center; border:none; margin-bottom:10px;">
                <h2 style="font-size:1.3rem; margin:0;"><i class="fa-solid fa-qrcode"></i> QR Code Survei</h2>
            </div>
            <p id="qr-title" style="color:#666; font-size:0.9rem; margin-bottom:20px;"></p>
            <div id="qr-image-container" style="background:#fff; padding:15px; border-radius:12px; border:2px solid #eee; display:inline-block; margin-bottom:20px;">
                <img id="qr-image" src="" alt="QR Code" style="width:200px; height:200px; display:none;">
            </div>
            <div style="display: flex; gap: 12px; justify-content: center;">
                <button class="btn" onclick="document.getElementById('qr-modal').style.display='none'" style="background: #f1f3f5; color: #495057; flex: 1; padding: 12px; border-radius: 12px; font-weight: 600; border:none; cursor:pointer;">Tutup</button>
            </div>
        </div>
    </div>

    <script>
        // Dropdown toggle logic
        function toggleDropdown(btn) {
            document.querySelectorAll('.dropdown-menu').forEach(menu => {
                if(menu !== btn.nextElementSibling) menu.style.display = 'none';
            });
            const menu = btn.nextElementSibling;
            menu.style.display = menu.style.display === 'block' ? 'none' : 'block';
        }
        
        // Global Toast Notification System
        const showToast = (msg, type = 'success') => {
            let toast = document.getElementById('admin-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'admin-toast';
                toast.style.cssText = `position:fixed;bottom:28px;right:28px;padding:14px 20px;border-radius:12px;font-weight:600;font-size:0.9rem;box-shadow:0 8px 24px rgba(0,0,0,0.15);z-index:9998;transition:all 0.4s;opacity:0;transform:translateY(10px);max-width:320px`;
                document.body.appendChild(toast);
            }
            toast.innerText = msg;
            toast.style.background = type === 'success' ? '#ebfbee' : '#fff5f5';
            toast.style.color = type === 'success' ? '#2b8a3e' : '#e03131';
            toast.style.border = `1.5px solid ${type === 'success' ? '#b2f2bb' : '#ffc9c9'}`;
            toast.style.opacity = '1';
            toast.style.transform = 'translateY(0)';
            setTimeout(() => { toast.style.opacity = '0'; toast.style.transform = 'translateY(10px)'; }, 3500);
        };

        // Copy Link
        function copyLink(path) {
            let baseUri = window.location.href.split('?')[0]; 
            baseUri = baseUri.substring(0, baseUri.lastIndexOf('/') + 1);
            const fullUrl = baseUri + path;
            if (navigator.clipboard) {
                navigator.clipboard.writeText(fullUrl).then(() => {
                    showToast('✅ Tautan survei berhasil disalin!', 'success');
                }).catch(err => {
                    showToast('❌ Gagal menyalin tautan: ' + err, 'error');
                });
            } else {
                // Fallback for non-https/older browsers
                const textArea = document.createElement("textarea");
                textArea.value = fullUrl;
                document.body.appendChild(textArea);
                textArea.select();
                try {
                    document.execCommand('copy');
                    showToast('✅ Tautan survei berhasil disalin!', 'success');
                } catch (err) {
                    showToast('❌ Gagal menyalin tautan', 'error');
                }
                document.body.removeChild(textArea);
            }
        }

        // Show QR
        function showQRModal(path, title) {
            // Perbaiki baseUri agar selalu sesuai
            let baseUri = window.location.href.split('?')[0]; 
            baseUri = baseUri.substring(0, baseUri.lastIndexOf('/') + 1);
            const fullUrl = baseUri + path;
            const qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data=" + encodeURIComponent(fullUrl);
            
            document.getElementById('qr-title').innerText = title;
            document.getElementById('qr-image').src = qrUrl;
            document.getElementById('qr-image').style.display = 'block';
            document.getElementById('qr-modal').style.display = 'flex';
        }

        function showDeleteSurveyModal(event, id, title) {
            if (event) event.preventDefault();
            const modal = document.getElementById('delete-confirm-modal');
            const titleEl = document.getElementById('delete-modal-title');
            const msg = document.getElementById('delete-modal-msg');
            const btn = document.getElementById('btn-confirm-delete');
            if (!modal || !titleEl || !msg || !btn) return;
            
            titleEl.innerText = "Hapus Survei?";
            msg.innerHTML = `Apakah Anda yakin ingin menghapus survei <strong>"${title}"</strong>?<br><span style="font-size: 0.85rem; color: #fa5252;">Seluruh data respons juga akan ikut terhapus selamanya.</span>`;
            btn.href = `admin.php?delete_survey=${id}&csrf=<?php echo csrf_token(); ?>`;
            
            modal.style.display = 'flex';
        }

        function showDeleteResponseModal(event, id, surveyId, preview) {
            if (event) event.preventDefault();
            const modal = document.getElementById('delete-confirm-modal');
            const titleEl = document.getElementById('delete-modal-title');
            const msg = document.getElementById('delete-modal-msg');
            const btn = document.getElementById('btn-confirm-delete');
            
            if (!modal || !titleEl || !msg || !btn) return;
            
            titleEl.innerText = "Hapus Respons?";
            msg.innerHTML = `Hapus data dari <strong id="delete-preview-text"></strong>?<br><span style="font-size: 0.85rem; color: #fa5252;">Tindakan ini tidak dapat dibatalkan.</span>`;
            document.getElementById('delete-preview-text').textContent = preview;
            btn.href = `hapus_data.php?id=${id}&survey_id=${surveyId}&csrf=<?php echo csrf_token(); ?>`;
            
            modal.style.display = 'flex';
        }

        function closeDeleteModal() {
            document.getElementById('delete-confirm-modal').style.display = 'none';
        }

        // Close when clicking outside
        window.addEventListener('click', (e) => {
            if (e.target && e.target.closest) {
                if(!e.target.closest('.dropdown-container')) {
                    document.querySelectorAll('.dropdown-menu').forEach(menu => menu.style.display = 'none');
                }
            }
            if (e.target === document.getElementById('qr-modal')) {
                document.getElementById('qr-modal').style.display = 'none';
            }
            if (e.target === document.getElementById('delete-confirm-modal')) {
                closeDeleteModal();
            }
        });
    </script>
</body>
</html>