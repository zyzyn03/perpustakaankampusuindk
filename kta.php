<?php
session_start();

// Mode scan publik: QR KTA dapat dibuka tanpa login, tetapi wajib token unik.
$public_scan = isset($_GET['scan']) && (string)$_GET['scan'] === '1';
if (!isset($_SESSION['admin']) && !$public_scan) {
    header("Location: login.php");
    exit();
}

require_once 'koneksi.php';

/* ============================================================
   KTA PERPUSTAKAAN - 2 SISI
   - Tidak membutuhkan perubahan database.
   - Data mahasiswa tetap dari tb_anggota.
   - Desain depan/belakang disimpan di kta_desain/.
   - Pengaturan teks + tanda tangan disimpan di kta_config.json.
    - QR dibuat otomatis, unik per NIM, dan membuka tampilan KTA publik terverifikasi.
   - Background desain tetap sebagai background; data mahasiswa,
     foto, barcode, teks dan tanda tangan dirender di atasnya.
   ============================================================ */
$foto_dir       = __DIR__ . DIRECTORY_SEPARATOR . 'foto';
$foto_url       = 'foto/';
$desain_dir     = __DIR__ . DIRECTORY_SEPARATOR . 'kta_desain';
$desain_url     = 'kta_desain/';
$config_file    = __DIR__ . DIRECTORY_SEPARATOR . 'kta_config.json';
$signature_file = __DIR__ . DIRECTORY_SEPARATOR . 'kta_signature.png';
$signature_url  = 'kta_signature.png';
$logo_dir        = __DIR__;
$logo_url_base   = '';

if (!is_dir($foto_dir))   @mkdir($foto_dir, 0755, true);
if (!is_dir($desain_dir)) @mkdir($desain_dir, 0755, true);

function kta_default_config() {
    return array(
        'active_design' => '',
        'designs' => array(),
        'settings' => array(
            'front_title' => 'KARTU TANDA ANGGOTA PERPUSTAKAAN',
            'front_subtitle' => 'UIN Datokarama Palu',
            'back_title' => 'KETENTUAN KARTU ANGGOTA',
            'back_text' => 'Kartu ini adalah milik perpustakaan. Harap dijaga dan digunakan sesuai ketentuan perpustakaan. Jika ditemukan, mohon dikembalikan ke perpustakaan.',
            'signature_name' => 'Kepala Perpustakaan',
            'signature_jabatan' => 'Kepala Perpustakaan',
            'signature_nip' => '',
            'show_signature' => 1,
            'logo_ext' => '',
            'public_base_url' => '',
            'public_secret' => bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16))
        )
    );
}

function kta_read_config($file) {
    $cfg = kta_default_config();
    if (is_file($file)) {
        $raw = @file_get_contents($file);
        $json = $raw !== false ? json_decode($raw, true) : null;
        if (is_array($json)) {
            $cfg = array_merge($cfg, $json);
            if (!isset($cfg['settings']) || !is_array($cfg['settings'])) {
                $cfg['settings'] = array();
            }
            $cfg['settings'] = array_merge(kta_default_config()['settings'], $cfg['settings']);
        }
    }
    if (!isset($cfg['designs']) || !is_array($cfg['designs'])) $cfg['designs'] = array();
    if (!isset($cfg['settings']) || !is_array($cfg['settings'])) $cfg['settings'] = kta_default_config()['settings'];
    $cfg['settings'] = array_merge(kta_default_config()['settings'], $cfg['settings']);
    if (empty($cfg['settings']['public_secret'])) {
        $cfg['settings']['public_secret'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    }
    return $cfg;
}

function kta_save_config($file, $cfg) {
    return @file_put_contents(
        $file,
        json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function kta_redirect($nim, $status, $msg) {
    header('Location: kta.php?nim=' . rawurlencode($nim) . '&status=' . rawurlencode($status) . '&msg=' . rawurlencode($msg));
    exit();
}

function kta_safe_id($value) {
    $value = preg_replace('/[^A-Za-z0-9_-]/', '_', $value);
    return trim($value, '_-');
}

function kta_image_url($base, $id, $file) {
    return $base . rawurlencode($id) . '/' . rawurlencode(basename($file));
}

function kta_h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function kta_public_token($nim, $secret) {
    return substr(hash_hmac('sha256', (string)$nim, (string)$secret), 0, 20);
}

// Cari alamat IPv4 LAN yang paling mungkin diakses HP pada jaringan yang sama.
// Mengabaikan loopback, link-local (169.254.x), dan adapter VirtualBox host-only
// (192.168.56.x) yang tidak punya gateway sehingga tidak bisa dijangkau dari HP.
function kta_best_lan_ip() {
    $candidates = array();

    // Kandidat dari resolusi nama host.
    $resolved = @gethostbynamel(gethostname());
    if (is_array($resolved)) {
        foreach ($resolved as $ip) $candidates[] = $ip;
    }

    // Kandidat tambahan dari ipconfig (bila shell tersedia), supaya adapter dengan
    // default gateway ikut terdeteksi walaupun resolusi nama host tidak lengkap.
    $gateway_ips = array();
    if (function_exists('shell_exec')) {
        $out = @shell_exec('ipconfig 2>NUL');
        if (is_string($out) && $out !== '') {
            // Kumpulkan IPv4 dari adapter yang punya Default Gateway.
            $blocks = preg_split('/\r?\n\r?\n/', $out);
            foreach ($blocks as $blk) {
                if (stripos($blk, 'Default Gateway') !== false && preg_match('/IPv4 Address[^:]*:\s*([0-9.]+)/i', $blk, $m)) {
                    $gateway_ips[] = $m[1];
                    $candidates[] = $m[1];
                }
            }
            // Kandidat umum semua IPv4.
            if (preg_match_all('/IPv4 Address[^:]*:\s*([0-9.]+)/i', $out, $mm)) {
                foreach ($mm[1] as $ip) $candidates[] = $ip;
            }
        }
    }

    $candidates = array_values(array_unique(array_filter($candidates)));

    $is_private = function($ip) {
        if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) return false;
        if (strpos($ip, '127.') === 0 || strpos($ip, '169.254.') === 0) return false;
        if (strpos($ip, '192.168.56.') === 0) return false; // VirtualBox host-only
        $parts = explode('.', $ip);
        $a = (int)$parts[0]; $b = (int)$parts[1];
        if ($a === 10) return true;
        if ($a === 192 && $b === 168) return true;
        if ($a === 172 && $b >= 16 && $b <= 31) return true;
        return false;
    };

    // Prioritas 1: IP yang punya default gateway (benar-benar tersambung ke jaringan).
    foreach ($gateway_ips as $ip) {
        if ($is_private($ip)) return $ip;
    }
    // Prioritas 2: IP privat apa pun yang lolos filter.
    foreach ($candidates as $ip) {
        if ($is_private($ip)) return $ip;
    }
    return '';
}

function kta_public_url($nim, $settings) {
    // Jika public_base_url diisi, gunakan alamat tetap ini untuk semua QR.
    // Nilainya boleh berupa URL lengkap .../kta.php atau alamat folder aplikasi.
    // Ini membuat QR tidak bergantung pada alamat lokal yang dipakai admin.
    $configured_base = trim((string)($settings['public_base_url'] ?? ''));
    $base = '';

    if ($configured_base !== '') {
        // Hanya terima alamat HTTP/HTTPS yang valid.
        $parts = @parse_url($configured_base);
        if (is_array($parts)
            && isset($parts['scheme'], $parts['host'])
            && in_array(strtolower($parts['scheme']), array('http', 'https'), true)
            && !isset($parts['user'])
            && !isset($parts['pass'])) {
            $configured_base = preg_replace('/[?#].*$/', '', $configured_base);
            $configured_base = rtrim($configured_base, '/');
            if (preg_match('~/kta\.php$~i', $configured_base)) {
                $base = $configured_base;
            } else {
                $base = $configured_base . '/kta.php';
            }
        }
    }

    // Fallback: bangun URL dari alamat LAN terbaik yang bisa diakses HP.
    if ($base === '') {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $host_name = preg_replace('/:\d+$/', '', (string)$host);
        $is_local = ($host_name === 'localhost' || $host_name === '127.0.0.1' || $host_name === '::1');
        if ($is_local) {
            $lan_ip = kta_best_lan_ip();
            if ($lan_ip !== '') {
                $port = '';
                if (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] !== ($scheme === 'https' ? 443 : 80)) {
                    $port = ':' . (int)$_SERVER['SERVER_PORT'];
                }
                $host = $lan_ip . $port;
            }
        }
        $path = $_SERVER['SCRIPT_NAME'] ?? '/kta.php';
        $base = $scheme . '://' . $host . $path;
    }

    $secret = (string)($settings['public_secret'] ?? '');
    return $base . '?scan=1&nim=' . rawurlencode((string)$nim) . '&t=' . rawurlencode(kta_public_token($nim, $secret));
}

// URL QR dibuat otomatis dari URL KTA mahasiswa. QR menggunakan warna terbalik
// (modul putih di atas latar hitam) sesuai desain kartu yang diminta.
// Endpoint ini hanya menerima data QR; identitas mahasiswa tetap berasal dari
// token HMAC di $scan_url sehingga setiap mahasiswa memiliki QR yang berbeda.
function kta_qr_url($scan_url, $size = 180) {
    $size = max(120, min(500, (int)$size));
    // QR tetap unik per mahasiswa. Logo kampus dikomposisikan oleh qr-kta.php
    // sehingga logo benar-benar menjadi bagian dari gambar QR, bukan sekadar overlay HTML.
    return 'qr-kta.php?size=' . $size . '&data=' . rawurlencode((string)$scan_url);
}

$config = kta_read_config($config_file);
// Pastikan secret QR tersimpan permanen walaupun kta_config.json berasal dari versi lama.
$raw_cfg = is_file($config_file) ? @file_get_contents($config_file) : false;
$raw_cfg_json = $raw_cfg !== false ? @json_decode($raw_cfg, true) : null;
if (!is_array($raw_cfg_json) || empty($raw_cfg_json['settings']['public_secret'])) {
    $config['settings']['public_secret'] = bin2hex(function_exists('random_bytes') ? random_bytes(16) : openssl_random_pseudo_bytes(16));
    @kta_save_config($config_file, $config);
} elseif (!is_file($config_file)) {
    @kta_save_config($config_file, $config);
}

$nim_get = isset($_GET['nim']) ? trim($_GET['nim']) : '';
if ($nim_get === '') {
    echo "<h3 style='text-align:center;margin-top:50px;'>Error: Parameter NIM tidak ditemukan!</h3>";
    exit();
}
$nim = mysqli_real_escape_string($koneksi, $nim_get);

/* ============================================================
   UPLOAD LOGO KAMPUS
   Logo dipakai sebagai watermark tengah kartu dan ikon tengah QR.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_logo'])) {
    if (!isset($_FILES['kampus_logo']) || $_FILES['kampus_logo']['error'] !== UPLOAD_ERR_OK) {
        kta_redirect($nim_get, 'error', 'Logo kampus belum dipilih atau gagal diunggah.');
    }
    $file = $_FILES['kampus_logo'];
    if ((int)$file['size'] > 2 * 1024 * 1024) {
        kta_redirect($nim_get, 'error', 'Ukuran logo maksimal 2 MB.');
    }
    $info = @getimagesize($file['tmp_name']);
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    $mime = $info && isset($info['mime']) ? $info['mime'] : '';
    if (!$info || !isset($allowed[$mime])) {
        kta_redirect($nim_get, 'error', 'Logo harus JPG, PNG, atau WEBP.');
    }
    $new_ext = $allowed[$mime];
    $old_ext = $config['settings']['logo_ext'] ?? '';
    if ($old_ext !== '' && is_file($logo_dir . DIRECTORY_SEPARATOR . 'kta_logo.' . $old_ext)) {
        @unlink($logo_dir . DIRECTORY_SEPARATOR . 'kta_logo.' . $old_ext);
    }
    $target = $logo_dir . DIRECTORY_SEPARATOR . 'kta_logo.' . $new_ext;
    if (!move_uploaded_file($file['tmp_name'], $target)) {
        kta_redirect($nim_get, 'error', 'Logo gagal disimpan. Pastikan folder aplikasi dapat ditulis PHP.');
    }
    $config['settings']['logo_ext'] = $new_ext;
    if (kta_save_config($config_file, $config) === false) {
        @unlink($target);
        kta_redirect($nim_get, 'error', 'Logo tersimpan tetapi konfigurasi gagal diperbarui.');
    }
    kta_redirect($nim_get, 'success', 'Logo kampus berhasil disimpan.');
}

/* ============================================================
   HAPUS LOGO KAMPUS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_logo'])) {
    $ext = $config['settings']['logo_ext'] ?? '';
    if ($ext !== '') { @unlink($logo_dir . DIRECTORY_SEPARATOR . 'kta_logo.' . $ext); }
    $config['settings']['logo_ext'] = '';
    kta_save_config($config_file, $config);
    kta_redirect($nim_get, 'success', 'Logo kampus berhasil dihapus.');
}

/* ============================================================
   UPLOAD / GANTI FOTO MAHASISWA
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_foto'])) {
    if (!isset($_FILES['foto_mahasiswa']) || $_FILES['foto_mahasiswa']['error'] !== UPLOAD_ERR_OK) {
        kta_redirect($nim_get, 'error', 'Foto belum dipilih atau gagal diunggah.');
    }

    $file = $_FILES['foto_mahasiswa'];
    if ((int)$file['size'] > 3 * 1024 * 1024) {
        kta_redirect($nim_get, 'error', 'Ukuran foto maksimal 3 MB.');
    }

    $info = @getimagesize($file['tmp_name']);
    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    $mime = $info && isset($info['mime']) ? $info['mime'] : '';
    if (!$info || !isset($allowed[$mime])) {
        kta_redirect($nim_get, 'error', 'Format foto harus JPG, PNG, atau WEBP.');
    }

    $old_q = mysqli_query($koneksi, "SELECT foto FROM tb_anggota WHERE nim = '$nim' LIMIT 1");
    $old = $old_q ? mysqli_fetch_assoc($old_q) : null;

    $new_name = 'mhs_' . kta_safe_id($nim_get) . '_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6) . '.' . $allowed[$mime];
    $target = $foto_dir . DIRECTORY_SEPARATOR . $new_name;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        kta_redirect($nim_get, 'error', 'Foto gagal disimpan. Pastikan folder foto dapat ditulis oleh PHP.');
    }

    $db_name = mysqli_real_escape_string($koneksi, $new_name);
    if (!mysqli_query($koneksi, "UPDATE tb_anggota SET foto = '$db_name' WHERE nim = '$nim' LIMIT 1")) {
        @unlink($target);
        kta_redirect($nim_get, 'error', 'Foto tersimpan tetapi gagal dikaitkan ke data mahasiswa.');
    }

    if ($old && !empty($old['foto']) && $old['foto'] !== 'default.jpg') {
        $old_name = basename($old['foto']);
        $old_path = $foto_dir . DIRECTORY_SEPARATOR . $old_name;
        if (is_file($old_path) && $old_name !== $new_name) @unlink($old_path);
    }

    kta_redirect($nim_get, 'success', 'Foto mahasiswa berhasil diperbarui.');
}

/* ============================================================
   HAPUS FOTO MAHASISWA
   Foto dihapus dari file dan kolom foto dikosongkan.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_foto'])) {
    $old_q = mysqli_query($koneksi, "SELECT foto FROM tb_anggota WHERE nim = '$nim' LIMIT 1");
    $old = $old_q ? mysqli_fetch_assoc($old_q) : null;
    if ($old && !empty($old['foto'])) {
        $old_name = basename($old['foto']);
        if ($old_name !== 'default.jpg') {
            $old_path = $foto_dir . DIRECTORY_SEPARATOR . $old_name;
            if (is_file($old_path)) @unlink($old_path);
        }
    }
    if (!mysqli_query($koneksi, "UPDATE tb_anggota SET foto = '' WHERE nim = '$nim' LIMIT 1")) {
        kta_redirect($nim_get, 'error', 'Foto gagal dihapus dari data mahasiswa.');
    }
    kta_redirect($nim_get, 'success', 'Foto mahasiswa berhasil dihapus.');
}

/* ============================================================
   UPLOAD DESAIN DEPAN + BELAKANG
   Desain baru otomatis aktif.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_desain'])) {
    $nama = isset($_POST['nama_desain']) ? trim($_POST['nama_desain']) : '';
    $show_photo = isset($_POST['show_photo']) ? 1 : 0;

    if ($nama === '') $nama = 'Desain KTA ' . date('d-m-Y H:i');
    if (strlen($nama) > 80) $nama = substr($nama, 0, 80);

    if (!isset($_FILES['desain_depan']) || $_FILES['desain_depan']['error'] !== UPLOAD_ERR_OK ||
        !isset($_FILES['desain_belakang']) || $_FILES['desain_belakang']['error'] !== UPLOAD_ERR_OK) {
        kta_redirect($nim_get, 'error', 'Desain depan dan belakang wajib dipilih.');
    }

    $allowed = array('image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp');
    $uploads = array();

    foreach (array('depan' => $_FILES['desain_depan'], 'belakang' => $_FILES['desain_belakang']) as $side => $file) {
        if ((int)$file['size'] > 5 * 1024 * 1024) {
            kta_redirect($nim_get, 'error', 'Ukuran masing-masing desain maksimal 5 MB.');
        }
        $info = @getimagesize($file['tmp_name']);
        $mime = $info && isset($info['mime']) ? $info['mime'] : '';
        if (!$info || !isset($allowed[$mime])) {
            kta_redirect($nim_get, 'error', 'Desain depan/belakang harus JPG, PNG, atau WEBP.');
        }
        $uploads[$side] = array('tmp' => $file['tmp_name'], 'ext' => $allowed[$mime]);
    }

    $id = 'kta_' . date('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 8);
    $dir = $desain_dir . DIRECTORY_SEPARATOR . $id;
    if (!@mkdir($dir, 0755, true) && !is_dir($dir)) {
        kta_redirect($nim_get, 'error', 'Folder desain gagal dibuat. Periksa izin folder kta_desain.');
    }

    $front = $dir . DIRECTORY_SEPARATOR . 'front.' . $uploads['depan']['ext'];
    $back  = $dir . DIRECTORY_SEPARATOR . 'back.' . $uploads['belakang']['ext'];

    if (!move_uploaded_file($uploads['depan']['tmp'], $front) || !move_uploaded_file($uploads['belakang']['tmp'], $back)) {
        if (is_file($front)) @unlink($front);
        if (is_file($back)) @unlink($back);
        @rmdir($dir);
        kta_redirect($nim_get, 'error', 'Desain gagal disimpan. Pastikan folder kta_desain dapat ditulis PHP.');
    }

    $config['designs'][$id] = array(
        'name' => $nama,
        'front' => basename($front),
        'back' => basename($back),
        'show_photo' => $show_photo,
        'created_at' => date('Y-m-d H:i:s')
    );
    $config['active_design'] = $id;

    if (kta_save_config($config_file, $config) === false) {
        @unlink($front); @unlink($back); @rmdir($dir);
        unset($config['designs'][$id]);
        kta_redirect($nim_get, 'error', 'Desain tersimpan tetapi konfigurasi gagal diperbarui.');
    }

    kta_redirect($nim_get, 'success', 'Desain depan + belakang berhasil ditambahkan dan langsung digunakan.');
}

/* ============================================================
   AKTIFKAN DESAIN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_design'])) {
    $id = basename(trim($_POST['design_id'] ?? ''));
    if ($id !== '' && isset($config['designs'][$id])) {
        $config['active_design'] = $id;
        if (kta_save_config($config_file, $config) !== false) {
            kta_redirect($nim_get, 'success', 'Desain KTA berhasil diganti.');
        }
    }
    kta_redirect($nim_get, 'error', 'Desain yang dipilih tidak ditemukan.');
}

/* ============================================================
   HAPUS DESAIN
   Sekarang desain aktif juga boleh dihapus. Jika masih ada desain
   lain, sistem otomatis memilih desain pertama yang tersisa.
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_design'])) {
    $id = basename(trim($_POST['design_id'] ?? ''));
    if ($id === '' || !isset($config['designs'][$id])) {
        kta_redirect($nim_get, 'error', 'Desain yang akan dihapus tidak ditemukan.');
    }

    $dir = $desain_dir . DIRECTORY_SEPARATOR . $id;
    if (is_dir($dir)) {
        $files = @scandir($dir);
        if ($files !== false) {
            foreach ($files as $f) {
                if ($f !== '.' && $f !== '..') @unlink($dir . DIRECTORY_SEPARATOR . $f);
            }
        }
        @rmdir($dir);
    }

    unset($config['designs'][$id]);
    if ($id === ($config['active_design'] ?? '')) {
        $keys = array_keys($config['designs']);
        $config['active_design'] = count($keys) ? $keys[0] : '';
    }

    kta_save_config($config_file, $config);
    kta_redirect($nim_get, 'success', 'Desain berhasil dihapus.');
}

/* ============================================================
   SIMPAN PENGATURAN TEKS KTA
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    $settings = $config['settings'];
    $settings['front_title'] = trim($_POST['front_title'] ?? '');
    $settings['front_subtitle'] = trim($_POST['front_subtitle'] ?? '');
    $settings['back_title'] = trim($_POST['back_title'] ?? '');
    $settings['back_text'] = trim($_POST['back_text'] ?? '');
    $settings['signature_name'] = trim($_POST['signature_name'] ?? '');
    $settings['signature_jabatan'] = trim($_POST['signature_jabatan'] ?? '');
    $settings['signature_nip'] = trim($_POST['signature_nip'] ?? '');
    $settings['show_signature'] = isset($_POST['show_signature']) ? 1 : 0;
    // Alamat publik untuk QR. Boleh dikosongkan agar sistem memilih IP LAN otomatis.
    $settings['public_base_url'] = trim($_POST['public_base_url'] ?? '');

    if ($settings['front_title'] === '') $settings['front_title'] = 'KARTU TANDA ANGGOTA PERPUSTAKAAN';
    if ($settings['front_subtitle'] === '') $settings['front_subtitle'] = 'UIN Datokarama Palu';
    if ($settings['back_title'] === '') $settings['back_title'] = 'KETENTUAN KARTU ANGGOTA';
    if ($settings['back_text'] === '') $settings['back_text'] = 'Kartu ini adalah milik perpustakaan. Harap dijaga dan digunakan sesuai ketentuan perpustakaan.';
    if ($settings['signature_name'] === '') $settings['signature_name'] = 'Kepala Perpustakaan';
    if ($settings['signature_jabatan'] === '') $settings['signature_jabatan'] = 'Kepala Perpustakaan';
    if (!isset($settings['signature_nip'])) $settings['signature_nip'] = '';
    if (empty($settings['public_secret'])) $settings['public_secret'] = kta_default_config()['settings']['public_secret'];

    $config['settings'] = $settings;
    if (kta_save_config($config_file, $config) === false) {
        kta_redirect($nim_get, 'error', 'Pengaturan gagal disimpan. Pastikan kta_config.json dapat ditulis PHP.');
    }
    kta_redirect($nim_get, 'success', 'Pengaturan teks KTA berhasil disimpan.');
}

/* ============================================================
   SIMPAN TANDA TANGAN ONLINE DARI CANVAS
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_signature'])) {
    $signature = isset($_POST['signature_data']) ? trim($_POST['signature_data']) : '';

    if ($signature === '' || strpos($signature, 'data:image/png;base64,') !== 0) {
        kta_redirect($nim_get, 'error', 'Tanda tangan belum dibuat. Silakan gambar tanda tangan pada kotak terlebih dahulu.');
    }

    $encoded = substr($signature, strlen('data:image/png;base64,'));
    $encoded = str_replace(' ', '+', $encoded);
    $binary = base64_decode($encoded, true);

    if ($binary === false || strlen($binary) < 50) {
        kta_redirect($nim_get, 'error', 'Data tanda tangan tidak valid.');
    }

    $img_info = @getimagesizefromstring($binary);
    if (!$img_info || ($img_info['mime'] ?? '') !== 'image/png') {
        kta_redirect($nim_get, 'error', 'Tanda tangan harus berupa gambar PNG.');
    }

    if (@file_put_contents($signature_file, $binary) === false) {
        kta_redirect($nim_get, 'error', 'Tanda tangan gagal disimpan. Pastikan folder aplikasi dapat ditulis PHP.');
    }

    $config['settings']['show_signature'] = 1;
    kta_save_config($config_file, $config);
    kta_redirect($nim_get, 'success', 'Tanda tangan berhasil disimpan dan akan muncul di sisi belakang KTA.');
}

/* ============================================================
   HAPUS TANDA TANGAN
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_signature'])) {
    if (is_file($signature_file)) @unlink($signature_file);
    $config['settings']['show_signature'] = 0;
    kta_save_config($config_file, $config);
    kta_redirect($nim_get, 'success', 'Tanda tangan berhasil dihapus.');
}

/* ============================================================
   DATA MAHASISWA
   ============================================================ */
$q = mysqli_query($koneksi, "SELECT * FROM tb_anggota WHERE nim = '$nim' LIMIT 1");
if (!$q || mysqli_num_rows($q) === 0) {
    echo "<h3 style='text-align:center;margin-top:50px;'>Error: Data mahasiswa dengan NIM " . kta_h($nim_get) . " tidak ditemukan!</h3>";
    exit();
}
$data = mysqli_fetch_assoc($q);

$pesan = isset($_GET['status']) && $_GET['status'] === 'success' ? ($_GET['msg'] ?? '') : '';
$error = isset($_GET['status']) && $_GET['status'] !== 'success' ? ($_GET['msg'] ?? '') : '';

/* ============================================================
   NORMALISASI DESAIN LAMA
   Config lama tetap bisa dibaca. Tidak perlu migrasi database.
   ============================================================ */
$designs = isset($config['designs']) && is_array($config['designs']) ? $config['designs'] : array();
$active_id = isset($config['active_design']) ? basename($config['active_design']) : '';
$active = null;
if ($active_id !== '' && isset($designs[$active_id])) {
    $candidate = $desain_dir . DIRECTORY_SEPARATOR . $active_id;
    if (is_dir($candidate) && !empty($designs[$active_id]['front']) && !empty($designs[$active_id]['back'])) {
        $front_path = $candidate . DIRECTORY_SEPARATOR . basename($designs[$active_id]['front']);
        $back_path  = $candidate . DIRECTORY_SEPARATOR . basename($designs[$active_id]['back']);
        if (is_file($front_path) && is_file($back_path)) {
            $active = $designs[$active_id];
            $active['id'] = $active_id;
        }
    }
}

$settings = $config['settings'];
$logo_ext = preg_replace('/[^a-z0-9]/i', '', (string)($settings['logo_ext'] ?? ''));
$logo_path = $logo_ext !== '' ? ($logo_dir . DIRECTORY_SEPARATOR . 'kta_logo.' . $logo_ext) : '';
$has_logo = $logo_path !== '' && is_file($logo_path);
$logo_src = $has_logo ? ('kta_logo.' . $logo_ext) : '';
$scan_url = kta_public_url($data['nim'], $settings);
$qr_url = kta_qr_url($scan_url, 220);

// Validasi token untuk tampilan publik hasil scan QR.
if ($public_scan && !$active) {
    http_response_code(404);
    echo '<div style="font-family:Segoe UI,sans-serif;max-width:600px;margin:60px auto;padding:24px;text-align:center"><h2>KTA belum tersedia</h2><p>Desain KTA aktif belum tersedia.</p></div>';
    exit();
}

if ($public_scan) {
    $token = isset($_GET['t']) ? (string)$_GET['t'] : '';
    $expected = kta_public_token($data['nim'], $settings['public_secret'] ?? '');
    if ($token === '' || !hash_equals($expected, $token)) {
        http_response_code(403);
        echo '<div style="font-family:Segoe UI,sans-serif;max-width:600px;margin:60px auto;padding:24px;text-align:center"><h2>Akses KTA tidak valid</h2><p>QR KTA tidak memiliki token verifikasi yang benar.</p></div>';
        exit();
    }
}

$foto_name = !empty($data['foto']) ? basename($data['foto']) : '';
$foto_path = $foto_name !== '' ? ($foto_dir . DIRECTORY_SEPARATOR . $foto_name) : '';
$has_photo = $foto_path !== '' && is_file($foto_path);
$foto_src = $has_photo ? ($foto_url . rawurlencode($foto_name)) : '';

$has_signature = is_file($signature_file) && filesize($signature_file) > 0 && !empty($settings['show_signature']);

if ($public_scan) {
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>KTA <?= kta_h($data['nim']); ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;font-family:Segoe UI,Tahoma,sans-serif;color:#111827}.verify{max-width:920px;margin:28px auto;padding:18px}.verify h1{text-align:center;font-size:20px}.verify-sub{text-align:center;color:#64748b;font-size:13px;margin-bottom:20px}.cards-public{display:flex;gap:20px;justify-content:center;flex-wrap:wrap}.kta-card{width:85.6mm;height:53.98mm;position:relative;overflow:hidden;background:#fff;border-radius:10px;box-shadow:0 6px 24px rgba(15,23,42,.18);color:var(--kta-text,#111);--kta-text:#111;--kta-line:rgba(0,0,0,.25);-webkit-print-color-adjust:exact;print-color-adjust:exact}.kta-card.theme-dark{--kta-text:#fff;--kta-line:rgba(255,255,255,.45)}.kta-card.theme-light{--kta-text:#111827;--kta-line:rgba(0,0,0,.25)}.kta-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover}.watermark{position:absolute;left:50%;top:50%;width:34%;height:55%;object-fit:contain;transform:translate(-50%,-50%);opacity:.09;z-index:1}.card-logo{position:absolute;left:7px;top:5px;width:8.5mm;height:8.5mm;object-fit:contain;z-index:4}.front-content,.back-content{position:relative;z-index:2;width:100%;height:100%;padding:9px;display:flex;flex-direction:column}.front-content{justify-content:space-between}.header{padding-left:0;padding-right:0;padding-bottom:3px;border-bottom:1px solid var(--kta-line);color:var(--kta-text);text-align:center}.header b{display:block;font-size:9px;text-transform:uppercase}.header span{font-size:6.8px;color:var(--kta-text)}.main{display:flex;gap:9px;align-items:flex-start;justify-content:flex-start}.photo{width:18mm;height:24mm;object-fit:cover;border-radius:3px;border:1px solid rgba(0,0,0,.3);background:#e2e8f0;flex:0 0 auto}.info{min-width:0;flex:1;text-align:left}.label{font-size:5.6px;text-transform:uppercase;color:var(--kta-text);opacity:.8;margin-top:2px}.value{font-size:7.8px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.qr-box{align-self:flex-end;background:#000;border-radius:4px;padding:2px;box-shadow:0 1px 3px rgba(0,0,0,.15)}.qr-wrap{position:relative;width:15mm;height:15mm}.qr{display:block;width:15mm;height:15mm;image-rendering:pixelated;object-fit:contain}.qr-logo{position:absolute;left:50%;top:50%;width:20%;height:20%;transform:translate(-50%,-50%);object-fit:contain;background:#fff;border-radius:3px;padding:1px;z-index:3}.back-content{position:relative;display:block;color:var(--kta-text)}.back-title{font-size:9px;font-weight:800;text-transform:uppercase;text-align:center;color:var(--kta-text)}.back-text{font-size:7px;line-height:1.35;white-space:pre-line;max-width:94%;margin-top:8px;color:var(--kta-text)}.signature-block{position:absolute;right:9px;bottom:3.2mm;text-align:center;min-width:100px;max-width:135px;color:var(--kta-text)}.signature-role{font-size:6.2px;font-weight:700;margin-bottom:1px}.signature-img{width:105px;height:38px;object-fit:contain;display:block;margin:0 auto 1px}.theme-dark .signature-img{filter:brightness(0) invert(1)}.signature-line{padding-top:1px;font-size:6.2px;font-weight:700;color:var(--kta-text)}.signature-nip{font-size:5.8px}.back-footer{position:absolute;left:9px;right:155px;bottom:3.2mm;font-size:6.5px;text-align:left;color:var(--kta-text)}.verify-actions{text-align:center;margin:10px 0 18px}.verify-actions button{border:0;border-radius:7px;padding:9px 14px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer}@media print{@page{size:A4 portrait;margin:0}body{background:#fff!important}.verify{max-width:none;margin:0;padding:0}.verify h1,.verify-sub,.verify-actions{display:none!important}.cards-public{display:flex!important;gap:8mm!important;justify-content:center!important;flex-wrap:nowrap!important;padding-top:121mm}.kta-card{width:85.6mm!important;height:53.98mm!important;border-radius:0!important;box-shadow:none!important;flex:0 0 85.6mm!important}*{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}}</style><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet" media="print" onload="this.media='all'"><noscript><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"></noscript>
</head><body><div class="verify"><h1><?= kta_h($settings['front_title']); ?></h1><div class="verify-sub">KTA terverifikasi · <?= kta_h($data['nama_lengkap']); ?> · <?= kta_h($data['nim']); ?></div><div class="verify-actions"><button type="button" onclick="window.print()">🖨️ Cetak KTA</button></div><div class="cards-public"><div class="kta-card"><img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url,$active_id,$active['front'])); ?>"><?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>"><img class="card-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>"><?php endif; ?><div class="front-content"><div class="header"><b><?= kta_h($settings['front_title']); ?></b><span><?= kta_h($settings['front_subtitle']); ?></span></div><div class="main"><?php if($has_photo && !empty($active['show_photo'])): ?><img class="photo" src="<?= kta_h($foto_src); ?>"><?php endif; ?><div class="info"><div class="label">Nama</div><div class="value"><?= kta_h($data['nama_lengkap']); ?></div><div class="label">NIM</div><div class="value"><?= kta_h($data['nim']); ?></div><div class="label">Program Studi</div><div class="value"><?= kta_h($data['program_studi']); ?></div></div></div><div class="qr-box"><div class="qr-wrap"><img class="qr" src="<?= kta_h($qr_url); ?>" alt="QR KTA <?= kta_h($data['nim']); ?>"><?php if($has_logo): ?><img class="qr-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo"><?php endif; ?></div></div></div></div><div class="kta-card"><img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url,$active_id,$active['back'])); ?>"><?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>"><?php endif; ?><div class="back-content"><div class="back-title"><?= kta_h($settings['back_title']); ?></div><div class="back-text"><?= kta_h($settings['back_text']); ?></div><?php if($has_signature): ?><div class="signature-block"><div class="signature-role"><?= kta_h($settings['signature_jabatan']); ?></div><img class="signature-img" src="<?= kta_h($signature_url); ?>?v=<?= filemtime($signature_file); ?>"><div class="signature-line"><?= kta_h($settings['signature_name']); ?></div><?php if(!empty($settings['signature_nip'])): ?><div class="signature-nip">NIP. <?= kta_h($settings['signature_nip']); ?></div><?php endif; ?></div><?php endif; ?><div class="back-footer">NIM: <?= kta_h($data['nim']); ?> · <?= kta_h($data['nama_lengkap']); ?></div></div></div></div></div><script>
(function(){document.querySelectorAll('.kta-card').forEach(function(card){var bg=card.querySelector('.kta-bg');if(!bg)return;function sample(){try{var c=document.createElement('canvas'),x=c.getContext('2d',{willReadFrequently:true});c.width=64;c.height=40;x.drawImage(bg,0,0,64,40);var d=x.getImageData(0,0,64,40).data,t=0,n=0;for(var i=0;i<d.length;i+=4){if(d[i+3]<10)continue;t+=.2126*d[i]+.7152*d[i+1]+.0722*d[i+2];n++;}var l=n?t/n:255;card.classList.toggle('theme-dark',l<145);card.classList.toggle('theme-light',l>=145);}catch(e){card.classList.add('theme-light')}}if(bg.complete)sample();else bg.addEventListener('load',sample,{once:true});});})();
</script></div></body></html>
<?php exit(); }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>KTA Perpustakaan - <?= kta_h($data['nim']); ?></title>
<style>
*{box-sizing:border-box}
body{margin:0;padding:24px;background:#f1f5f9;color:#1e293b;font-family:'Segoe UI',Tahoma,sans-serif}
.page{max-width:1200px;margin:auto}.top{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}
button,.btn{border:0;border-radius:7px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:13px}
.btn-print{background:#2563eb;color:#fff}.btn-back{background:#64748b;color:#fff}.btn-upload{background:#0f766e;color:#fff}.btn-use{background:#2563eb;color:#fff;padding:7px 9px;font-size:11px}.btn-del{background:#dc2626;color:#fff;padding:7px 9px;font-size:11px}.btn-save{background:#0f766e;color:#fff}.btn-clear{background:#64748b;color:#fff}.btn-danger{background:#dc2626;color:#fff}
.panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:18px;box-shadow:0 2px 8px rgba(15,23,42,.05)}
h2,h3{margin:0 0 10px}.muted{font-size:12px;color:#64748b;line-height:1.5}.alert{padding:11px 13px;border-radius:7px;margin-bottom:14px;font-size:13px}.ok{background:#dcfce7;color:#166534}.err{background:#fee2e2;color:#991b1b}
.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.box{border:1px dashed #cbd5e1;border-radius:8px;padding:14px}.box input[type=file],.box input[type=text],.box textarea{width:100%;margin:8px 0;padding:9px;border:1px solid #cbd5e1;border-radius:5px;font:inherit}.box textarea{min-height:90px;resize:vertical}.settings-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px}.field label{font-size:12px;font-weight:700;color:#475569;display:block}.field input,.field textarea{width:100%;margin-top:5px;padding:9px;border:1px solid #cbd5e1;border-radius:5px;font:inherit}.field textarea{min-height:100px;resize:vertical}
.designs{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:12px;margin-top:12px}.design{border:1px solid #e2e8f0;border-radius:8px;padding:9px;background:#f8fafc}.sides{display:grid;grid-template-columns:1fr 1fr;gap:6px}.sides img{width:100%;aspect-ratio:1.586/1;object-fit:cover;border-radius:5px;background:#e2e8f0}.design-name{font-size:12px;font-weight:700;margin:8px 0 4px}.design-meta{font-size:10px;color:#64748b}.design-actions{display:flex;gap:6px;margin-top:8px;align-items:center}.badge{background:#dcfce7;color:#166534;border-radius:4px;padding:5px 7px;font-size:10px;font-weight:700}
.signature-wrap{max-width:560px}.signature-pad{width:100%;height:170px;background:#fff;border:2px dashed #94a3b8;border-radius:8px;touch-action:none;display:block}.signature-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.signature-current{max-width:240px;max-height:90px;object-fit:contain;background:#fff;border:1px solid #e2e8f0;border-radius:6px;padding:4px}
.preview-title{font-size:12px;color:#64748b;margin-bottom:10px}.cards{display:flex;flex-wrap:wrap;justify-content:center;gap:28px}.card-wrap{text-align:center}.side-label{font-size:11px;font-weight:700;color:#475569;margin-bottom:7px}
.kta-card{width:85.6mm;height:53.98mm;position:relative;overflow:hidden;background:#fff;color:var(--kta-text,#111);box-shadow:0 5px 18px rgba(0,0,0,.2);border-radius:7px;-webkit-print-color-adjust:exact;print-color-adjust:exact;--kta-text:#111;--kta-muted:#334155;--kta-line:rgba(0,0,0,.25)}
.kta-card.theme-dark{--kta-text:#fff;--kta-muted:#f1f5f9;--kta-line:rgba(255,255,255,.45)}
.kta-card.theme-light{--kta-text:#111827;--kta-muted:#334155;--kta-line:rgba(0,0,0,.25)}
.kta-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}.card-logo{position:absolute;left:7px;top:5px;width:8.5mm;height:8.5mm;object-fit:contain;z-index:4}.watermark{position:absolute;left:50%;top:50%;width:34%;height:55%;object-fit:contain;transform:translate(-50%,-50%);opacity:.09;z-index:1;pointer-events:none}.front-content,.back-content{position:relative;z-index:2;width:100%;height:100%;padding:9px;display:flex;flex-direction:column}.front-content{justify-content:space-between}.header{padding-left:0;padding-right:0;padding-bottom:3px;border-bottom:1px solid var(--kta-line);color:var(--kta-text);text-align:center}.header b{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.25px}.header span{font-size:6.8px;color:var(--kta-text)}.main{display:flex;gap:10px;align-items:flex-start;justify-content:flex-start}.photo{width:18mm;height:24mm;object-fit:cover;border-radius:3px;border:1px solid rgba(0,0,0,.3);background:#e2e8f0;flex:0 0 auto}.info{min-width:0;flex:1;text-align:left}.label{font-size:5.6px;text-transform:uppercase;margin-top:2px;color:var(--kta-muted)}.value{font-size:7.8px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.qr-box{align-self:flex-end;background:#000;border-radius:4px;padding:2px;box-shadow:0 1px 3px rgba(0,0,0,.15)}.qr{display:block;width:15mm;height:15mm;image-rendering:pixelated;object-fit:contain}.qr-wrap{position:relative;width:15mm;height:15mm}.qr-logo{position:absolute;left:50%;top:50%;width:18%;height:18%;transform:translate(-50%,-50%);object-fit:contain;background:#fff;border-radius:4px;padding:1px;z-index:3}.back-content{position:relative;display:block;color:var(--kta-text)}.back-title{font-size:9px;font-weight:800;text-transform:uppercase;text-align:center;color:var(--kta-text)}.back-text{font-size:7px;line-height:1.35;white-space:pre-line;max-width:94%;margin-top:8px;color:var(--kta-text)}.back-footer{position:absolute;left:9px;right:155px;bottom:3.2mm;font-size:6.5px;text-align:left;color:var(--kta-text)}.signature-block{position:absolute;right:9px;bottom:3.2mm;text-align:center;min-width:100px;max-width:135px;color:var(--kta-text)}.signature-role{font-size:6.2px;font-weight:700;margin-bottom:1px}.signature-img{width:105px;height:38px;object-fit:contain;display:block;margin:0 auto 1px}.theme-dark .signature-img{filter:brightness(0) invert(1)}.signature-line{padding-top:1px;font-size:6.2px;font-weight:700;color:var(--kta-text)}.signature-nip{font-size:5.8px}
/*
 * CETAK 1:1 (UKURAN KARTU ID-1 / KTP)
 * Standar fisik: 85,60 x 53,98 mm.
 * Satu lembar A4 memuat sisi DEPAN dan BELAKANG berdampingan.
 * Semua ukuran kartu pada mode print dikunci dalam satuan mm agar
 * browser tidak mengubah ukuran kartu menjadi ukuran responsif.
 */
@page{size:A4 portrait;margin:0}
.print-sheet{display:none}
@media print{
    html,body{
        width:210mm!important;
        min-width:210mm!important;
        height:297mm!important;
        min-height:297mm!important;
        margin:0!important;
        padding:0!important;
        background:#fff!important;
    }
    .no-print{display:none!important}
    .page{
        width:210mm!important;
        max-width:none!important;
        min-width:210mm!important;
        margin:0!important;
        padding:0!important;
    }
    .print-sheet{
        display:flex!important;
        box-sizing:border-box!important;
        width:210mm!important;
        height:297mm!important;
        min-width:210mm!important;
        min-height:297mm!important;
        margin:0!important;
        padding:0!important;
        align-items:center!important;
        justify-content:center!important;
        gap:8mm!important;
        flex-direction:row!important;
        page-break-after:auto!important;
        break-after:auto!important;
        overflow:hidden!important;
    }
    .print-sheet .card-wrap{
        display:block!important;
        width:85.6mm!important;
        min-width:85.6mm!important;
        max-width:85.6mm!important;
        flex:0 0 85.6mm!important;
        text-align:center!important;
        margin:0!important;
        padding:0!important;
    }
    .print-sheet .side-label{
        display:block!important;
        width:85.6mm!important;
        height:5mm!important;
        font-size:0!important;
        line-height:0!important;
        margin:0!important;
        padding:0!important;
        color:#334155!important;
    }
    .print-sheet .kta-card{
        box-sizing:border-box!important;
        display:block!important;
        width:85.6mm!important;
        min-width:85.6mm!important;
        max-width:85.6mm!important;
        height:53.98mm!important;
        min-height:53.98mm!important;
        max-height:53.98mm!important;
        margin:0!important;
        padding:0!important;
        border-radius:0!important;
        box-shadow:none!important;
        overflow:hidden!important;
    }
    .print-sheet .kta-card,.print-sheet .kta-bg{
        print-color-adjust:exact!important;
        -webkit-print-color-adjust:exact!important;
    }
    .print-sheet .kta-bg{
        width:85.6mm!important;
        height:53.98mm!important;
        min-width:85.6mm!important;
        min-height:53.98mm!important;
        max-width:85.6mm!important;
        max-height:53.98mm!important;
    }
    .cards{display:none!important}
}
@media(max-width:700px){body{padding:12px}.cards{gap:18px}}

/* ===== DASHBOARD LAYOUT (sidebar + topbar) ===== */
body.with-dash{padding:0;font-family:'Plus Jakarta Sans','Segoe UI',Tahoma,sans-serif}
.dash-side{position:fixed;left:0;top:0;bottom:0;width:260px;background:#0b5a48;color:#fff;display:flex;flex-direction:column;z-index:50;overflow-y:auto;transition:transform .25s ease}
.dash-brand{display:flex;align-items:center;gap:12px;padding:22px 20px 18px;border-bottom:1px solid rgba(255,255,255,.12)}
.dash-brand-logo{width:44px;height:44px;border-radius:10px;background:#fff;padding:4px;display:flex;align-items:center;justify-content:center;flex:0 0 auto;overflow:hidden}
.dash-brand-logo img{width:100%;height:100%;object-fit:contain}
.dash-brand-logo svg{width:26px;height:26px}
.dash-brand b{display:block;font-size:17px;font-weight:800;line-height:1.2}
.dash-brand span{display:block;font-size:11.5px;opacity:.8;margin-top:2px}
.dash-nav{padding:18px 14px;display:flex;flex-direction:column;gap:6px}
.dash-nav a{display:flex;align-items:center;gap:14px;padding:12px 14px;border-radius:10px;color:#e7f4ef;text-decoration:none;font-size:14.5px;font-weight:600;transition:background .15s}
.dash-nav a:hover{background:rgba(255,255,255,.09)}
.dash-nav a.active{background:#1b7a63;color:#fff}
.dash-nav svg{width:20px;height:20px;flex:0 0 auto;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.dash-nav hr{border:0;border-top:1px solid rgba(255,255,255,.14);margin:10px 6px}
.dash-flower{margin-top:auto;padding:24px 0 34px;display:flex;justify-content:center;pointer-events:none}.dash-flower img{width:150px;height:auto;display:block}
.dash-main{margin-left:260px;min-height:100vh}
.dash-top{position:sticky;top:0;z-index:40;background:#fff;border-bottom:1px solid #e2e8f0;height:64px;padding:0 24px;display:flex;align-items:center;justify-content:space-between;gap:12px;font-family:'Plus Jakarta Sans','Segoe UI',Tahoma,sans-serif}
.dash-top h1{margin:0;font-size:18px;font-weight:800;color:#0f172a}
.dash-top-left{display:flex;align-items:center;gap:12px}
.dash-burger{display:none;background:#f1f5f9;color:#0f172a;padding:8px 10px;border-radius:8px;font-size:16px;line-height:1}
.dash-top-right{display:flex;align-items:center;gap:16px}
.dash-user{display:flex;align-items:center;gap:8px;font-size:14px;color:#334155}
.dash-user svg{width:18px;height:18px;stroke:currentColor;fill:none;stroke-width:1.9;stroke-linecap:round;stroke-linejoin:round}
.dash-logout{background:#dc2626;color:#fff!important;padding:9px 16px;border-radius:8px;text-decoration:none;font-size:13px;font-weight:700;display:inline-flex;align-items:center;gap:8px}
.dash-logout svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.dash-content{padding:24px}
.dash-overlay{display:none}
@media(max-width:900px){
    .dash-side{transform:translateX(-100%)}
    .dash-side.open{transform:translateX(0)}
    .dash-main{margin-left:0}
    .dash-burger{display:inline-block}
    .dash-user span{display:none}
    .dash-content{padding:14px}
    .dash-overlay.open{display:block;position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:45}
}
@media print{.dash-side,.dash-top,.dash-overlay{display:none!important}.dash-main{margin:0!important;min-height:0!important}.dash-content{padding:0!important}}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body class="with-dash">
<aside class="dash-side no-print" id="dashSide">
    <div class="dash-brand">
        <div class="dash-brand-logo"><img src="logo_uin_transparan.png" alt="Logo UIN Datokarama"></div>
        <div><b>Perpustakaan</b><span>UIN Datokarama Palu</span></div>
    </div>
    <nav class="dash-nav">
        <a href="dashboard.php"><svg viewBox="0 0 24 24"><path d="M3 11l9-8 9 8"/><path d="M5 10v10h14V10"/></svg>Dashboard</a>
        <a href="buku.php"><svg viewBox="0 0 24 24"><path d="M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z"/></svg>Data Buku</a>
        <a href="anggota.php" class="active"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/></svg>Data Anggota</a>
        <a href="peminjaman.php"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Peminjaman</a>
        <a href="pengembalian.php"><svg viewBox="0 0 24 24"><path d="M6 4h12"/><path d="M6 9h12"/><path d="M6 9c6 0 9 2 9 5s-3 5-9 5l5 0"/></svg>Pengembalian</a>
        <a href="laporan.php"><svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6"/><path d="M8 13h8M8 17h5"/></svg>Cetak Laporan</a>
        <hr>
        <a href="katalog.php"><svg viewBox="0 0 24 24"><path d="M2 4h7a3 3 0 0 1 3 3v14a2 2 0 0 0-2-2H2z"/><path d="M22 4h-7a3 3 0 0 0-3 3v14a2 2 0 0 1 2-2h8z"/></svg>Lihat Katalog</a>
    </nav>
    <div class="dash-flower"><img src="logo_bunga.png" alt=""></div>
</aside>
<div class="dash-overlay no-print" id="dashOverlay" onclick="document.getElementById('dashSide').classList.remove('open');this.classList.remove('open');"></div>

<div class="dash-main">
    <header class="dash-top no-print">
        <div class="dash-top-left">
            <button type="button" class="dash-burger" aria-label="Menu" onclick="document.getElementById('dashSide').classList.toggle('open');document.getElementById('dashOverlay').classList.toggle('open');">&#9776;</button>
            <h1>Kelola KTA</h1>
        </div>
        <div class="dash-top-right">
            <div class="dash-user"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/></svg><span>Halo, Petugas Perpustakaan</span></div>
            <a class="dash-logout" href="logout.php"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Logout</a>
        </div>
    </header>
    <div class="dash-content">
<div class="page">
    <div class="top no-print">
        <button class="btn-print" type="button" onclick="window.print()">🖨️ Cetak Depan + Belakang / PDF</button>
        <a class="btn btn-back" href="anggota.php">← Kembali ke Data Anggota</a>
    </div>

    <?php if ($pesan !== ''): ?><div class="alert ok no-print"><?= kta_h($pesan); ?></div><?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert err no-print"><?= kta_h($error); ?></div><?php endif; ?>

    <div class="panel no-print">
        <h2>Kelola KTA 2 Sisi</h2>
        <div class="muted">Mahasiswa: <strong><?= kta_h($data['nim']); ?></strong> — <?= kta_h($data['nama_lengkap']); ?></div>

        <div class="grid" style="margin-top:14px">
            <div class="box">
                <h3>📷 Foto Mahasiswa</h3>
                <div class="muted">Foto hanya dipakai jika desain aktif memilih opsi tampilkan foto.</div>
                <form method="post" enctype="multipart/form-data">
                    <input type="file" name="foto_mahasiswa" accept="image/jpeg,image/png,image/webp" required>
                    <button class="btn btn-upload" name="upload_foto" type="submit">Simpan / Ganti Foto</button>
                </form>
                <?php if ($has_photo): ?>
                    <div style="margin-top:10px;display:flex;align-items:center;gap:10px">
                        <img src="<?= kta_h($foto_src); ?>?v=<?= filemtime($foto_path); ?>" alt="Foto mahasiswa" style="width:58px;height:76px;object-fit:cover;border-radius:5px;border:1px solid #cbd5e1">
                        <form method="post" onsubmit="return confirm('Hapus foto mahasiswa?');"><button class="btn btn-danger" name="delete_foto" type="submit">Hapus Foto</button></form>
                    </div>
                <?php else: ?>
                    <div class="muted" style="margin-top:8px">Belum ada foto. Jika foto kosong, kotak foto tidak akan ditampilkan dan data otomatis merapat ke kiri.</div>
                <?php endif; ?>
            </div>

            <div class="box">
                <h3>🎨 Desain Depan + Belakang</h3>
                <div class="muted">Upload dua gambar sebagai <b>background</b>. Sistem tidak mencetak gambar mahasiswa sebagai satu gambar tempelan; sistem menambahkan data mahasiswa, foto dan barcode di atas background.</div>
                <form method="post" enctype="multipart/form-data">
                    <input type="text" name="nama_desain" maxlength="80" placeholder="Nama desain, misalnya KTA 2026">
                    <label class="muted"><b>Background Depan</b></label>
                    <input type="file" name="desain_depan" accept="image/jpeg,image/png,image/webp" required>
                    <label class="muted"><b>Background Belakang</b></label>
                    <input type="file" name="desain_belakang" accept="image/jpeg,image/png,image/webp" required>
                    <label style="display:block;font-size:12px;margin:5px 0 10px"><input type="checkbox" name="show_photo" value="1" checked> Tampilkan foto mahasiswa pada sisi depan</label>
                    <button class="btn btn-upload" name="upload_desain" type="submit">Tambah & Aktifkan Desain</button>
                </form>
            </div>
        </div>

        <h3 style="margin-top:18px">Daftar Desain</h3>
        <div class="muted">Desain aktif dapat dihapus. Jika dihapus dan masih ada desain lain, desain pertama yang tersisa otomatis dipakai.</div>
        <?php if (count($designs) > 0): ?>
        <div class="designs">
            <?php foreach ($designs as $id => $d):
                $dir = $desain_dir . DIRECTORY_SEPARATOR . $id;
                $front_file = isset($d['front']) ? $d['front'] : '';
                $back_file = isset($d['back']) ? $d['back'] : '';
                if (!is_dir($dir) || $front_file === '' || $back_file === '' || !is_file($dir . DIRECTORY_SEPARATOR . basename($front_file)) || !is_file($dir . DIRECTORY_SEPARATOR . basename($back_file))) continue;
                $is_active = ($id === $active_id);
            ?>
            <div class="design">
                <div class="sides">
                    <img src="<?= kta_h(kta_image_url($desain_url, $id, $front_file)); ?>" alt="Depan">
                    <img src="<?= kta_h(kta_image_url($desain_url, $id, $back_file)); ?>" alt="Belakang">
                </div>
                <div class="design-name"><?= kta_h($d['name'] ?? $id); ?></div>
                <div class="design-meta">Foto: <?= !empty($d['show_photo']) ? 'Ya' : 'Tidak'; ?></div>
                <div class="design-actions">
                    <?php if ($is_active): ?><span class="badge">✓ Aktif</span><?php endif; ?>
                    <?php if (!$is_active): ?>
                        <form method="post"><input type="hidden" name="design_id" value="<?= kta_h($id); ?>"><button class="btn btn-use" name="set_design" type="submit">Gunakan</button></form>
                    <?php endif; ?>
                    <form method="post" onsubmit="return confirm('Hapus desain ini? Background depan dan belakang akan dihapus.');"><input type="hidden" name="design_id" value="<?= kta_h($id); ?>"><button class="btn btn-del" name="delete_design" type="submit">Hapus</button></form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="muted" style="margin-top:10px">Belum ada desain yang diupload.</div>
        <?php endif; ?>
    </div>

    <div class="panel no-print">
        <h3>✍️ Pengaturan Tulisan & Identitas KTA</h3>
        <div class="muted" style="margin-bottom:12px">Pengaturan ini berlaku untuk semua mahasiswa. Anda cukup mengatur sekali, lalu setiap tombol <b>Cetak KTA</b> akan memakai data mahasiswa yang sedang dipilih.</div>
        <div class="box" style="margin-bottom:12px">
            <h3 style="margin-bottom:6px">🏫 Logo Kampus</h3>
            <div class="muted">Logo dipakai sebagai watermark tipis di tengah kartu dan sebagai ikon kecil di tengah QR mahasiswa.</div>
            <form method="post" enctype="multipart/form-data" style="margin-top:8px">
                <input type="file" name="kampus_logo" accept="image/jpeg,image/png,image/webp" required>
                <button class="btn btn-upload" type="submit" name="upload_logo">Upload / Ganti Logo</button>
            </form>
            <?php if ($has_logo): ?>
                <div style="margin-top:10px;display:flex;align-items:center;gap:10px"><img src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo kampus" style="width:70px;height:70px;object-fit:contain;border:1px solid #e2e8f0;border-radius:6px;background:#fff;padding:4px"><form method="post" onsubmit="return confirm('Hapus logo kampus?');"><button class="btn btn-danger" type="submit" name="delete_logo">Hapus Logo</button></form></div>
            <?php endif; ?>
        </div>
        <form method="post">
            <div class="settings-grid">
                <div class="field"><label>Judul kartu bagian depan</label><input type="text" name="front_title" value="<?= kta_h($settings['front_title']); ?>"></div>
                <div class="field"><label>Nama institusi / subjudul</label><input type="text" name="front_subtitle" value="<?= kta_h($settings['front_subtitle']); ?>"></div>
                <div class="field"><label>Judul bagian belakang</label><input type="text" name="back_title" value="<?= kta_h($settings['back_title']); ?>"></div>
                <div class="field"><label>Nama pejabat penandatangan</label><input type="text" name="signature_name" value="<?= kta_h($settings['signature_name']); ?>"></div>
                <div class="field"><label>Jabatan penandatangan</label><input type="text" name="signature_jabatan" value="<?= kta_h($settings['signature_jabatan']); ?>"></div>
                <div class="field"><label>NIP pejabat penandatangan</label><input type="text" name="signature_nip" value="<?= kta_h($settings['signature_nip'] ?? ''); ?>"></div>
                                <div class="field" style="grid-column:1/-1"><label>Teks / kata-kata bagian belakang</label><textarea name="back_text"><?= kta_h($settings['back_text']); ?></textarea></div>
                                <div class="field" style="grid-column:1/-1"><label>Alamat publik untuk QR (opsional)</label><input type="text" name="public_base_url" value="<?= kta_h($settings['public_base_url'] ?? ''); ?>" placeholder="Contoh: http://192.168.1.2/perpustakaan_kampus/kta.php atau https://domain-anda.com/perpustakaan_kampus/kta.php"><div class="muted" style="margin-top:4px">Kosongkan agar sistem memilih alamat LAN otomatis. Isi ini jika ingin QR selalu menunjuk alamat tetap (mis. tunnel/domain) supaya bisa dipindai dari HP.</div></div>
            </div>
            <label style="display:block;font-size:12px;margin:10px 0"><input type="checkbox" name="show_signature" value="1" <?= !empty($settings['show_signature']) ? 'checked' : ''; ?>> Tampilkan tanda tangan pada bagian belakang</label>
            <button class="btn btn-save" type="submit" name="save_settings">💾 Simpan Pengaturan KTA</button><div class="muted" style="margin-top:8px">QR dibuat otomatis dan unik berdasarkan NIM. Agar dapat dipindai dari HP, isi Alamat publik di atas (mis. IP LAN komputer atau URL tunnel) lalu buka halaman KTA.</div>
        </form>
    </div>

    <div class="panel no-print">
        <h3>🖊️ Tanda Tangan Kepala Perpustakaan</h3>
        <div class="muted">Gambar tanda tangan menggunakan mouse, touchpad, atau layar sentuh. Tanda tangan disimpan sebagai PNG dan otomatis muncul di sisi belakang KTA saat dicetak.</div>
        <div class="signature-wrap" style="margin-top:10px">
            <canvas id="signaturePad" class="signature-pad" width="900" height="280"></canvas>
            <div class="signature-actions">
                <button type="button" class="btn btn-clear" id="clearSignature">Bersihkan</button>
                <button type="button" class="btn btn-save" id="saveSignature">Simpan Tanda Tangan</button>
            </div>
            <?php if ($has_signature): ?>
                <div class="muted" style="margin-top:10px">Tanda tangan saat ini:</div>
                <img class="signature-current" src="<?= kta_h($signature_url); ?>?v=<?= filemtime($signature_file); ?>" alt="Tanda tangan saat ini">
                <form method="post" style="margin-top:8px" onsubmit="return confirm('Hapus tanda tangan saat ini?');"><button class="btn btn-danger" type="submit" name="delete_signature">Hapus Tanda Tangan</button></form>
            <?php endif; ?>
            <form method="post" id="signatureForm" style="display:none">
                <input type="hidden" name="signature_data" id="signatureData">
                <button type="submit" name="save_signature" id="submitSignature">Simpan</button>
            </form>
        </div>
    </div>

    <div class="panel">
        <div class="preview-title no-print">Pratinjau — ukuran kartu 85,6 × 53,98 mm.</div>
        <?php if ($active): ?>
        <div class="cards no-print">
            <div class="card-wrap">
                <div class="side-label">DEPAN</div>
                <div class="kta-card">
                    <img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url, $active_id, $active['front'])); ?>" alt="Desain depan">
                    <?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo watermark"><img class="card-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo kampus"><?php endif; ?>
                    <div class="front-content">
                        <div class="header"><b><?= kta_h($settings['front_title']); ?></b><span><?= kta_h($settings['front_subtitle']); ?></span></div>
                        <div class="main">
                            <?php if ($has_photo && !empty($active['show_photo'])): ?><img class="photo" src="<?= kta_h($foto_src); ?>" alt="Foto">
                            <?php endif; ?>
                            <div class="info">
                                <div class="label">Nama</div><div class="value"><?= kta_h($data['nama_lengkap']); ?></div>
                                <div class="label">NIM</div><div class="value"><?= kta_h($data['nim']); ?></div>
                                <div class="label">Program Studi</div><div class="value"><?= kta_h($data['program_studi']); ?></div>
                            </div>
                        </div>
                        <div class="qr-box"><div class="qr-wrap"><img class="qr" src="<?= kta_h($qr_url); ?>" alt="QR KTA <?= kta_h($data['nim']); ?>"><?php if($has_logo): ?><img class="qr-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo"><?php endif; ?></div></div>
                    </div>
                </div>
            </div>

            <div class="card-wrap">
                <div class="side-label">BELAKANG</div>
                <div class="kta-card">
                    <img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url, $active_id, $active['back'])); ?>" alt="Desain belakang">
                    <?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo watermark"><?php endif; ?>
                    <div class="back-content">
                        <div class="back-title"><?= kta_h($settings['back_title']); ?></div>
                        <div class="back-text"><?= kta_h($settings['back_text']); ?></div>
                        <?php if ($has_signature): ?><div class="signature-block"><div class="signature-role"><?= kta_h($settings['signature_jabatan']); ?></div><img class="signature-img" src="<?= kta_h($signature_url); ?>?v=<?= filemtime($signature_file); ?>" alt="Tanda tangan"><div class="signature-line"><?= kta_h($settings['signature_name']); ?></div><?php if(!empty($settings['signature_nip'])): ?><div class="signature-nip">NIP. <?= kta_h($settings['signature_nip']); ?></div><?php endif; ?></div><?php endif; ?>
                        <div class="back-footer">NIM: <?= kta_h($data['nim']); ?> · <?= kta_h($data['nama_lengkap']); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Cetak: satu lembar A4 berisi DEPAN + BELAKANG. -->
        <div class="print-sheet">
            <div class="card-wrap">
                <div class="side-label">DEPAN</div>
                <div class="kta-card">
                    <img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url, $active_id, $active['front'])); ?>" alt="Depan">
                    <?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo watermark"><img class="card-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo kampus"><?php endif; ?>
                    <div class="front-content">
                        <div class="header"><b><?= kta_h($settings['front_title']); ?></b><span><?= kta_h($settings['front_subtitle']); ?></span></div>
                        <div class="main">
                            <?php if ($has_photo && !empty($active['show_photo'])): ?><img class="photo" src="<?= kta_h($foto_src); ?>" alt="Foto">
                            <?php endif; ?>
                            <div class="info"><div class="label">Nama</div><div class="value"><?= kta_h($data['nama_lengkap']); ?></div><div class="label">NIM</div><div class="value"><?= kta_h($data['nim']); ?></div><div class="label">Program Studi</div><div class="value"><?= kta_h($data['program_studi']); ?></div></div>
                        </div>
                        <div class="qr-box"><div class="qr-wrap"><img class="qr" src="<?= kta_h($qr_url); ?>" alt="QR KTA <?= kta_h($data['nim']); ?>"><?php if($has_logo): ?><img class="qr-logo" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo"><?php endif; ?></div></div>
                    </div>
                </div>
            </div>

            <div class="card-wrap">
                <div class="side-label">BELAKANG</div>
                <div class="kta-card">
                    <img class="kta-bg" src="<?= kta_h(kta_image_url($desain_url, $active_id, $active['back'])); ?>" alt="Belakang">
                    <?php if ($has_logo): ?><img class="watermark" src="<?= kta_h($logo_src); ?>?v=<?= filemtime($logo_path); ?>" alt="Logo watermark"><?php endif; ?>
                    <div class="back-content">
                        <div class="back-title"><?= kta_h($settings['back_title']); ?></div>
                        <div class="back-text"><?= kta_h($settings['back_text']); ?></div>
                        <?php if ($has_signature): ?><div class="signature-block"><div class="signature-role"><?= kta_h($settings['signature_jabatan']); ?></div><img class="signature-img" src="<?= kta_h($signature_url); ?>?v=<?= filemtime($signature_file); ?>" alt="Tanda tangan"><div class="signature-line"><?= kta_h($settings['signature_name']); ?></div><?php if(!empty($settings['signature_nip'])): ?><div class="signature-nip">NIP. <?= kta_h($settings['signature_nip']); ?></div><?php endif; ?></div><?php endif; ?>
                        <div class="back-footer">NIM: <?= kta_h($data['nim']); ?> · <?= kta_h($data['nama_lengkap']); ?></div>
                    </div>
                </div>
            </div>
        </div>
        <?php else: ?>
            <div class="muted">Belum ada desain aktif. Upload background depan dan belakang terlebih dahulu.</div>
        <?php endif; ?>
    </div>
</div>
    </div><!-- /.dash-content -->
</div><!-- /.dash-main -->

<script>
(function(){
    /* Kontras teks otomatis: membaca luminansi background tiap kartu. */
    function applyAutoContrast(card){
        var bg = card.querySelector('.kta-bg');
        if(!bg) return;
        function sample(){
            try{
                var c=document.createElement('canvas'), ctx=c.getContext('2d',{willReadFrequently:true});
                var w=64,h=40;c.width=w;c.height=h;ctx.drawImage(bg,0,0,w,h);
                var d=ctx.getImageData(0,0,w,h).data, total=0,count=0;
                for(var i=0;i<d.length;i+=4){
                    if(d[i+3]<10) continue;
                    total += (0.2126*d[i])+(0.7152*d[i+1])+(0.0722*d[i+2]); count++;
                }
                var lum=count?total/count:255;
                card.classList.toggle('theme-dark',lum<145);
                card.classList.toggle('theme-light',lum>=145);
            }catch(e){ card.classList.add('theme-light'); }
        }
        if(bg.complete) sample(); else bg.addEventListener('load',sample,{once:true});
    }
    document.querySelectorAll('.kta-card').forEach(applyAutoContrast);

    /* QR dibuat sebagai gambar URL otomatis sehingga tidak bergantung pada CDN JavaScript. */
    /* Tanda tangan online */
    var canvas = document.getElementById('signaturePad');
    if (!canvas) return;
    var ctx = canvas.getContext('2d');
    var drawing = false;
    var hasInk = false;
    ctx.lineWidth = 4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    ctx.strokeStyle = '#111827';

    function pos(e){
        var rect = canvas.getBoundingClientRect();
        var p = e.touches ? e.touches[0] : e;
        return {
            x:(p.clientX-rect.left)*(canvas.width/rect.width),
            y:(p.clientY-rect.top)*(canvas.height/rect.height)
        };
    }
    function start(e){e.preventDefault();drawing=true;hasInk=true;var p=pos(e);ctx.beginPath();ctx.moveTo(p.x,p.y);}
    function move(e){if(!drawing)return;e.preventDefault();var p=pos(e);ctx.lineTo(p.x,p.y);ctx.stroke();}
    function end(e){if(e)e.preventDefault();drawing=false;ctx.closePath();}

    canvas.addEventListener('mousedown',start); canvas.addEventListener('mousemove',move); window.addEventListener('mouseup',end);
    canvas.addEventListener('touchstart',start,{passive:false}); canvas.addEventListener('touchmove',move,{passive:false}); canvas.addEventListener('touchend',end,{passive:false});

    document.getElementById('clearSignature').addEventListener('click',function(){ctx.clearRect(0,0,canvas.width,canvas.height);hasInk=false;});
    document.getElementById('saveSignature').addEventListener('click',function(){
        if(!hasInk){alert('Silakan gambar tanda tangan terlebih dahulu.');return;}
        document.getElementById('signatureData').value=canvas.toDataURL('image/png');
        document.getElementById('submitSignature').click();
    });
})();
</script>
</body>
</html>
