<?php
require_once __DIR__ . '/../php-func/user.valid.php';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    exit('Hak baca pembayaran supplier tidak tersedia.');
}

/*
Document      : supplier.payment.list.print.php
Description   : Cetak daftar / rekap seluruh pembayaran supplier
Dipanggil dari: supplier.payment.js via LIST_PRINT_URL
Backend data  : Query langsung ke DB
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

$db = new crud();

$sql = "
    SELECT p.id, p.payment_no, p.payment_date, p.supplier_id,
           s.name AS supplier_name,
           (coa.account_code || ' - ' || coa.account_name) AS account_name,
           p.payment_method, p.total_amount, 
           COALESCE(p.allocated_amount, 0) AS allocated_amount,
           COALESCE(p.excess_amount, 0) AS excess_amount,
           COALESCE(p.deposit_applied, 0) AS deposit_applied,
           p.reference_no, p.notes
    FROM supplier_payments p
    LEFT JOIN m_suppliers s ON s.id = p.supplier_id
    LEFT JOIN chart_of_accounts coa ON coa.id = p.account_id
    ORDER BY p.payment_date DESC, p.id DESC
    LIMIT 500
";
$stmt = $db->query($sql);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

function esc($v) {
    return htmlspecialchars($v === null ? '' : (string)$v, ENT_QUOTES, 'UTF-8');
}
function num($v) { $n = floatval($v); return is_finite($n) ? $n : 0; }
function money($v) { return number_format(num($v), 2, ',', '.'); }
function dateId($v) {
    if (!$v) return '-';
    $s = substr($v, 0, 10);
    $p = explode('-', $s);
    return count($p) === 3 ? $p[2] . '/' . $p[1] . '/' . $p[0] : esc($v);
}
function methodLabel($v) {
    $map = ['BANK_TRANSFER' => 'Transfer Bank', 'CASH' => 'Tunai', 'GIRO' => 'Giro', 'CEK' => 'Cek', 'CHEQUE' => 'Cek'];
    return $map[strtoupper($v)] ?? $v ?? '-';
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Daftar Pembayaran Supplier</title>
<style>
    @page { size: A4 landscape; margin: 12mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 0; padding: 15px; }
    .hdr { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f766e; padding-bottom: 8px; margin-bottom: 12px; }
    .hdr .l .co-name { font-size: 16px; font-weight: bold; color: #0f766e; }
    .hdr .l .co-addr { font-size: 10px; color: #6b7280; }
    .hdr .r { text-align: right; }
    .hdr .r b { font-size: 13px; color: #0f766e; }
    .title { text-align: center; font-size: 16px; font-weight: bold; color: #0f766e; margin: 10px 0; }
    .items { width: 100%; border-collapse: collapse; }
    .items th { background: #0f766e; color: white; padding: 6px; text-align: left; font-size: 10px; }
    .items th.r-al, .items td.r-al { text-align: right; }
    .items td { padding: 5px 6px; border-bottom: 1px solid #e5e7eb; }
    .items tfoot td { background: #f3f4f6; font-weight: bold; padding: 6px; }
    .items tfoot td.r-al { text-align: right; }
    .btn-bar { text-align: right; margin-bottom: 8px; }
    .btn-bar button { padding: 6px 14px; margin-left: 6px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; font-size: 11px; }
    .btn-print { background: #0f766e; color: white; }
    .btn-close { background: #6b7280; color: white; }
    @media print {
        .btn-bar { display: none; }
        body { padding: 0; }
    }
</style>
</head>
<body>

<div class="btn-bar">
    <button class="btn-print" id="btnPrintList">🖨️ Cetak</button>
    <button class="btn-close" id="btnCloseList">✕ Tutup</button>
</div>

<header class="hdr">
    <div class="l">
        <div class="co-name">RAB-Monitor</div>
        <div class="co-addr">Sistem Monitoring Proyek</div>
    </div>
    <div class="r">
        <b>DAFTAR PEMBAYARAN SUPPLIER</b>
        <div>Tanggal Cetak: <?= date('d/m/Y H:i') ?></div>
    </div>
</header>

<h1 class="title">REKAP PEMBAYARAN SUPPLIER</h1>

<?php if (empty($data)): ?>
    <p style="text-align:center; color:#6b7280;">Belum ada data pembayaran supplier.</p>
<?php else:
    $grandTotal = 0;
?>
<table class="items">
    <thead>
        <tr>
            <th>No. Pembayaran</th>
            <th>Tanggal</th>
            <th>Supplier</th>
            <th>Akun</th>
            <th>Metode</th>
            <th>Referensi</th>
            <th class="r-al">Total Dibayar</th>
            <th class="r-al">Dialokasikan</th>
            <th class="r-al">Deposit Dipakai</th>
            <th class="r-al">Lebih Bayar</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($data as $r):
            $grandTotal += num($r['total_amount']);
        ?>
        <tr>
            <td><b><?= esc($r['payment_no']) ?></b></td>
            <td><?= dateId($r['payment_date']) ?></td>
            <td><?= esc($r['supplier_name']) ?></td>
            <td><?= esc($r['account_name'] ?? '-') ?></td>
            <td><?= esc(methodLabel($r['payment_method'])) ?></td>
            <td><?= esc($r['reference_no'] ?? '-') ?></td>
            <td class="r-al"><?= money($r['total_amount']) ?></td>
            <td class="r-al"><?= money($r['allocated_amount']) ?></td>
            <td class="r-al"><?= money($r['deposit_applied']) ?></td>
            <td class="r-al"><?= money($r['excess_amount']) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="6" class="r-al">GRAND TOTAL</td>
            <td class="r-al"><?= money($grandTotal) ?></td>
            <td colspan="3"></td>
        </tr>
    </tfoot>
</table>
<?php endif; ?>

<div class="footer" style="margin-top:20px; text-align:center; font-size:10px; color:#9ca3af; border-top:1px dashed #d1d5db; padding-top:6px;">
    Dokumen dihasilkan oleh RAB-Monitor &middot; Dicetak: <?= date('d/m/Y H:i') ?>
</div>

<script>
document.getElementById('btnPrintList').onclick = function () {
    window.focus();
    window.print();
};
document.getElementById('btnCloseList').onclick = function () {
    window.close();
    setTimeout(function () {
        if (!window.closed) {
            if (window.history.length > 1) window.history.back();
            else window.location.href = '/rab-project/';
        }
    }, 120);
};
</script>

</body>
</html>