<?php
// Script untuk mengaudit & menerapkan validasi Tutup Periode pada seluruh file transaksi CRUD.

$dir = '/home/myadmin/proyekgmn-dev/php-crud';
$files = scandir($dir);
$count = 0;

foreach ($files as $file) {
    if (strpos($file, 'CRD.php') !== false && !in_array($file, ['m.fiscalperiodCRD.php', 'journal.entryCRD.php'])) {
        $path = $dir . '/' . $file;
        $content = file_get_contents($path);

        // Cek apakah sudah ada ensure_period_open
        if (strpos($content, 'ensure_period_open') === false) {
            echo "Memproses: $file\n";
            // Implementasi sederhana:
            // 1. Cari case create/update/delete
            // 2. Tambahkan validasi periode
            // Ini akan memerlukan logika lebih dalam jika mau otomatis.
            // Untuk sekarang, kita lakukan secara manual untuk file krusial.
        }
    }
}
