<?php
session_start();
include 'koneksi.php';

// --- 1. DETEKSI OTOMATIS NAMA TABEL ---
$tbl_anggota = 'tb_anggota';
$chk_a = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_anggota'");
if (!$chk_a OR mysqli_num_rows($chk_a) == 0) {$tbl_anggota = 'anggota';
}

$tbl_buku = 'tb_buku';
$chk_b = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_buku'");
if (!$chk_b OR mysqli_num_rows($chk_b) == 0) {$tbl_buku = 'buku';
}

$tbl_peminjaman = 'tb_peminjaman';
$chk_p = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_peminjaman'");
if (!$chk_p OR mysqli_num_rows($chk_p) == 0) {$tbl_peminjaman = 'peminjaman';
}

$tbl_admin = 'tb_admin';
$chk_adm = mysqli_query($koneksi, "SHOW TABLES LIKE 'tb_admin'");
if (!$chk_adm OR mysqli_num_rows($chk_adm) == 0) {$tbl_admin = 'admin';
}

// --- 2. DETEKSI KOLOM TABEL PEMINJAMAN ---
$kolom_peminjaman = array();
$q_kolom_p = mysqli_query($koneksi, "SHOW COLUMNS FROM $tbl_peminjaman");
if ($q_kolom_p) {
    while ($r = mysqli_fetch_assoc($q_kolom_p)) {
        $kolom_peminjaman[] = strtolower($r['Field']);
    }
}

$col_tgl_pinjam = 'tgl_pinjam';
if (in_array('tanggal_pinjam', $kolom_peminjaman)) {$col_tgl_pinjam = 'tanggal_pinjam';
} else if (in_array('tgl_peminjaman', $kolom_peminjaman)) {$col_tgl_peminjaman = 'tgl_peminjaman';
}

$col_tgl_kembali = 'tgl_kembali';
if (in_array('tgl_harus_kembali', $kolom_peminjaman)) {$col_tgl_kembali = 'tgl_harus_kembali';
} else if (in_array('tanggal_kembali', $kolom_peminjaman)) {$col_tgl_kembali = 'tanggal_kembali';
}

$col_nim_pem = 'nim';
if (in_array('id_anggota', $kolom_peminjaman) AND !in_array('nim', $kolom_peminjaman)) {$col_nim_pem = 'id_anggota';
}

$col_buku_pem = 'kode_buku';
if (in_array('id_buku', $kolom_peminjaman) AND !in_array('kode_buku', $kolom_peminjaman)) {$col_buku_pem = 'id_buku';
}

$col_admin_pem = '';
if (in_array('id_admin', $kolom_peminjaman)) {$col_admin_pem = 'id_admin';
} else if (in_array('id_petugas', $kolom_peminjaman)) {$col_admin_pem = 'id_petugas';
} else if (in_array('id_user', $kolom_peminjaman)) {$col_admin_pem = 'id_user';
}


// --- 3. PROSES SIMPAN TRANSAKSI PEMINJAMAN ---
if (isset($_POST['proses_pinjam'])) {
    $nim         = mysqli_real_escape_string($koneksi, trim($_POST['nim']));
    $tgl_pinjam  = mysqli_real_escape_string($koneksi, $_POST['tgl_pinjam']);
    $tgl_kembali = mysqli_real_escape_string($koneksi, $_POST['tgl_kembali']);

    // Satu transaksi dapat berisi beberapa buku. Form mengirim kode_buku[];
    // tetap menerima kode_buku lama jika ada agar tidak merusak pemakaian sebelumnya.
    $kode_buku_post = isset($_POST['kode_buku']) ? $_POST['kode_buku'] : array();
    if (!is_array($kode_buku_post)) {
        $kode_buku_post = array($kode_buku_post);
    }

    $kode_buku_list = array();
    $kode_buku_seen = array();
    foreach ($kode_buku_post as $kode) {
        $kode = trim($kode);
        if ($kode !== '') {
            if (isset($kode_buku_seen[strtolower($kode)])) {
                echo "<script>alert('Gagal: kode buku \"" . addslashes($kode) . "\" dimasukkan lebih dari satu kali dalam transaksi.'); window.history.back();</script>";
                exit;
            }
            $kode_buku_seen[strtolower($kode)] = true;
            $kode_buku_list[] = mysqli_real_escape_string($koneksi, $kode);
        }
    }

    if (empty($kode_buku_list)) {
        echo "<script>alert('Gagal: minimal satu kode buku harus diisi!'); window.history.back();</script>";
        exit;
    }

    // A. Cari Data Anggota
    $where_a = array();
    $q_cols_a = mysqli_query($koneksi, "SHOW COLUMNS FROM $tbl_anggota");
    if ($q_cols_a) {
        while ($c = mysqli_fetch_assoc($q_cols_a)) {
            $f = $c['Field'];
            $where_a[] = "TRIM(`$f`) = '$nim'";
        }
    }
    
    $sql_a = "SELECT * FROM $tbl_anggota WHERE " . implode(" OR ", $where_a);
    $q_anggota = mysqli_query($koneksi, $sql_a);

    if (!$q_anggota OR mysqli_num_rows($q_anggota) == 0) {
        echo "<script>alert('Gagal: NIM / Data Anggota \"$nim\" tidak ditemukan!'); window.history.back();</script>";
        exit;
    }
    $d_anggota = mysqli_fetch_assoc($q_anggota);
    $val_nim   = isset($d_anggota['nim']) ? $d_anggota['nim'] : (isset($d_anggota['id_anggota']) ? $d_anggota['id_anggota'] : $nim);
    $val_nim_esc = mysqli_real_escape_string($koneksi, (string)$val_nim);

    // ATURAN: satu mahasiswa hanya boleh meminjam 1 unit untuk 1 judul buku.
    // Jika judul yang diminta sudah sedang dipinjam aktif oleh mahasiswa yang
    // sama, transaksi ditolak. (Duplikat dalam satu transaksi sudah dicek di atas.)
    if (in_array('status_pinjam', $kolom_peminjaman)) {
        $judul_aktif = array();
        foreach ($kode_buku_list as $kode_cek) {
            $q_cek = mysqli_query($koneksi, "SELECT 1 FROM $tbl_peminjaman
                WHERE `$col_nim_pem` = '$val_nim_esc' AND `$col_buku_pem` = '$kode_cek'
                AND (LOWER(status_pinjam) = 'dipinjam' OR status_pinjam IS NULL OR status_pinjam = '')
                LIMIT 1");
            if ($q_cek AND mysqli_num_rows($q_cek) > 0) { $judul_aktif[] = $kode_cek; }
        }
        if (!empty($judul_aktif)) {
            $daftar = addslashes(implode(', ', array_unique($judul_aktif)));
            echo "<script>alert('Gagal: mahasiswa ini masih meminjam judul buku ($daftar). Satu mahasiswa hanya boleh meminjam 1 unit untuk 1 judul. Silakan pilih judul lain.'); window.history.back();</script>";
            exit;
        }
    }

    // B. Cari ID Admin Valid
    $val_admin = '';
    if (isset($_SESSION['id_admin'])) {
        $val_admin = $_SESSION['id_admin'];
    } else if (isset($_SESSION['id_user'])) {
        $val_admin = $_SESSION['id_user'];
    } else if (isset($_SESSION['id_petugas'])) {
        $val_admin = $_SESSION['id_petugas'];
    } else if (isset($_SESSION['id'])) {
        $val_admin = $_SESSION['id'];
    }

    if (empty($val_admin)) {
        $q_get_adm = mysqli_query($koneksi, "SELECT * FROM $tbl_admin LIMIT 1");
        if ($q_get_adm AND mysqli_num_rows($q_get_adm) > 0) {
            $d_get_adm = mysqli_fetch_assoc($q_get_adm);
            $val_admin = isset($d_get_adm['id_admin']) ? $d_get_adm['id_admin'] : reset($d_get_adm);
        }
    }

    // C. Siapkan kolom transaksi yang tetap sama dengan struktur database lama.
    $fields = array("`$col_nim_pem`", "`$col_buku_pem`", "`$col_tgl_pinjam`", "`$col_tgl_kembali`");
    if (!empty($col_admin_pem) AND !empty($val_admin)) {
        $fields[] = "`$col_admin_pem`";
    }
    if (in_array('status_pinjam', $kolom_peminjaman)) {
        $fields[] = "`status_pinjam`";
    }

    // D. Simpan setiap buku sebagai satu baris transaksi.
    // Ini penting agar modul pengembalian dan laporan yang sudah ada tetap membaca
    // setiap buku sebagai data transaksi tersendiri.
    mysqli_begin_transaction($koneksi);
    $berhasil = 0;
    $error_db = '';

    foreach ($kode_buku_list as $kode_buku) {
        // Cari data buku berdasarkan kolom kode_buku saja agar kode selalu
        // menunjuk ke judul buku yang tepat dan tidak tertukar dengan kolom lain.
        $sql_b = "SELECT * FROM $tbl_buku WHERE kode_buku = '$kode_buku' LIMIT 1";
        $q_buku = mysqli_query($koneksi, $sql_b);

        if (!$q_buku OR mysqli_num_rows($q_buku) == 0) {
            $error_db = "Kode Buku \"$kode_buku\" tidak ditemukan.";
            break;
        }

        $d_buku   = mysqli_fetch_assoc($q_buku);
        $val_buku = $d_buku['kode_buku'];

        // Jangan izinkan peminjaman jika seluruh stok buku sedang dipinjam.
        // Stok pada tb_buku adalah jumlah eksemplar yang masih tersedia.
        $stok_tersedia = isset($d_buku['stok']) ? (int)$d_buku['stok'] : (isset($d_buku['jumlah']) ? (int)$d_buku['jumlah'] : 0);
        if ($stok_tersedia <= 0) {
            $error_db = "Buku \"$val_buku\" belum tersedia karena seluruh stok sedang dipinjam.";
            break;
        }

        $values = array("'$val_nim'", "'$val_buku'", "'$tgl_pinjam'", "'$tgl_kembali'");
        if (!empty($col_admin_pem) AND !empty($val_admin)) {
            $values[] = "'" . mysqli_real_escape_string($koneksi, $val_admin) . "'";
        }
        if (in_array('status_pinjam', $kolom_peminjaman)) {
            $values[] = "'Dipinjam'";
        }

        $sql_insert = "INSERT INTO $tbl_peminjaman (" . implode(", ", $fields) . ") VALUES (" . implode(", ", $values) . ")";
        if (!mysqli_query($koneksi, $sql_insert)) {
            $error_db = mysqli_error($koneksi);
            break;
        }

        $berhasil++;

        // Kurangi stok satu untuk setiap buku yang berhasil dipinjam.
        $q_stok_col = mysqli_query($koneksi, "SHOW COLUMNS FROM $tbl_buku LIKE 'stok'");
        if ($q_stok_col AND mysqli_num_rows($q_stok_col) > 0) {
            // Kurangi stok berdasarkan kode_buku yang sama persis.
            $q_update_stok = mysqli_query($koneksi, "UPDATE $tbl_buku SET stok = stok - 1 WHERE kode_buku='$val_buku' AND stok > 0 LIMIT 1");
            if (!$q_update_stok || mysqli_affected_rows($koneksi) !== 1) {
                $error_db = "Stok buku \"$val_buku\" sudah tidak tersedia. Peminjaman dibatalkan.";
                break;
            }
        }
    }

    if ($berhasil === count($kode_buku_list) AND empty($error_db)) {
        mysqli_commit($koneksi);
        $jumlah_berhasil = $berhasil;
        echo "<script>alert('Peminjaman berhasil diproses untuk $jumlah_berhasil buku!'); window.location='peminjaman.php';</script>";
        exit;
    }

    mysqli_rollback($koneksi);
    $err = addslashes($error_db !== '' ? $error_db : 'Transaksi tidak dapat disimpan.');
    echo "<script>alert('Gagal memproses peminjaman: $err'); window.history.back();</script>";
    exit;
}

// --- 4. AMBIL DAFTAR PEMINJAMAN (HANYA YANG BERSTATUS DIPINJAM) ---
if (in_array('status_pinjam', $kolom_peminjaman)) {$q_daftar = mysqli_query($koneksi, "SELECT * FROM $tbl_peminjaman WHERE LOWER(status_pinjam) = 'dipinjam' OR status_pinjam IS NULL OR status_pinjam = '' ORDER BY 1 DESC");
} else {
    $q_daftar = mysqli_query($koneksi, "SELECT * FROM $tbl_peminjaman ORDER BY 1 DESC");
}

$tgl_today   = date('Y-m-d');$tgl_kembali = date('Y-m-d', strtotime('+7 days'));
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Peminjaman &mdash; Perpustakaan UIN Datokarama Palu</title>
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
.btn-logout{display:flex;align-items:center;gap:7px;background:var(--red);color:#fff;border:0;border-radius:8px;padding:8px 15px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none}
.btn-logout svg{width:15px;height:15px}
.btn-logout:hover{background:#d23b40}
.mob-header{display:none}
.content{padding:30px 32px;max-width:1220px}
.page-title{font-size:22px;font-weight:800;letter-spacing:-.3px}
.page-sub{font-size:12.5px;color:var(--muted);margin:4px 0 22px}
.card{background:var(--card);border-radius:14px;padding:24px;box-shadow:0 1px 3px rgba(6,76,64,.05);border:1px solid #e8eef0;margin-bottom:20px}
.form-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px}
label.flabel{display:block;font-size:12px;font-weight:700;margin-bottom:7px;color:#334155}
label.flabel svg{display:none}
input,select{font:inherit;font-size:12.5px;border:1px solid #cbd5e1;border-radius:10px;padding:10px 14px;color:var(--ink);background:#fff;outline:none;width:100%}
input:focus,select:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(37,99,235,.12)}
input::placeholder{color:#9aa8ae}
.iwrap{position:relative;display:flex;align-items:center}
.iwrap>svg{position:absolute;left:12px;width:15px;height:15px;color:#94a3b8;pointer-events:none}
.iwrap input{padding-left:36px}
.form-actions{display:flex;flex-wrap:wrap;align-items:flex-end;justify-content:space-between;gap:16px;margin-top:22px}
.form-actions .btn-wrap{display:flex;gap:10px}
.date-row{display:grid;grid-template-columns:auto auto;gap:16px}
.date-row label.flabel{font-size:11px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:10px;padding:11px 20px;font:inherit;font-size:12.5px;font-weight:700;cursor:pointer;text-decoration:none;color:#fff;background:var(--green-900);transition:filter .15s ease,transform .15s ease}
.btn svg{width:16px;height:16px}
.btn:hover{filter:brightness(1.06)}.btn:active{transform:scale(.98)}
.btn.blue{background:var(--blue)}
.btn.red{background:var(--red)}
.btn.yellow{background:var(--yellow)}
.btn.ghost{background:#fff;color:#475569;border:1px solid #e2e8f0;font-weight:600}
.btn.sm{padding:5px 11px;font-size:11px;border-radius:6px}
.list-head{display:flex;align-items:center;gap:10px;margin-bottom:18px;flex-wrap:wrap}
.list-head h3{font-size:15px;font-weight:800;display:flex;align-items:center;gap:9px}
.list-head h3 svg{width:19px;height:19px;color:var(--green-900)}
.list-head .searchbar{display:flex;gap:8px;margin-left:auto}
.list-head .swrap{position:relative}
.list-head .swrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8}
.list-head .swrap input{padding-left:36px;min-width:280px}
.table-wrap{overflow-x:auto;border:1px solid #f1f5f9;border-radius:12px}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}
thead th{background:#f1f5f9;color:#334155;font-weight:700;text-align:left;padding:12px 14px;border-bottom:1px solid #e2e8f0}
tbody td{padding:13px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody tr:hover{background:#f8fafc}
.badge{display:inline-block;font-size:10.5px;font-weight:700;border-radius:999px;padding:4px 12px}
.badge.pinjam{background:#f59e0b;color:#fff}
.badge.kembali{background:#dcfce7;color:#15803d}
.acts{display:flex;gap:6px;flex-wrap:wrap;justify-content:center}
.pager{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:18px;font-size:12px;color:var(--muted)}
.pager .pg{display:flex;gap:7px}
.pager .pg a,.pager .pg span{min-width:28px;height:28px;display:grid;place-items:center;border-radius:6px;background:#fff;border:1px solid var(--line);font-size:11px;font-weight:700;color:#4c626b;text-decoration:none;padding:0 6px}
.pager .pg .on{background:var(--blue);border-color:var(--blue);color:#fff}
.bottomnav{display:none}
@media(max-width:900px){
  .main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
  body.nav-open .sidebar{transform:none}
  body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
  .hamburger{display:block}
  .form-grid{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
  .main{padding-bottom:120px}
  .mob-header{display:flex;align-items:center;gap:10px;background:linear-gradient(120deg,#085f50,#064c40);color:#fff;border-radius:0 0 26px 26px;padding:18px 18px 34px}
  .mob-header img{width:46px;height:46px;object-fit:contain}
  .mob-header b{display:block;font-size:16px;line-height:1.2}
  .mob-header small{font-size:11.5px;color:#c4ddd0}
  .mob-user{margin-left:auto;display:flex;align-items:center;gap:8px;font-size:12px}
  .mob-user .ava{width:42px;height:42px;border-radius:50%;background:#fff;color:var(--green-800);display:grid;place-items:center}
  .mob-user .ava svg{width:24px;height:24px}
  .topbar{border-radius:14px 14px 0 0;margin-top:-18px;padding:14px 16px}
  .topbar h2{font-size:21px}
  .user-chip{display:none}
  .content{padding:18px 12px}
  .page-title{font-size:22px}
  .form-grid{grid-template-columns:1fr}
  .date-row{grid-template-columns:1fr 1fr}
  .list-head .searchbar{margin-left:0;width:100%}
  .list-head .swrap{flex:1}
  .list-head .swrap input{min-width:0}
  .bottomnav{display:flex;position:fixed;left:10px;right:10px;bottom:12px;z-index:60;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(11,77,58,.18);padding:10px 6px}
  .bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:#93a3aa;font-size:11.5px;font-weight:600;padding:6px 0}
  .bottomnav a svg{width:24px;height:24px}
  .bottomnav a.on{color:var(--green-900)}
  .bottomnav a.on::after{content:"";width:44px;height:3px;border-radius:99px;background:var(--green-900)}
}
@media(max-width:480px){.date-row{grid-template-columns:1fr}}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<div class="layout">
  <aside class="sidebar" aria-label="Navigasi utama">
    <div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
    <nav class="side-nav">
      <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
      <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
      <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
      <a href="peminjaman.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
      <a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
      <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
      <hr>
      <a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg>Lihat Katalog</a>
    </nav>
    <div class="side-art"><img src="assets/logo-bunga.png" alt="Ilustrasi buku"></div>
  </aside>
  <div class="main">
    <div class="mob-header">
      <div class="row">
        <button class="mob-menu-btn" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16"/></svg></button>
        <img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
        <div><b>Perpustakaan</b><small>UIN Datokarama Palu</small></div>
        <div class="mob-user"><span class="ava"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0"/></svg></span><span>Halo,<br>Petugas Perpustakaan</span></div>
      </div>
    </div>
    <div class="topbar">
      <button class="hamburger" aria-label="Buka menu" onclick="document.body.classList.toggle('nav-open')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg></button>
      <h2>Peminjaman</h2>
      <div class="topbar-right">
        <span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, Petugas Perpustakaan&nbsp;&#9662;</span>
        <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Logout</a>
      </div>
    </div>
    <div class="content">
      <h3 class="page-title">Transaksi Peminjaman Buku</h3>
      <p class="page-sub">Kelola data peminjaman buku perpustakaan dengan mudah dan cepat.</p>
      <div class="card">
        <!-- Kontrak: proses_pinjam + nim/tgl_pinjam/tgl_kembali/kode_buku[] -->
        <form action="peminjaman.php" method="POST" id="formPinjam">
          <div class="form-grid">
            <div><label class="flabel" for="nim">Nim / Nama anggota</label><div class="iwrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg><input type="text" id="nim" name="nim" placeholder="Masukan NIM atau nama anggota..." required></div></div>
            <div><label class="flabel" for="kode">Kode Buku / Judul Buku ke-1</label><div class="iwrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg><input type="text" id="kode" name="kode_buku[]" placeholder="Masukkan kode buku atau judul ke-1..." required></div></div>
            <div><label class="flabel" for="jml">Jumlah Judul Buku Dipinjam</label><input type="number" id="jml" min="1" max="10" value="1" inputmode="numeric" oninput="syncBukuFields()"><div id="bukuHint" style="font-size:11px;color:#94a3b8;margin-top:5px">Satu judul = 1 unit per mahasiswa.</div></div>
          </div>
          <!-- Kolom judul buku tambahan muncul otomatis sesuai "Jumlah Judul Buku Dipinjam".
               Satu judul = satu unit. Kode buku dikirim sebagai kode_buku[] (array). -->
          <div id="bukuTambahan" style="display:grid;gap:14px;margin-top:14px"></div>
          <div class="form-actions">
            <div class="btn-wrap">
              <button type="submit" name="proses_pinjam" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M5 13l4 4L19 7"/></svg>Proses Peminjaman</button>
              <button type="reset" class="btn ghost"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>Reset</button>
            </div>
            <div class="date-row">
              <div><label class="flabel" for="tp">Tanggal Pinjaman</label><input type="date" id="tp" name="tgl_pinjam" value="<?= htmlspecialchars($tgl_today); ?>"></div>
              <div><label class="flabel" for="tk">Tanggal Pengembalian</label><input type="date" id="tk" name="tgl_kembali" value="<?= htmlspecialchars($tgl_kembali); ?>"></div>
            </div>
          </div>
        </form>
      </div>
      <div class="card">
        <div class="list-head">
          <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>Daftar Peminjaman Buku</h3>
          <form class="searchbar" action="peminjaman.php" method="GET">
            <div class="swrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg><input type="search" name="q" placeholder="Cari berdasarkan NIM, nama, atau judul buku..."></div>
            <button type="submit" class="btn blue sm" style="padding:10px 18px;font-size:12.5px">Cari</button>
          </form>
        </div>
        <div class="table-wrap"><table>
          <thead><tr><th>No</th><th>NIM</th><th>Nama Peminjam</th><th>Kode &amp; Judul Buku</th><th>Tanggal Pinjam</th><th>Tanggal Kembali</th><th>Status</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if ($q_daftar && mysqli_num_rows($q_daftar) > 0): $no = 1; while ($row = mysqli_fetch_assoc($q_daftar)):
            $r_nim = $row[$col_nim_pem] ?? ($row['nim'] ?? '-');
            $r_kode = $row[$col_buku_pem] ?? ($row['kode_buku'] ?? '-');
            $r_tp = $row[$col_tgl_pinjam] ?? ($row['tanggal_pinjam'] ?? '-');
            $r_tk = $row[$col_tgl_kembali] ?? ($row['tanggal_kembali'] ?? '-');
            $r_id = $row['id_transaksi'] ?? ($row['id_peminjaman'] ?? '');
            $nama_pem = '-'; $judul_bk = '-';
            $qa = mysqli_query($koneksi, "SELECT nama_lengkap FROM $tbl_anggota WHERE nim='" . mysqli_real_escape_string($koneksi, $r_nim) . "' LIMIT 1");
            if ($qa && $da = mysqli_fetch_assoc($qa)) { $nama_pem = $da['nama_lengkap'] ?? '-'; }
            $qb = mysqli_query($koneksi, "SELECT judul_buku FROM $tbl_buku WHERE kode_buku='" . mysqli_real_escape_string($koneksi, $r_kode) . "' LIMIT 1");
            if ($qb && $db = mysqli_fetch_assoc($qb)) { $judul_bk = $db['judul_buku'] ?? '-'; }
            $st = strtolower(trim((string)($row['status_pinjam'] ?? 'dipinjam')));
            $dipinjam = ($st === '' || $st === 'dipinjam');
          ?>
            <tr><td><?= $no++; ?></td><td><b><?= htmlspecialchars($r_nim); ?></b></td><td><?= htmlspecialchars($nama_pem); ?></td><td><div style="font-weight:700"><?= htmlspecialchars($judul_bk); ?></div><div style="font-size:11px;color:#64748b"><code style="font-family:inherit;background:none;padding:0;color:#0f766e;font-weight:700"><?= htmlspecialchars($r_kode); ?></code></div></td><td><?= htmlspecialchars($r_tp); ?></td><td><?= htmlspecialchars($r_tk); ?></td><td style="text-align:center"><span class="badge <?= $dipinjam ? 'pinjam' : 'kembali'; ?>"><?= $dipinjam ? 'Dipinjam' : 'Dikembalikan'; ?></span></td>
              <td><div class="acts">
                <?php if ($dipinjam): ?>
                <form action="pengembalian.php" method="POST" style="display:inline"><input type="hidden" name="id_transaksi_perpanjang" value="<?= htmlspecialchars($r_id); ?>"><input type="hidden" name="durasi_perpanjang" value="7"><button type="submit" name="proses_perpanjang" class="btn blue sm">Perpanjang</button></form>
                <form action="pengembalian.php" method="POST" style="display:inline"><input type="hidden" name="id_transaksi" value="<?= htmlspecialchars($r_id); ?>"><button type="submit" name="proses_kembali" class="btn red sm">Kembalikan</button></form>
                <?php else: ?><span style="color:#8a99a0;font-size:11px">-</span><?php endif; ?>
              </div></td></tr>
          <?php endwhile; else: ?>
            <tr><td colspan="8" style="text-align:center;color:#8a99a0;padding:20px">Belum ada peminjaman aktif.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
        <div class="pager"><span>Data peminjaman aktif perpustakaan</span></div>
      </div>
    </div>
  </div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2.5 10v11h6v-6h7v6h6V10L12 3z"/></svg>Beranda</a>
  <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19V5.5A1.5 1.5 0 0 1 5.5 4H18v13H5.5A1.5 1.5 0 0 0 4 18.5z"/><path d="M4 19a1.5 1.5 0 0 0 1.5 1.5H20V17"/></svg>Data Buku</a>
  <a href="peminjaman.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6V3z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></svg>Peminjaman</a>
  <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l4 4v14H6V3z"/><path d="M14 3v5h5M9 13h7M9 17h7"/></svg>Laporan</a>
</nav>
<script>"use strict";document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});
function bukuFieldHtml(i){
  return '<div class="buku-item">'
    +'<label class="flabel">Kode Buku / Judul Buku ke-'+i+'</label>'
    +'<div class="iwrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg><input type="text" name="kode_buku[]" placeholder="Masukkan kode buku atau judul ke-'+i+'..." required></div>'
    +'</div>';
}
function syncBukuFields(){
  var jmlEl=document.getElementById('jml'), wrap=document.getElementById('bukuTambahan');
  if(!jmlEl||!wrap) return;
  var jml=parseInt(jmlEl.value,10); if(isNaN(jml)||jml<1) jml=1; if(jml>10) jml=10; jmlEl.value=jml;
  var extra=jml-1; // field pertama sudah ada di HTML statis
  var items=wrap.querySelectorAll('.buku-item'), current=items.length;
  if(extra>current){ for(var i=current+2;i<=jml;i++) wrap.insertAdjacentHTML('beforeend',bukuFieldHtml(i)); }
  else if(extra<current){ for(var j=current-1;j>=extra;j--) items[j].remove(); }
  wrap.querySelectorAll('.buku-item').forEach(function(el,idx){
    var lab=el.querySelector('label'); if(lab) lab.textContent='Kode Buku / Judul Buku ke-'+(idx+2);
    var inp=el.querySelector('input'); if(inp) inp.required=true;
  });
  var hint=document.getElementById('bukuHint');
  if(hint){ hint.textContent = jml>1 ? ('Wajib diisi: '+jml+' judul berbeda. Satu judul = 1 unit per mahasiswa.') : 'Satu judul = 1 unit per mahasiswa.'; }
}
document.addEventListener('DOMContentLoaded',syncBukuFields);
window.addEventListener('load',syncBukuFields);
</script>
</body>
</html>
