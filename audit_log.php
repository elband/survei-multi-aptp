<?php
require_once 'db.php';
require_once 'auth.php';
checkLogin();

// Pagination
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$limit  = 30;
$offset = ($page - 1) * $limit;

// Filter by action type
$filter = isset($_GET['filter']) ? trim($_GET['filter']) : '';

// Count total logs
if ($filter) {
    $countStmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs WHERE action = ?");
    $countStmt->execute([$filter]);
} else {
    $countStmt = $pdo->query("SELECT COUNT(*) FROM audit_logs");
}
$totalLogs  = $countStmt->fetchColumn();
$totalPages = max(1, ceil($totalLogs / $limit));

// Fetch logs
if ($filter) {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs WHERE action = ? ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute([$filter]);
} else {
    $stmt = $pdo->prepare("SELECT * FROM audit_logs ORDER BY created_at DESC LIMIT $limit OFFSET $offset");
    $stmt->execute();
}
$logs = $stmt->fetchAll();

// Get unique action types for filter dropdown
$actionTypes = $pdo->query("SELECT DISTINCT action FROM audit_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);

// Helper: badge color per action type
function badgeClass($action) {
    return match($action) {
        'LOGIN'           => 'badge-success',
        'DELETE_RESPONSE' => 'badge-danger',
        'DELETE_SURVEY'   => 'badge-danger',
        'CREATE_SURVEY'   => 'badge-info',
        'SAVE_CONFIG'     => 'badge-warning',
        default           => 'badge-muted',
    };
}
function badgeIcon($action) {
    return match($action) {
        'LOGIN'           => 'fa-right-to-bracket',
        'DELETE_RESPONSE' => 'fa-trash',
        'DELETE_SURVEY'   => 'fa-trash',
        'CREATE_SURVEY'   => 'fa-plus',
        'SAVE_CONFIG'     => 'fa-floppy-disk',
        default           => 'fa-bolt',
    };
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audit Log - Admin Panel</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="assets/css/admin.css">
    <style>
        /* ===== AUDIT LOG SPECIFIC STYLES ===== */
        .audit-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 24px;
        }
        .filter-bar {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-bar select,
        .filter-bar a.btn {
            font-size: 0.88rem;
            padding: 8px 14px;
            border-radius: 10px;
            border: 1px solid #e0e0e0;
            background: #fff;
            cursor: pointer;
            text-decoration: none;
            color: #444;
            transition: all 0.2s;
        }
        .filter-bar select:focus { outline: none; border-color: #1971c2; }
        .filter-bar a.btn:hover  { background: #f1f3f5; }

        /* Badge */
        .badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 30px;
            font-size: 0.78rem;
            font-weight: 600;
            white-space: nowrap;
        }
        .badge-success { background: #d3f9d8; color: #2f9e44; }
        .badge-danger  { background: #ffe3e3; color: #e03131; }
        .badge-info    { background: #d0ebff; color: #1971c2; }
        .badge-warning { background: #fff3bf; color: #e67700; }
        .badge-muted   { background: #f1f3f5; color: #666;    }

        /* Table */
        .audit-table-wrap { overflow-x: auto; border-radius: 14px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
        .audit-table {
            width: 100%;
            border-collapse: collapse;
            background: #fff;
            font-size: 0.9rem;
        }
        .audit-table thead th {
            background: #1a1a2e;
            color: #fff;
            padding: 13px 16px;
            text-align: left;
            font-weight: 600;
            font-size: 0.82rem;
            letter-spacing: 0.5px;
            white-space: nowrap;
        }
        .audit-table thead th:first-child { border-radius: 14px 0 0 0; }
        .audit-table thead th:last-child  { border-radius: 0 14px 0 0; }
        .audit-table tbody tr {
            border-bottom: 1px solid #f0f0f0;
            transition: background 0.15s;
        }
        .audit-table tbody tr:hover { background: #f8f9fa; }
        .audit-table tbody td { padding: 13px 16px; vertical-align: middle; }
        .audit-table tbody tr:last-child td { border-bottom: none; }

        .ip-tag {
            font-family: 'Courier New', monospace;
            background: #f1f3f5;
            padding: 2px 8px;
            border-radius: 6px;
            font-size: 0.82rem;
            color: #555;
        }
        .admin-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: #1971c2;
            font-weight: 600;
        }
        .target-text {
            color: #888;
            font-size: 0.83rem;
            font-family: 'Courier New', monospace;
        }
        .time-cell { white-space: nowrap; color: #555; font-size: 0.88rem; }
        .time-cell span { display: block; font-size: 0.78rem; color: #aaa; }

        /* Empty state */
        .empty-log {
            text-align: center;
            padding: 60px 20px;
            color: #aaa;
        }
        .empty-log i { font-size: 3rem; margin-bottom: 16px; display: block; }

        /* Stats */
        .log-stats {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 20px;
        }
        .log-stat-card {
            background: #fff;
            border-radius: 12px;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            flex: 1;
            min-width: 140px;
        }
        .log-stat-icon { font-size: 1.4rem; width: 40px; height: 40px; border-radius: 10px; display: flex; align-items: center; justify-content: center; }
        .log-stat-val  { font-size: 1.4rem; font-weight: 700; color: #1a1a2e; }
        .log-stat-lbl  { font-size: 0.78rem; color: #888; }

        /* Pagination */
        .pagination { display: flex; justify-content: center; gap: 6px; margin-top: 24px; flex-wrap: wrap; }
        .pagination a, .pagination span {
            padding: 8px 14px;
            border-radius: 10px;
            font-size: 0.88rem;
            text-decoration: none;
            transition: all 0.2s;
        }
        .pagination a { background: #fff; color: #444; border: 1px solid #e0e0e0; }
        .pagination a:hover { background: #f1f3f5; }
        .pagination .active { background: #1971c2; color: #fff; border-color: #1971c2; }

        /* === MOBILE RESPONSIVE === */
        .mobile-overlay {
            display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.5);
            z-index: 990; backdrop-filter: blur(2px); opacity: 0; transition: opacity 0.3s;
        }
        .mobile-overlay.show { display: block; opacity: 1; }
        .btn-mobile-menu { display: none; margin-right: 15px; border: none; background: transparent; font-size: 1.4rem; color: #1971c2; cursor: pointer; }

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
            
            /* Audit Log layout fixes */
            .audit-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            .filter-bar { width: 100%; flex-wrap: wrap; }
            .filter-bar form, .filter-bar select { width: 100%; }
            .log-stats { flex-direction: column; }
            .log-stat-card { width: 100%; }
            
            /* Table responsiveness */
            .audit-table thead { display: none; }
            .audit-table, .audit-table tbody, .audit-table tr, .audit-table td { display: block; width: 100%; box-sizing: border-box; }
            .audit-table tr {
                margin-bottom: 14px;
                border: 1px solid #e9ecef;
                border-radius: 10px;
                padding: 10px 12px;
                background: #fff;
                box-shadow: 0 2px 8px rgba(0,0,0,0.05);
            }
            .audit-table td {
                text-align: right;
                padding: 6px 0;
                border: none;
                font-size: 0.88rem;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .audit-table td:nth-child(1)::before { content: "No"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:nth-child(2)::before { content: "Waktu"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:nth-child(3)::before { content: "Admin"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:nth-child(4)::before { content: "Aksi"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:nth-child(5)::before { content: "Target"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:nth-child(6)::before { content: "IP Address"; font-weight: bold; color: #888; font-size: 0.75rem; text-transform: uppercase; }
            .audit-table td:first-child { border-bottom: 1px solid #f1f3f5; padding-bottom: 8px; }
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

    <!-- Sidebar -->
    <div class="sidebar">
        <div class="logo-area">
            <img src="https://aptpairport.id/assets_landing/img/logo/logo-apt.svg" alt="Logo">
            <h2>Admin Panel</h2>
            <p class="sidebar-sub">Survei APT Pranoto</p>
        </div>
        <nav>
            <a href="admin.php" style="background:#f1f3f5; color:#333;">
                <i class="fa-solid fa-arrow-left"></i>
                <span>Kembali ke Survei</span>
            </a>
            <a href="audit_log.php" class="active">
                <i class="fa-solid fa-shield-halved"></i>
                <span>Audit Log</span>
            </a>
        </nav>
        <div class="logout-area">
            <a href="logout.php">
                <i class="fa-solid fa-right-from-bracket"></i> <span>Logout</span>
            </a>
        </div>
    </div>

    <!-- Main Content -->
    <div class="main-content">

        <!-- Page Header -->
        <div class="audit-header">
            <div style="display:flex; align-items:center; gap:12px;">
                <button class="btn-mobile-menu" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
                <div>
                    <h1 style="font-size:1.5rem; font-weight:700; color:#1a1a2e; margin:0;">
                        <i class="fa-solid fa-shield-halved" style="color:#1971c2; margin-right:8px;"></i>
                        Audit Log
                    </h1>
                    <p class="page-sub">Rekaman seluruh aktivitas admin secara real-time</p>
                </div>
            </div>
            <div class="filter-bar">
                <form method="GET" action="audit_log.php" style="display:flex; gap:8px; align-items:center;">
                    <select name="filter" onchange="this.form.submit()">
                        <option value="">Semua Aktivitas</option>
                        <?php foreach ($actionTypes as $type): ?>
                            <option value="<?php echo $type; ?>" <?php echo ($filter === $type) ? 'selected' : ''; ?>>
                                <?php echo $type; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <?php if ($filter): ?>
                    <a href="audit_log.php" class="btn"><i class="fa-solid fa-xmark"></i> Reset</a>
                <?php endif; ?>
            </div>
        </div>

        <!-- Quick Stats -->
        <?php
        $statLogin  = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='LOGIN'")->fetchColumn();
        $statDelete = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action IN ('DELETE_RESPONSE','DELETE_SURVEY')")->fetchColumn();
        $statCreate = $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE action='CREATE_SURVEY'")->fetchColumn();
        ?>
        <div class="log-stats">
            <div class="log-stat-card">
                <div class="log-stat-icon" style="background:#d3f9d8; color:#2f9e44;">
                    <i class="fa-solid fa-right-to-bracket"></i>
                </div>
                <div>
                    <div class="log-stat-val"><?php echo $statLogin; ?></div>
                    <div class="log-stat-lbl">Total Login</div>
                </div>
            </div>
            <div class="log-stat-card">
                <div class="log-stat-icon" style="background:#ffe3e3; color:#e03131;">
                    <i class="fa-solid fa-trash"></i>
                </div>
                <div>
                    <div class="log-stat-val"><?php echo $statDelete; ?></div>
                    <div class="log-stat-lbl">Total Hapus</div>
                </div>
            </div>
            <div class="log-stat-card">
                <div class="log-stat-icon" style="background:#d0ebff; color:#1971c2;">
                    <i class="fa-solid fa-plus"></i>
                </div>
                <div>
                    <div class="log-stat-val"><?php echo $statCreate; ?></div>
                    <div class="log-stat-lbl">Survei Dibuat</div>
                </div>
            </div>
            <div class="log-stat-card">
                <div class="log-stat-icon" style="background:#f3d9fa; color:#9c36b5;">
                    <i class="fa-solid fa-list-check"></i>
                </div>
                <div>
                    <div class="log-stat-val"><?php echo $totalLogs; ?></div>
                    <div class="log-stat-lbl">Total Log <?php echo $filter ? "(Filter)" : ""; ?></div>
                </div>
            </div>
        </div>

        <!-- Log Table -->
        <div class="audit-table-wrap">
            <table class="audit-table">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Waktu</th>
                        <th>Admin</th>
                        <th>Aksi</th>
                        <th>Target / Info</th>
                        <th>IP Address</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($logs)): ?>
                        <tr>
                            <td colspan="6">
                                <div class="empty-log">
                                    <i class="fa-solid fa-shield-halved"></i>
                                    <p>Belum ada aktivitas yang tercatat.</p>
                                </div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($logs as $i => $log): ?>
                            <tr>
                                <td style="color:#bbb; font-size:0.82rem;"><?php echo $offset + $i + 1; ?></td>
                                <td class="time-cell">
                                    <?php
                                        // DB sudah menyimpan waktu lokal, tampilkan langsung
                                        $dt = new DateTime($log['created_at']);
                                        echo $dt->format('d M Y');
                                    ?>
                                    <span><?php echo $dt->format('H:i:s') . ' WITA'; ?></span>
                                </td>
                                <td>
                                    <span class="admin-tag">
                                        <i class="fa-solid fa-user-shield"></i>
                                        <?php echo htmlspecialchars($log['admin_user'] ?? '-'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="badge <?php echo badgeClass($log['action']); ?>">
                                        <i class="fa-solid <?php echo badgeIcon($log['action']); ?>"></i>
                                        <?php echo htmlspecialchars($log['action']); ?>
                                    </span>
                                </td>
                                <td class="target-text"><?php echo htmlspecialchars($log['target'] ?? '-'); ?></td>
                                <td><span class="ip-tag"><?php echo htmlspecialchars($log['ip_address'] ?? '-'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="pagination">
                <?php if ($page > 1): ?>
                    <a href="?page=<?php echo $page-1; ?>&filter=<?php echo urlencode($filter); ?>">
                        <i class="fa-solid fa-chevron-left"></i>
                    </a>
                <?php endif; ?>

                <?php for ($p = 1; $p <= $totalPages; $p++): ?>
                    <a href="?page=<?php echo $p; ?>&filter=<?php echo urlencode($filter); ?>"
                       class="<?php echo ($p === $page) ? 'active' : ''; ?>">
                        <?php echo $p; ?>
                    </a>
                <?php endfor; ?>

                <?php if ($page < $totalPages): ?>
                    <a href="?page=<?php echo $page+1; ?>&filter=<?php echo urlencode($filter); ?>">
                        <i class="fa-solid fa-chevron-right"></i>
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div><!-- /main-content -->
</body>
</html>
