<?php
// Skrip untuk menerapkan migrasi retensi klien (yang tertunda)
require_once __DIR__ . '/../php-func/function.all.php';

global $conn;

$sql = file_get_contents(__DIR__ . '/../sql/20261115.project_invoice_retention.sql');

echo "Menerapkan migrasi retensi...\n";
$res = pg_query($conn, $sql);
if ($res === false) {
    echo "GAGAL: " . pg_last_error($conn) . "\n";
    exit(1);
}
echo "Migrasi berhasil diterapkan.\n";
