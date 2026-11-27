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
<div class="footer">Dokumen dihasilkan oleh RAB-Project</div>
</main>
<script>
window.PAYMENT_LIST_CRD_URL =
    <?= json_encode(rtrim(BASE_URL, '/') . '/php-crud/project.paymentCRD.php') ?>;
</script>

<script src="<?= htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8') ?>/js/project.payment.list.print.js?v=20261107-fix2"></script>
</body>
</html>
