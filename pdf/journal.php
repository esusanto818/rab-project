<?php
/*
  Document      : pdf/journal.php   [PRINT][NEW]
  Description   : Cetak Journal Entry (header + baris debit/kredit).
  Parameter     : id         = cetak satu jurnal
                  period_id  = cetak semua jurnal satu periode
                  (tanpa parameter = semua jurnal, urut tanggal)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();

$id = intval($_GET['id'] ?? 0);
$periodId = intval($_GET['period_id'] ?? 0);
$where = []; $params = [];
$sub = 'Semua jurnal';
if ($id > 0) { $where[] = 'je.id = :id'; $params['id'] = $id; $sub = 'Satu jurnal'; }
if ($periodId > 0) {
    $p = pr_period($db, $periodId);
    $where[] = 'je.period_id = :pid'; $params['pid'] = $periodId;
    $sub = 'Periode ' . $p['period_name'] . ' (' . pr_date($p['start_date']) . ' s/d ' . pr_date($p['end_date']) . ')';
}

$st = $db->prepare("
    SELECT je.id, je.ref_no, je.journal_date, je.description, je.status::text AS status,
           je.reference_type::text AS reference_type, ap.period_name
      FROM journal_entries je
      LEFT JOIN accounting_periods ap ON ap.id = je.period_id
     " . (count($where) ? 'WHERE ' . implode(' AND ', $where) : '') . "
     ORDER BY je.journal_date ASC, je.id ASC");
$st->execute($params);
$heads = $st->fetchAll(PDO::FETCH_ASSOC);

$stL = $db->prepare("
    SELECT c.account_code, c.account_name, jl.debit, jl.credit, jl.description
      FROM journal_lines jl
      JOIN chart_of_accounts c ON c.id = jl.account_id
     WHERE jl.journal_id = :jid
     ORDER BY jl.id ASC");

pr_open('Journal Entry', 'JOURNAL ENTRY', [pr_e($sub)]);
$gD = 0.0; $gK = 0.0;
foreach ($heads as $h) {
    $stL->execute(['jid' => $h['id']]);
    $lines = $stL->fetchAll(PDO::FETCH_ASSOC);
    $sd = 0.0; $sk = 0.0;
    echo '<div class="jv"><div class="jvh"><span>' . pr_e($h['ref_no']) . ' &nbsp;|&nbsp; ' . pr_e(pr_date($h['journal_date'])) .
         ' &nbsp;|&nbsp; ' . pr_e($h['period_name'] ?: '-') . '</span><span>' . pr_e(strtoupper($h['status'])) . ' &middot; ' . pr_e($h['reference_type']) . '</span></div>' .
         '<div style="padding:3px 6px">' . pr_e($h['description']) . '</div>' .
         '<table class="rpt"><tr><th>Kode</th><th>Nama Akun</th><th>Keterangan</th><th class="r">Debit</th><th class="r">Kredit</th></tr>';
    $i = 0;
    foreach ($lines as $l) {
        $d = floatval($l['debit']); $k = floatval($l['credit']); $sd += $d; $sk += $k;
        echo '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td>' . pr_e($l['account_code']) . '</td><td>' . pr_e($l['account_name']) . '</td><td>' . pr_e($l['description']) .
             '</td><td class="r">' . ($d > 0.004 ? pr_n($d) : '') . '</td><td class="r">' . ($k > 0.004 ? pr_n($k) : '') . '</td></tr>';
    }
    echo '<tr class="tot"><td colspan="3">TOTAL</td><td class="r">' . pr_n($sd) . '</td><td class="r">' . pr_n($sk) . '</td></tr></table></div>';
    $gD += $sd; $gK += $sk;
}
if (!count($heads)) { echo '<p class="muted">Tidak ada jurnal.</p>'; }
else { echo '<div class="grand"><span>TOTAL ' . count($heads) . ' JURNAL (Debit / Kredit)</span><span>' . pr_n($gD) . ' / ' . pr_n($gK) . '</span></div>'; }
pr_close();