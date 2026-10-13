<?php
session_start();
require_once 'koneksi.php';

// Mode katalog berdasarkan pintu masuk portal dan sesi pengguna.
// - Pengunjung yang belum login tetap mendapatkan katalog publik.
// - Jika admin/petugas membuka index.php dari modul mana pun tanpa parameter,
//   katalog otomatis menjadi katalog admin.
// - Jika mahasiswa membuka index.php tanpa parameter, katalog otomatis menjadi
//   katalog mahasiswa.
// - Parameter portal tetap didukung untuk link yang sudah ada.
$is_logged_session = isset($_SESSION['login']) && $_SESSION['login'] === true;
$role_session = strtolower(trim((string)($_SESSION['role'] ?? '')));
$is_admin_session = isset($_SESSION['admin']) && trim((string)$_SESSION['admin']) !== '';
$is_admin_session = $is_admin_session ||
    in_array($role_session, ['admin', 'petugas'], true) ||
    strpos($role_session, 'admin') !== false ||
    strpos($role_session, 'petugas') !== false;

$requested_portal = isset($_GET['portal']) ? strtolower(trim($_GET['portal'])) : '';

if ($requested_portal === 'admin' && $is_admin_session) {
    $catalog_portal = 'admin';
} elseif ($requested_portal === 'mahasiswa' && $is_logged_session && !$is_admin_session) {
    $catalog_portal = 'mahasiswa';
} elseif ($requested_portal === '' && $is_admin_session) {
    // Penting: link lama dari peminjaman/pengembalian yang masih menuju
    // index.php tanpa ?portal=admin tetap membuka katalog admin.
    $catalog_portal = 'admin';
} elseif ($requested_portal === '' && $is_logged_session && !$is_admin_session) {
    $catalog_portal = 'mahasiswa';
} else {
    // Belum login = katalog publik.
    $catalog_portal = '';
}

// ----------------------------------------------------------------------
// 1. DETEKSI STRUKTUR KOLOM TB_BUKU SECARA OTOMATIS
// ----------------------------------------------------------------------
$cols_buku = [];
$res_cols = mysqli_query($koneksi, "SHOW COLUMNS FROM tb_buku");
if ($res_cols) {
    while ($c = mysqli_fetch_assoc($res_cols)) {
        $cols_buku[] =$c['Field'];
    }
}

// Penentuan nama kolom secara dinamis sesuai database
$col_id    = in_array('id_buku',$cols_buku) ? 'id_buku' : (in_array('id', $cols_buku) ? 'id' : ($cols_buku[0] ?? '1'));
$col_judul = in_array('judul_buku',$cols_buku) ? 'judul_buku' : (in_array('judul', $cols_buku) ? 'judul' : 'judul_buku');$col_kode  = in_array('kode_buku', $cols_buku) ? 'kode_buku' : (in_array('kode',$cols_buku) ? 'kode' : 'kode_buku');
$col_stok  = in_array('stok',$cols_buku) ? 'stok' : (in_array('jumlah', $cols_buku) ? 'jumlah' : (in_array('jumlah_buku',$cols_buku) ? 'jumlah_buku' : 'stok'));
$col_tahun = in_array('tahun_terbit',$cols_buku) ? 'tahun_terbit' : (in_array('tahun', $cols_buku) ? 'tahun' : 'tahun_terbit');$col_rak   = in_array('lokasi_rak', $cols_buku) ? 'lokasi_rak' : (in_array('rak',$cols_buku) ? 'rak' : 'lokasi_rak');

// Cek keberadaan tabel tb_kategori
$has_tb_kategori = false;
$res_tbl_kat = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_kategori'");
if ($res_tbl_kat && mysqli_num_rows($res_tbl_kat) > 0) {$has_tb_kategori = true;
}

// ----------------------------------------------------------------------
// 2. PROSES PENCARIAN & FILTER KATEGORI
// ----------------------------------------------------------------------
$search     = isset($_GET['search']) ? mysqli_real_escape_string($koneksi, trim($_GET['search'])) : '';$kat_filter = isset($_GET['kategori']) ? (int)$_GET['kategori'] : 0;

$where_clauses = [];
if (!empty($search)) {$s_fields = [];
    if (in_array($col_judul, $cols_buku))$s_fields[] = "tb_buku.`$col_judul` LIKE '%$search%'";
    if (in_array($col_kode, $cols_buku))$s_fields[] = "tb_buku.`$col_kode` LIKE '%$search%'";
    if (in_array('pengarang', $cols_buku))$s_fields[] = "tb_buku.`pengarang` LIKE '%$search%'";
    if (in_array('penerbit', $cols_buku))$s_fields[] = "tb_buku.`penerbit` LIKE '%$search%'";
    
    if (!empty($s_fields)) {
        $where_clauses[] = "(" . implode(" OR ", $s_fields) . ")";
    }
}

if ($kat_filter > 0 && in_array('id_kategori',$cols_buku)) {
    $where_clauses[] = "tb_buku.id_kategori = '$kat_filter'";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// ----------------------------------------------------------------------
// 3. FETCH DATA BUKU DARI DATABASE
// ----------------------------------------------------------------------
// Sort fungsional via ?sort= (baru|lama|judul|stok). Default: terbaru.
$sort_key = isset($_GET['sort']) ? strtolower(trim($_GET['sort'])) : 'baru';
$sort_map = [
    'baru'  => "tb_buku.`$col_id` DESC",
    'lama'  => "tb_buku.`$col_id` ASC",
    'judul' => "tb_buku.`$col_judul` ASC",
    'stok'  => "tb_buku.`$col_stok` DESC",
];
$order_sql = isset($sort_map[$sort_key]) ? $sort_map[$sort_key] : $sort_map['baru'];

if ($has_tb_kategori && in_array('id_kategori', $cols_buku)) {$query_sql = "SELECT tb_buku.*, tb_kategori.nama_kategori 
                  FROM tb_buku 
                  LEFT JOIN tb_kategori ON tb_buku.id_kategori = tb_kategori.id_kategori 
                  $where_sql 
                  ORDER BY $order_sql";
} else {
    $query_sql = "SELECT tb_buku.* FROM tb_buku $where_sql ORDER BY $order_sql";
}

$res_buku = mysqli_query($koneksi,$query_sql);

// ----------------------------------------------------------------------
// PAGINATION katalog (mirip mockup: ada tombol halaman 2,3,4,5)
// ----------------------------------------------------------------------
$per_page = 12;
$page_now = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$total_buku_katalog = 0;
$count_sql = $has_tb_kategori && in_array('id_kategori', $cols_buku)
    ? "SELECT COUNT(*) AS total FROM tb_buku LEFT JOIN tb_kategori ON tb_buku.id_kategori = tb_kategori.id_kategori $where_sql"
    : "SELECT COUNT(*) AS total FROM tb_buku $where_sql";
$q_count = mysqli_query($koneksi, $count_sql);
if ($q_count) { $total_buku_katalog = (int)(mysqli_fetch_assoc($q_count)['total'] ?? 0); }
$total_pages = max(1, (int)ceil($total_buku_katalog / $per_page));
if ($page_now > $total_pages) { $page_now = $total_pages; }
$offset = ($page_now - 1) * $per_page;
// Query ulang dengan LIMIT sesuai halaman aktif.
$query_paged = $query_sql . " LIMIT $per_page OFFSET $offset";
$res_buku = mysqli_query($koneksi, $query_paged);

// Hitung jumlah eksemplar yang sedang dipinjam untuk setiap kode buku.
// Nilai stok pada tb_buku adalah stok yang saat ini tersedia karena modul
// peminjaman menguranginya dan modul pengembalian menambahkannya kembali.
$dipinjam_per_kode = [];
$has_tb_peminjaman = false;
$res_tbl_peminjaman = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_peminjaman'");
if ($res_tbl_peminjaman && mysqli_num_rows($res_tbl_peminjaman) > 0) {
    $has_tb_peminjaman = true;
}
if ($has_tb_peminjaman) {
    $q_dipinjam = mysqli_query($koneksi, "SELECT kode_buku, COUNT(*) AS jumlah_dipinjam
        FROM tb_peminjaman
        WHERE LOWER(status_pinjam) = 'dipinjam' OR status_pinjam IS NULL OR status_pinjam = ''
        GROUP BY kode_buku");
    if ($q_dipinjam) {
        while ($r_dipinjam = mysqli_fetch_assoc($q_dipinjam)) {
            $dipinjam_per_kode[(string)$r_dipinjam['kode_buku']] = (int)$r_dipinjam['jumlah_dipinjam'];
        }
    }
}

// Ambil Kategori untuk Dropdown Filter
$res_kategori = false;
if ($has_tb_kategori) {
    $res_kategori = mysqli_query($koneksi, "SELECT * FROM tb_kategori ORDER BY nama_kategori ASC");
}

// Data Sesi Login User
$is_logged_in = isset($_SESSION['login']) && $_SESSION['login'] === true;
$role         = $_SESSION['role'] ?? 'guest';
$nama_user    = $_SESSION['nama'] ?? 'Pengunjung';
$username_val = $_SESSION['username'] ?? '';

// PENTING: index.php tanpa parameter portal adalah KATALOG PUBLIK.
// Walaupun ada sesi admin/mahasiswa yang sedang aktif, katalog publik
// harus tetap tampil seperti sebelum login. Tampilan admin/mahasiswa
// hanya boleh aktif jika pintu masuknya menyertakan portal=admin/mahasiswa.
$is_public_catalog = ($catalog_portal === '');

$role_lower = strtolower(trim((string)$role));
$is_admin_petugas = (!$is_public_catalog && $catalog_portal === 'admin') ||
    (!$is_public_catalog && in_array($role_lower, ['petugas', 'admin'], true)) ||
    (!$is_public_catalog && strpos($role_lower, 'petugas') !== false) ||
    (!$is_public_catalog && strpos($role_lower, 'admin') !== false) ||
    (!$is_public_catalog && $is_admin_session);

// Di katalog publik, identitas sesi tidak ditampilkan.
$show_logged_user = (!$is_public_catalog && $is_logged_in);
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Katalog Perpustakaan UIN Datokarama Palu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#064c40;--brand-dark:#04382f;--brand-2:#085f50;--ink:#1f2a2e;--muted:#8a99a0;--line:#e8eeeb;--bg:#f4f8f6}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);-webkit-tap-highlight-color:transparent}
/* ===== DESKTOP NAV ===== */
.topnav{display:flex;align-items:center;gap:26px;background:#fff;padding:14px 42px;border-bottom:1px solid var(--line)}
.brand{display:flex;align-items:center;gap:11px;text-decoration:none;color:var(--ink)}
.brand .logo{width:42px;height:42px;object-fit:contain;flex:none}
.brand b{display:block;font-size:15px;line-height:1.2;color:var(--brand)}
.brand small{display:block;font-size:10.5px;color:var(--muted);font-weight:600}
.top-links{margin-left:auto;display:flex;align-items:center;gap:22px;flex-wrap:wrap}
.top-links a{text-decoration:none;color:#5b6e76;font-size:13.5px;font-weight:600}
.top-links a.on{color:var(--brand);border-bottom:2.5px solid var(--brand);padding-bottom:3px}
.btn-masuk{display:flex;align-items:center;gap:7px;border:1.5px solid var(--brand);color:var(--brand);border-radius:999px;padding:8px 18px;font:inherit;font-size:13px;font-weight:700;text-decoration:none}
.btn-masuk svg{width:16px;height:16px}
.btn-outline-dark{display:flex;align-items:center;gap:7px;background:var(--brand);color:#fff;border-radius:10px;padding:9px 15px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none}
.btn-outline-dark svg{width:16px;height:16px}
.btn-blue{background:#1e70eb;color:#fff;border-radius:999px;padding:9px 18px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none}
.btn-ghost{border:1.5px solid #cbd5e1;color:#475569;border-radius:999px;padding:8px 16px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none}
.btn-logout{display:flex;align-items:center;gap:7px;background:#e5484d;color:#fff;border-radius:10px;padding:9px 16px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none}
.btn-logout svg{width:15px;height:15px}
.user-chip{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#334155}
.user-chip svg{width:18px;height:18px}
/* Tombol navigasi admin mengikuti desain referensi */
.top-links .btn-outline-dark{background:var(--brand);color:#fff;border:1px solid var(--brand);border-radius:11px;padding:10px 16px;white-space:nowrap}
.top-links .admin-chip{display:flex;align-items:center;gap:8px;border:1px solid #cbd5e1;border-radius:10px;padding:10px 14px;color:#334155;font-size:13px;font-weight:600;white-space:nowrap}
.top-links .admin-chip svg{width:17px;height:17px;flex:none}
.top-links .btn-logout{background:#e52329;color:#fff;border:1px solid #e52329;border-radius:10px;padding:10px 17px;white-space:nowrap}
.top-links .btn-logout:hover{background:#c91d23;border-color:#c91d23}
@media(max-width:900px){.topnav{padding:12px 20px;gap:14px}.top-links{gap:10px}.top-links .admin-chip{font-size:12px;padding:8px 10px}.top-links .btn-outline-dark,.top-links .btn-logout{font-size:12px;padding:8px 10px}}
/* ===== HERO ===== */
.hero{background:linear-gradient(120deg,#04382f,#064c40);color:#fff;padding:38px 40px 42px;position:relative;overflow:hidden;border-radius:16px;margin:24px auto 0;max-width:1180px;box-shadow:0 6px 22px rgba(6,76,64,.14)}
.hero h1{font-size:26px;font-weight:800;display:flex;align-items:center;gap:10px}
.hero p{font-size:13.5px;color:#cfe3d8;margin:8px 0 22px;max-width:560px}
.hero-art{position:absolute;right:34px;top:0;height:100%;opacity:.92;pointer-events:none}
.hero-art img{height:118%;object-fit:contain}
.searchbar{position:relative;z-index:1;display:flex;align-items:center;gap:8px;background:#fff;border-radius:12px;padding:6px 6px 6px 16px;max-width:640px;box-shadow:0 6px 18px rgba(0,0,0,.16)}
.searchbar>svg{width:19px;height:19px;color:#7b8a90;flex:none}
.searchbar input{flex:1;border:0;outline:none;font:inherit;font-size:13.5px;color:var(--ink);background:transparent;min-width:0}
.searchbar select{border:0;background:transparent;font:inherit;font-size:13px;color:#475569;padding:0 8px;outline:none;max-width:150px}
.btn-cari{display:flex;align-items:center;gap:8px;background:var(--brand);color:#fff;border:0;border-radius:8px;padding:11px 22px;font:inherit;font-size:13.5px;font-weight:700;cursor:pointer}
.btn-cari svg{width:16px;height:16px}
/* ===== WRAP ===== */
.wrap{max-width:1180px;margin:0 auto;padding:24px 26px 60px}
.cats{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:20px}
.cat{display:flex;align-items:center;gap:7px;border:1.5px solid var(--line);background:#fff;border-radius:999px;padding:8px 18px;font:inherit;font-size:12.5px;font-weight:600;color:#4c626b;cursor:pointer;text-decoration:none}
.cat svg{width:16px;height:16px;color:var(--brand)}
.cat.on{background:var(--brand);border-color:var(--brand);color:#fff}
.cat.on svg{color:#fff}
.sec-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:18px}
.sec-head h2{font-size:19px;font-weight:800;display:flex;align-items:center;gap:10px;color:#1f2937}
.sec-head h2 svg{width:22px;height:22px;color:var(--brand)}
.sortbox{display:inline-flex;align-items:center;gap:6px;background:#fff;border:1px solid var(--line);border-radius:8px;padding:7px 12px;font-size:12px;font-weight:600;color:#475569;cursor:pointer;text-decoration:none}
.sortbox select{border:0;background:transparent;font:inherit;font-weight:600;color:#0f172a;outline:none;cursor:pointer;padding:0;appearance:none;-webkit-appearance:none}
.sortbox svg{width:14px;height:14px;color:#94a3b8;pointer-events:none}
.sortform{margin:0}
.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:16px}
.card{background:#fff;border-radius:12px;padding:12px;box-shadow:0 1px 3px rgba(6,76,64,.05);display:flex;align-items:center;gap:13px;border:1px solid var(--line);transition:box-shadow .2s ease}
.card:hover{box-shadow:0 6px 20px rgba(6,76,64,.09)}
.cover{width:80px;height:112px;flex:none;border-radius:8px;background:linear-gradient(150deg,#0e4a30,#04382f);overflow:hidden;display:grid;place-items:center;position:relative}
.cover::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:#04362d}
.cover img{width:100%;height:100%;object-fit:cover}
.card .info{display:flex;flex-direction:column;justify-content:space-between;flex:1;min-width:0;height:112px;padding:1px 0}
.card h3{font-size:12.5px;font-weight:800;line-height:1.3;color:#111827;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.card .au{font-size:11px;color:var(--muted);margin:2px 0}
.card .kt{font-size:10.5px;color:var(--brand);font-weight:700}
.card .st{font-size:10.5px;color:#059669;font-weight:600;margin:0 0 6px}
.card .st::before{content:"●";margin-right:5px;font-size:8px}
.card .st.habis{color:#c4343a}
.badge-kat{display:none}
.btn-detail{align-self:flex-start;display:inline-flex;align-items:center;gap:5px;border:1px solid #e2e8f0;color:#4b5563;border-radius:6px;padding:4px 10px;font:inherit;font-size:10px;font-weight:600;background:#fff;cursor:pointer;text-decoration:none}
.btn-detail:hover{border-color:var(--brand);color:var(--brand)}
.btn-detail svg{width:12px;height:12px}
.empty{grid-column:1/-1;text-align:center;padding:60px 20px;background:#fff;border-radius:16px;border:1px solid var(--line);color:var(--muted)}
.pager{display:flex;justify-content:center;align-items:center;gap:6px;margin-top:26px;flex-wrap:wrap}
.pager a,.pager span{min-width:28px;height:28px;padding:0 8px;display:grid;place-items:center;border-radius:6px;background:#fff;border:1px solid var(--line);font-size:12px;font-weight:600;color:#4c626b;text-decoration:none}
.pager .on{background:var(--brand);border-color:var(--brand);color:#fff}
/* ===== MOBILE ===== */
.bottomnav{display:none}
.mob-search{display:none}
.mob-topbar{display:none}
@media(max-width:1024px){.grid{grid-template-columns:repeat(3,minmax(0,1fr))}.hero{margin:18px 20px 0}}
  @media(max-width:768px){
  .topnav,.hero-art{display:none}
  body{background:#f4f8f6}
  .mob-topbar{display:block;background:linear-gradient(160deg,#04382f,#064c40);color:#fff;border-radius:0 0 28px 28px;padding:16px 18px 40px;position:relative;overflow:hidden}
  .mob-topbar .row{display:flex;align-items:center;gap:10px}
  .mob-topbar .logo{width:36px;height:36px;object-fit:contain;background:rgba(255,255,255,.9);border-radius:9px;padding:3px;flex:none}
  .mob-topbar b{display:block;font-size:13px;line-height:1.15}
  .mob-topbar small{font-size:9.5px;color:#bcdccf}
  .mob-links{margin-left:auto;display:flex;align-items:center;gap:11px;font-size:11px;font-weight:600}
  .mob-links a{color:rgba(255,255,255,.82);text-decoration:none}
  .mob-links a.on{color:#fff;border-bottom:2px solid #fff;padding-bottom:2px}
  .mob-links .btn-masuk{border:1px solid rgba(255,255,255,.35);border-radius:999px;padding:5px 12px;font-size:10.5px;color:#fff;display:flex;align-items:center;gap:5px}
  .mob-links .btn-masuk svg{width:13px;height:13px}
  .mob-links .btn-back{display:flex;align-items:center;gap:5px;background:#04382f;border-radius:8px;padding:4px 9px;font-size:9px;line-height:1.05;color:#fff;font-weight:600;text-align:left}
  .mob-links .btn-back svg{width:14px;height:14px;flex:none}
  .hero{display:block;margin:14px 14px 0;padding:22px 18px 26px;border-radius:18px;max-width:none}
  .hero h1{font-size:19px}
  .hero p{font-size:12px;margin:6px 0 14px}
  .hero-art{display:none}
  .searchbar{max-width:100%;border-radius:12px;box-shadow:0 6px 16px rgba(0,0,0,.14)}
  .btn-cari{padding:10px 16px;font-size:12px}
  .wrap{padding:14px 14px 110px}
  .mob-search{display:none}
  .mob-search{background:#fff;border-radius:999px;padding:6px;box-shadow:0 8px 22px rgba(8,60,44,.16);display:flex;align-items:center;gap:6px}
  .mob-search svg{width:18px;height:18px;color:#9ca3af;margin-left:10px;flex:none}
  .mob-search input{flex:1;border:0;outline:none;font:inherit;font-size:12.5px;min-width:0;background:transparent}
  .mob-search button{border:0;background:var(--brand-dark);color:#fff;border-radius:999px;padding:9px 15px;font:inherit;font-size:11.5px;font-weight:700;display:flex;align-items:center;gap:5px;cursor:pointer;flex:none}
  .mob-search button svg{width:13px;height:13px;margin:0;color:#fff}
  .cats{margin-top:14px;gap:8px}
  .cat{padding:7px 13px;font-size:11px;border-radius:999px}
  .sec-head{margin-top:16px;margin-bottom:12px}
  .sec-head h2{font-size:16px}
  .grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:11px}
  .card{flex-direction:column;align-items:stretch;gap:8px;padding:11px;border-radius:16px}
  .card .cover{width:100%;height:auto;aspect-ratio:3/4;margin-bottom:2px;border-radius:9px}
  .card .info{height:auto;min-height:0;gap:8px}
  .card h3{font-size:12px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
  .card .au{font-size:10.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .badge-kat{font-size:9px;padding:2px 7px}
  .card .st{font-size:10px;margin:3px 0 8px}
  .btn-detail{font-size:10.5px;padding:6px 10px;border-radius:999px;align-self:stretch;justify-content:center}
  .pager a,.pager span{min-width:32px;height:32px;font-size:12px}
  .bottomnav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:60;background:#fff;border-top:1px solid var(--line);padding:9px 10px calc(9px + env(safe-area-inset-bottom,0px));justify-content:space-around;align-items:center;box-shadow:0 -4px 18px rgba(8,60,44,.07)}
  .bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:#93a3aa;font-size:10.5px;font-weight:600;padding:4px 0}
  .bottomnav a svg{width:23px;height:23px}
  .bottomnav a.on{color:var(--brand);font-weight:800}
  .bottomnav a.on::after{content:"";width:30px;height:3px;border-radius:99px;background:var(--brand);margin-top:1px}
}

/* Modal detail buku: tambahan fitur, layout katalog tetap dipertahankan */
.detail-overlay{position:fixed;inset:0;background:rgba(15,23,42,.52);backdrop-filter:blur(3px);display:none;align-items:center;justify-content:center;padding:20px;z-index:9999}
.detail-overlay.show{display:flex}
.detail-modal{position:relative;width:min(560px,100%);max-height:min(88vh,760px);overflow:auto;background:#fff;border-radius:20px;box-shadow:0 24px 80px rgba(0,0,0,.25);padding:28px;color:#1f2a2e;animation:detailPop .18s ease-out}
@keyframes detailPop{from{opacity:0;transform:translateY(8px) scale(.98)}to{opacity:1;transform:translateY(0) scale(1)}}
.detail-close-x{position:absolute;right:16px;top:14px;width:34px;height:34px;border:0;border-radius:50%;background:#f1f5f4;color:#52645f;font-size:22px;cursor:pointer}
.detail-main{display:grid;grid-template-columns:145px minmax(0,1fr);gap:24px;align-items:start;padding-top:8px}
.detail-cover{width:145px;height:205px;object-fit:cover;border-radius:10px;box-shadow:0 5px 18px rgba(0,0,0,.13);background:#edf3f0}
.detail-kat{display:inline-block;background:#e8f4ef;color:#08604e;border-radius:999px;padding:5px 10px;font-size:11px;font-weight:700;margin-bottom:10px}
.detail-title{font-size:22px;line-height:1.3;margin:0 28px 16px 0;color:#172b27}
.detail-fields{display:grid;grid-template-columns:110px minmax(0,1fr);gap:11px 12px;font-size:13px;line-height:1.45}
.detail-fields dt{color:#7b8985}.detail-fields dd{margin:0;font-weight:600;overflow-wrap:anywhere}
.detail-availability{display:inline-block;background:#dcfce7;color:#16804a;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:700}
.detail-availability.out{background:#fee2e2;color:#b91c1c}
.detail-footer{display:flex;justify-content:flex-end;margin-top:24px}
.detail-close-btn{border:0;border-radius:9px;background:var(--brand);color:#fff;padding:10px 22px;font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.detail-close-btn:hover{background:var(--brand-dark)}
@media(max-width:520px){.detail-overlay{padding:12px}.detail-modal{padding:22px 18px;border-radius:16px}.detail-main{grid-template-columns:100px minmax(0,1fr);gap:15px}.detail-cover{width:100px;height:145px}.detail-title{font-size:18px}.detail-fields{grid-template-columns:90px minmax(0,1fr);font-size:12px;gap:10px}.detail-footer{margin-top:18px}}

</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<?php $portal_q = $catalog_portal !== '' ? '?portal=' . urlencode($catalog_portal) : ''; ?>
<!-- DESKTOP NAV -->
<nav class="topnav">
  <a class="brand" href="index.php<?= $portal_q; ?>">
    <img class="logo" src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
    <span><b>Perpustakaan</b><small>UIN Datokarama Palu</small></span>
  </a>
  <div class="top-links">
    <?php if ($is_public_catalog): ?>
      <a href="index.php" class="on">Beranda</a>
      <a href="index.php">Katalog</a>
      <a class="btn-masuk" href="login.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Masuk</a>
    <?php elseif ($is_admin_petugas): ?>
      <a href="index.php?portal=admin" class="on">Katalog</a>
      <a class="btn-outline-dark" href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 15l2 2 5-5"/></svg>Kembali ke Dashboard</a>
      <span class="admin-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="4"/><path d="M5 21a7 7 0 0 1 14 0"/></svg>Admin: Petugas Perpustakaan</span>
      <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari akun admin?')">Logout</a>
    <?php else: ?>
      <span class="user-chip"><?= htmlspecialchars($nama_user); ?></span>
      <a class="btn-blue" href="riwayat.php">Riwayat Saya</a>
      <a class="btn-ghost" href="ganti_password.php">Ganti Password</a>
      <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')">Logout</a>
    <?php endif; ?>
  </div>
</nav>

<!-- MOBILE TOPBAR -->
<div class="mob-topbar">
  <div class="row">
    <img class="logo" src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
    <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
    <div class="mob-links">
      <?php if ($is_public_catalog): ?>
        <a href="index.php">Beranda</a><a href="index.php" class="on">Katalog</a>
        <a class="btn-masuk" href="login.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>Masuk</a>
      <?php elseif ($is_admin_petugas): ?>
        <a class="btn-back" href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" transform="rotate(180 12 12)"/></svg><span>Kembali<br>Ke Dashboard</span></a>
        <a class="btn-masuk" href="logout.php" onclick="return confirm('Keluar?')">Logout</a>
      <?php else: ?>
        <a href="riwayat.php">Riwayat</a>
        <a class="btn-masuk" href="logout.php" onclick="return confirm('Keluar?')">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</div>

<!-- HERO (desktop) -->
<header class="hero">
  <h1>Temukan Buku Favoritmu 📖</h1>
  <p>Cari dan temukan koleksi buku Perpustakaan UIN Datokarama Palu.</p>
  <form class="searchbar" action="index.php" method="GET">
    <?php if ($catalog_portal !== ''): ?><input type="hidden" name="portal" value="<?= htmlspecialchars($catalog_portal, ENT_QUOTES); ?>"><?php endif; ?>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>
    <input type="text" name="search" placeholder="Cari judul buku, penulis, atau ISBN..." value="<?= htmlspecialchars($search); ?>">
    <select name="kategori" aria-label="Kategori">
      <option value="0">Semua Kategori</option>
      <?php if ($res_kategori && mysqli_num_rows($res_kategori) > 0): while ($kat = mysqli_fetch_assoc($res_kategori)): ?>
        <option value="<?= (int)$kat['id_kategori']; ?>" <?= $kat_filter == $kat['id_kategori'] ? 'selected' : ''; ?>><?= htmlspecialchars($kat['nama_kategori']); ?></option>
      <?php endwhile; endif; ?>
    </select>
    <button class="btn-cari" type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>Cari</button>
  </form>
  <span class="hero-art"><img src="assets/logo-bunga.png" alt=""></span>
</header>

<div class="wrap">
  <div class="wrap">
  <form class="mob-search" action="index.php" method="GET">
    <?php if ($catalog_portal !== ''): ?><input type="hidden" name="portal" value="<?= htmlspecialchars($catalog_portal, ENT_QUOTES); ?>"><?php endif; ?>
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>
    <input type="text" name="search" placeholder="Cari judul buku, penulis, atau ISBN..." value="<?= htmlspecialchars($search); ?>">
    <button type="submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>Cari</button>
  </form>

  <div class="cats">
    <a class="cat <?= $kat_filter == 0 ? 'on' : ''; ?>" href="index.php<?= $portal_q; ?>">Semua</a>
    <?php if ($res_kategori && mysqli_num_rows($res_kategori) > 0): mysqli_data_seek($res_kategori, 0); while ($kat = mysqli_fetch_assoc($res_kategori)): ?>
      <a class="cat <?= $kat_filter == $kat['id_kategori'] ? 'on' : ''; ?>" href="index.php?<?= $catalog_portal !== '' ? 'portal=' . urlencode($catalog_portal) . '&amp;' : ''; ?>kategori=<?= (int)$kat['id_kategori']; ?>"><?= htmlspecialchars($kat['nama_kategori']); ?></a>
    <?php endwhile; endif; ?>
  </div>
  <div class="sec-head">
    <h2><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg><?= !empty($search) ? 'Hasil Pencarian' : 'Koleksi Buku'; ?></h2>
    <form class="sortform" action="index.php" method="GET">
      <?php if ($catalog_portal !== ''): ?><input type="hidden" name="portal" value="<?= htmlspecialchars($catalog_portal, ENT_QUOTES); ?>"><?php endif; ?>
      <?php if (!empty($search)): ?><input type="hidden" name="search" value="<?= htmlspecialchars($search, ENT_QUOTES); ?>"><?php endif; ?>
      <?php if ($kat_filter > 0): ?><input type="hidden" name="kategori" value="<?= (int)$kat_filter; ?>"><?php endif; ?>
      <label class="sortbox" for="sortSel">Urutkan:
        <select id="sortSel" name="sort" onchange="this.form.submit()" aria-label="Urutkan buku">
          <option value="baru"  <?= $sort_key === 'baru'  ? 'selected' : ''; ?>>Terbaru</option>
          <option value="lama"  <?= $sort_key === 'lama'  ? 'selected' : ''; ?>>Terlama</option>
          <option value="judul" <?= $sort_key === 'judul' ? 'selected' : ''; ?>>Judul A-Z</option>
          <option value="stok"  <?= $sort_key === 'stok'  ? 'selected' : ''; ?>>Stok Terbanyak</option>
        </select>
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 9l-7 7-7-7"/></svg>
      </label>
    </form>
  </div>
  <div class="grid">
    <?php if ($res_buku && mysqli_num_rows($res_buku) > 0): while ($buku = mysqli_fetch_assoc($res_buku)):
      $stok_val  = max(0, (int)($buku[$col_stok] ?? $buku['stok'] ?? 0));
      $judul_val = $buku[$col_judul] ?? $buku['judul_buku'] ?? 'Tanpa Judul';
      $kode_val  = $buku[$col_kode] ?? $buku['kode_buku'] ?? '-';
      $kat_nama  = $buku['nama_kategori'] ?? $buku['kategori'] ?? 'Umum';
      $gambar_db = trim($buku['gambar'] ?? '');
      $img_src = (!empty($gambar_db) && file_exists('uploads/' . $gambar_db)) ? 'uploads/' . $gambar_db : 'uploads/default.png';
    ?>
    <article class="card">
      <div class="cover"><img src="<?= htmlspecialchars($img_src); ?>" alt="<?= htmlspecialchars($judul_val); ?>"></div>
      <div class="info">
        <div>
          <h3><?= htmlspecialchars($judul_val); ?></h3>
          <p class="au"><?= htmlspecialchars($buku['pengarang'] ?? '-'); ?></p>
          <span class="kt"><?= htmlspecialchars($kat_nama); ?></span>
        </div>
        <div>
          <?php if ($stok_val > 0): ?><p class="st">Tersedia</p>
          <?php else: ?><p class="st habis">Belum tersedia</p><?php endif; ?>
          <button type="button" class="btn-detail js-detail-buku"
            data-judul="<?= htmlspecialchars($judul_val, ENT_QUOTES, 'UTF-8'); ?>"
            data-gambar="<?= htmlspecialchars($img_src, ENT_QUOTES, 'UTF-8'); ?>"
            data-kategori="<?= htmlspecialchars($kat_nama, ENT_QUOTES, 'UTF-8'); ?>"
            data-pengarang="<?= htmlspecialchars($buku['pengarang'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>"
            data-penerbit="<?= htmlspecialchars($buku['penerbit'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>"
            data-kode="<?= htmlspecialchars($kode_val, ENT_QUOTES, 'UTF-8'); ?>"
            data-rak="<?= htmlspecialchars($buku[$col_rak] ?? $buku['lokasi_rak'] ?? $buku['rak'] ?? '-', ENT_QUOTES, 'UTF-8'); ?>"
            data-tahun="<?= htmlspecialchars((string)($buku[$col_tahun] ?? $buku['tahun_terbit'] ?? $buku['tahun'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?>"
            data-dipinjam="<?= (int)($dipinjam_per_kode[(string)$kode_val] ?? 0); ?>"
            data-stok="<?= $stok_val; ?>">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>Lihat Detail
          </button>
        </div>
      </div>
    </article>
    <?php endwhile; else: ?>
    <div class="empty"><p style="font-size:16px;font-weight:600">Buku tidak ditemukan.</p><p style="font-size:13px">Belum ada koleksi buku terdaftar atau coba kata kunci lain.</p></div>
    <?php endif; ?>
  </div>
  <?php if ($total_pages > 1): ?>
  <div class="pager">
    <?php
      $qbase = [];
      if ($catalog_portal !== '') { $qbase['portal'] = $catalog_portal; }
      if (!empty($search)) { $qbase['search'] = $search; }
      if ($kat_filter > 0) { $qbase['kategori'] = $kat_filter; }
      if ($sort_key !== 'baru') { $qbase['sort'] = $sort_key; }
      $mkurl = function($p) use ($qbase) { $q = $qbase; $q['page'] = $p; return 'index.php?' . http_build_query($q); };
    ?>
    <?php if ($page_now > 1): ?><a href="<?= htmlspecialchars($mkurl($page_now - 1)); ?>">&lsaquo;</a><?php endif; ?>
    <?php for ($p = 1; $p <= $total_pages; $p++): ?>
      <?php if ($p == $page_now): ?><span class="on"><?= $p; ?></span>
      <?php else: ?><a href="<?= htmlspecialchars($mkurl($p)); ?>"><?= $p; ?></a><?php endif; ?>
    <?php endfor; ?>
    <?php if ($page_now < $total_pages): ?><a href="<?= htmlspecialchars($mkurl($page_now + 1)); ?>">&rsaquo;</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="index.php?portal=mahasiswa" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Beranda</a>
  <a href="riwayat.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Riwayat</a>
  <a href="ganti_password.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>Akun</a>
</nav>

<!-- Modal detail buku berada di tengah layar -->
<div class="detail-overlay" id="detailBukuOverlay" aria-hidden="true">
  <section class="detail-modal" role="dialog" aria-modal="true" aria-labelledby="detailBukuJudul">
    <button type="button" class="detail-close-x" id="detailBukuX" aria-label="Tutup">&times;</button>
    <div class="detail-main">
      <img class="detail-cover" id="detailBukuGambar" src="uploads/default.png" alt="Sampul buku">
      <div>
        <span class="detail-kat" id="detailBukuKategori">Kategori</span>
        <h2 class="detail-title" id="detailBukuJudul">Judul Buku</h2>
        <dl class="detail-fields">
          <dt>Pengarang</dt><dd id="detailBukuPengarang">-</dd>
          <dt>Penerbit</dt><dd id="detailBukuPenerbit">-</dd>
          <dt>Kode Buku</dt><dd id="detailBukuKode">-</dd>
          <dt>Lokasi Rak</dt><dd id="detailBukuRak">-</dd>
          <dt>Tahun Terbit</dt><dd id="detailBukuTahun">-</dd>
          <dt>Sedang dipinjam</dt><dd id="detailBukuDipinjam">0 eksemplar</dd>
          <dt>Ketersediaan</dt><dd><span class="detail-availability" id="detailBukuStok">Tersedia</span></dd>
        </dl>
      </div>
    </div>
    <div class="detail-footer"><button type="button" class="detail-close-btn" id="detailBukuTutup">Tutup</button></div>
  </section>
</div>
<script>
(function(){
  const overlay = document.getElementById('detailBukuOverlay');
  if (!overlay) return;
  const setText = (id, value) => { const el = document.getElementById(id); if (el) el.textContent = value || '-'; };
  function bukaDetail(button) {
    setText('detailBukuJudul', button.dataset.judul);
    setText('detailBukuKategori', button.dataset.kategori);
    setText('detailBukuPengarang', button.dataset.pengarang);
    setText('detailBukuPenerbit', button.dataset.penerbit);
    setText('detailBukuKode', button.dataset.kode);
    setText('detailBukuRak', button.dataset.rak);
    setText('detailBukuTahun', button.dataset.tahun);
    setText('detailBukuDipinjam', (button.dataset.dipinjam || '0') + ' eksemplar');
    const stok = parseInt(button.dataset.stok || '0', 10);
    const stokEl = document.getElementById('detailBukuStok');
    stokEl.textContent = stok > 0 ? 'Tersedia: ' + stok : 'Belum tersedia';
    stokEl.classList.toggle('out', stok <= 0);
    const gambar = document.getElementById('detailBukuGambar');
    gambar.src = button.dataset.gambar || 'uploads/default.png';
    gambar.alt = button.dataset.judul || 'Sampul buku';
    overlay.classList.add('show');
    overlay.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
    document.getElementById('detailBukuTutup').focus();
  }
  function tutupDetail(){
    overlay.classList.remove('show');
    overlay.setAttribute('aria-hidden', 'true');
    document.body.style.overflow = '';
  }
  document.querySelectorAll('.js-detail-buku').forEach(button => button.addEventListener('click', () => bukaDetail(button)));
  document.getElementById('detailBukuX').addEventListener('click', tutupDetail);
  document.getElementById('detailBukuTutup').addEventListener('click', tutupDetail);
  overlay.addEventListener('click', event => { if (event.target === overlay) tutupDetail(); });
  document.addEventListener('keydown', event => { if (event.key === 'Escape' && overlay.classList.contains('show')) tutupDetail(); });
})();
</script>

</body>
</html>
