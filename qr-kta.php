<?php
// QR KTA: menghasilkan QR unik dari URL KTA dan menggabungkan logo kampus ke tengah QR.
// Tidak menyimpan data mahasiswa di QR; QR hanya membawa URL bertoken.
$data = isset($_GET['data']) ? (string)$_GET['data'] : '';
$size = isset($_GET['size']) ? (int)$_GET['size'] : 220;
$size = max(160, min(500, $size));

if ($data === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    exit('QR data tidak tersedia.');
}

$config_file = __DIR__ . DIRECTORY_SEPARATOR . 'kta_config.json';
$cfg = array('settings' => array());
if (is_file($config_file)) {
    $raw = @file_get_contents($config_file);
    $json = $raw !== false ? json_decode($raw, true) : null;
    if (is_array($json)) $cfg = $json;
}

$ext = preg_replace('/[^a-z0-9]/i', '', (string)($cfg['settings']['logo_ext'] ?? ''));
$logo_path = '';
if ($ext !== '') {
    $candidate = __DIR__ . DIRECTORY_SEPARATOR . 'kta_logo.' . $ext;
    if (is_file($candidate)) $logo_path = $candidate;
}
// Fallback penting: bila logo_ext di konfigurasi kosong/lama, cari file logo KTA
// yang benar-benar ada. Ini mencegah QR kembali tanpa logo setelah cetak massal.
if ($logo_path === '') {
    foreach (array('png','jpg','jpeg','webp') as $try_ext) {
        $candidate = __DIR__ . DIRECTORY_SEPARATOR . 'kta_logo.' . $try_ext;
        if (is_file($candidate)) {
            $logo_path = $candidate;
            $ext = $try_ext;
            break;
        }
    }
}
$has_logo = $logo_path !== '' && is_file($logo_path);

// QR dengan error correction H agar tetap mudah dipindai walaupun ada logo di tengah.
$remote = 'https://api.qrserver.com/v1/create-qr-code/?size=' . $size . 'x' . $size
        . '&margin=1&ecc=H&color=0-0-0&bgcolor=255-255-255&format=png&data=' . rawurlencode($data);

$qr_binary = false;
if (function_exists('curl_init')) {
    $ch = curl_init($remote);
    curl_setopt_array($ch, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT => 'KTA-Perpustakaan/1.0'
    ));
    $qr_binary = curl_exec($ch);
    curl_close($ch);
} elseif (ini_get('allow_url_fopen')) {
    $ctx = stream_context_create(array('http' => array('timeout' => 15, 'user_agent' => 'KTA-Perpustakaan/1.0')));
    $qr_binary = @file_get_contents($remote, false, $ctx);
}

if ($qr_binary === false || $qr_binary === '') {
    // Fallback aman: browser tetap menerima QR dari layanan generator.
    header('Location: ' . $remote, true, 302);
    exit();
}

// Jika GD tidak tersedia, kembalikan QR asli. Overlay HTML pada kta.php tetap aktif.
if (!$has_logo || !function_exists('imagecreatefromstring') || !function_exists('imagepng')) {
    header('Content-Type: image/png');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo $qr_binary;
    exit();
}

$qr = @imagecreatefromstring($qr_binary);
$logo = @imagecreatefromstring(@file_get_contents($logo_path));
if (!$qr || !$logo) {
    if ($qr) imagedestroy($qr);
    if ($logo) imagedestroy($logo);
    header('Content-Type: image/png');
    echo $qr_binary;
    exit();
}

$qr_w = imagesx($qr);
$qr_h = imagesy($qr);
$box = max(24, (int)round(min($qr_w, $qr_h) * 0.18));
$pad = max(3, (int)round($box * 0.12));
$inner = $box - (2 * $pad);

// Kotak putih di tengah menjaga quiet zone lokal sehingga modul QR tetap terbaca.
$white = imagecolorallocate($qr, 255, 255, 255);
imagefilledrectangle($qr, (int)(($qr_w-$box)/2), (int)(($qr_h-$box)/2), (int)(($qr_w+$box)/2), (int)(($qr_h+$box)/2), $white);

$lw = imagesx($logo);
$lh = imagesy($logo);
$scale = min($inner / max(1,$lw), $inner / max(1,$lh));
$dw = max(1, (int)round($lw * $scale));
$dh = max(1, (int)round($lh * $scale));
$dx = (int)round(($qr_w - $dw) / 2);
$dy = (int)round(($qr_h - $dh) / 2);

imagealphablending($qr, true);
imagesavealpha($qr, true);
imagecopyresampled($qr, $logo, $dx, $dy, 0, 0, $dw, $dh, $lw, $lh);

header('Content-Type: image/png');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
imagepng($qr, null, 6);
imagedestroy($qr);
imagedestroy($logo);
