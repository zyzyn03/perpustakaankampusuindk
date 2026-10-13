<?php
session_start();
if (!isset($_SESSION['admin'])) {
    header('Location: login.php');
    exit();
}
require_once 'koneksi.php';

$config_file = __DIR__ . DIRECTORY_SEPARATOR . 'kta_config.json';
$foto_dir = __DIR__ . DIRECTORY_SEPARATOR . 'foto';
$foto_url = 'foto/';
$desain_dir = __DIR__ . DIRECTORY_SEPARATOR . 'kta_desain';
$desain_url = 'kta_desain/';
$signature_file = __DIR__ . DIRECTORY_SEPARATOR . 'kta_signature.png';
$signature_url = 'kta_signature.png';
$logo_dir = __DIR__;

function cp_h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cp_image_url($base,$id,$file){ return $base.rawurlencode($id).'/'.rawurlencode(basename($file)); }
function cp_public_token($nim,$secret){ return substr(hash_hmac('sha256',(string)$nim,(string)$secret),0,20); }
function cp_best_lan_ip(){
    $candidates=array(); $gateway_ips=array();
    $resolved=@gethostbynamel(gethostname());
    if(is_array($resolved)) foreach($resolved as $ip) $candidates[]=$ip;
    if(function_exists('shell_exec')){
        $out=@shell_exec('ipconfig 2>NUL');
        if(is_string($out)&&$out!==''){
            $blocks=preg_split('/\r?\n\r?\n/',$out);
            foreach($blocks as $blk){
                if(stripos($blk,'Default Gateway')!==false && preg_match('/IPv4 Address[^:]*:\s*([0-9.]+)/i',$blk,$m)){ $gateway_ips[]=$m[1]; $candidates[]=$m[1]; }
            }
            if(preg_match_all('/IPv4 Address[^:]*:\s*([0-9.]+)/i',$out,$mm)) foreach($mm[1] as $ip) $candidates[]=$ip;
        }
    }
    $candidates=array_values(array_unique(array_filter($candidates)));
    $is_private=function($ip){ if(!filter_var($ip,FILTER_VALIDATE_IP,FILTER_FLAG_IPV4))return false; if(strpos($ip,'127.')===0||strpos($ip,'169.254.')===0)return false; if(strpos($ip,'192.168.56.')===0)return false; $p=explode('.',$ip);$a=(int)$p[0];$b=(int)$p[1]; if($a===10)return true; if($a===192&&$b===168)return true; if($a===172&&$b>=16&&$b<=31)return true; return false; };
    foreach($gateway_ips as $ip) if($is_private($ip)) return $ip;
    foreach($candidates as $ip) if($is_private($ip)) return $ip;
    return '';
}
function cp_scan_url($nim,$settings){
    $configured=trim((string)($settings['public_base_url'] ?? ''));
    $base='';
    if($configured!==''){
        $parts=@parse_url($configured);
        if(is_array($parts)&&isset($parts['scheme'],$parts['host'])&&in_array(strtolower($parts['scheme']),array('http','https'),true)&&!isset($parts['user'])&&!isset($parts['pass'])){
            $configured=preg_replace('/[?#].*$/','',$configured); $configured=rtrim($configured,'/');
            $base=preg_match('~/kta\.php$~i',$configured)?$configured:($configured.'/kta.php');
        }
    }
    if($base===''){
        $scheme=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')?'https':'http';
        $host=$_SERVER['HTTP_HOST']??'localhost';
        $host_name=preg_replace('/:\d+$/','',(string)$host);
        if($host_name==='localhost'||$host_name==='127.0.0.1'||$host_name==='::1'){
            $lan_ip=cp_best_lan_ip();
            if($lan_ip!==''){ $port=''; if(isset($_SERVER['SERVER_PORT'])&&(int)$_SERVER['SERVER_PORT']!==($scheme==='https'?443:80)) $port=':'.(int)$_SERVER['SERVER_PORT']; $host=$lan_ip.$port; }
        }
        $dir=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/cetak-kta.php')); $dir=rtrim($dir,'/'); if($dir===''||$dir==='.') $dir='';
        $path=($dir===''?'':$dir).'/kta.php';
        $base=$scheme.'://'.$host.$path;
    }
    $secret=(string)($settings['public_secret']??'');
    return $base.'?scan=1&nim='.rawurlencode((string)$nim).'&t='.rawurlencode(cp_public_token($nim,$secret));
}
function cp_qr_url($scan_url,$size=220,$version=''){
    $url = 'qr-kta.php?size='.(int)$size.'&data='.rawurlencode($scan_url);
    if ($version !== '') $url .= '&v='.rawurlencode((string)$version);
    return $url;
}

$default = array(
    'active_design'=>'', 'designs'=>array(), 'settings'=>array(
        'front_title'=>'KARTU TANDA ANGGOTA PERPUSTAKAAN',
        'front_subtitle'=>'UIN Datokarama Palu',
        'back_title'=>'KETENTUAN KARTU ANGGOTA',
        'back_text'=>'Kartu ini adalah milik perpustakaan. Harap dijaga dan digunakan sesuai ketentuan perpustakaan. Jika ditemukan, mohon dikembalikan ke perpustakaan.',
        'signature_name'=>'Kepala Perpustakaan', 'signature_jabatan'=>'Kepala Perpustakaan',
        'signature_nip'=>'', 'show_signature'=>1, 'logo_ext'=>'', 'public_secret'=>''
    )
);
$config = $default;
if (is_file($config_file)) {
    $raw = @file_get_contents($config_file);
    $json = $raw !== false ? json_decode($raw,true) : null;
    if (is_array($json)) {
        $config = array_merge($config,$json);
        $config['settings'] = array_merge($default['settings'],is_array($json['settings']??null)?$json['settings']:array());
    }
}
$settings = $config['settings'];

$active_id = basename((string)($config['active_design'] ?? ''));
$active = null;
if ($active_id !== '' && isset($config['designs'][$active_id])) {
    $d = $config['designs'][$active_id];
    $dir = $desain_dir.DIRECTORY_SEPARATOR.$active_id;
    $front = basename((string)($d['front']??''));
    $back = basename((string)($d['back']??''));
    if ($front !== '' && $back !== '' && is_file($dir.DIRECTORY_SEPARATOR.$front) && is_file($dir.DIRECTORY_SEPARATOR.$back)) {
        $active = $d;
        $active['id'] = $active_id;
        $active['front'] = $front;
        $active['back'] = $back;
    }
}
if (!$active) {
    echo '<div style="font-family:Segoe UI,sans-serif;max-width:700px;margin:60px auto;padding:20px;text-align:center"><h2>Desain KTA belum tersedia</h2><p>Silakan aktifkan desain dari halaman KTA terlebih dahulu.</p></div>';
    exit();
}

$jurusan = trim($_GET['jurusan'] ?? '');
$angkatan = trim($_GET['angkatan'] ?? '');
$jurusan_db = mysqli_real_escape_string($koneksi,$jurusan);
$angkatan_db = mysqli_real_escape_string($koneksi,$angkatan);

$where = array();
if ($jurusan !== '') $where[] = "program_studi = '$jurusan_db'";
if ($angkatan !== '') $where[] = "LEFT(CAST(nim AS CHAR),2) = '$angkatan_db'";
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';
$res = mysqli_query($koneksi,"SELECT * FROM tb_anggota $where_sql ORDER BY nim ASC");
if (!$res) die('Gagal mengambil data anggota: '.cp_h(mysqli_error($koneksi)));

$programs = array();
$q1 = mysqli_query($koneksi,"SELECT DISTINCT program_studi FROM tb_anggota WHERE program_studi IS NOT NULL AND TRIM(program_studi) <> '' ORDER BY program_studi ASC");
if ($q1) while($r=mysqli_fetch_assoc($q1)) $programs[]=$r['program_studi'];
$angkatan_list = array();
$q2 = mysqli_query($koneksi,"SELECT DISTINCT LEFT(CAST(nim AS CHAR),2) AS angkatan FROM tb_anggota WHERE nim IS NOT NULL AND LENGTH(TRIM(nim)) >= 2 ORDER BY angkatan DESC");
if ($q2) while($r=mysqli_fetch_assoc($q2)) $angkatan_list[]=$r['angkatan'];

$logo_ext = preg_replace('/[^a-z0-9]/i','',(string)($settings['logo_ext']??''));
$logo_path = $logo_ext !== '' ? $logo_dir.DIRECTORY_SEPARATOR.'kta_logo.'.$logo_ext : '';
$has_logo = $logo_path !== '' && is_file($logo_path);
$logo_src = $has_logo ? 'kta_logo.'.$logo_ext : '';
$has_signature = is_file($signature_file) && filesize($signature_file)>0 && !empty($settings['show_signature']);

$students = array();
while($row=mysqli_fetch_assoc($res)) $students[]=$row;

function cp_photo($row,$foto_dir,$foto_url){
    $name = !empty($row['foto']) ? basename($row['foto']) : '';
    $path = $name !== '' ? $foto_dir.DIRECTORY_SEPARATOR.$name : '';
    return ($path !== '' && is_file($path)) ? $foto_url.rawurlencode($name) : '';
}
?>
<!DOCTYPE html>
<html lang="id"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Cetak KTA Keseluruhan</title>
<style>
*{box-sizing:border-box}body{margin:0;padding:24px;background:#f1f5f9;color:#1e293b;font-family:'Segoe UI',Tahoma,sans-serif}.page{max-width:1200px;margin:auto}.top{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}.btn{border:0;border-radius:7px;padding:10px 14px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-block;font-size:13px}.btn-blue{background:#2563eb;color:#fff}.btn-gray{background:#64748b;color:#fff}.btn-green{background:#059669;color:#fff}.panel{background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:18px;margin-bottom:18px;box-shadow:0 2px 8px rgba(15,23,42,.05)}h1{margin:0 0 12px;font-size:24px}.muted{font-size:13px;color:#64748b;line-height:1.5}.filters{display:grid;grid-template-columns:1fr 1fr auto auto;gap:10px;align-items:end;margin-top:12px}.field label{display:block;font-size:12px;font-weight:700;color:#475569;margin-bottom:5px}.field select{width:100%;padding:10px;border:1px solid #cbd5e1;border-radius:6px;background:#fff;font:inherit}.count{margin-top:10px;color:#64748b;font-size:13px}.bulk-preview{display:flex;flex-direction:column;align-items:center;gap:18px}.bulk-sheet{background:#fff;width:min(100%,960px);padding:34px 28px;box-shadow:0 2px 12px rgba(15,23,42,.08);display:flex;justify-content:center;gap:8mm}.card-wrap{text-align:center}.side-label{font-size:11px;font-weight:700;color:#475569;margin-bottom:7px}.kta-card{width:85.6mm;height:53.98mm;position:relative;overflow:hidden;background:#fff;color:var(--kta-text,#111);box-shadow:0 5px 18px rgba(0,0,0,.2);border-radius:10px;-webkit-print-color-adjust:exact;print-color-adjust:exact;--kta-text:#111;--kta-line:rgba(0,0,0,.25)}.kta-card.theme-dark{--kta-text:#fff;--kta-line:rgba(255,255,255,.45)}.kta-card.theme-light{--kta-text:#111827;--kta-line:rgba(0,0,0,.25)}.kta-bg{position:absolute;inset:0;width:100%;height:100%;object-fit:cover;z-index:0}.card-logo{position:absolute;left:7px;top:5px;width:8.5mm;height:8.5mm;object-fit:contain;z-index:4}.watermark{position:absolute;left:50%;top:50%;width:34%;height:55%;object-fit:contain;transform:translate(-50%,-50%);opacity:.09;z-index:1;pointer-events:none}.front-content,.back-content{position:relative;z-index:2;width:100%;height:100%;padding:9px;display:flex;flex-direction:column}.front-content{justify-content:space-between}.header{padding-left:0;padding-right:0;padding-bottom:3px;border-bottom:1px solid var(--kta-line);color:var(--kta-text);text-align:center}.header b{display:block;font-size:9px;text-transform:uppercase;letter-spacing:.25px}.header span{font-size:6.8px;color:var(--kta-text)}.main{display:flex;gap:9px;align-items:flex-start;justify-content:flex-start}.photo{width:18mm;height:24mm;object-fit:cover;border-radius:3px;border:1px solid rgba(0,0,0,.3);background:#e2e8f0;flex:0 0 auto}.info{min-width:0;flex:1;text-align:left}.label{font-size:5.6px;text-transform:uppercase;margin-top:2px;color:var(--kta-text);opacity:.8}.value{font-size:7.8px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--kta-text)}.qr-box{align-self:flex-end;background:#000;border-radius:4px;padding:2px;box-shadow:0 1px 3px rgba(0,0,0,.15)}.qr{display:block;width:15mm;height:15mm;image-rendering:pixelated;object-fit:contain}.qr-wrap{position:relative;width:15mm;height:15mm}.qr-logo{position:absolute;left:50%;top:50%;width:21%;height:21%;transform:translate(-50%,-50%);object-fit:contain;background:#fff;border-radius:4px;padding:1px;z-index:3}.back-content{position:relative;display:block;color:var(--kta-text)}.back-title{font-size:9px;font-weight:800;text-transform:uppercase;text-align:center;color:var(--kta-text)}.back-text{font-size:7px;line-height:1.35;white-space:pre-line;max-width:94%;margin-top:8px;color:var(--kta-text)}.signature-block{position:absolute;right:9px;bottom:3.2mm;text-align:center;min-width:100px;max-width:135px;color:var(--kta-text)}.signature-role{font-size:6.2px;font-weight:700;margin-bottom:1px}.signature-img{width:105px;height:38px;object-fit:contain;display:block;margin:0 auto 1px}.theme-dark .signature-img{filter:brightness(0) invert(1)}.signature-line{padding-top:1px;font-size:6.2px;font-weight:700;color:var(--kta-text)}.signature-nip{font-size:5.8px}.back-footer{position:absolute;left:9px;right:155px;bottom:3.2mm;font-size:6.5px;text-align:left;color:var(--kta-text)}
@media(max-width:900px){.filters{grid-template-columns:1fr 1fr}.bulk-sheet{overflow:auto;justify-content:flex-start}.top{gap:6px}}
@media print{
 @page{size:A4 portrait;margin:0}
 html,body{margin:0!important;padding:0!important;background:#fff!important;width:210mm!important;min-width:210mm!important}
 .no-print{display:none!important}.page{max-width:none!important;margin:0!important;padding:0!important}
 .bulk-preview{display:block!important}.bulk-sheet{width:210mm!important;height:297mm!important;min-height:297mm!important;max-height:297mm!important;margin:0!important;padding:0!important;display:flex!important;align-items:center!important;justify-content:center!important;gap:8mm!important;box-shadow:none!important;page-break-after:always!important;break-after:page!important;overflow:hidden!important}
 .bulk-sheet:last-child{page-break-after:auto!important;break-after:auto!important}
 .side-label{display:none!important}.card-wrap{width:85.6mm!important;min-width:85.6mm!important;max-width:85.6mm!important}.kta-card{width:85.6mm!important;min-width:85.6mm!important;max-width:85.6mm!important;height:53.98mm!important;min-height:53.98mm!important;max-height:53.98mm!important;border-radius:0!important;box-shadow:none!important}
 *{-webkit-print-color-adjust:exact!important;print-color-adjust:exact!important}
}

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
</style><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head><body class="with-dash">
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
            <h1>Cetak KTA</h1>
        </div>
        <div class="dash-top-right">
            <div class="dash-user"><svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.5 3-6 7-6s7 2.5 7 6"/></svg><span>Halo, Petugas Perpustakaan</span></div>
            <a class="dash-logout" href="logout.php"><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Logout</a>
        </div>
    </header>
    <div class="dash-content">
<div class="page">
<div class="top no-print"><a class="btn btn-gray" href="anggota.php">&larr; Kembali ke Data Anggota</a><button class="btn btn-blue" type="button" onclick="window.print()">&#128424; Cetak KTA</button></div>
<div class="panel no-print"><h1>Cetak KTA Keseluruhan</h1><div class="muted">Pilih jurusan dan angkatan. Angkatan diambil otomatis dari <b>2 digit awal NIM</b>, misalnya 246250095 &rarr; 24.</div>
<form method="get" class="filters"><div class="field"><label>Jurusan / Program Studi</label><select name="jurusan"><option value="">Semua Jurusan</option><?php foreach($programs as $p): ?><option value="<?=cp_h($p)?>" <?=($p===$jurusan?'selected':'')?>><?=cp_h($p)?></option><?php endforeach; ?></select></div>
<div class="field"><label>Angkatan</label><select name="angkatan"><option value="">Semua Angkatan</option><?php foreach($angkatan_list as $a): ?><option value="<?=cp_h($a)?>" <?=($a===$angkatan?'selected':'')?>><?=cp_h($a)?></option><?php endforeach; ?></select></div>
<button class="btn btn-gray" type="button" onclick="location.href='cetak-kta.php'">Reset</button><button class="btn btn-green" type="submit">Terapkan Filter</button></form>
<div class="count">Ditemukan <b><?=count($students)?></b> anggota untuk filter ini. Setiap anggota dirender dengan desain KTA yang sama seperti halaman <b>Cetak KTA</b>, termasuk foto (jika ada), logo, QR, watermark, teks otomatis kontras, tanda tangan dan NIP.</div></div>
<div class="panel"><div class="bulk-preview">
<?php foreach($students as $row):
    $nim=(string)$row['nim']; $nama=(string)$row['nama_lengkap']; $prodi=(string)$row['program_studi'];
    $foto_src=cp_photo($row,$foto_dir,$foto_url); $show_photo=!empty($active['show_photo']) && $foto_src!=='';
    $scan=cp_scan_url($nim,$settings); $qr=cp_qr_url($scan,220,$has_logo ? @filemtime($logo_path) : '1');
?>
<div class="bulk-sheet">
 <div class="card-wrap"><div class="side-label">DEPAN</div><div class="kta-card">
  <img class="kta-bg" src="<?=cp_h(cp_image_url($desain_url,$active_id,$active['front']))?>" alt="Depan">
  <?php if($has_logo): ?><img class="watermark" src="<?=cp_h($logo_src)?>?v=<?=filemtime($logo_path)?>" alt="Logo watermark"><img class="card-logo" src="<?=cp_h($logo_src)?>?v=<?=filemtime($logo_path)?>" alt="Logo kampus"><?php endif; ?>
  <div class="front-content"><div class="header"><b><?=cp_h($settings['front_title'])?></b><span><?=cp_h($settings['front_subtitle'])?></span></div><div class="main">
  <?php if($show_photo): ?><img class="photo" src="<?=cp_h($foto_src)?>" alt="Foto mahasiswa"><?php endif; ?><div class="info"><div class="label">Nama</div><div class="value"><?=cp_h($nama)?></div><div class="label">NIM</div><div class="value"><?=cp_h($nim)?></div><div class="label">Program Studi</div><div class="value"><?=cp_h($prodi)?></div></div></div>
  <div class="qr-box"><div class="qr-wrap"><img class="qr" src="<?=cp_h($qr)?>" alt="QR KTA <?=cp_h($nim)?>"><?php if($has_logo): ?><img class="qr-logo" src="<?=cp_h($logo_src)?>?v=<?=filemtime($logo_path)?>" alt="Logo"><?php endif; ?></div></div></div>
 </div></div>
 <div class="card-wrap"><div class="side-label">BELAKANG</div><div class="kta-card">
  <img class="kta-bg" src="<?=cp_h(cp_image_url($desain_url,$active_id,$active['back']))?>" alt="Belakang">
  <?php if($has_logo): ?><img class="watermark" src="<?=cp_h($logo_src)?>?v=<?=filemtime($logo_path)?>" alt="Logo watermark"><?php endif; ?>
  <div class="back-content"><div class="back-title"><?=cp_h($settings['back_title'])?></div><div class="back-text"><?=cp_h($settings['back_text'])?></div>
  <?php if($has_signature): ?><div class="signature-block"><div class="signature-role"><?=cp_h($settings['signature_jabatan'])?></div><img class="signature-img" src="<?=cp_h($signature_url)?>?v=<?=filemtime($signature_file)?>" alt="Tanda tangan"><div class="signature-line"><?=cp_h($settings['signature_name'])?></div><?php if(!empty($settings['signature_nip'])): ?><div class="signature-nip">NIP. <?=cp_h($settings['signature_nip'])?></div><?php endif; ?></div><?php endif; ?>
  <div class="back-footer">NIM: <?=cp_h($nim)?> &middot; <?=cp_h($nama)?></div></div>
 </div></div>
</div>
<?php endforeach; ?>
<?php if(count($students)===0): ?><div class="muted no-print" style="padding:40px;text-align:center">Tidak ada data anggota untuk filter tersebut.</div><?php endif; ?>
</div></div></div>
    </div><!-- /.dash-content -->
</div><!-- /.dash-main -->
<script>
(function(){
 function contrast(card){var bg=card.querySelector('.kta-bg');if(!bg)return;function sample(){try{var c=document.createElement('canvas'),x=c.getContext('2d',{willReadFrequently:true});c.width=64;c.height=40;x.drawImage(bg,0,0,64,40);var d=x.getImageData(0,0,64,40).data,t=0,n=0;for(var i=0;i<d.length;i+=4){if(d[i+3]<10)continue;t+=.2126*d[i]+.7152*d[i+1]+.0722*d[i+2];n++;}var l=n?t/n:255;card.classList.toggle('theme-dark',l<145);card.classList.toggle('theme-light',l>=145);}catch(e){card.classList.add('theme-light')}}if(bg.complete)sample();else bg.addEventListener('load',sample,{once:true});}
 document.querySelectorAll('.kta-card').forEach(contrast);
})();
</script></body></html>
