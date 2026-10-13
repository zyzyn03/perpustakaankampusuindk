<?php
session_start();
include 'koneksi.php';

// KEBIJAKAN: halaman Ganti Password ini KHUSUS MAHASISWA (ganti password sendiri).
// Admin/petugas tidak mengganti password di sini; admin hanya boleh MERESET
// password mahasiswa lewat halaman Data Anggota (aksi=reset).
$is_admin_here = isset($_SESSION['admin']) && trim((string)$_SESSION['admin']) !== '';
if ($is_admin_here) {
    header('Location: anggota.php');
    exit();
}
if (!isset($_SESSION['login'])) {
    header('Location: login.php');
    exit();
}

// Ambil NIM dari session
$nim_login = '';
if (isset($_SESSION['username'])) {
    $nim_login =$_SESSION['username'];
} else if (isset($_SESSION['nim'])) {
    $nim_login =$_SESSION['nim'];
} else if (isset($_SESSION['id_user'])) {
    $nim_login =$_SESSION['id_user'];
}

// Deteksi Nama Tabel Otomatis
$tbl_anggota = 'tb_anggota';
$chk_a = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_anggota'");
if (!$chk_a OR mysqli_num_rows($chk_a) == 0) {$tbl_anggota = 'anggota'; 
}

// Deteksi nama kolom password dan id di tb_anggota
$col_pw = 'password';$col_id = 'nim';

$q_cols = mysqli_query($koneksi, "SHOW COLUMNS FROM $tbl_anggota");
if ($q_cols) {
    while ($c = mysqli_fetch_assoc($q_cols)) {
        $f = strtolower($c['Field']);
        if ($f == 'password' OR$f == 'pass') {
            $col_pw =$c['Field'];
        }
        if ($f == 'nim') {$col_id = 'nim';
        } else if ($f == 'id_anggota' AND $col_id != 'nim') {$col_id = 'id_anggota';
        }
    }
}

// Proses Simpan Password Baru
if (isset($_POST['simpan_password'])) {$pw_lama = mysqli_real_escape_string($koneksi, trim($_POST['pw_lama']));
    $pw_baru = mysqli_real_escape_string($koneksi, trim($_POST['pw_baru']));$pw_konf = mysqli_real_escape_string($koneksi, trim($_POST['pw_konf']));

    if (empty($pw_baru)) {
        echo "<script>alert('Password baru tidak boleh kosong!'); window.history.back();</script>";
        exit();
    }

    if ($pw_baru !==$pw_konf) {
        echo "<script>alert('Konfirmasi password baru tidak cocok!'); window.history.back();</script>";
        exit();
    }

    // Ambil data password di DB
    $q_mhs = mysqli_query($koneksi, "SELECT * FROM `$tbl_anggota` WHERE `$col_id` = '$nim_login' OR `nim` = '$nim_login' LIMIT 1");
    $d_mhs = array();
    if ($q_mhs AND mysqli_num_rows($q_mhs) > 0) {
        $d_mhs = mysqli_fetch_assoc($q_mhs);
    }

    $pw_db = isset($d_mhs[$col_pw]) ? $d_mhs[$col_pw] : '';

    // Validasi password lama (Mendukung NIM / Plaintext / MD5)
    $pw_valid = false;
    if (
        empty($pw_db) OR 
        $pw_lama ===$pw_db OR 
        $pw_lama ===$nim_login OR 
        md5($pw_lama) ===$pw_db OR 
        password_verify($pw_lama,$pw_db)
    ) {
        $pw_valid = true;
    }

    if (!$pw_valid) {
        echo "<script>alert('Password lama salah! Password default adalah NIM Anda ($nim_login).'); window.history.back();</script>";
        exit();
    }

    // Update password
    $sql_update = "UPDATE `$tbl_anggota` SET `$col_pw` = '$pw_baru' WHERE `$col_id` = '$nim_login' OR `nim` = '$nim_login'";

    if (mysqli_query($koneksi,$sql_update)) {
        echo "<script>alert('Password berhasil diperbarui! Silakan gunakan password baru Anda.'); window.location='riwayat.php';</script>";
        exit();
    } else {
        $err = addslashes(mysqli_error($koneksi));
        echo "<script>alert('Gagal mengedit password: $err'); window.history.back();</script>";
        exit();
    }
}
?>
<?php
$is_admin_user = isset($_SESSION['admin']) && trim((string)$_SESSION['admin']) !== '';
$nama_tampil = $is_admin_user ? (isset($_SESSION['nama']) ? $_SESSION['nama'] : 'Petugas Perpustakaan') : (isset($_SESSION['nama']) ? $_SESSION['nama'] : 'Mahasiswa');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Ganti Password - Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green-900:#064c40;--green-800:#085f50;--green-700:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e4ebee;--bg:#eff1f2;--card:#fff;--red:#e5484d}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
.layout{display:flex;min-height:100vh}
.sidebar{width:256px;flex:none;background:#064c40;color:#dcebe3;display:flex;flex-direction:column;justify-content:space-between;padding:0;position:fixed;left:0;top:0;bottom:0;height:100vh;overflow-y:auto;z-index:30;box-shadow:0 0 24px rgba(6,76,64,.14)}
.side-brand{display:flex;align-items:center;gap:12px;padding:20px 24px;border-bottom:1px solid rgba(11,92,78,.5)}
.side-brand img{width:40px;height:40px;object-fit:contain;flex:none;filter:drop-shadow(0 1px 2px rgba(0,0,0,.3))}
.side-brand b{display:block;font-size:15px;line-height:1.2;color:#fff;font-weight:700}
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
.main{flex:1;min-width:0;margin-left:256px;min-height:100vh;padding-bottom:110px}
.topbar{display:flex;align-items:center;gap:14px;background:#fff;border-bottom:1px solid var(--line);padding:14px 26px;position:sticky;top:0;z-index:20}
.hamburger{border:0;background:none;cursor:pointer;padding:6px;display:none;color:inherit}
.hamburger svg{width:24px;height:24px}
.topbar h2{font-size:19px;font-weight:800}
.topbar-right{margin-left:auto;display:flex;align-items:center;gap:12px}
.user-chip{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:600}
.user-chip svg{width:19px;height:19px}
.btn-logout{display:flex;align-items:center;gap:7px;background:var(--red);color:#fff;border:0;border-radius:10px;padding:9px 18px;font:inherit;font-size:13px;font-weight:700;cursor:pointer;text-decoration:none;box-shadow:0 2px 8px rgba(229,72,77,.25)}
.btn-logout svg{width:16px;height:16px}
.btn-logout:hover{background:#d23b40;transform:translateY(-1px);box-shadow:0 6px 16px rgba(229,72,77,.3)}
.mob-header{display:none}
.topnav-mhs{display:flex;align-items:center;gap:11px;background:#fff;padding:14px 30px;border-bottom:1px solid var(--line)}
.topnav-mhs img{width:44px;height:44px;object-fit:contain}
.topnav-mhs b{display:block;font-size:15.5px;line-height:1.2}
.topnav-mhs small{display:block;font-size:11px;color:var(--muted);font-weight:600}
.topnav-mhs .links{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.btn-pill{display:inline-flex;align-items:center;gap:7px;border-radius:10px;padding:9px 18px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none;cursor:pointer}
.btn-pill.ghost{border:1.5px solid #cbd5e1;color:#475569}
.btn-pill.blue{background:#2563eb;color:#fff;box-shadow:0 2px 8px rgba(37,99,235,.25)}
.btn-pill.red{background:var(--red);color:#fff;box-shadow:0 2px 8px rgba(229,72,77,.25)}
.content{padding:30px;display:flex;justify-content:center}
.gp-card{background:#fff;border-radius:20px;padding:36px 40px;width:min(100%,560px);box-shadow:0 8px 28px rgba(31,42,46,.07)}
.gp-head{display:flex;gap:14px;align-items:flex-start;margin-bottom:24px}
.gp-ic{width:40px;height:40px;flex:none;color:var(--green-800)}
.gp-ic svg{width:100%;height:100%}
.gp-head h3{font-size:21px;font-weight:800}
.gp-head p{font-size:12.5px;color:var(--muted);margin-top:4px;line-height:1.55}
.field{margin-bottom:18px}
.field label{display:block;font-size:13.5px;font-weight:800;color:#2b3f4c;margin-bottom:8px}
.input-wrap{position:relative}
.gp-card .input-wrap>svg{position:absolute;left:14px;top:50%;transform:translateY(-50%);width:19px;height:19px;color:var(--green-800);pointer-events:none}
.gp-card .form-control{width:100%;height:48px;border:1.5px solid var(--line);border-radius:12px;padding:0 44px 0 44px;font:inherit;font-size:13px;color:var(--ink);outline:none;transition:border-color .15s ease,box-shadow .15s ease}
.gp-card .form-control::placeholder{color:#9aa8ae}
.gp-card .form-control:focus{border-color:var(--green-700);box-shadow:0 0 0 3px rgba(14,107,92,.12)}
.toggle-pw{position:absolute;right:8px;top:50%;transform:translateY(-50%);border:0;background:none;cursor:pointer;color:#8a99a0;padding:8px;border-radius:8px}
.toggle-pw:hover{background:#f0f4f3;color:var(--green-800)}
.toggle-pw svg{width:18px;height:18px;position:static;transform:none}
.btn-submit{width:100%;height:50px;border:0;border-radius:12px;background:var(--green-900);color:#fff;font:inherit;font-size:14.5px;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;margin-top:8px;box-shadow:0 6px 16px rgba(6,76,65,.28);transition:transform .15s ease,box-shadow .15s ease,background .15s ease}
.btn-submit:hover{background:#04382f;transform:translateY(-1px);box-shadow:0 8px 20px rgba(6,76,65,.32)}
.btn-submit:active{transform:translateY(0)}
.btn-submit svg{width:19px;height:19px}
.backrow{display:none}
.bottomnav{display:none}
@media(max-width:900px){
  .main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
  body.nav-open .sidebar{transform:none}
  body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
  .hamburger{display:block}
}
@media(max-width:640px){
  .main{padding-bottom:130px}
  .mob-header{display:flex;align-items:center;gap:10px;background:linear-gradient(120deg,#085f50,#064c40);color:#fff;border-radius:0 0 26px 26px;padding:18px 18px 34px}
  .mob-header img{width:46px;height:46px;object-fit:contain}
  .mob-header b{display:block;font-size:16px;line-height:1.2}
  .mob-header small{font-size:11.5px;color:#c4ddd0}
  .mob-user{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12px}
  .mob-user .ava{width:42px;height:42px;border-radius:50%;background:#fff;color:var(--green-800);display:grid;place-items:center}
  .mob-user .ava svg{width:24px;height:24px}
  .topbar{display:none}
  .topnav-mhs{flex-wrap:wrap}
  .backrow{display:flex;align-items:center;gap:14px;padding:22px 16px 4px}
  .backrow a{width:44px;height:44px;border-radius:50%;background:#fff;display:grid;place-items:center;color:var(--green-800);box-shadow:0 2px 8px rgba(31,42,46,.08);text-decoration:none}
  .backrow a svg{width:22px;height:22px}
  .backrow h2{font-size:25px;font-weight:800;color:var(--green-900)}
  .content{padding:18px 16px}
  .gp-card{padding:28px 22px}
  .gp-ic{width:52px;height:52px}
  .gp-head h3{font-size:25px}
  .bottomnav{display:flex;position:fixed;left:10px;right:10px;bottom:12px;z-index:60;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(6,76,65,.18);padding:10px 6px}
  .bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:#93a3aa;font-size:11.5px;font-weight:600;padding:6px 0}
  .bottomnav a svg{width:24px;height:24px}
  .bottomnav a.on{color:var(--green-900)}
  .bottomnav a.on::after{content:"";width:44px;height:3px;border-radius:99px;background:var(--green-900)}
}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<nav class="topnav-mhs">
  <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
  <div><b>Sistem Perpustakaan</b><small>UIN Datokarama Palu</small></div>
  <div class="links">
    <span class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg><?= htmlspecialchars($nama_tampil); ?></span>
    <a class="b-ghost" href="index.php?portal=mahasiswa">Katalog Buku</a>
    <a class="b-ghost" href="ganti_password.php">Ganti Password</a>
    <a class="b-red" href="logout.php" onclick="return confirm('Keluar dari sistem?')">Logout</a>
  </div>
</nav>
<?php if ($is_admin_user): ?>
<div class="layout">
  <aside class="sidebar" aria-label="Navigasi utama">
    <div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
    <nav class="side-nav">
      <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
      <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
      <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
      <a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
      <a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
      <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
      <a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Katalog Publik</a>
      <hr>
      <a href="ganti_password.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Ganti Password</a>
    </nav>
    <div class="side-art"><img src="assets/logo-bunga.png" alt="Ilustrasi buku"></div>
  </aside>
  <div class="main">
    <div class="mob-header mhs-top">
      <button class="hamburger" style="display:block;color:#fff" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
      <div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div>
      <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg></span><span>Halo,<br><?= htmlspecialchars($nama_tampil); ?></span></div>
    </div>
    <div class="topbar">
      <button class="hamburger" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <h2>Ganti Password</h2>
      <div class="topbar-right">
        <span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, <?= htmlspecialchars($nama_tampil); ?></span>
        <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Logout</a>
      </div>
    </div>
    <div class="content">
      <section class="gp-card">
        <div class="gp-head">
          <span class="gp-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14.2v2.3"/></svg></span>
          <div><h3>Ganti Password</h3><p>Pastikan password anda kuat dan mudah diingat. Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol.</p></div>
        </div>
        <form action="ganti_password.php" method="POST" id="gpForm">
          <div class="field">
            <label for="pw_lama">Password lama / NIM</label>
            <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16.5c.5-1.6 1.6-2.5 3-2.5s2.5.9 3 2.5M14 9.5h5M14 12.5h5"/></svg><input type="password" id="pw_lama" name="pw_lama" class="form-control" placeholder="Masukkan password lama atau NIM" required><button type="button" class="toggle-pw" data-target="pw_lama" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
          </div>
          <div class="field">
            <label for="pw_baru">Password baru</label>
            <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input type="password" id="pw_baru" name="pw_baru" class="form-control" placeholder="Masukkan password baru" required><button type="button" class="toggle-pw" data-target="pw_baru" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
          </div>
          <div class="field">
            <label for="pw_konf">Konfirmasi password baru</label>
            <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input type="password" id="pw_konf" name="pw_konf" class="form-control" placeholder="Ulangi password baru" required><button type="button" class="toggle-pw" data-target="pw_konf" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
          </div>
          <button type="submit" name="simpan_password" class="btn-submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h-8v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Simpan Password Baru</button>
        </form>
      </section>
    </div>
  </div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2.5 10v11h6v-6h7v6h6V10L12 3z"/></svg>Beranda</a>
  <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
  <a href="ganti_password.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Password</a>
  <a href="riwayat.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Riwayat</a>
</nav>
<?php else: ?>
<div class="mob-header mhs-top">
  <div class="row" style="display:flex;align-items:center;gap:10px">
    <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
    <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
    <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:21px;height:21px"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg></span><span>Halo,<br><?= htmlspecialchars($nama_tampil); ?></span></div>
  </div>
</div>
<div class="backrow"><a href="riwayat.php" aria-label="Kembali"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 5l-7 7 7 7"/></svg></a><h2>Ganti Password</h2></div>
<div class="content">
  <section class="gp-card">
    <div class="gp-head">
      <span class="gp-ic"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/><path d="M12 14.2v2.3"/></svg></span>
      <div><h3>Ganti Password</h3><p>Pastikan password anda kuat dan mudah diingat. Gunakan kombinasi huruf besar, huruf kecil, angka, dan simbol.</p></div>
    </div>
    <form action="ganti_password.php" method="POST" id="gpForm2">
      <div class="field">
        <label for="pw_lama2">Password lama / NIM</label>
        <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="5" width="18" height="14" rx="2"/><circle cx="8.5" cy="11" r="2"/><path d="M5.5 16.5c.5-1.6 1.6-2.5 3-2.5s2.5.9 3 2.5M14 9.5h5M14 12.5h5"/></svg><input type="password" id="pw_lama2" name="pw_lama" class="form-control" placeholder="Masukkan password lama atau NIM" required><button type="button" class="toggle-pw" data-target="pw_lama2" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      </div>
      <div class="field">
        <label for="pw_baru2">Password baru</label>
        <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input type="password" id="pw_baru2" name="pw_baru" class="form-control" placeholder="Masukkan password baru" required><button type="button" class="toggle-pw" data-target="pw_baru2" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      </div>
      <div class="field">
        <label for="pw_konf2">Konfirmasi password baru</label>
        <div class="input-wrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4.5" y="10" width="15" height="10" rx="2.5"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg><input type="password" id="pw_konf2" name="pw_konf" class="form-control" placeholder="Ulangi password baru" required><button type="button" class="toggle-pw" data-target="pw_konf2" aria-label="Tampilkan password"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M2.5 12S6 5.5 12 5.5 21.5 12 21.5 12 18 18.5 12 18.5 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/></svg></button></div>
      </div>
      <button type="submit" name="simpan_password" class="btn-submit"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h-8v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Simpan Password Baru</button>
    </form>
  </section>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="index.php?portal=mahasiswa"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Beranda</a>
  <a href="riwayat.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Riwayat</a>
  <a href="ganti_password.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>Akun</a>
</nav>
<?php endif; ?>
<script>
"use strict";
document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});
document.querySelectorAll(".toggle-pw").forEach(function(btn){
  btn.addEventListener("click",function(){
    var inp=document.getElementById(btn.getAttribute("data-target"));
    if(!inp) return;
    inp.type = inp.type==="password" ? "text" : "password";
  });
});
document.querySelectorAll("#gpForm,#gpForm2").forEach(function(f){
  f.addEventListener("submit",function(e){
    var b=f.querySelector("input[name=pw_baru]").value;
    var k=f.querySelector("input[name=pw_konf]").value;
    if(b!==k){e.preventDefault();alert("Konfirmasi password baru tidak cocok!");}
  });
});
</script>
</body>
</html>