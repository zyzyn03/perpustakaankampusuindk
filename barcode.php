<?php
// =====================================================================
// GENERATOR BARCODE CODE39 (SVG) — untuk label buku perpustakaan.
// Dipakai oleh buku.php?aksi=barcode & aksi=cetak_barcode.
// Contoh: barcode.php?data=BK-025&h=70
// =====================================================================
$data = isset($_GET['data']) ? strtoupper(trim((string)$_GET['data'])) : '';
$h    = isset($_GET['h']) ? max(30, min(200, (int)$_GET['h'])) : 70;
$narrow = isset($_GET['nw']) ? max(1, min(4, (int)$_GET['nw'])) : 2; // lebar modul sempit (px)

// Tabel Code39: 9 elemen per karakter (b=bar, s=space), 'w'=wide, 'n'=narrow.
$code39 = array(
  '0'=>'nnnwwnwnn','1'=>'wnnwnnnnw','2'=>'nnwwnnnnw','3'=>'wnwwnnnnn','4'=>'nnnwwnnnw',
  '5'=>'wnnwwnnnn','6'=>'nnwwwnnnn','7'=>'nnnwnnwnw','8'=>'wnnwnnwnn','9'=>'nnwwnnwnn',
  'A'=>'wnnnnwnnw','B'=>'nnwnnwnnw','C'=>'wnwnnwnnn','D'=>'nnnnwwnnw','E'=>'wnnnwwnnn',
  'F'=>'nnwnwwnnn','G'=>'nnnnnwwnw','H'=>'wnnnnwwnn','I'=>'nnwnnwwnn','J'=>'nnnnwwwnn',
  'K'=>'wnnnnnnww','L'=>'nnwnnnnww','M'=>'wnwnnnnwn','N'=>'nnnnwnnww','O'=>'wnnnwnnwn',
  'P'=>'nnwnwnnwn','Q'=>'nnnnnnwww','R'=>'wnnnnnwwn','S'=>'nnwnnnwwn','T'=>'nnnnwnwwn',
  'U'=>'wwnnnnnnw','V'=>'nwwnnnnnw','W'=>'wwwnnnnnn','X'=>'nwnnwnnnw','Y'=>'wwnnwnnnn',
  'Z'=>'nwwnwnnnn','-'=>'nwnnnnwnw','.'=>'wwnnnnwnn',' '=>'nwwnnnwnn','$'=>'nwnwnwnnn',
  '/'=>'nwnwnnnwn','+'=>'nwnnnwnwn','%'=>'nnnwnwnwn','*'=>'nwnnwnwnn'
);

// Bersihkan data: hanya karakter yang didukung Code39, default '-' bila kosong.
$clean = '';
$len = strlen($data);
for ($i = 0; $i < $len; $i++) {
    $ch = $data[$i];
    if (isset($code39[$ch])) $clean .= $ch;
}
if ($clean === '') $clean = '-';

$seq = '*' . $clean . '*'; // start & stop guard

// Bangun bar: setiap karakter 9 elemen, elemen ganjil = bar, genap = space.
// Setelah tiap karakter ada 1 narrow space sebagai inter-character gap.
$bars = array(); // [x, width]
$x = 0;
$slen = strlen($seq);
for ($c = 0; $c < $slen; $c++) {
    $pat = $code39[$seq[$c]];
    for ($e = 0; $e < 9; $e++) {
        $w = ($pat[$e] === 'w') ? $narrow * 3 : $narrow;
        $isBar = ($e % 2 === 0);
        if ($isBar) $bars[] = array($x, $w);
        $x += $w;
    }
    $x += $narrow; // inter-character gap
}
$totalW = $x + $narrow; // quiet zone kanan

header('Content-Type: image/svg+xml; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . $totalW . '" height="' . ($h + 18) . '" viewBox="0 0 ' . $totalW . ' ' . ($h + 18) . '">';
$svg .= '<rect width="100%" height="100%" fill="#ffffff"/>';
foreach ($bars as $b) {
    $svg .= '<rect x="' . $b[0] . '" y="0" width="' . $b[1] . '" height="' . $h . '" fill="#000000"/>';
}
// Teks kode di bawah barcode.
$svg .= '<text x="' . round($totalW / 2) . '" y="' . ($h + 14) . '" text-anchor="middle" font-family="monospace" font-size="12" fill="#000000">' . htmlspecialchars($clean, ENT_QUOTES) . '</text>';
$svg .= '</svg>';

echo $svg;
