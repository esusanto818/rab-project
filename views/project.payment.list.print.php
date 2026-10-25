<?php
/* Cetak daftar header seluruh pembayaran RAB-Monitor. */
include "../php-func/user.valid.php";
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Daftar Pembayaran - RAB-Monitor</title>
<style>
@page { size: A4 landscape; margin: 12mm; }
* { box-sizing: border-box; }
body { font: 11px Arial, Helvetica, sans-serif; color: #18212b; margin: 0; background: #eee; }
.toolbar { padding: 10px; text-align: center; background: #333; }
button { padding: 8px 18px; margin: 0 4px; border: 0; border-radius: 3px; cursor: pointer; }
.print { background: #08756e; color: #fff; }
.close { background: #e8edf0; color: #18212b; }
.sheet { width: 100%; max-width: 1200px; margin: 12px auto; padding: 12mm; background: #fff; }
h1 { margin: 0 0 5px; font-size: 20px; color: #075e58; }
.subtitle { margin: 0 0 18px; color: #64717d; }
table { width: 100%; border-collapse: collapse; }
th, td { border: 1px solid #cbd3d8; padding: 6px; vertical-align: top; }
th { background: #edf4f4; text-align: left; }
.num { text-align: right; white-space: nowrap; }
tfoot td { font-weight: bold; }
.status { padding: 20px; text-align: center; color: #64717d; }
.footer { margin-top: 12px; border-top: 1px solid #d8dee4; padding-top: 6px; color: #7a858e; text-align: center; font-size: 9px; }
@media print { body { background: #fff; } .toolbar { display: none; } .sheet { margin: 0; max-width: none; padding: 0; } thead { display: table-header-group; } tr { break-inside: avoid; page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="toolbar">
<button class="print" id="btnPrintList" type="button">Cetak / Simpan PDF</button>
<button class="close" id="btnCloseList" type="button">Tutup</button>
</div>
<main class="sheet">
<h1>Daftar Pembayaran</h1>
<p class="subtitle">RAB-Monitor · Seluruh pembayaran yang sudah tercatat</p>
<div id="listStatus" class="status">Memuat data pembayaran...</div>
<table id="paymentTable" hidden>
<thead><tr>
<th>No. Pembayaran</th><th>Tanggal</th><th>Client</th><th>Proyek</th><th>Metode</th><th>Mata Uang</th>
<th class="num">Jumlah Diterima</th><th class="num">Dialokasikan</th><th class="num">Lebih Bayar</th><th>Referensi</th>
</tr></thead><tbody id="paymentRows"></tbody>
<tfoot><tr><td colspan="6" class="num">Total Diterima (IDR)</td><td id="grandTotal" class="num"></td><td colspan="3"></td></tr></tfoot>
</table>
<div class="footer">Dokumen dihasilkan oleh RAB-Monitor</div>
</main>
<script>
<<<<<<< Updated upstream
window.PAYMENT_LIST_CRD_URL = '/rab-project/dev/php-crud/project.paymentCRD.php';
</script>
<script src="/rab-project/dev/js/project.payment.list.print.js"></script>
=======
window.PAYMENT_LIST_CRD_URL = '/rab-project/live/php-crud/project.paymentCRD.php';
</script>
<script src="/rab-project/live/js/project.payment.list.print.js"></script>
>>>>>>> Stashed changes
</body>
</html>
