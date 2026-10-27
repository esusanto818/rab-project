<?php
/*
  Document      : pdf/projectinvoice.php   [TAGIHAN KE KLIEN]
  Description   : Cetak invoice (tagihan termin) ke client: nomor & tanggal, jatuh tempo, client, proyek, rincian DPP / PPN / total,
                  pembayaran yang sudah diterima, sisa tagihan, dan no. faktur pajak. Memakai helper print.common.php
                  (pr_boot, pr_open, pr_close, pr_e, pr_n, pr_date) seperti pdf/profitloss.php.
  Parameter     : id (project_invoices.id, wajib)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();

$id = intval($_GET['id'] ?? 0);
$st = $db->prepare("
    SELECT v.id, v.inv_no, v.invoice_date, v.due_date, v.termin_name, v.percentage, v.amount, v.paid_amount,
           v.dpp_amount, v.ppn_rate, v.ppn_amount, v.faktur_pajak_no, v.faktur_pajak_date, v.remark,
           p.name AS project_name, p.location AS project_location, COALESCE(p.contract_value, 0) AS contract_value,
           cl.name AS client_name, cl.address AS client_address, cl.phone AS client_phone, cl.contact_person AS client_pic
      FROM project_invoices v
      JOIN projects p ON p.id = v.project_id
      LEFT JOIN clients cl ON cl.id = p.client_id
     WHERE v.id = :id");
$st->execute(['id' => $id]);
$inv = $st->fetch(PDO::FETCH_ASSOC);
if (!$inv) { http_response_code(404); echo 'Invoice tidak ditemukan.'; exit; }

$st = $db->prepare("
    SELECT pp.payment_no, pp.payment_date, i.amount_paid
      FROM project_payment_items i JOIN project_payments pp ON pp.id = i.payment_id
     WHERE i.invoice_id = :id ORDER BY pp.payment_date, pp.id");
$st->execute(['id' => $id]);
$pays = $st->fetchAll(PDO::FETCH_ASSOC);

$amount = floatval($inv['amount']); $paid = floatval($inv['paid_amount']);
$dpp    = floatval($inv['dpp_amount']) > 0 ? floatval($inv['dpp_amount']) : $amount;     // invoice lama: seluruh amount = DPP
$ppn    = floatval($inv['ppn_amount']);
$sisa   = round($amount - $paid, 2);

$info = [
    'No. Invoice: ' . pr_e($inv['inv_no']) . ' &nbsp;|&nbsp; Tanggal: ' . pr_e(pr_date($inv['invoice_date'])) .
        ($inv['due_date'] ? ' &nbsp;|&nbsp; Jatuh Tempo: ' . pr_e(pr_date($inv['due_date'])) : ''),
    'Kepada: ' . pr_e($inv['client_name'] ?: '-') . ($inv['client_address'] ? ' - ' . pr_e($inv['client_address']) : ''),
    'Proyek: ' . pr_e($inv['project_name']) . ($inv['project_location'] ? ' (' . pr_e($inv['project_location']) . ')' : ''),
];
pr_open('Invoice ' . $inv['inv_no'], 'INVOICE / TAGIHAN', $info);
?>
<table class="rpt fx">
    <colgroup><col><col style="width:200px"></colgroup>
    <tr><th>Keterangan</th><th class="r">Jumlah (IDR)</th></tr>
    <tr>
        <td>Termin: <?php echo pr_e($inv['termin_name']); ?><?php
            if (floatval($inv['percentage']) > 0) { echo ' (' . pr_e(rtrim(rtrim(number_format(floatval($inv['percentage']), 2, '.', ''), '0'), '.')) . '% dari nilai kontrak)'; } ?></td>
        <td class="r"><?php echo pr_n($dpp); ?></td>
    </tr>
<?php if ($ppn > 0.004) { ?>
    <tr class="z"><td>PPN <?php echo pr_e(rtrim(rtrim(number_format(floatval($inv['ppn_rate']), 2, '.', ''), '0'), '.')); ?>%</td><td class="r"><?php echo pr_n($ppn); ?></td></tr>
<?php } ?>
    <tr class="tot"><td>TOTAL TAGIHAN</td><td class="r"><?php echo pr_n($amount); ?></td></tr>
<?php foreach ($pays as $i => $p) { ?>
    <tr<?php echo $i % 2 ? ' class="z"' : ''; ?>><td>Pembayaran diterima <?php echo pr_e($p['payment_no']); ?> (<?php echo pr_e(pr_date($p['payment_date'])); ?>)</td><td class="r">- <?php echo pr_n(floatval($p['amount_paid'])); ?></td></tr>
<?php } ?>
    <tr class="net"><td>SISA TAGIHAN</td><td class="r"><?php echo pr_n($sisa); ?></td></tr>
</table>
<?php if ($ppn > 0.004) { ?>
<p>Faktur Pajak: <b><?php echo $inv['faktur_pajak_no'] ? pr_e($inv['faktur_pajak_no']) . ($inv['faktur_pajak_date'] ? ' (' . pr_e(pr_date($inv['faktur_pajak_date'])) . ')' : '') : 'belum diterbitkan'; ?></b></p>
<?php } ?>
<?php if ($inv['remark']) { ?><p>Catatan: <?php echo pr_e($inv['remark']); ?></p><?php } ?>
<?php pr_close(); ?>