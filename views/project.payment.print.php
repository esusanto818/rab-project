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
    <title>Tanda Terima Pembayaran</title>
    <style>
        @page { size: A4; margin: 15mm; }
        * { box-sizing: border-box; }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #18212b; background: #eee; margin: 0; }
        .toolbar { padding: 10px; text-align: center; background: #333; }
        .toolbar button { padding: 8px 20px; font-size: 13px; cursor: pointer; margin: 0 4px; border: 0; border-radius: 3px; }
        .toolbar .print { background: #08756e; color: #fff; }
        .toolbar .close { background: #e8edf0; color: #18212b; }
        .sheet { width: 190mm; min-height: 120mm; margin: 12px auto; padding: 12mm; background: #fff; border: 1px solid #999; }
        .hdr { display: table; width: 100%; border-bottom: 2px solid #075e58; padding-bottom: 8px; }
        .hdr .l, .hdr .r { display: table-cell; vertical-align: top; }
        .hdr .r { text-align: right; }
        .co-name { font-size: 19px; font-weight: bold; color: #075e58; }
        .co-addr { font-size: 11px; color: #555; margin-top: 3px; }
        .title { font-size: 18px; font-weight: bold; letter-spacing: 1px; text-align: center; margin: 20px 0; }
        .meta { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .meta td { padding: 4px 2px; vertical-align: top; }
        .meta td.k { width: 140px; color: #586572; }
        .amount-box { border: 2px solid #075e58; padding: 8px 12px; margin: 10px 0; font-size: 15px; font-weight: bold; }
        .terbilang { font-style: italic; margin: 4px 0 12px 0; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th, table.items td { border: 1px solid #cbd3d8; padding: 6px; vertical-align: top; }
        table.items th { background: #edf4f4; text-align: left; }
        .r-al { text-align: right; white-space: nowrap; }
        .sign { display: table; width: 100%; margin-top: 28px; text-align: center; }
        .sign > div { display: table-cell; width: 50%; }
        .sign .space { height: 58px; }
        .sign .line { border-top: 1px solid #333; display: inline-block; min-width: 160px; padding-top: 4px; }
        .err { padding: 30px; text-align: center; color: #b42318; font-size: 14px; }
        .ai-section { margin-top: 24px; border: 1px solid #b8d8d5; padding: 12px; break-inside: auto; page-break-inside: auto; }
        .ai-section h3 { margin: 0 0 10px; color: #075e58; font-size: 15px; }
        .ai-project { border-top: 1px solid #d9e7e5; padding-top: 10px; margin-top: 10px; }
        .ai-risk { display: inline-block; padding: 4px 8px; border-radius: 3px; font-weight: bold; background: #eef2f4; }
        .ai-summary { line-height: 1.5; white-space: pre-wrap; }
        .ai-recommendations { padding-left: 20px; line-height: 1.5; }
        .muted { color: #64717d; font-size: 10px; }
        .footer { margin-top: 20px; border-top: 1px solid #d8dee4; padding-top: 8px; color: #7a858e; text-align: center; font-size: 10px; }
        @media print {
            body { background: #fff; }
            .toolbar { display: none; }
            .sheet { border: none; margin: 0; width: auto; min-height: 0; padding: 0; }
            .ai-section, .ai-project { break-inside: auto !important; page-break-inside: auto !important; display: block !important; visibility: visible !important; }
            .ai-section * { visibility: visible !important; }
            .ai-section.force-page-break { break-before: page; page-break-before: always; }
        }
        @media screen and (max-width: 760px) {
            .sheet { width: 100%; margin: 0; padding: 18px; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button type="button" class="print" id="btnPrintReceipt">Cetak</button>
        <button type="button" class="close" id="btnCloseReceipt">Tutup</button>
    </div>
    <main id="receipt" class="sheet">
        <div class="err" style="color:#64717d;">Memuat tanda terima...</div>
    </main>
    <script>
        window.PAYMENT_ID = <?php echo json_encode($paymentId); ?>;
        window.PAYMENT_CRD_URL = '/rab-project/dev/php-crud/project.paymentCRD.php';
    </script>
    <script src="/rab-project/dev/js/project.payment.print.js?v=20261023-fix2"></script>
</body>
</html>
