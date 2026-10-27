<?php
/*
  Document      : pdf/ppnreport.php
  Description   : Halaman cetak Laporan PPN bulanan per proyek (HTML siap cetak, tombol Cetak / Simpan PDF).  [PPN - TAHAP 3]
                  Data diambil dari php-crud/ppn.reportCRD.php lewat fetch (satu sumber hitungan dengan layar laporan).
  Param         : year, month, project_id (0 = semua proyek)
*/
include "../php-func/user.valid.php";
$year  = (int)($_GET['year'] ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
$pid   = (int)($_GET['project_id'] ?? 0);
if ($year < 2000 || $year > 2100) { $year = (int)date('Y'); }
if ($month < 1 || $month > 12)    { $month = (int)date('n'); }
$qs = http_build_query(['year' => $year, 'month' => $month, 'project_id' => $pid]);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<title>Laporan PPN <?php echo sprintf('%04d-%02d', $year, $month); ?></title>
<style>
    @page { size: A4 landscape; margin: 12mm; }
    body { font: 12px Arial, sans-serif; color: #000; margin: 0; padding: 12px 16px; }
    .toolbar { margin-bottom: 12px; }
    .toolbar button { padding: 6px 16px; font-weight: bold; cursor: pointer; }
    h1 { text-align: center; font-size: 18px; margin: 0 0 2px 0; }
    .sub { text-align: center; color: #444; margin-bottom: 14px; }
    h2 { font-size: 14px; margin: 18px 0 6px 0; border-bottom: 1px solid #000; padding-bottom: 2px; }
    table { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    th, td { border: 1px solid #888; padding: 3px 6px; }
    th { background: #e8e8e8; text-align: left; }
    td.r, th.r { text-align: right; }
    td.c { text-align: center; }
    tfoot td { font-weight: bold; background: #f3f3f3; }
    .net { margin: 8px 0; font-size: 14px; font-weight: bold; }
    .warn { color: #b36b00; }
    .muted { color: #666; font-style: italic; }
    @media print { .toolbar { display: none; } body { padding: 0; } h2 { page-break-after: avoid; } tr { page-break-inside: avoid; } }
</style>
</head>
<body>
<div class="toolbar"><button type="button" onclick="window.print()">Cetak / Simpan PDF</button></div>
<h1>LAPORAN PPN</h1>
<div class="sub" id="sub">Memuat data...</div>
<div id="out"></div>
<script type="text/javascript">
(function () {
    var CRD = '../php-crud/ppn.reportCRD.php';
    var QS = <?php echo json_encode($qs); ?>;
    var PID = <?php echo json_encode($pid); ?>;
    var MONTHS = ['Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember'];
    var PERIOD = MONTHS[<?php echo $month - 1; ?>] + ' <?php echo $year; ?>';

    function esc(v) { return String(v == null ? '' : v).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }
    function fmt(v) {
        var n = Math.round((parseFloat(v) || 0) * 100) / 100, neg = n < 0;
        var parts = Math.abs(n).toFixed(2).split('.');
        parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
        return (neg ? '-' : '') + parts.join('.');
    }
    function get(task) {
        return fetch(CRD + '?task=' + task + '&' + QS, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.message || 'Gagal memuat data.'); return j; });
    }
    function faktur(v) { return v ? esc(v) : '<span class="warn">belum diisi</span>'; }

    Promise.all([get('list_summary'), get('list_keluaran'), get('list_masukan'), get('list_projects')]).then(function (res) {
        var sum = res[0], kel = res[1].data, mas = res[2].data, prj = res[3].data, t = sum.totals || {};
        var pname = '(Semua proyek)';
        for (var i = 0; i < prj.length; i++) { if (String(prj[i].id) === String(PID)) pname = prj[i].name; }
        document.getElementById('sub').innerHTML = 'Masa Pajak: <b>' + esc(PERIOD) + '</b> &nbsp;|&nbsp; Proyek: <b>' + esc(pname) + '</b>';

        var h = '<h2>Ringkasan per Proyek</h2><table><thead><tr><th>Proyek</th><th class="r">DPP Keluaran</th><th class="r">PPN Keluaran</th>' +
                '<th class="r">DPP Masukan</th><th class="r">PPN Masukan</th><th class="r">Kurang(+) / Lebih(-) Bayar</th></tr></thead><tbody>';
        if (!sum.data.length) h += '<tr><td colspan="6" class="muted">Tidak ada transaksi ber-PPN pada periode ini.</td></tr>';
        sum.data.forEach(function (r) {
            h += '<tr><td>' + esc(r.project_name) + '</td><td class="r">' + fmt(r.keluaran_dpp) + '</td><td class="r">' + fmt(r.keluaran_ppn) +
                 '</td><td class="r">' + fmt(r.masukan_dpp) + '</td><td class="r">' + fmt(r.masukan_ppn) + '</td><td class="r">' + fmt(r.net_ppn) + '</td></tr>';
        });
        h += '</tbody><tfoot><tr><td>TOTAL</td><td class="r">' + fmt(t.keluaran_dpp) + '</td><td class="r">' + fmt(t.keluaran_ppn) + '</td><td class="r">' +
             fmt(t.masukan_dpp) + '</td><td class="r">' + fmt(t.masukan_ppn) + '</td><td class="r">' + fmt(t.net_ppn) + '</td></tr></tfoot></table>';
        var net = parseFloat(t.net_ppn) || 0;
        h += '<div class="net">' + (net > 0 ? 'PPN KURANG BAYAR' : (net < 0 ? 'PPN LEBIH BAYAR (dapat dikompensasi)' : 'PPN NIHIL')) + ': ' + fmt(Math.abs(net)) + '</div>';

        h += '<h2>Detail PPN Keluaran (Termin)</h2><table><thead><tr><th>Tgl Pajak</th><th>No. Invoice</th><th>Proyek</th><th>Customer</th><th>Termin</th>' +
             '<th>No. Faktur Pajak</th><th class="r">DPP</th><th class="r">Tarif</th><th class="r">PPN</th><th class="r">Total</th></tr></thead><tbody>';
        if (!kel.length) h += '<tr><td colspan="10" class="muted">Tidak ada data.</td></tr>';
        var sd = 0, sp = 0, st = 0;
        kel.forEach(function (r) {
            sd += r.dpp_amount; sp += r.ppn_amount; st += r.total_amount;
            h += '<tr><td class="c">' + esc(r.tax_date) + '</td><td>' + esc(r.inv_no) + '</td><td>' + esc(r.project_name) + '</td><td>' + esc(r.counterparty) +
                 '</td><td>' + esc(r.termin_name) + '</td><td>' + faktur(r.faktur_pajak_no) + '</td><td class="r">' + fmt(r.dpp_amount) + '</td><td class="r">' +
                 r.ppn_rate + '%</td><td class="r">' + fmt(r.ppn_amount) + '</td><td class="r">' + fmt(r.total_amount) + '</td></tr>';
        });
        h += '</tbody><tfoot><tr><td colspan="6">TOTAL</td><td class="r">' + fmt(sd) + '</td><td></td><td class="r">' + fmt(sp) + '</td><td class="r">' + fmt(st) + '</td></tr></tfoot></table>';

        h += '<h2>Detail PPN Masukan (Invoice Supplier)</h2><table><thead><tr><th>Tgl Pajak</th><th>No. Invoice</th><th>Inv. Supplier</th><th>Proyek</th><th>Supplier</th>' +
             '<th>NPWP</th><th>No. Faktur Pajak</th><th class="r">DPP</th><th class="r">Tarif</th><th class="r">PPN</th><th class="r">Total</th></tr></thead><tbody>';
        if (!mas.length) h += '<tr><td colspan="11" class="muted">Tidak ada data.</td></tr>';
        sd = 0; sp = 0; st = 0;
        mas.forEach(function (r) {
            sd += r.dpp_amount; sp += r.ppn_amount; st += r.total_amount;
            h += '<tr><td class="c">' + esc(r.tax_date) + '</td><td>' + esc(r.inv_no) + '</td><td>' + esc(r.supplier_invoice_no) + '</td><td>' + esc(r.project_name) +
                 '</td><td>' + esc(r.counterparty) + '</td><td>' + esc(r.npwp_no || '-') + '</td><td>' + faktur(r.faktur_pajak_no) + '</td><td class="r">' + fmt(r.dpp_amount) +
                 '</td><td class="r">' + r.ppn_rate + '%</td><td class="r">' + fmt(r.ppn_amount) + '</td><td class="r">' + fmt(r.total_amount) + '</td></tr>';
        });
        h += '</tbody><tfoot><tr><td colspan="7">TOTAL</td><td class="r">' + fmt(sd) + '</td><td></td><td class="r">' + fmt(sp) + '</td><td class="r">' + fmt(st) + '</td></tr></tfoot></table>';
        h += '<div class="muted">Dicetak: ' + new Date().toLocaleString('id-ID') + '</div>';
        document.getElementById('out').innerHTML = h;
    }).catch(function (e) {
        document.getElementById('sub').innerHTML = '<span class="warn">' + esc(e.message || e) + '</span>';
    });
})();
</script>
</body>
</html>