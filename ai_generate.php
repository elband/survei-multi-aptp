<?php
ob_start();
error_reporting(0);
ini_set('display_errors', 0);
session_start();
ob_clean();
header('Content-Type: application/json');

// Cek sesi admin
if (empty($_SESSION['admin_id'])) {
    echo json_encode(['success' => false, 'message' => 'Silakan login terlebih dahulu']);
    exit;
}

$topic = trim($_POST['topic'] ?? '');
if (!$topic) {
    echo json_encode(['success' => false, 'message' => 'Topik kosong']);
    exit;
}

// Baca API Key dari .env
$apiKey = '';
$envFile = __DIR__ . '/.env';
if (file_exists($envFile)) {
    foreach (file($envFile) as $line) {
        $line = trim($line);
        if (str_starts_with($line, 'GEMINI_API_KEY=')) {
            $apiKey = trim(substr($line, 15), '"\'');
            break;
        }
    }
}

if (!$apiKey) {
    echo json_encode(['success' => false, 'message' => 'GEMINI_API_KEY belum diset di .env']);
    exit;
}

$prompt = "Buatkan 5 pertanyaan survei tentang \"{$topic}\" untuk bandara APT Pranoto Samarinda.\n"
    . "Jawab HANYA dengan JSON array, tanpa teks lain, tanpa markdown, tanpa ```json.\n"
    . "Format tiap item: {\"label\":\"Teks pertanyaan?\",\"name\":\"nama_variable\",\"type\":\"radio\",\"options\":[{\"value\":\"Sangat Baik\",\"label\":\"Sangat Baik\"}]}\n"
    . "Type tersedia: text, radio, textarea. Untuk text/textarea JANGAN sertakan options.\n"
    . "Gunakan bahasa Indonesia. snake_case untuk name.";

$url = "https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key={$apiKey}";
$body = json_encode([
    'contents' => [['parts' => [['text' => $prompt]]]],
    'generationConfig' => ['temperature' => 0.7, 'maxOutputTokens' => 2048]
]);

// Gunakan file_get_contents dengan stream context (alternatif cURL)
$context = stream_context_create([
    'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nContent-Length: " . strlen($body) . "\r\n",
        'content' => $body,
        'timeout' => 60,
        'ignore_errors' => true,
    ],
    'ssl' => ['verify_peer' => false, 'verify_peer_name' => false]
]);

$response = @file_get_contents($url, false, $context);

if ($response === false) {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => 60,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        $response = curl_exec($ch);
        $curlErr = curl_error($ch);
        curl_close($ch);
        if (!$response) {
            echo json_encode(['success' => false, 'message' => 'Koneksi gagal: ' . $curlErr]);
            exit;
        }
    } else {
        echo json_encode(['success' => false, 'message' => 'Koneksi API gagal']);
        exit;
    }
}

$data = json_decode($response, true);

if (isset($data['error'])) {
    echo json_encode(['success' => false, 'message' => $data['error']['message'] ?? 'Gemini error']);
    exit;
}

$rawText = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
$rawText = preg_replace('/```json\s*/i', '', $rawText);
$rawText = preg_replace('/```\s*/i', '', $rawText);
$rawText = trim($rawText);

preg_match('/\[[\s\S]*\]/', $rawText, $matches);
if (!$matches) {
    echo json_encode(['success' => false, 'message' => 'Format AI tidak valid', 'raw' => $rawText]);
    exit;
}

$questions = json_decode($matches[0], true);
if (!$questions) {
    echo json_encode(['success' => false, 'message' => 'Gagal parse JSON: ' . json_last_error_msg(), 'raw' => $matches[0]]);
    exit;
}

echo json_encode(['success' => true, 'questions' => $questions]);

