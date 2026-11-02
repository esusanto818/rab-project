<?php
/**
 * project.analysis.php  --  MESIN ANALISIS PROYEK BERSAMA (dashboard & Pembayaran Termin memakai fungsi yang sama)
 * ============================================================
 * Dipakai oleh : php-crud/dashboardCRD.php (read_project_ai, read_project_health)
 *                php-crud/project_paymentCRD.php (analyze_project_variance, latest_project_variance)
 * Isi          : - dash_project_context()  : data proyek lengkap + temuan deterministik (aturan sistem)
 *                - dash_call_gemini()      : narasi AI (Gemini, GOOGLE_API_KEY seperti m_proyekCRD.php)
 *                - dash_run_analysis()     : konteks -> AI (atau fallback temuan sistem) -> SIMPAN ke ai_variance_logs
 *                - dash_card_level()       : status kartu (NORMAL / WASPADA / KRITIS) dari angka + hasil AI terakhir
 * Sumber kebenaran tunggal: tabel ai_variance_logs (baris terbaru per proyek). Setiap analisis baru / analisis ulang
 * menyimpan satu baris, sehingga kartu dashboard, panel AI dashboard, dan layar Pembayaran Termin selalu menampilkan hasil yang sama.
 * Migrasi      : sql/2026-11-01.ai_variance_unify.sql (kolom tambahan; tanpa migrasi masih jalan dengan kolom lama, tanpa cache).
 * ============================================================
 */
if (!defined('HEALTH_WARN_GAP')) define('HEALTH_WARN_GAP', 10);    // gap (pemakaian% - progres%) >= ini -> WASPADA
if (!defined('HEALTH_CRIT_GAP')) define('HEALTH_CRIT_GAP', 25);    // gap >= ini (atau biaya > budget) -> KRITIS
if (!defined('AR_OS')) define('AR_OS', "(pinv.amount - GREATEST(pinv.paid_amount, COALESCE((SELECT SUM(x.amount_paid) FROM project_payment_items x WHERE x.invoice_id = pinv.id), 0)))");
if (!defined('AI_LOG_TTL_HOURS')) define('AI_LOG_TTL_HOURS', 12);        // hasil AI yang sama dipakai ulang selama ini bila data tidak berubah
if (!defined('AI_CARD_VALID_DAYS')) define('AI_CARD_VALID_DAYS', 14);    // hasil AI dipakai untuk status kartu selama ini

/** Cek tabel ada (modul baru mungkin belum dimigrasi). */
function tbl_exists($db, string $name): bool {
    try {
        $st = $db->prepare("SELECT to_regclass(:n) IS NOT NULL");
        $st->execute([':n' => 'public.' . $name]);
        return (bool)$st->fetchColumn();
    } catch (Exception $e) { return false; }
}

// ============================================================
// [AI] Rekomendasi proyek: konteks data lengkap -> temuan deterministik -> narasi AI (Gemini, sama seperti m_proyekCRD.php)
//   Temuan (findings) SELALU dihitung oleh sistem dari data, lalu dikirim ke AI sebagai daftar yang wajib dibahas, sehingga
//   tidak ada indikator yang terlewat. Bila AI tidak tersedia, narasi disusun dari temuan yang sama (ai_used = false).
// ============================================================
if (!function_exists('dash_call_gemini')) {
    function dash_call_gemini(string $prompt, int $timeout = 45): string {
        $apiKey = getenv('GOOGLE_API_KEY');
        if (!$apiKey) throw new Exception('GOOGLE_API_KEY belum diset di environment server.');
        $candidateModels = ['gemini-3.6-flash', 'gemini-3.5-flash-lite'];     // sama dengan mpCallGemini()
        $response = null; $httpCode = 0; $lastErrorMsg = '';
        foreach ($candidateModels as $model) {
            $apiUrl = "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent";
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                $ch = curl_init($apiUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['responseMimeType' => 'application/json', 'temperature' => 0.3]
                ]));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey]);
                curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
                $response = curl_exec($ch);
                $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);
                if ($httpCode === 200) break 2;
                $lastErrorMsg = "Model {$model} (HTTP {$httpCode})";
                if ($httpCode === 503 || $httpCode === 429) sleep(1); else break;
            }
        }
        if ($httpCode !== 200) throw new Exception("Gagal terhubung ke Gemini API: {$lastErrorMsg}");
        $apiResult = json_decode($response, true);
        $raw = $apiResult['candidates'][0]['content']['parts'][0]['text'] ?? '';
        return trim(str_replace(['```json', '```'], '', $raw));
    }
}

/** Kumpulkan seluruh data proyek yang relevan + temuan deterministik. */
function dash_project_context($db, int $pid): ?array {
    $q = function (string $sql, array $p = []) use ($db) { $st = $db->prepare($sql); $st->execute($p); return $st; };
    $proj = $q("SELECT id, name, status, start_date, end_date, contract_value, COALESCE(ppn_rate,0) AS ppn_rate, COALESCE(ppn_inclusive,false) AS ppn_inclusive,
                       COALESCE(progress,0) AS progress,
                       COALESCE(NULLIF(budget * COALESCE(NULLIF(budget_exch_rate, 0), 1), 0), (SELECT SUM(b.qty_plan*b.unit_price_plan) FROM budget_items b WHERE b.project_id = projects.id), 0) AS budget
                  FROM projects WHERE id = :p", [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
    if (!$proj) return null;
    $progress = (int)$proj['progress']; $budget = (float)$proj['budget'];
    $cv = (float)$proj['contract_value'];
    $inclusive = ($proj['ppn_inclusive'] === true || $proj['ppn_inclusive'] === 't');
    $contractDpp = ($inclusive && (float)$proj['ppn_rate'] > 0) ? $cv / (1 + (float)$proj['ppn_rate'] / 100) : $cv;

    $issueWhere = "h.project_id = :p AND h.status = 'posted' AND h.reversed_at IS NULL AND COALESCE(h.issue_type,'PROJECT') = 'PROJECT'";
    $cm = (float)$q("SELECT COALESCE(SUM(i.qty_out*i.unit_cost),0) FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id WHERE {$issueWhere}", [':p' => $pid])->fetchColumn();
    $ce = (float)$q("SELECT COALESCE(SUM(amount),0) FROM financial_expenses WHERE project_id = :p AND COALESCE(UPPER(status),'') NOT IN ('VOID','CANCELLED','CANCELED','BATAL')", [':p' => $pid])->fetchColumn();
    $hasSub = tbl_exists($db, 'subcon_progress') && tbl_exists($db, 'subcon_contracts');
    $sub = ['kontrak_aktif' => 0, 'nilai_kontrak' => 0.0, 'tertagih_dpp' => 0.0, 'sisa_hutang' => 0.0];
    if ($hasSub) {
        $r = $q("SELECT (SELECT COUNT(*) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS n,
                        (SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE project_id = :p AND status IN ('Disetujui','Selesai')) AS v,
                        (SELECT COALESCE(SUM(sp.gross_amount),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui') AS g,
                        (SELECT COALESCE(SUM(sp.net_payable),0) FROM subcon_progress sp JOIN subcon_contracts sc ON sc.id = sp.contract_id WHERE sc.project_id = :p AND sp.status = 'Disetujui')
                        - (SELECT COALESCE(SUM(y.amount),0) FROM subcon_payments y JOIN subcon_contracts sc ON sc.id = y.contract_id WHERE sc.project_id = :p AND y.status = 'Posted' AND y.pay_type = 'PROGRESS') AS h",
                  [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        $sub = ['kontrak_aktif' => (int)$r['n'], 'nilai_kontrak' => round((float)$r['v'], 2), 'tertagih_dpp' => round((float)$r['g'], 2), 'sisa_hutang' => round((float)$r['h'], 2)];
    }
    $cs = $sub['tertagih_dpp'];
    $cost = $cm + $ce + $cs;
    $usage = $budget > 0 ? $cost / $budget * 100 : null;
    $gap = $usage !== null ? $usage - $progress : null;

    // waktu terpakai
    $elapsed = null; $daysToEnd = null;
    if ($proj['start_date'] && $proj['end_date']) {
        $sd = new DateTime($proj['start_date']); $ed = new DateTime($proj['end_date']); $today = new DateTime('today');
        $span = (int)$sd->diff($ed)->format('%r%a');
        if ($span > 0) $elapsed = max(0, min(100, ((int)$sd->diff($today)->format('%r%a')) / $span * 100));
        $daysToEnd = (int)$today->diff($ed)->format('%r%a');
    }

    // RAB material: yang paling melampaui (termasuk material di luar RAB)
    $rab = $q("SELECT COALESCE(m.material_name, '(material #' || COALESCE(bx.material_id, ox.material_id) || ')') AS material,
                      COALESCE(bx.plan,0) AS plan_qty, COALESCE(bx.tol,0) AS tol, COALESCE(ox.q,0) AS out_qty,
                      COALESCE(bx.plan_value,0) AS plan_value, COALESCE(ox.v,0) AS out_value, (bx.material_id IS NULL) AS no_rab
                 FROM (SELECT b.material_id, SUM(b.qty_plan) AS plan, MAX(COALESCE(b.tolerance_pct,0)) AS tol, SUM(b.qty_plan*b.unit_price_plan) AS plan_value
                         FROM budget_items b WHERE b.project_id = :p AND b.material_id IS NOT NULL GROUP BY b.material_id) bx
                 FULL OUTER JOIN (SELECT i.material_id, SUM(i.qty_out) AS q, SUM(i.qty_out*i.unit_cost) AS v
                                    FROM material_out_items i JOIN material_out_header h ON h.id = i.material_out_id
                                   WHERE {$issueWhere} GROUP BY i.material_id) ox ON ox.material_id = bx.material_id
                 LEFT JOIN materials m ON m.id = COALESCE(bx.material_id, ox.material_id)
                WHERE COALESCE(ox.q,0) > 0
                ORDER BY (COALESCE(ox.v,0) - COALESCE(bx.plan_value,0)) DESC LIMIT 8", [':p' => $pid])->fetchAll(PDO::FETCH_ASSOC);
    $overLines = []; $noRabLines = [];
    foreach ($rab as &$r) {
        $r['plan_qty'] = (float)$r['plan_qty']; $r['out_qty'] = (float)$r['out_qty']; $r['plan_value'] = round((float)$r['plan_value'], 2); $r['out_value'] = round((float)$r['out_value'], 2);
        $r['no_rab'] = ($r['no_rab'] === true || $r['no_rab'] === 't');
        $r['used_pct'] = $r['plan_qty'] > 0 ? round($r['out_qty'] / $r['plan_qty'] * 100, 1) : null;
        if ($r['no_rab']) $noRabLines[] = $r;
        elseif ($r['out_qty'] > $r['plan_qty'] * (1 + (float)$r['tol'] / 100) + 0.0005) $overLines[] = $r;
        unset($r['tol']);
    }
    unset($r);
    $lastIssue = $q("SELECT MAX(h.issue_date) FROM material_out_header h WHERE {$issueWhere}", [':p' => $pid])->fetchColumn();
    $daysSinceIssue = $lastIssue ? (int)(new DateTime($lastIssue))->diff(new DateTime('today'))->format('%r%a') : null;

    // penagihan & piutang
    $bill = $q("SELECT COALESCE(SUM(COALESCE(v.dpp_amount, v.amount)),0) AS billed_dpp,
                       COALESCE(SUM(" . str_replace('pinv.', 'v.', AR_OS) . "),0) AS ar_outstanding,
                       COALESCE(SUM(CASE WHEN v.due_date < CURRENT_DATE AND " . str_replace('pinv.', 'v.', AR_OS) . " > 0.005 THEN " . str_replace('pinv.', 'v.', AR_OS) . " END),0) AS ar_overdue,
                       COUNT(CASE WHEN v.due_date < CURRENT_DATE AND " . str_replace('pinv.', 'v.', AR_OS) . " > 0.005 THEN 1 END) AS overdue_cnt,
                       COUNT(*) AS inv_cnt
                  FROM project_invoices v WHERE v.project_id = :p", [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
    $billed = (float)$bill['billed_dpp'];
    $collected = (float)$q("SELECT COALESCE(SUM(ppi.amount_paid),0) FROM project_payment_items ppi JOIN project_invoices v ON v.id = ppi.invoice_id WHERE v.project_id = :p", [':p' => $pid])->fetchColumn();
    $billedPct = $contractDpp > 0 ? $billed / $contractDpp * 100 : null;
    $margin = $billed > 0 ? ($billed - $cost) / $billed * 100 : null;

    // task
    $taskTotal = 0; $taskDone = 0; $lateTasks = [];
    if (tbl_exists($db, 'project_tasks')) {
        $r = $q("SELECT COUNT(*) AS n, COUNT(CASE WHEN COALESCE(status,'') IN ('Selesai','Closed','Done') THEN 1 END) AS d FROM project_tasks WHERE project_id = :p", [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
        $taskTotal = (int)$r['n']; $taskDone = (int)$r['d'];
        $lateTasks = $q("SELECT task_name, end_date, (CURRENT_DATE - end_date) AS days_late FROM project_tasks
                          WHERE project_id = :p AND end_date < CURRENT_DATE AND COALESCE(status,'') NOT IN ('Selesai','Closed','Done') ORDER BY end_date ASC LIMIT 5", [':p' => $pid])->fetchAll(PDO::FETCH_ASSOC);
        $lateCount = (int)$q("SELECT COUNT(*) FROM project_tasks WHERE project_id = :p AND end_date < CURRENT_DATE AND COALESCE(status,'') NOT IN ('Selesai','Closed','Done')", [':p' => $pid])->fetchColumn();
    } else { $lateCount = 0; }
    foreach ($lateTasks as &$t) $t['days_late'] = (int)$t['days_late'];
    unset($t);

    // pembelian
    $po = $q("SELECT COUNT(CASE WHEN status='Draft' THEN 1 END) AS draft, COUNT(CASE WHEN status='Diajukan' THEN 1 END) AS diajukan, COUNT(CASE WHEN status='Disetujui' THEN 1 END) AS disetujui
                FROM purchase_orders WHERE project_id = :p", [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
    $grnUninv = (int)$q("SELECT COUNT(*) FROM grn_hdr g JOIN purchase_orders po ON po.id = g.po_id WHERE po.project_id = :p
                           AND COALESCE(LOWER(g.status),'') NOT IN ('cancelled','canceled','dibatalkan','void','reversed')
                           AND NOT EXISTS (SELECT 1 FROM purchase_invoice_dtl d WHERE d.grn_hdr_id = g.id)", [':p' => $pid])->fetchColumn();
    $returOpen = tbl_exists($db, 'supplier_return_hdr') ? (int)$q("SELECT COUNT(*) FROM supplier_return_hdr WHERE project_id = :p AND status = 'Dikirim'", [':p' => $pid])->fetchColumn() : 0;
    $opnameLoss = ['jumlah' => 0, 'nilai_kurang' => 0.0];
    if (tbl_exists($db, 'stock_opname_hdr')) {
        try {       // bagian opsional: kegagalan di sini tidak boleh menggagalkan seluruh analisis
            $r = $q("SELECT COUNT(*) AS n, COALESCE(SUM(o.total_loss),0) AS l FROM stock_opname_hdr o JOIN warehouses w ON w.id = o.warehouse_id
                      WHERE w.project_id = :p AND o.status = 'Disetujui'", [':p' => $pid])->fetch(PDO::FETCH_ASSOC);
            $opnameLoss = ['jumlah' => (int)$r['n'], 'nilai_kurang' => round((float)$r['l'], 2)];
        } catch (Exception $e) { error_log('[dashboardCRD] ctx opname: ' . $e->getMessage()); }
    }

    // ------------------------------------------------------------ temuan deterministik
    $F = [];
    $add = function (string $code, string $sev, string $title, string $evidence, string $action) use (&$F) { $F[] = compact('code', 'sev', 'title', 'evidence', 'action'); };
    $rp = function ($v) { return 'Rp ' . number_format((float)$v, 0, ',', '.'); };
    $pc = function ($v) { return rtrim(rtrim(number_format((float)$v, 1, ',', '.'), '0'), ','); };

    if ($budget <= 0) $add('BUDGET_EMPTY', 'medium', 'Budget proyek belum diisi', 'Budget proyek dan RAB bernilai 0, sehingga pemakaian biaya tidak dapat diukur.', 'Isi budget di Master Proyek atau lengkapi RAB.');
    if ($usage !== null && $usage > 100) $add('BUDGET_OVER', 'high', 'Biaya melampaui budget', "Biaya riil {$rp($cost)} = {$pc($usage)}% dari budget {$rp($budget)}.", 'Tahan pengeluaran baru; ajukan revisi RAB atau addendum kontrak.');
    if ($gap !== null && $gap >= HEALTH_WARN_GAP) {
        $dom = $cost > 0 ? ($cm / $cost >= 0.5 ? 'material' : ($cs / $cost >= 0.5 ? 'prestasi subkon' : 'pengeluaran non-material')) : '-';
        $add('COST_AHEAD', $gap >= HEALTH_CRIT_GAP ? 'high' : 'medium', 'Pemakaian biaya jauh di depan progres',
             "Pemakaian {$pc($usage)}% vs progres {$progress}% (gap +" . round($gap) . "); biaya didominasi {$dom} (material {$rp($cm)}, pengeluaran {$rp($ce)}, subkon {$rp($cs)}).",
             $dom === 'material' ? 'Lakukan stock opname lapangan dan cocokkan issue material dengan progres fisik.' : ($dom === 'prestasi subkon' ? 'Verifikasi prestasi subkon dengan berita acara progres lapangan.' : 'Tinjau bukti dan alokasi pengeluaran proyek.'));
    }
    if ($gap !== null && $gap <= -25 && $progress > 0) $add('COST_UNRECORDED', 'medium', 'Biaya kemungkinan belum tercatat',
             "Pemakaian hanya {$pc($usage)}% sementara progres {$progress}% (gap " . round($gap) . "). Ini bukan otomatis efisiensi: material belum di-issue, tagihan supplier/subkon belum diinput, atau progres terlalu tinggi.",
             'Cocokkan dengan pengeluaran lapangan: issue material, prestasi subkon, invoice supplier, dan pengeluaran yang belum dicatat; verifikasi angka progres.');
    if ($progress === 0 && $elapsed !== null && $elapsed >= 20) $add('PROGRESS_NOT_UPDATED', 'medium', 'Progres belum diperbarui',
             "Progres tercatat 0% padahal waktu terpakai {$pc($elapsed)}%. Gap dan status kesehatan tidak dapat dipercaya sampai progres diisi.", 'Perbarui progres lapangan lewat modul Project Progress.');
    if (count($overLines)) { $n = $overLines[0];
        $add('MATERIAL_OVER_RAB', 'high', count($overLines) . ' material melebihi batas RAB', 'Terbesar: ' . $n['material'] . " keluar {$n['out_qty']} dari rencana {$n['plan_qty']} ({$pc($n['used_pct'])}%).", 'Selidiki pemakaian berlebih (pemborosan, kehilangan, salah catat) dan lakukan stock opname material tersebut.'); }
    if (count($noRabLines)) $add('MATERIAL_NO_RAB', 'medium', count($noRabLines) . ' material keluar tanpa baris RAB', 'Contoh: ' . $noRabLines[0]['material'] . ' senilai ' . $rp($noRabLines[0]['out_value']) . '.', 'Pastikan material tersebut sah untuk proyek ini; tambahkan ke RAB atau koreksi issue.');
    if ($hasSub && $sub['nilai_kontrak'] > 0 && $contractDpp > 0) {
        $subPct = $sub['tertagih_dpp'] / $sub['nilai_kontrak'] * 100;
        if ($subPct - $progress >= 15) $add('SUBCON_AHEAD', 'medium', 'Prestasi subkon mendahului progres proyek', "Prestasi subkon disetujui {$pc($subPct)}% dari nilai SPK sedangkan progres proyek {$progress}%.", 'Verifikasi berita acara prestasi sebelum prestasi berikutnya disetujui.');
    }
    if ($billedPct !== null && $contractDpp > 0 && $progress - $billedPct >= 15 && $billed < $contractDpp) $add('BILLING_LAG', 'medium', 'Penagihan tertinggal dari progres', "Tertagih {$pc($billedPct)}% dari kontrak sedangkan progres {$progress}%.", 'Terbitkan termin berikutnya agar arus kas tidak tertekan.');
    if ((float)$bill['ar_overdue'] > 0.005) $add('AR_OVERDUE', 'high', 'Piutang klien jatuh tempo', "{$bill['overdue_cnt']} tagihan senilai {$rp($bill['ar_overdue'])} sudah lewat jatuh tempo.", 'Lakukan penagihan segera dan eskalasi ke klien.');
    if ($elapsed !== null && $progress > 0 && $elapsed - $progress >= 15) $add('SCHEDULE_LAG', 'medium', 'Progres tertinggal dari jadwal', "Waktu terpakai {$pc($elapsed)}% vs progres {$progress}%" . ($lateCount ? "; {$lateCount} task terlambat" : '') . '.', 'Tinjau jadwal (S-curve) dan tambah sumber daya pada task kritis.');
    if ($daysToEnd !== null && $daysToEnd < 0 && $progress < 100) $add('PAST_DEADLINE', 'high', 'Melewati tanggal selesai', 'Tanggal selesai terlewat ' . abs($daysToEnd) . " hari, progres {$progress}%.", 'Tetapkan jadwal baru atau addendum waktu dan kendalikan denda keterlambatan.');
    if ($lateCount > 0 && !count(array_filter($F, function ($f) { return $f['code'] === 'SCHEDULE_LAG'; }))) $add('TASKS_LATE', 'medium', "{$lateCount} task terlambat", 'Terlama: ' . ($lateTasks[0]['task_name'] ?? '-') . ' (' . ($lateTasks[0]['days_late'] ?? 0) . ' hari).', 'Perbarui status task atau susun rencana pemulihan.');
    if ($margin !== null && $margin < 10) $add('MARGIN_LOW', $margin < 0 ? 'high' : 'medium', 'Margin berjalan rendah', "Margin {$pc($margin)}% (tertagih {$rp($billed)} - biaya {$rp($cost)}).", 'Tinjau biaya terhadap tagihan dan pertimbangkan klaim variation order.');
    if ($returOpen > 0) $add('RETUR_OPEN', 'low', "{$returOpen} retur supplier belum diselesaikan", 'Retur sudah dikirim tetapi belum diganti / dikreditkan.', 'Selesaikan retur (ganti barang atau nota kredit).');
    if ($grnUninv > 0) $add('GRN_UNINVOICED', 'low', "{$grnUninv} GRN belum di-invoice", 'Barang sudah diterima tetapi tagihan supplier belum dicatat.', 'Catat invoice supplier agar hutang dan PPN masukan tercatat.');
    if ($opnameLoss['nilai_kurang'] > 0) $add('OPNAME_LOSS', 'medium', 'Opname gudang proyek mencatat selisih kurang', "{$opnameLoss['jumlah']} opname disetujui dengan nilai kurang {$rp($opnameLoss['nilai_kurang'])}.", 'Telusuri penyebab selisih dan perketat kontrol penerimaan / pengeluaran gudang.');
    if ($progress > 0 && $daysSinceIssue !== null && $daysSinceIssue > 30) $add('NO_RECENT_ISSUE', 'low', 'Tidak ada pengeluaran material baru', "Issue material terakhir {$daysSinceIssue} hari lalu padahal proyek berjalan.", 'Pastikan pemakaian material lapangan dicatat tepat waktu.');

    $rank = ['high' => 0, 'medium' => 1, 'low' => 2];
    usort($F, function ($a, $b) use ($rank) { return $rank[$a['sev']] <=> $rank[$b['sev']]; });
    $risk = 'LOW';
    foreach ($F as $f) { if ($f['sev'] === 'high') { $risk = 'HIGH'; break; } if ($f['sev'] === 'medium') $risk = 'MEDIUM'; }

    return [
        'risk_rule' => $risk, 'findings' => $F,
        'metrics' => ['progress' => $progress, 'usage_pct' => $usage === null ? null : round($usage, 1), 'gap' => $gap === null ? null : round($gap, 1),
                      'budget' => round($budget, 2), 'cost_total' => round($cost, 2), 'cost_material' => round($cm, 2), 'cost_expense' => round($ce, 2), 'cost_subcon' => round($cs, 2),
                      'elapsed_pct' => $elapsed === null ? null : round($elapsed, 1), 'margin_pct' => $margin === null ? null : round($margin, 1)],
        'context' => [
            'proyek' => ['nama' => $proj['name'], 'status' => $proj['status'], 'mulai' => $proj['start_date'], 'selesai' => $proj['end_date'], 'nilai_kontrak_dpp' => round($contractDpp, 2), 'progres_pct' => $progress, 'waktu_terpakai_pct' => $elapsed === null ? null : round($elapsed, 1), 'sisa_hari' => $daysToEnd],
            'biaya' => ['budget' => round($budget, 2), 'total' => round($cost, 2), 'material' => round($cm, 2), 'pengeluaran' => round($ce, 2), 'subkon' => round($cs, 2), 'pemakaian_pct' => $usage === null ? null : round($usage, 1)],
            'material_teratas' => $rab, 'material_terakhir_keluar_hari_lalu' => $daysSinceIssue,
            'subkon' => $sub,
            'penagihan' => ['tertagih_dpp' => round($billed, 2), 'tertagih_pct_kontrak' => $billedPct === null ? null : round($billedPct, 1), 'terbayar_klien' => round($collected, 2),
                            'piutang' => round((float)$bill['ar_outstanding'], 2), 'piutang_jatuh_tempo' => round((float)$bill['ar_overdue'], 2), 'margin_pct' => $margin === null ? null : round($margin, 1)],
            'task' => ['total' => $taskTotal, 'selesai' => $taskDone, 'terlambat' => $lateCount, 'terlambat_teratas' => $lateTasks],
            'pembelian' => ['po_draft' => (int)$po['draft'], 'po_diajukan' => (int)$po['diajukan'], 'po_disetujui' => (int)$po['disetujui'], 'grn_belum_diinvoice' => $grnUninv, 'retur_belum_selesai' => $returOpen],
            'opname_gudang_proyek' => $opnameLoss,
        ],
    ];
}


/** Kolom tambahan ai_variance_logs sudah dimigrasi? */
function avl_has_cols($db): bool {
    static $has = null;
    if ($has === null) {
        try {
            $st = $db->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'ai_variance_logs'
                                AND column_name IN ('analysis', 'source', 'ai_used', 'headline', 'risk_rule')");
            $has = ((int)$st->fetchColumn() === 5);
        } catch (Exception $e) { $has = false; }
    }
    return $has;
}

/**
 * Jalankan analisis satu proyek dan SIMPAN hasilnya ke ai_variance_logs.
 * $refresh=false : bila ada hasil AI untuk data yang sama dalam AI_LOG_TTL_HOURS jam, kembalikan hasil itu (tanpa panggil AI, tanpa baris baru).
 * $refresh=true  : selalu analisis ulang (panggil AI) dan simpan baris baru.
 */
function dash_run_analysis($db, int $pid, bool $refresh, string $source, ?int $userId): array {
    @set_time_limit(90);
    $ctx = dash_project_context($db, $pid);
    if (!$ctx) throw new Exception('Proyek tidak ditemukan.');
    $hasCols = avl_has_cols($db);
    $hash = md5(json_encode([$ctx['context'], $ctx['findings']], JSON_UNESCAPED_UNICODE) . date('Y-m-d'));

    if ($hasCols && !$refresh) {
        $st = $db->prepare("SELECT id, analysis, created_at FROM ai_variance_logs
                             WHERE project_id = :p AND ai_used = true AND analysis->>'ctx_hash' = :h AND created_at > NOW() - (:ttl || ' hours')::interval
                             ORDER BY created_at DESC, id DESC LIMIT 1");
        $st->execute([':p' => $pid, ':h' => $hash, ':ttl' => (string)AI_LOG_TTL_HOURS]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $res = json_decode($row['analysis'], true);
            if (is_array($res)) { $res['cached'] = true; $res['generated_at'] = $row['created_at']; $res['log_id'] = (int)$row['id']; return $res; }
        }
    }

    $F = $ctx['findings']; $risk = $ctx['risk_rule']; $name = $ctx['context']['proyek']['nama'];
    $aiUsed = false; $aiNote = ''; $headline = ''; $summary = ''; $actions = []; $questions = [];
    try {
        $prompt = "Anda adalah Project Controller senior untuk perusahaan kontraktor di Indonesia. Analisis kesehatan satu proyek berdasarkan DATA berikut dan beri rekomendasi.\n" .
            "DATA PROYEK (JSON): " . json_encode($ctx['context'], JSON_UNESCAPED_UNICODE) . "\n" .
            "TEMUAN SISTEM (WAJIB dibahas semuanya, jangan ada yang terlewat): " . json_encode($F, JSON_UNESCAPED_UNICODE) . "\n" .
            "ATURAN: (1) Bahasa Indonesia, spesifik, sebut angka dari data; jangan mengarang data yang tidak ada. " .
            "(2) Jelaskan hubungan antar temuan (mis. biaya di depan progres + material melebihi RAB = indikasi kebocoran material) dan beri hipotesis penyebab sebagai INDIKASI, bukan kepastian. " .
            "(3) Bila pemakaian biaya jauh DI BAWAH progres, jangan menyebutnya efisiensi tanpa syarat: sebutkan kemungkinan biaya belum tercatat atau progres terlalu tinggi. " .
            "(4) actions: 4-6 tindakan konkret berurutan prioritas yang bisa dilakukan di sistem ERP ini (stock opname, verifikasi prestasi subkon, terbitkan termin, update progres, revisi RAB, selesaikan retur, catat invoice supplier, dan sejenisnya). " .
            "(5) risk hanya salah satu dari LOW, MEDIUM, HIGH; risiko minimal sama dengan hasil sistem: {$risk}. " .
            "(6) questions: 0-3 hal yang perlu dikonfirmasi manusia karena data tidak cukup.\n" .
            "Jawab JSON MURNI: {\"risk\":\"LOW|MEDIUM|HIGH\",\"headline\":\"satu kalimat inti\",\"summary\":\"satu paragraf 3-5 kalimat\",\"actions\":[\"...\"],\"questions\":[\"...\"]}";
        $ai = json_decode(dash_call_gemini($prompt), true);
        if (is_array($ai) && !empty($ai['summary']) && !empty($ai['actions']) && is_array($ai['actions'])) {
            $order = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2];
            $aiRisk = strtoupper((string)($ai['risk'] ?? 'LOW'));
            if ($aiRisk === 'CRITICAL') $aiRisk = 'HIGH';
            if (isset($order[$aiRisk]) && $order[$aiRisk] > $order[$risk]) $risk = $aiRisk;        // AI boleh menaikkan risiko, tidak menurunkan di bawah hasil aturan
            $headline = trim((string)($ai['headline'] ?? ''));
            $summary = trim((string)$ai['summary']);
            $actions = array_slice(array_values(array_filter(array_map('trim', array_map('strval', $ai['actions'])))), 0, 6);
            $questions = array_slice(array_values(array_filter(array_map('trim', array_map('strval', (array)($ai['questions'] ?? []))))), 0, 3);
            $aiUsed = true;
        } else { $aiNote = 'Respons AI tidak sesuai format; ditampilkan analisis sistem.'; }
    } catch (Exception $e) {
        error_log('[project.analysis] gemini: ' . $e->getMessage());
        $aiNote = 'AI tidak tersedia (' . $e->getMessage() . '); ditampilkan analisis sistem.';
    }
    if (!$aiUsed) {
        $headline = count($F) ? "{$name}: " . $F[0]['title'] : "{$name}: tidak ada temuan yang perlu ditindaklanjuti.";
        $parts = []; foreach (array_slice($F, 0, 4) as $f) $parts[] = $f['evidence'];
        $summary = count($F) ? implode(' ', $parts) : 'Seluruh indikator (biaya, progres, jadwal, penagihan, pembelian) berada dalam batas normal.';
        $seen = [];
        foreach ($F as $f) { if (!isset($seen[$f['action']])) { $seen[$f['action']] = true; $actions[] = $f['action']; } }
        $actions = array_slice($actions, 0, 6);
    }

    $m = $ctx['metrics'];
    $result = ['project_id' => $pid, 'project_name' => $name, 'risk' => $risk, 'risk_rule' => $ctx['risk_rule'], 'headline' => $headline, 'summary' => $summary,
               'actions' => $actions, 'questions' => $questions, 'findings' => $F, 'metrics' => $m, 'ai_used' => $aiUsed, 'ai_note' => $aiNote,
               'cached' => false, 'ctx_hash' => $hash, 'source' => $source, 'generated_at' => date('Y-m-d H:i:s')];

    // simpan ke ai_variance_logs (setiap analisis baru / ulang)  -> dibaca kartu dashboard & layar Pembayaran Termin
    $budget = (float)$m['budget']; $cost = (float)$m['cost_total'];
    $varAmt = $cost - $budget; $varPct = $budget > 0 ? $varAmt / $budget * 100 : 0;
    try {
        if ($hasCols) {
            $st = $db->prepare("INSERT INTO ai_variance_logs
                    (project_id, total_budget, total_actual, variance_amount, variance_percentage, risk_level, ai_summary, recommendations, created_by,
                     risk_rule, headline, analysis, source, ai_used)
                VALUES (:pid, :tb, :ta, :va, :vp, :rl, :sum, CAST(:rec AS jsonb), :uid, :rr, :hl, CAST(:an AS jsonb), :src, :used)
                RETURNING id, created_at");
            $st->execute([':pid' => $pid, ':tb' => $budget, ':ta' => $cost, ':va' => $varAmt, ':vp' => $varPct, ':rl' => $risk, ':sum' => $summary,
                          ':rec' => json_encode($actions, JSON_UNESCAPED_UNICODE), ':uid' => $userId ?: null, ':rr' => $ctx['risk_rule'], ':hl' => $headline,
                          ':an' => json_encode($result, JSON_UNESCAPED_UNICODE), ':src' => $source, ':used' => $aiUsed ? 't' : 'f']);
        } else {      // kolom lama saja (migrasi belum dijalankan)
            $st = $db->prepare("INSERT INTO ai_variance_logs (project_id, total_budget, total_actual, variance_amount, variance_percentage, risk_level, ai_summary, recommendations, created_by)
                VALUES (:pid, :tb, :ta, :va, :vp, :rl, :sum, CAST(:rec AS jsonb), :uid) RETURNING id, created_at");
            $st->execute([':pid' => $pid, ':tb' => $budget, ':ta' => $cost, ':va' => $varAmt, ':vp' => $varPct, ':rl' => $risk, ':sum' => $summary,
                          ':rec' => json_encode($actions, JSON_UNESCAPED_UNICODE), ':uid' => $userId ?: null]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);
        $result['log_id'] = (int)$row['id']; $result['generated_at'] = $row['created_at'];
    } catch (Exception $e) {
        error_log('[project.analysis] simpan log: ' . $e->getMessage());
        $result['log_id'] = null;
        $result['ai_note'] = trim($result['ai_note'] . ' Hasil belum tersimpan ke ai_variance_logs (' . $e->getMessage() . ').');
    }
    return $result;
}

/**
 * Status kartu kesehatan proyek -- SATU aturan untuk dashboard (selaras indeks biaya modul Project Progress: CPI < 0.80 merah, < 0.90 kuning).
 * Input : progress (%), budget, cost, end_date (Y-m-d|null), ai_risk (LOW|MEDIUM|HIGH|CRITICAL|null)
 * Output: ['level' => NORMAL|WASPADA|KRITIS|NA, 'reasons' => [...], 'usage' => %|null, 'gap' => |null, 'cpi' => |null, 'late_days' => int]
 */
function dash_card_level(int $progress, float $budget, float $cost, ?string $endDate, ?string $aiRisk): array {
    $rank = ['NORMAL' => 0, 'NA' => 0, 'WASPADA' => 1, 'KRITIS' => 2];
    $level = 'NORMAL'; $reasons = [];
    $bump = function (string $lv, string $why) use (&$level, &$reasons, $rank) { if ($rank[$lv] > $rank[$level]) $level = $lv; $reasons[] = $why; };
    $usage = $budget > 0 ? $cost / $budget * 100 : null;
    $gap = $usage !== null ? $usage - $progress : null;
    $ev = $budget * $progress / 100;
    $cpi = ($budget > 0 && $cost > 0) ? $ev / $cost : null;
    if ($budget <= 0) { $level = 'NA'; if ($cost > 0) $reasons[] = 'Budget belum diisi'; }
    if ($usage !== null && $usage > 100) $bump('KRITIS', 'Biaya melampaui budget');
    if ($gap !== null && $gap >= HEALTH_CRIT_GAP) $bump('KRITIS', 'Gap biaya >= ' . HEALTH_CRIT_GAP);
    elseif ($gap !== null && $gap >= HEALTH_WARN_GAP) $bump('WASPADA', 'Gap biaya >= ' . HEALTH_WARN_GAP);
    if ($cpi !== null && $cpi < 0.80) $bump('KRITIS', 'Indeks biaya (CPI) ' . round($cpi, 2) . ' < 0,80');
    elseif ($cpi !== null && $cpi < 0.90) $bump('WASPADA', 'Indeks biaya (CPI) ' . round($cpi, 2) . ' < 0,90');
    $late = 0;
    if ($endDate && $progress < 100) {
        $late = -((int)(new DateTime('today'))->diff(new DateTime($endDate))->format('%r%a'));       // hari melewati tanggal selesai
        if ($late > 30) $bump('KRITIS', "Melewati tanggal selesai {$late} hari");
        elseif ($late > 0) $bump('WASPADA', "Melewati tanggal selesai {$late} hari");
    }
    if ($gap !== null && $gap <= -25 && $progress > 0) $bump('WASPADA', 'Biaya kemungkinan belum tercatat (pemakaian jauh di bawah progres)');
    $ai = strtoupper((string)$aiRisk);
    if ($ai === 'HIGH' || $ai === 'CRITICAL') $bump('KRITIS', 'Analisis AI terakhir: risiko ' . $ai);
    elseif ($ai === 'MEDIUM') $bump('WASPADA', 'Analisis AI terakhir: risiko MEDIUM');
    return ['level' => $level, 'reasons' => $reasons, 'usage' => $usage === null ? null : round($usage, 1), 'gap' => $gap === null ? null : round($gap, 1),
            'cpi' => $cpi === null ? null : round($cpi, 2), 'late_days' => max(0, $late)];
}
