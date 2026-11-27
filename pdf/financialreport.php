<?php
/*
  Document      : pdf/financialreport.php   [PRINT][NEW]
  Description   : Cetak Financial Report = Neraca (kumulatif s/d akhir periode) + Laba Rugi (kolom "Periode Ini" dan
                  "Tahun Berjalan (YTD)" = 1 Januari tahun periode s/d akhir periode).
                  Rumus = php-crud/financialreportCRD.php. Akun yang semua kolomnya nol disembunyikan.
  Parameter     : period_id (wajib)
*/
require_once __DIR__ . '/print.common.php';
$db = pr_boot();
$period = pr_period($db, intval($_GET['period_id'] ?? 0));
$s = $period['start_date']; $e = $period['end_date'];

// Neraca
$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type IN ('asset', 'bank', 'cash') THEN l.debit - l.credit
                        ELSE l.credit - l.debit END
               ELSE 0 END
           ), 0) AS balance
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date <= :e
     WHERE c.is_active = true AND c.account_type IN ('asset', 'bank', 'cash', 'liability', 'equity')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
$st->execute(['e' => $e]);
$A = []; $L = []; $Q = []; $tA = 0.0; $tL = 0.0; $tQ = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $b = floatval($r['balance']);
    if (abs($b) < 0.004) continue;
    $it = [$r['account_code'], $r['account_name'], $b];
    if (in_array($r['account_type'], ['asset', 'bank', 'cash'])) { $A[] = $it; $tA += $b; }
    elseif ($r['account_type'] === 'liability')                  { $L[] = $it; $tL += $b; }
    else                                                         { $Q[] = $it; $tQ += $b; }
}
// Laba/rugi kumulatif s/d akhir periode -> ekuitas
$st = $db->prepare("
    SELECT COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        ELSE -(l.debit - l.credit) END
               ELSE 0 END
           ), 0) AS ni
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date <= :e
     WHERE c.account_type IN ('revenue', 'expense')");
$st->execute(['e' => $e]);
$niCum = floatval($st->fetchColumn());
if (abs($niCum) > 0.004) { $Q[] = ['-', 'Laba (Rugi) Kumulatif s/d Periode Ini', $niCum]; }
$tQ += $niCum;
$balanced = abs($tA - ($tL + $tQ)) < 0.01;

// Laba Rugi periode
$st = $db->prepare("
    SELECT c.account_code, c.account_name, c.account_type,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL AND j.journal_date BETWEEN :s AND :e THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        ELSE l.debit - l.credit END
               ELSE 0 END
           ), 0) AS amount,
           COALESCE(SUM(
               CASE WHEN j.id IS NOT NULL THEN
                   CASE WHEN c.account_type = 'revenue' THEN l.credit - l.debit
                        ELSE l.debit - l.credit END
               ELSE 0 END
           ), 0) AS amount_cum
      FROM chart_of_accounts c
      LEFT JOIN journal_lines l ON l.account_id = c.id
      LEFT JOIN journal_entries j ON j.id = l.journal_id
           AND j.status IN ('posted', 'reversed') AND j.journal_date BETWEEN :ys AND :ce
           AND j.reference_type::text <> 'closing_entry'   -- [CLOSING] jurnal penutup tidak ikut dihitung di Laba Rugi
     WHERE c.is_active = true AND c.account_type IN ('revenue', 'expense')
     GROUP BY c.id, c.account_code, c.account_name, c.account_type
     ORDER BY c.account_code ASC");
// [YTD] awal tahun berjalan = 1 Januari tahun akhir periode (bila periode mulai sebelum itu, pakai awal periode)
$ytdStart = date('Y', strtotime($e)) . '-01-01';
if ($s < $ytdStart) { $ytdStart = $s; }
$st->execute(['s' => $s, 'e' => $e, 'ys' => $ytdStart, 'ce' => $e]);
$R = []; $X = []; $tR = 0.0; $tX = 0.0; $tRc = 0.0; $tXc = 0.0;
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
    $a = floatval($r['amount']); $c = floatval($r['amount_cum']);
    $show = (abs($a) > 0.004 || abs($c) > 0.004);
    if ($r['account_type'] === 'revenue') { $tR += $a; $tRc += $c; if ($show) $R[] = [$r['account_code'], $r['account_name'], $a, $c]; }
    else                                  { $tX += $a; $tXc += $c; if ($show) $X[] = [$r['account_code'], $r['account_name'], $a, $c]; }
}
$netP = $tR - $tX; $netC = $tRc - $tXc;

// [YTD] seksi laba rugi dua kolom nilai; lebar kolom tetap (colgroup) agar sejajar di semua seksi & baris laba bersih
define('FR_COLS', '<colgroup><col style="width:70px"><col><col style="width:150px"><col style="width:170px"></colgroup>');
function fr_is_section($title, $items, $totalLabel, $t, $tc) {
    $h = '<h3>' . pr_e($title) . '</h3><table class="rpt fx">' . FR_COLS .
         '<tr><th>Kode</th><th>Nama Akun</th><th class="r">Periode Ini</th><th class="r">Tahun Berjalan (YTD)</th></tr>';
    $i = 0;
    foreach ($items as $it) {
        $h .= '<tr' . ($i++ % 2 ? ' class="z"' : '') . '><td class="c">' . pr_e($it[0]) . '</td><td>' . pr_e($it[1]) . '</td><td class="r">' . pr_n($it[2]) . '</td><td class="r">' . pr_n($it[3]) . '</td></tr>';
    }
    if (!count($items)) { $h .= '<tr><td colspan="4" class="muted">Tidak ada saldo.</td></tr>'; }
    return $h . '<tr class="tot"><td colspan="2">' . pr_e($totalLabel) . '</td><td class="r">' . pr_n($t) . '</td><td class="r">' . pr_n($tc) . '</td></tr></table>';
}

pr_open('Financial Report - ' . $period['period_name'], 'FINANCIAL REPORT',
    ['Periode: ' . pr_e($period['period_name']) . ' (' . pr_e(pr_date($s)) . ' s/d ' . pr_e(pr_date($e)) . ')'],
    '[ NERACA: ' . ($balanced ? 'BALANCE' : 'TIDAK BALANCE (selisih ' . pr_n($tA - $tL - $tQ) . ')') . ' ]', $balanced);
?>
<h2 style="font-size:15px; margin:10px 0 0 0">NERACA (per <?php echo pr_e(pr_date($e)); ?>)</h2>
<?php
echo pr_section('ASET', $A, 'TOTAL ASET', $tA);
echo pr_section('LIABILITAS', $L, 'TOTAL LIABILITAS', $tL);
echo pr_section('EKUITAS', $Q, 'TOTAL EKUITAS', $tQ);
?>
<div class="grand"><span>TOTAL LIABILITAS &amp; EKUITAS</span><span><?php echo pr_n($tL + $tQ); ?></span></div>

<h2 style="font-size:15px; margin:22px 0 0 0">LAPORAN LABA RUGI (<?php echo pr_e(pr_date($s)); ?> s/d <?php echo pr_e(pr_date($e)); ?> | YTD: <?php echo pr_e(pr_date($ytdStart)); ?> s/d <?php echo pr_e(pr_date($e)); ?>)</h2>
<?php
echo fr_is_section('PENDAPATAN', $R, 'TOTAL PENDAPATAN', $tR, $tRc);
echo fr_is_section('BEBAN / BIAYA', $X, 'TOTAL BEBAN', $tX, $tXc);
?>
<!-- [YTD] Laba bersih sebagai tabel dengan kolom sama (Periode Ini | YTD) -->
<table class="rpt fx" style="margin-top:14px; border-top:2px solid #000; border-bottom:2px solid #000">
    <?php echo FR_COLS; ?>
    <tr style="font-size:14px; font-weight:bold">
        <td colspan="2"><?php echo $netP >= 0 ? 'LABA BERSIH' : 'RUGI BERSIH'; ?></td>
        <td class="r"><?php echo pr_n($netP); ?></td>
        <td class="r"><?php echo pr_n($netC); ?></td>
    </tr>
</table>
<?php pr_close(); ?>