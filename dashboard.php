<?php
session_start();

// Proteksi Halaman: Jika belum login, lempar kembali ke login.php
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

require_once 'koneksi.php';

// --- DETEKSI NAMA TABEL & KOLOM AGAR AMAN ---
$tbl_anggota = 'tb_anggota';
$chk_a = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_anggota'");
if (!$chk_a OR mysqli_num_rows($chk_a) == 0) { $tbl_anggota = 'anggota'; }

$tbl_buku = 'tb_buku';
$chk_b = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_buku'");
if (!$chk_b OR mysqli_num_rows($chk_b) == 0) { $tbl_buku = 'buku'; }

$tbl_peminjaman = 'tb_peminjaman';
$chk_p = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_peminjaman'");
if (!$chk_p OR mysqli_num_rows($chk_p) == 0) { $tbl_peminjaman = 'peminjaman'; }

// Deteksi Kolom Peminjaman
$kolom_peminjaman = array();
$q_kolom_p = mysqli_query($koneksi, "SHOW COLUMNS FROM $tbl_peminjaman");
if ($q_kolom_p) {
    while ($r = mysqli_fetch_assoc($q_kolom_p)) {
        $kolom_peminjaman[] = strtolower($r['Field']);
    }
}

$col_tgl_kembali = 'tgl_kembali';
if (in_array('tgl_harus_kembali', $kolom_peminjaman)) { $col_tgl_kembali = 'tgl_harus_kembali'; }
else if (in_array('tanggal_kembali', $kolom_peminjaman)) { $col_tgl_kembali = 'tanggal_kembali'; }

$col_nim_pem = 'nim';
if (in_array('id_anggota', $kolom_peminjaman) AND !in_array('nim', $kolom_peminjaman)) { $col_nim_pem = 'id_anggota'; }

$col_buku_pem = 'kode_buku';
if (in_array('id_buku', $kolom_peminjaman) AND !in_array('kode_buku', $kolom_peminjaman)) { $col_buku_pem = 'id_buku'; }

// Mengambil data ringkas untuk statistik dashboard
$total_buku    = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM tb_buku"))['total'];
$total_anggota = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM tb_anggota"))['total'];
$total_pinjam  = mysqli_fetch_assoc(mysqli_query($koneksi, "SELECT COUNT(*) AS total FROM tb_peminjaman WHERE status_pinjam = 'Dipinjam'"))['total'];
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Dashboard - Perpustakaan UIN Datokarama Palu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">

<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#064c40;--brand-dark:#04382f;--ink:#1f2a2e;--muted:#8a99a0;--line:#e8eeeb;--bg:#f4f8f6}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink);-webkit-tap-highlight-color:transparent}
.layout{display:flex;min-height:100vh}
/* SIDEBAR (desktop) */
.sidebar{width:256px;flex:none;background:#064c40;color:#dcebe3;display:flex;flex-direction:column;justify-content:space-between;padding:0;position:fixed;left:0;top:0;bottom:0;height:100vh;overflow-y:auto;z-index:30;box-shadow:0 0 24px rgba(6,76,64,.14)}
.side-brand{display:flex;align-items:center;gap:12px;padding:20px 24px;border-bottom:1px solid rgba(11,92,78,.5)}
.side-brand img{width:40px;height:40px;object-fit:contain;flex:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))}
.side-brand b{display:block;font-size:15px;line-height:1.2;color:#fff;font-weight:700;letter-spacing:.2px}
.side-brand small{display:block;font-size:11px;color:#b8d8d1;font-weight:500}
.side-nav{margin-top:0;padding:16px;display:flex;flex-direction:column;gap:6px}
.side-nav a{display:flex;align-items:center;gap:14px;padding:10px 14px;border-radius:8px;color:rgba(209,250,229,.9);text-decoration:none;font-size:13.5px;font-weight:500}
.side-nav a svg{width:20px;height:20px;flex:none;opacity:.85}
.side-nav a:hover{background:#0b5649;color:#fff}
.side-nav a.on{background:#0d5c4e;color:#fff;font-weight:600}
.side-nav a.on svg{opacity:1}
.side-nav hr{border:0;border-top:1px solid rgba(11,92,78,.6);margin:10px 4px}
.side-art{margin-top:auto;padding:8px 12px 14px;text-align:center}
.side-art img{width:180px;max-width:100%;object-fit:contain;opacity:.95}
.main{flex:1;min-width:0;margin-left:256px;min-height:100vh}
/* TOPBAR (desktop) */
.topbar{display:flex;align-items:center;gap:16px;background:#fff;border-bottom:1px solid #f1f5f9;height:64px;padding:0 32px;position:sticky;top:0;z-index:20}
.hamburger{border:0;background:none;cursor:pointer;padding:6px;display:none;color:#334155}
.hamburger svg{width:24px;height:24px}
.topbar h2{font-size:20px;font-weight:800;letter-spacing:-.3px}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:24px}
.user-chip{display:flex;align-items:center;gap:8px;font-size:13.5px;font-weight:600;color:#334155}
.user-chip svg{width:19px;height:19px;color:#475569}
.btn-logout{display:flex;align-items:center;gap:6px;background:#dc2626;color:#fff;border:0;border-radius:8px;padding:8px 14px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-logout svg{width:15px;height:15px}
.btn-logout:hover{background:#b91c1c}
.mob-header{display:none}
.content{padding:32px;max-width:1280px;width:100%}
.page-title{font-size:24px;font-weight:800;letter-spacing:-.4px}
.page-sub{font-size:12.5px;color:var(--muted);margin:4px 0 24px}
.stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:24px;margin-bottom:28px}
.stat{background:#fff;border-radius:12px;padding:20px;display:flex;gap:16px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,.03);border:1px solid #f1f5f9}
.stat-ic{width:40px;height:40px;flex:none;display:grid;place-items:center}
.stat-ic svg{width:38px;height:38px}
.si-green{color:#064c40}
.si-gray{color:#94a3b8}
.si-yellow{color:#eaa123}
.stat b{display:block;font-size:11px;letter-spacing:.5px;font-weight:700}
.stat .num{font-size:30px;font-weight:800;line-height:1.15;color:#0f172a}
.stat small{font-size:12px;color:var(--muted)}
.alert{background:#fff;border:1px solid #f1f5f9;border-left:4px solid #dc2626;border-radius:12px;padding:20px;margin-bottom:28px;box-shadow:0 2px 8px rgba(0,0,0,.03)}
.alert-head{display:flex;align-items:center;gap:12px;margin-bottom:16px}
.alert-ic{width:36px;height:36px;flex:none;border-radius:8px;background:#fee2e2;color:#dc2626;display:grid;place-items:center}
.alert-ic svg{width:22px;height:22px}
.alert-head h3{font-size:14px;font-weight:800;color:#dc2626}
.table-wrap{overflow-x:auto;border-radius:10px}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:720px}
thead th{background:#f8fafc;color:#475569;font-weight:600;text-align:left;padding:12px 16px;font-size:12px;border-bottom:1px solid #f1f5f9}
tbody td{padding:14px 16px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody td .sub{color:var(--muted);font-size:12px}
.tgl-merah{color:#dc2626;font-weight:600}
.badge-terlambat{display:inline-block;background:#dc2626;color:#fff;font-size:11px;font-weight:500;border-radius:999px;padding:6px 16px;white-space:nowrap}
.welcome{background:#fff;border-radius:12px;padding:24px;display:flex;gap:20px;align-items:center;border:1px solid #f1f5f9;box-shadow:0 2px 8px rgba(0,0,0,.03)}
.welcome-ic{width:56px;height:56px;flex:none;border-radius:50%;background:#064c40;display:grid;place-items:center;color:#fff}
.welcome-ic svg{width:28px;height:28px}
.welcome h3{font-size:15px;font-weight:800;margin:0 0 4px;color:#0f172a}
.welcome p{font-size:12px;color:#64748b;line-height:1.65;max-width:760px}
.bottomnav{display:none}
@media(max-width:900px){
  .main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
  body.nav-open .sidebar{transform:none}
  body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
  .hamburger{display:grid;place-items:center}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr))}
  .stat:last-child{grid-column:1/-1}
}
@media(max-width:640px){
  .main{padding-bottom:96px}
  .mob-header{display:block;background:linear-gradient(120deg,#064c40,#04382f);color:#fff;border-radius:0 0 26px 26px;padding:16px 18px 30px}
  .mob-header .row{display:flex;align-items:center;gap:10px}
  .mob-header img{width:40px;height:40px;object-fit:contain;background:rgba(255,255,255,.92);border-radius:10px;padding:3px;flex:none}
  .mob-header b{display:block;font-size:15px;line-height:1.15}
  .mob-header small{display:block;font-size:10.5px;color:#bcdccf}
  .mob-user{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:11.5px}
  .mob-user .ava{width:38px;height:38px;border-radius:50%;background:#fff;color:var(--brand);display:grid;place-items:center}
  .mob-user .ava svg{width:21px;height:21px}
  .topbar{background:#fff;border:0;border-radius:18px 18px 0 0;margin:-16px 0 0;padding:14px 16px;box-shadow:0 4px 16px rgba(8,60,44,.06)}
  .topbar h2{font-size:19px}
  .user-chip{display:none}
  .content{padding:18px 16px 100px}
  .page-title{font-size:21px;color:var(--brand)}
  .page-sub{font-size:12px;margin:3px 0 16px}
  .stats{grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}
  .stat{border-radius:16px;padding:14px;gap:12px}
  .stat-ic{width:44px;height:44px;border-radius:12px}
  .stat-ic svg{width:22px;height:22px}
  .stat .num{font-size:24px}
  .stat small{font-size:10.5px}
  .alert{border-radius:16px;padding:16px}
  .welcome{border-radius:16px;padding:16px}
  .welcome-ic{width:52px;height:52px}
  .bottomnav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:60;background:#fff;border-top:1px solid var(--line);padding:9px 10px calc(9px + env(safe-area-inset-bottom,0px));justify-content:space-around;align-items:center;box-shadow:0 -4px 18px rgba(8,60,44,.07)}
  .bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px;text-decoration:none;color:#93a3aa;font-size:10.5px;font-weight:600;padding:4px 0}
  .bottomnav a svg{width:23px;height:23px}
  .bottomnav a.on{color:var(--brand);font-weight:800}
  .bottomnav a.on::after{content:"";width:30px;height:3px;border-radius:99px;background:var(--brand);margin-top:1px}
}
</style>
<link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar" aria-label="Navigasi utama">
    <div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
    <nav class="side-nav">
      <a href="dashboard.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
      <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
      <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
      <a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
      <a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
      <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
      <hr>
      <a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>Lihat Katalog</a>
    </nav>
    <div class="side-art"><img src="assets/logo-bunga.png" alt="Ilustrasi buku"></div>
  </aside>
  <div class="main">
    <div class="mob-header">
      <div class="row"><button class="mob-menu-btn" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
        <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
        <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg></span><span>Halo,<br><?= htmlspecialchars($_SESSION['nama_admin'] ?? 'Petugas'); ?></span></div>
      </div>
    </div>
    <div class="topbar">
      <button class="hamburger" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
      <h2>Dashboard</h2>
      <div class="topbar-right">
        <span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, <?= htmlspecialchars($_SESSION['nama_admin'] ?? 'Petugas'); ?></span>
        <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
      </div>
    </div>
    <div class="content">
      <h3 class="page-title">Ringkasan Sistem</h3>
      <p class="page-sub">Informasi umum mengenai aktivitas perpustakaan.</p>
      <div class="stats">
        <div class="stat"><span class="stat-ic si-green"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></span><div><b style="color:#1b7a54">TOTAL KOLEKSI BUKU</b><span class="num"><?= (int)$total_buku; ?></span><br><small>Buku tersedia di perpustakaan</small></div></div>
        <div class="stat"><span class="stat-ic si-gray"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span><div><b>TOTAL ANGGOTA</b><span class="num"><?= (int)$total_anggota; ?></span><br><small>Anggota terdaftar aktif</small></div></div>
        <div class="stat"><span class="stat-ic si-yellow"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 7h8a2 2 0 012 2v9a2 2 0 01-2 2H8a2 2 0 01-2-2v-1"/><path d="M3 12h10m0 0l-3-3m3 3l-3 3"/></svg></span><div><b style="color:#d97706">BUKU SEDANG DIPINJAM</b><span class="num"><?= (int)$total_pinjam; ?></span><br><small>Buku yang sedang dipinjam</small></div></div>
      </div>
      <div class="alert">
        <div class="alert-head">
          <span class="alert-ic"><svg viewBox="0 0 20 20" fill="currentColor"><path d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"/></svg></span>
          <h3>Perhatian: Daftar Mahasiswa Melewati Tenggat Waktu (Terlambat)</h3>
        </div>
        <div class="table-wrap"><table>
          <thead><tr><th>NIM &amp; Nama Peminjam</th><th>Kode &amp; Judul Buku</th><th>Tenggat Kembali</th><th>Keterlambatan &amp; Estimasi Denda</th></tr></thead>
          <tbody>
          <?php
          $q_def_denda = mysqli_query($koneksi, "SELECT nilai FROM tb_pengaturan WHERE kunci = 'tarif_denda' LIMIT 1");
          $tarif_denda = ($q_def_denda && mysqli_num_rows($q_def_denda) > 0) ? intval(mysqli_fetch_assoc($q_def_denda)['nilai']) : 1000;
          $tgl_sekarang = date('Y-m-d');
          $sql_telat = "SELECT * FROM $tbl_peminjaman WHERE (LOWER(status_pinjam) = 'dipinjam' OR status_pinjam IS NULL OR status_pinjam = '') AND $col_tgl_kembali < '$tgl_sekarang' ORDER BY $col_tgl_kembali ASC";
          $q_telat = mysqli_query($koneksi, $sql_telat);
          if ($q_telat && mysqli_num_rows($q_telat) > 0):
            while ($rt = mysqli_fetch_assoc($q_telat)):
              $t_nim = $rt[$col_nim_pem] ?? '-'; $t_buku = $rt[$col_buku_pem] ?? '-'; $t_tgl_k = $rt[$col_tgl_kembali] ?? '-';
              $nama_mhs = 'Mahasiswa';
              $q_am = mysqli_query($koneksi, "SELECT * FROM $tbl_anggota WHERE nim='$t_nim' OR id_anggota='$t_nim' LIMIT 1");
              if ($q_am && $dm = mysqli_fetch_assoc($q_am)) { foreach ($dm as $kc => $vc) { if (stripos($kc, 'nama') !== false) { $nama_mhs = $vc; break; } } }
              $judul_buku = 'Buku Perpustakaan';
              $q_bk = mysqli_query($koneksi, "SELECT * FROM $tbl_buku WHERE kode_buku='$t_buku' OR id_buku='$t_buku' LIMIT 1");
              if ($q_bk && $dbk = mysqli_fetch_assoc($q_bk)) { foreach ($dbk as $kcb => $vcb) { if (stripos($kcb, 'judul') !== false || stripos($kcb, 'nama') !== false) { $judul_buku = $vcb; break; } } }
              $jml_hari = floor((strtotime($tgl_sekarang) - strtotime($t_tgl_k)) / 86400);
              $total_denda = $jml_hari * $tarif_denda;
          ?>
            <tr><td><b><?= htmlspecialchars($t_nim); ?></b><br><span class="sub"><?= htmlspecialchars($nama_mhs); ?></span></td><td><code><?= htmlspecialchars($t_buku); ?></code> - <?= htmlspecialchars($judul_buku); ?></td><td class="tgl-merah"><?= htmlspecialchars($t_tgl_k); ?></td><td><span class="badge-terlambat">Terlambat <?= (int)$jml_hari; ?> hari (Rp <?= number_format($total_denda, 0, ',', '.'); ?>)</span></td></tr>
          <?php endwhile; else: ?>
            <tr><td colspan="4" style="text-align:center;color:#15803d;padding:15px;font-weight:500">Tidak ada peminjaman yang melewati tenggat waktu saat ini.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
      </div>
      <div class="welcome">
        <span class="welcome-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg></span>
        <div><h3>Selamat Datang di Panel Petugas Perpustakaan</h3><p>Gunakan menu di sebelah kiri untuk mengelola master data buku, pendaftaran anggota mahasiswa, memproses sirkulasi peminjaman/pengembalian, serta mengunduh laporan bulanan.</p></div>
      </div>
    </div>
  </div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="dashboard.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Beranda</a>
  <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
  <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Anggota</a>
  <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Laporan</a>
</nav>
<script>"use strict";document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});</script>
</body>
</html>
