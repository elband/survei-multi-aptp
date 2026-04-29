<?php
    // Atur zona waktu ke WITA (Waktu Indonesia Tengah)
date_default_timezone_set('Asia/Makassar');
require_once 'db.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $inputJSON = file_get_contents('php://input');
    $inputData = json_decode($inputJSON, true);
    if (is_array($inputData)) {
        $data = $inputData;
    } else {
        $data = $_POST;
    }
    
    $survey_id = isset($data['survey_id']) ? (int)$data['survey_id'] : 0;
    unset($data['survey_id']);

    // Handle "Lainnya" inputs (if sent via normal form)
    foreach ($data as $key => $value) {
        if (strpos($key, 'other_') === 0) continue;
        
        $otherKey = "other_" . $key;
        if (isset($data[$otherKey]) && !empty($data[$otherKey])) {
            if (is_array($value)) {
                $data[$key] = array_map(function($v) use ($data, $otherKey) {
                    return ($v === 'lainnya') ? $data[$otherKey] : $v;
                }, $value);
            } else if ($value === 'lainnya') {
                $data[$key] = $data[$otherKey];
            }
        }
    }

    try {
        $sql = "INSERT INTO survey_responses (survey_id, raw_data) VALUES (?, ?)";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $survey_id,
            json_encode($data)
        ]);

        header('Content-Type: application/json');
        echo json_encode(['success' => true]);
        exit;
    } catch (PDOException $e) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => "Gagal menyimpan data: " . $e->getMessage()]);
        exit;
    }
} else {
    header("Location: index.php");
    exit;
}
?>
