<?php
/*
  Document      : pdf/print.common.php   [PRINT][NEW]
  Description   : Helper bersama halaman cetak laporan keuangan (trialbalance.php, profitloss.php,
                  financialreport.php, journal.php): boot sesi + koneksi, periode, kerangka HTML A4, format angka.
                  Halaman cetak = HTML siap cetak; dialog cetak browser baru muncul saat tombol "Cetak / Simpan PDF" diklik.
*/

function pr_e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pr_n($v) { return number_format((float)$v, 2, '.', ','); }

// Sesi + koneksi PDO. Mengembalikan objek crud.
function pr_boot() {
    if (session_status() === PHP_SESSION_NONE) { session_start(); }
    require_once __DIR__ . '/../php-func/function.all.php';
    require_once __DIR__ . '/../php-class/crudClass.php';
    header('Content-Type: text/html; charset=utf-8');
    // [NOCACHE] halaman cetak jangan di-cache browser/proxy (ngrok) agar selalu memakai versi file terbaru di server
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        echo 'Sesi berakhir, silakan login kembali.';
        exit;
    }
    return new crud();
}

// Ambil periode akuntansi; keluar dengan pesan bila tidak valid.
function pr_period($db, $periodId) {
    if ($periodId <= 0) { echo 'Periode wajib dipilih.'; exit; }
    $st = $db->prepare("SELECT id, period_name, start_date, end_date FROM accounting_periods WHERE id = :id");
    $st->execute(['id' => $periodId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { echo 'Periode tidak ditemukan.'; exit; }
    return $p;
}

function pr_date($d) { return $d ? date('d/m/Y', strtotime($d)) : '-'; }

// Buka halaman: $lines = baris sub-judul, $status = teks status (opsional), $ok = warna status
// $extraCss (opsional) = CSS tambahan khusus halaman, mis. '@page { size: A4 landscape; }' untuk laporan lebar seperti General Ledger
function pr_open($docTitle, $heading, $lines = [], $status = '', $ok = true, $extraCss = '') {
    ?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title><?php echo pr_e($docTitle); ?></title>

</head>
<body>
<div class="noprint"><button onclick="window.print()">Cetak / Simpan PDF</button></div>
<h1><?php echo pr_e($heading); ?></h1>
<?php foreach ($lines as $l) { echo '<div class="sub">' . $l . '</div>'; } ?>
<?php if ($status !== '') { echo '<div class="status" >' . pr_e($status) . '</div>'; } ?>
<?php
}

function pr_close() {
    echo '<div class="foot">Dicetak: ' . date('d/m/Y H:i') . '</div></body></html>';
}

// Blok seksi: judul + daftar [kode, nama, nilai] + baris total. Baris bernilai ~0 sudah disaring pemanggil.
function pr_section($title, $items, $totalLabel, $total) {
    $h = '<h3>' . pr_e($title) . '</h3><table class="rpt">';
    $i = 0;
    foreach ($items as $it) {
        $h .= '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td class="c">' . pr_e($it[0]) . '</td><td>' . pr_e($it[1]) . '</td><td class="r">' . pr_n($it[2]) . '</td></tr>';
    }
    if (!count($items)) { $h .= '<tr><td colspan="3" class="muted">Tidak ada saldo.</td></tr>'; }
    $h .= '<tr class="tot"><td colspan="2">' . pr_e($totalLabel) . '</td><td class="r">' . pr_n($total) . '</td></tr></table>';
    return $h;
}