<?php
/*
  Document      : pdf/spk.php   [PRINT][NEW]
  Description   : Cetak SPK (Surat Perintah Kerja) satu dokumen: identitas SPK, daftar material (qty SPK, terpakai, sisa),
                  dan kolom tanda tangan. Dibuka dari tombol "Cetak SPK" di spk.js.
  Parameter     : id (wajib) = spk.id
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();

$id = intval($_GET['id'] ?? 0);
if ($id <= 0) { echo 'ID SPK wajib diisi.'; exit; }

$st = $db->prepare("SELECT s.*, p.name AS project_name, c.name AS client_name
                      FROM spk s JOIN projects p ON p.id = s.project_id LEFT JOIN clients c ON c.id = p.client_id
                     WHERE s.id = :id");
$st->execute(['id' => $id]);
$s = $st->fetch(PDO::FETCH_ASSOC);
if (!$s) { echo 'SPK tidak ditemukan.'; exit; }

$st = $db->prepare("SELECT si.material_id, m.material_code, m.material_name, COALESCE(NULLIF(si.unit, ''), m.unit, '') AS unit,
                           SUM(si.qty) AS qty, MAX(si.notes) AS notes
                      FROM spk_items si JOIN materials m ON m.id = si.material_id
                     WHERE si.spk_id = :id
                     GROUP BY si.material_id, m.material_code, m.material_name, COALESCE(NULLIF(si.unit, ''), m.unit, '')
                     ORDER BY m.material_name");
$st->execute(['id' => $id]);
$items = $st->fetchAll(PDO::FETCH_ASSOC);

$st = $db->prepare("SELECT i.material_id, SUM(i.qty_out) AS q
                      FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id
                     WHERE h.spk_id = :id AND h.status = 'posted' GROUP BY i.material_id");
$st->execute(['id' => $id]);
$used = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $used[(int)$r['material_id']] = (float)$r['q']; }

function q4($v) { return number_format((float)$v, 4, '.', ','); }

pr_open('SPK ' . $s['spk_no'], 'SURAT PERINTAH KERJA (SPK)',
    ['No. SPK: <b>' . pr_e($s['spk_no']) . '</b> &nbsp;|&nbsp; Tanggal: <b>' . pr_e(pr_date($s['spk_date'])) . '</b> &nbsp;|&nbsp; Status: <b>' . pr_e($s['status']) . '</b>']);
?>
<table class="rpt fx" style="margin-top:8px">
    <colgroup><col style="width:150px"><col></colgroup>
    <tr><td><b>Proyek</b></td><td><?php echo pr_e($s['project_name']); ?></td></tr>
    <tr class="z"><td><b>Klien</b></td><td><?php echo pr_e($s['client_name'] ?: '-'); ?></td></tr>
    <tr><td><b>Mandor / Pelaksana</b></td><td><?php echo pr_e($s['foreman'] ?: '-'); ?></td></tr>
    <tr class="z"><td><b>Uraian Pekerjaan</b></td><td><?php echo pr_e($s['description'] ?: '-'); ?></td></tr>
<?php if (!empty($s['remark'])) { ?>
    <tr><td><b>Catatan</b></td><td><?php echo pr_e($s['remark']); ?></td></tr>
<?php } ?>
</table>

<h3>MATERIAL YANG DIIZINKAN KELUAR DARI GUDANG</h3>
<table class="rpt fx">
    <colgroup><col style="width:36px"><col style="width:90px"><col><col style="width:60px"><col style="width:110px"><col style="width:110px"><col style="width:110px"><col style="width:140px"></colgroup>
    <tr><th>No</th><th>Kode</th><th>Material</th><th>Sat</th><th class="r">Qty SPK</th><th class="r">Terpakai</th><th class="r">Sisa</th><th>Catatan</th></tr>
<?php
$i = 0;
foreach ($items as $it) {
    $u = isset($used[(int)$it['material_id']]) ? $used[(int)$it['material_id']] : 0.0;
    $q = (float)$it['qty'];
    echo '<tr' . ($i % 2 ? ' class="z"' : '') . '><td>' . (++$i) . '</td><td>' . pr_e($it['material_code']) . '</td><td>' . pr_e($it['material_name']) . '</td><td>' . pr_e($it['unit']) .
         '</td><td class="r">' . q4($q) . '</td><td class="r">' . q4($u) . '</td><td class="r">' . q4(max(0, $q - $u)) . '</td><td>' . pr_e($it['notes']) . '</td></tr>';
}
if (!count($items)) echo '<tr><td colspan="8" class="muted">Tidak ada item.</td></tr>';
?>
</table>

<table class="fx" style="width:100%; margin-top:40px; border-collapse:collapse; text-align:center">
    <colgroup><col><col><col></colgroup>
    <tr><td>Dibuat oleh</td><td>Disetujui oleh</td><td>Diterima oleh (Mandor)</td></tr>
    <tr><td style="height:80px"></td><td></td><td></td></tr>
    <tr><td>( ........................ )</td><td>( ........................ )</td><td>( <?php echo pr_e($s['foreman'] ?: '........................'); ?> )</td></tr>
</table>
<?php pr_close(); ?>