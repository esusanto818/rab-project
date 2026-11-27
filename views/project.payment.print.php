<?php
/*
 * Cetak Tanda Terima Pembayaran Termin
 * File ini hanya menyediakan kerangka HTML/CSS.
 * Pengambilan data dan rendering dilakukan oleh project.payment.print.js.
 */
include "../php-func/user.valid.php";
$paymentId = intval($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1" />
    <title>Tanda Terima Pembayaran Proyek</title>
    
</head>
<body>
    <div class="toolbar">
        <button type="button" class="print" id="btnPrintReceipt">Cetak</button>
        <button type="button" class="close" id="btnCloseReceipt">Tutup</button>
    </div>
    <main id="receipt" class="sheet">
        <div class="err" >Memuat tanda terima...</div>
    </main>
    <script>
        window.PAYMENT_ID = <?php echo json_encode($paymentId); ?>;
        window.PAYMENT_CRD_URL = '/rab-project/dev/php-crud/project.paymentCRD.php';
    </script>
    <script src="/rab-project/dev/js/project.payment.print.js?v=20261023-fix2"></script>
</body>
</html>
