<?php
session_start();
require_once 'koneksi.php';

// ============================================================
// PROTEKSI HALAMAN ADMIN
// ============================================================
if (!isset($_SESSION['admin'])) {
    header("Location: login.php");
    exit();
}

$pesan = "";
$error = "";

// ============================================================
// PEMBERSIHAN OTOMATIS DATA ANGGOTA DENGAN TELEPON TIDAK VALID
// Anggota dengan nomor terisi tetapi bukan 10-15 digit angka
// dihapus bersama transaksi peminjamannya dalam satu transaksi DB.
// Nomor telepon kosong tetap diperbolehkan.
// ============================================================
$anggota_dihapus_otomatis = 0;
$transaksi_dihapus_otomatis = 0;

$cek_invalid = mysqli_query(
    $koneksi,
    "SELECT a.nim
     FROM tb_anggota a
     WHERE a.no_telepon IS NOT NULL
       AND TRIM(a.no_telepon) <> ''
       AND TRIM(a.no_telepon) NOT REGEXP '^[0-9]{10,15}$'"
);

if ($cek_invalid) {
    $nim_invalid = array();
    while ($anggota_invalid = mysqli_fetch_assoc($cek_invalid)) {
        $nim_invalid[] = (string)$anggota_invalid['nim'];
    }

    if (!empty($nim_invalid)) {
        mysqli_begin_transaction($koneksi);
        $stmtHapusTransaksi = mysqli_prepare($koneksi, "DELETE FROM tb_peminjaman WHERE nim = ?");
        $stmtHapusAnggota = mysqli_prepare(
            $koneksi,
            "DELETE FROM tb_anggota
             WHERE nim = ?
               AND no_telepon IS NOT NULL
               AND TRIM(no_telepon) <> ''
               AND TRIM(no_telepon) NOT REGEXP '^[0-9]{10,15}$'
             LIMIT 1"
        );

        if ($stmtHapusTransaksi && $stmtHapusAnggota) {
            $gagal_hapus_otomatis = false;
            foreach ($nim_invalid as $nim_invalid_item) {
                mysqli_stmt_bind_param($stmtHapusTransaksi, 's', $nim_invalid_item);
                if (!mysqli_stmt_execute($stmtHapusTransaksi)) {
                    $gagal_hapus_otomatis = true;
                    break;
                }
                $transaksi_dihapus_otomatis += mysqli_stmt_affected_rows($stmtHapusTransaksi);

                mysqli_stmt_bind_param($stmtHapusAnggota, 's', $nim_invalid_item);
                if (!mysqli_stmt_execute($stmtHapusAnggota)) {
                    $gagal_hapus_otomatis = true;
                    break;
                }
                $anggota_dihapus_otomatis += mysqli_stmt_affected_rows($stmtHapusAnggota);
            }
            mysqli_stmt_close($stmtHapusTransaksi);
            mysqli_stmt_close($stmtHapusAnggota);

            if ($gagal_hapus_otomatis || !mysqli_commit($koneksi)) {
                mysqli_rollback($koneksi);
                $anggota_dihapus_otomatis = 0;
                $transaksi_dihapus_otomatis = 0;
                $error = 'Pembersihan otomatis dibatalkan karena database gagal memproses penghapusan.';
            }
        } else {
            if ($stmtHapusTransaksi) mysqli_stmt_close($stmtHapusTransaksi);
            if ($stmtHapusAnggota) mysqli_stmt_close($stmtHapusAnggota);
            mysqli_rollback($koneksi);
            $error = 'Pembersihan otomatis tidak dapat dijalankan: ' . mysqli_error($koneksi);
        }
    }

    if ($anggota_dihapus_otomatis > 0) {
        $pesan = $anggota_dihapus_otomatis . ' data mahasiswa dengan nomor telepon tidak valid dihapus otomatis beserta ' . $transaksi_dihapus_otomatis . ' transaksi terkait.';
    }
} else {
    $error = 'Pembersihan otomatis tidak dijalankan karena pemeriksaan data gagal: ' . mysqli_error($koneksi);
}

// ============================================================
// PROSES RESET PASSWORD MAHASISWA OLEH ADMIN
// Password dikembalikan ke NIM.
// Password disimpan dalam bentuk hash agar admin tidak dapat
// melihat password mahasiswa di database.
// ============================================================
if (isset($_GET['aksi']) && $_GET['aksi'] === 'reset' && isset($_GET['nim'])) {
    $nim_reset = trim($_GET['nim']);

    if ($nim_reset === '') {
        echo "<script>alert('NIM tidak valid!'); window.location='anggota.php';</script>";
        exit();
    }

    $nim_reset_db = mysqli_real_escape_string($koneksi, $nim_reset);

    // Pastikan mahasiswa benar-benar ada.
    $cek_mhs = mysqli_query(
        $koneksi,
        "SELECT nim FROM tb_anggota WHERE nim = '$nim_reset_db' LIMIT 1"
    );

    if (!$cek_mhs || mysqli_num_rows($cek_mhs) === 0) {
        echo "<script>alert('Data mahasiswa dengan NIM tersebut tidak ditemukan!'); window.location='anggota.php';</script>";
        exit();
    }

    // Deteksi nama kolom password agar tetap kompatibel
    // dengan sistem yang memakai 'password' atau 'pass'.
    $kolom_password = 'password';
    $cek_kolom_password = mysqli_query($koneksi, "SHOW COLUMNS FROM tb_anggota");

    if ($cek_kolom_password) {
        $kolom_ditemukan = array();

        while ($kolom = mysqli_fetch_assoc($cek_kolom_password)) {
            $kolom_ditemukan[] = strtolower($kolom['Field']);
        }

        if (in_array('password', $kolom_ditemukan, true)) {
            $kolom_password = 'password';
        } elseif (in_array('pass', $kolom_ditemukan, true)) {
            $kolom_password = 'pass';
        } else {
            echo "<script>alert('Kolom password pada tabel tb_anggota tidak ditemukan!'); window.location='anggota.php';</script>";
            exit();
        }
    }

    // Default password = NIM, tetapi yang disimpan ke database
    // adalah hash agar password tidak tersimpan sebagai plaintext.
    $password_hash = password_hash($nim_reset, PASSWORD_DEFAULT);

    $query_reset = "UPDATE tb_anggota
                    SET `$kolom_password` = '" . mysqli_real_escape_string($koneksi, $password_hash) . "'
                    WHERE nim = '$nim_reset_db'
                    LIMIT 1";

    if (mysqli_query($koneksi, $query_reset)) {
        echo "<script>
                alert('Password mahasiswa dengan NIM " . addslashes($nim_reset) . " berhasil direset. Password sementara kembali menjadi NIM.');
                window.location='anggota.php';
              </script>";
        exit();
    } else {
        $err = addslashes(mysqli_error($koneksi));
        echo "<script>
                alert('Gagal mereset password: $err');
                window.location='anggota.php';
              </script>";
        exit();
    }
}

// ============================================================
// IMPORT DATA ANGGOTA MASSAL (CSV / XLSX)
// Tidak menghapus atau mengganti fitur input manual.
// Kolom yang didukung: NIM, Nama Lengkap, Program Studi/Jurusan,
// No. Telepon/WA. Nomor telepon boleh kosong.
// ============================================================
function anggota_normalize_header($value) {
    $value = trim((string)$value);
    $value = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = strtolower($value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim($value, '_');
}

function anggota_find_column($headers, $aliases) {
    foreach ($aliases as $alias) {
        $alias = anggota_normalize_header($alias);
        if (isset($headers[$alias])) return $headers[$alias];
    }
    return null;
}

function anggota_read_csv_rows($file) {
    $rows = array();
    $fh = @fopen($file, 'r');
    if (!$fh) return $rows;

    $first = fgets($fh);
    if ($first === false) { fclose($fh); return $rows; }
    $delimiter = ',';
    $counts = array(',' => substr_count($first, ','), ';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"));
    arsort($counts);
    $delimiter = key($counts);
    rewind($fh);

    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

function anggota_read_xlsx_rows($file) {
    $rows = array();
    if (!class_exists('ZipArchive')) return $rows;

    $zip = new ZipArchive();
    if ($zip->open($file) !== true) return $rows;

    $shared = array();
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx && isset($sx->si)) {
            foreach ($sx->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } else {
                    foreach ($si->r as $run) $text .= (string)$run->t;
                }
                $shared[] = $text;
            }
        }
    }

    $sheetName = 'xl/worksheets/sheet1.xml';
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml !== false && $relsXml !== false) {
        $wb = @simplexml_load_string($workbookXml);
        $rels = @simplexml_load_string($relsXml);
        if ($wb && $rels) {
            $ns = $wb->getNamespaces(true);
            $wb->registerXPathNamespace('m', $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $relNs = $rels->getNamespaces(true);
            $rels->registerXPathNamespace('r', $relNs[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships');
            $sheets = $wb->xpath('//m:sheets/m:sheet');
            if ($sheets && isset($sheets[0])) {
                $rid = (string)$sheets[0]->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                foreach ($rels->Relationship as $rel) {
                    if ((string)$rel['Id'] === $rid) {
                        $target = (string)$rel['Target'];
                        $target = ltrim(str_replace('\\', '/', $target), '/');
                        $sheetName = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                        break;
                    }
                }
            }
        }
    }

    $sheetXml = $zip->getFromName($sheetName);
    if ($sheetXml === false) { $zip->close(); return $rows; }
    $sx = @simplexml_load_string($sheetXml);
    if (!$sx) { $zip->close(); return $rows; }

    $ns = $sx->getNamespaces(true);
    $sx->registerXPathNamespace('m', $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $rowNodes = $sx->xpath('//m:sheetData/m:row');
    foreach ($rowNodes as $rowNode) {
        $values = array();
        foreach ($rowNode->c as $cell) {
            $ref = (string)$cell['r'];
            preg_match('/([A-Z]+)/i', $ref, $m);
            $colLetters = strtoupper($m[1] ?? 'A');
            $index = 0;
            for ($i = 0; $i < strlen($colLetters); $i++) $index = $index * 26 + (ord($colLetters[$i]) - 64);
            $index--;
            $type = (string)$cell['t'];
            $value = '';
            if ($type === 's') {
                $idx = (int)$cell->v;
                $value = $shared[$idx] ?? '';
            } elseif ($type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : '';
            } else {
                $value = isset($cell->v) ? (string)$cell->v : '';
            }
            $values[$index] = $value;
        }
        if (!empty($values)) {
            $max = max(array_keys($values));
            $out = array_fill(0, $max + 1, '');
            foreach ($values as $i => $v) $out[$i] = $v;
            $rows[] = $out;
        }
    }
    $zip->close();
    return $rows;
}

if (isset($_POST['import_anggota'])) {
    if (!isset($_FILES['file_anggota']) || $_FILES['file_anggota']['error'] !== UPLOAD_ERR_OK) {
        $error = 'File Excel/CSV belum dipilih atau gagal diunggah.';
    } else {
        $tmp = $_FILES['file_anggota']['tmp_name'];
        $name = strtolower((string)$_FILES['file_anggota']['name']);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $rows = array();
        if ($ext === 'csv') {
            $rows = anggota_read_csv_rows($tmp);
        } elseif ($ext === 'xlsx') {
            $rows = anggota_read_xlsx_rows($tmp);
            if (!$rows && !class_exists('ZipArchive')) {
                $error = 'Server PHP belum mengaktifkan ZipArchive. Gunakan CSV sementara atau aktifkan ekstensi ZIP di XAMPP.';
            }
        } else {
            $error = 'Format tidak didukung. Gunakan .xlsx atau .csv.';
        }

        if ($error === '' && !empty($rows)) {
            $headersRaw = array_shift($rows);
            $headers = array();
            foreach ($headersRaw as $i => $h) $headers[anggota_normalize_header($h)] = $i;

            $colNim   = anggota_find_column($headers, array('nim','nomor_induk_mahasiswa','no_induk_mahasiswa','nomor_induk'));
            $colNama  = anggota_find_column($headers, array('nama_lengkap','nama','nama_mahasiswa','nama_mhs'));
            $colProdi = anggota_find_column($headers, array('program_studi','program_study','prodi','jurusan','program'));
            $colTelp  = anggota_find_column($headers, array('no_telepon','no_telepon_wa','no_wa','telepon','phone','whatsapp','wa'));

            if ($colNim === null || $colNama === null || $colProdi === null) {
                $error = 'Header wajib belum lengkap. Minimal harus ada: NIM, Nama Lengkap, dan Program Studi/Jurusan. No. Telepon boleh kosong.';
            } else {
                $berhasil = 0; $duplikat = 0; $gagal = 0; $kosong = 0; $detailGagal = array();
                mysqli_begin_transaction($koneksi);
                $stmtCek = mysqli_prepare($koneksi, 'SELECT nim FROM tb_anggota WHERE nim = ? LIMIT 1');
                $stmtIns = mysqli_prepare($koneksi, 'INSERT INTO tb_anggota (nim,nama_lengkap,program_studi,no_telepon,foto,tgl_bergabung) VALUES (?,?,?,?,?,?)');
                if (!$stmtCek || !$stmtIns) {
                    mysqli_rollback($koneksi);
                    $error = 'Gagal menyiapkan proses import: ' . mysqli_error($koneksi);
                } else {
                    foreach ($rows as $lineNo => $row) {
                        $nim = isset($row[$colNim]) ? trim((string)$row[$colNim]) : '';
                        $nama = isset($row[$colNama]) ? trim((string)$row[$colNama]) : '';
                        $prodi = isset($row[$colProdi]) ? trim((string)$row[$colProdi]) : '';
                        $telp = $colTelp !== null && isset($row[$colTelp]) ? trim((string)$row[$colTelp]) : '';
                        if ($nim === '' && $nama === '' && $prodi === '') { $kosong++; continue; }
                        if ($nim === '' || $nama === '' || $prodi === '') {
                            $gagal++;
                            if (count($detailGagal) < 10) $detailGagal[] = 'Baris ' . ($lineNo + 2) . ': NIM, nama, dan program studi wajib diisi.';
                            continue;
                        }
                        // Nomor telepon opsional; jika diisi, hanya angka 10-15 digit.
                        if ($telp !== '' && (!preg_match('/^[0-9]+$/', $telp) || strlen($telp) < 10 || strlen($telp) > 15)) {
                            $gagal++;
                            if (count($detailGagal) < 10) $detailGagal[] = 'Baris ' . ($lineNo + 2) . ': nomor telepon harus 10-15 digit angka tanpa spasi atau tanda baca.';
                            continue;
                        }
                        mysqli_stmt_bind_param($stmtCek, 's', $nim);
                        mysqli_stmt_execute($stmtCek);
                        mysqli_stmt_store_result($stmtCek);
                        if (mysqli_stmt_num_rows($stmtCek) > 0) { $duplikat++; continue; }

                        $foto = 'default.jpg'; $tgl = date('Y-m-d');
                        mysqli_stmt_bind_param($stmtIns, 'ssssss', $nim, $nama, $prodi, $telp, $foto, $tgl);
                        if (mysqli_stmt_execute($stmtIns)) $berhasil++;
                        else {
                            $gagal++;
                            if (count($detailGagal) < 10) $detailGagal[] = 'Baris ' . ($lineNo + 2) . ': ' . mysqli_stmt_error($stmtIns);
                        }
                    }
                    mysqli_stmt_close($stmtCek); mysqli_stmt_close($stmtIns);
                    if (!mysqli_commit($koneksi)) {
                        mysqli_rollback($koneksi);
                        $error = 'Import dibatalkan karena transaksi database gagal disimpan.';
                    } else {
                        $pesan = "Import selesai: $berhasil data baru ditambahkan, $duplikat duplikat dilewati, $gagal baris gagal, $kosong baris kosong.";
                        if (!empty($detailGagal)) $error = 'Detail beberapa baris yang gagal: ' . implode(' | ', $detailGagal);
                    }
                }
            }
        } elseif ($error === '') {
            $error = 'File tidak berisi data yang bisa diimpor.';
        }
    }
}

// ============================================================
// 1. PROSES TAMBAH ANGGOTA
// ============================================================
if (isset($_POST['tambah_anggota'])) {
    $nim           = mysqli_real_escape_string($koneksi, trim($_POST['nim']));
    $nama_lengkap  = mysqli_real_escape_string($koneksi, trim($_POST['nama_lengkap']));
    $program_studi = mysqli_real_escape_string($koneksi, trim($_POST['program_studi']));
    $telepon_input = trim((string)($_POST['no_telepon'] ?? ''));
    $tgl_bergabung = date('Y-m-d');

    if ($telepon_input !== '' && (!preg_match('/^[0-9]+$/', $telepon_input) || strlen($telepon_input) < 10 || strlen($telepon_input) > 15)) {
        $error = 'Nomor telepon harus terdiri dari 10-15 digit angka saja. Nomor telepon boleh dikosongkan.';
    } else {
        $no_telepon = mysqli_real_escape_string($koneksi, $telepon_input);

        // Cek duplikasi NIM
        $cek_nim = mysqli_query($koneksi, "SELECT nim FROM tb_anggota WHERE nim = '$nim' LIMIT 1");

        if ($cek_nim && mysqli_num_rows($cek_nim) > 0) {
        $error = "NIM $nim sudah terdaftar sebagai anggota!";
    } else {
        $query_insert = "INSERT INTO tb_anggota
                        (nim, nama_lengkap, program_studi, no_telepon, foto, tgl_bergabung)
                        VALUES
                        ('$nim', '$nama_lengkap', '$program_studi', '$no_telepon', 'default.jpg', '$tgl_bergabung')";

        if (mysqli_query($koneksi, $query_insert)) {
            $pesan = "Anggota baru berhasil didaftarkan!";
        } else {
            $error = "Gagal mendaftarkan anggota: " . mysqli_error($koneksi);
        }
    }
    }
}

// ============================================================
// 2. PROSES EDIT ANGGOTA
// ============================================================
if (isset($_POST['edit_anggota'])) {
    $nim           = mysqli_real_escape_string($koneksi, trim($_POST['nim']));
    $nama_lengkap  = mysqli_real_escape_string($koneksi, trim($_POST['nama_lengkap']));
    $program_studi = mysqli_real_escape_string($koneksi, trim($_POST['program_studi']));
    $telepon_input = trim((string)($_POST['no_telepon'] ?? ''));

    if ($telepon_input !== '' && (!preg_match('/^[0-9]+$/', $telepon_input) || strlen($telepon_input) < 10 || strlen($telepon_input) > 15)) {
        $error = 'Nomor telepon harus terdiri dari 10-15 digit angka saja. Nomor telepon boleh dikosongkan.';
    } else {
        $no_telepon = mysqli_real_escape_string($koneksi, $telepon_input);
        $query_update = "UPDATE tb_anggota SET
                            nama_lengkap = '$nama_lengkap',
                            program_studi = '$program_studi',
                            no_telepon = '$no_telepon'
                         WHERE nim = '$nim'
                         LIMIT 1";

        if (mysqli_query($koneksi, $query_update)) {
            $pesan = "Data anggota berhasil diperbarui!";
        } else {
            $error = "Gagal memperbarui data: " . mysqli_error($koneksi);
        }
    }
}

// ============================================================
// 3. PROSES HAPUS ANGGOTA
// ============================================================
if (isset($_GET['aksi']) && $_GET['aksi'] === 'hapus' && isset($_GET['nim'])) {
    $nim = mysqli_real_escape_string($koneksi, trim($_GET['nim']));

    $query_delete = "DELETE FROM tb_anggota WHERE nim = '$nim' LIMIT 1";

    if (mysqli_query($koneksi, $query_delete)) {
        header("Location: anggota.php?pesan=dihapus");
        exit();
    } else {
        $error = "Gagal menghapus anggota: " . mysqli_error($koneksi);
    }
}

// ============================================================
// 3B. HAPUS ANGGOTA MASSAL BERDASARKAN JURUSAN / ANGKATAN
// Jurusan dan angkatan dapat dipilih sendiri atau digabungkan.
// Jika keduanya kosong, penghapusan berarti SEMUA anggota.
// ============================================================
if (isset($_POST['hapus_massal'])) {
    $hapus_jurusan  = isset($_POST['hapus_jurusan']) ? trim($_POST['hapus_jurusan']) : '';
    $hapus_angkatan = isset($_POST['hapus_angkatan']) ? trim($_POST['hapus_angkatan']) : '';

    $where = array();

    if ($hapus_jurusan !== '') {
        $jurusan_db = mysqli_real_escape_string($koneksi, $hapus_jurusan);
        $where[] = "program_studi = '$jurusan_db'";
    }

    if ($hapus_angkatan !== '') {
        // Angkatan mengikuti 2 digit awal NIM, sama seperti fitur cetak KTA.
        $angkatan_db = mysqli_real_escape_string($koneksi, $hapus_angkatan);
        $where[] = "LEFT(nim, 2) = '$angkatan_db'";
    }

    $where_sql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

    // Hitung dahulu agar admin mendapat informasi jumlah data yang benar-benar dihapus.
    $count_res = mysqli_query($koneksi, "SELECT COUNT(*) AS jumlah FROM tb_anggota$where_sql");
    $jumlah_target = 0;
    if ($count_res) {
        $count_row = mysqli_fetch_assoc($count_res);
        $jumlah_target = (int)($count_row['jumlah'] ?? 0);
    }

    if ($jumlah_target === 0) {
        $error = 'Tidak ada mahasiswa yang cocok dengan filter penghapusan.';
    } else {
        $query_delete_massal = "DELETE FROM tb_anggota$where_sql";

        if (mysqli_query($koneksi, $query_delete_massal)) {
            $filter_label = array();
            if ($hapus_jurusan !== '') $filter_label[] = 'Jurusan: ' . $hapus_jurusan;
            if ($hapus_angkatan !== '') $filter_label[] = 'Angkatan: ' . $hapus_angkatan;
            if (empty($filter_label)) $filter_label[] = 'Semua mahasiswa';

            $pesan = 'Berhasil menghapus ' . $jumlah_target . ' mahasiswa (' . implode(' + ', $filter_label) . ').';
        } else {
            $error = 'Gagal menghapus data mahasiswa: ' . mysqli_error($koneksi);
        }
    }
}

// ============================================================
// 4. AMBIL DATA UNTUK FORM EDIT
// ============================================================
$data_edit = null;

if (isset($_GET['aksi']) && $_GET['aksi'] === 'edit' && isset($_GET['nim'])) {
    $nim_edit = mysqli_real_escape_string($koneksi, trim($_GET['nim']));

    $res_edit = mysqli_query(
        $koneksi,
        "SELECT * FROM tb_anggota WHERE nim = '$nim_edit' LIMIT 1"
    );

    if ($res_edit) {
        $data_edit = mysqli_fetch_assoc($res_edit);
    }
}

// ============================================================
// PENCARIAN + AMBIL DAFTAR ANGGOTA
// Pencarian dapat berdasarkan NIM atau nama mahasiswa.
// Tidak mengubah struktur tabel/database.
// ============================================================
$cari = isset($_GET['cari']) ? trim($_GET['cari']) : '';
$cari_db = mysqli_real_escape_string($koneksi, $cari);

if ($cari !== '') {
    $anggota_sql = "SELECT * FROM tb_anggota
                    WHERE nim LIKE '%$cari_db%'
                       OR nama_lengkap LIKE '%$cari_db%'
                    ORDER BY tgl_bergabung DESC";
} else {
    $anggota_sql = "SELECT * FROM tb_anggota ORDER BY tgl_bergabung DESC";
}

$anggota_res = mysqli_query($koneksi, $anggota_sql);

if (!$anggota_res) {
    $error = "Gagal mengambil data anggota: " . mysqli_error($koneksi);
}

// Data pilihan cetak KTA massal diambil langsung dari database agar
// tetap lengkap walaupun tabel sedang difilter atau berisi ribuan anggota.
$prodi_options = array();
$prodi_res = mysqli_query($koneksi, "SELECT DISTINCT program_studi FROM tb_anggota WHERE program_studi IS NOT NULL AND TRIM(program_studi) <> '' ORDER BY program_studi ASC");
if ($prodi_res) {
    while ($p_row = mysqli_fetch_assoc($prodi_res)) {
        $prodi_options[] = $p_row['program_studi'];
    }
}

$angkatan_options = array();
$angkatan_res = mysqli_query($koneksi, "SELECT DISTINCT LEFT(nim, 2) AS angkatan FROM tb_anggota WHERE nim REGEXP '^[0-9]{2}' ORDER BY angkatan DESC");
if ($angkatan_res) {
    while ($a_row = mysqli_fetch_assoc($angkatan_res)) {
        $angkatan_options[] = $a_row['angkatan'];
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Data Anggota &mdash; Perpustakaan UIN Datokarama Palu</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
:root{--green-900:#064c40;--green-800:#085f50;--green-700:#166e5e;--ink:#1f2a2e;--muted:#8a99a0;--line:#e4ebee;--bg:#eff1f2;--card:#fff;--red:#e5484d;--blue:#2563eb;--yellow:#e8a013}
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
/* COPY: topbar-start */
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
/* COPY: topbar-end */
.mob-header{display:none}
.backrow{display:none}
.content{padding:32px;max-width:1280px;width:100%}
.page-title{font-size:22px;font-weight:800;letter-spacing:-.3px}
.page-sub{font-size:12.5px;color:var(--muted);margin:4px 0 22px}
.card{background:var(--card);border-radius:12px;padding:20px;box-shadow:0 1px 3px rgba(6,76,64,.05);border:1px solid #e8eef0;margin-bottom:18px}
.card h3{font-size:13px;font-weight:800;display:flex;align-items:center;gap:8px;margin-bottom:14px;color:#0f172a}
.card h3 svg{width:17px;height:17px;color:#0f172a}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:16px}
.grid2 .card{margin-bottom:0}
.file-row{display:flex;gap:10px;align-items:center;margin-bottom:12px}
.file-btn{display:flex;align-items:center;gap:8px;border:1px solid #cbd5e1;border-radius:7px;padding:7px 15px;font:inherit;font-size:12px;font-weight:600;color:#334155;background:#f8fafc;cursor:pointer;white-space:nowrap}
.file-btn svg{width:16px;height:16px;color:#475569}
.file-name{font-size:12px;color:#94a3b8;padding:7px 0;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.btn-row{display:flex;gap:10px;flex-wrap:wrap}
.btn{display:inline-flex;align-items:center;gap:7px;border:0;border-radius:7px;padding:8px 15px;font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;color:#fff;background:var(--green-900);transition:filter .15s ease,transform .15s ease}
.btn svg{width:14px;height:14px}
.btn:hover{filter:brightness(1.06)}.btn:active{transform:scale(.98)}
.btn.blue{background:var(--blue)}
.btn.red{background:#dc2626}
.btn.yellow{background:#f59e0b}
.btn.ghost{background:#fff;color:#475569;border:1px solid #e2e8f0}
.btn.sm{padding:5px 11px;font-size:11px;border-radius:6px}
.btn.sm svg{width:12px;height:12px}
select,input[type=text],input[type=search]{font:inherit;font-size:12px;border:1px solid #cbd5e1;border-radius:7px;padding:8px 12px;color:var(--ink);background:#fff;outline:none;width:100%}
select:focus,input:focus{border-color:var(--green-700);box-shadow:0 0 0 3px rgba(6,76,64,.1)}
label.flabel{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:#334155}
.hapus-row{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
.hapus-row>div{flex:1;min-width:150px}
.reg-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px}
.reg-grid .full{grid-column:1/-1}
.searchbar{display:flex;gap:8px;margin-bottom:16px;justify-content:flex-end}
.searchbar .swrap{position:relative;flex:1;max-width:420px}
.searchbar .swrap svg{position:absolute;left:12px;top:50%;transform:translateY(-50%);width:15px;height:15px;color:#94a3b8}
.searchbar .swrap input{padding-left:36px}
.table-wrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px;min-width:860px}
thead th{background:#f8fafc;color:#334155;font-weight:600;text-align:left;padding:11px 14px;border-bottom:1px solid #e2e8f0}
tbody td{padding:12px 14px;border-bottom:1px solid #f1f5f9;vertical-align:middle}
tbody tr:hover{background:#f8fafc}
tbody td b{font-variant-numeric:tabular-nums;color:#0f172a}
.acts{display:flex;gap:5px;flex-wrap:wrap}
.pager{display:flex;align-items:center;justify-content:space-between;gap:8px;margin-top:16px;font-size:12px;color:var(--muted);border-top:1px solid #f1f5f9;padding-top:14px}
.pager .pg{margin-left:auto;display:flex;gap:7px}
.pager .pg a,.pager .pg span{width:30px;height:30px;display:grid;place-items:center;border-radius:8px;background:#fff;border:1px solid var(--line);font-size:12.5px;font-weight:700;color:#4c626b;text-decoration:none}
.pager .pg .on{background:var(--blue);border-color:var(--blue);color:#fff}
/* COPY: bottomnav-start */
.bottomnav{display:none}
/* COPY: bottomnav-end */
@media(max-width:900px){
  .main{margin-left:0}.sidebar{position:fixed;left:0;top:0;z-index:50;transform:translateX(-105%);transition:transform .25s ease}
  body.nav-open .sidebar{transform:none}
  body.nav-open::after{content:"";position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:40}
  .hamburger{display:block}
  .grid2{grid-template-columns:1fr}
  .reg-grid{grid-template-columns:1fr 1fr}
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
  .content{padding:20px 14px}
  .page-title{font-size:24px}
  .reg-grid{grid-template-columns:1fr 1fr}
  .hapus-row{flex-direction:column;align-items:stretch}
  .bottomnav{display:flex;position:fixed;left:10px;right:10px;bottom:12px;z-index:60;background:#fff;border-radius:22px;box-shadow:0 10px 30px rgba(11,77,58,.18);padding:10px 6px}
  .bottomnav a{flex:1;display:flex;flex-direction:column;align-items:center;gap:4px;text-decoration:none;color:#93a3aa;font-size:11.5px;font-weight:600;padding:6px 0}
  .bottomnav a svg{width:24px;height:24px}
  .bottomnav a.on{color:var(--green-900)}
  .bottomnav a.on::after{content:"";width:44px;height:3px;border-radius:99px;background:var(--green-900)}
}
@media(max-width:480px){.reg-grid{grid-template-columns:1fr}}
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
      <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
      <a href="anggota.php" class="on" aria-current="page"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Data Anggota</a>
      <a href="peminjaman.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Peminjaman</a>
      <a href="pengembalian.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M10 4H6v16h8"/><path d="M14 12H3M6 8.5 2.5 12 6 15.5"/></svg>Pengembalian</a>
      <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Cetak Laporan</a>
      <hr>
      <a href="index.php?portal=admin"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Lihat Katalog</a>
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
      <h2>Data Anggota</h2>
      <div class="topbar-right">
        <span class="user-chip"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"><circle cx="12" cy="8" r="3.6"/><path d="M5 20c.8-3.6 3.6-5.4 7-5.4s6.2 1.8 7 5.4"/></svg>Halo, Petugas Perpustakaan&nbsp;&#9662;</span>
        <a class="btn-logout" href="logout.php" onclick="return confirm('Keluar dari sistem?')"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4H6v16h8"/><path d="M10 12h11M18 8.5 21.5 12 18 15.5"/></svg>Logout</a>
      </div>
    </div>
    <!-- COPY: topbar-end -->
    <div class="content">
      <h3 class="page-title">Kelola Data Anggota Perpustakaan</h3>
      <p class="page-sub">Lihat, cari, dan kelola data anggota perpustakaan dengan mudah</p>
      <?php if (!empty($pesan)): ?><div class="card" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:13px 16px;font-size:13px"><?= htmlspecialchars($pesan); ?></div><?php endif; ?>
      <?php if (isset($_GET['pesan']) && $_GET['pesan'] === 'dihapus'): ?><div class="card" style="background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;padding:13px 16px;font-size:13px">Data anggota berhasil dihapus!</div><?php endif; ?>
      <?php if (!empty($error)): ?><div class="card" style="background:#fdf0ec;border:1px solid #efc4b7;color:#9d321f;padding:13px 16px;font-size:13px;white-space:pre-line"><?= htmlspecialchars($error); ?></div><?php endif; ?>
      <div class="grid2">
        <div class="card">
          <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3v12M7.5 10.5 12 15l4.5-4.5"/><path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2"/></svg>Import Data Mahasiswa Massal</h3>
          <!-- Kontrak: import_anggota + file_anggota -->
          <form action="anggota.php" method="POST" enctype="multipart/form-data">
            <div class="file-row">
              <label class="file-btn" for="file_anggota"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"/></svg>Pilih File</label>
              <span class="file-name" id="fileName">Tidak ada file yang dipilih</span>
              <input type="file" id="file_anggota" name="file_anggota" accept=".xlsx,.csv" hidden required>
            </div>
            <div class="btn-row">
              <button type="submit" name="import_anggota" class="btn">Import Data</button>
              <a href="template_anggota.csv" class="btn ghost" download>Template Excel/CSV</a>
            </div>
          </form>
        </div>
        <div class="card">
          <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1.5"/></svg>Cetak KTA Massal</h3>
          <div class="hapus-row" style="margin-bottom:12px">
            <div><select id="ktaJurusan" aria-label="Jurusan"><option value="">Semua Jurusan</option><?php foreach ($prodi_options as $p): ?><option value="<?= htmlspecialchars($p, ENT_QUOTES); ?>"><?= htmlspecialchars($p); ?></option><?php endforeach; ?></select></div>
            <div><select id="ktaAngkatan" aria-label="Angkatan"><option value="">Semua Angkatan</option><?php foreach ($angkatan_options as $a): ?><option value="<?= htmlspecialchars($a, ENT_QUOTES); ?>"><?= htmlspecialchars($a); ?></option><?php endforeach; ?></select></div>
          </div>
          <div class="btn-row"><button type="button" class="btn" id="btnCetakKta"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 9V3h12v6M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="7" rx="1.5"/></svg>Cetak KTA</button></div>
        </div>
      </div>
      <div class="card">
        <div class="hapus-row">
          <div style="flex:1.2"><h3 style="margin:0">Hapus Data Mahasiswa Massal</h3></div>
          <!-- Kontrak: hapus_massal + hapus_jurusan/hapus_angkatan -->
          <form action="anggota.php" method="POST" style="display:flex;gap:14px;flex:1;flex-wrap:wrap" onsubmit="return confirm('Yakin hapus data sesuai filter? Data yang dihapus tidak dapat dikembalikan.')">
            <div style="flex:1;min-width:150px"><label class="flabel" for="hj">Program Studi</label><select id="hj" name="hapus_jurusan"><option value="">Semua Jurusan</option><?php foreach ($prodi_options as $p): ?><option value="<?= htmlspecialchars($p, ENT_QUOTES); ?>"><?= htmlspecialchars($p); ?></option><?php endforeach; ?></select></div>
            <div style="flex:1;min-width:150px"><label class="flabel" for="ha">Angkatan</label><select id="ha" name="hapus_angkatan"><option value="">Semua Angkatan</option><?php foreach ($angkatan_options as $a): ?><option value="<?= htmlspecialchars($a, ENT_QUOTES); ?>"><?= htmlspecialchars($a); ?></option><?php endforeach; ?></select></div>
            <div style="align-self:flex-end"><button type="submit" name="hapus_massal" class="btn red">Hapus Sesuai Filter</button></div>
          </form>
        </div>
      </div>
      <div class="card">
        <h3><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg><?= $data_edit ? 'Edit Data Anggota' : 'Registrasi Anggota Baru'; ?></h3>
        <!-- Kontrak: tambah_anggota / edit_anggota + nim/nama_lengkap/program_studi/no_telepon -->
        <form action="anggota.php" method="POST">
          <div class="reg-grid">
            <div><label class="flabel" for="nim">NIM Mahasiswa</label><input type="text" id="nim" name="nim" value="<?= $data_edit ? htmlspecialchars($data_edit['nim']) : ''; ?>" placeholder="Masukkan NIM..." <?= $data_edit ? 'readonly' : 'required'; ?>></div>
            <div><label class="flabel" for="nama">Nama Lengkap</label><input type="text" id="nama" name="nama_lengkap" value="<?= $data_edit ? htmlspecialchars($data_edit['nama_lengkap']) : ''; ?>" placeholder="Masukkan Nama Lengkap..." required></div>
            <div><label class="flabel" for="prodi">Program Studi</label><input type="text" id="prodi" name="program_studi" value="<?= $data_edit ? htmlspecialchars($data_edit['program_studi']) : ''; ?>" placeholder="Masukkan Program Studi" required></div>
            <div><label class="flabel" for="hp">Nomor Telepon/Wa</label><input type="text" id="hp" name="no_telepon" value="<?= $data_edit ? htmlspecialchars($data_edit['no_telepon']) : ''; ?>" placeholder="Masukkan Nomor Tlp..."></div>
            <div class="full">
              <?php if ($data_edit): ?>
                <button type="submit" name="edit_anggota" class="btn blue">Simpan Perubahan</button>
                <a href="anggota.php" class="btn ghost">Batal</a>
              <?php else: ?>
                <button type="submit" name="tambah_anggota" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m5 13 4 4L19 7"/></svg>Daftar Anggota</button>
              <?php endif; ?>
            </div>
          </div>
        </form>
      </div>
      <div class="card">
        <form class="searchbar" action="anggota.php" method="GET">
          <div class="swrap"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg><input type="search" name="cari" placeholder="Cari berdasarkan NIM, nama, atau judul buku..."></div>
          <button type="submit" class="btn blue"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.8-3.8"/></svg>Cari</button>
        </form>
        <div class="table-wrap"><table>
          <thead><tr><th>NIM</th><th>Nama Lengkap</th><th>Program Studi</th><th>No. Telepon</th><th>Tgl Bergabung</th><th>Aksi</th></tr></thead>
          <tbody>
          <?php if ($anggota_res && mysqli_num_rows($anggota_res) > 0): while ($row = mysqli_fetch_assoc($anggota_res)): ?>
            <tr><td><b><?= htmlspecialchars($row['nim']); ?></b></td><td><?= htmlspecialchars($row['nama_lengkap']); ?></td><td><?= htmlspecialchars($row['program_studi']); ?></td><td><?= !empty($row['no_telepon']) ? htmlspecialchars($row['no_telepon']) : '-'; ?></td><td><?= !empty($row['tgl_bergabung']) ? date('d-m-Y', strtotime($row['tgl_bergabung'])) : '-'; ?></td>
              <td><div class="acts">
                <a class="btn yellow sm" href="anggota.php?aksi=edit&amp;nim=<?= urlencode($row['nim']); ?>">Edit</a>
                <a class="btn blue sm" href="anggota.php?aksi=reset&amp;nim=<?= urlencode($row['nim']); ?>" onclick="return confirm('Reset password NIM <?= htmlspecialchars($row['nim'], ENT_QUOTES); ?> menjadi NIM?')">Reset Pass</a>
                <a class="btn red sm" href="anggota.php?aksi=hapus&amp;nim=<?= urlencode($row['nim']); ?>" onclick="return confirm('Yakin hapus anggota ini?')">Hapus</a>
                <a class="btn sm" href="kta.php?nim=<?= urlencode($row['nim']); ?>" target="_blank" rel="noopener">Cetak KTA</a>
              </div></td></tr>
          <?php endwhile; else: ?>
            <tr><td colspan="6" style="text-align:center;color:#8a99a0">Belum ada anggota yang cocok. Coba kata kunci lain.</td></tr>
          <?php endif; ?>
          </tbody>
        </table></div>
        <div class="pager"><span><?= $cari !== '' ? 'Hasil pencarian: "' . htmlspecialchars($cari) . '"' : 'Data anggota perpustakaan'; ?></span></div>
      </div>
    </div>
  </div>
</div>
<!-- COPY: bottomnav-start -->
<nav class="bottomnav" aria-label="Navigasi bawah">
  <a href="dashboard.php"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 3 2.5 10v11h6v-6h7v6h6V10L12 3z"/></svg>Beranda</a>
  <a href="buku.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 6.253v13m0-13C10.832 5.477 9.246 5 7.5 5S4.168 5.477 3 6.253v13C4.168 18.477 5.754 18 7.5 18s3.332.477 4.5 1.253m0-13C13.168 5.477 14.754 5 16.5 5c1.747 0 3.332.477 4.5 1.253v13C19.832 18.477 18.247 18 16.5 18c-1.746 0-3.332.477-4.5 1.253"/></svg>Data Buku</a>
  <a href="anggota.php" class="on"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>Anggota</a>
  <a href="laporan.php"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>Laporan</a>
</nav>
<!-- COPY: bottomnav-end -->
<script>
"use strict";
document.addEventListener("click",function(e){if(!e.target.closest(".sidebar")&&!e.target.closest(".hamburger")&&!e.target.closest(".mob-menu-btn"))document.body.classList.remove("nav-open");});
var fi=document.getElementById("file_anggota"),fn=document.getElementById("fileName");
if(fi&&fn){fi.addEventListener("change",function(){fn.textContent=fi.files.length?fi.files[0].name:"Tidak ada file yang dipilih";});}
var bk=document.getElementById("btnCetakKta");
if(bk){bk.addEventListener("click",function(){var j=document.getElementById("ktaJurusan"),a=document.getElementById("ktaAngkatan"),p=[];if(j&&j.value)p.push("jurusan="+encodeURIComponent(j.value));if(a&&a.value)p.push("angkatan="+encodeURIComponent(a.value));window.open("cetak-kta.php"+(p.length?"?"+p.join("&"):""),"_blank");});}
</script>
</body>
</html>
