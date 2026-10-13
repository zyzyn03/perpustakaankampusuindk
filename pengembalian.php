<?php
session_start();

// Proteksi Halaman
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

require_once 'koneksi.php';

$pesan = "";
$error = "";

// ============================================================
// PENGELOLAAN RIWAYAT PENGEMBALIAN
//
// Prinsip keamanan:
// - Riwayat TIDAK dihapus dari database.
// - Sistem hanya menyembunyikan riwayat dari tabel ini (soft delete).
// - Laporan dan riwayat mahasiswa tetap aman.
// - Admin memiliki 2 cara: MANUAL dan OTOMATIS.
// ============================================================

// Pastikan tabel pengaturan tersedia.
mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS `tb_pengaturan` (
    `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
    `kunci` VARCHAR(50) NOT NULL,
    `nilai` VARCHAR(255) NOT NULL
)");

// Tambahkan penanda riwayat dan waktu pengembalian jika belum ada.
$cek_kolom_riwayat = mysqli_query($koneksi, "SHOW COLUMNS FROM `tb_peminjaman` LIKE 'riwayat_dihapus'");
if ($cek_kolom_riwayat && mysqli_num_rows($cek_kolom_riwayat) === 0) {
    mysqli_query($koneksi, "ALTER TABLE `tb_peminjaman` ADD `riwayat_dihapus` TINYINT(1) NOT NULL DEFAULT 0");
}

$cek_kolom_tanggal = mysqli_query($koneksi, "SHOW COLUMNS FROM `tb_peminjaman` LIKE 'tanggal_dikembalikan'");
if ($cek_kolom_tanggal && mysqli_num_rows($cek_kolom_tanggal) === 0) {
    mysqli_query($koneksi, "ALTER TABLE `tb_peminjaman` ADD `tanggal_dikembalikan` DATETIME NULL DEFAULT NULL");
}

// Kolom pendukung perpanjangan peminjaman. Jika belum ada, sistem menambahkannya otomatis.
$cek_kolom_perpanjangan = mysqli_query($koneksi, "SHOW COLUMNS FROM `tb_peminjaman` LIKE 'jumlah_perpanjangan'");
if ($cek_kolom_perpanjangan && mysqli_num_rows($cek_kolom_perpanjangan) === 0) {
    mysqli_query($koneksi, "ALTER TABLE `tb_peminjaman` ADD `jumlah_perpanjangan` TINYINT(2) NOT NULL DEFAULT 0");
}

$cek_kolom_perpanjangan_tgl = mysqli_query($koneksi, "SHOW COLUMNS FROM `tb_peminjaman` LIKE 'tanggal_perpanjangan_terakhir'");
if ($cek_kolom_perpanjangan_tgl && mysqli_num_rows($cek_kolom_perpanjangan_tgl) === 0) {
    mysqli_query($koneksi, "ALTER TABLE `tb_peminjaman` ADD `tanggal_perpanjangan_terakhir` DATETIME NULL DEFAULT NULL");
}

// Pengaturan otomatis. Pengaturan lama (mode/tanggal) tidak lagi dipakai.
$setting_riwayat_default = [
    'riwayat_auto_aktif'    => '0',
    'riwayat_auto_jumlah'   => '30',
    'riwayat_auto_satuan'   => 'hari',
    'riwayat_auto_terakhir' => ''
];

foreach ($setting_riwayat_default as $kunci_default => $nilai_default) {
    $kunci_aman = mysqli_real_escape_string($koneksi, $kunci_default);
    $nilai_aman = mysqli_real_escape_string($koneksi, $nilai_default);
    $cek = mysqli_query($koneksi, "SELECT id FROM tb_pengaturan WHERE kunci = '$kunci_aman' LIMIT 1");
    if ($cek && mysqli_num_rows($cek) === 0) {
        mysqli_query($koneksi, "INSERT INTO tb_pengaturan (kunci, nilai) VALUES ('$kunci_aman', '$nilai_aman')");
    }
}

$setting_riwayat = $setting_riwayat_default;
$q_riwayat_setting = mysqli_query($koneksi, "SELECT kunci, nilai FROM tb_pengaturan WHERE kunci LIKE 'riwayat_auto_%'");
if ($q_riwayat_setting) {
    while ($sr = mysqli_fetch_assoc($q_riwayat_setting)) {
        if (array_key_exists($sr['kunci'], $setting_riwayat)) {
            $setting_riwayat[$sr['kunci']] = $sr['nilai'];
        }
    }
}

$auto_aktif = ($setting_riwayat['riwayat_auto_aktif'] === '1');
$auto_jumlah = max(1, min((int)$setting_riwayat['riwayat_auto_jumlah'], 3650));
$auto_satuan = in_array($setting_riwayat['riwayat_auto_satuan'], ['hari', 'minggu', 'bulan'], true)
    ? $setting_riwayat['riwayat_auto_satuan']
    : 'hari';
$auto_terakhir = $setting_riwayat['riwayat_auto_terakhir'];

// ============================================================
// MANUAL: BERSIHKAN RIWAYAT BERDASARKAN TANGGAL / RENTANG TANGGAL
// Admin cukup memilih tanggal mulai dan tanggal akhir.
// Jika keduanya sama, hanya satu hari yang dibersihkan.
// ============================================================
if (isset($_POST['hapus_riwayat_manual'])) {
    $tanggal_mulai_manual = isset($_POST['manual_tanggal_mulai']) ? trim($_POST['manual_tanggal_mulai']) : '';
    $tanggal_akhir_manual = isset($_POST['manual_tanggal_akhir']) ? trim($_POST['manual_tanggal_akhir']) : '';

    $dt_mulai = DateTime::createFromFormat('Y-m-d', $tanggal_mulai_manual);
    $dt_akhir = DateTime::createFromFormat('Y-m-d', $tanggal_akhir_manual);
    $valid_mulai = $dt_mulai && $dt_mulai->format('Y-m-d') === $tanggal_mulai_manual;
    $valid_akhir = $dt_akhir && $dt_akhir->format('Y-m-d') === $tanggal_akhir_manual;

    if (!$valid_mulai || !$valid_akhir) {
        $error = 'Tanggal mulai dan tanggal akhir harus diisi dengan benar.';
    } elseif ($dt_mulai > $dt_akhir) {
        $error = 'Tanggal mulai tidak boleh lebih besar dari tanggal akhir.';
    } else {
        $awal_manual = $tanggal_mulai_manual . ' 00:00:00';
        $akhir_manual = date('Y-m-d H:i:s', strtotime($tanggal_akhir_manual . ' +1 day'));
        $awal_aman = mysqli_real_escape_string($koneksi, $awal_manual);
        $akhir_aman = mysqli_real_escape_string($koneksi, $akhir_manual);

        $kondisi_tanggal = "COALESCE(tanggal_dikembalikan, tanggal_kembali) >= '$awal_aman'
                             AND COALESCE(tanggal_dikembalikan, tanggal_kembali) < '$akhir_aman'";

        if ($tanggal_mulai_manual === $tanggal_akhir_manual) {
            $label_periode = date('d-m-Y', strtotime($tanggal_mulai_manual));
        } else {
            $label_periode = date('d-m-Y', strtotime($tanggal_mulai_manual)) . ' s/d ' . date('d-m-Y', strtotime($tanggal_akhir_manual));
        }

        $query_hapus_manual = "UPDATE tb_peminjaman
                               SET riwayat_dihapus = 1
                               WHERE status_pinjam = 'Dikembalikan'
                                 AND riwayat_dihapus = 0
                                 AND $kondisi_tanggal";

        if (mysqli_query($koneksi, $query_hapus_manual)) {
            $jumlah_manual = mysqli_affected_rows($koneksi);
            $pesan = $jumlah_manual > 0
                ? "Riwayat $label_periode berhasil dibersihkan ($jumlah_manual riwayat). Data transaksi tetap tersimpan."
                : "Tidak ada riwayat yang perlu dibersihkan pada $label_periode.";
        } else {
            $error = 'Gagal membersihkan riwayat: ' . mysqli_error($koneksi);
        }
    }
}

// ============================================================
// MANUAL: HAPUS SEMUA RIWAYAT YANG TAMPIL DI BAGIAN INI
// ============================================================
if (isset($_POST['hapus_semua_riwayat'])) {
    $query_hapus_semua = "UPDATE tb_peminjaman
                          SET riwayat_dihapus = 1
                          WHERE status_pinjam = 'Dikembalikan'
                            AND riwayat_dihapus = 0";
    if (mysqli_query($koneksi, $query_hapus_semua)) {
        $jumlah_dihapus = mysqli_affected_rows($koneksi);
        $pesan = $jumlah_dihapus > 0
            ? "Seluruh riwayat pengembalian pada bagian ini berhasil dibersihkan ($jumlah_dihapus riwayat). Data transaksi tetap tersimpan."
            : 'Tidak ada riwayat pengembalian yang perlu dibersihkan.';
    } else {
        $error = 'Gagal membersihkan seluruh riwayat: ' . mysqli_error($koneksi);
    }
}

// ============================================================
// OTOMATIS: BERSIHKAN RIWAYAT YANG SUDAH MELEWATI BATAS USIA
// Dijalankan sekali per hari ketika halaman admin dibuka.
// ============================================================
if ($auto_aktif && $auto_terakhir !== date('Y-m-d')) {
    $satuan_sql = ['hari' => 'DAY', 'minggu' => 'WEEK', 'bulan' => 'MONTH'][$auto_satuan] ?? 'DAY';
    $interval_sql = $auto_jumlah . ' ' . $satuan_sql;

    $query_auto = "UPDATE tb_peminjaman
                   SET riwayat_dihapus = 1
                   WHERE status_pinjam = 'Dikembalikan'
                     AND riwayat_dihapus = 0
                     AND COALESCE(tanggal_dikembalikan, tanggal_kembali) < DATE_SUB(NOW(), INTERVAL $interval_sql)";

    if (mysqli_query($koneksi, $query_auto)) {
        $jumlah_auto = mysqli_affected_rows($koneksi);
        mysqli_query($koneksi, "UPDATE tb_pengaturan SET nilai = '" . date('Y-m-d') . "' WHERE kunci = 'riwayat_auto_terakhir'");
        $setting_riwayat['riwayat_auto_terakhir'] = date('Y-m-d');

        if ($jumlah_auto > 0 && empty($pesan) && empty($error)) {
            $pesan = "Pembersihan otomatis selesai. $jumlah_auto riwayat lama dibersihkan.";
        }
    }
}

// ============================================================
// SIMPAN PENGATURAN OTOMATIS
// ============================================================
if (isset($_POST['simpan_pengaturan_riwayat'])) {
    $aktif = isset($_POST['riwayat_auto_aktif']) ? '1' : '0';
    $jumlah = isset($_POST['riwayat_auto_jumlah']) ? (int)$_POST['riwayat_auto_jumlah'] : 30;
    $jumlah = max(1, min($jumlah, 3650));
    $satuan = isset($_POST['riwayat_auto_satuan']) ? $_POST['riwayat_auto_satuan'] : 'hari';
    if (!in_array($satuan, ['hari', 'minggu', 'bulan'], true)) {
        $satuan = 'hari';
    }

    $pengaturan_simpan = [
        'riwayat_auto_aktif'  => $aktif,
        'riwayat_auto_jumlah' => (string)$jumlah,
        'riwayat_auto_satuan' => $satuan
    ];

    foreach ($pengaturan_simpan as $kunci => $nilai) {
        $k = mysqli_real_escape_string($koneksi, $kunci);
        $v = mysqli_real_escape_string($koneksi, $nilai);
        mysqli_query($koneksi, "UPDATE tb_pengaturan SET nilai = '$v' WHERE kunci = '$k'");
    }

    // Jalankan lagi pemeriksaan otomatis pada hari berikutnya.
    mysqli_query($koneksi, "UPDATE tb_pengaturan SET nilai = '' WHERE kunci = 'riwayat_auto_terakhir'");

    $auto_aktif = ($aktif === '1');
    $auto_jumlah = $jumlah;
    $auto_satuan = $satuan;
    $pesan = $auto_aktif
        ? "Pembersihan otomatis aktif. Riwayat akan disimpan selama $jumlah " . ucfirst($satuan) . "."
        : 'Pembersihan otomatis dinonaktifkan. Riwayat hanya akan dibersihkan secara manual.';
}

// ============================================================
// DATA UNTUK PREVIEW MANUAL
// Default: seluruh bulan sebelumnya agar mudah digunakan.
// Admin tetap dapat mengubahnya menjadi tanggal/rentang tanggal apa pun.
// ============================================================
$bulan_sebelumnya = strtotime('first day of last month');
$manual_tanggal_mulai_pilih = isset($_GET['manual_tanggal_mulai']) ? trim($_GET['manual_tanggal_mulai']) : date('Y-m-01', $bulan_sebelumnya);
$manual_tanggal_akhir_pilih = isset($_GET['manual_tanggal_akhir']) ? trim($_GET['manual_tanggal_akhir']) : date('Y-m-t', $bulan_sebelumnya);

$dt_preview_mulai = DateTime::createFromFormat('Y-m-d', $manual_tanggal_mulai_pilih);
$dt_preview_akhir = DateTime::createFromFormat('Y-m-d', $manual_tanggal_akhir_pilih);
$valid_preview_mulai = $dt_preview_mulai && $dt_preview_mulai->format('Y-m-d') === $manual_tanggal_mulai_pilih;
$valid_preview_akhir = $dt_preview_akhir && $dt_preview_akhir->format('Y-m-d') === $manual_tanggal_akhir_pilih;

if (!$valid_preview_mulai) {
    $manual_tanggal_mulai_pilih = date('Y-m-01', $bulan_sebelumnya);
    $dt_preview_mulai = DateTime::createFromFormat('Y-m-d', $manual_tanggal_mulai_pilih);
}
if (!$valid_preview_akhir) {
    $manual_tanggal_akhir_pilih = date('Y-m-t', $bulan_sebelumnya);
    $dt_preview_akhir = DateTime::createFromFormat('Y-m-d', $manual_tanggal_akhir_pilih);
}

if ($dt_preview_mulai > $dt_preview_akhir) {
    // Jangan membuat admin bingung: bila rentang terbalik, gunakan satu hari dari tanggal mulai.
    $manual_tanggal_akhir_pilih = $manual_tanggal_mulai_pilih;
    $dt_preview_akhir = DateTime::createFromFormat('Y-m-d', $manual_tanggal_akhir_pilih);
}

$manual_awal_preview = $manual_tanggal_mulai_pilih . ' 00:00:00';
$manual_akhir_preview = date('Y-m-d H:i:s', strtotime($manual_tanggal_akhir_pilih . ' +1 day'));
$manual_awal_aman = mysqli_real_escape_string($koneksi, $manual_awal_preview);
$manual_akhir_aman = mysqli_real_escape_string($koneksi, $manual_akhir_preview);
$manual_kondisi_preview = "COALESCE(tanggal_dikembalikan, tanggal_kembali) >= '$manual_awal_aman'
                          AND COALESCE(tanggal_dikembalikan, tanggal_kembali) < '$manual_akhir_aman'";

if ($manual_tanggal_mulai_pilih === $manual_tanggal_akhir_pilih) {
    $manual_label_preview = date('d-m-Y', strtotime($manual_tanggal_mulai_pilih));
} else {
    $manual_label_preview = date('d-m-Y', strtotime($manual_tanggal_mulai_pilih)) . ' s/d ' . date('d-m-Y', strtotime($manual_tanggal_akhir_pilih));
}

$query_manual_count = "SELECT COUNT(*) AS jumlah
                       FROM tb_peminjaman
                       WHERE status_pinjam = 'Dikembalikan'
                         AND riwayat_dihapus = 0
                         AND $manual_kondisi_preview";
$res_manual_count = mysqli_query($koneksi, $query_manual_count);
$jumlah_manual_preview = $res_manual_count ? (int)mysqli_fetch_assoc($res_manual_count)['jumlah'] : 0;

// --- 1. PROSES UPDATE TARIF DENDA OLEH ADMIN ---
if (isset($_POST['update_denda'])) {
    $tarif_baru = (int)preg_replace('/\D/', '', (string)($_POST['tarif_denda'] ?? ''));
    if ($tarif_baru < 1) { $tarif_baru = 1000; $error = 'Tarif denda tidak valid, memakai nilai default Rp 1.000.'; }
    
    // Cek apakah tabel pengaturan sudah ada, jika belum buat otomatis
    mysqli_query($koneksi, "CREATE TABLE IF NOT EXISTS `tb_pengaturan` (
      `id` INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
      `kunci` VARCHAR(50) NOT NULL,
      `nilai` VARCHAR(255) NOT NULL
    )");

    $cek_setting = mysqli_query($koneksi, "SELECT * FROM tb_pengaturan WHERE kunci = 'tarif_denda'");
    if (mysqli_num_rows($cek_setting) > 0) {
        mysqli_query($koneksi, "UPDATE tb_pengaturan SET nilai = '$tarif_baru' WHERE kunci = 'tarif_denda'");
    } else {
        mysqli_query($koneksi, "INSERT INTO tb_pengaturan (kunci, nilai) VALUES ('tarif_denda', '$tarif_baru')");
    }
    
    if (empty($error)) { $pesan = "Tarif denda berhasil diperbarui menjadi Rp " . number_format($tarif_baru, 0, ',', '.') . " per hari!"; }
}

// Ambil tarif denda aktif dari database (default 1000 jika belum diatur)
$q_set = mysqli_query($koneksi, "SELECT nilai FROM tb_pengaturan WHERE kunci = 'tarif_denda' LIMIT 1");
if ($q_set && mysqli_num_rows($q_set) > 0) {
    $tarif_denda_per_hari = intval(mysqli_fetch_assoc($q_set)['nilai']);
} else {
    $tarif_denda_per_hari = 1000;
}

// 2. PROSES PERPANJANGAN PEMINJAMAN
// Kontrol perpanjangan sepenuhnya berada di tangan admin:
// - Admin menentukan sendiri berapa hari tambahan yang diberikan.
// - Tidak ada pilihan durasi tetap seperti 7/14 hari.
// - Tidak ada batas jumlah berapa kali transaksi boleh diperpanjang.
// - Transaksi yang sudah terlambat tetap tidak dapat diperpanjang.
if (isset($_POST['proses_perpanjang'])) {
    $id_perpanjang = isset($_POST['id_transaksi_perpanjang']) ? trim($_POST['id_transaksi_perpanjang']) : '';
    $durasi_perpanjang = isset($_POST['durasi_perpanjang']) ? (int)$_POST['durasi_perpanjang'] : 0;
    $id_aman = mysqli_real_escape_string($koneksi, $id_perpanjang);

    // Admin bebas menentukan durasi, tetapi tetap diberi batas pengaman agar tidak salah input.
    // Maksimum 3650 hari (10 tahun) cukup longgar untuk kebutuhan perpustakaan.
    if ($durasi_perpanjang < 1 || $durasi_perpanjang > 3650) {
        $error = 'Jumlah hari perpanjangan harus antara 1 sampai 3650 hari.';
    } else {
        $q_perpanjang = mysqli_query($koneksi, "SELECT id_transaksi, tanggal_kembali, status_pinjam, jumlah_perpanjangan
            FROM tb_peminjaman
            WHERE id_transaksi = '$id_aman' AND status_pinjam = 'Dipinjam' LIMIT 1");

        if ($q_perpanjang && mysqli_num_rows($q_perpanjang) === 1) {
            $data_perpanjang = mysqli_fetch_assoc($q_perpanjang);
            $jumlah_saat_ini = isset($data_perpanjang['jumlah_perpanjangan']) ? (int)$data_perpanjang['jumlah_perpanjangan'] : 0;
            $tgl_jatuh_tempo = new DateTime($data_perpanjang['tanggal_kembali']);
            $tgl_hari_ini = new DateTime(date('Y-m-d'));

            if ($tgl_hari_ini > $tgl_jatuh_tempo) {
                $error = 'Peminjaman yang sudah terlambat tidak dapat diperpanjang. Silakan proses pengembalian terlebih dahulu.';
            } else {
                $tgl_baru = clone $tgl_jatuh_tempo;
                $tgl_baru->modify('+' . $durasi_perpanjang . ' days');
                $tgl_baru_sql = $tgl_baru->format('Y-m-d');

                $query_update_perpanjang = "UPDATE tb_peminjaman SET
                    tanggal_kembali = '$tgl_baru_sql',
                    jumlah_perpanjangan = jumlah_perpanjangan + 1,
                    tanggal_perpanjangan_terakhir = NOW()
                    WHERE id_transaksi = '$id_aman' AND status_pinjam = 'Dipinjam'";

                if (mysqli_query($koneksi, $query_update_perpanjang)) {
                    $nomor_ke = $jumlah_saat_ini + 1;
                    $pesan = "Peminjaman berhasil diperpanjang $durasi_perpanjang hari. Tenggat baru: " . $tgl_baru->format('d-m-Y') . " (perpanjangan ke-$nomor_ke).";
                } else {
                    $error = 'Gagal memperpanjang peminjaman: ' . mysqli_error($koneksi);
                }
            }
        } else {
            $error = 'Data peminjaman tidak ditemukan atau sudah dikembalikan.';
        }
    }
}

// 3. PROSES PENGEMBALIAN BUKU
if (isset($_POST['proses_kembali'])) {$id_transaksi = mysqli_real_escape_string($koneksi,$_POST['id_transaksi']);

    // Cek detail transaksi
    $query_trx = mysqli_query($koneksi, "SELECT * FROM tb_peminjaman WHERE id_transaksi = '$id_transaksi' AND status_pinjam = 'Dipinjam'");
    if (mysqli_num_rows($query_trx) === 1) {$data_trx    = mysqli_fetch_assoc($query_trx);$kode_buku   = $data_trx['kode_buku'];$tgl_kembali = new DateTime($data_trx['tanggal_kembali']);$tgl_sekarang= new DateTime(date('Y-m-d'));

        // Hitung denda jika terlambat
        $denda = 0;
        $selisih_hari = 0;
        if ($tgl_sekarang > $tgl_kembali) {$selisih_hari = $tgl_sekarang->diff($tgl_kembali)->days;
            $denda        = $selisih_hari * $tarif_denda_per_hari;
        }

        // Update status transaksi menjadi Dikembalikan dan simpan nilai denda
        $query_update = "UPDATE tb_peminjaman SET 
                            status_pinjam = 'Dikembalikan', 
                            denda = '$denda',
                            tanggal_dikembalikan = NOW()
                         WHERE id_transaksi = '$id_transaksi'";

        if (mysqli_query($koneksi,$query_update)) {
            // Otomatis tambah kembali stok buku (+1)
            mysqli_query($koneksi, "UPDATE tb_buku SET stok = stok + 1 WHERE kode_buku = '$kode_buku'");
            
            if ($denda > 0) {$pesan = "Buku berhasil dikembalikan! Terlambat $selisih_hari hari, Denda: <b>Rp " . number_format($denda, 0, ',', '.') . "</b>. Stok buku bertambah 1.";
            } else {
                $pesan = "Buku berhasil dikembalikan tepat waktu (Tanpa Denda). Stok buku bertambah 1.";
            }
        } else {
            $error = "Gagal memproses pengembalian: " . mysqli_error($koneksi);
        }
    } else {
        $error = "Data transaksi peminjaman tidak ditemukan atau sudah dikembalikan!";
    }
}

// Pencarian data peminjaman (NIM anggota / kode buku)
$cari_q     = isset($_GET['q']) ? trim($_GET['q']) : '';
$cari_jenis = (isset($_GET['jenis']) && $_GET['jenis'] === 'kode') ? 'kode' : 'nim';
$filter_cari = '';
if ($cari_q !== '') {
    $q_aman = mysqli_real_escape_string($koneksi, $cari_q);
    $kolom_cari = ($cari_jenis === 'kode') ? 'p.kode_buku' : 'p.nim';
    $filter_cari = " AND $kolom_cari LIKE '%$q_aman%'";
}

// Ambil transaksi yang sedang dipinjam
$query_pinjam = "SELECT p.*, a.nama_lengkap, b.judul_buku 
                 FROM tb_peminjaman p 
                 JOIN tb_anggota a ON p.nim = a.nim 
                 JOIN tb_buku b ON p.kode_buku = b.kode_buku 
                 WHERE p.status_pinjam = 'Dipinjam' $filter_cari
                 ORDER BY p.id_transaksi DESC";
$res_pinjam = mysqli_query($koneksi,$query_pinjam);

// Ambil riwayat pengembalian yang sudah selesai
$query_selesai = "SELECT p.*, a.nama_lengkap, b.judul_buku 
                  FROM tb_peminjaman p 
                  JOIN tb_anggota a ON p.nim = a.nim 
                  JOIN tb_buku b ON p.kode_buku = b.kode_buku 
                  WHERE p.status_pinjam = 'Dikembalikan'
                    AND p.riwayat_dihapus = 0
                  ORDER BY COALESCE(p.tanggal_dikembalikan, p.tanggal_kembali) DESC, p.id_transaksi DESC LIMIT 10";
$res_selesai = mysqli_query($koneksi,$query_selesai);
?>
<?php
// ============================================================
// DATA UNTUK TAMPILAN
// ============================================================
function tgl_id($d) {
    $t = $d ? strtotime($d) : false;
    return $t ? date('d-m-Y', $t) : '-';
}
function rp($n) {
    return 'Rp ' . number_format((int)$n, 0, ',', '.');
}

$rows_aktif      = [];
$jml_terlambat   = 0;
$total_hari_telat = 0;
$total_denda     = 0;
$hari_ini        = new DateTime(date('Y-m-d'));

if ($res_pinjam) {
    while ($r = mysqli_fetch_assoc($res_pinjam)) {
        $jt = new DateTime(date('Y-m-d', strtotime($r['tanggal_kembali'])));
        $r['_late'] = $hari_ini > $jt;
        $r['_hari'] = $r['_late'] ? $hari_ini->diff($jt)->days : 0;
        if ($r['_late']) {
            $jml_terlambat++;
            $total_hari_telat += $r['_hari'];
            $total_denda += $r['_hari'] * $tarif_denda_per_hari;
        }
        $rows_aktif[] = $r;
    }
}
$rows_selesai = [];
if ($res_selesai) {
    while ($r = mysqli_fetch_assoc($res_selesai)) { $rows_selesai[] = $r; }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>Pengembalian Buku &mdash; Perpustakaan UIN Datokarama Palu</title>
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--brand:#064c40;--brand-dark:#04382f;--ink:#1f2a2e;--muted:#8a99a0;--line:#e8eeeb;--bg:#f4f8f6;--red:#dc2626;--blue:#2563eb;--yellow:#e8a013}
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
.bottomnav{display:none}
/* ===== KONTEN HALAMAN PENGEMBALIAN (mengikuti gaya dashboard) ===== */
svg.i{width:20px;height:20px;flex:none;fill:none;stroke:currentColor;stroke-width:2;stroke-linecap:round;stroke-linejoin:round}
.sprite{position:absolute;width:0;height:0;overflow:hidden}
.card{background:#fff;border-radius:12px;padding:20px;border:1px solid #f1f5f9;box-shadow:0 2px 8px rgba(0,0,0,.03);margin-bottom:24px}
.card-title{display:flex;align-items:center;gap:10px;font-size:14px;font-weight:800;color:#0f172a}
.card-title svg.i{width:20px;height:20px;color:var(--brand)}
.card-title .right-note{margin-left:auto;font-size:12px;font-weight:500;color:var(--muted)}
.card .hint{font-size:12px;color:var(--muted);margin:3px 0 16px 30px}
.search-grid{display:grid;grid-template-columns:minmax(0,5fr) minmax(0,4fr) auto;gap:20px;align-items:end;margin-bottom:16px}
label.flabel{display:block;font-size:12px;font-weight:700;color:#334155;margin-bottom:7px}
input,select{font:inherit;font-size:12.5px;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;color:var(--ink);background:#fff;outline:none;width:100%}
input:focus,select:focus{border-color:var(--brand);box-shadow:0 0 0 3px rgba(6,76,64,.12)}
input::placeholder{color:#9aa8b0}
select{appearance:none;-webkit-appearance:none;background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' fill='none' stroke='%236b7a84' stroke-width='1.6' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='M1 1l4 4 4-4'/%3E%3C/svg%3E");background-repeat:no-repeat;background-position:right 12px center;padding-right:30px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:8px;padding:10px 18px;font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff;background:var(--brand);transition:filter .15s,transform .1s;white-space:nowrap}
.btn svg.i{width:16px;height:16px}
.btn:hover{filter:brightness(1.1)}.btn:active{transform:scale(.98)}
.btn.blue{background:var(--blue)}
.btn.red{background:var(--red)}
.btn.yellow{background:var(--yellow)}
.btn.ghost{background:#fff;color:#475569;border:1px solid #e2e8f0}
.btn.sm{padding:7px 12px;font-size:11.5px;border-radius:6px}
.btn.search{padding:11px 34px}
.info-banner{background:#d9ece6;border-radius:8px;padding:10px 14px;color:var(--brand)}
.info-banner h4{font-size:13px;font-weight:800}
.info-banner p{font-size:11px;font-weight:600;margin-top:2px}

.tbl{width:100%;border-collapse:collapse;font-size:12px}
.tbl thead th{background:#f8fafc;color:#475569;font-weight:600;text-align:left;padding:12px 16px;font-size:12px;border-bottom:1px solid #f1f5f9;white-space:nowrap}
.tbl tbody td{padding:14px 16px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
.tbl tbody tr.empty td{text-align:center;color:var(--muted);padding:22px}
.tbl td.strong{font-weight:600}

.badge{display:inline-block;font-size:11px;font-weight:500;border-radius:999px;padding:6px 16px;color:#fff;white-space:nowrap}
.badge.late{background:#dc2626}
.badge.loan{background:#eaa123}
.badge.ok{background:#15803d}
.aksi{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.aksi form{display:inline-flex;align-items:center;gap:8px}
.extend{display:inline-flex;align-items:center;gap:8px}
.extend input{width:64px;padding:7px 10px;font-size:12px}
.extend span{font-size:12px}

.denda-banner{background:#dc2626;border-radius:12px;padding:14px 18px;color:#fff;display:flex;align-items:center;gap:16px;margin-top:14px}
.denda-banner>svg.i{width:30px;height:30px;stroke-width:1.6}
.denda-banner .txt{flex:1}
.denda-banner .txt h4{font-size:13.5px;font-weight:800;letter-spacing:.2px;text-transform:uppercase}
.denda-banner .txt p{font-size:12px;font-weight:600;margin-top:2px}
.denda-banner .act{display:flex;gap:9px;align-items:center}
.denda-banner .act input{width:134px;border:0;border-radius:8px;padding:9px 12px;font-weight:600;color:#0f172a}
.denda-banner .act .btn{padding:9px 14px}

details.manage{margin-top:14px;font-size:12px;border-top:1px dashed var(--line);padding-top:12px}
details.manage summary{cursor:pointer;font-weight:700;color:#475569;list-style:none;display:inline-flex;align-items:center;gap:6px}
details.manage summary::-webkit-details-marker{display:none}
details.manage summary::before{content:"\25B8";font-size:11px;transition:transform .15s}
details.manage[open] summary::before{transform:rotate(90deg)}
.manage-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-top:12px}
.manage-box{border:1px solid #f1f5f9;border-radius:12px;padding:14px}
.manage-box h5{font-size:12.5px;font-weight:800;margin-bottom:10px}
.manage-row{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.manage-row>div{flex:1;min-width:120px}

.alert{border-radius:12px;padding:14px 18px;font-size:12.5px;margin-bottom:20px;white-space:pre-line}
.alert.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46}
.alert.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}

@media(max-width:1100px){
  .search-grid{grid-template-columns:1fr 1fr}
  .search-grid .btn-col{grid-column:1/-1}
  .search-grid .btn-col .btn{width:100%}
}
@media(max-width:900px){
  .main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
  body.nav-open .sidebar{transform:none}
  body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
  .hamburger{display:grid;place-items:center}
  .manage-grid{grid-template-columns:1fr}
}
/* tabel menjadi kartu pada layar kecil */
@media(max-width:768px){
  .search-grid{grid-template-columns:1fr;gap:12px}
  .btn.search{width:100%;padding:12px}
  .tbl,.tbl tbody,.tbl tr,.tbl td{display:block;width:100%}
  .tbl thead{display:none}
  .tbl tbody tr{background:#fff;border:1px solid #f1f5f9;border-radius:12px;padding:6px 14px;margin-bottom:12px;box-shadow:0 2px 8px rgba(0,0,0,.03)}
  .tbl tbody td{border:0;padding:8px 0;display:flex;justify-content:space-between;align-items:center;gap:14px;text-align:right;border-bottom:1px dashed #edf1f4}
  .tbl tbody td:last-child{border-bottom:0}
  .tbl tbody td::before{content:attr(data-label);font-weight:700;font-size:11px;color:var(--muted);text-align:left;flex:none}
  .tbl tbody td.td-aksi{display:block;text-align:left;padding-top:12px}
  .tbl tbody td.td-aksi::before{display:none}
  .tbl tbody tr.empty{padding:0}
  .tbl tbody tr.empty td{display:block;text-align:center;border:0}
  .tbl tbody tr.empty td::before{display:none}
  .aksi,.aksi form{width:100%;flex-direction:column;align-items:stretch}
  .aksi .btn{width:100%;padding:11px}
  .aksi form.extend{width:100%;flex-direction:row;align-items:center;gap:8px}
  .extend input{flex:1;width:auto;min-width:0}
  .extend .btn{flex:1.6;padding:11px 8px}
  .tbl tbody td[data-label="No"]{display:none}
  .tbl tbody td[data-label="Kode Transaksi"],.tbl tbody td[data-label="ID Transaksi"]{font-weight:800}
  .denda-banner{flex-direction:column;align-items:stretch;gap:12px;border-radius:16px}
  .denda-banner>svg.i{display:none}
  .denda-banner .act{width:100%}
  .denda-banner .act input{flex:1;width:auto}
  .card-title .right-note{display:none}
  .card-title.has-note{flex-wrap:wrap}
}
/* mobile: header hijau + navigasi bawah, sama seperti dashboard */
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
  .card{border-radius:16px;padding:16px}
  .card .hint{margin-left:0}
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

<!-- Kumpulan ikon -->
<svg class="sprite" aria-hidden="true">
  <symbol id="i-edit" viewBox="0 0 24 24"><path d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5"/><path d="M17.6 3.6a2 2 0 112.8 2.8L11.8 15H9v-2.8l8.6-8.6z"/></symbol>
  <symbol id="i-db" viewBox="0 0 24 24"><ellipse cx="12" cy="5.5" rx="8.5" ry="3"/><path d="M3.5 5.5v13c0 1.7 3.8 3 8.5 3s8.5-1.3 8.5-3v-13"/><path d="M3.5 12c0 1.7 3.8 3 8.5 3s8.5-1.3 8.5-3"/></symbol>
  <symbol id="i-warn" viewBox="0 0 24 24"><path d="M12 3.5 2.5 20h19L12 3.5z"/><path d="M12 10v4.5M12 17.4v.1"/></symbol>
  <symbol id="i-refresh" viewBox="0 0 24 24"><path d="M20 12a8 8 0 11-2.6-5.9"/><path d="M20 4v4.5h-4.5"/></symbol>
</svg>

<div class="layout">
  <aside class="sidebar" aria-label="Navigasi utama">
    <div class="side-brand"><img src="assets/logo-uin.png" alt="Logo UIN Datokarama Palu"><div><b>Perpustakaan<small>UIN Datokarama Palu</small></b></div></div>
    <nav class="side-nav">
      <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Dashboard</a>
      <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
      <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
      <a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
      <a href="pengembalian.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
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
      <h2>Pengembalian Buku</h2>
      <div class="topbar-right">
        <span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, <?= htmlspecialchars($_SESSION['nama_admin'] ?? 'Petugas'); ?></span>
        <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"/></svg>Logout</a>
      </div>
    </div>
    <main class="content">
    <h1 class="page-title">Pengembalian Buku</h1>
    <p class="page-sub">Kembalikan buku tepat waktu, hindari denda, jaga koleksi perpustakaan.</p>

    <?php if (!empty($pesan)): ?><div class="alert ok"><?= htmlspecialchars($pesan); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="alert err"><?= htmlspecialchars($error); ?></div><?php endif; ?>

    <!-- ===== INPUT DATA PENGEMBALIAN ===== -->
    <!-- Kontrak: GET q (NIM / kode buku) + jenis (nim | kode) -->
    <section class="card">
      <h3 class="card-title"><svg class="i"><use href="#i-edit"/></svg>Input Data Pengembalian</h3>
      <p class="hint">Cari data peminjaman berdasarkan NIM anggota atau kode buku untuk melakukan pengembalian</p>
      <form action="pengembalian.php" method="GET">
        <div class="search-grid">
          <div>
            <label class="flabel" for="cari_q">NIM / Kode Buku</label>
            <input type="text" id="cari_q" name="q" value="<?= htmlspecialchars($cari_q); ?>" placeholder="Masukkan NIM atau kode buku..." autocomplete="off">
          </div>
          <div>
            <label class="flabel" for="cari_jenis">Jenis Pencarian</label>
            <select id="cari_jenis" name="jenis">
              <option value="nim" <?= $cari_jenis === 'nim' ? 'selected' : ''; ?>>NIM Anggota</option>
              <option value="kode" <?= $cari_jenis === 'kode' ? 'selected' : ''; ?>>Kode Buku</option>
            </select>
          </div>
          <div class="btn-col">
            <button type="submit" class="btn blue search"><svg class="i" viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/></svg>Cari data</button>
          </div>
        </div>
      </form>
      <div class="info-banner">
        <h4>Informasi Penting</h4>
        <p>Buku yang dikembalikan harus dalam kondisi baik. Jika terlambat, akan dikenakan denda sebesar <?= rp($tarif_denda_per_hari); ?> per hari</p>
      </div>
    </section>

    <!-- ===== DATA PEMINJAMAN YANG DITEMUKAN ===== -->
    <section class="card" id="hasil">
      <h3 class="card-title has-note"><svg class="i"><use href="#i-db"/></svg>Data Peminjaman yang Ditemukan<span class="right-note"><?= count($rows_aktif); ?> data<?= $cari_q !== '' ? ' untuk &ldquo;' . htmlspecialchars($cari_q) . '&rdquo;' : ''; ?></span></h3>
      <div style="height:12px"></div>
      <table class="tbl">
        <thead><tr><th>No</th><th>Kode Transaksi</th><th>Nama Anggota</th><th>NIM</th><th>Judul Buku</th><th>Jatuh Tempo</th><th>Status</th><th>Aksi</th></tr></thead>
        <tbody>
        <?php if (count($rows_aktif) > 0): $no = 1; foreach ($rows_aktif as $row): $rid = (int)$row['id_transaksi']; ?>
          <tr>
            <td data-label="No"><?= $no++; ?></td>
            <td data-label="Kode Transaksi">TRX-<?= date('Y', strtotime($row['tanggal_pinjam'] ?? 'now')); ?>-<?= str_pad((string)$rid, 4, '0', STR_PAD_LEFT); ?></td>
            <td data-label="Nama Anggota"><?= htmlspecialchars($row['nama_lengkap'] ?? '-'); ?></td>
            <td data-label="NIM"><?= htmlspecialchars($row['nim']); ?></td>
            <td data-label="Judul Buku"><?= htmlspecialchars($row['judul_buku'] ?? '-'); ?></td>
            <td data-label="Jatuh Tempo"><?= tgl_id($row['tanggal_kembali'] ?? ''); ?></td>
            <td data-label="Status"><?= $row['_late'] ? '<span class="badge late">Terlambat</span>' : '<span class="badge loan">Dipinjam</span>'; ?></td>
            <td class="td-aksi">
              <div class="aksi">
                <form action="pengembalian.php" method="POST">
                  <input type="hidden" name="id_transaksi" value="<?= $rid; ?>">
                  <button type="submit" name="proses_kembali" class="btn sm" onclick="return confirm('Proses pengembalian transaksi <?= $rid; ?>?')">Proses Pengembalian</button>
                </form>
                <?php if (!$row['_late']): ?>
                <form action="pengembalian.php" method="POST" class="extend">
                  <input type="hidden" name="id_transaksi_perpanjang" value="<?= $rid; ?>">
                  <input type="number" name="durasi_perpanjang" value="7" min="1" max="3650" aria-label="Jumlah hari perpanjangan">
                  <span>Hari</span>
                  <button type="submit" name="proses_perpanjang" class="btn blue sm"><svg class="i"><use href="#i-refresh"/></svg>Perpanjang</button>
                </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; else: ?>
          <tr class="empty"><td colspan="8">Tidak ada data peminjaman aktif<?= $cari_q !== '' ? ' yang cocok dengan pencarian' : ' saat ini'; ?>.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <!-- Kontrak: update_denda + tarif_denda -->
      <div class="denda-banner">
        <svg class="i"><use href="#i-warn"/></svg>
        <div class="txt">
          <h4>Rincian Denda</h4>
          <?php if ($jml_terlambat === 0): ?>
            <p>Tidak ada keterlambatan pada data di atas</p>
            <p><?= rp(0); ?></p>
          <?php elseif ($jml_terlambat === 1): ?>
            <p>Keterlambatan <?= (int)$total_hari_telat; ?> hari x <?= rp($tarif_denda_per_hari); ?> per hari</p>
            <p><?= rp($total_denda); ?></p>
          <?php else: ?>
            <p><?= (int)$jml_terlambat; ?> transaksi terlambat (total <?= (int)$total_hari_telat; ?> hari) x <?= rp($tarif_denda_per_hari); ?> per hari</p>
            <p><?= rp($total_denda); ?></p>
          <?php endif; ?>
        </div>
        <form action="pengembalian.php" method="POST" class="act" onsubmit="this.tarif_denda.value=this.tarif_denda.value.replace(/\D/g,'')">
          <input type="text" name="tarif_denda" value="<?= number_format((int)$tarif_denda_per_hari, 0, ',', '.'); ?>" inputmode="numeric" aria-label="Tarif denda per hari" oninput="fmtRibuan(this)">
          <button type="submit" name="update_denda" class="btn blue">Ubah Tarif</button>
        </form>
      </div>
    </section>

    <!-- ===== RIWAYAT PENGEMBALIAN TERAKHIR ===== -->
    <section class="card">
      <h3 class="card-title has-note"><svg class="i"><use href="#i-edit"/></svg>Riwayat Pengembalian Terakhir<span class="right-note">Menampilkan maksimal 10 riwayat terakhir</span></h3>
      <div style="height:12px"></div>
      <table class="tbl">
        <thead><tr><th>ID Transaksi</th><th>Peminjam</th><th>Buku</th><th>Tgl Dikembalikan</th><th>Denda Dibayar</th><th>Status</th></tr></thead>
        <tbody>
        <?php if (count($rows_selesai) > 0): foreach ($rows_selesai as $rs): $denda_rs = (int)($rs['denda'] ?? 0); ?>
          <tr>
            <td data-label="ID Transaksi">TRX-<?= date('Y', strtotime($rs['tanggal_dikembalikan'] ?? $rs['tanggal_kembali'] ?? 'now')); ?>-<?= str_pad((string)(int)$rs['id_transaksi'], 4, '0', STR_PAD_LEFT); ?></td>
            <td data-label="Peminjam"><?= htmlspecialchars($rs['nama_lengkap'] ?? '-'); ?></td>
            <td data-label="Buku"><?= htmlspecialchars($rs['judul_buku'] ?? '-'); ?></td>
            <td data-label="Tgl Dikembalikan"><?= tgl_id($rs['tanggal_dikembalikan'] ?? ($rs['tanggal_kembali'] ?? '')); ?></td>
            <td data-label="Denda Dibayar" class="strong"><?= $denda_rs > 0 ? '<b>' . rp($denda_rs) . '</b>' : 'Tepat Waktu ' . rp(0); ?></td>
            <td data-label="Status"><?= $denda_rs > 0 ? '<span class="badge late">Terlambat</span>' : '<span class="badge ok">Tepat Waktu</span>'; ?></td>
          </tr>
        <?php endforeach; else: ?>
          <tr class="empty"><td colspan="6">Belum ada riwayat pengembalian.</td></tr>
        <?php endif; ?>
        </tbody>
      </table>

      <!-- Kontrak: hapus_riwayat_manual + manual_tanggal_mulai/akhir ; hapus_semua_riwayat ; simpan_pengaturan_riwayat + riwayat_auto_* -->
      <details class="manage">
        <summary>Kelola riwayat (bersihkan manual / otomatis)</summary>
        <div class="manage-grid">
          <form action="pengembalian.php" method="POST" class="manage-box">
            <h5>Bersihkan manual</h5>
            <div class="manage-row">
              <div><label class="flabel" for="mm">Dari</label><input type="date" id="mm" name="manual_tanggal_mulai" value="<?= htmlspecialchars($manual_tanggal_mulai_pilih); ?>"></div>
              <div><label class="flabel" for="ma">Sampai</label><input type="date" id="ma" name="manual_tanggal_akhir" value="<?= htmlspecialchars($manual_tanggal_akhir_pilih); ?>"></div>
            </div>
            <div class="manage-row" style="margin-top:12px">
              <button type="submit" name="hapus_riwayat_manual" class="btn yellow sm">Bersihkan Periode</button>
              <button type="submit" name="hapus_semua_riwayat" class="btn red sm" onclick="return confirm('Bersihkan SEMUA riwayat pengembalian?')">Bersihkan Semua</button>
            </div>
          </form>
          <form action="pengembalian.php" method="POST" class="manage-box">
            <h5>Pembersihan otomatis</h5>
            <div class="manage-row">
              <div><label class="flabel">Status</label><select name="riwayat_auto_aktif"><option value="0" <?= !$auto_aktif ? 'selected' : ''; ?>>Nonaktif</option><option value="1" <?= $auto_aktif ? 'selected' : ''; ?>>Aktif</option></select></div>
              <div><label class="flabel">Simpan selama</label><input type="number" name="riwayat_auto_jumlah" min="1" max="3650" value="<?= (int)$auto_jumlah; ?>"></div>
              <div><label class="flabel">Satuan</label><select name="riwayat_auto_satuan"><option value="hari" <?= $auto_satuan === 'hari' ? 'selected' : ''; ?>>hari</option><option value="minggu" <?= $auto_satuan === 'minggu' ? 'selected' : ''; ?>>minggu</option><option value="bulan" <?= $auto_satuan === 'bulan' ? 'selected' : ''; ?>>bulan</option></select></div>
            </div>
            <div class="manage-row" style="margin-top:12px"><button type="submit" name="simpan_pengaturan_riwayat" class="btn ghost sm">Simpan</button></div>
          </form>
        </div>
      </details>
    </section>
  </main>
  </div>
</div>
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"/></svg>Beranda</a>
  <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
  <a href="anggota.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Anggota</a>
  <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Laporan</a>
</nav>
<script>
"use strict";
document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});
function fmtRibuan(el){
  var d = el.value.replace(/\D/g,'');
  el.value = d ? d.replace(/\B(?=(\d{3})+(?!\d))/g,'.') : '';
}
// Setelah pencarian, langsung tampilkan hasil
if (location.search.indexOf('q=') > -1) {
  var h = document.getElementById('hasil'); if (h) h.scrollIntoView({block:'start'});
}
</script>
</body>
</html>
