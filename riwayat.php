<?php
session_start();
include 'koneksi.php';

// Pastikan Mahasiswa sudah login
if (!isset($_SESSION['login'])) {
    echo "<script>alert('Silakan login terlebih dahulu!'); window.location='login.php';</script>";
    exit();
}

$nim_login = isset($_SESSION['username']) ? $_SESSION['username'] : (isset($_SESSION['nim']) ? $_SESSION['nim'] : '');$nama_user = isset($_SESSION['nama']) ?$_SESSION['nama'] : 'Mahasiswa';

// 1. Deteksi Nama Tabel Otomatis
$tbl_peminjaman = 'tb_peminjaman';
$chk_p = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_peminjaman'");
if (!$chk_p OR mysqli_num_rows($chk_p) == 0) {$tbl_peminjaman = 'peminjaman'; 
}

$tbl_buku = 'tb_buku';
$chk_b = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_buku'");
if (!$chk_b OR mysqli_num_rows($chk_b) == 0) {$tbl_buku = 'buku'; 
}

$tbl_anggota = 'tb_anggota';
$chk_a = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_anggota'");
if (!$chk_a OR mysqli_num_rows($chk_a) == 0) {$tbl_anggota = 'anggota'; 
}

$tbl_pengembalian = 'tb_pengembalian';
$chk_ret = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_pengembalian'");
if (!$chk_ret OR mysqli_num_rows($chk_ret) == 0) {
    $chk_ret2 = mysqli_query($koneksi, "SHOW TABLES LIKE 'pengembalian'");
    if ($chk_ret2 AND mysqli_num_rows($chk_ret2) > 0) {$tbl_pengembalian = 'pengembalian';
    } else {
        $tbl_pengembalian = '';
    }
}

$tbl_pengaturan = 'tb_pengaturan';
$chk_set = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_pengaturan'");
if (!$chk_set OR mysqli_num_rows($chk_set) == 0) {$tbl_pengaturan = 'pengaturan'; 
}

// AMBIL TARIF DENDA DINAMIS DARI TABEL tb_pengaturan (KUNCI = 'tarif_denda')
$tarif_denda_per_hari = 1000;
$q_tarif = mysqli_query($koneksi, "SELECT * FROM `$tbl_pengaturan` WHERE `kunci` = 'tarif_denda' LIMIT 1");
if ($q_tarif AND mysqli_num_rows($q_tarif) > 0) {
    $d_tarif = mysqli_fetch_assoc($q_tarif);
    if (isset($d_tarif['nilai'])) {
        $tarif_denda_per_hari = intval($d_tarif['nilai']);
    }
}

// 2. Ambil data nama lengkap dan ID anggota dari tabel anggota
$id_anggota_db = '';
if (!empty($nim_login)) {
    // Deteksi kolom identitas yang benar-benar ada agar query aman di semua skema.
    $anggota_cols = array();
    if ($rc_anggota = mysqli_query($koneksi, "SHOW COLUMNS FROM `$tbl_anggota`")) {
        while ($c_anggota = mysqli_fetch_assoc($rc_anggota)) { $anggota_cols[] = strtolower($c_anggota['Field']); }
    }
    $nim_login_aman = mysqli_real_escape_string($koneksi, $nim_login);
    $where_u = "`nim`='$nim_login_aman'";
    if (in_array('id_anggota', $anggota_cols, true)) { $where_u .= " OR `id_anggota`='$nim_login_aman'"; }
    if (in_array('id', $anggota_cols, true)) { $where_u .= " OR `id`='$nim_login_aman'"; }
    $q_u = mysqli_query($koneksi, "SELECT * FROM `$tbl_anggota` WHERE $where_u LIMIT 1");
    if ($q_u AND mysqli_num_rows($q_u) > 0) {$d_u = mysqli_fetch_assoc($q_u);$id_anggota_db = isset($d_u['id_anggota']) ?$d_u['id_anggota'] : (isset($d_u['id']) ?$d_u['id'] : '');
        if (isset($d_u['nama_lengkap']) AND !empty($d_u['nama_lengkap'])) {
            $nama_user =$d_u['nama_lengkap'];
        } else if (isset($d_u['nama']) AND !empty($d_u['nama'])) {
            $nama_user =$d_u['nama'];
        }
    }
}

$data_riwayat = array();$seen_trx = array();
$sum_dipinjam = 0; $sum_kembali = 0; $sum_denda = 0;
// Ambil semua transaksi peminjaman milik mahasiswa ini
$q_all_p = mysqli_query($koneksi, "SELECT * FROM `$tbl_peminjaman` ORDER BY 1 DESC");
if ($q_all_p AND mysqli_num_rows($q_all_p) > 0) {
    while ($r = mysqli_fetch_assoc($q_all_p)) {
        // Cocokkan transaksi hanya melalui kolom identitas anggota yang benar.
        // Jangan membandingkan semua kolom transaksi dengan NIM karena nilai
        // kolom lain (misalnya kode buku/tanggal) bisa kebetulan sama.
        $match = false;
        if (isset($r['nim']) && (string)$r['nim'] === (string)$nim_login) {
            $match = true;
        } elseif (isset($r['id_anggota']) && $id_anggota_db !== '' &&
                  (string)$r['id_anggota'] === (string)$id_anggota_db) {
            $match = true;
        }

        if ($match) {
            $id_trx  = isset($r['id_peminjaman']) ? $r['id_peminjaman'] : (isset($r['id_transaksi']) ? $r['id_transaksi'] : (isset($r['id']) ? $r['id'] : ''));$id_buku = isset($r['kode_buku']) ?$r['kode_buku'] : (isset($r['id_buku']) ?$r['id_buku'] : '');
            $tgl_p   = isset($r['tgl_pinjam']) ? $r['tgl_pinjam'] : (isset($r['tanggal_pinjam']) ? $r['tanggal_pinjam'] : (isset($r['tgl_peminjaman']) ? $r['tgl_peminjaman'] : '-'));$tgl_k   = isset($r['tgl_kembali']) ?$r['tgl_kembali'] : (isset($r['tgl_harus_kembali']) ?$r['tgl_harus_kembali'] : (isset($r['tanggal_kembali']) ?$r['tanggal_kembali'] : '-'));
            
            // Ambil kolom denda dari tabel peminjaman jika ada
            $denda_db = isset($r['denda']) ? intval($r['denda']) : 0;

            // Periksa kolom status di tb_peminjaman secara fleksibel
            $stt_raw = '';
            if (isset($r['status_pinjam'])) { 
                $stt_raw = trim($r['status_pinjam']); 
            } else if (isset($r['status'])) { 
                $stt_raw = trim($r['status']); 
            }
            $stt_lower = strtolower($stt_raw);

            $is_returned = false;
            if ($stt_lower != '' AND$stt_lower != 'dipinjam' AND $stt_lower != 'pinjam' AND$stt_lower != '0' AND $stt_lower != 'belum') {$is_returned = true;
            }

            // Cek juga apakah ada di tabel pengembalian
            if (!$is_returned AND !empty($tbl_pengembalian) AND !empty($id_trx)) {
                $q_ret_chk = mysqli_query($koneksi, "SELECT * FROM `$tbl_pengembalian` WHERE `id_peminjaman`='$id_trx' OR `id_transaksi`='$id_trx' OR `id`='$id_trx' LIMIT 1");
                if ($q_ret_chk AND mysqli_num_rows($q_ret_chk) > 0) {
                    $d_ret = mysqli_fetch_assoc($q_ret_chk);
                    if (isset($d_ret['denda'])) {
                        $denda_db = intval($d_ret['denda']);
                    }
                    $is_returned = true;
                }
            }

            // Ambil Judul Buku
            $judul_buku = 'Buku Perpustakaan';$kode_buku  = !empty($id_buku) ?$id_buku : '-';

            if (!empty($id_buku)) {
                // Kode buku dari transaksi harus dicocokkan tepat dengan kode_buku.
                // Jangan memakai OR ke kolom ID lain karena bisa mengambil judul buku yang berbeda.
                $id_buku_aman = mysqli_real_escape_string($koneksi, (string)$id_buku);
                $q_b = mysqli_query($koneksi, "SELECT * FROM `$tbl_buku` WHERE `kode_buku`='$id_buku_aman' LIMIT 1");
                if ($q_b AND mysqli_num_rows($q_b) > 0) {
                    $d_b = mysqli_fetch_assoc($q_b);
                    if (isset($d_b['judul_buku']) AND !empty($d_b['judul_buku'])) { 
                        $judul_buku =$d_b['judul_buku']; 
                    } else if (isset($d_b['judul']) AND !empty($d_b['judul'])) { 
                        $judul_buku =$d_b['judul']; 
                    }
                    if (isset($d_b['kode_buku']) AND !empty($d_b['kode_buku'])) { 
                        $kode_buku =$d_b['kode_buku']; 
                    }
                }
            }

            // Kalkulasi Transparan Keterlambatan & Denda
            $selisih_hari = 0;
            $total_denda = 0;
            $tgl_sekarang = new DateTime(date('Y-m-d'));$tgl_tenggat  = ($tgl_k != '-' && strtotime($tgl_k)) ? new DateTime($tgl_k) : null;

            if (!$is_returned && $tgl_tenggat &&$tgl_sekarang > $tgl_tenggat) {$selisih_hari = $tgl_sekarang->diff($tgl_tenggat)->days;
                $total_denda  = $selisih_hari * $tarif_denda_per_hari;
            } else if ($is_returned) {
                $total_denda =$denda_db;
            }

            $unique_key = $kode_buku . '_' .$tgl_p;
            if (!isset($seen_trx[$unique_key])) {
                $seen_trx[$unique_key] = true;
                $data_riwayat[] = array(
                    'kode_buku'    => $kode_buku,
                    'judul_buku'   => $judul_buku,
                    'tgl_pinjam'   => $tgl_p,
                    'tgl_kembali'  => $tgl_k,
                    'status'       => $is_returned ? 'Dikembalikan' : 'Dipinjam',
                    'selisih_hari' => $selisih_hari,
                    'total_denda'  => $total_denda
                );
                if ($is_returned) { $sum_kembali++; } else { $sum_dipinjam++; }
                $sum_denda += (int)$total_denda;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Riwayat — Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green-900:#064c40;--green-800:#085f50;--green-700:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e4ebee;--bg:#eff1f2;--card:#fff;--red:#e5484d;--blue:#2563eb}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
.topnav{display:flex;align-items:center;gap:11px;background:#fff;padding:14px 30px;border-bottom:1px solid var(--line)}
.topnav img{width:44px;height:44px;object-fit:contain}
.topnav b{display:block;font-size:15.5px;line-height:1.2}
.topnav small{display:block;font-size:11px;color:var(--muted);font-weight:600}
.top-links{margin-left:auto;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.user-chip{display:flex;align-items:center;gap:7px;font-size:13px;font-weight:600;color:#334155}
.user-chip svg{width:18px;height:18px}
.btn{display:inline-flex;align-items:center;gap:7px;border-radius:9px;padding:9px 16px;font:inherit;font-size:12.5px;font-weight:700;text-decoration:none;cursor:pointer}
.btn.blue{background:var(--blue);color:#fff}
.btn.ghost{border:1.5px solid #cbd5e1;color:#475569}
.btn.red{background:var(--red);color:#fff}
.btn svg{width:15px;height:15px}
.wrap{max-width:1100px;margin:0 auto;padding:26px}
.hero-riw{background:#064c40;background-image:radial-gradient(circle at 85% 50%,rgba(20,102,88,.45) 0%,transparent 60%);color:#fff;border-radius:18px;padding:30px 34px;margin-bottom:20px;position:relative;overflow:hidden}
.hero-riw .tag{display:inline-flex;align-items:center;background:rgba(6,95,70,.8);color:#d1fae5;font-size:11px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;padding:5px 12px;border-radius:99px;border:1px solid rgba(16,185,129,.4);margin-bottom:14px}
.hero-riw h2{font-size:28px;font-weight:800;letter-spacing:-.5px;margin-bottom:10px}
.hero-riw p{font-size:13.5px;color:rgba(209,250,229,.9);line-height:1.65;max-width:640px;margin-bottom:18px}
.hero-stats{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;max-width:720px}
.hero-stat{background:rgba(255,255,255,.1);border:1px solid rgba(255,255,255,.15);border-radius:12px;padding:14px;display:flex;align-items:center;gap:12px}
.hero-stat .ic{width:40px;height:40px;border-radius:10px;display:grid;place-items:center;flex:none}
.hero-stat .ic svg{width:20px;height:20px}
.hero-stat .ic.a{background:rgba(251,191,36,.2);color:#fcd34d}
.hero-stat .ic.g{background:rgba(52,211,153,.2);color:#6ee7b7}
.hero-stat .ic.r{background:rgba(248,113,113,.2);color:#fca5a5}
.hero-stat small{display:block;font-size:11px;color:#a7f3d0;text-transform:uppercase;font-weight:500;letter-spacing:.4px}
.hero-stat b{font-size:19px;font-weight:800;color:#fff}
.hero-stat b span{font-size:11px;font-weight:400;color:#a7f3d0}
.page-title{font-size:23px;font-weight:800}
.page-sub{font-size:12.5px;color:var(--muted);margin:3px 0 22px}
.card{background:#fff;border-radius:16px;padding:24px;box-shadow:0 4px 18px rgba(31,42,46,.06);margin-bottom:18px}
.banner{background:#fffbeb;border:1px solid #fde68a;color:#92400e;font-size:13px;line-height:1.6}
.table-wrap{overflow-x:auto;border-radius:10px}
table{width:100%;border-collapse:collapse;font-size:12.5px;min-width:820px}
thead th{background:#f4f7fa;color:#5b6e77;font-weight:700;text-align:left;padding:12px 14px}
tbody td{padding:12px 14px;border-bottom:1px solid #f0f4f6;vertical-align:top}
.badge{display:inline-block;font-size:11px;font-weight:700;border-radius:8px;padding:5px 12px}
.badge.pinjam{background:#fdf0d3;color:#b97b0c}
.badge.kembali{background:#dcfce7;color:#15803d}
.kalkulasi{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;font-size:12px;line-height:1.55}
@media(max-width:640px){.wrap{padding:18px 14px}.top-links .user-chip{display:none}.page-title{font-size:24px}.hero-riw{padding:24px 20px;border-radius:16px}.hero-riw h2{font-size:23px}.hero-stats{grid-template-columns:1fr;gap:10px}}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<nav class="topnav-mhs">
  <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
  <div><b>Sistem Perpustakaan</b><small>UIN Datokarama Palu</small></div>
  <div class="links">
    <span class="chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg><?= htmlspecialchars($nama_user); ?></span>
    <a class="b-ghost" href="index.php?portal=mahasiswa">Katalog Buku</a>
    <a class="b-ghost" href="ganti_password.php">Ganti Password</a>
    <a class="b-red" href="logout.php" onclick="return confirm('Keluar dari sistem?')">Logout</a>
  </div>
</nav>
<div class="mob-header mhs-top">
  <div class="row" style="display:flex;align-items:center;gap:10px">
    <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
    <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
    <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:21px;height:21px"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg></span><span>Halo,<br><?= htmlspecialchars($nama_user); ?></span></div>
  </div>
</div>
<div class="wrap">
  <section class="hero-riw">
    <span class="tag">Portal Mahasiswa &amp; Anggota</span>
    <h2>Riwayat Peminjaman Buku 📖</h2>
    <p>Pantau daftar buku yang sedang Anda pinjam, jadwal tenggat pengembalian, status sanksi denda keterlambatan, dan arsip riwayat peminjaman terdahulu di Perpustakaan UIN Datokarama Palu.</p>
    <div class="hero-stats">
      <div class="hero-stat"><span class="ic a"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></span><div><small>Sedang Dipinjam</small><b><?= (int)$sum_dipinjam; ?> <span>Buku</span></b></div></div>
      <div class="hero-stat"><span class="ic g"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg></span><div><small>Sudah Dikembalikan</small><b><?= (int)$sum_kembali; ?> <span>Buku</span></b></div></div>
      <div class="hero-stat"><span class="ic r"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></span><div><small>Denda Berjalan</small><b style="color:#fca5a5">Rp <?= number_format($sum_denda, 0, ',', '.'); ?></b></div></div>
    </div>
  </section>
  <div class="card" style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;padding:0;overflow:hidden">
    <div style="padding:20px 24px;display:flex;align-items:center;gap:14px">
      <span style="width:36px;height:36px;border-radius:9px;background:#ecfdf5;color:#065f46;display:grid;place-items:center;flex:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="width:19px;height:19px"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg></span>
      <div>
        <h3 class="page-title" style="font-size:17px;margin:0">Riwayat Peminjaman Buku</h3>
        <p class="page-sub" style="margin:2px 0 0">Transparansi status peminjaman dan kalkulasi denda Anda</p>
      </div>
    </div>
    <span style="font-size:12px;font-weight:600;color:#047857;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:99px;padding:5px 14px;white-space:nowrap"><?= (int)$sum_dipinjam; ?> Koleksi Aktif</span>
  </div>
  <div class="card banner"><b>Perhatian Mahasiswa:</b> Pastikan buku dikembalikan ke meja sirkulasi sebelum tanggal jatuh tempo. Keterlambatan pengembalian buku akan dikenakan denda administrasi sebesar <b>Rp <?= number_format($tarif_denda_per_hari, 0, ',', '.'); ?> / hari per buku</b>.</div>
  <div class="card">
    <div class="table-wrap"><table>
      <thead><tr><th style="text-align:center;width:48px">No</th><th>Kode Transaksi</th><th>Buku &amp; Pengarang</th><th>Tanggal Pinjam</th><th>Jatuh Tempo</th><th style="text-align:center">Status</th><th style="text-align:right">Denda Berjalan</th></tr></thead>
      <tbody>
      <?php if (!empty($data_riwayat)): $no=1; foreach ($data_riwayat as $item):
        $terlambat = ($item['status'] === 'Dipinjam' && $item['selisih_hari'] > 0);
      ?>
        <tr>
          <td style="text-align:center;color:#94a3b8"><?= $no++; ?></td>
          <td style="font-family:ui-monospace,Consolas,monospace;font-weight:600;color:#334155">TRX-<?= str_pad(preg_replace('/[^0-9]/','',md5($item['kode_buku'].$item['tgl_pinjam'])) % 10000, 4, '0', STR_PAD_LEFT); ?></td>
          <td><div style="font-weight:600;color:#0f172a"><?= htmlspecialchars($item['judul_buku']); ?></div><div style="font-size:11.5px;color:#64748b"><?= htmlspecialchars($item['kode_buku']); ?></div></td>
          <td style="color:#475569"><?= htmlspecialchars($item['tgl_pinjam']); ?></td>
          <td style="font-weight:600;<?= $terlambat ? 'color:#dc2626' : 'color:#475569' ?>"><?= htmlspecialchars($item['tgl_kembali']); ?></td>
          <td style="text-align:center"><?php if ($item['status'] == 'Dikembalikan'): ?><span class="badge kembali">Dikembalikan</span><?php elseif ($item['selisih_hari'] > 0): ?><span style="display:inline-block;background:#fee2e2;color:#dc2626;border-radius:99px;padding:4px 12px;font-size:11px;font-weight:700">Terlambat (<?= (int)$item['selisih_hari']; ?> Hari)</span><?php else: ?><span class="badge pinjam">Sedang Dipinjam</span><?php endif; ?></td>
          <td style="text-align:right;font-weight:700;color:<?= $item['total_denda'] > 0 ? '#dc2626' : '#64748b'; ?>">Rp <?= number_format($item['total_denda'], 0, ',', '.'); ?></td>
        </tr>
      <?php endforeach; else: ?>
        <tr><td colspan="7" style="text-align:center;color:#94a3b8;padding:30px">Belum ada riwayat peminjaman buku untuk akun Anda.</td></tr>
      <?php endif; ?>
      </tbody>
    </table></div>
  </div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="index.php?portal=mahasiswa"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Beranda</a>
  <a href="riwayat.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Riwayat</a>
  <a href="ganti_password.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg>Akun</a>
</nav>
</body>
</html>
