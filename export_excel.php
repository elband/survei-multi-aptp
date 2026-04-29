<?php
require_once 'db.php';
require_once 'auth.php';
checkLogin();

$survey_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($survey_id === 0) {
    die("ID Survei tidak valid.");
}

// Ambil info survei
$stmt = $pdo->prepare("SELECT * FROM surveys WHERE id = ?");
$stmt->execute([$survey_id]);
$survey = $stmt->fetch();

if (!$survey) {
    die("Survei tidak ditemukan.");
}

// Ambil semua response
$stmtData = $pdo->prepare("SELECT * FROM survey_responses WHERE survey_id = ? ORDER BY submitted_at ASC");
$stmtData->execute([$survey_id]);
$responses = $stmtData->fetchAll();

// Kumpulkan semua key/kolom yang ada dari seluruh data JSON
$columns = [];
$parsedData = [];

foreach ($responses as $row) {
    $data = json_decode($row['raw_data'], true) ?: [];
    foreach (array_keys($data) as $key) {
        if (!in_array($key, $columns)) {
            $columns[] = $key;
        }
    }
    $row['parsed_data'] = $data;
    $parsedData[] = $row;
}

$filename = "Hasil_Survei_" . preg_replace('/[^a-zA-Z0-9]+/', '_', $survey['title']) . "_" . date('Ymd_Hi') . ".xls";

// Set header untuk memberitahu browser bahwa ini adalah file Excel (format tabel HTML)
header("Content-Type: application/vnd.ms-excel; charset=utf-8");
header("Content-Disposition: attachment; filename=\"$filename\"");
header("Pragma: no-cache");
header("Expires: 0");

// Output tabel HTML (Excel bisa membaca ini dengan sangat baik)
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
</head>
<body>
    <table border="1">
        <thead>
            <tr>
                <th style="background-color: #1971c2; color: #ffffff;">ID Respons</th>
                <th style="background-color: #1971c2; color: #ffffff;">Waktu Pengisian</th>
                <?php foreach ($columns as $col): ?>
                    <th style="background-color: #1971c2; color: #ffffff;"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $col))); ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($parsedData as $index => $row): ?>
                <tr>
                    <td><?php echo $index + 1; ?></td>
                    <td><?php echo date('d/m/Y H:i:s', strtotime($row['submitted_at'])); ?></td>
                    <?php foreach ($columns as $col): ?>
                        <td>
                            <?php 
                                $val = isset($row['parsed_data'][$col]) ? $row['parsed_data'][$col] : ''; 
                                if (is_array($val)) {
                                    echo htmlspecialchars(implode(', ', $val));
                                } else {
                                    echo htmlspecialchars((string)$val);
                                }
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</body>
</html>
