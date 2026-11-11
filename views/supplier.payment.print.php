<?php
require_once __DIR__ . '/../php-func/user.valid.php';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    exit('Hak baca pembayaran supplier tidak tersedia.');
}

/*
Document      : supplier.payment.print.php
Description   : Cetak tanda terima / bukti pembayaran supplier (single)
Dipanggil dari: supplier.payment.js via PRINT_URL + id
Backend data  : Query langsung ke DB (tidak via task CRUD)
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

$paymentId = intval($_GET['id'] ?? 0);
if ($paymentId <= 0) {
    echo '<!DOCTYPE html><html><body><h3 style="color:#e05353;padding:20px;">ID pembayaran tidak valid.</h3></body></html>';
    exit;
}

$db = new crud();

// Load header pembayaran
$stmt = $db->prepare("
    SELECT p.id, p.payment_no, p.payment_date, p.supplier_id,
           p.account_id, p.payment_method, p.total_amount,
           p.allocated_amount, p.excess_amount, p.deposit_applied,
           p.reference_no, p.notes, p.created_by, p.created_at,
           s.name AS supplier_name, s.npwp_no AS supplier_npwp,
           (coa.account_code || ' - ' || coa.account_name) AS account_name,
           coa.account_code,
           u.login_name AS created_by_name
    FROM supplier_payments p
    LEFT JOIN m_suppliers s ON s.id = p.supplier_id
    LEFT JOIN chart_of_accounts coa ON coa.id = p.account_id
    LEFT JOIN m_user_system u ON u.id = p.created_by
    WHERE p.id = :id
");
$stmt->execute(['id' => $paymentId]);
$header = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$header) {
    echo '<!DOCTYPE html><html><body><h3 style="color:#e05353;padding:20px;">Data pembayaran tidak ditemukan.</h3></body></html>';
    exit;
}

// Load items (alokasi invoice)
$stmt2 = $db->prepare("
    SELECT 
        pi.inv_no AS invoice_no,
        pi.supplier_invoice_no,
        TO_CHAR(pi.invoice_date, 'DD-MM-YYYY') AS invoice_date,
        COALESCE(pi.total_amount, 0) AS total_amount,
        COALESCE(pi.total_amount - pi.paid_amount, 0) AS unpaid_amount,
        COALESCE(spi.amount_paid, 0) AS amount_paid
    FROM supplier_payment_items spi
    INNER JOIN purchase_invoice_hdr pi ON spi.invoice_id = pi.id
    WHERE spi.supplier_payment_id = :payment_id
    ORDER BY pi.invoice_date ASC, pi.id ASC
");
$stmt2->execute(['payment_id' => $paymentId]);
$items = $stmt2->fetchAll(PDO::FETCH_ASSOC);

function esc($v) {
    return htmlspecialchars($v === null || $v === undefined ? '' : (string)$v, ENT_QUOTES, 'UTF-8');
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

$h = $header;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Bukti Pembayaran Supplier - <?= esc($h['payment_no']) ?></title>
<style>
    @page { size: A4; margin: 15mm; }
    * { box-sizing: border-box; }
    body { font-family: 'Segoe UI', Arial, sans-serif; font-size: 12px; color: #1f2937; margin: 0; padding: 20px; }
    .hdr { display: flex; justify-content: space-between; align-items: flex-start; border-bottom: 2px solid #0f766e; padding-bottom: 10px; margin-bottom: 15px; }
    .hdr .l .co-name { font-size: 18px; font-weight: bold; color: #0f766e; }
    .hdr .l .co-addr { font-size: 11px; color: #6b7280; }
    .hdr .r { text-align: right; }
    .hdr .r b { font-size: 14px; color: #0f766e; }
    .title { text-align: center; font-size: 20px; font-weight: bold; color: #0f766e; margin: 15px 0; letter-spacing: 2px; }
    .meta { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .meta td { padding: 4px 8px; vertical-align: top; }
    .meta td.k { font-weight: bold; width: 160px; color: #374151; }
    .meta td b { color: #111827; }
    .items { width: 100%; border-collapse: collapse; margin-bottom: 15px; }
    .items th { background: #0f766e; color: white; padding: 8px; text-align: left; font-size: 11px; }
    .items th.r-al { text-align: right; }
    .items td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; }
    .items td.r-al { text-align: right; }
    .items tfoot td { background: #f3f4f6; font-weight: bold; padding: 8px; }
    .items tfoot td.r-al { text-align: right; }
    .sign { display: flex; justify-content: space-between; margin-top: 40px; }
    .sign > div { width: 45%; text-align: center; }
    .sign .space { height: 70px; }
    .sign .line { border-top: 1px solid #1f2937; padding-top: 4px; font-weight: bold; }
    .footer { margin-top: 30px; text-align: center; font-size: 10px; color: #9ca3af; border-top: 1px dashed #d1d5db; padding-top: 8px; }
    .btn-bar { text-align: right; margin-bottom: 10px; }
    .btn-bar button { padding: 8px 16px; margin-left: 8px; border: none; border-radius: 4px; cursor: pointer; font-weight: bold; }
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
    <button class="btn-print" id="btnPrintReceipt">🖨️ Cetak</button>
    <button class="btn-close" id="btnCloseReceipt">✕ Tutup</button>
</div>

<header class="hdr">
    <div class="l">
        <div class="co-name">RAB-Project</div>
        <div class="co-addr">Sistem Monitoring Proyek</div>
    </div>
    <div class="r">
        <b>BUKTI PEMBAYARAN SUPPLIER</b>
        <div>No. <?= esc($h['payment_no']) ?></div>
    </div>
</header>

<h1 class="title">BUKTI PEMBAYARAN</h1>

<p>Telah dilakukan pembayaran kepada supplier:</p>

<table class="meta">
    <tr>
        <td class="k">Nama Supplier</td>
        <td><b><?= esc($h['supplier_name']) ?></b></td>
        <td class="k">Tanggal Pembayaran</td>
        <td><b><?= dateId($h['payment_date']) ?></b></td>
    </tr>
    <tr>
        <td class="k">NPWP Supplier</td>
        <td><b><?= esc($h['supplier_npwp'] ?? '-') ?></b></td>
        <td class="k">Metode Pembayaran</td>
        <td><b><?= esc(methodLabel($h['payment_method'])) ?></b></td>
    </tr>
    <tr>
        <td class="k">Akun Pembayaran</td>
        <td><b><?= esc($h['account_name'] ?? '-') ?></b></td>
        <td class="k">Referensi</td>
        <td><b><?= esc($h['reference_no'] ?? '-') ?></b></td>
    </tr>
</table>

<table class="items">
    <thead>
        <tr>
            <th>No. Invoice</th>
            <th>No. Invoice Supplier</th>
            <th>Tgl Invoice</th>
            <th class="r-al">Total Tagihan</th>
            <th class="r-al">Sisa Tagihan</th>
            <th class="r-al">Dibayar</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($items)): ?>
        <tr><td colspan="6" style="text-align:center; padding:15px;">Tidak ada rincian alokasi invoice.</td></tr>
        <?php else:
            $totalAlloc = 0;
            foreach ($items as $it):
                $totalAlloc += num($it['amount_paid']);
        ?>
        <tr>
            <td><?= esc($it['invoice_no']) ?></td>
            <td><?= esc($it['supplier_invoice_no']) ?></td>
            <td><?= esc($it['invoice_date']) ?></td>
            <td class="r-al"><?= money($it['total_amount']) ?></td>
            <td class="r-al"><?= money($it['unpaid_amount']) ?></td>
            <td class="r-al"><b><?= money($it['amount_paid']) ?></b></td>
        </tr>
        <?php endforeach; endif; ?>
    </tbody>
    <tfoot>
        <tr>
            <td colspan="5" class="r-al">TOTAL DIALOKASIKAN</td>
            <td class="r-al"><?= money($totalAlloc) ?></td>
        </tr>
        <?php if (num($h['deposit_applied']) > 0.01): ?>
        <tr>
            <td colspan="5" class="r-al">Termasuk Saldo Deposit Dipakai</td>
            <td class="r-al"><?= money($h['deposit_applied']) ?></td>
        </tr>
        <?php endif; ?>
        <?php if (num($h['excess_amount']) > 0.01): ?>
        <tr>
            <td colspan="5" class="r-al">Lebih Bayar (Uang Muka)</td>
            <td class="r-al"><?= money($h['excess_amount']) ?></td>
        </tr>
        <?php endif; ?>
        <tr>
            <td colspan="5" class="r-al"><b>TOTAL DIBAYAR</b></td>
            <td class="r-al"><b><?= money($h['total_amount']) ?></b></td>
        </tr>
    </tfoot>
</table>

<?php if (!empty($h['notes'])): ?>
<p><b>Catatan:</b> <?= esc($h['notes']) ?></p>
<?php endif; ?>

<div class="sign">
    <div>
        <p>Dibayar oleh,</p>
        <div class="space"></div>
        <div class="line"><?= esc($h['created_by_name'] ?? 'Petugas Keuangan') ?></div>
    </div>
    <div>
        <p>Diterima oleh (Supplier),</p>
        <div class="space"></div>
        <div class="line"><?= esc($h['supplier_name']) ?></div>
    </div>
</div>

<div class="footer">
    Dokumen dihasilkan oleh RAB-Monitor &middot; <?= esc($h['payment_no']) ?> &middot; Dicetak: <?= date('d/m/Y H:i') ?>
</div>

<script>
document.getElementById('btnPrintReceipt').onclick = function () {
    window.focus();
    window.print();
};
document.getElementById('btnCloseReceipt').onclick = function () {
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