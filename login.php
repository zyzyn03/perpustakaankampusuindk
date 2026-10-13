<?php
session_start();
include 'koneksi.php';

// --- LOGIKA PEMROSESAN LOGIN ---
if (isset($_POST['login'])) {$username = mysqli_real_escape_string($koneksi, trim($_POST['username']));
    $password = mysqli_real_escape_string($koneksi, trim($_POST['password']));$role     = isset($_POST['role']) ?$_POST['role'] : 'admin';

    // 1. Deteksi Nama Tabel Admin & Anggota Secara Otomatis
    $tbl_admin = 'tb_admin';$possible_admin_tables = array('tb_admin', 'admin', 'tb_petugas', 'petugas', 'tb_user', 'user');
    foreach ($possible_admin_tables as$t_name) {
        $chk = mysqli_query($koneksi, "SHOW TABLES LIKE '$t_name'");
        if ($chk AND mysqli_num_rows($chk) > 0) {
            $tbl_admin =$t_name;
            break;
        }
    }

    $tbl_anggota = 'tb_anggota';$possible_member_tables = array('tb_anggota', 'anggota', 'tb_mahasiswa', 'mahasiswa');
    foreach ($possible_member_tables as$m_name) {
        $chk_m = mysqli_query($koneksi, "SHOW TABLES LIKE '$m_name'");
        if ($chk_m AND mysqli_num_rows($chk_m) > 0) {
            $tbl_anggota =$m_name;
            break;
        }
    }

    if ($role == 'admin') {
        // --- A. LOGIN PETUGAS / ADMIN ---
        $cols_admin = array();
        $q_cols_adm = mysqli_query($koneksi, "SHOW COLUMNS FROM `$tbl_admin`");
        if ($q_cols_adm) {
            while ($cad = mysqli_fetch_assoc($q_cols_adm)) {
                $cols_admin[] = strtolower($cad['Field']);
            }
        }

        // Cari berdasarkan kolom username/id_admin/id_petugas/user
        $where_adm = array();$possible_id_cols = array('username', 'user', 'id_admin', 'id_petugas', 'id_user');
        foreach ($possible_id_cols as$p_col) {
            if (in_array($p_col, $cols_admin)) {$where_adm[] = "`$p_col` = '$username'";
            }
        }
        $where_sql_adm = !empty($where_adm) ? implode(" OR ", $where_adm) : "`username` = '$username'";

        // Deteksi nama kolom password
        $col_pw_adm = 'password';
        if (in_array('password', $cols_admin)) {$col_pw_adm = 'password'; }
        else if (in_array('pass', $cols_admin)) {$col_pw_adm = 'pass'; }

        $q_admin = mysqli_query($koneksi, "SELECT * FROM `$tbl_admin` WHERE $where_sql_adm LIMIT 1");
        
        if ($q_admin AND mysqli_num_rows($q_admin) > 0) {$d_admin = mysqli_fetch_assoc($q_admin);$pw_db_admin = isset($d_admin[$col_pw_adm]) ? $d_admin[$col_pw_adm] : '';

            // Validasi Password Admin
            if (
                empty($pw_db_admin) OR
                $password ===$pw_db_admin OR 
                md5($password) ===$pw_db_admin OR 
                password_verify($password,$pw_db_admin)
            ) {
                // Setel SEMUA variabel Session Admin lengkap agar lolos validasi di seluruh file admin
                $_SESSION['login']        = true;
                $_SESSION['status']       = 'login';$_SESSION['login_status'] = 'login';
                $_SESSION['status_login'] = 'login';$_SESSION['is_login']     = true;
                $_SESSION['role']         = 'admin';$_SESSION['level']        = 'admin';
                $_SESSION['admin']        = isset($d_admin['username']) ? $d_admin['username'] :$username;
                $_SESSION['admin_login']  = true;
                $_SESSION['petugas']      =$_SESSION['admin'];
                $_SESSION['id_admin']     = isset($d_admin['id_admin']) ? $d_admin['id_admin'] : (isset($d_admin['id_petugas']) ? $d_admin['id_petugas'] : '1');$_SESSION['id_petugas']   = $_SESSION['id_admin'];$_SESSION['id_user']      = $_SESSION['id_admin'];$_SESSION['username']     = $_SESSION['admin'];$_SESSION['user']         = $_SESSION['admin'];$_SESSION['nama']         = isset($d_admin['nama_admin']) ?$d_admin['nama_admin'] : (isset($d_admin['nama_lengkap']) ?$d_admin['nama_lengkap'] : 'Admin');
                $_SESSION['nama_admin']   =$_SESSION['nama'];
                $_SESSION['nama_petugas'] =$_SESSION['nama'];
                $_SESSION['nama_lengkap'] =$_SESSION['nama'];

                // Tentukan Halaman Tujuan Admin yang Tersedia
                $target_page = 'dashboard.php';
                if (file_exists('dashboard.php')) {
                    $target_page = 'dashboard.php';
                } else if (file_exists('peminjaman.php')) {
                    $target_page = 'peminjaman.php';
                } else if (file_exists('buku.php')) {
                    $target_page = 'buku.php';
                } else {
                    $target_page = 'index.php';
                }

                // Paksa penulisan session ke server sebelum browser melakukan redirect
                session_write_close();

                echo "<script>alert('Login Admin Berhasil!'); window.location='$target_page';</script>";
                exit();
            }
        }

        echo "<script>alert('Username atau Password Admin salah!'); window.history.back();</script>";
        exit();

    } else {
        // --- B. LOGIN ANGGOTA / MAHASISWA ---
        $cols_anggota = array();
        $q_cols_a = mysqli_query($koneksi, "SHOW COLUMNS FROM `$tbl_anggota`");
        if ($q_cols_a) {
            while ($ca = mysqli_fetch_assoc($q_cols_a)) {
                $cols_anggota[] = strtolower($ca['Field']);
            }
        }

        $where_mhs = array();
        if (in_array('nim', $cols_anggota)) {$where_mhs[] = "`nim` = '$username'"; }
        if (in_array('id_anggota', $cols_anggota)) {$where_mhs[] = "`id_anggota` = '$username'"; }
        if (in_array('id', $cols_anggota)) {$where_mhs[] = "`id` = '$username'"; }
        $where_sql_mhs = !empty($where_mhs) ? implode(" OR ", $where_mhs) : "`nim` = '$username'";

        $q_mhs = mysqli_query($koneksi, "SELECT * FROM `$tbl_anggota` WHERE $where_sql_mhs LIMIT 1");
        
        if ($q_mhs AND mysqli_num_rows($q_mhs) > 0) {
            $d_mhs = mysqli_fetch_assoc($q_mhs);

            $col_pw_mhs = in_array('pass',$cols_anggota) ? 'pass' : 'password';

            $nim_db   = isset($d_mhs['nim']) ? $d_mhs['nim'] : (isset($d_mhs['id_anggota']) ? $d_mhs['id_anggota'] :$username);
            $pw_db    = isset($d_mhs[$col_pw_mhs]) ?$d_mhs[$col_pw_mhs] : '';$nama_mhs = isset($d_mhs['nama_lengkap']) ?$d_mhs['nama_lengkap'] : (isset($d_mhs['nama']) ?$d_mhs['nama'] : 'Mahasiswa');

            $login_valid = false;

            // ATURAN PASSWORD KETAT MAHASISWA:
            // 1. Jika password DB KOSONG atau SAMA DENGAN NIM (Belum ganti password / habis di-reset Admin)
            if (empty($pw_db) OR $pw_db ===$nim_db) {
                if ($password === $nim_db) {$login_valid = true;
                }
            } else {
                // 2. Jika password DB SUDAH DIGANTI (Tidak sama lagi dengan NIM)
                // HANYA cocokkan dengan password baru! NIM/password lama otomatis ditolak.
                if ($password === $pw_db OR md5($password) === $pw_db OR password_verify($password, $pw_db)) {$login_valid = true;
                }
            }

            if ($login_valid) {$_SESSION['login']        = true;
                $_SESSION['status']       = 'login';$_SESSION['login_status'] = 'login';
                $_SESSION['status_login'] = 'login';$_SESSION['is_login']     = true;
                $_SESSION['role']         = 'mahasiswa';$_SESSION['level']        = 'mahasiswa';
                $_SESSION['username']     =$nim_db;
                $_SESSION['nim']          =$nim_db;
                $_SESSION['nama']         =$nama_mhs;

                session_write_close();

                echo "<script>alert('Selamat Datang, " . addslashes($nama_mhs) . "!'); window.location='riwayat.php';</script>";
                exit();
            } else {
                echo "<script>alert('Password salah! Jika Anda telah mengganti password, gunakan password baru Anda.'); window.history.back();</script>";
                exit();
            }
        } else {
            echo "<script>alert('NIM Mahasiswa tidak ditemukan!'); window.history.back();</script>";
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Login - Sistem Perpustakaan UIN Datokarama Palu</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#064c40;--brand-dark:#04382f;--brand-accent:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e5ebe8}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:#f4f8f6;color:var(--ink);-webkit-tap-highlight-color:transparent}
.login-page{min-height:100vh;display:flex;flex-direction:column;align-items:center;justify-content:center;position:relative;overflow:hidden;padding:40px 20px;background:url("assets/bg-perpus.jpg") center/cover no-repeat,#eef3ee}
.login-page::before{content:"";position:absolute;inset:0;background:linear-gradient(160deg,rgba(8,60,44,.34),rgba(8,60,44,.16) 45%,rgba(8,60,44,.42));pointer-events:none}
.wave{position:absolute;left:0;right:0;z-index:1;pointer-events:none}
.wave.top{top:0}
.wave.bottom{bottom:0}
.wave svg{display:block;width:100%;height:auto}
.login-card{position:relative;z-index:2;width:min(100%,365px);background:#fff;border-radius:24px;padding:26px 24px;box-shadow:0 20px 45px -10px rgba(0,0,0,.18),0 8px 16px -6px rgba(0,0,0,.08);border:1px solid rgba(255,255,255,.6)}
.brand{display:flex;flex-direction:column;align-items:center;text-align:center}
.brand-logo{width:66px;height:56px;margin-bottom:8px;display:block;object-fit:contain}
.brand h1{font-size:21px;font-weight:800;color:var(--brand);letter-spacing:-.3px;line-height:1.25}
.brand p{font-size:13px;font-weight:600;color:#6b7280;margin-top:2px}
.role-switch{display:flex;align-items:center;gap:0;background:#f3f4f6;border:1px solid #e5e7eb;border-radius:999px;padding:4px;margin:20px 0}
.role-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;border:0;background:transparent;border-radius:999px;padding:9px 8px;font:inherit;font-size:12px;font-weight:600;color:#4b5563;cursor:pointer}
.role-btn svg{width:16px;height:16px;flex:none}
.role-btn.active{background:var(--brand);color:#fff;box-shadow:0 2px 6px rgba(11,83,62,.3)}
.field{margin-bottom:15px}
.field label{display:block;font-size:13px;font-weight:800;color:#1f2937;margin-bottom:6px}
.input-wrap{position:relative;display:flex;align-items:center}
.login-card .input-wrap>svg{position:absolute;left:13px;top:50%;transform:translateY(-50%);width:18px;height:18px;color:#9ca3af;pointer-events:none}
.login-card .form-control{width:100%;padding:11px 40px 11px 40px;border:1.5px solid #d1d5db;border-radius:12px;font:inherit;font-size:13.5px;color:#1f2937;background:#fff;outline:none}
.login-card .form-control::placeholder{color:#9ca3af}
.login-card .form-control:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(11,83,62,.10)}
.toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);border:0;background:none;cursor:pointer;color:#9ca3af;padding:6px;border-radius:8px;display:grid;place-items:center}
.toggle-pw:hover{background:#f0f4f3;color:var(--brand)}
.toggle-pw svg{width:17px;height:17px}
.btn-submit{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:12px;background:var(--brand);color:#fff;font:inherit;font-size:14px;font-weight:700;padding:13px;cursor:pointer;margin-top:4px;box-shadow:0 6px 16px rgba(11,83,62,.24)}
.btn-submit:hover{background:var(--brand-dark)}
.btn-submit svg{width:17px;height:17px}
.divider{position:relative;display:flex;align-items:center;justify-content:center;margin:18px 0 14px}
.divider::before{content:"";position:absolute;left:0;right:0;top:50%;height:1px;background:#e5e7eb}
.divider span{position:relative;background:#fff;padding:0 12px;font-size:11px;color:#6b7280;white-space:nowrap}
.btn-katalog{width:100%;display:flex;align-items:center;justify-content:center;gap:8px;border:1px solid #cbe4d7;border-radius:12px;background:#eef6f2;color:var(--brand);font-size:13px;font-weight:700;padding:12px;text-decoration:none;cursor:pointer}
.btn-katalog:hover{background:#e4f0ea}
.btn-katalog svg{width:16px;height:16px}
@media(max-width:420px){.login-card{padding:22px 18px}.brand h1{font-size:19px}.role-btn{font-size:11px;padding:8px 6px}}
@media(min-width:769px){
  .wave{display:none}
  .login-page{background:url("assets/bg-perpus.jpg") center/cover no-repeat,#064c40}
  .login-page::before{background:linear-gradient(120deg,rgba(6,76,64,.55),rgba(6,76,64,.42))}
  .login-card{width:min(100%,440px)}
}
</style>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="assets/app.css">
</head>
<body>
<main class="login-page">
  <div class="wave top" aria-hidden="true">
    <svg fill="none" viewBox="0 0 430 180" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
      <path d="M-20 -20 H450 V65 C340 70 230 45 140 70 C70 89 20 120 -20 135 Z" fill="#04382f" opacity="0.95"></path>
      <path d="M-20 -20 H450 V50 C360 55 260 40 160 58 C85 72 25 105 -20 120 Z" fill="#064c40"></path>
      <path d="M-20 115 C25 100 80 65 160 50 C250 35 350 48 450 42" stroke="white" stroke-linecap="round" stroke-width="2.5" opacity="0.85"></path>
    </svg>
  </div>
  <div class="wave bottom" aria-hidden="true">
    <svg fill="none" viewBox="0 0 430 190" xmlns="http://www.w3.org/2000/svg" preserveAspectRatio="none">
      <path d="M450 200 H-20 V115 C60 125 160 145 250 115 C335 85 390 120 450 100 Z" fill="#04382f" opacity="0.95"></path>
      <path d="M450 200 H-20 V135 C70 140 170 160 260 125 C345 92 395 130 450 110 Z" fill="#064c40"></path>
      <path d="M-20 138 C75 143 175 162 265 125 C345 93 398 126 450 112" stroke="white" stroke-linecap="round" stroke-width="2.5" opacity="0.85"></path>
    </svg>
  </div>

  <section class="login-card" aria-label="Form Login Sistem Perpustakaan">
    <header class="brand">
      <img class="brand-logo" src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu">
      <h1>Sistem Perpustakaan</h1>
      <p>UIN Datokarama Palu</p>
    </header>

    <div class="role-switch" role="tablist" aria-label="Pilih jenis pengguna">
      <button type="button" class="role-btn" id="tab-admin" role="tab" aria-selected="false" onclick="switchRole('admin')">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/><path d="M19 11v3m0 0l2 1m-2-1l-2 1"/></svg>
        Petugas / Operator
      </button>
      <button type="button" class="role-btn active" id="tab-mhs" role="tab" aria-selected="true" onclick="switchRole('mahasiswa')">
        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3L1 9L12 15L21 10.09V17H23V9M5 13.18V17.18C5 19.94 8.13 22 12 22C15.87 22 19 19.94 19 17.18V13.18L12 17L5 13.18Z"/></svg>
        Anggota / Mahasiswa
      </button>
    </div>

    <!-- Kontrak backend: login/role/username/password -->
    <form action="login.php" method="POST" id="loginForm">
      <input type="hidden" name="login" value="1">
      <input type="hidden" name="role" id="role_input" value="mahasiswa">
      <div class="field">
        <label for="inp_user" id="lbl_user">NIM Mahasiswa</label>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
          <input type="text" name="username" id="inp_user" class="form-control" placeholder="Masukkan NIM Mahasiswa" required autocomplete="username">
        </div>
      </div>
      <div class="field">
        <label for="inp_password">Password</label>
        <div class="input-wrap">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
          <input type="password" name="password" id="inp_password" class="form-control" placeholder="Masukkan password" required autocomplete="current-password">
          <button type="button" class="toggle-pw" id="pwToggle" aria-label="Tampilkan password">
            <svg id="eyeIcon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
          </button>
        </div>
      </div>
      <button type="submit" class="btn-submit" id="submitBtn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1" transform="rotate(180 12 12)"/></svg>
        <span>Masuk Ke Sistem</span>
      </button>
    </form>

    <div class="divider"><span>atau cari buku tanpa login</span></div>
    <a href="index.php" class="btn-katalog">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>
      Cari Buku di Katalog Publik
    </a>
  </section>
</main>

<script>
"use strict";
function switchRole(role){
  var a=document.getElementById("tab-admin"),m=document.getElementById("tab-mhs"),
      ri=document.getElementById("role_input"),lu=document.getElementById("lbl_user"),iu=document.getElementById("inp_user");
  if(!a||!m||!ri||!lu||!iu) return;
  if(role==="admin"){
    a.classList.add("active");m.classList.remove("active");
    a.setAttribute("aria-selected","true");m.setAttribute("aria-selected","false");
    ri.value="admin";lu.textContent="NIP / Username Petugas";iu.placeholder="Masukkan NIP atau Username";
  }else{
    m.classList.add("active");a.classList.remove("active");
    m.setAttribute("aria-selected","true");a.setAttribute("aria-selected","false");
    ri.value="mahasiswa";lu.textContent="NIM Mahasiswa";iu.placeholder="Masukkan NIM Mahasiswa";
  }
  iu.focus();
}
document.addEventListener("DOMContentLoaded",function(){
  var t=document.getElementById("pwToggle"),p=document.getElementById("inp_password"),ic=document.getElementById("eyeIcon");
  if(t&&p&&ic){
    t.addEventListener("click",function(){
      if(p.type==="password"){p.type="text";ic.innerHTML='<path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l18 18"/>';}
      else{p.type="password";ic.innerHTML='<path d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/>';}
    });
  }
  var f=document.getElementById("loginForm"),b=document.getElementById("submitBtn");
  if(f&&b){f.addEventListener("submit",function(){b.style.opacity=".8";});}
});
</script>
</body>
</html>
