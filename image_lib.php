<?php
/**
 * Helper bersama untuk fitur upload gambar.
 * Dipakai oleh upload_image.php, proses_survei.php, admin.php,
 * hapus_data.php, dan export_excel.php.
 */

/**
 * Pola path yang SAH — hanya cocok dengan yang diproduksi upload_image.php.
 * Ini adalah batas keamanan utama: karena nama file selalu 32 hex dan
 * direktorinya tetap, path traversal menjadi mustahil secara struktural
 * di semua kode yang membaca atau menghapus file.
 */
const IMG_PATH_RE       = '#^uploads/(responses|illustrations)/\d+/[a-f0-9]{32}\.(jpg|png|webp)$#';
const IMG_RESPONSE_RE   = '#^uploads/responses/\d+/[a-f0-9]{32}\.(jpg|png|webp)$#';
const IMG_ILLUS_RE      = '#^uploads/illustrations/\d+/[a-f0-9]{32}\.(jpg|png|webp)$#';

function isUploadPath($v)       { return is_string($v) && preg_match(IMG_PATH_RE, $v) === 1; }
function isResponseImage($v)    { return is_string($v) && preg_match(IMG_RESPONSE_RE, $v) === 1; }
function isIllustrationImage($v){ return is_string($v) && preg_match(IMG_ILLUS_RE, $v) === 1; }

/**
 * Telusuri semua pertanyaan di config survei, termasuk grup 'row' bersarang.
 * @param array|null $config Hasil json_decode(config_json, true)
 * @param callable   $fn     Dipanggil untuk setiap pertanyaan (bukan grup row)
 */
function walkSurveyQuestions($config, callable $fn) {
    if (!is_array($config)) return;

    $walk = function ($questions) use (&$walk, $fn) {
        if (!is_array($questions)) return;
        foreach ($questions as $q) {
            if (!is_array($q)) continue;
            if (($q['type'] ?? '') === 'row') {
                $walk($q['questions'] ?? []);
                continue;
            }
            $fn($q);
        }
    };

    foreach ($config as $step) {
        if (is_array($step)) $walk($step['questions'] ?? []);
    }
}

/**
 * Daftar nama pertanyaan bertipe image-upload.
 */
function collectImageUploadFields($config) {
    $fields = [];
    walkSurveyQuestions($config, function ($q) use (&$fields) {
        if (($q['type'] ?? '') === 'image-upload' && !empty($q['name'])) {
            $fields[] = $q['name'];
        }
    });
    return $fields;
}

/**
 * Daftar path gambar ilustrasi yang dipakai sebuah config survei.
 */
function collectIllustrationUrls($config) {
    $urls = [];
    walkSurveyQuestions($config, function ($q) use (&$urls) {
        if (!empty($q['imageUrl']) && isIllustrationImage($q['imageUrl'])) {
            $urls[] = $q['imageUrl'];
        }
    });
    return array_values(array_unique($urls));
}

/**
 * Salin semua gambar ilustrasi sebuah config ke direktori survei baru dan
 * tulis ulang path-nya. Dipakai saat duplikasi survei, agar setiap file
 * dimiliki tepat satu survei — invariant yang diandalkan oleh penghapusan
 * per-direktori dan diff-delete di save_config.
 *
 * @return array Config baru dengan path yang sudah diarahkan ulang.
 */
function duplicateIllustrations($config, $newSurveyId) {
    if (!is_array($config)) return $config;

    $newSurveyId = (int)$newSurveyId;
    $targetRel   = 'uploads/illustrations/' . $newSurveyId;
    $targetAbs   = __DIR__ . '/' . $targetRel;
    $map         = [];

    foreach (collectIllustrationUrls($config) as $old) {
        $srcAbs = __DIR__ . '/' . $old;
        if (!is_file($srcAbs)) continue;

        if (!is_dir($targetAbs) && !@mkdir($targetAbs, 0755, true) && !is_dir($targetAbs)) {
            break; // gagal membuat folder: biarkan path lama apa adanya
        }

        $ext     = pathinfo($old, PATHINFO_EXTENSION);
        $newName = bin2hex(random_bytes(16)) . '.' . $ext;
        if (@copy($srcAbs, $targetAbs . '/' . $newName)) {
            $map[$old] = $targetRel . '/' . $newName;
        }
    }

    if (!$map) return $config;

    // Terapkan pemetaan ke seluruh pertanyaan, termasuk yang bersarang di 'row'
    $rewrite = function (&$questions) use (&$rewrite, $map) {
        if (!is_array($questions)) return;
        foreach ($questions as &$q) {
            if (!is_array($q)) continue;
            if (($q['type'] ?? '') === 'row') {
                $rewrite($q['questions']);
                continue;
            }
            if (!empty($q['imageUrl']) && isset($map[$q['imageUrl']])) {
                $q['imageUrl'] = $map[$q['imageUrl']];
            }
        }
        unset($q);
    };

    foreach ($config as &$step) {
        if (is_array($step) && isset($step['questions'])) $rewrite($step['questions']);
    }
    unset($step);

    return $config;
}

/**
 * Hapus satu file upload. Hanya path yang cocok pola sah yang diproses,
 * jadi tidak mungkin menyentuh file di luar folder uploads/.
 */
function deleteUploadFile($relPath) {
    if (!isUploadPath($relPath)) return false;

    $abs = __DIR__ . '/' . $relPath;
    if (!is_file($abs)) return false;

    // Sabuk pengaman kedua: pastikan hasil resolusi benar-benar di dalam uploads/
    $realBase = realpath(__DIR__ . '/uploads');
    $realFile = realpath($abs);
    if ($realBase === false || $realFile === false || strpos($realFile, $realBase) !== 0) {
        return false;
    }

    return @unlink($realFile);
}

/**
 * Hapus semua gambar yang direferensikan satu baris raw_data respons.
 */
function deleteResponseImages($rawData) {
    $data = is_array($rawData) ? $rawData : (json_decode((string)$rawData, true) ?: []);
    $count = 0;
    foreach ($data as $v) {
        foreach ((is_array($v) ? $v : [$v]) as $item) {
            if (isResponseImage($item) && deleteUploadFile($item)) $count++;
        }
    }
    return $count;
}

/**
 * Hapus rekursif satu direktori di dalam uploads/ (dipakai saat survei dihapus).
 * @param string $relDir contoh: "uploads/responses/7"
 */
function removeUploadDir($relDir) {
    if (!preg_match('#^uploads/(responses|illustrations)/\d+$#', $relDir)) return false;

    $abs = realpath(__DIR__ . '/' . $relDir);
    $realBase = realpath(__DIR__ . '/uploads');
    if ($abs === false || $realBase === false || strpos($abs, $realBase) !== 0 || !is_dir($abs)) {
        return false;
    }

    foreach (scandir($abs) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $p = $abs . DIRECTORY_SEPARATOR . $entry;
        is_dir($p) ? @rmdir($p) : @unlink($p);
    }
    return @rmdir($abs);
}

/**
 * Base URL absolut yang diturunkan saat request.
 * Proyek ini tidak punya konstanta BASE_URL, dan path relatif tidak berguna
 * di dalam berkas Excel yang sudah diunduh.
 */
function baseUrl() {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir    = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');
    return $scheme . '://' . $host . $dir . '/';
}
