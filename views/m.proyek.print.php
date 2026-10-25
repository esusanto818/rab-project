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
<style>
*{box-sizing:border-box}body{font:12px Arial,sans-serif;color:#17212b;margin:20px}.toolbar{display:flex;gap:8px;margin-bottom:18px}.toolbar button{padding:8px 16px;border:0;border-radius:4px;cursor:pointer}.print{background:#087f8c;color:#fff}.close{background:#e8edf0;color:#17212b}.heading{border-bottom:2px solid #087f8c;padding-bottom:10px;margin-bottom:16px}.heading h1{font-size:21px;margin:0 0 5px}.muted{color:#52616b}.meta{display:grid;grid-template-columns:1fr 1fr;gap:7px 20px;margin:14px 0}.meta div{padding:5px 0;border-bottom:1px solid #e2e8eb}table{width:100%;border-collapse:collapse;margin-top:12px}th,td{border:1px solid #cbd5da;padding:7px;text-align:left;vertical-align:top}th{background:#eaf2f4}h2{font-size:15px;margin:20px 0 6px}.status{padding:20px;text-align:center;color:#52616b}@media print{body{margin:0}.toolbar{display:none}thead{display:table-header-group}tr{break-inside:avoid}.heading,h2{break-after:avoid}}
</style>
</head>
<body>
<div class="toolbar"><button class="print" id="btnPrint" type="button">Cetak / Simpan PDF</button><button class="close" id="btnClose" type="button">Tutup</button></div>
<header class="heading"><h1 id="title">Laporan Proyek</h1><div class="muted" id="subtitle">RAB-Monitor</div></header>
<main id="content"><div class="status">Memuat data...</div></main>
<script>window.PROJECT_PRINT_MODE = <?php echo json_encode($_GET['mode'] ?? 'projects'); ?>; window.PROJECT_PRINT_ID = <?php echo json_encode((int)($_GET['project_id'] ?? 0)); ?>;</script>
<script src="m.proyek.print.js"></script>
</body></html>