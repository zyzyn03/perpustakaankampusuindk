<?php
session_start();

// Proteksi Halaman
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

require_once 'koneksi.php';

// Kolom tanggal pengembalian diperlukan agar laporan mengikuti tanggal buku benar-benar dikembalikan.
// IF NOT EXISTS menjaga instalasi lama tetap aman tanpa perlu menghapus/merombak tabel.
mysqli_query($koneksi, "ALTER TABLE tb_peminjaman ADD COLUMN IF NOT EXISTS tanggal_dikembalikan DATE NULL AFTER tanggal_kembali");

// Data pengembalian lama yang belum memiliki tanggal historis diberi tanggal hari ini.
// Ini mempertahankan perilaku halaman pengembalian sebelumnya yang menampilkan tanggal saat proses pengembalian.
mysqli_query($koneksi, "UPDATE tb_peminjaman SET tanggal_dikembalikan = CURDATE() WHERE status_pinjam = 'Dikembalikan' AND tanggal_dikembalikan IS NULL");

// Filter Bulan dan Tahun (Default: Bulan & Tahun saat ini)
$bulan_pilih = isset($_GET['bulan']) ? mysqli_real_escape_string($koneksi, $_GET['bulan']) : date('m');
$tahun_pilih = isset($_GET['tahun']) ? mysqli_real_escape_string($koneksi, $_GET['tahun']) : date('Y');

// Array Nama Bulan Bahasa Indonesia
$nama_bulan = [
    '01' => 'Januari', '02' => 'Februari', '03' => 'Maret', '04' => 'April',
    '05' => 'Mei', '06' => 'Juni', '07' => 'Juli', '08' => 'Agustus',
    '09' => 'September', '10' => 'Oktober', '11' => 'November', '12' => 'Desember'
];

$pesan = '';
$error = '';

// Identitas penandatangan laporan. Disimpan di session agar bisa dipakai
// saat cetak maupun ekspor PDF tanpa mengubah struktur database.
$nama_penandatangan = isset($_SESSION['nama_penandatangan_laporan']) ? trim($_SESSION['nama_penandatangan_laporan']) : '';
$nip_penandatangan  = isset($_SESSION['nip_penandatangan_laporan']) ? trim($_SESSION['nip_penandatangan_laporan']) : '';
$jabatan_penandatangan = isset($_SESSION['jabatan_penandatangan_laporan']) ? trim($_SESSION['jabatan_penandatangan_laporan']) : 'Kepala / Petugas Perpustakaan';

// Teks kop laporan. Disimpan di session agar admin dapat menyesuaikan
// identitas dan keterangan laporan tanpa mengubah database.
$kop_instansi = isset($_SESSION['kop_instansi_laporan']) ? trim($_SESSION['kop_instansi_laporan']) : 'PERPUSTAKAAN UIN DATOKARAMA PALU';
$kop_judul = isset($_SESSION['kop_judul_laporan']) ? trim($_SESSION['kop_judul_laporan']) : 'LAPORAN REKAPITULASI SIRKULASI PEMINJAMAN BUKU';
$kop_keterangan = isset($_SESSION['kop_keterangan_laporan']) ? trim($_SESSION['kop_keterangan_laporan']) : 'Dokumen Laporan Sirkulasi Perpustakaan';
$logo_laporan = isset($_SESSION['logo_laporan']) ? trim($_SESSION['logo_laporan']) : '';

// Logo kop laporan disimpan sebagai file terpisah agar tidak mengubah database.
// Admin cukup memilih gambar dari komputer, lalu gambar dipakai saat cetak dan ekspor PDF.
if (isset($_POST['simpan_kop_laporan'])) {
    $instansi_baru = trim($_POST['kop_instansi'] ?? '');
    $judul_baru = trim($_POST['kop_judul'] ?? '');
    $keterangan_baru = trim($_POST['kop_keterangan'] ?? '');

    if ($instansi_baru === '' || $judul_baru === '' || $keterangan_baru === '') {
        $error = 'Nama instansi, judul laporan, dan keterangan laporan wajib diisi.';
    } else {
        $_SESSION['kop_instansi_laporan'] = $instansi_baru;
        $_SESSION['kop_judul_laporan'] = $judul_baru;
        $_SESSION['kop_keterangan_laporan'] = $keterangan_baru;
        $kop_instansi = $instansi_baru;
        $kop_judul = $judul_baru;
        $kop_keterangan = $keterangan_baru;

        // Upload logo opsional. Format yang diterima: JPG, JPEG, PNG, WEBP.
        if (isset($_FILES['logo_laporan']) && $_FILES['logo_laporan']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file_logo = $_FILES['logo_laporan'];
            if ($file_logo['error'] !== UPLOAD_ERR_OK) {
                $error = 'Logo gagal diunggah. Silakan pilih file gambar yang valid.';
            } else {
                // Tidak ada batas ukuran file logo dari aplikasi.
                // Batas upload bawaan server PHP (upload_max_filesize/post_max_size) tetap berlaku.
                $info_logo = @getimagesize($file_logo['tmp_name']);
                $mime_logo = $info_logo['mime'] ?? '';
                $ekstensi_logo = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp'
                ];

                if (!isset($ekstensi_logo[$mime_logo])) {
                    $error = 'Format logo harus JPG, JPEG, PNG, atau WEBP.';
                } else {
                    $folder_logo = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
                    if (!is_dir($folder_logo)) {
                        @mkdir($folder_logo, 0755, true);
                    }
                    $nama_file_logo = 'logo_laporan.' . $ekstensi_logo[$mime_logo];
                    $path_logo = $folder_logo . DIRECTORY_SEPARATOR . $nama_file_logo;
                    if (@move_uploaded_file($file_logo['tmp_name'], $path_logo)) {
                        // Hapus ekstensi logo lama bila berbeda.
                        foreach (['jpg','png','webp'] as $ext_lama) {
                            $lama = $folder_logo . DIRECTORY_SEPARATOR . 'logo_laporan.' . $ext_lama;
                            if ($lama !== $path_logo && is_file($lama)) { @unlink($lama); }
                        }
                        $logo_laporan = 'uploads/' . $nama_file_logo;
                        $_SESSION['logo_laporan'] = $logo_laporan;
                    } else {
                        $error = 'Logo tidak dapat disimpan. Pastikan folder uploads dapat ditulis oleh PHP.';
                    }
                }
            }
        }

        if ($error === '') {
            $pesan = 'Kop laporan berhasil disimpan.';
        }
    }
}

if (isset($_POST['simpan_penandatangan'])) {
    $nama_baru = trim($_POST['nama_penandatangan'] ?? '');
    $nip_baru  = trim($_POST['nip_penandatangan'] ?? '');
    $jabatan_baru = trim($_POST['jabatan_penandatangan'] ?? '');

    if ($nama_baru === '' || $jabatan_baru === '') {
        $error = 'Nama dan jabatan penandatangan wajib diisi.';
    } else {
        $_SESSION['nama_penandatangan_laporan'] = $nama_baru;
        $_SESSION['nip_penandatangan_laporan'] = $nip_baru;
        $_SESSION['jabatan_penandatangan_laporan'] = $jabatan_baru;
        $nama_penandatangan = $nama_baru;
        $nip_penandatangan = $nip_baru;
        $jabatan_penandatangan = $jabatan_baru;
        $pesan = 'Identitas penandatangan laporan berhasil disimpan.';
    }
}

// Tarif denda aktif. Dipakai untuk menampilkan estimasi denda pada transaksi yang masih dipinjam.
$tarif_denda_per_hari = 1000;
$q_tarif = mysqli_query($koneksi, "SELECT nilai FROM tb_pengaturan WHERE kunci = 'tarif_denda' LIMIT 1");
if ($q_tarif && mysqli_num_rows($q_tarif) > 0) {
    $tarif_denda_per_hari = max(0, (int)mysqli_fetch_assoc($q_tarif)['nilai']);
}

// ==========================================================
// PENGHAPUSAN DATA LAPORAN
// Hanya transaksi yang sudah Dikembalikan yang boleh dihapus.
// Transaksi aktif tidak dihapus agar stok buku dan proses peminjaman tetap aman.
// ==========================================================
if (isset($_POST['hapus_laporan_manual'])) {
    $tanggal_mulai = isset($_POST['tanggal_mulai']) ? trim($_POST['tanggal_mulai']) : '';
    $tanggal_selesai = isset($_POST['tanggal_selesai']) ? trim($_POST['tanggal_selesai']) : '';

    $valid_mulai = DateTime::createFromFormat('Y-m-d', $tanggal_mulai);
    $valid_selesai = DateTime::createFromFormat('Y-m-d', $tanggal_selesai);

    if (!$valid_mulai || !$valid_selesai || $valid_mulai->format('Y-m-d') !== $tanggal_mulai || $valid_selesai->format('Y-m-d') !== $tanggal_selesai) {
        $error = 'Tanggal mulai dan tanggal selesai harus diisi dengan benar.';
    } elseif ($tanggal_mulai > $tanggal_selesai) {
        $error = 'Tanggal mulai tidak boleh lebih besar dari tanggal selesai.';
    } else {
        $mulai_aman = mysqli_real_escape_string($koneksi, $tanggal_mulai);
        $selesai_aman = mysqli_real_escape_string($koneksi, $tanggal_selesai);

        $q_hapus = mysqli_query($koneksi, "DELETE FROM tb_peminjaman
            WHERE status_pinjam = 'Dikembalikan'
            AND tanggal_pinjam BETWEEN '$mulai_aman' AND '$selesai_aman'");

        if ($q_hapus) {
            $jumlah_hapus = mysqli_affected_rows($koneksi);
            $pesan = "Berhasil menghapus $jumlah_hapus riwayat laporan pada periode $tanggal_mulai sampai $tanggal_selesai.";
        } else {
            $error = 'Gagal menghapus data laporan: ' . mysqli_error($koneksi);
        }
    }
}

if (isset($_POST['hapus_semua_laporan'])) {
    $q_hapus_semua = mysqli_query($koneksi, "DELETE FROM tb_peminjaman WHERE status_pinjam = 'Dikembalikan'");
    if ($q_hapus_semua) {
        $jumlah_hapus = mysqli_affected_rows($koneksi);
        $pesan = "Berhasil menghapus seluruh $jumlah_hapus riwayat pengembalian dari laporan. Peminjaman yang masih aktif tetap aman dan tidak dihapus.";
    } else {
        $error = 'Gagal menghapus seluruh riwayat laporan: ' . mysqli_error($koneksi);
    }
}

// Query Ambil Laporan Transaksi
// Ambil SEMUA transaksi dari tb_peminjaman pada periode laporan.
// LEFT JOIN sengaja digunakan agar transaksi tidak hilang dari laporan hanya
// karena data anggota/buku/admin terkait belum ditemukan. Sumber utama laporan
// tetap tb_peminjaman, sehingga jumlah baris laporan harus mengikuti transaksi
// yang benar-benar ada di sistem.
$query_laporan = "SELECT 
                    p.*, 
                    COALESCE(a.nama_lengkap, '(Nama anggota tidak ditemukan)') AS nama_lengkap,
                    COALESCE(b.judul_buku, '(Judul buku tidak ditemukan)') AS judul_buku,
                    COALESCE(adm.nama_admin, '(Petugas tidak ditemukan)') AS nama_admin
                  FROM tb_peminjaman p 
                  LEFT JOIN tb_anggota a ON p.nim = a.nim 
                  LEFT JOIN tb_buku b ON p.kode_buku = b.kode_buku 
                  LEFT JOIN tb_admin adm ON p.id_admin = adm.id_admin 
                  WHERE (
                        (MONTH(p.tanggal_pinjam) = '$bulan_pilih' AND YEAR(p.tanggal_pinjam) = '$tahun_pilih')
                        OR
                        (p.status_pinjam = 'Dikembalikan' AND MONTH(p.tanggal_dikembalikan) = '$bulan_pilih' AND YEAR(p.tanggal_dikembalikan) = '$tahun_pilih')
                  )
                  ORDER BY COALESCE(p.tanggal_dikembalikan, p.tanggal_pinjam) ASC, p.id_transaksi ASC";

$res_laporan = mysqli_query($koneksi, $query_laporan);

// Hitung Rekapitulasi Statistik
$total_transaksi = 0;
$total_denda     = 0;
$total_dikembalikan = 0;
$total_dipinjam  = 0;

$data_rows = [];
while ($row = mysqli_fetch_assoc($res_laporan)) {
    $data_rows[] = $row;
    $total_transaksi++;
    // Total denda terkumpul hanya menghitung denda yang sudah tersimpan saat buku dikembalikan.
    $total_denda += (int)$row['denda'];
    if ($row['status_pinjam'] == 'Dikembalikan') {
        $total_dikembalikan++;
    } else {
        $total_dipinjam++;
    }
}


// ==========================================================
// CETAK / SIMPAN PDF (dialog cetak browser, A4 portrait)
// Membuka halaman siap cetak; pengguna dapat memilih "Microsoft Print to PDF"
// / "Save as PDF" pada dialog cetak. Tidak mengubah database.
// ==========================================================
if (isset($_GET['export']) && $_GET['export'] === 'cetak') {
    $judul_cetak = 'Laporan Sirkulasi - ' . $nama_bulan[$bulan_pilih] . ' ' . $tahun_pilih;
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">';
    echo '<title>' . htmlspecialchars($judul_cetak) . '</title>';
    echo '<style>@page{size:A4 portrait;margin:12mm}*{box-sizing:border-box}body{font-family:"Times New Roman",Times,serif;font-size:9pt;color:#000;margin:0;background:#fff}.kop{text-align:center;margin-bottom:0}.kop table{width:100%;border-collapse:collapse;margin:0}.kop td{border:0;padding:0;vertical-align:middle}.kop-logo{width:75px;text-align:left}.kop-logo img{width:62px;height:62px;object-fit:contain}.kop h1{font-size:15pt;margin:0 0 3px;text-transform:uppercase;letter-spacing:.3px}.kop h2{font-size:9.5pt;margin:0 0 2px}.kop p{font-size:8pt;margin:0}.garis-atas{border-top:1px solid #000;margin-top:14px;padding:8px 0 6px;text-align:center;font-size:10pt;border-bottom:3px solid #000}.recap{width:100%;border-collapse:separate;border-spacing:8px 0;margin:12px -8px 10px;width:calc(100% + 16px)}.recap td{border:1px solid #000;padding:6px 8px;width:25%;text-align:left;vertical-align:top}.recap .lb{font-size:7.5pt;font-weight:bold;text-transform:uppercase;display:block}.recap .vl{font-size:10pt;font-weight:bold;display:block;margin-top:1px}table.data{width:100%;border-collapse:collapse;table-layout:auto}table.data th,table.data td{border:1px solid #000;padding:4px 5px;font-size:7.5pt;vertical-align:middle;overflow-wrap:anywhere}table.data th{font-weight:bold;text-align:center;background:#eee;print-color-adjust:exact;-webkit-print-color-adjust:exact}table.data td.c{text-align:center}table.data td.b{font-weight:bold;text-align:center}table.data td.r{text-align:right}thead{display:table-header-group}tr{page-break-inside:avoid;break-inside:avoid}.ttd{margin-top:28px;text-align:right;page-break-inside:avoid;padding-right:20px}.ttd div{display:inline-block;width:230px;text-align:center;font-size:9pt}.no-print{margin:0 0 12px;padding:8px 12px;background:#eef4ff;border:1px solid #b7cdfb;font:13px Arial,sans-serif}@media print{.no-print{display:none}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}</style></head><body>';
    echo '<div class="no-print">Pilih <b>Microsoft Print to PDF</b> / <b>Save as PDF</b> pada dialog cetak untuk menyimpan sebagai PDF. <a href="#" onclick="window.print();return false;">Cetak ulang</a></div>';

    $logo_cetak_html = '';
    if ($logo_laporan !== '') {
        $logo_abs_cetak = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo_laporan);
        if (is_file($logo_abs_cetak)) {
            $logo_mime_cetak = function_exists('mime_content_type') ? mime_content_type($logo_abs_cetak) : 'image/png';
            $logo_data_cetak = base64_encode((string)@file_get_contents($logo_abs_cetak));
            if ($logo_data_cetak !== '') {
                $logo_cetak_html = '<img src="data:' . htmlspecialchars($logo_mime_cetak, ENT_QUOTES, 'UTF-8') . ';base64,' . $logo_data_cetak . '" alt="Logo">';
            }
        }
    }
    echo '<div class="kop"><table><tr><td class="kop-logo">' . $logo_cetak_html . '</td><td>';
    echo '<h1>' . htmlspecialchars($kop_instansi) . '</h1>';
    echo '<h2>' . htmlspecialchars($kop_judul) . '</h2>';
    echo '<p>Periode: <b>' . htmlspecialchars($nama_bulan[$bulan_pilih] . ' ' . $tahun_pilih) . '</b></p>';
    echo '</td><td class="kop-logo"></td></tr></table></div>';
    echo '<div class="garis-atas">' . htmlspecialchars($kop_keterangan) . '</div>';

    echo '<table class="recap"><tr>';
    echo '<td><span class="lb">Total Peminjaman</span><span class="vl">' . $total_transaksi . ' Transaksi</span></td>';
    echo '<td><span class="lb">Buku Dikembalikan</span><span class="vl">' . $total_dikembalikan . '</span></td>';
    echo '<td><span class="lb">Masih Dipinjam</span><span class="vl">' . $total_dipinjam . '</span></td>';
    echo '<td><span class="lb">Total Denda Terkumpul</span><span class="vl">Rp ' . number_format($total_denda, 0, ',', '.') . '</span></td>';
    echo '</tr></table>';

    echo '<table class="data"><thead><tr><th>No</th><th>ID Transaksi</th><th>Tanggal Pinjam</th><th>Tenggat /<br>Kembali</th><th>NIM</th><th>Nama Peminjam</th><th>Judul Buku</th><th>Status</th><th>Denda</th><th>Petugas</th></tr></thead><tbody>';
    if (count($data_rows) > 0) {
        $no_cetak = 1;
        foreach ($data_rows as $row) {
            $denda_cetak = (int)$row['denda'];
            $label_cetak = 'Denda';
            if ($row['status_pinjam'] === 'Dipinjam' && date('Y-m-d') > $row['tanggal_kembali']) {
                $hari_terlambat_cetak = (new DateTime($row['tanggal_kembali']))->diff(new DateTime(date('Y-m-d')))->days;
                $denda_cetak = $hari_terlambat_cetak * $tarif_denda_per_hari;
                $label_cetak = 'Estimasi';
            }
            $tgl_kembali_cetak = ($row['status_pinjam'] === 'Dikembalikan' && !empty($row['tanggal_dikembalikan'])) ? $row['tanggal_dikembalikan'] : $row['tanggal_kembali'];
            echo '<tr>';
            echo '<td class="c">' . $no_cetak++ . '</td>';
            echo '<td>#TRX-' . (int)$row['id_transaksi'] . '</td>';
            echo '<td class="c">' . date('d-m-Y', strtotime($row['tanggal_pinjam'])) . '</td>';
            echo '<td class="c">' . date('d-m-Y', strtotime($tgl_kembali_cetak)) . '</td>';
            echo '<td>' . htmlspecialchars($row['nim']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_lengkap']) . '</td>';
            echo '<td>' . htmlspecialchars($row['judul_buku']) . '</td>';
            echo '<td class="b">' . htmlspecialchars($row['status_pinjam']) . '</td>';
            echo '<td class="r">' . $label_cetak . ': Rp ' . number_format($denda_cetak, 0, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_admin']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="10" class="c">Tidak ada transaksi peminjaman pada periode ini.</td></tr>';
    }
    echo '</tbody></table>';

    $nama_ttd_cetak = $nama_penandatangan !== '' ? $nama_penandatangan : 'Nama Penandatangan';
    $nip_ttd_cetak = $nip_penandatangan !== '' ? 'NIP. ' . htmlspecialchars($nip_penandatangan) : 'NIP. ________________________';
    $jabatan_ttd_cetak = $jabatan_penandatangan !== '' ? $jabatan_penandatangan : 'Kepala / Petugas Perpustakaan';
    echo '<div class="ttd"><div>Palu, ' . date('d') . ' ' . $nama_bulan[date('m')] . ' ' . date('Y') . '<br>' . htmlspecialchars($jabatan_ttd_cetak) . '<br><br><br><br><b><u>' . htmlspecialchars($nama_ttd_cetak) . '</u></b><br>' . $nip_ttd_cetak . '</div></div>';
    echo '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},300);});</script>';
    echo '</body></html>';
    exit();
}

// ==========================================================
// EXPORT PDF VIA DIALOG CETAK BROWSER
// Menghasilkan laporan siap cetak yang dapat disimpan sebagai PDF.
// Tidak memerlukan pustaka tambahan atau perubahan database.
// ==========================================================
if (isset($_GET['export']) && $_GET['export'] === 'pdf') {
    $filename = 'Laporan_Sirkulasi_' . $nama_bulan[$bulan_pilih] . '_' . $tahun_pilih;
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo '<!DOCTYPE html><html lang="id"><head><meta charset="UTF-8">';
    echo '<title>Laporan Sirkulasi - ' . htmlspecialchars($nama_bulan[$bulan_pilih] . ' ' . $tahun_pilih) . '</title>';
    echo '<style>@page{size:A4 landscape;margin:12mm}*{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;font-size:9pt;color:#000;margin:0}.kop-header{width:100%;border-bottom:3px double #000;padding-bottom:7px;margin-bottom:10px;page-break-inside:avoid}.kop-header table{width:100%;border-collapse:collapse;margin:0}.kop-header td{border:0;padding:0;vertical-align:middle}.kop-logo{width:75px;text-align:left}.kop-logo img{width:62px;height:62px;object-fit:contain}.kop-center{text-align:center}.kop-center h2,.kop-center p{text-align:center;margin:2px 0}.kop-line{border-top:1px solid #000;padding-top:5px;margin-top:6px;text-align:center}.kop-right{width:75px}table{width:100%;border-collapse:collapse;margin-top:12px;table-layout:auto}th,td{border:1px solid #000;padding:4px;vertical-align:middle;overflow-wrap:anywhere}th{font-weight:bold;text-align:center;background:#eee;print-color-adjust:exact;-webkit-print-color-adjust:exact}thead{display:table-header-group}tr{page-break-inside:avoid;break-inside:avoid}.recap{width:100%;margin:12px 0;page-break-inside:avoid}.recap td{border:1px solid #777;text-align:center}.signature{margin-top:28px;text-align:right;page-break-inside:avoid}.signature div{display:inline-block;width:230px;text-align:center}.no-print{margin:0 0 12px;padding:8px 12px;background:#eef4ff;border:1px solid #b7cdfb;font:13px Arial,sans-serif}@media print{.no-print{display:none}body{print-color-adjust:exact;-webkit-print-color-adjust:exact}}</style></head><body><div class="no-print">Pilih <b>Save as PDF / Simpan sebagai PDF</b> pada dialog cetak. Nama berkas: ' . htmlspecialchars($filename . '.pdf', ENT_QUOTES, 'UTF-8') . '</div>';
    $logo_word_html = '';
    if ($logo_laporan !== '') {
        $logo_abs = __DIR__ . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo_laporan);
        if (is_file($logo_abs)) {
            $logo_mime = function_exists('mime_content_type') ? mime_content_type($logo_abs) : 'image/png';
            $logo_data = base64_encode((string)@file_get_contents($logo_abs));
            if ($logo_data !== '') {
                $logo_word_html = '<img src="data:' . htmlspecialchars($logo_mime, ENT_QUOTES, 'UTF-8') . ';base64,' . $logo_data . '" alt="Logo">';
            }
        }
    }
    echo '<div class="kop-header"><table><tr><td class="kop-logo">' . $logo_word_html . '</td><td class="kop-center">';
    echo '<h2>' . htmlspecialchars($kop_instansi) . '</h2>';
    echo '<p><b>' . htmlspecialchars($kop_judul) . '</b></p>';
    echo '<p>Periode: <b>' . htmlspecialchars($nama_bulan[$bulan_pilih] . ' ' . $tahun_pilih) . '</b></p>';
    echo '<div class="kop-line">' . htmlspecialchars($kop_keterangan) . '</div>';
    echo '</td><td class="kop-right"></td></tr></table></div>';
    echo '<table class="recap"><tr>';
    echo '<td><b>TOTAL PEMINJAMAN</b><br>' . $total_transaksi . ' Transaksi</td>';
    echo '<td><b>BUKU DIKEMBALIKAN</b><br>' . $total_dikembalikan . '</td>';
    echo '<td><b>MASIH DIPINJAM</b><br>' . $total_dipinjam . '</td>';
    echo '<td><b>TOTAL DENDA TERKUMPUL</b><br>Rp ' . number_format($total_denda, 0, ',', '.') . '</td>';
    echo '</tr></table>';
    echo '<table><thead><tr><th>No</th><th>ID Transaksi</th><th>Tanggal Pinjam</th><th>Tenggat / Kembali</th><th>NIM</th><th>Nama Peminjam</th><th>Judul Buku</th><th>Status</th><th>Denda</th><th>Petugas</th></tr></thead><tbody>';
    if (count($data_rows) > 0) {
        $no_word = 1;
        foreach ($data_rows as $row) {
            $denda_word = (int)$row['denda'];
            $label_word = 'Denda';
            if ($row['status_pinjam'] === 'Dipinjam' && date('Y-m-d') > $row['tanggal_kembali']) {
                $hari_terlambat_word = (new DateTime($row['tanggal_kembali']))->diff(new DateTime(date('Y-m-d')))->days;
                $denda_word = $hari_terlambat_word * $tarif_denda_per_hari;
                $label_word = 'Estimasi';
            }
            echo '<tr>';
            echo '<td style="text-align:center">' . $no_word++ . '</td>';
            echo '<td>#TRX-' . (int)$row['id_transaksi'] . '</td>';
            echo '<td>' . date('d-m-Y', strtotime($row['tanggal_pinjam'])) . '</td>';
            echo '<td>' . date('d-m-Y', strtotime($row['tanggal_kembali'])) . '</td>';
            echo '<td>' . htmlspecialchars($row['nim']) . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_lengkap']) . '</td>';
            echo '<td>' . htmlspecialchars($row['judul_buku']) . '</td>';
            echo '<td>' . htmlspecialchars($row['status_pinjam']) . '</td>';
            echo '<td>' . $label_word . ': Rp ' . number_format($denda_word, 0, ',', '.') . '</td>';
            echo '<td>' . htmlspecialchars($row['nama_admin']) . '</td>';
            echo '</tr>';
        }
    } else {
        echo '<tr><td colspan="10" style="text-align:center">Tidak ada transaksi peminjaman pada periode ini.</td></tr>';
    }
    echo '</tbody></table>';
    $nama_ttd_word = $nama_penandatangan !== '' ? $nama_penandatangan : '____________________________';
    $nip_ttd_word = $nip_penandatangan !== '' ? 'NIP. ' . $nip_penandatangan : 'NIP. ________________________';
    $jabatan_ttd_word = $jabatan_penandatangan !== '' ? $jabatan_penandatangan : 'Kepala / Petugas Perpustakaan';
    echo '<div class="signature"><div>Palu, ' . date('d') . ' ' . $nama_bulan[date('m')] . ' ' . date('Y') . '<br>' . htmlspecialchars($jabatan_ttd_word) . '<br><br><br><b><u>' . htmlspecialchars($nama_ttd_word) . '</u></b><br>' . htmlspecialchars($nip_ttd_word) . '</div></div>';
    // Unduh PDF langsung ke perangkat menggunakan html2pdf.js.
    // Tidak membuka dialog cetak browser.
    echo '<script src="https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js"></script>';
    echo '<script>
    window.addEventListener("load", function () {
        var target = document.body;
        var bar = document.querySelector(".no-print");
        if (bar) { bar.style.display = "none"; }
        var options = {
            margin: [10, 10, 12, 10],
            filename: ' . json_encode($filename . '.pdf') . ',
            image: { type: "jpeg", quality: 0.98 },
            html2canvas: { scale: 1.5, useCORS: true, logging: false, backgroundColor: "#ffffff" },
            jsPDF: { unit: "mm", format: "a4", orientation: "landscape" },
            pagebreak: { mode: ["css", "legacy"], avoid: ["tr", ".kop-header", ".recap", ".signature"] }
        };
        if (typeof html2pdf === "undefined") {
            document.body.insertAdjacentHTML("afterbegin",
                "<div style=\\"padding:12px;background:#fee2e2;color:#991b1b;font:14px Arial\\">Pustaka PDF tidak dapat dimuat. Periksa koneksi internet lalu muat ulang halaman.</div>");
            return;
        }
        html2pdf().set(options).from(target).save().then(function () {
            // Ekspor dibuka di tab baru; setelah unduhan selesai, tutup tab ekspor.
            // Tab laporan asli tetap terbuka pada halaman laporan.
            setTimeout(function () {
                window.close();
            }, 700);
        }).catch(function () {
            alert("PDF gagal dibuat. Silakan muat ulang halaman dan coba kembali.");
        });
    });
    </script>';
    echo '</body></html>';
    exit();
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Laporan &mdash; Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green-900:#064c40;--green-800:#085f50;--green-700:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e4ebee;--bg:#eff1f2;--card:#fff;--red:#e5484d;--blue:#2563eb}
body{font-family:"Plus Jakarta Sans","Segoe UI",Roboto,Arial,sans-serif;background:var(--bg);color:var(--ink)}
.layout{display:flex;min-height:100vh}
/* COPY: sidebar-start */
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
/* COPY: sidebar-end */
.main{flex:1;min-width:0;margin-left:256px;min-height:100vh;padding-bottom:110px}
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
.page-title{font-size:22px;font-weight:800;letter-spacing:-.3px}
.page-sub{font-size:12.5px;color:var(--muted);margin:4px 0 22px}
.card{background:var(--card);border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(6,76,64,.05);border:1px solid #e8eef0;margin-bottom:18px}
.card h3{font-size:13.5px;font-weight:800;margin-bottom:14px;display:flex;align-items:center;gap:9px;color:#0f172a}
.card h3 svg{width:18px;height:18px;color:#0f172a}
label.flabel{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#334155}
input,select{font:inherit;font-size:12.5px;border:1px solid #cbd5e1;border-radius:8px;padding:9px 13px;color:var(--ink);background:#fff;outline:none;width:100%}
input:focus,select:focus{border-color:var(--green-700);box-shadow:0 0 0 3px rgba(6,76,64,.1)}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;border:0;border-radius:8px;padding:9px 17px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff;background:var(--green-900);transition:filter .15s ease,transform .15s ease}
.btn svg{width:15px;height:15px}
.btn:hover{filter:brightness(1.06)}.btn:active{transform:scale(.98)}
.btn.blue{background:var(--blue)}
.btn.red{background:#dc2626}
.recap{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:20px}
.recap .rc{background:#fff;border-radius:12px;padding:18px 20px;box-shadow:0 1px 3px rgba(6,76,64,.05);border:1px solid #e8eef0;position:relative;overflow:hidden}
.recap .rc::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:#064c40}
.recap .rc:nth-child(2)::before{background:#0891b2}
.recap .rc:nth-child(3)::before{background:#16a34a}
.recap .rc:nth-child(4)::before{background:#dc2626}
.recap b{display:block;font-size:11px;color:#64748b;letter-spacing:.3px;font-weight:700}
.recap .num{font-size:24px;font-weight:800;color:#0f172a;margin-top:4px}
.recap .rc:last-child .num{color:#dc2626}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:900px}
thead th{background:#f8fafc;color:#334155;font-weight:600;text-align:left;padding:11px 14px;border-bottom:1px solid #e2e8f0}
tbody td{padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody tr:hover{background:#f8fafc}
.badge{display:inline-block;font-size:10.5px;font-weight:600;border-radius:999px;padding:4px 11px}
.badge.pinjam{background:#ccfbf1;color:#0f766e}
.badge.kembali{background:#dcfce7;color:#15803d}
.frow{display:flex;gap:12px;align-items:end;flex-wrap:wrap}
.frow>div{flex:1;min-width:150px}
.bottomnav{display:none}
@media(max-width:900px){
.main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
body.nav-open .sidebar{transform:none}
body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
.hamburger{display:block}
.recap{grid-template-columns:1fr 1fr}
}
@media(max-width:640px){
.main{padding-bottom:120px}
.mob-header{display:flex;align-items:center;gap:10px;background:linear-gradient(120deg,#085f50,#064c40);color:#fff;border-radius:0 0 26px 26px;padding:18px 18px 34px}
.mob-header img{width:46px;height:46px;object-fit:contain}
.mob-header b{display:block;font-size:16px;line-height:1.2}
.mob-header small{font-size:11.5px;color:#c4ddd0}
.mob-user{margin-left:auto;font-size:12px}
.topbar{border-radius:14px 14px 0 0;margin-top:-18px;padding:14px 16px}
.topbar h2{font-size:20px}
.user-chip{display:none}
.content{padding:20px 14px}
.recap{grid-template-columns:1fr 1fr}
.bottomnav{display:flex;position:fixed;left:10px;right:10px;bottom:12px;z-index:60;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(11,77,58,.18);padding:10px 6px}
.bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:#93a3aa;font-size:11.5px;font-weight:600;padding:6px 0}
.bottomnav a svg{width:24px;height:24px}
.bottomnav a.on{color:var(--green-900)}
.bottomnav a.on::after{content:"";width:44px;height:3px;border-radius:99px;background:var(--green-900)}
}
</style>
<link rel="stylesheet" href="assets/app.css">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
</head>
<body>
<div class="layout">
<aside class="sidebar" aria-label="Navigasi utama">
<div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
<nav class="side-nav">
<a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
<a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
<a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
<a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
<a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
<a href="laporan.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
<hr>
<a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Lihat Katalog</a>

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
<h2>Laporan</h2>
<div class="topbar-right">
<span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, Petugas Perpustakaan</span>
<a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Logout</a>
</div>
</div>
<div class="content">
<h3 class="page-title">Laporan Sirkulasi Perpustakaan</h3>
<p class="page-sub">Rekap peminjaman bulanan, cetak, dan ekspor PDF.</p>
<?php if (!empty($pesan)): ?><div class="card" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:13px 16px;font-size:13px"><?= htmlspecialchars($pesan); ?></div><?php endif; ?>
<?php if (!empty($error)): ?><div class="card" style="background:#fdf0ec;border:1px solid #efc4b7;color:#9d321f;padding:13px 16px;font-size:13px"><?= htmlspecialchars($error); ?></div><?php endif; ?>
<div class="card">
<h3>Filter Periode</h3>
<form action="laporan.php" method="GET" class="frow">
<div><label class="flabel">Bulan</label><select name="bulan"><?php foreach ($nama_bulan as $kb => $nb): ?><option value="<?= $kb; ?>" <?= $bulan_pilih === (string)$kb ? 'selected' : ''; ?>><?= $nb; ?></option><?php endforeach; ?></select></div>
<div><label class="flabel">Tahun</label><select name="tahun"><?php for ($ty = 2020; $ty <= (int)date('Y') + 1; $ty++): ?><option value="<?= $ty; ?>" <?= (string)$tahun_pilih === (string)$ty ? 'selected' : ''; ?>><?= $ty; ?></option><?php endfor; ?></select></div>
<div style="flex:0 0 auto;align-self:end;display:flex;gap:10px"><button type="submit" class="btn blue">Tampilkan</button><a class="btn" style="background:#16a34a" target="_blank" href="laporan.php?bulan=<?= htmlspecialchars($bulan_pilih); ?>&amp;tahun=<?= htmlspecialchars($tahun_pilih); ?>&amp;export=cetak">Cetak / Simpan PDF</a><a class="btn" href="laporan.php?bulan=<?= htmlspecialchars($bulan_pilih); ?>&amp;tahun=<?= htmlspecialchars($tahun_pilih); ?>&amp;export=pdf">Ekspor PDF</a></div>
</form>
</div>
<div class="recap">
<div class="rc"><b>TOTAL TRANSAKSI</b><span class="num"><?= (int)$total_transaksi; ?></span></div>
<div class="rc"><b>DIPINJAM</b><span class="num"><?= (int)$total_dipinjam; ?></span></div>
<div class="rc"><b>DIKEMBALIKAN</b><span class="num"><?= (int)$total_dikembalikan; ?></span></div>
<div class="rc"><b>TOTAL DENDA</b><span class="num">Rp <?= number_format((int)$total_denda, 0, ',', '.'); ?></span></div>
</div>
<div class="card">
<div style="display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px">
<div>
<div style="display:flex;align-items:center;gap:8px"><span style="width:9px;height:9px;border-radius:99px;background:#064c40"></span><h3 style="margin:0">Pratinjau Data Laporan Sirkulasi &amp; Denda</h3></div>
<p class="page-sub" style="margin:6px 0 0">Periode Registrasi: <?= htmlspecialchars($nama_bulan[$bulan_pilih] ?? $bulan_pilih); ?> <?= htmlspecialchars($tahun_pilih); ?> &bull; Format Kop Surat Baku</p>
</div>
<span class="badge kembali" style="background:#d1fae5;color:#047857;font-weight:600">&#10003; Status Dokumen: Siap Cetak</span>
</div>
<div style="background:#f8fafc;border:1px solid #eef2f1;border-radius:10px;padding:16px;display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:16px">
<div style="display:flex;align-items:center;gap:14px">
<span style="width:48px;height:48px;border-radius:10px;background:#fff;border:1px solid #e2e8f0;display:grid;place-items:center;color:#064c40;flex:none"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" style="width:26px;height:26px"><path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/></svg></span>
<div>
<div style="font-size:12.5px;font-weight:800;color:#064c40;text-transform:uppercase;letter-spacing:.4px"><?= htmlspecialchars($kop_instansi ?: 'KEMENTERIAN AGAMA REPUBLIK INDONESIA'); ?></div>
<div style="font-size:12px;color:#334155;font-weight:600;margin-top:1px"><?= htmlspecialchars($kop_judul ?: 'UPT Pusat Perpustakaan Universitas Islam Negeri Datokarama Palu'); ?></div>
<div style="font-size:11px;color:#94a3b8;margin-top:1px"><?= htmlspecialchars($kop_keterangan ?: 'Jalan Diponegoro No. 23, Kota Palu, Sulawesi Tengah | e-Perpus'); ?></div>
</div>
</div>
<div style="text-align:right;font-size:11px;color:#94a3b8;line-height:1.7">
<span>Tanggal Cetak: <strong style="color:#475569"><?= date('d F Y'); ?></strong></span>
</div>
</div>
<div class="table-wrap"><table>
<thead><tr><th style="text-align:center;width:48px">No</th><th>Kode Transaksi</th><th>Tanggal Pinjam</th><th>Tenggat / Kembali</th><th>NIM &amp; Peminjam</th><th>Judul Buku</th><th style="text-align:center">Status</th><th style="text-align:right">Denda (Rp)</th></tr></thead>
<tbody>
<?php if (!empty($data_rows)): $no = 1; foreach ($data_rows as $dr):
  $st_low = strtolower((string)$dr['status_pinjam']);
  $terlambat = ((int)$dr['denda'] > 0);
?>
<tr><td style="text-align:center;color:#94a3b8"><?= $no++; ?></td><td style="color:#064c40;font-weight:600">TRX-<?= str_pad((string)(int)($dr['id_transaksi'] ?? $no), 4, '0', STR_PAD_LEFT); ?></td><td><?= htmlspecialchars($dr['tanggal_pinjam']); ?></td><td class="<?= $terlambat ? 'tgl-merah' : ''; ?>" style="<?= $terlambat ? 'color:#dc2626;font-weight:600' : ''; ?>"><?= htmlspecialchars($dr['tanggal_dikembalikan'] ?? $dr['tanggal_kembali']); ?></td><td><div style="font-weight:600;color:#0f172a"><?= htmlspecialchars($dr['nama_lengkap']); ?></div><div style="font-size:11px;color:#94a3b8">NIM <?= htmlspecialchars($dr['nim']); ?></div></td><td style="font-weight:500;color:#0f172a"><?= htmlspecialchars($dr['judul_buku']); ?><span style="display:block;font-size:11px;color:#94a3b8"><?= htmlspecialchars($dr['kode_buku']); ?></span></td><td style="text-align:center"><span class="badge <?= $st_low === 'dikembalikan' ? 'kembali' : 'pinjam'; ?>"><?= htmlspecialchars($dr['status_pinjam']); ?></span></td><td style="text-align:right;font-weight:700;color:<?= $terlambat ? '#dc2626' : '#64748b'; ?>">Rp <?= number_format((int)$dr['denda'], 0, ',', '.'); ?></td></tr>
<?php endforeach; else: ?>
<tr><td colspan="8" style="text-align:center;color:#8a99a0;padding:20px">Tidak ada transaksi pada <?= htmlspecialchars($nama_bulan[$bulan_pilih] ?? $bulan_pilih); ?> <?= htmlspecialchars($tahun_pilih); ?>.</td></tr>
<?php endif; ?>
</tbody>
</table></div>
</div>
<div class="card">
<h3>Kop &amp; Penandatangan</h3>
<form action="laporan.php" method="POST" enctype="multipart/form-data" style="display:grid;gap:12px">
<div><label class="flabel">Nama Instansi</label><input type="text" name="kop_instansi" value="<?= htmlspecialchars($kop_instansi); ?>"></div>
<div><label class="flabel">Judul Laporan</label><input type="text" name="kop_judul" value="<?= htmlspecialchars($kop_judul); ?>"></div>
<div><label class="flabel">Keterangan</label><input type="text" name="kop_keterangan" value="<?= htmlspecialchars($kop_keterangan); ?>"></div>
<div><label class="flabel">Logo (JPG/PNG/WEBP)</label><input type="file" name="logo_laporan" accept=".jpg,.jpeg,.png,.webp"></div>
<div><button type="submit" name="simpan_kop_laporan" class="btn">Simpan Kop</button></div>
</form>
<form action="laporan.php" method="POST" class="frow" style="margin-top:16px">
<div><label class="flabel">Nama Penandatangan</label><input type="text" name="nama_penandatangan" value="<?= htmlspecialchars($nama_penandatangan); ?>"></div>
<div><label class="flabel">NIP</label><input type="text" name="nip_penandatangan" value="<?= htmlspecialchars($nip_penandatangan); ?>"></div>
<div><label class="flabel">Jabatan</label><input type="text" name="jabatan_penandatangan" value="<?= htmlspecialchars($jabatan_penandatangan); ?>"></div>
<div style="flex:0 0 auto;align-self:end"><button type="submit" name="simpan_penandatangan" class="btn blue">Simpan Penandatangan</button></div>
</form>
</div>
<div class="card">
<h3>Hapus Laporan Periode</h3>
<form action="laporan.php" method="POST" class="frow" onsubmit="return confirm('Hapus data laporan periode ini?')">
<div><label class="flabel">Dari</label><input type="date" name="tanggal_mulai"></div>
<div><label class="flabel">Sampai</label><input type="date" name="tanggal_selesai"></div>
<div style="flex:0 0 auto;align-self:end;display:flex;gap:10px">
<button type="submit" name="hapus_laporan_manual" class="btn red">Hapus Periode</button>
<button type="submit" name="hapus_semua_laporan" class="btn red" onclick="return confirm('Hapus SEMUA laporan yang sudah dikembalikan?')">Hapus Semua</button>
</div>
</form>
</div>
</div>
</div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
<a href="dashboard.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2.5 10v11h6v-6h7v6h6V10L12 3z"/></svg>Beranda</a>
<a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
<a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Anggota</a>
<a href="laporan.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Laporan</a>
</nav>
<script>"use strict";document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});</script>
</body>
</html>
