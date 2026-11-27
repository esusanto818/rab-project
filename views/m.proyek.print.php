<?php
/*
 * Print Master Proyek dan Rincian Task.
 * Data dibaca melalui endpoint resmi m.proyekCRD.php agar konsisten dengan aplikasi.
 */
require_once __DIR__ . '/../php-func/user.valid.php';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cetak Proyek</title>

</head>
<body>
<div class="toolbar"><button class="print" id="btnPrint" type="button">Cetak / Simpan PDF</button><button class="close" id="btnClose" type="button">Tutup</button></div>
<header class="heading"><h1 id="title">Laporan Proyek</h1><div class="muted" id="subtitle">RAB-Monitor</div></header>
<main id="content"><div class="status">Memuat data...</div></main>
<script>window.PROJECT_PRINT_MODE = <?php echo json_encode($_GET['mode'] ?? 'projects'); ?>; window.PROJECT_PRINT_ID = <?php echo json_encode((int)($_GET['project_id'] ?? 0)); ?>;</script>
<script src="m.proyek.print.js"></script>
</body></html>