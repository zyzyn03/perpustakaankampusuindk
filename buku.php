<?php
session_start();
require_once 'koneksi.php';

/* ================================================================
   KELOLA DATA MASTER BUKU
   - Tambah / edit buku
   - Import data massal CSV
   - Upload foto massal berdasarkan kode buku
   - Pasang foto ke buku tertentu
   - Pencarian
   - Hapus satu / banyak / berdasarkan kategori / stok
   - Tetap memakai struktur tb_buku yang sudah ada
   ================================================================ */

mysqli_set_charset($koneksi, 'utf8mb4');

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect_msg($message, $anchor = '') {
    // Flash message + redirect server-side: lebih stabil daripada alert + window.location.
    // Status 303 memastikan browser kembali ke buku.php tanpa mengulang POST.
    $_SESSION['buku_flash_message'] = (string)$message;
    $url = 'buku.php' . ($anchor !== '' ? '#' . rawurlencode($anchor) : '#daftar-buku');
    if (!headers_sent()) {
        header('Location: ' . $url, true, 303);
        exit;
    }
    echo '<script>window.location.replace(' . json_encode($url) . ');</script>';
    exit;
}

function upload_book_image($file, &$error = '') {
    $error = '';
    if (!isset($file) || !is_array($file)) {
        $error = 'File gambar tidak ditemukan.';
        return null;
    }
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $error = 'Upload gambar gagal.';
        return null;
    }
    if (($file['size'] ?? 0) > 5 * 1024 * 1024) {
        $error = 'Ukuran gambar maksimal 5 MB.';
        return null;
    }

    $allowed = ['jpg', 'jpeg', 'png', 'webp'];
    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        $error = 'Format gambar harus JPG, JPEG, PNG, atau WEBP.';
        return null;
    }

    if (!is_dir(__DIR__ . '/uploads')) {
        @mkdir(__DIR__ . '/uploads', 0777, true);
    }

    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
    if ($finfo) {
        $mime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        $allowed_mime = ['image/jpeg', 'image/png', 'image/webp'];
        if (!in_array($mime, $allowed_mime, true)) {
            $error = 'File bukan gambar yang valid.';
            return null;
        }
    }

    $new_name = 'buku_' . date('Ymd_His') . '_' . bin2hex(random_bytes(5)) . '.' . $ext;
    $destination = __DIR__ . '/uploads/' . $new_name;
    if (!move_uploaded_file($file['tmp_name'], $destination)) {
        $error = 'Gambar gagal disimpan ke folder uploads.';
        return null;
    }
    return $new_name;
}

function remove_book_image($filename) {
    $filename = trim((string)$filename);
    if ($filename === '' || $filename === 'default.png') return;
    $path = __DIR__ . '/uploads/' . basename($filename);
    if (is_file($path)) @unlink($path);
}

function get_book_by_code($koneksi, $kode) {
    $stmt = mysqli_prepare($koneksi, "SELECT * FROM tb_buku WHERE kode_buku = ? LIMIT 1");
    if (!$stmt) return null;
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return $row;
}

function book_has_active_loan($koneksi, $kode) {
    $stmt = mysqli_prepare($koneksi, "SELECT id_transaksi FROM tb_peminjaman WHERE kode_buku = ? AND status_pinjam = 'Dipinjam' LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function get_active_loan_count($koneksi, $kode) {
    $stmt = mysqli_prepare($koneksi, "SELECT COUNT(*) AS total FROM tb_peminjaman WHERE kode_buku = ? AND status_pinjam = 'Dipinjam'");
    if (!$stmt) return 0;
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);
    return (int)($row['total'] ?? 0);
}

function book_has_transaction($koneksi, $kode) {
    $stmt = mysqli_prepare($koneksi, "SELECT id_transaksi FROM tb_peminjaman WHERE kode_buku = ? LIMIT 1");
    if (!$stmt) return false;
    mysqli_stmt_bind_param($stmt, 's', $kode);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $exists = $result && mysqli_num_rows($result) > 0;
    mysqli_stmt_close($stmt);
    return $exists;
}

function delete_book_safely($koneksi, $kode) {
    $book = get_book_by_code($koneksi, $kode);
    if (!$book) return ['ok' => false, 'message' => 'Buku ' . $kode . ' tidak ditemukan.'];

    // Buku yang masih sedang dipinjam wajib dipertahankan sampai dikembalikan.
    $dipinjam = get_active_loan_count($koneksi, $kode);
    if ($dipinjam > 0) {
        return ['ok' => false, 'message' => 'Buku ' . $kode . ' tidak dapat dihapus karena masih dipinjam sebanyak ' . $dipinjam . ' buku. Silakan proses pengembalian terlebih dahulu.'];
    }

    // Jika tidak sedang dipinjam, buku boleh dihapus.
    // Riwayat transaksi yang sudah selesai tidak lagi menjadi penghalang
    // penghapusan; yang wajib dipertahankan hanya buku yang masih aktif dipinjam.

    $stmt = mysqli_prepare($koneksi, "DELETE FROM tb_buku WHERE kode_buku = ? LIMIT 1");
    if (!$stmt) return ['ok' => false, 'message' => 'Gagal menyiapkan penghapusan buku ' . $kode . '.'];
    mysqli_stmt_bind_param($stmt, 's', $kode);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    if ($ok) {
        remove_book_image($book['gambar'] ?? '');
        return ['ok' => true, 'message' => 'Buku ' . $kode . ' berhasil dihapus.'];
    }
    return ['ok' => false, 'message' => 'Buku ' . $kode . ' gagal dihapus: ' . mysqli_error($koneksi)];
}

function delete_all_categories_safely($koneksi) {
    $result = ['deleted' => 0, 'skipped' => [], 'failed' => []];
    $res = mysqli_query($koneksi, "SELECT id_kategori, nama_kategori FROM tb_kategori ORDER BY id_kategori ASC");
    if (!$res) {
        $result['failed'][] = 'Daftar kategori tidak dapat diperiksa: ' . mysqli_error($koneksi);
        return $result;
    }

    while ($kat = mysqli_fetch_assoc($res)) {
        $id_kategori = (int)$kat['id_kategori'];
        $nama_kategori = (string)$kat['nama_kategori'];
        $stmt = mysqli_prepare($koneksi, "SELECT kode_buku, judul_buku, gambar FROM tb_buku WHERE id_kategori = ? ORDER BY kode_buku ASC");
        if (!$stmt) {
            $result['failed'][] = "Kategori '$nama_kategori' gagal diproses.";
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
        mysqli_stmt_execute($stmt);
        $books_res = mysqli_stmt_get_result($stmt);
        $books = [];
        while ($book = mysqli_fetch_assoc($books_res)) $books[] = $book;
        mysqli_stmt_close($stmt);

        $protected = [];
        foreach ($books as $book) {
            if (book_has_transaction($koneksi, $book['kode_buku'])) {
                $protected[] = $book['kode_buku'] . '  -  ' . $book['judul_buku'];
            }
        }

        if ($protected) {
            $result['skipped'][] = "$nama_kategori (memiliki buku dengan riwayat transaksi: " . implode(', ', array_slice($protected, 0, 3)) . (count($protected) > 3 ? ', ...' : '') . ')';
            continue;
        }

        mysqli_begin_transaction($koneksi);
        $ok = true;
        $stmt = mysqli_prepare($koneksi, "DELETE FROM tb_buku WHERE id_kategori = ?");
        if (!$stmt) {
            $ok = false;
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
            $ok = mysqli_stmt_execute($stmt);
            mysqli_stmt_close($stmt);
        }

        if ($ok) {
            $stmt = mysqli_prepare($koneksi, "DELETE FROM tb_kategori WHERE id_kategori = ? LIMIT 1");
            if (!$stmt) {
                $ok = false;
            } else {
                mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
                $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
                mysqli_stmt_close($stmt);
            }
        }

        if ($ok) {
            mysqli_commit($koneksi);
            foreach ($books as $book) remove_book_image($book['gambar'] ?? '');
            $result['deleted']++;
        } else {
            mysqli_rollback($koneksi);
            $result['failed'][] = "Kategori '$nama_kategori' gagal dihapus.";
        }
    }
    mysqli_free_result($res);
    return $result;
}

// ------------------------------------------------------------------
// 1. Pastikan kolom pendukung yang memang sudah dipakai sistem ada.
// ------------------------------------------------------------------
mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS tb_kategori (
    id_kategori INT AUTO_INCREMENT PRIMARY KEY,
    nama_kategori VARCHAR(100) NOT NULL
)");

$cols_result = mysqli_query($koneksi, "SHOW COLUMNS FROM tb_buku");
$existing_cols = [];
if ($cols_result) {
    while ($col = mysqli_fetch_assoc($cols_result)) $existing_cols[] = $col['Field'];
}

if (!in_array('lokasi_rak', $existing_cols, true)) {
    @mysqli_query($koneksi, "ALTER TABLE tb_buku ADD lokasi_rak VARCHAR(100) DEFAULT 'Rak Umum'");
}
if (!in_array('gambar', $existing_cols, true)) {
    @mysqli_query($koneksi, "ALTER TABLE tb_buku ADD gambar VARCHAR(255) DEFAULT NULL");
}

// Refresh kolom setelah ALTER.
$cols_result = mysqli_query($koneksi, "SHOW COLUMNS FROM tb_buku");
$existing_cols = [];
if ($cols_result) {
    while ($col = mysqli_fetch_assoc($cols_result)) $existing_cols[] = $col['Field'];
}

// ------------------------------------------------------------------
// 2. Download template CSV.
// ------------------------------------------------------------------
if (isset($_GET['aksi']) && $_GET['aksi'] === 'template_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="template_import_buku.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['kode_buku','kategori','judul_buku','pengarang','penerbit','tahun_terbit','stok','lokasi_rak','gambar'], ';');
    fputcsv($out, ['BK-007','literasi','Contoh Judul','Nama Pengarang','Nama Penerbit','2026','10','Rak Umum','BK-007.jpg'], ';');
    fclose($out);
    exit;
}

// ------------------------------------------------------------------
// 2b. Cetak label & barcode buku (satuan / massal) + ekspor CSV.
// ------------------------------------------------------------------
function buku_label_rows($koneksi, $where_sql = '') {
    $sql = "SELECT tb_buku.kode_buku, tb_buku.judul_buku, tb_buku.pengarang, tb_buku.lokasi_rak,
                   tb_kategori.nama_kategori
            FROM tb_buku
            LEFT JOIN tb_kategori ON tb_buku.id_kategori = tb_kategori.id_kategori";
    if ($where_sql !== '') $sql .= " WHERE " . $where_sql;
    $sql .= " ORDER BY tb_buku.kode_buku ASC";
    $rows = [];
    if ($res = mysqli_query($koneksi, $sql)) {
        while ($r = mysqli_fetch_assoc($res)) $rows[] = $r;
    }
    return $rows;
}

if (isset($_GET['aksi']) && ($_GET['aksi'] === 'barcode' || $_GET['aksi'] === 'cetak_barcode')) {
    $rows = [];
    if ($_GET['aksi'] === 'barcode' && isset($_GET['id'])) {
        $kode = mysqli_real_escape_string($koneksi, trim((string)$_GET['id']));
        $rows = buku_label_rows($koneksi, "tb_buku.kode_buku = '$kode'");
    } else {
        $clauses = [];
        if (!empty($_GET['kat'])) $clauses[] = "tb_buku.id_kategori = '" . (int)$_GET['kat'] . "'";
        if (!empty($_GET['rak'])) $clauses[] = "tb_buku.lokasi_rak = '" . mysqli_real_escape_string($koneksi, trim((string)$_GET['rak'])) . "'";
        $rows = buku_label_rows($koneksi, implode(' AND ', $clauses));
    }
    ?><!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8">
<title>Label &amp; Barcode Buku &mdash; Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:"Plus Jakarta Sans","Segoe UI",Arial,sans-serif;background:#eef2f1;color:#0f172a;padding:24px}
.bar{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:18px;flex-wrap:wrap}
.bar h1{font-size:20px;font-weight:800;color:#064c40}
.bar .btn{border:0;border-radius:8px;padding:10px 18px;font:inherit;font-size:13px;font-weight:600;cursor:pointer;text-decoration:none;background:#064c40;color:#fff;display:inline-flex;align-items:center;gap:7px}
.bar .btn.ghost{background:#fff;color:#475569;border:1px solid #cbd5e1}
.labels{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
.label{background:#fff;border:1px solid #d7e2df;border-radius:10px;padding:14px 14px 10px;display:flex;flex-direction:column;gap:8px;break-inside:avoid}
.label .kop{display:flex;align-items:center;gap:8px}
.label .kop img{width:26px;height:26px;object-fit:contain}
.label .kop b{font-size:11px;color:#064c40;line-height:1.15}
.label .kop small{display:block;font-size:9px;color:#64748b}
.label .bc{display:flex;justify-content:center;padding:4px 0}
.label .bc img{height:64px;max-width:100%}
.label .kode{text-align:center;font-family:ui-monospace,Consolas,monospace;font-weight:700;font-size:13px;letter-spacing:1px}
.label .info{font-size:10.5px;color:#334155;line-height:1.35;border-top:1px dashed #cbd5e1;padding-top:6px}
.label .info b{display:block;font-size:11px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.empty{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:30px;text-align:center;color:#94a3b8}
@media print{
  body{background:#fff;padding:0}
  .bar{display:none}
  .labels{grid-template-columns:repeat(3,1fr);gap:8px}
  .label{border:1px dashed #999;border-radius:6px}
}
</style></head>
<body>
<div class="bar">
  <h1>Label &amp; Barcode Buku (<?= count($rows); ?>)</h1>
  <div>
    <a class="btn ghost" href="buku.php">&larr; Kembali</a>
    <button class="btn" onclick="window.print()">Cetak / Simpan PDF</button>
  </div>
</div>
<?php if (empty($rows)): ?>
  <div class="empty">Tidak ada buku pada filter ini.</div>
<?php else: ?>
  <div class="labels">
  <?php foreach ($rows as $r):
      $kode = (string)$r['kode_buku'];
      $barcode_src = 'barcode.php?data=' . rawurlencode($kode) . '&h=64&nw=2';
  ?>
    <div class="label">
      <div class="kop">
        <img src="assets/logo-uin.png" alt="UIN">
        <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
      </div>
      <div class="bc"><img src="<?= htmlspecialchars($barcode_src); ?>" alt="barcode <?= htmlspecialchars($kode); ?>"></div>
      <div class="kode"><?= htmlspecialchars($kode); ?></div>
      <div class="info"><b><?= htmlspecialchars($r['judul_buku'] ?? '-'); ?></b><?= htmlspecialchars($r['pengarang'] ?? '-'); ?> &bull; <?= htmlspecialchars($r['nama_kategori'] ?? 'Umum'); ?> &bull; <?= htmlspecialchars($r['lokasi_rak'] ?? 'Rak Umum'); ?></div>
    </div>
  <?php endforeach; ?>
  </div>
<?php endif; ?>
</body></html>
<?php
    exit;
}

if (isset($_GET['aksi']) && $_GET['aksi'] === 'export_excel') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="data_buku_' . date('Ymd_His') . '.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['kode_buku','judul_buku','pengarang','penerbit','tahun_terbit','kategori','lokasi_rak','stok'], ';');
    if ($res = mysqli_query($koneksi, "SELECT tb_buku.*, tb_kategori.nama_kategori FROM tb_buku LEFT JOIN tb_kategori ON tb_buku.id_kategori=tb_kategori.id_kategori ORDER BY tb_buku.kode_buku ASC")) {
        while ($r = mysqli_fetch_assoc($res)) {
            fputcsv($out, [
                $r['kode_buku'] ?? '', $r['judul_buku'] ?? '', $r['pengarang'] ?? '',
                $r['penerbit'] ?? '', $r['tahun_terbit'] ?? '', $r['nama_kategori'] ?? '',
                $r['lokasi_rak'] ?? '', $r['stok'] ?? 0
            ], ';');
        }
    }
    fclose($out);
    exit;
}

// ------------------------------------------------------------------
// 3. Tambah / hapus kategori.
// ------------------------------------------------------------------
if (isset($_POST['tambah_kategori'])) {
    $nama = trim($_POST['nama_kategori_baru'] ?? '');
    if ($nama === '') redirect_msg('Nama kategori tidak boleh kosong.');

    $stmt = mysqli_prepare($koneksi, "SELECT id_kategori FROM tb_kategori WHERE nama_kategori = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $nama);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $exists = $res && mysqli_num_rows($res) > 0;
    mysqli_stmt_close($stmt);

    if ($exists) redirect_msg('Kategori tersebut sudah ada.');

    $stmt = mysqli_prepare($koneksi, "INSERT INTO tb_kategori (nama_kategori) VALUES (?)");
    mysqli_stmt_bind_param($stmt, 's', $nama);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    redirect_msg($ok ? 'Kategori berhasil ditambahkan.' : 'Kategori gagal ditambahkan.');
}

if (isset($_POST['hapus_semua_kategori'])) {
    $hasil = delete_all_categories_safely($koneksi);
    if ($hasil['deleted'] === 0 && !$hasil['skipped'] && !$hasil['failed']) {
        redirect_msg('Belum ada kategori yang dapat diproses.');
    }

    $pesan = 'Proses semua kategori selesai. Kategori terhapus: ' . $hasil['deleted'] . '.';
    if ($hasil['skipped']) {
        $pesan .= "\nKategori yang dipertahankan karena memiliki riwayat transaksi:\n-  " . implode("\n-  ", array_slice($hasil['skipped'], 0, 8));
        if (count($hasil['skipped']) > 8) $pesan .= "\n-  ... dan " . (count($hasil['skipped']) - 8) . ' kategori lainnya.';
    }
    if ($hasil['failed']) {
        $pesan .= "\nKategori yang gagal diproses:\n-  " . implode("\n-  ", array_slice($hasil['failed'], 0, 8));
    }
    redirect_msg($pesan, 'tambah-buku');
}

if (isset($_POST['hapus_kategori'])) {
    $id_kategori = (int)($_POST['id_kategori_hapus'] ?? 0);
    if ($id_kategori <= 0) redirect_msg('Kategori belum dipilih.');

    $stmt = mysqli_prepare($koneksi, "SELECT nama_kategori FROM tb_kategori WHERE id_kategori = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $kat = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);
    if (!$kat) redirect_msg('Kategori tidak ditemukan.');

    // Saat kategori dihapus, semua buku yang memakai kategori tersebut ikut dihapus.
    // Namun buku yang sudah mempunyai riwayat peminjaman tidak boleh dihapus agar
    // riwayat transaksi tetap aman. Jika ada buku seperti itu, seluruh proses
    // penghapusan kategori dibatalkan.
    $stmt = mysqli_prepare($koneksi, "SELECT kode_buku, judul_buku FROM tb_buku WHERE id_kategori = ? ORDER BY kode_buku ASC");
    if (!$stmt) redirect_msg('Data buku kategori belum dapat diperiksa.');
    mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $category_books = [];
    while ($row = mysqli_fetch_assoc($res)) {
        $category_books[] = $row;
    }
    mysqli_stmt_close($stmt);

    if ($category_books) {
        $riwayat = [];
        foreach ($category_books as $book_row) {
            if (book_has_transaction($koneksi, $book_row['kode_buku'])) {
                $riwayat[] = $book_row['kode_buku'] . '  -  ' . $book_row['judul_buku'];
            }
        }

        if ($riwayat) {
            $detail = implode("\n", array_slice($riwayat, 0, 8));
            $lebih = count($riwayat) > 8 ? "\n... dan " . (count($riwayat) - 8) . " buku lainnya." : '';
            redirect_msg("Kategori '{$kat['nama_kategori']}' tidak dapat dihapus karena ada buku yang memiliki riwayat peminjaman.\n\nBuku tersebut tetap dipertahankan agar riwayat transaksi tidak rusak:\n$detail$lebih\n\nHapus/pindahkan buku tersebut terlebih dahulu jika memang diperlukan.");
        }
    }

    mysqli_begin_transaction($koneksi);
    $ok = true;
    $hapus_gambar = [];

    if ($category_books) {
        foreach ($category_books as $book_row) {
            $kode_buku = $book_row['kode_buku'];
            $stmt = mysqli_prepare($koneksi, "DELETE FROM tb_buku WHERE kode_buku = ? AND id_kategori = ? LIMIT 1");
            if (!$stmt) { $ok = false; break; }
            mysqli_stmt_bind_param($stmt, 'si', $kode_buku, $id_kategori);
            if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) $ok = false;
            mysqli_stmt_close($stmt);
            if (!$ok) break;

            $hapus_gambar[] = $book_row['kode_buku'];
        }
    }

    if ($ok) {
        $stmt = mysqli_prepare($koneksi, "DELETE FROM tb_kategori WHERE id_kategori = ? LIMIT 1");
        if (!$stmt) {
            $ok = false;
        } else {
            mysqli_stmt_bind_param($stmt, 'i', $id_kategori);
            $ok = mysqli_stmt_execute($stmt) && mysqli_stmt_affected_rows($stmt) === 1;
            mysqli_stmt_close($stmt);
        }
    }

    if (!$ok) {
        mysqli_rollback($koneksi);
        redirect_msg('Kategori dan buku di dalamnya gagal dihapus. Tidak ada data yang diubah.');
    }

    mysqli_commit($koneksi);

    // Hapus file sampul setelah transaksi database berhasil.
    foreach ($category_books as $book_row) {
        remove_book_image($book_row['gambar'] ?? '');
    }

    $total_buku = count($category_books);
    redirect_msg($total_buku > 0
        ? "Kategori '{$kat['nama_kategori']}' dan $total_buku buku di dalamnya berhasil dihapus."
        : "Kategori '{$kat['nama_kategori']}' berhasil dihapus.");
}

// ------------------------------------------------------------------
// 4. Tambah buku satuan.
// ------------------------------------------------------------------
if (isset($_POST['tambah_buku'])) {
    $kode = trim($_POST['kode_buku'] ?? '');
    $id_kategori = (int)($_POST['id_kategori'] ?? 0);
    $judul = trim($_POST['judul_buku'] ?? '');
    $pengarang = trim($_POST['pengarang'] ?? '');
    $penerbit = trim($_POST['penerbit'] ?? '');
    $tahun = (int)($_POST['tahun_terbit'] ?? 0);
    $stok = (int)($_POST['stok'] ?? 0);
    $rak = trim($_POST['lokasi_rak'] ?? 'Rak Umum');

    if ($kode === '' || $judul === '' || $id_kategori <= 0) redirect_msg('Kode, kategori, dan judul buku wajib diisi.');
    if ($stok < 1) redirect_msg('Stok buku minimal 1 dan tidak boleh kosong.');
    if (get_book_by_code($koneksi, $kode)) redirect_msg("Kode buku $kode sudah terdaftar.");

    $gambar = null;
    if (isset($_FILES['gambar']) && ($_FILES['gambar']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload_error = '';
        $gambar = upload_book_image($_FILES['gambar'], $upload_error);
        if ($gambar === null) redirect_msg($upload_error);
    }

    $fields = ['kode_buku','id_kategori','judul_buku','pengarang','penerbit'];
    $values = [$kode,$id_kategori,$judul,$pengarang,$penerbit];
    $types = 'sisss';

    if (in_array('tahun_terbit', $existing_cols, true)) { $fields[]='tahun_terbit'; $values[]=$tahun; $types.='i'; }
    if (in_array('stok', $existing_cols, true)) { $fields[]='stok'; $values[]=$stok; $types.='i'; }
    if (in_array('lokasi_rak', $existing_cols, true)) { $fields[]='lokasi_rak'; $values[]=$rak; $types.='s'; }
    if (in_array('gambar', $existing_cols, true)) { $fields[]='gambar'; $values[]=$gambar; $types.='s'; }
    if (in_array('kategori', $existing_cols, true)) {
        $stmtk = mysqli_prepare($koneksi, "SELECT nama_kategori FROM tb_kategori WHERE id_kategori=? LIMIT 1");
        mysqli_stmt_bind_param($stmtk, 'i', $id_kategori);
        mysqli_stmt_execute($stmtk);
        $rk = mysqli_stmt_get_result($stmtk);
        $nk = $rk ? (mysqli_fetch_assoc($rk)['nama_kategori'] ?? '-') : '-';
        mysqli_stmt_close($stmtk);
        $fields[]='kategori'; $values[]=$nk; $types.='s';
    }

    $placeholders = implode(',', array_fill(0, count($fields), '?'));
    $sql = "INSERT INTO tb_buku (" . implode(',', array_map(fn($f) => "`$f`", $fields)) . ") VALUES ($placeholders)";
    $stmt = mysqli_prepare($koneksi, $sql);
    if (!$stmt) redirect_msg('Gagal menyiapkan data buku: ' . mysqli_error($koneksi));
    mysqli_stmt_bind_param($stmt, $types, ...$values);
    $ok = mysqli_stmt_execute($stmt);
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) {
        if ($gambar) remove_book_image($gambar);
        redirect_msg('Gagal menambah buku: ' . $err);
    }
    redirect_msg('Buku berhasil ditambahkan.');
}

// ------------------------------------------------------------------
// 5. Edit buku: judul, pengarang, penerbit, tahun, stok, rak, kategori.
// ------------------------------------------------------------------
if (isset($_POST['edit_buku'])) {
    $kode_lama = trim($_POST['kode_buku_lama'] ?? '');
    $kode_baru = trim($_POST['kode_buku'] ?? '');
    $id_kategori = (int)($_POST['id_kategori'] ?? 0);
    $judul = trim($_POST['judul_buku'] ?? '');
    $pengarang = trim($_POST['pengarang'] ?? '');
    $penerbit = trim($_POST['penerbit'] ?? '');
    $tahun = (int)($_POST['tahun_terbit'] ?? 0);
    $stok = (int)($_POST['stok'] ?? 0);
    $rak = trim($_POST['lokasi_rak'] ?? 'Rak Umum');

    $old = get_book_by_code($koneksi, $kode_lama);
    if (!$old) redirect_msg('Data buku yang akan diedit tidak ditemukan.');
    if ($kode_baru === '' || $judul === '' || $id_kategori <= 0) redirect_msg('Kode, kategori, dan judul wajib diisi.');
    if ($stok < 1) redirect_msg('Stok buku minimal 1 dan tidak boleh kosong.');
    if ($kode_baru !== $kode_lama && get_book_by_code($koneksi, $kode_baru)) redirect_msg("Kode baru $kode_baru sudah dipakai buku lain.");

    // Stok total tidak boleh lebih kecil dari jumlah buku yang masih dipinjam.
    // Jika 1 buku masih dipinjam, stok minimal harus 1; jika 3 dipinjam, minimal 3.
    $jumlah_dipinjam_edit = get_active_loan_count($koneksi, $kode_lama);
    if ($stok < $jumlah_dipinjam_edit) {
        redirect_msg("Stok {$kode_lama} tidak dapat diubah menjadi {$stok} unit karena masih ada {$jumlah_dipinjam_edit} buku yang sedang dipinjam. Stok minimum yang diperbolehkan adalah {$jumlah_dipinjam_edit} unit.");
    }

    $gambar_baru = $old['gambar'] ?? null;
    if (isset($_FILES['gambar_edit']) && ($_FILES['gambar_edit']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $upload_error = '';
        $uploaded = upload_book_image($_FILES['gambar_edit'], $upload_error);
        if ($uploaded === null) redirect_msg($upload_error);
        $gambar_baru = $uploaded;
    }

    $nama_kat = '-';
    $stmtk = mysqli_prepare($koneksi, "SELECT nama_kategori FROM tb_kategori WHERE id_kategori=? LIMIT 1");
    mysqli_stmt_bind_param($stmtk, 'i', $id_kategori);
    mysqli_stmt_execute($stmtk);
    $rk = mysqli_stmt_get_result($stmtk);
    if ($rk) $nama_kat = mysqli_fetch_assoc($rk)['nama_kategori'] ?? '-';
    mysqli_stmt_close($stmtk);

    $stmt = mysqli_prepare($koneksi, "UPDATE tb_buku SET kode_buku=?, id_kategori=?, judul_buku=?, pengarang=?, penerbit=?, tahun_terbit=?, stok=?, lokasi_rak=?, kategori=?, gambar=? WHERE kode_buku=? LIMIT 1");
    if (!$stmt) redirect_msg('Gagal menyiapkan perubahan buku: ' . mysqli_error($koneksi));
    mysqli_stmt_bind_param($stmt, 'sisssiissss', $kode_baru, $id_kategori, $judul, $pengarang, $penerbit, $tahun, $stok, $rak, $nama_kat, $gambar_baru, $kode_lama);
    $ok = mysqli_stmt_execute($stmt);
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) {
        if ($gambar_baru !== ($old['gambar'] ?? null)) remove_book_image($gambar_baru);
        redirect_msg('Gagal mengedit buku: ' . $err);
    }
    if ($gambar_baru !== ($old['gambar'] ?? null)) remove_book_image($old['gambar'] ?? '');
    redirect_msg('Data buku berhasil diperbarui.');
}

// ------------------------------------------------------------------
// 6. Upload foto massal. Nama file harus sama dengan kode buku,
//    contoh: BK-002.jpg, BK-003.png, dst.
// ------------------------------------------------------------------
if (isset($_POST['upload_foto_massal'])) {
    $files = $_FILES['foto_massal'] ?? null;
    if (!$files || !isset($files['name']) || !is_array($files['name'])) redirect_msg('Belum ada foto yang dipilih.');

    $berhasil = 0; $gagal = 0; $pesan_gagal = [];
    $total = count($files['name']);
    for ($i=0; $i<$total; $i++) {
        if (($files['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $original = $files['name'][$i];
        $kode = strtoupper(trim(pathinfo($original, PATHINFO_FILENAME)));
        if ($kode === '') { $gagal++; $pesan_gagal[] = "$original: nama file harus berupa kode buku"; continue; }
        $book = get_book_by_code($koneksi, $kode);
        if (!$book) { $gagal++; $pesan_gagal[] = "$original: kode $kode tidak ditemukan"; continue; }

        $file = [
            'name'=>$files['name'][$i], 'type'=>$files['type'][$i], 'tmp_name'=>$files['tmp_name'][$i],
            'error'=>$files['error'][$i], 'size'=>$files['size'][$i]
        ];
        $upload_error='';
        $new_image = upload_book_image($file, $upload_error);
        if (!$new_image) { $gagal++; $pesan_gagal[] = "$original: $upload_error"; continue; }

        $stmt = mysqli_prepare($koneksi, "UPDATE tb_buku SET gambar=? WHERE kode_buku=? LIMIT 1");
        mysqli_stmt_bind_param($stmt, 'ss', $new_image, $kode);
        $ok = mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
        if ($ok) {
            remove_book_image($book['gambar'] ?? '');
            $berhasil++;
        } else {
            remove_book_image($new_image);
            $gagal++; $pesan_gagal[] = "$original: gagal menyimpan ke database";
        }
    }

    $msg = "Upload foto massal selesai. Berhasil: $berhasil, gagal: $gagal.";
    if ($pesan_gagal) $msg .= "

" . implode("
", array_slice($pesan_gagal, 0, 10));
    redirect_msg($msg);
}

// ------------------------------------------------------------------
// 7. Upload satu foto ke buku yang dipilih.
// ------------------------------------------------------------------
if (isset($_POST['pasang_foto'])) {
    $kode = trim($_POST['kode_foto'] ?? '');
    $book = get_book_by_code($koneksi, $kode);
    if (!$book) redirect_msg('Buku yang dipilih tidak ditemukan.');
    if (!isset($_FILES['foto_pilihan']) || ($_FILES['foto_pilihan']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) redirect_msg('Pilih foto terlebih dahulu.');

    $upload_error='';
    $new_image = upload_book_image($_FILES['foto_pilihan'], $upload_error);
    if (!$new_image) redirect_msg($upload_error);
    $stmt = mysqli_prepare($koneksi, "UPDATE tb_buku SET gambar=? WHERE kode_buku=? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 'ss', $new_image, $kode);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
    if (!$ok) { remove_book_image($new_image); redirect_msg('Foto gagal disimpan.'); }
    remove_book_image($book['gambar'] ?? '');
    redirect_msg("Foto untuk buku $kode berhasil dipasang.");
}

// ------------------------------------------------------------------
// 8. Import data massal CSV / Excel (XLSX).
// ------------------------------------------------------------------
function read_xlsx_rows($file) {
    if (!class_exists('ZipArchive')) {
        throw new Exception('Server PHP belum mengaktifkan ZipArchive. Aktifkan ekstensi ZIP untuk membaca Excel (.xlsx).');
    }
    if (!function_exists('simplexml_load_string')) {
        throw new Exception('Server PHP belum mengaktifkan SimpleXML. Aktifkan ekstensi SimpleXML untuk membaca Excel (.xlsx).');
    }

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) {
        throw new Exception('File Excel (.xlsx) tidak dapat dibuka. Pastikan file benar-benar format .xlsx.');
    }

    // XLSX dari WPS/Excel dapat memakai namespace XML default tanpa prefix "a".
    // Karena itu parser menggunakan local-name() agar tidak bergantung pada prefix namespace.
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx !== false) {
            foreach ($sx->xpath('//*[local-name()="si"]') ?: [] as $si) {
                $text = '';
                foreach ($si->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                    $text .= (string)$part;
                }
                $shared[] = $text;
            }
        }
    }

    // Ambil worksheet pertama yang benar-benar ada di dalam workbook.
    $sheetXml = false;
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('~^xl/worksheets/sheet[0-9]+\.xml$~', $name)) {
            $sheetXml = $zip->getFromName($name);
            if ($sheetXml !== false) break;
        }
    }
    if ($sheetXml === false) {
        $zip->close();
        throw new Exception('Worksheet Excel tidak ditemukan.');
    }

    $sx = @simplexml_load_string($sheetXml);
    if ($sx === false) {
        $zip->close();
        throw new Exception('Struktur Excel (.xlsx) tidak valid.');
    }

    $rows = [];
    $rowNodes = $sx->xpath('//*[local-name()="sheetData"]/*[local-name()="row"]') ?: [];
    foreach ($rowNodes as $rowNode) {
        $cells = [];
        $cellNodes = $rowNode->xpath('./*[local-name()="c"]') ?: [];

        foreach ($cellNodes as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)[0-9]+/', strtoupper($ref), $m);
            $col = 0;
            if (!empty($m[1])) {
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $col--;
            }

            $type = (string)$cell['t'];
            $value = '';

            if ($type === 'inlineStr') {
                foreach ($cell->xpath('.//*[local-name()="t"]') ?: [] as $part) {
                    $value .= (string)$part;
                }
            } else {
                $v = $cell->xpath('./*[local-name()="v"]');
                $value = $v ? (string)$v[0] : '';

                if ($type === 's' && $value !== '' && isset($shared[(int)$value])) {
                    $value = $shared[(int)$value];
                } elseif ($type === 'b') {
                    $value = ($value === '1') ? '1' : '0';
                }
            }

            $cells[$col] = $value;
        }

        if (!$cells) continue;
        $max = max(array_keys($cells));
        $out = array_fill(0, $max + 1, '');
        foreach ($cells as $idx => $value) $out[$idx] = $value;
        $rows[] = $out;
    }

    $zip->close();
    return $rows;
}

if (isset($_POST['import_csv'])) {
    if (!isset($_FILES['file_csv']) || ($_FILES['file_csv']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) redirect_msg('Pilih file CSV atau Excel (.xlsx) terlebih dahulu.');
    $ext = strtolower(pathinfo($_FILES['file_csv']['name'], PATHINFO_EXTENSION));

    $handle = null;
    $xlsxRows = null;
    if ($ext === 'xlsx') {
        try {
            $xlsxRows = read_xlsx_rows($_FILES['file_csv']['tmp_name']);
        } catch (Throwable $e) {
            redirect_msg('Excel tidak dapat diproses. ' . $e->getMessage(), 'import-massal');
        }
        if (!$xlsxRows) redirect_msg('File Excel kosong atau tidak memiliki data.', 'import-massal');
        $header = array_map(fn($v) => strtolower(trim((string)$v)), array_shift($xlsxRows));
        $rowsToImport = $xlsxRows;
    } elseif ($ext === 'csv') {
        $handle = fopen($_FILES['file_csv']['tmp_name'], 'r');
        if (!$handle) redirect_msg('File CSV tidak dapat dibaca.');
        $first = fgets($handle);
        if ($first === false) { fclose($handle); redirect_msg('CSV kosong.'); }
        $delimiter = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        rewind($handle);
        $header = fgetcsv($handle, 0, $delimiter);
        if (!$header) { fclose($handle); redirect_msg('Header CSV tidak valid.'); }
        $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
        $header = array_map(fn($v) => strtolower(trim((string)$v)), $header);
        $rowsToImport = null;
    } else {
        redirect_msg('Format tidak didukung. Silakan gunakan CSV atau Excel (.xlsx).', 'import-massal');
    }

    $required = ['kode_buku','kategori','judul_buku','pengarang','penerbit','tahun_terbit','stok','lokasi_rak'];
    foreach ($required as $r) {
        if (!in_array($r, $header, true)) {
            if (is_resource($handle)) fclose($handle);
            redirect_msg("Kolom '$r' tidak ditemukan pada file import. Pastikan header sesuai template: kode_buku, kategori, judul_buku, pengarang, penerbit, tahun_terbit, stok, lokasi_rak.", 'import-massal');
        }
    }

    $inserted=0; $updated=0; $skipped=0; $errors=[];
    mysqli_begin_transaction($koneksi);
    try {
        $rowIterator = $rowsToImport !== null ? $rowsToImport : null;
        while (true) {
            $row = $rowIterator !== null ? (count($rowIterator) ? array_shift($rowIterator) : false) : fgetcsv($handle, 0, $delimiter);
            if ($row === false) break;
            if (count($row) === 1 && trim((string)$row[0]) === '') continue;
            $data=[];
            foreach ($header as $idx=>$key) $data[$key] = trim((string)($row[$idx] ?? ''));

            $kode = $data['kode_buku'];
            $kategori = $data['kategori'];
            $judul = $data['judul_buku'];
            if ($kode === '' || $judul === '' || $kategori === '') { $skipped++; $errors[]='Baris dengan kode/judul/kategori kosong dilewati.'; continue; }

            // Cari/buat kategori berdasarkan nama.
            $stmt = mysqli_prepare($koneksi, "SELECT id_kategori FROM tb_kategori WHERE nama_kategori=? LIMIT 1");
            mysqli_stmt_bind_param($stmt, 's', $kategori);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            $kat = $res ? mysqli_fetch_assoc($res) : null;
            mysqli_stmt_close($stmt);
            if ($kat) {
                $id_kategori = (int)$kat['id_kategori'];
            } else {
                $stmt = mysqli_prepare($koneksi, "INSERT INTO tb_kategori (nama_kategori) VALUES (?)");
                mysqli_stmt_bind_param($stmt, 's', $kategori);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('Gagal membuat kategori ' . $kategori);
                $id_kategori = mysqli_insert_id($koneksi);
                mysqli_stmt_close($stmt);
            }

            $pengarang=$data['pengarang']; $penerbit=$data['penerbit']; $tahun=(int)$data['tahun_terbit'];
            $stok=(int)$data['stok'];
            if ($stok < 1) { $skipped++; $errors[]="Kode $kode dilewati karena stok harus minimal 1."; continue; }
            $rak=$data['lokasi_rak'] !== '' ? $data['lokasi_rak'] : 'Rak Umum';
            $gambar=$data['gambar'] !== '' ? basename($data['gambar']) : null;

            $old=get_book_by_code($koneksi,$kode);
            if ($old) {
                $stmt=mysqli_prepare($koneksi,"UPDATE tb_buku SET id_kategori=?,judul_buku=?,pengarang=?,penerbit=?,tahun_terbit=?,stok=?,lokasi_rak=?,kategori=?,gambar=COALESCE(NULLIF(?,''),gambar) WHERE kode_buku=? LIMIT 1");
                mysqli_stmt_bind_param($stmt,'isssiissss',$id_kategori,$judul,$pengarang,$penerbit,$tahun,$stok,$rak,$kategori,$gambar,$kode);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('Gagal memperbarui '.$kode);
                mysqli_stmt_close($stmt); $updated++;
            } else {
                $stmt=mysqli_prepare($koneksi,"INSERT INTO tb_buku (kode_buku,id_kategori,judul_buku,pengarang,penerbit,tahun_terbit,stok,lokasi_rak,kategori,gambar) VALUES (?,?,?,?,?,?,?,?,?,?)");
                mysqli_stmt_bind_param($stmt,'sisssiisss',$kode,$id_kategori,$judul,$pengarang,$penerbit,$tahun,$stok,$rak,$kategori,$gambar);
                if (!mysqli_stmt_execute($stmt)) throw new Exception('Gagal menambahkan '.$kode);
                mysqli_stmt_close($stmt); $inserted++;
            }
        }
        mysqli_commit($koneksi);
    } catch (Throwable $e) {
        mysqli_rollback($koneksi);
        if (is_resource($handle)) fclose($handle);
        redirect_msg('Import dibatalkan agar database tetap aman. Detail: ' . $e->getMessage(), 'import-massal');
    }
    if (is_resource($handle)) fclose($handle);
    $jenisImport = ($ext === 'xlsx') ? 'Excel' : 'CSV';
    $msg="Import $jenisImport selesai. Data baru: $inserted, diperbarui: $updated, dilewati: $skipped.";
    if ($errors) $msg .= "

" . implode("
", array_slice($errors,0,10));
    redirect_msg($msg);
}

// ------------------------------------------------------------------
// 9. Kelola stok buku.
// ------------------------------------------------------------------
if (isset($_POST['kelola_stok'])) {
    $kode = trim((string)($_POST['kode_stok'] ?? ''));
    $aksi = $_POST['aksi_stok'] ?? '';
    $jumlah = max(0, (int)($_POST['jumlah_stok'] ?? 0));
    if ($kode === '' || !in_array($aksi, ['tambah', 'kurangi'], true) || $jumlah <= 0) {
        redirect_msg('Isi jumlah stok dengan benar.');
    }

    $book = get_book_by_code($koneksi, $kode);
    if (!$book) redirect_msg('Buku tidak ditemukan.');

    $stok_lama = max(0, (int)($book['stok'] ?? 0));

    // Jika masih ada buku yang sedang dipinjam, stok tidak boleh dikurangi
    // sampai lebih kecil dari jumlah buku yang masih berada pada peminjam.
    // Contoh: stok 10 dan sedang dipinjam 3, stok tidak boleh menjadi 2, 1, atau 0.
    $jumlah_dipinjam = get_active_loan_count($koneksi, $kode);
    if ($aksi === 'kurangi') {
        if ($jumlah > $stok_lama) {
            redirect_msg("Stok {$kode} sekarang {$stok_lama} unit. Jumlah yang dikurangi tidak boleh lebih dari stok yang ada.");
        }

        $stok_baru = $stok_lama - $jumlah;
        if ($stok_baru < $jumlah_dipinjam) {
            redirect_msg("Stok {$kode} tidak dapat dikurangi menjadi {$stok_baru} unit karena masih ada {$jumlah_dipinjam} buku yang sedang dipinjam. Stok minimum yang diperbolehkan adalah {$jumlah_dipinjam} unit.");
        }
    } else {
        $stok_baru = $stok_lama + $jumlah;
    }

    $stmt = mysqli_prepare($koneksi, "UPDATE tb_buku SET stok=? WHERE kode_buku=? LIMIT 1");
    if (!$stmt) redirect_msg('Stok belum bisa diubah. Coba lagi.');
    mysqli_stmt_bind_param($stmt, 'is', $stok_baru, $kode);
    $ok = mysqli_stmt_execute($stmt);
    $err = mysqli_stmt_error($stmt);
    mysqli_stmt_close($stmt);

    if (!$ok) redirect_msg('Stok belum tersimpan. ' . $err);

    $arah = $aksi === 'tambah' ? 'ditambah' : 'dikurangi';
    $pesan = "Stok {$book['judul_buku']} {$arah} {$jumlah} unit. Sekarang {$stok_baru} unit.";
    redirect_msg($pesan, 'daftar-buku');
}

// ------------------------------------------------------------------
// 9. Hapus satu buku.
// ------------------------------------------------------------------
if (isset($_GET['aksi']) && $_GET['aksi'] === 'hapus' && isset($_GET['id'])) {
    $kode = trim((string)$_GET['id']);
    $result = delete_book_safely($koneksi, $kode);
    redirect_msg($result['message']);
}

// ------------------------------------------------------------------
// 10. Hapus massal berdasarkan pilihan / kategori / stok.
// ------------------------------------------------------------------
if (isset($_POST['hapus_massal'])) {
    $mode = $_POST['mode_hapus'] ?? '';
    $codes=[];
    if ($mode === 'selected') {
        $codes = array_values(array_unique(array_filter(array_map('trim', $_POST['kode_pilihan'] ?? []))));
    } elseif ($mode === 'category') {
        $id_kat=(int)($_POST['kategori_hapus'] ?? 0);
        if ($id_kat > 0) {
            $stmt=mysqli_prepare($koneksi,"SELECT kode_buku FROM tb_buku WHERE id_kategori=?");
            mysqli_stmt_bind_param($stmt,'i',$id_kat); mysqli_stmt_execute($stmt); $res=mysqli_stmt_get_result($stmt);
            while($r=mysqli_fetch_assoc($res)) $codes[]=$r['kode_buku'];
            mysqli_stmt_close($stmt);
        }
    } elseif ($mode === 'filter') {
        // Hapus sesuai filter cepat (kategori + status) di panel Data Buku.
        $f_kat = (int)($_POST['filter_kat'] ?? 0);
        $f_status = trim((string)($_POST['filter_status'] ?? ''));
        $f_where = array();
        if ($f_kat > 0) $f_where[] = "id_kategori = '$f_kat'";
        if ($f_status === 'ada') $f_where[] = "stok > 0";
        elseif ($f_status === 'habis') $f_where[] = "stok <= 0";
        $f_sql = "SELECT kode_buku FROM tb_buku" . ($f_where ? " WHERE " . implode(' AND ', $f_where) : '');
        if ($res_f = mysqli_query($koneksi, $f_sql)) {
            while ($r = mysqli_fetch_assoc($res_f)) $codes[] = $r['kode_buku'];
        }
    }

    if (!$codes) redirect_msg('Tidak ada buku yang memenuhi pilihan penghapusan.');
    $ok_count=0; $skip_count=0; $skip=[];
    foreach($codes as $code){
        $r=delete_book_safely($koneksi,$code);
        if($r['ok']) $ok_count++; else { $skip_count++; $skip[]=$r['message']; }
    }
    $msg="Penghapusan massal selesai. Berhasil: $ok_count, tidak dihapus: $skip_count.";
    if($skip) $msg.="

".implode("
",array_slice($skip,0,10));
    redirect_msg($msg);
}

// ------------------------------------------------------------------
// 11. Data edit, pencarian, dan daftar buku.
// ------------------------------------------------------------------
$edit_book = null;
if (isset($_GET['aksi']) && $_GET['aksi'] === 'edit' && isset($_GET['id'])) {
    $edit_book = get_book_by_code($koneksi, trim((string)$_GET['id']));
}

$search = trim($_GET['q'] ?? '');
$search_like = '%' . $search . '%';
$q_kat = isset($_GET['q_kat']) ? (int)$_GET['q_kat'] : 0;
$q_status = trim($_GET['q_status'] ?? '');

$sql_list = "SELECT tb_buku.*, tb_kategori.nama_kategori
             FROM tb_buku
             LEFT JOIN tb_kategori ON tb_buku.id_kategori=tb_kategori.id_kategori";
$where_list = array();
$bind_types = '';
$bind_vals = array();
if ($search !== '') {
    $where_list[] = "(tb_buku.kode_buku LIKE ? OR tb_buku.judul_buku LIKE ? OR tb_buku.pengarang LIKE ? OR tb_buku.penerbit LIKE ? OR tb_kategori.nama_kategori LIKE ? OR tb_buku.lokasi_rak LIKE ?)";
    $bind_types .= 'ssssss';
    array_push($bind_vals, $search_like, $search_like, $search_like, $search_like, $search_like, $search_like);
}
if ($q_kat > 0) { $where_list[] = "tb_buku.id_kategori = ?"; $bind_types .= 'i'; $bind_vals[] = $q_kat; }
if ($q_status === 'ada') { $where_list[] = "tb_buku.stok > 0"; }
elseif ($q_status === 'habis') { $where_list[] = "tb_buku.stok <= 0"; }
if ($where_list) { $sql_list .= " WHERE " . implode(' AND ', $where_list); }
$sql_list .= " ORDER BY tb_buku.kode_buku DESC";
$stmt_list=mysqli_prepare($koneksi,$sql_list);
if($bind_types!==''){ mysqli_stmt_bind_param($stmt_list,$bind_types,...$bind_vals); }
mysqli_stmt_execute($stmt_list); $query_buku=mysqli_stmt_get_result($stmt_list);

$get_kat = mysqli_query($koneksi, "SELECT * FROM tb_kategori ORDER BY nama_kategori ASC");
$kategori_list=[];
if($get_kat) while($kat=mysqli_fetch_assoc($get_kat)) $kategori_list[]=$kat;

// Data buku untuk pratinjau sebelum penghapusan berdasarkan kategori.
$category_books_preview=[];
$q_preview=mysqli_query($koneksi,"SELECT tb_buku.kode_buku,tb_buku.judul_buku,tb_buku.pengarang,tb_buku.stok,tb_buku.id_kategori,tb_kategori.nama_kategori FROM tb_buku LEFT JOIN tb_kategori ON tb_buku.id_kategori=tb_kategori.id_kategori ORDER BY tb_buku.judul_buku ASC");
if($q_preview){ while($r=mysqli_fetch_assoc($q_preview)){ $category_books_preview[]=[
    'kode'=>(string)($r['kode_buku']??''),
    'judul'=>(string)($r['judul_buku']??''),
    'pengarang'=>(string)($r['pengarang']??''),
    'stok'=>(int)($r['stok']??0),
    'id_kategori'=>(int)($r['id_kategori']??0),
    'kategori'=>(string)($r['nama_kategori']??'')
]; }}

// Kode otomatis aman: cari nomor terbesar yang sudah ada, bukan COUNT().
$next_num=1;
$res_codes=mysqli_query($koneksi,"SELECT kode_buku FROM tb_buku");
if($res_codes){
    while($r=mysqli_fetch_assoc($res_codes)){
        if(preg_match('/^BK-(\d+)$/i',$r['kode_buku'],$m)) $next_num=max($next_num,(int)$m[1]+1);
    }
}
$kode_buku_otomatis='BK-'.str_pad($next_num,3,'0',STR_PAD_LEFT);

// Statistik kecil untuk admin.
$total_buku=(int)(mysqli_fetch_assoc(mysqli_query($koneksi,"SELECT COUNT(*) total FROM tb_buku"))['total'] ?? 0);
$total_stok=(int)(mysqli_fetch_assoc(mysqli_query($koneksi,"SELECT COALESCE(SUM(stok),0) total FROM tb_buku"))['total'] ?? 0);
$total_tanpa_foto=(int)(mysqli_fetch_assoc(mysqli_query($koneksi,"SELECT COUNT(*) total FROM tb_buku WHERE gambar IS NULL OR gambar=''"))['total'] ?? 0);

$flash_message = $_SESSION['buku_flash_message'] ?? '';
unset($_SESSION['buku_flash_message']);

// Jumlah eksemplar yang sedang dipinjam per kode buku (untuk kolom Stok "tersedia / total").
$dipinjam_map = [];
$q_dipinjam_map = mysqli_query($koneksi, "SELECT kode_buku, COUNT(*) AS jml FROM tb_peminjaman WHERE LOWER(status_pinjam) = 'dipinjam' GROUP BY kode_buku");
if ($q_dipinjam_map) { while ($rd = mysqli_fetch_assoc($q_dipinjam_map)) { $dipinjam_map[(string)$rd['kode_buku']] = (int)$rd['jml']; } }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Buku &mdash; Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green-900:#064c40;--green-800:#085f50;--green-700:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e4ebee;--bg:#eff1f2;--card:#fff;--red:#e5484d;--blue:#2563eb;--yellow:#e8a013}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
.layout{display:flex;min-height:100vh}
.sidebar{width:256px;flex:none;background:#064c40;color:#dcebe3;display:flex;flex-direction:column;justify-content:space-between;padding:0;position:fixed;left:0;top:0;bottom:0;height:100vh;overflow-y:auto;z-index:30;box-shadow:0 0 24px rgba(6,76,64,.14)}
.side-brand{display:flex;align-items:center;gap:10px;padding:0 8px 18px;border-bottom:1px solid rgba(255,255,255,.14)}
.side-brand img{width:42px;height:42px;object-fit:contain}
.side-brand b{display:block;font-size:13px;line-height:1.25}
.side-brand small{display:block;font-size:10px;color:#a9c8bb;font-weight:500}
.side-nav{margin-top:16px;display:flex;flex-direction:column;gap:4px}
.side-nav a{display:flex;align-items:center;gap:11px;padding:11px 12px;border-radius:10px;color:#d5e6dd;text-decoration:none;font-size:13.5px;font-weight:600}
.side-nav a svg{width:19px;height:19px;flex:none}
.side-nav a:hover{background:rgba(255,255,255,.08)}
.side-nav a.on{background:rgba(255,255,255,.16);color:#fff;box-shadow:inset 3px 0 0 #6fe3c4}
.side-nav hr{border:0;border-top:1px solid rgba(255,255,255,.14);margin:8px 4px}
.side-art{margin-top:auto;padding:6px 4px 0}
.side-art img{width:100%;border-radius:12px;opacity:.92}
.main{flex:1;min-width:0;margin-left:256px;min-height:100vh;padding-bottom:110px}
.topbar{display:flex;align-items:center;gap:14px;background:#fff;border-bottom:1px solid var(--line);padding:14px 26px;position:sticky;top:0;z-index:20}
.hamburger{border:0;background:none;cursor:pointer;padding:6px;display:none;color:inherit}
.hamburger svg{width:24px;height:24px}
.topbar h2{font-size:19px;font-weight:800}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.user-chip{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600}
.user-chip svg{width:19px;height:19px}
.btn-logout{display:flex;align-items:center;gap:7px;background:var(--red);color:#fff;border:0;border-radius:10px;padding:9px 16px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.btn-logout svg{width:16px;height:16px}
.btn-logout:hover{background:#d23b40;transform:translateY(-1px);box-shadow:0 6px 16px rgba(229,72,77,.3)}
.mob-header{display:none}
.content{padding:26px 30px;max-width:1220px}
.page-title{font-size:22px;font-weight:800;letter-spacing:-.3px}
.page-sub{font-size:12.5px;color:var(--muted);margin:3px 0 22px}
.card{background:var(--card);border-radius:12px;padding:22px;box-shadow:0 1px 3px rgba(6,76,64,.05);border:1px solid #e8eef0;margin-bottom:20px}
.card-editing{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.18)}
#form-buku{scroll-margin-top:80px}
.card h3{font-size:14px;font-weight:800;display:flex;align-items:center;gap:9px;margin-bottom:14px}
.card h3 svg{width:19px;height:19px;color:var(--green-800)}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:18px}
.stat{background:#fff;border:1px solid #e8eef0;border-radius:12px;padding:16px 18px;box-shadow:0 1px 3px rgba(6,76,64,.05)}
.stat b{display:block;font-size:11px;color:var(--green-800);letter-spacing:.4px}
.stat .num{font-size:28px;font-weight:800}
.stat small{font-size:12px;color:var(--muted)}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:20px}
.grid2 .card{margin-bottom:0}
label.flabel{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:6px}
input,select{font:inherit;font-size:12px;border:1px solid #cbd5e1;border-radius:8px;padding:10px 12px;color:var(--ink);background:#fff;outline:none;width:100%}
input:focus,select:focus{border-color:var(--green-700)}
input::placeholder{color:#94a3b8}
.form-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.form-grid .full{grid-column:1/-1}
.form-grid .span2{grid-column:span 2}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:6px;border:0;border-radius:8px;padding:9px 17px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff;background:var(--green-900);transition:transform .15s ease,background .15s ease,filter .15s ease}
.btn svg{width:14px;height:14px}
.btn:hover{filter:brightness(1.07)}.btn:active{transform:scale(.97)}
.btn.blue{background:var(--blue)}
.btn.red{background:var(--red)}
.btn.yellow{background:var(--yellow)}
.btn.ghost{background:#f1f5f9;color:#475569;border:1px solid #e2e8f0}
.btn.dark{background:#04382f}
.btn.sm{padding:5px 10px;font-size:11px;border-radius:6px}
.btn-row{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
.filebox{display:flex;align-items:center;border:1px solid #cbd5e1;border-radius:8px;padding:5px;background:#f8fafc;gap:10px;margin-bottom:10px}
.filebox .pick{background:#fff;border:1px solid #cbd5e1;border-radius:6px;padding:5px 12px;font-size:11.5px;font-weight:600;color:#334155;cursor:pointer;white-space:nowrap}
.filebox span{font-size:11.5px;color:#94a3b8}
.file-hidden{display:none !important}
.searchbar{display:flex;gap:8px}
.searchbar .swrap{position:relative;flex:1}
.searchbar .swrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8}
.searchbar .swrap input{padding-left:34px;border-radius:8px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:980px}
thead th{background:#f8fafc;color:#475569;font-weight:600;text-align:left;padding:11px 14px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
tbody tr:hover{background:#f8fafc}
tbody td{padding:13px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody .kode{font-weight:800;color:#0f172a;font-size:12px}
tbody .sub{font-size:11px;color:#94a3b8}
tbody .ttl{font-weight:600;color:#0f172a;display:flex;align-items:center;gap:8px}
.dot{width:7px;height:7px;border-radius:99px;flex:none}
.dot.g{background:#10b981}.dot.r{background:#f43f5e}
.catpill{display:inline-block;font-size:11px;font-weight:500;border-radius:4px;padding:3px 9px;border:1px solid}
.catpill.t{background:#f0fdfa;color:#0f766e;border-color:#99f6e4}
.catpill.a{background:#fffbeb;color:#b45309;border-color:#fde68a}
.catpill.u{background:#f1f5f9;color:#475569;border-color:#e2e8f0}
.stok b{font-weight:800}.stok span{color:#94a3b8}
.stok b.zero{color:#e11d48}
.statpill{display:inline-flex;align-items:center;font-size:11px;font-weight:600;border-radius:99px;padding:4px 11px;white-space:nowrap}
.statpill.ok{background:#dcfce7;color:#15803d}
.statpill.out{background:#ffe4e6;color:#be123c}
.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
.filtertags{display:flex;gap:8px;flex-wrap:wrap;padding:12px 20px;background:#f8fafc;border-bottom:1px solid #e8eef0}

/* Fitur tambahan: pilihan buku massal */
.bulk-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 20px 12px;padding:10px 12px;background:#eff6ff;border:1px solid #cfe0fb;border-radius:9px}
.bulk-toolbar .btn{white-space:nowrap}
.bulk-toolbar [hidden]{display:none!important}
.bulk-count{font-size:11.5px;color:#475569;margin-left:2px}
.bulk-check-cell{display:none;text-align:center;width:42px}
.bulk-select-mode .bulk-check-cell{display:table-cell}
.bulk-check{width:15px;height:15px;cursor:pointer;accent-color:#2563eb}
@media(max-width:640px){.bulk-toolbar{margin-left:12px;margin-right:12px}.bulk-toolbar .btn{padding:8px 10px;font-size:11px}}
.ftag{border-radius:99px;padding:5px 13px;font:inherit;font-size:11.5px;font-weight:500;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#64748b;text-decoration:none}
.ftag.on{background:var(--green-900);border-color:var(--green-900);color:#fff;font-weight:600}
.pager{display:flex;align-items:center;justify-content:space-between;margin-top:0;padding:14px 20px;font-size:12px;color:var(--muted)}
.bottomnav{display:none}
.thumb{width:42px;height:58px;object-fit:cover;border-radius:6px;border:1px solid var(--line);background:#eef3f1}
@media(max-width:900px){
.main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
body.nav-open .sidebar{transform:none}
body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
.hamburger{display:block}
.grid2{grid-template-columns:1fr}
.form-grid{grid-template-columns:1fr 1fr}
.stats{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
.main{padding-bottom:120px}
.mob-header{display:flex;align-items:center;gap:10px;background:linear-gradient(120deg,#085f50,#064c40);color:#fff;border-radius:0 0 26px 26px;padding:18px 18px 34px}
.mob-header img{width:46px;height:46px;object-fit:contain}
.mob-header b{display:block;font-size:16px;line-height:1.2}
.mob-header small{font-size:11.5px;color:#c4ddd0}
.mob-user{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12px}
.topbar{border-radius:14px 14px 0 0;margin-top:-18px;padding:14px 16px}
.topbar h2{font-size:20px}
.user-chip{display:none}
.content{padding:20px 14px}
.form-grid{grid-template-columns:1fr}
.stats{grid-template-columns:1fr 1fr}
.bottomnav{display:flex;position:fixed;left:10px;right:10px;bottom:12px;z-index:60;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(11,77,58,.18);padding:10px 6px}
.bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:#93a3aa;font-size:11.5px;font-weight:600;padding:6px 0}
.bottomnav a svg{width:24px;height:24px}
.bottomnav a.on{color:var(--green-900)}
.bottomnav a.on::after{content:"";width:44px;height:3px;border-radius:99px;background:var(--green-900)}
}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
<!-- COPY: sidebar-start -->
<aside class="sidebar" aria-label="Navigasi utama">
<div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
<nav class="side-nav">
<a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
<a href="buku.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
<a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
<a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
<a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
<a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
<hr>
<a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>Lihat Katalog</a>
</nav>
<div class="side-art"><img src="assets/logo-bunga.png" alt="Ilustrasi buku"></div>
</aside>
<!-- COPY: sidebar-end -->
<div class="main">
<div class="mob-header">
      <div class="row">
        <button class="mob-menu-btn" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
        <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
        <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg></span><span>Halo,<br>Petugas Perpustakaan</span></div>
      </div>
    </div>
    <!-- COPY: topbar-start -->
<div class="topbar">
<button class="hamburger" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
<h2>Data Buku</h2>
<div class="topbar-right">
<span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, Petugas Perpustakaan&nbsp;&#9662;</span>
<a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Logout</a>
</div>
</div>
<!-- COPY: topbar-end -->
<div class="content">
<h3 class="page-title">Kelola Data Buku Koleksi Perpustakaan</h3>
<p class="page-sub">Tambah, perbarui, dan kelola inventaris buku fisik serta status ketersediaan koleksi UIN Datokarama Palu.</p><?php if (!empty($flash_message)): ?><div class="card" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:13px 16px;font-size:13px;white-space:pre-line"><?= htmlspecialchars($flash_message); ?></div><?php endif; ?>
<section class="card" style="padding:22px">
<div class="grid2">
<div>
<label class="flabel">Import Data Koleksi Buku Massal</label>
<!-- Kontrak: import_csv + file_csv -->
<form action="buku.php" method="POST" enctype="multipart/form-data">
<div class="filebox"><label class="pick" for="fileCsv">Pilih File</label><input type="file" id="fileCsv" name="file_csv" accept=".csv,.xlsx" required hidden><span id="csvName">Tidak ada file yang dipilih</span></div>
<div class="btn-row" style="margin-top:0"><button type="submit" name="import_csv" class="btn">Import Data</button><a class="btn dark" href="buku.php?aksi=template_csv">Template Excel/CSV</a></div>
</form>
</div>
<div>
<label class="flabel">Cetak Label &amp; Barcode Massal</label>
<div class="form-grid" style="grid-template-columns:1fr 1fr;margin-bottom:10px">
<div><select id="bcKat" aria-label="Kategori"><option value="">Semua Kategori</option><?php foreach ($kategori_list as $kl): ?><option value="<?= (int)$kl['id_kategori']; ?>"><?= htmlspecialchars($kl['nama_kategori']); ?></option><?php endforeach; ?></select></div>
<div><select id="bcRak" aria-label="Rak"><option value="">Semua Rak / Lokasi</option><?php $q_rak = mysqli_query($koneksi, "SELECT DISTINCT lokasi_rak FROM tb_buku ORDER BY lokasi_rak ASC"); if ($q_rak) { while ($rr = mysqli_fetch_assoc($q_rak)): if (trim($rr['lokasi_rak']) !== ''): ?><option><?= htmlspecialchars($rr['lokasi_rak']); ?></option><?php endif; endwhile; } ?></select></div>
</div>
<button type="button" class="btn" onclick="window.location='buku.php?aksi=cetak_barcode&kat='+encodeURIComponent(document.getElementById('bcKat').value)+'&rak='+encodeURIComponent(document.getElementById('bcRak').value)"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"/></svg>Cetak Barcode &amp; Label</a>
</div>
</div>
<hr style="border:0;border-top:1px solid #f1f5f9;margin:20px 0 16px">
<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap">
<div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap">
<span style="font-size:13px;font-weight:700;color:#0f172a">Filter Cepat Koleksi:</span>
<form action="buku.php" method="GET" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
<span style="font-size:11.5px;color:#64748b">Kategori:</span>
<select name="q_kat" id="selFilterKat" onchange="this.form.submit()" style="width:auto;min-width:140px"><option value="">Semua Kategori</option><?php foreach ($kategori_list as $kl): ?><option value="<?= (int)$kl['id_kategori']; ?>" <?= $q_kat === (int)$kl['id_kategori'] ? 'selected' : ''; ?>><?= htmlspecialchars($kl['nama_kategori']); ?></option><?php endforeach; ?></select>
<span style="font-size:11.5px;color:#64748b">Status:</span>
<select name="q_status" id="selFilterStatus" onchange="this.form.submit()" style="width:auto;min-width:130px"><option value="">Semua Status</option><option value="ada" <?= $q_status === 'ada' ? 'selected' : ''; ?>>Tersedia</option><option value="habis" <?= $q_status === 'habis' ? 'selected' : ''; ?>>Habis Dipinjam</option></select>
</form>
</div>
<div style="display:flex;gap:8px;flex-wrap:wrap">
<form action="buku.php" method="POST" style="display:inline" onsubmit="return syncHapusFilter(this)">
<input type="hidden" name="mode_hapus" value="filter">
<input type="hidden" name="filter_kat" id="hapusFilterKat" value="">
<input type="hidden" name="filter_status" id="hapusFilterStatus" value="">
<button type="submit" name="hapus_massal" class="btn red">Hapus Sesuai Filter</button>
</form>
<a class="btn ghost" href="buku.php?aksi=export_excel"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>Ekspor Excel</a>
</div>
</div>
</section>
<section class="card<?= $edit_book ? ' card-editing' : ''; ?>" id="form-buku">
<div style="display:flex;align-items:center;gap:8px;padding-bottom:16px;border-bottom:1px solid #f1f5f9;margin-bottom:16px">
<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:19px;height:19px;color:#0f172a"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
<h3 style="margin:0;font-size:14px"><?= $edit_book ? 'Edit Data Buku' : 'Registrasi &amp; Tambah Buku Baru'; ?></h3>
<?php if ($edit_book): ?><span style="margin-left:auto;font-size:11.5px;font-weight:600;color:#b45309;background:#fef3c7;border:1px solid #fde68a;border-radius:99px;padding:4px 12px">Mode Edit &bull; <?= htmlspecialchars($edit_book['kode_buku']); ?></span><?php endif; ?>
</div>
<!-- Kontrak: tambah_buku + kode_buku/id_kategori/judul_buku/pengarang/penerbit/tahun_terbit/stok/lokasi_rak/gambar -->
<form action="buku.php" method="POST" enctype="multipart/form-data">
<div class="form-grid">
<div><label class="flabel">Kode &amp; ISBN Buku</label><input type="text" name="kode_buku" value="<?= $edit_book ? htmlspecialchars($edit_book['kode_buku']) : htmlspecialchars($kode_buku_otomatis); ?>" placeholder="Contoh: BK-INF-005 / 978-623-..." required></div>
<div class="span2"><label class="flabel">Judul Lengkap Buku</label><input type="text" name="judul_buku" value="<?= $edit_book ? htmlspecialchars($edit_book['judul_buku']) : ''; ?>" placeholder="Masukkan judul buku secara lengkap..." required></div>
<div><label class="flabel">Kategori / Klasifikasi</label><select name="id_kategori" required><option value="">Pilih...</option><?php foreach ($kategori_list as $kl): ?><option value="<?= (int)$kl['id_kategori']; ?>" <?= $edit_book && (int)$edit_book['id_kategori'] === (int)$kl['id_kategori'] ? 'selected' : ''; ?>><?= htmlspecialchars($kl['nama_kategori']); ?></option><?php endforeach; ?></select></div>
<div><label class="flabel">Pengarang / Penulis</label><input type="text" name="pengarang" value="<?= $edit_book ? htmlspecialchars($edit_book['pengarang'] ?? '') : ''; ?>" placeholder="Nama penulis / pengarang..."></div>
<div><label class="flabel">Penerbit &amp; Tahun Terbit</label><input type="text" name="penerbit" value="<?= $edit_book ? htmlspecialchars(($edit_book['penerbit'] ?? '') . (isset($edit_book['tahun_terbit']) && $edit_book['tahun_terbit'] !== '' ? ' (' . $edit_book['tahun_terbit'] . ')' : '')) : ''; ?>" placeholder="Contoh: Andi Publisher (2024)"></div>
<div><label class="flabel">Nomor Rak / Lokasi</label><input type="text" name="lokasi_rak" value="<?= $edit_book ? htmlspecialchars($edit_book['lokasi_rak'] ?? '') : ''; ?>" placeholder="Contoh: Rak INF-01"></div>
<div><label class="flabel">Jumlah Stok (Eksemplar)</label><input type="text" name="stok" value="<?= $edit_book ? (int)$edit_book['stok'] : 5; ?>" inputmode="numeric" required></div>
<div class="full" style="display:none"><input type="text" name="tahun_terbit" value="<?= $edit_book ? htmlspecialchars($edit_book['tahun_terbit'] ?? '2026') : '2026'; ?>"></div>
<div class="full"><label class="flabel">Sampul (JPG/PNG/WEBP, maks 5MB)</label><input type="file" name="<?= $edit_book ? 'gambar_edit' : 'gambar'; ?>" accept=".jpg,.jpeg,.png,.webp"></div>
<div class="full" style="display:flex;gap:8px">
<?php if ($edit_book): ?>
<input type="hidden" name="kode_buku_lama" value="<?= htmlspecialchars($edit_book['kode_buku']); ?>">
<button type="submit" name="edit_buku" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>Simpan Perubahan</button>
<a href="buku.php" class="btn ghost">Reset Form</a>
<?php else: ?>
<button type="submit" name="tambah_buku" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>Daftar Buku Baru</button>
<button type="reset" class="btn ghost">Reset Form</button>
<?php endif; ?>
</div>
</div>
</form>
</section>
<div class="grid2">
<div class="card">
<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>Import CSV / Excel</h3>
<!-- Kontrak: import_csv + file_csv -->
<form action="buku.php" method="POST" enctype="multipart/form-data">
<input type="file" name="file_csv" accept=".csv,.xlsx" required>
<div class="btn-row"><button type="submit" name="import_csv" class="btn">Import</button><a class="btn ghost" href="buku.php?aksi=template_csv">Template CSV</a></div>
</form>
</div>
<div class="card">
<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M2 12h3M19 12h3"/></svg>Kelola Stok</h3>
<!-- Kontrak: kelola_stok + kode_stok/aksi_stok/jumlah_stok -->
<form action="buku.php" method="POST">
<div class="form-grid" style="grid-template-columns:1fr 1fr">
<div><label class="flabel">Kode Buku</label><input type="text" name="kode_stok" placeholder="BK-..." required></div>
<div><label class="flabel">Jumlah</label><input type="text" name="jumlah_stok" value="1" inputmode="numeric" required></div>
<div><label class="flabel">Aksi</label><select name="aksi_stok"><option value="tambah">Tambah</option><option value="kurangi">Kurangi</option></select></div>
<div style="align-self:end"><button type="submit" name="kelola_stok" class="btn">Simpan Stok</button></div>
</div>
</form>
</div>
</div>
<div class="grid2" style="margin-top:18px">
<div class="card">
<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="9" cy="9" r="1.6"/><path d="m21 15-4.5-4.5L6 21"/></svg>Upload Foto Massal</h3>
<!-- Kontrak: upload_foto_massal + foto_massal[] ; nama file = kode buku -->
<form action="buku.php" method="POST" enctype="multipart/form-data">
<input type="file" name="foto_massal[]" accept=".jpg,.jpeg,.png,.webp" multiple required>
<p style="font-size:12px;color:var(--muted);margin-top:8px">Nama file harus sama dengan kode buku, contoh: BK-007.jpg</p>
<div class="btn-row"><button type="submit" name="upload_foto_massal" class="btn">Upload Foto</button></div>
</form>
<!-- Kontrak: pasang_foto + kode_foto/foto_pilihan (pasang sampul ke satu buku) -->
<form action="buku.php" method="POST" enctype="multipart/form-data" style="margin-top:16px;border-top:1px solid #f1f5f9;padding-top:14px">
<label class="flabel">Pasang Sampul ke Satu Buku</label>
<input type="text" name="kode_foto" placeholder="Kode buku, contoh: BK-007" required style="margin-bottom:10px">
<input type="file" name="foto_pilihan" accept=".jpg,.jpeg,.png,.webp" required>
<div class="btn-row"><button type="submit" name="pasang_foto" class="btn">Pasang Foto</button></div>
</form>
</div>
<div class="card">
<h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>Kategori</h3>
<!-- Kontrak: tambah_kategori + nama_kategori_baru ; hapus_kategori + id_kategori_hapus ; hapus_semua_kategori -->
<form action="buku.php" method="POST" style="display:flex;gap:10px;margin-bottom:12px">
<input type="text" name="nama_kategori_baru" placeholder="Nama kategori baru..." required style="flex:1">
<button type="submit" name="tambah_kategori" class="btn">Tambah</button>
</form>
<form action="buku.php" method="POST" style="display:flex;gap:10px" onsubmit="return confirm('Hapus kategori beserta bukunya? Buku ber-riwayat dilindungi.')">
<select name="id_kategori_hapus" style="flex:1"><option value="">Pilih kategori...</option><?php foreach ($kategori_list as $kl): ?><option value="<?= (int)$kl['id_kategori']; ?>"><?= htmlspecialchars($kl['nama_kategori']); ?></option><?php endforeach; ?></select>
<button type="submit" name="hapus_kategori" class="btn red">Hapus</button>
</form>
<form action="buku.php" method="POST" style="margin-top:10px" onsubmit="return confirm('Proses SEMUA kategori? Kategori ber-riwayat akan dipertahankan otomatis.')">
<button type="submit" name="hapus_semua_kategori" class="btn red" style="width:100%">Hapus Semua Kategori</button>
</form>
</div>
</div>
<section class="card" id="daftar-buku" style="padding:0;overflow:hidden">
<div style="display:flex;align-items:center;gap:12px;padding:18px 20px;flex-wrap:wrap">
<div style="display:flex;align-items:center;gap:12px">
<span style="width:34px;height:34px;border-radius:9px;background:#e6f0ee;display:grid;place-items:center;color:#064c40;flex:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:17px;height:17px"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></span>
<div><h3 style="margin:0;font-size:13.5px">Daftar Koleksi Buku Perpustakaan</h3><span class="statpill ok" style="margin-top:4px">Total: <?= (int)$total_buku; ?> Judul (<?= (int)$total_stok; ?> Eksemplar)</span></div>
</div>
<form class="searchbar" action="buku.php" method="GET" style="margin-left:auto;flex:1;max-width:440px;min-width:240px">
<div class="swrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg><input type="search" name="q" placeholder="Cari judul, ISBN, atau nomor rak..." value="<?= htmlspecialchars($search); ?>"></div>
<button type="submit" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>Cari</button>
</form>
</div>
<div class="filtertags">
<a class="ftag on" href="buku.php#daftar-buku">Semua (<?= (int)$total_buku; ?>)</a>
<?php foreach ($kategori_list as $kl): $kn = strtolower(trim($kl['nama_kategori'])); $cls = (strpos($kn, 'informatika') !== false || strpos($kn, 'teknologi') !== false || strpos($kn, 'sains') !== false) ? 't' : ((strpos($kn, 'agama') !== false) ? 'a' : 'u'); ?>
<a class="ftag" href="buku.php?q=<?= urlencode($kl['nama_kategori']); ?>#daftar-buku"><?= htmlspecialchars($kl['nama_kategori']); ?></a>
<?php endforeach; ?>
</div>
<form id="bulkDeleteForm" action="buku.php" method="POST" onsubmit="return confirmHapusBukuTerpilih()">
<input type="hidden" name="mode_hapus" value="selected">
<div class="bulk-toolbar" id="bulkToolbar">
  <button type="button" class="btn red" id="btnPilihBuku" onclick="toggleModePilihBuku(true)">&#9745; Pilih Buku</button>
  <button type="button" class="btn blue" id="btnCentangSemua" onclick="centangSemuaBuku()" hidden>Centang Semua</button>
  <button type="button" class="btn ghost" id="btnBatalCentang" onclick="batalCentangSemuaBuku()" hidden>Batal Centang Semua</button>
  <button type="submit" name="hapus_massal" value="1" class="btn red" id="btnHapusTerpilih" hidden>Hapus yang Dipilih</button>
  <button type="button" class="btn ghost" id="btnSelesaiPilih" onclick="toggleModePilihBuku(false)" hidden>Selesai / Sembunyikan</button>
  <span class="bulk-count" id="jumlahBukuDipilih" hidden>0 buku dipilih</span>
</div>
<div class="table-wrap"><table>
<thead><tr><th class="bulk-check-cell"><input class="bulk-check" type="checkbox" id="checkSemuaBuku" aria-label="Centang semua buku" onchange="centangSemuaBuku(this.checked)"></th><th>Kode &amp; ISBN</th><th>Judul Buku</th><th>Pengarang &amp; Penerbit</th><th>Kategori</th><th>Lokasi Rak</th><th style="text-align:center">Stok</th><th>Status</th><th style="text-align:center">Aksi</th></tr></thead>
<tbody>
<?php if ($query_buku && mysqli_num_rows($query_buku) > 0): while ($b = mysqli_fetch_assoc($query_buku)):
  $b_kode = (string)($b['kode_buku'] ?? '-');
  $b_stok = max(0, (int)($b['stok'] ?? 0));
  $b_stok_total = $b_stok + (int)($dipinjam_map[$b_kode] ?? 0);
  $b_cat = (string)($b['nama_kategori'] ?? ($b['kategori'] ?? '-'));
  $b_cat_l = strtolower($b_cat);
  $b_cat_cls = (strpos($b_cat_l, 'informatika') !== false || strpos($b_cat_l, 'teknologi') !== false) ? 't' : ((strpos($b_cat_l, 'agama') !== false) ? 'a' : 'u');
  $b_habis = $b_stok <= 0;
?>
<tr <?= $b_habis ? 'style="background:#fef2f2"' : ''; ?>>
<td class="bulk-check-cell"><input class="bulk-check buku-pilihan" type="checkbox" name="kode_pilihan[]" value="<?= htmlspecialchars($b_kode, ENT_QUOTES, 'UTF-8'); ?>" aria-label="Pilih buku <?= htmlspecialchars($b_kode, ENT_QUOTES, 'UTF-8'); ?>" onchange="perbaruiJumlahBukuDipilih()"></td>
<td><div class="kode"><?= htmlspecialchars($b_kode); ?></div><div class="sub"><?= htmlspecialchars($b['kode_buku'] ?? ''); ?></div></td>
<td><span class="ttl"><span class="dot <?= $b_habis ? 'r' : 'g'; ?>"></span><?= htmlspecialchars($b['judul_buku'] ?? '-'); ?></span></td>
<td><?= htmlspecialchars($b['pengarang'] ?? '-'); ?><br><span class="sub"><?= htmlspecialchars($b['penerbit'] ?? '-'); ?><?= ($b['tahun_terbit'] ?? '') !== '' ? ' (' . htmlspecialchars($b['tahun_terbit']) . ')' : ''; ?></span></td>
<td><span class="catpill <?= $b_cat_cls; ?>"><?= htmlspecialchars($b_cat); ?></span></td>
<td><?= htmlspecialchars($b['lokasi_rak'] ?? 'Rak Umum'); ?></td>
<td class="stok" style="text-align:center"><b class="<?= $b_habis ? 'zero' : ''; ?>"><?= $b_stok; ?></b> <span>/ <?= $b_stok_total; ?></span></td>
<td><?= $b_habis ? '<span class="statpill out">&#9679; Habis Dipinjam</span>' : '<span class="statpill ok">&#9679; Tersedia</span>'; ?></td>
<td><div class="acts"><a class="btn yellow sm" href="buku.php?aksi=edit&amp;id=<?= urlencode($b_kode); ?>#form-buku">Edit</a><a class="btn sm" style="background:#064c40" href="buku.php?aksi=barcode&amp;id=<?= urlencode($b_kode); ?>">Barcode</a><a class="btn red sm" href="buku.php?aksi=hapus&amp;id=<?= urlencode($b_kode); ?>" onclick="return confirm('Hapus buku <?= htmlspecialchars($b_kode, ENT_QUOTES); ?>?')">Hapus</a></div></td></tr>
<?php endwhile; else: ?>
<tr><td colspan="9" style="text-align:center;color:#8a99a0;padding:20px">Belum ada buku. Tambah manual atau import CSV.</td></tr>
<?php endif; ?>
</tbody>
</table></div>
</form>
<div class="pager" style="border-top:1px solid #f1f5f9"><span><?= $search !== '' ? 'Hasil pencarian: "' . htmlspecialchars($search) . '"' : 'Menampilkan 1-' . (int)min($total_buku, 20) . ' dari ' . (int)$total_buku . ' data buku'; ?></span><span><a href="#" style="text-decoration:none;color:#94a3b8;border:1px solid #e2e8f0;border-radius:6px;padding:4px 9px">&lt;</a> <a href="#" style="text-decoration:none;color:#fff;background:#064c40;border-radius:6px;padding:4px 10px;font-weight:700">1</a> <a href="#" style="text-decoration:none;color:#94a3b8;border:1px solid #e2e8f0;border-radius:6px;padding:4px 9px">&gt;</a></span></div>
</section>
</div>
</div>
</div>
<!-- COPY: bottomnav-start -->
<nav class="bottomnav" aria-label="Navigasi bawah">
<a href="dashboard.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2.5 10v11h6v-6h7v6h6V10L12 3z"/></svg>Beranda</a>
<a href="buku.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5.5A1.5 1.5 0 0 1 5.5 4H18v13H5.5A1.5 1.5 0 0 0 4 18.5z"/><path d="M4 19a1.5 1.5 0 0 0 1.5 1.5H20V17"/></svg>Data Buku</a>
<a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="9" cy="8" r="3.2"/><path d="M3.5 19c.6-3 2.8-4.6 5.5-4.6s4.9 1.6 5.5 4.6"/><circle cx="17" cy="9" r="2.4"/><path d="M15.5 14.6c2.3.2 4 1.6 4.5 4"/></svg>Anggota</a>
<a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6V3z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></svg>Laporan</a>
</nav>
<!-- COPY: bottomnav-end -->
<script>"use strict";document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});
function toggleModePilihBuku(aktif){
  var daftar=document.getElementById("daftar-buku");
  if(!daftar) return;
  daftar.classList.toggle("bulk-select-mode", !!aktif);
  ["btnCentangSemua","btnBatalCentang","btnHapusTerpilih","btnSelesaiPilih","jumlahBukuDipilih"].forEach(function(id){
    var el=document.getElementById(id); if(el) el.hidden=!aktif;
  });
  var btn=document.getElementById("btnPilihBuku"); if(btn) btn.hidden=!!aktif;
  if(!aktif){
    document.querySelectorAll(".buku-pilihan").forEach(function(cb){cb.checked=false;});
    var master=document.getElementById("checkSemuaBuku"); if(master) master.checked=false;
  }
  perbaruiJumlahBukuDipilih();
}
function centangSemuaBuku(centang){
  if(typeof centang!=="boolean") centang=true;
  document.querySelectorAll(".buku-pilihan").forEach(function(cb){cb.checked=centang;});
  var master=document.getElementById("checkSemuaBuku"); if(master) master.checked=centang;
  perbaruiJumlahBukuDipilih();
}
function batalCentangSemuaBuku(){centangSemuaBuku(false);}
function perbaruiJumlahBukuDipilih(){
  var boxes=Array.from(document.querySelectorAll(".buku-pilihan"));
  var terpilih=boxes.filter(function(cb){return cb.checked;}).length;
  var label=document.getElementById("jumlahBukuDipilih");
  if(label) label.textContent=terpilih+" buku dipilih";
  var master=document.getElementById("checkSemuaBuku");
  if(master){master.checked=boxes.length>0 && terpilih===boxes.length;master.indeterminate=terpilih>0 && terpilih<boxes.length;}
  var hapus=document.getElementById("btnHapusTerpilih"); if(hapus) hapus.disabled=terpilih===0;
}
function confirmHapusBukuTerpilih(){
  var total=document.querySelectorAll(".buku-pilihan:checked").length;
  if(total===0){alert("Pilih minimal satu buku terlebih dahulu.");return false;}
  return confirm("Hapus "+total+" buku yang dipilih? Buku yang masih dipinjam akan tetap dipertahankan.");
}
function syncHapusFilter(form){
  var k=document.getElementById("selFilterKat"),s=document.getElementById("selFilterStatus");
  var hk=document.getElementById("hapusFilterKat"),hs=document.getElementById("hapusFilterStatus");
  if(hk) hk.value = k ? k.value : "";
  if(hs) hs.value = s ? s.value : "";
  var label=(k&&k.options[k.selectedIndex]?k.options[k.selectedIndex].text:"Semua Kategori")+" / "+(s&&s.options[s.selectedIndex]?s.options[s.selectedIndex].text:"Semua Status");
  return confirm("Hapus buku sesuai filter: "+label+"?");
}</script>

</body>
</html>
