<?php
/**
 * subconCRD.php
 * ============================================================
 * Backend Modul Subkontraktor (js/subcon.js, views/subcon.php). Pola sama dengan poCRD.php: PDO (class crud),
 * dispatch lewat $_REQUEST['task'], respons JSON {success, message, data, totalCount}. Migrasi: sql/2026-10-30.subcon.sql
 *
 * Rantai dokumen (semua bisa ditelusuri, tidak ada yang dihapus -- hanya Dibatalkan dengan alasan):
 *   Proyek -> (Task proyek) -> SPK (subcon_contracts) -> Prestasi (subcon_progress) -> Pembayaran (subcon_payments) -> Jurnal
 *
 * SPK     : Draft -> Diajukan -> Disetujui -> Selesai ; Dibatalkan dari Draft/Diajukan/Disetujui selama belum ada prestasi/pembayaran.
 *           Nilai SPK (DPP) dibagi ke task proyek (project_tasks); total pembagian wajib = nilai kontrak. Opsional terikat satu baris RAB:
 *           total SPK pada baris RAB itu tidak boleh melebihi nilai anggaran baris (qty_plan x unit_price_plan).
 * Prestasi: per periode, progres kumulatif % per task (tidak boleh turun, maks 100). Nilai periode = nilai task x (kum - sebelumnya)/100.
 *           PPN = nilai x tarif SPK ; PPh final dipotong = nilai x tarif ; retensi = nilai x % ; potongan uang muka proporsional
 *           (nilai x dp% , maks sisa uang muka; di progres 100% sisa uang muka dipotong semua) ;
 *           bayar = nilai + PPN - PPh - retensi - potongan uang muka.
 *           Hanya satu prestasi Draft per SPK. Disetujui = jurnal (Dr WIP 1700 / PPN Masukan 2400 ; Cr Hutang Subkon 2110 ;
 *           potongan: Dr 2110 / Cr Hutang PPh 2130, Hutang Retensi 2120, Uang Muka Subkon 1410). Batal hanya prestasi Disetujui TERAKHIR
 *           dan belum ada pembayaran.
 * Bayar   : DP (Dr 1410 / Cr kas-bank, <= uang muka SPK), PROGRESS (Dr 2110 / Cr kas-bank, <= sisa prestasi), RETENSI
 *           (Dr 2120 / Cr kas-bank, <= retensi ditahan, hanya setelah SPK Selesai). Batal = reverse jurnal.
 * Kontrol : SC_REQUIRE_OTHER_APPROVER=true -> penyetuju SPK / prestasi harus user lain selain pembuatnya.
 * Task    : list_project_options | list_subcontractors | save_subcontractor | list_project_tasks | list_budget_items | list_accounts |
 *           list_contracts | load_contract | save_contract | change_status | delete_contract | progress_prepare | save_progress |
 *           approve_progress | cancel_progress | save_payment | cancel_payment
 * ============================================================
 */
ini_set('display_errors', '0');
ob_start();

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$db = new crud();

const SC_EPS = 0.01;
const SC_REQUIRE_OTHER_APPROVER = false;
const SC_REF_PROGRESS = 'subcon_progress';
const SC_REF_PAYMENT  = 'subcon_payment';
const SC_TRANSITIONS = [
    'Draft'      => ['Diajukan', 'Dibatalkan'],
    'Diajukan'   => ['Disetujui', 'Draft', 'Dibatalkan'],
    'Disetujui'  => ['Selesai', 'Dibatalkan'],
    'Selesai'    => [],
    'Dibatalkan' => [],
];

class ScError extends Exception {}

function sc_send($success, $data = [], $message = '', $extra = []) {
    $resp = array_merge(['success' => $success, 'message' => $message], $extra);
    if ($success) {
        $resp['data'] = $data;
        if (!isset($resp['totalCount'])) $resp['totalCount'] = is_array($data) ? count($data) : 0;    // totalCount dari $extra (paging) diutamakan
    }
    if (ob_get_length()) { ob_clean(); }
    echo json_encode($resp, JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function sc_f($v): float { return round((float)$v, 2); }
function sc_uid() { return isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null; }
function sc_date(string $d, string $label): string {
    $x = DateTime::createFromFormat('Y-m-d', $d);
    if (!$x || $x->format('Y-m-d') !== $d) throw new ScError($label . ' tidak valid (YYYY-MM-DD).');
    return $d;
}
function sc_pct($v, string $label): float {
    $p = round((float)$v, 2);
    if ($p < 0 || $p > 100) throw new ScError($label . ' harus antara 0 - 100.');
    return $p;
}

/** Pastikan enum reference_type jurnal memuat 'subcon_progress' & 'subcon_payment' (bila kolom berupa enum). */
function sc_assert_ref_types($db): void {
    $st = $db->query("SELECT t.typtype, t.oid FROM pg_attribute a JOIN pg_class c ON c.oid = a.attrelid JOIN pg_type t ON t.oid = a.atttypid
                       WHERE c.relname = 'journal_entries' AND a.attname = 'reference_type' AND NOT a.attisdropped LIMIT 1");
    $r = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
    if (!$r || $r['typtype'] !== 'e') return;
    $st = $db->prepare("SELECT enumlabel FROM pg_enum WHERE enumtypid = :o");
    $st->execute([':o' => $r['oid']]);
    $have = $st->fetchAll(PDO::FETCH_COLUMN);
    foreach ([SC_REF_PROGRESS, SC_REF_PAYMENT] as $need) {
        if (!in_array($need, $have, true)) {
            throw new ScError("Tipe jurnal '{$need}' belum terdaftar di database. Jalankan ALTER TYPE public.reference_type ADD VALUE untuk nilai itu (lihat spk.subcon.step1.sql) terlebih dahulu.");
        }
    }
}
/** Apakah kolom subcon_contracts.spk_id (tautan ke SPK subkon) sudah ada di database ini? */
function sc_has_spk_link($db): bool {
    static $ok = null;
    if ($ok === null) {
        $ok = (bool)$db->query("SELECT 1 FROM information_schema.columns
                                 WHERE table_schema = 'public' AND table_name = 'subcon_contracts' AND column_name = 'spk_id' LIMIT 1")->fetchColumn();
    }
    return $ok;
}
function sc_has_journal($db, string $ref, int $id): bool {
    $st = $db->prepare("SELECT 1 FROM journal_entries WHERE reference_type::text = :t AND reference_id = :id LIMIT 1");
    $st->execute([':t' => $ref, ':id' => $id]);
    return (bool)$st->fetchColumn();
}
function sc_post($db, string $rule, string $ref, int $id, string $date, float $amount, string $desc, ?int $creditAccountId = null): void {
    if ($amount <= 0.004) return;
    $p = ['rule_code' => $rule, 'reference_type' => $ref, 'reference_id' => $id, 'journal_date' => $date, 'amount' => $amount, 'description' => $desc];
    if ($creditAccountId) $p['credit_account_id'] = $creditAccountId;
    $r = AutoPostJournal($db, $p);
    if (!$r['success']) throw new ScError('Gagal posting jurnal ' . $rule . ': ' . $r['message']);
}

function sc_contract($db, int $id, bool $lock = false): array {
    $st = $db->prepare("SELECT * FROM subcon_contracts WHERE id = :id" . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([':id' => $id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) throw new ScError('SPK tidak ditemukan.');
    foreach (['contract_value', 'ppn_rate', 'pph_rate', 'retention_pct', 'dp_pct'] as $k) $c[$k] = (float)$c[$k];
    return $c;
}

/** Total prestasi disetujui & pembayaran posted untuk satu SPK (dasar sisa uang muka, retensi, hutang). */
function sc_totals($db, int $cid): array {
    $st = $db->prepare("SELECT COALESCE(SUM(gross_amount),0) g, COALESCE(SUM(ppn_amount),0) ppn, COALESCE(SUM(pph_amount),0) pph,
                               COALESCE(SUM(retention_amount),0) ret, COALESCE(SUM(dp_recovery),0) dprec, COALESCE(SUM(net_payable),0) net
                          FROM subcon_progress WHERE contract_id = :c AND status = 'Disetujui'");
    $st->execute([':c' => $cid]);
    $t = $st->fetch(PDO::FETCH_ASSOC);
    $st = $db->prepare("SELECT COALESCE(SUM(CASE WHEN pay_type='DP' THEN amount END),0) dp, COALESCE(SUM(CASE WHEN pay_type='PROGRESS' THEN amount END),0) pr,
                               COALESCE(SUM(CASE WHEN pay_type='RETENSI' THEN amount END),0) rt
                          FROM subcon_payments WHERE contract_id = :c AND status = 'Posted'");
    $st->execute([':c' => $cid]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    $o = ['gross' => sc_f($t['g']), 'ppn' => sc_f($t['ppn']), 'pph' => sc_f($t['pph']), 'retention' => sc_f($t['ret']),
          'dp_recovered' => sc_f($t['dprec']), 'net' => sc_f($t['net']),
          'dp_paid' => sc_f($p['dp']), 'progress_paid' => sc_f($p['pr']), 'retention_paid' => sc_f($p['rt'])];
    $o['dp_balance']        = sc_f($o['dp_paid'] - $o['dp_recovered']);        // uang muka yang belum dipotong
    $o['retention_balance'] = sc_f($o['retention'] - $o['retention_paid']);    // retensi ditahan, belum dilepas
    $o['payable_balance']   = sc_f($o['net'] - $o['progress_paid']);           // hutang prestasi belum dibayar
    return $o;
}

/** Progres kumulatif sebelumnya (disetujui) per baris task SPK. */
function sc_prev_map($db, int $cid, int $exclProgressId = 0): array {
    $st = $db->prepare("SELECT d.contract_task_id, MAX(d.cum_pct) AS m FROM subcon_progress_dtl d JOIN subcon_progress p ON p.id = d.progress_id
                         WHERE p.contract_id = :c AND p.status = 'Disetujui' AND p.id <> :x GROUP BY d.contract_task_id");
    $st->execute([':c' => $cid, ':x' => $exclProgressId]);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int)$r['contract_task_id']] = (float)$r['m'];
    return $m;
}

/** Hitung PPN, PPh, retensi, potongan uang muka dan nilai bayar satu prestasi. */
function sc_calc(array $c, float $gross, float $cumPct, float $dpBalance): array {
    $ppn = round($gross * $c['ppn_rate'] / 100, 2);
    $pph = round($gross * $c['pph_rate'] / 100, 2);
    $ret = round($gross * $c['retention_pct'] / 100, 2);
    $plan = round($gross * $c['dp_pct'] / 100, 2);
    $dpRec = ($cumPct >= 99.999) ? $dpBalance : min($dpBalance, $plan);       // progres 100% -> sisa uang muka dipotong semua
    $dpRec = max(0.0, round($dpRec, 2));
    $net = round($gross + $ppn - $pph - $ret - $dpRec, 2);
    if ($net < 0) { $dpRec = max(0.0, round($dpRec + $net, 2)); $net = round($gross + $ppn - $pph - $ret - $dpRec, 2); }
    return ['ppn' => $ppn, 'pph' => $pph, 'retention' => $ret, 'dp_recovery' => $dpRec, 'net' => $net];
}

/* =====================================================================================================
 * [2026-11-11] Klaim berbasis KUANTITAS + INSPEKSI  (Langkah 2, docs/SUBCONTRACT_MANAGEMENT_DESIGN.md)
 *   Task kontrak dipetakan 1:1 ke item lingkup SPK (spk_work_items). Untuk task terpetakan, prestasi diisi dengan
 *   QTY klaim (bukan persen). Inspeksi meloloskan / menolak / meminta perbaikan per baris; hanya qty LOLOS yang
 *   membentuk progres, nilai prestasi, jurnal dan hutang. Task tanpa pemetaan tetap memakai persen seperti sebelumnya.
 * ===================================================================================================== */
function sc_has_qty($db): bool {
    static $ok = null;
    if ($ok === null) {
        $v = $db->query("SELECT to_regclass('public.subcon_inspections') IS NOT NULL
                            AND to_regclass('public.subcon_progress_qty_items') IS NOT NULL
                            AND to_regclass('public.spk_work_items') IS NOT NULL
                            AND EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public'
                                         AND table_name = 'subcon_contract_tasks' AND column_name = 'work_item_id')")->fetchColumn();
        $ok = ($v === true || $v === 't' || $v === 1 || $v === '1');
    }
    return $ok;
}

/** Qty yang sudah DITERIMA (lolos inspeksi, prestasi Disetujui) per item lingkup pada satu kontrak. */
function sc_accepted_map($db, int $cid, int $exclProgressId = 0): array {
    $st = $db->prepare("SELECT q.work_item_id, COALESCE(SUM(ii.qty_passed), 0) AS passed
                          FROM subcon_inspection_items ii
                          JOIN subcon_inspections i ON i.id = ii.inspection_id AND i.status = 'APPROVED'
                          JOIN subcon_progress_qty_items q ON q.id = ii.progress_qty_item_id
                          JOIN subcon_progress p ON p.id = q.progress_id AND p.contract_id = :c AND p.status = 'Disetujui' AND p.id <> :x
                         GROUP BY q.work_item_id");
    $st->execute([':c' => $cid, ':x' => $exclProgressId]);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $m[(int)$r['work_item_id']] = (float)$r['passed'];
    return $m;
}

/** Task kontrak yang terpetakan ke item lingkup: contract_task_id => info item. */
function sc_task_work_map($db, int $cid): array {
    $st = $db->prepare("SELECT t.id AS tid, t.task_name, t.work_item_id, w.work_code, w.description, w.unit, w.contract_qty, w.unit_price
                          FROM subcon_contract_tasks t JOIN spk_work_items w ON w.id = t.work_item_id WHERE t.contract_id = :c");
    $st->execute([':c' => $cid]);
    $m = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $r['work_item_id'] = (int)$r['work_item_id']; $r['contract_qty'] = (float)$r['contract_qty']; $r['unit_price'] = (float)$r['unit_price'];
        $m[(int)$r['tid']] = $r;
    }
    return $m;
}

function sc_insp_result(float $claimed, float $inspected, float $passed, float $rejected, float $rework): string {
    $e = 0.00005;
    if ($inspected <= $e) return 'PENDING';
    if ($passed >= $claimed - $e) return 'PASS';
    if ($passed > $e) return 'PARTIAL';
    return ($rework > $e) ? 'REWORK' : 'REJECT';
}

/** Hitung ulang baris task terpetakan + header satu prestasi Draft. $usePassed=true -> dasar qty LOLOS inspeksi; false -> qty KLAIM. */
function sc_recompute_progress($db, array $c, int $pid, bool $usePassed): array {
    $cid  = (int)$c['id'];
    $acc  = sc_accepted_map($db, $cid, $pid);
    $prev = sc_prev_map($db, $cid, $pid);
    $st = $db->prepare("SELECT q.contract_task_id, q.work_item_id, q.qty_claimed, q.unit_price_snapshot, w.contract_qty,
                               COALESCE((SELECT SUM(ii.qty_passed) FROM subcon_inspection_items ii
                                           JOIN subcon_inspections i ON i.id = ii.inspection_id AND i.status = 'APPROVED'
                                          WHERE ii.progress_qty_item_id = q.id), 0) AS passed
                          FROM subcon_progress_qty_items q JOIN spk_work_items w ON w.id = q.work_item_id WHERE q.progress_id = :p");
    $st->execute([':p' => $pid]);
    $upd = $db->prepare("UPDATE subcon_progress_dtl SET cum_pct = :c, amount = :a WHERE progress_id = :p AND contract_task_id = :t");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $q) {
        $tid = (int)$q['contract_task_id']; $wi = (int)$q['work_item_id'];
        $qty = $usePassed ? (float)$q['passed'] : (float)$q['qty_claimed'];
        $cq  = (float)$q['contract_qty'];
        $pv  = isset($prev[$tid]) ? $prev[$tid] : 0.0;
        $prior = isset($acc[$wi]) ? $acc[$wi] : 0.0;
        $cum = ($qty > 0 && $cq > 0) ? min(100.0, round(($prior + $qty) / $cq * 100, 3)) : $pv;
        $amt = round($qty * (float)$q['unit_price_snapshot'], 2);
        $upd->execute([':c' => $cum, ':a' => $amt, ':p' => $pid, ':t' => $tid]);
    }
    $st = $db->prepare("SELECT COALESCE(SUM(d.amount), 0) AS g, COALESCE(SUM(t.task_value * d.cum_pct / 100), 0) AS w
                          FROM subcon_progress_dtl d JOIN subcon_contract_tasks t ON t.id = d.contract_task_id WHERE d.progress_id = :p");
    $st->execute([':p' => $pid]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    $gross  = sc_f($r['g']);
    $cumPct = $c['contract_value'] > 0 ? round((float)$r['w'] / $c['contract_value'] * 100, 3) : 0;
    $tot  = sc_totals($db, $cid);
    $calc = sc_calc($c, $gross, $cumPct, $tot['dp_balance']);
    $db->prepare("UPDATE subcon_progress SET gross_amount = :g, ppn_amount = :ppn, pph_amount = :pph, retention_amount = :ret,
                         dp_recovery = :dp, net_payable = :net, cum_pct = :cp, updated_at = NOW() WHERE id = :id")
       ->execute([':g' => $gross, ':ppn' => $calc['ppn'], ':pph' => $calc['pph'], ':ret' => $calc['retention'], ':dp' => $calc['dp_recovery'],
                  ':net' => $calc['net'], ':cp' => $cumPct, ':id' => $pid]);
    return ['gross' => $gross, 'cum_pct' => $cumPct, 'calc' => $calc];
}

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sc_send(false, [], 'Sesi berakhir, silakan login ulang.');
}


$task = $_REQUEST['task'] ?? '';

// Otorisasi operasi berdasarkan FormAccess dari router.
// Level: 1 baca, 2 tambah, 3 ubah/approval, 4 pembatalan finansial/penghapusan.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    sc_send(false, [], 'Sesi berakhir, silakan login ulang.');
}
$scAccess = (int)($_SESSION['FormAccess'] ?? 0);
$scRequired = 1;
if ($task === 'save_subcontractor') {
    $scRequired = 2;
} elseif ($task === 'save_contract') {
    $scRequired = 2;
} elseif ($task === 'save_progress') {
    $scRequired = 2;
} elseif (in_array($task, ['save_payment', 'save_inspection'], true)) {
    $scRequired = 2;
} elseif (in_array($task, ['change_status', 'approve_progress', 'approve_inspection', 'cancel_inspection'], true)) {
    $scRequired = 3;
} elseif ($task === 'cancel_progress') {
    $scRequired = 4;
} elseif ($task === 'cancel_payment') {
    $scRequired = 4;
} elseif ($task === 'delete_contract') {
    $scRequired = 4;
}
if ($scAccess < $scRequired) {
    http_response_code(403);
    sc_send(false, [], 'Hak akses tidak mencukupi untuk operasi subkontraktor ini.');
}

try {
    switch ($task) {

        /* ------------------------------------------------------------------ master untuk combo */
        case 'list_project_options':
            $rows = $db->query("SELECT id, name FROM public.projects ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            sc_send(true, $rows);
            break;

        case 'list_subcontractors':
            $rows = $db->query("SELECT id, name, npwp_no, is_pkp, pph_rate FROM public.subcontractors WHERE active ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['pph_rate'] = (float)$r['pph_rate']; }
            unset($r);
            sc_send(true, $rows);
            break;

        case 'save_subcontractor':
            $id = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new ScError('Nama subkontraktor wajib diisi.');
            $pph = sc_pct($_POST['pph_rate'] ?? 0, 'Tarif PPh');
            $v = [':name' => $name, ':npwp' => trim((string)($_POST['npwp_no'] ?? '')) ?: null,
                  ':pkp' => (($_POST['is_pkp'] ?? '') === '1' || ($_POST['is_pkp'] ?? '') === 'true') ? 't' : 'f',
                  ':cp' => trim((string)($_POST['contact_person'] ?? '')) ?: null, ':ph' => trim((string)($_POST['phone'] ?? '')) ?: null,
                  ':ad' => trim((string)($_POST['address'] ?? '')) ?: null, ':bn' => trim((string)($_POST['bank_name'] ?? '')) ?: null,
                  ':ba' => trim((string)($_POST['bank_account_no'] ?? '')) ?: null, ':bh' => trim((string)($_POST['bank_account_name'] ?? '')) ?: null,
                  ':pph' => $pph];
            if ($id > 0) {
                $v[':id'] = $id;
                $db->prepare("UPDATE subcontractors SET name=:name, npwp_no=:npwp, is_pkp=:pkp, contact_person=:cp, phone=:ph, address=:ad,
                                     bank_name=:bn, bank_account_no=:ba, bank_account_name=:bh, pph_rate=:pph WHERE id=:id")->execute($v);
            } else {
                $st = $db->prepare("INSERT INTO subcontractors (name, npwp_no, is_pkp, contact_person, phone, address, bank_name, bank_account_no, bank_account_name, pph_rate)
                                    VALUES (:name,:npwp,:pkp,:cp,:ph,:ad,:bn,:ba,:bh,:pph) RETURNING id");
                $st->execute($v);
                $id = (int)$st->fetchColumn();
            }
            sc_send(true, ['id' => $id], 'Subkontraktor tersimpan.');
            break;

        case 'list_project_tasks':
            $st = $db->prepare("SELECT id, task_name, start_date, end_date FROM public.project_tasks WHERE project_id = :p ORDER BY start_date ASC, id ASC");
            $st->execute([':p' => (int)($_REQUEST['project_id'] ?? 0)]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) $r['id'] = (int)$r['id'];
            unset($r);
            sc_send(true, $rows);
            break;

        // Baris RAB non-material proyek (upah / subkon / lainnya) + sisa anggarannya
        case 'list_budget_items':
            $st = $db->prepare("SELECT bi.id, bi.item_name, bi.category, COALESCE(bi.qty_plan,0) * COALESCE(bi.unit_price_plan,0) AS budget_value,
                                       COALESCE((SELECT SUM(c.contract_value) FROM subcon_contracts c WHERE c.budget_item_id = bi.id
                                                  AND c.status <> 'Dibatalkan' AND c.id <> :x), 0) AS committed
                                  FROM public.budget_items bi WHERE bi.project_id = :p AND bi.category <> 'Material' ORDER BY bi.item_name");
            $st->execute([':p' => (int)($_REQUEST['project_id'] ?? 0), ':x' => (int)($_REQUEST['contract_id'] ?? 0)]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['budget_value'] = sc_f($r['budget_value']); $r['committed'] = sc_f($r['committed']);
                $r['remaining'] = sc_f($r['budget_value'] - $r['committed']);
                $r['label'] = $r['item_name'] . ' (' . $r['category'] . ') - sisa ' . number_format($r['remaining'], 2);
            }
            unset($r);
            array_unshift($rows, ['id' => 0, 'item_name' => '', 'category' => '', 'budget_value' => 0, 'committed' => 0, 'remaining' => 0, 'label' => '(tanpa baris RAB)']);
            sc_send(true, $rows);
            break;

        case 'list_accounts':
            $rows = $db->query("SELECT id, account_code, account_name FROM public.chart_of_accounts
                                 WHERE account_type::text IN ('cash','bank') AND is_active ORDER BY account_code")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['label'] = $r['account_code'] . ' - ' . $r['account_name']; }
            unset($r);
            sc_send(true, $rows);
            break;

        /* ------------------------------------------------------------------ daftar SPK (grid utama) */
        /* ------------------------------------------------------------------ SPK subkon yang bisa ditautkan ke kontrak */
        case 'list_spk_options':
            $oPrj = (int)($_REQUEST['project_id'] ?? 0);
            $oSub = (int)($_REQUEST['subcontractor_id'] ?? 0);
            $oCur = (int)($_REQUEST['contract_id'] ?? 0);
            if (!sc_has_spk_link($db) || $oPrj <= 0 || $oSub <= 0) { sc_send(true, []); break; }
            $st = $db->prepare("SELECT s.id, s.spk_no, s.spk_date, s.status, COALESCE(s.description, '') AS description
                                  FROM spk s
                                 WHERE s.spk_type = 'SUBCON' AND s.project_id = :p AND s.execution_subcontractor_id = :s
                                   AND s.status IN ('Draft', 'Diterbitkan')
                                   AND NOT EXISTS (SELECT 1 FROM subcon_contracts c WHERE c.spk_id = s.id AND c.id <> :c)
                                 ORDER BY s.spk_date DESC, s.id DESC");
            $st->execute([':p' => $oPrj, ':s' => $oSub, ':c' => $oCur]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) { $r['id'] = (int)$r['id']; }
            unset($r);
            sc_send(true, $rows);
            break;

        /* ------------------------------------------------------------------ item lingkup SPK untuk pemetaan task kontrak */
        case 'list_work_items':
            $wSpk = (int)($_REQUEST['spk_id'] ?? 0);
            if (!sc_has_qty($db) || $wSpk <= 0) { sc_send(true, []); break; }
            $st = $db->prepare("SELECT w.id, w.work_code, w.description, w.unit, w.contract_qty, w.unit_price, w.line_amount, w.project_task_id
                                  FROM spk_work_items w WHERE w.spk_id = :s AND w.status = 'ACTIVE' ORDER BY w.sort_order, w.id");
            $st->execute([':s' => $wSpk]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['contract_qty'] = (float)$r['contract_qty']; $r['unit_price'] = (float)$r['unit_price'];
                $r['line_amount'] = (float)$r['line_amount']; $r['project_task_id'] = $r['project_task_id'] !== null ? (int)$r['project_task_id'] : null;
            }
            unset($r);
            sc_send(true, $rows);
            break;

        case 'list_contracts':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');
            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (c.spk_no ILIKE :q OR c.title ILIKE :q OR p.name ILIKE :q OR s.name ILIKE :q)";
                $params[':q'] = '%' . $query . '%';
            }
            $hasLink = sc_has_spk_link($db);
            $lx = $hasLink ? "c.spk_id, ls.spk_no AS linked_spk_no, ls.status AS linked_spk_status,"
                           : "NULL::int AS spk_id, NULL::text AS linked_spk_no, NULL::text AS linked_spk_status,";
            $jx = $hasLink ? "LEFT JOIN spk ls ON ls.id = c.spk_id" : "";
            $st = $db->prepare("SELECT COUNT(*) FROM subcon_contracts c JOIN public.projects p ON p.id = c.project_id
                                  JOIN subcontractors s ON s.id = c.subcontractor_id {$where}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT c.id, c.spk_no, c.project_id, p.name AS project_name, c.subcontractor_id, s.name AS subcontractor_name, c.title,
                       c.contract_value, c.ppn_rate, c.pph_rate, c.retention_pct, c.dp_pct, c.start_date, c.end_date, c.status,
                       {$lx}
                       COALESCE(pr.gross,0) AS billed_gross, COALESCE(pr.net,0) AS billed_net, COALESCE(pr.ret,0) AS retention_held,
                       COALESCE(pay.dp,0) AS dp_paid, COALESCE(pay.pr,0) AS progress_paid, COALESCE(pay.rt,0) AS retention_paid
                  FROM subcon_contracts c
                  JOIN public.projects p ON p.id = c.project_id
                  JOIN subcontractors s ON s.id = c.subcontractor_id
                  {$jx}
                  LEFT JOIN (SELECT contract_id, SUM(gross_amount) gross, SUM(net_payable) net, SUM(retention_amount) ret
                               FROM subcon_progress WHERE status = 'Disetujui' GROUP BY contract_id) pr ON pr.contract_id = c.id
                  LEFT JOIN (SELECT contract_id, SUM(CASE WHEN pay_type='DP' THEN amount END) dp, SUM(CASE WHEN pay_type='PROGRESS' THEN amount END) pr,
                                    SUM(CASE WHEN pay_type='RETENSI' THEN amount END) rt
                               FROM subcon_payments WHERE status = 'Posted' GROUP BY contract_id) pay ON pay.contract_id = c.id
                  {$where}
                 ORDER BY c.id DESC LIMIT :limit OFFSET :start");
            foreach ($params as $k => $v) $st->bindValue($k, $v);
            $st->bindValue(':limit', $limit, PDO::PARAM_INT);
            $st->bindValue(':start', $start, PDO::PARAM_INT);
            $st->execute();
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['project_id'] = (int)$r['project_id']; $r['subcontractor_id'] = (int)$r['subcontractor_id'];
                foreach (['contract_value', 'ppn_rate', 'pph_rate', 'retention_pct', 'dp_pct', 'billed_gross', 'billed_net', 'retention_held', 'dp_paid', 'progress_paid', 'retention_paid'] as $k) $r[$k] = (float)$r[$k];
                $r['progress_pct'] = $r['contract_value'] > 0 ? round($r['billed_gross'] / $r['contract_value'] * 100, 2) : 0;
                $r['payable_balance'] = sc_f($r['billed_net'] - $r['progress_paid']);
            }
            unset($r);
            sc_send(true, $rows, '', ['totalCount' => $total]);
            break;

        /* ------------------------------------------------------------------ detail SPK (panel detail) */
        case 'load_contract':
            $id = (int)($_REQUEST['id'] ?? 0);
            $c = sc_contract($db, $id);
            $st = $db->prepare("SELECT c.*, p.name AS project_name, s.name AS subcontractor_name, s.npwp_no, s.is_pkp,
                                       bi.item_name AS budget_item_name, cu.login_name AS created_by_name, au.login_name AS approved_by_name,
                                       xu.login_name AS cancelled_by_name, zu.login_name AS closed_by_name
                                  FROM subcon_contracts c JOIN public.projects p ON p.id = c.project_id JOIN subcontractors s ON s.id = c.subcontractor_id
                                  LEFT JOIN public.budget_items bi ON bi.id = c.budget_item_id
                                  LEFT JOIN public.m_user_system cu ON cu.id = c.created_by LEFT JOIN public.m_user_system au ON au.id = c.approved_by
                                  LEFT JOIN public.m_user_system xu ON xu.id = c.cancelled_by LEFT JOIN public.m_user_system zu ON zu.id = c.closed_by
                                 WHERE c.id = :id");
            $st->execute([':id' => $id]);
            $hdr = $st->fetch(PDO::FETCH_ASSOC);
            $hdr['linked_spk_no'] = null; $hdr['linked_spk_status'] = null;
            if (!empty($hdr['spk_id'])) {
                $st = $db->prepare("SELECT spk_no, status FROM spk WHERE id = :i");
                $st->execute([':i' => (int)$hdr['spk_id']]);
                $ls = $st->fetch(PDO::FETCH_ASSOC);
                if ($ls) { $hdr['linked_spk_no'] = $ls['spk_no']; $hdr['linked_spk_status'] = $ls['status']; }
            }

            $prev = sc_prev_map($db, $id);
            $st = $db->prepare("SELECT t.id, t.task_id, t.task_name, t.task_value, t.notes, pt.start_date, pt.end_date
                                  FROM subcon_contract_tasks t LEFT JOIN public.project_tasks pt ON pt.id = t.task_id
                                 WHERE t.contract_id = :id ORDER BY pt.start_date NULLS LAST, t.id");
            $st->execute([':id' => $id]);
            $tasks = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($tasks as &$t) {
                $t['id'] = (int)$t['id']; $t['task_id'] = $t['task_id'] !== null ? (int)$t['task_id'] : null; $t['task_value'] = (float)$t['task_value'];
                $t['cum_pct'] = isset($prev[$t['id']]) ? $prev[$t['id']] : 0.0;
                $t['billed_amount'] = sc_f($t['task_value'] * $t['cum_pct'] / 100);
            }
            unset($t);
            $hasQty = sc_has_qty($db);
            if ($hasQty) {
                $wm = sc_task_work_map($db, $id);
                foreach ($tasks as &$t) {
                    $t['work_item_id'] = null; $t['work_code'] = null; $t['unit'] = null; $t['contract_qty'] = null; $t['unit_price'] = null; $t['accepted_qty'] = null;
                    if (isset($wm[$t['id']])) {
                        $w = $wm[$t['id']];
                        $t['work_item_id'] = $w['work_item_id']; $t['work_code'] = $w['work_code']; $t['unit'] = $w['unit'];
                        $t['contract_qty'] = $w['contract_qty']; $t['unit_price'] = $w['unit_price'];
                    }
                }
                unset($t);
                $accAll = sc_accepted_map($db, $id);
                foreach ($tasks as &$t) { if ($t['work_item_id']) $t['accepted_qty'] = isset($accAll[$t['work_item_id']]) ? $accAll[$t['work_item_id']] : 0.0; }
                unset($t);
            }

            $st = $db->prepare("SELECT p.*, cu.login_name AS created_by_name, au.login_name AS approved_by_name,
                                       COALESCE((SELECT SUM(y.amount) FROM subcon_payments y WHERE y.progress_id = p.id AND y.status = 'Posted'), 0) AS paid_amount
                                  FROM subcon_progress p LEFT JOIN public.m_user_system cu ON cu.id = p.created_by LEFT JOIN public.m_user_system au ON au.id = p.approved_by
                                 WHERE p.contract_id = :id ORDER BY p.id");
            $st->execute([':id' => $id]);
            $progress = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($progress as &$p) {
                $p['id'] = (int)$p['id'];
                foreach (['gross_amount', 'ppn_amount', 'pph_amount', 'retention_amount', 'dp_recovery', 'net_payable', 'cum_pct', 'paid_amount'] as $k) $p[$k] = (float)$p[$k];
                $p['qty_lines'] = 0; $p['insp_status'] = null;
            }
            unset($p);
            $inspections = [];
            if ($hasQty && count($progress)) {
                $st = $db->prepare("SELECT q.progress_id, COUNT(*) AS n FROM subcon_progress_qty_items q JOIN subcon_progress p ON p.id = q.progress_id WHERE p.contract_id = :c GROUP BY q.progress_id");
                $st->execute([':c' => $id]);
                $qn = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $qn[(int)$r['progress_id']] = (int)$r['n'];
                $st = $db->prepare("SELECT i.id, i.inspection_no, i.progress_id, p.ref_no AS progress_ref, i.inspection_date, i.status, i.general_result, i.evidence_ref, i.cancel_reason,
                                           cu.login_name AS created_by_name, au.login_name AS approved_by_name
                                      FROM subcon_inspections i JOIN subcon_progress p ON p.id = i.progress_id
                                      LEFT JOIN public.m_user_system cu ON cu.id = i.created_by LEFT JOIN public.m_user_system au ON au.id = i.approved_by
                                     WHERE p.contract_id = :c ORDER BY i.id");
                $st->execute([':c' => $id]);
                $inspections = $st->fetchAll(PDO::FETCH_ASSOC);
                $ids = [];
                foreach ($inspections as &$i) { $i['id'] = (int)$i['id']; $i['progress_id'] = (int)$i['progress_id']; $i['items'] = []; $ids[] = $i['id']; }
                unset($i);
                if (count($ids)) {
                    $st = $db->query("SELECT ii.inspection_id, ii.progress_qty_item_id, ii.qty_inspected, ii.qty_passed, ii.qty_rejected, ii.qty_rework, ii.result, ii.notes,
                                             q.qty_claimed, w.description, w.unit
                                        FROM subcon_inspection_items ii JOIN subcon_progress_qty_items q ON q.id = ii.progress_qty_item_id
                                        JOIN spk_work_items w ON w.id = q.work_item_id
                                       WHERE ii.inspection_id IN (" . implode(',', $ids) . ") ORDER BY ii.id");
                    $by = [];
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                        foreach (['qty_inspected', 'qty_passed', 'qty_rejected', 'qty_rework', 'qty_claimed'] as $k) $r[$k] = (float)$r[$k];
                        $by[(int)$r['inspection_id']][] = $r;
                    }
                    foreach ($inspections as &$i) { $i['items'] = isset($by[$i['id']]) ? $by[$i['id']] : []; }
                    unset($i);
                }
                $latest = [];
                foreach ($inspections as $i) { if (in_array($i['status'], ['DRAFT', 'APPROVED'], true)) $latest[$i['progress_id']] = $i['status']; }
                foreach ($progress as &$p) { $p['qty_lines'] = isset($qn[$p['id']]) ? $qn[$p['id']] : 0; $p['insp_status'] = isset($latest[$p['id']]) ? $latest[$p['id']] : null; }
                unset($p);
            }

            $st = $db->prepare("SELECT y.*, a.account_code, a.account_name, pr.ref_no AS progress_ref, cu.login_name AS created_by_name, xu.login_name AS cancelled_by_name
                                  FROM subcon_payments y JOIN public.chart_of_accounts a ON a.id = y.account_id
                                  LEFT JOIN subcon_progress pr ON pr.id = y.progress_id
                                  LEFT JOIN public.m_user_system cu ON cu.id = y.created_by LEFT JOIN public.m_user_system xu ON xu.id = y.cancelled_by
                                 WHERE y.contract_id = :id ORDER BY y.id");
            $st->execute([':id' => $id]);
            $payments = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($payments as &$y) { $y['id'] = (int)$y['id']; $y['amount'] = (float)$y['amount']; }
            unset($y);

            $st = $db->prepare("SELECT j.ref_no, j.journal_date, j.reference_type::text AS reference_type, j.reference_id
                                  FROM journal_entries j
                                 WHERE (j.reference_type::text = :rp AND j.reference_id IN (SELECT id FROM subcon_progress WHERE contract_id = :c))
                                    OR (j.reference_type::text = :ry AND j.reference_id IN (SELECT id FROM subcon_payments WHERE contract_id = :c))
                                 ORDER BY j.id");
            $st->execute([':rp' => SC_REF_PROGRESS, ':ry' => SC_REF_PAYMENT, ':c' => $id]);
            $journals = $st->fetchAll(PDO::FETCH_ASSOC);

            $tot = sc_totals($db, $id);
            $tot['billed_pct'] = $c['contract_value'] > 0 ? round($tot['gross'] / $c['contract_value'] * 100, 2) : 0;
            $tot['dp_planned'] = sc_f($c['contract_value'] * $c['dp_pct'] / 100);

            $allowed = SC_TRANSITIONS[$hdr['status']] ?? [];
            if ($tot['gross'] > 0 || $tot['dp_paid'] > 0) $allowed = array_values(array_diff($allowed, ['Dibatalkan', 'Draft']));
            sc_send(true, ['header' => $hdr, 'tasks' => $tasks, 'progress' => $progress, 'inspections' => $inspections, 'payments' => $payments, 'journals' => $journals,
                           'totals' => $tot, 'allowed_actions' => $allowed]);
            break;

        /* ------------------------------------------------------------------ simpan SPK (baru / ubah Draft) */
        case 'save_contract':
            $id   = (int)($_POST['id'] ?? 0);
            $prj  = (int)($_POST['project_id'] ?? 0);
            $sub  = (int)($_POST['subcontractor_id'] ?? 0);
            $title = trim((string)($_POST['title'] ?? ''));
            $val  = sc_f($_POST['contract_value'] ?? 0);
            $ppn  = sc_pct($_POST['ppn_rate'] ?? 0, 'Tarif PPN');
            $pph  = sc_pct($_POST['pph_rate'] ?? 0, 'Tarif PPh');
            $ret  = sc_pct($_POST['retention_pct'] ?? 0, 'Retensi');
            $dp   = sc_pct($_POST['dp_pct'] ?? 0, 'Uang muka');
            $bid  = (int)($_POST['budget_item_id'] ?? 0) ?: null;
            $sd   = trim((string)($_POST['start_date'] ?? '')); $ed = trim((string)($_POST['end_date'] ?? ''));
            $scope = trim((string)($_POST['scope'] ?? '')) ?: null; $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            $rowsIn = json_decode($_POST['tasks'] ?? '[]', true);
            $spkId = (int)($_POST['spk_id'] ?? 0) ?: null;      // tautan ke SPK bertipe SUBCON (opsional)
            if ($prj <= 0) throw new ScError('Proyek wajib dipilih.');
            if ($sub <= 0) throw new ScError('Subkontraktor wajib dipilih.');
            if ($title === '') throw new ScError('Judul / lingkup pekerjaan wajib diisi.');
            if ($val <= 0) throw new ScError('Nilai kontrak harus lebih besar dari 0.');
            if ($sd !== '') sc_date($sd, 'Tanggal mulai'); if ($ed !== '') sc_date($ed, 'Tanggal selesai');
            if ($sd !== '' && $ed !== '' && $ed < $sd) throw new ScError('Tanggal selesai tidak boleh lebih awal dari tanggal mulai.');
            if (!is_array($rowsIn)) $rowsIn = [];

            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT 1 FROM public.projects WHERE id = :id"); $st->execute([':id' => $prj]);
                if (!$st->fetchColumn()) throw new ScError('Proyek tidak ditemukan.');
                $st = $db->prepare("SELECT 1 FROM subcontractors WHERE id = :id AND active"); $st->execute([':id' => $sub]);
                if (!$st->fetchColumn()) throw new ScError('Subkontraktor tidak ditemukan / tidak aktif.');

                // tautan ke SPK subkon: tipe SUBCON, proyek & subkontraktor sama, masih aktif, belum dipakai kontrak lain
                if ($spkId) {
                    if (!sc_has_spk_link($db)) throw new ScError('Tautan ke SPK belum aktif: jalankan database/subcontract_management_ddl.sql di database ini.');
                    $st = $db->prepare("SELECT s.spk_no, s.spk_type, s.project_id, s.execution_subcontractor_id, s.status,
                                               (SELECT c.id FROM subcon_contracts c WHERE c.spk_id = s.id AND c.id <> :x LIMIT 1) AS other_contract
                                          FROM spk s WHERE s.id = :i");
                    $st->execute([':i' => $spkId, ':x' => $id]);
                    $sp = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$sp) throw new ScError('SPK yang dipilih tidak ditemukan.');
                    if ($sp['spk_type'] !== 'SUBCON') throw new ScError('Kontrak subkon hanya dapat ditautkan ke SPK bertipe Subkon.');
                    if ((int)$sp['project_id'] !== $prj || (int)$sp['execution_subcontractor_id'] !== $sub) {
                        throw new ScError("SPK {$sp['spk_no']} dibuat untuk proyek / subkontraktor yang berbeda dari kontrak ini.");
                    }
                    if (!in_array($sp['status'], ['Draft', 'Diterbitkan'], true)) throw new ScError("SPK {$sp['spk_no']} berstatus {$sp['status']}, tidak dapat ditautkan.");
                    if ($sp['other_contract']) throw new ScError("SPK {$sp['spk_no']} sudah terhubung ke kontrak subkon lain.");
                }

                // pembagian ke task proyek (kosong = seluruh pekerjaan satu baris)
                $clean = []; $seen = []; $sum = 0.0;
                if (!count($rowsIn)) $rowsIn = [['task_id' => 0, 'task_name' => $title, 'task_value' => $val]];
                $stT = $db->prepare("SELECT task_name FROM public.project_tasks WHERE id = :t AND project_id = :p");
                // pemetaan task -> item lingkup SPK (opsional): nilai task = jumlah item lingkup, klaim memakai qty + inspeksi
                $wiMap = []; $seenWi = [];
                $anyWi = false; foreach ($rowsIn as $r0) { if ((int)($r0['work_item_id'] ?? 0) > 0) $anyWi = true; }
                if ($anyWi) {
                    if (!$spkId) throw new ScError('Untuk menggunakan rincian volume (qty & harga satuan per item), pilih dulu "Tautan SPK Subkon" di form. Pastikan SPK tersebut sudah diisi lingkup pekerjaannya (menu SPK → Atur Lingkup Pekerjaan).');
                    if (!sc_has_qty($db)) throw new ScError('Lingkup pekerjaan belum aktif: jalankan database/subcontract_management_ddl.sql di database ini.');
                    $st = $db->prepare("SELECT id, description, line_amount, project_task_id, status FROM spk_work_items WHERE spk_id = :s");
                    $st->execute([':s' => $spkId]);
                    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $w) $wiMap[(int)$w['id']] = $w;
                }
                foreach ($rowsIn as $n => $r) {
                    $tid = (int)($r['task_id'] ?? 0) ?: null;
                    $tv  = sc_f($r['task_value'] ?? 0);
                    $no  = 'Baris task ' . ($n + 1);
                    $wid = (int)($r['work_item_id'] ?? 0) ?: null; $wRow = null;
                    if ($wid) {
                        if (!isset($wiMap[$wid])) throw new ScError("{$no}: item lingkup bukan bagian dari SPK yang ditautkan.");
                        if (isset($seenWi[$wid])) throw new ScError("{$no}: item lingkup yang sama dipilih dua kali.");
                        $seenWi[$wid] = true; $wRow = $wiMap[$wid];
                        if ($wRow['status'] !== 'ACTIVE') throw new ScError("{$no}: item lingkup berstatus {$wRow['status']}, tidak dapat dipakai.");
                        $tv  = sc_f($wRow['line_amount']);                                   // nilai task selalu = jumlah item lingkup
                        $tid = $wRow['project_task_id'] !== null ? (int)$wRow['project_task_id'] : $tid;
                    }
                    if ($tv <= 0) throw new ScError("{$no}: nilai harus lebih besar dari 0.");
                    if ($tid) {
                        if (!$wid) {                                                        // beberapa item lingkup boleh berbagi satu task proyek
                            if (isset($seen[$tid])) throw new ScError("{$no}: task yang sama dipilih dua kali.");
                            $seen[$tid] = true;
                        }
                        $stT->execute([':t' => $tid, ':p' => $prj]);
                        $tn = $stT->fetchColumn();
                        if ($tn === false) throw new ScError("{$no}: task bukan milik proyek ini.");
                    } else {
                        $tn = trim((string)($r['task_name'] ?? '')) ?: $title;
                    }
                    if ($wRow) { $tn = $wRow['description']; }
                    $sum += $tv;
                    $clean[] = ['tid' => $tid, 'name' => $tn, 'val' => $tv, 'wid' => $wid, 'notes' => trim((string)($r['notes'] ?? '')) ?: null];
                }
                if (abs($sum - $val) > SC_EPS) {
                    throw new ScError('Total pembagian task (' . number_format($sum, 2) . ') harus sama dengan nilai kontrak (' . number_format($val, 2) . ').');
                }

                // batas anggaran RAB (bila SPK diikat ke baris RAB)
                if ($bid) {
                    $st = $db->prepare("SELECT project_id, COALESCE(qty_plan,0) * COALESCE(unit_price_plan,0) AS bv, item_name FROM public.budget_items WHERE id = :id");
                    $st->execute([':id' => $bid]);
                    $bi = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$bi || (int)$bi['project_id'] !== $prj) throw new ScError('Baris RAB tidak ditemukan pada proyek ini.');
                    $st = $db->prepare("SELECT COALESCE(SUM(contract_value),0) FROM subcon_contracts WHERE budget_item_id = :b AND status <> 'Dibatalkan' AND id <> :x");
                    $st->execute([':b' => $bid, ':x' => $id]);
                    $committed = (float)$st->fetchColumn();
                    if ($committed + $val > (float)$bi['bv'] + SC_EPS) {
                        throw new ScError('Nilai SPK melebihi sisa anggaran RAB "' . $bi['item_name'] . '" (anggaran ' . number_format((float)$bi['bv'], 2) .
                                          ', sudah dikomitmen SPK lain ' . number_format($committed, 2) . ').');
                    }
                }

                $v = [':p' => $prj, ':s' => $sub, ':t' => $title, ':sc' => $scope, ':b' => $bid, ':val' => $val, ':ppn' => $ppn, ':pph' => $pph,
                      ':ret' => $ret, ':dp' => $dp, ':sd' => $sd !== '' ? $sd : null, ':ed' => $ed !== '' ? $ed : null, ':n' => $notes];
                if ($id > 0) {
                    $c0 = sc_contract($db, $id, true);
                    if ($c0['status'] !== 'Draft') throw new ScError('Hanya SPK berstatus Draft yang dapat diubah.');
                    $v[':id'] = $id;
                    $db->prepare("UPDATE subcon_contracts SET project_id=:p, subcontractor_id=:s, title=:t, scope=:sc, budget_item_id=:b, contract_value=:val,
                                         ppn_rate=:ppn, pph_rate=:pph, retention_pct=:ret, dp_pct=:dp, start_date=CAST(:sd AS date), end_date=CAST(:ed AS date),
                                         notes=:n, updated_at=NOW() WHERE id=:id")->execute($v);
                    $db->prepare("DELETE FROM subcon_contract_tasks WHERE contract_id = :id")->execute([':id' => $id]);
                    $spk = $c0['spk_no'];
                } else {
                    if (!function_exists('CreateRefNo')) throw new Exception('CreateRefNo() tidak tersedia.');
                    $spk = CreateRefNo('subcon_contracts', 'spk_no', 'SPK');
                    $v[':no'] = $spk; $v[':cb'] = sc_uid();
                    $st = $db->prepare("INSERT INTO subcon_contracts (spk_no, project_id, subcontractor_id, title, scope, budget_item_id, contract_value, ppn_rate, pph_rate,
                                               retention_pct, dp_pct, start_date, end_date, notes, status, created_by)
                                        VALUES (:no,:p,:s,:t,:sc,:b,:val,:ppn,:pph,:ret,:dp,CAST(:sd AS date),CAST(:ed AS date),:n,'Draft',:cb) RETURNING id");
                    $st->execute($v);
                    $id = (int)$st->fetchColumn();
                }
                if (sc_has_spk_link($db)) {
                    $db->prepare("UPDATE subcon_contracts SET spk_id = CAST(:spk AS integer) WHERE id = :id")->execute([':spk' => $spkId, ':id' => $id]);
                }
                if (sc_has_qty($db)) {
                    $stI = $db->prepare("INSERT INTO subcon_contract_tasks (contract_id, task_id, task_name, task_value, notes, work_item_id) VALUES (:c,:t,:n,:v,:no,CAST(:w AS bigint))");
                    foreach ($clean as $r) $stI->execute([':c' => $id, ':t' => $r['tid'], ':n' => $r['name'], ':v' => $r['val'], ':no' => $r['notes'], ':w' => $r['wid']]);
                } else {
                    $stI = $db->prepare("INSERT INTO subcon_contract_tasks (contract_id, task_id, task_name, task_value, notes) VALUES (:c,:t,:n,:v,:no)");
                    foreach ($clean as $r) $stI->execute([':c' => $id, ':t' => $r['tid'], ':n' => $r['name'], ':v' => $r['val'], ':no' => $r['notes']]);
                }
                $db->commit();
                sc_send(true, ['id' => $id, 'spk_no' => $spk], "SPK {$spk} berhasil disimpan.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        /* ------------------------------------------------------------------ ubah status SPK */
        case 'change_status':
            $id = (int)($_POST['id'] ?? 0);
            $to = (string)($_POST['to'] ?? '');
            $reason = trim((string)($_POST['reason'] ?? ''));
            $db->beginTransaction();
            try {
                $c = sc_contract($db, $id, true);
                if (!in_array($to, SC_TRANSITIONS[$c['status']] ?? [], true)) throw new ScError("Status {$c['status']} tidak dapat diubah ke {$to}.");
                $tot = sc_totals($db, $id);
                $st = $db->prepare("SELECT COUNT(*) FROM subcon_progress WHERE contract_id = :c AND status <> 'Dibatalkan'");
                $st->execute([':c' => $id]);
                $nProgress = (int)$st->fetchColumn();
                $st = $db->prepare("SELECT COUNT(*) FROM subcon_payments WHERE contract_id = :c AND status = 'Posted'");
                $st->execute([':c' => $id]);
                $nPay = (int)$st->fetchColumn();

                if (($to === 'Dibatalkan' || $to === 'Draft') && ($nProgress > 0 || $nPay > 0)) {
                    throw new ScError('SPK ini sudah punya prestasi / pembayaran, tidak dapat dibatalkan / dikembalikan ke Draft. Batalkan dokumen turunannya dulu.');
                }
                $set = "status = :s, updated_at = NOW()"; $p = [':s' => $to, ':id' => $id];
                if ($to === 'Dibatalkan') {
                    if (strlen($reason) < 5) throw new ScError('Alasan pembatalan wajib diisi (minimal 5 karakter).');
                    $set .= ", cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r"; $p[':u'] = sc_uid(); $p[':r'] = $reason;
                    // lepas tautan SPK (indeks unik uq_subcon_contract_spk) agar SPK itu bisa dipakai kontrak lain; jejaknya masuk catatan
                    if (!empty($c['spk_id'])) {
                        $st = $db->prepare("SELECT spk_no FROM spk WHERE id = :i"); $st->execute([':i' => (int)$c['spk_id']]);
                        $oldSpk = (string)$st->fetchColumn();
                        $set .= ", spk_id = NULL, notes = TRIM(BOTH E'\\n' FROM COALESCE(notes, '') || E'\\n' || :ln)";
                        $p[':ln'] = 'Tautan ke SPK ' . $oldSpk . ' dilepas saat kontrak dibatalkan.';
                    }
                } elseif ($to === 'Disetujui') {
                    if (SC_REQUIRE_OTHER_APPROVER && (int)$c['created_by'] === (int)sc_uid()) throw new ScError('Penyetuju harus user lain selain pembuat SPK.');
                    $set .= ", approved_by = :u, approved_at = NOW()"; $p[':u'] = sc_uid();
                } elseif ($to === 'Selesai') {
                    $st = $db->prepare("SELECT COUNT(*) FROM subcon_progress WHERE contract_id = :c AND status = 'Draft'");
                    $st->execute([':c' => $id]);
                    if ((int)$st->fetchColumn() > 0) throw new ScError('Masih ada prestasi berstatus Draft. Setujui atau batalkan dulu.');
                    if ($tot['gross'] < $c['contract_value'] - SC_EPS) throw new ScError('Progres SPK belum 100% (tertagih ' . number_format($tot['gross'], 2) . ' dari ' . number_format($c['contract_value'], 2) . ').');
                    if ($tot['dp_balance'] > SC_EPS) throw new ScError('Uang muka subkon belum seluruhnya dipotong (sisa ' . number_format($tot['dp_balance'], 2) . ').');
                    $set .= ", closed_by = :u, closed_at = NOW()"; $p[':u'] = sc_uid();
                } elseif ($to === 'Diajukan') {
                    $st = $db->prepare("SELECT COUNT(*) FROM subcon_contract_tasks WHERE contract_id = :c");
                    $st->execute([':c' => $id]);
                    if ((int)$st->fetchColumn() === 0) throw new ScError('Kontrak subkon belum memiliki pembagian task. Edit kontrak dan tambahkan minimal satu baris task (atau gunakan "Isi dari Lingkup SPK").');
                }
                $db->prepare("UPDATE subcon_contracts SET {$set} WHERE id = :id")->execute($p);
                $db->commit();
                sc_send(true, [], "SPK {$c['spk_no']} sekarang berstatus {$to}.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'delete_contract':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $c = sc_contract($db, $id, true);
                if ($c['status'] !== 'Draft') throw new ScError('Hanya SPK berstatus Draft yang dapat dihapus. Gunakan Batalkan untuk SPK lain.');
                $st = $db->prepare("SELECT COUNT(*) FROM subcon_progress WHERE contract_id = :c"); $st->execute([':c' => $id]);
                if ((int)$st->fetchColumn() > 0) throw new ScError('SPK sudah memiliki prestasi, tidak dapat dihapus.');
                $db->prepare("DELETE FROM subcon_contract_tasks WHERE contract_id = :id")->execute([':id' => $id]);
                $db->prepare("DELETE FROM subcon_contracts WHERE id = :id")->execute([':id' => $id]);
                $db->commit();
                sc_send(true, [], "SPK {$c['spk_no']} dihapus.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        /* ------------------------------------------------------------------ prestasi */
        // Data awal dialog prestasi: baris task + progres sebelumnya + parameter potongan
        case 'progress_prepare':
            $cid = (int)($_REQUEST['contract_id'] ?? 0);
            $pid = (int)($_REQUEST['progress_id'] ?? 0);
            $c = sc_contract($db, $cid);
            $prev = sc_prev_map($db, $cid, $pid);
            $st = $db->prepare("SELECT id, task_name, task_value FROM subcon_contract_tasks WHERE contract_id = :c ORDER BY id");
            $st->execute([':c' => $cid]);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);
            $cur = [];
            if ($pid > 0) {
                $st = $db->prepare("SELECT contract_task_id, cum_pct FROM subcon_progress_dtl WHERE progress_id = :p");
                $st->execute([':p' => $pid]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $cur[(int)$r['contract_task_id']] = (float)$r['cum_pct'];
            }
            $wm = sc_has_qty($db) ? sc_task_work_map($db, $cid) : [];
            $acc = sc_has_qty($db) ? sc_accepted_map($db, $cid, $pid) : [];
            $curQty = [];
            if ($pid > 0 && count($wm)) {
                $st = $db->prepare("SELECT contract_task_id, qty_claimed FROM subcon_progress_qty_items WHERE progress_id = :p");
                $st->execute([':p' => $pid]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $curQty[(int)$r['contract_task_id']] = (float)$r['qty_claimed'];
            }
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['task_value'] = (float)$r['task_value'];
                $r['prev_pct'] = isset($prev[$r['id']]) ? $prev[$r['id']] : 0.0;
                $r['cum_pct'] = isset($cur[$r['id']]) ? $cur[$r['id']] : $r['prev_pct'];
                $r['qty_mode'] = false; $r['unit'] = null; $r['contract_qty'] = null; $r['unit_price'] = null; $r['accepted_qty'] = null; $r['remaining_qty'] = null; $r['qty_claimed'] = 0.0;
                if (isset($wm[$r['id']])) {
                    $w = $wm[$r['id']]; $a = isset($acc[$w['work_item_id']]) ? $acc[$w['work_item_id']] : 0.0;
                    $r['qty_mode'] = true; $r['unit'] = $w['unit']; $r['contract_qty'] = $w['contract_qty']; $r['unit_price'] = $w['unit_price'];
                    $r['accepted_qty'] = $a; $r['remaining_qty'] = round($w['contract_qty'] - $a, 4);
                    $r['qty_claimed'] = isset($curQty[$r['id']]) ? $curQty[$r['id']] : 0.0;
                }
            }
            unset($r);
            $tot = sc_totals($db, $cid);
            sc_send(true, $rows, '', ['params' => ['contract_value' => $c['contract_value'], 'ppn_rate' => $c['ppn_rate'], 'pph_rate' => $c['pph_rate'],
                       'retention_pct' => $c['retention_pct'], 'dp_pct' => $c['dp_pct'], 'dp_balance' => $tot['dp_balance'], 'status' => $c['status']]]);
            break;

        case 'save_progress':
            $id   = (int)($_POST['id'] ?? 0);
            $cid  = (int)($_POST['contract_id'] ?? 0);
            $date = sc_date((string)($_POST['progress_date'] ?? ''), 'Tanggal prestasi');
            $evid = trim((string)($_POST['evidence_ref'] ?? '')) ?: null;
            $remark = trim((string)($_POST['remark'] ?? '')) ?: null;
            $rowsIn = json_decode($_POST['details'] ?? '[]', true);
            if (!is_array($rowsIn) || !count($rowsIn)) throw new ScError('Isi progres minimal satu task.');
            $db->beginTransaction();
            try {
                $c = sc_contract($db, $cid, true);
                if ($c['status'] !== 'Disetujui') throw new ScError('Prestasi hanya dapat dibuat untuk SPK berstatus Disetujui.');
                $st = $db->prepare("SELECT id FROM subcon_progress WHERE contract_id = :c AND status = 'Draft' AND id <> :x LIMIT 1");
                $st->execute([':c' => $cid, ':x' => $id]);
                if ($st->fetchColumn()) throw new ScError('Masih ada prestasi Draft pada SPK ini. Setujui atau batalkan dulu.');
                if ($id > 0) {
                    $st = $db->prepare("SELECT status, ref_no, contract_id FROM subcon_progress WHERE id = :id FOR UPDATE");
                    $st->execute([':id' => $id]);
                    $p0 = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$p0 || (int)$p0['contract_id'] !== $cid) throw new ScError('Prestasi tidak ditemukan.');
                    if ($p0['status'] !== 'Draft') throw new ScError('Hanya prestasi berstatus Draft yang dapat diubah.');
                    if (sc_has_qty($db)) {
                        $st = $db->prepare("SELECT 1 FROM subcon_inspections WHERE progress_id = :p AND status IN ('DRAFT', 'APPROVED') LIMIT 1");
                        $st->execute([':p' => $id]);
                        if ($st->fetchColumn()) throw new ScError('Prestasi ini sudah memiliki inspeksi aktif. Batalkan inspeksinya dulu sebelum mengubah klaim.');
                    }
                }
                $prev = sc_prev_map($db, $cid, $id);
                $wmap = sc_has_qty($db) ? sc_task_work_map($db, $cid) : [];
                $accQ = sc_has_qty($db) ? sc_accepted_map($db, $cid, $id) : [];
                $qtyLines = [];
                $st = $db->prepare("SELECT id, task_value FROM subcon_contract_tasks WHERE contract_id = :c");
                $st->execute([':c' => $cid]);
                $taskVal = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $taskVal[(int)$t['id']] = (float)$t['task_value'];

                $dtl = []; $gross = 0.0; $weighted = 0.0; $changed = false;
                foreach ($rowsIn as $r) {
                    $tid = (int)($r['contract_task_id'] ?? 0);
                    if (!isset($taskVal[$tid])) throw new ScError('Task prestasi tidak termasuk dalam SPK ini.');
                    if (isset($wmap[$tid])) {                                       // task terpetakan ke item lingkup: klaim dalam QTY
                        $w = $wmap[$tid]; $cq = $w['contract_qty'];
                        $q = round((float)($r['qty_claimed'] ?? 0), 4);
                        $prior = isset($accQ[$w['work_item_id']]) ? $accQ[$w['work_item_id']] : 0.0;
                        $pv = isset($prev[$tid]) ? $prev[$tid] : 0.0;
                        if ($q < 0) throw new ScError('Qty klaim "' . $w['task_name'] . '" tidak boleh negatif.');
                        if ($q > $cq - $prior + 0.00005) {
                            throw new ScError('Qty klaim "' . $w['task_name'] . '" (' . $q . ') melebihi sisa qty kontrak (' . round($cq - $prior, 4) . ' ' . $w['unit'] . ').');
                        }
                        if ($q > 0) {
                            $cum = min(100.0, round(($prior + $q) / $cq * 100, 3));
                            $amt = round($q * $w['unit_price'], 2);
                            $changed = true;
                            $qtyLines[$tid] = ['wid' => $w['work_item_id'], 'qty' => $q, 'price' => $w['unit_price'], 'amt' => $amt];
                        } else { $cum = $pv; $amt = 0.0; }
                        $gross += $amt;
                        $dtl[$tid] = ['prev' => $pv, 'cum' => $cum, 'amt' => $amt];
                        continue;
                    }
                    $cum = round((float)($r['cum_pct'] ?? 0), 3);
                    $pv  = isset($prev[$tid]) ? $prev[$tid] : 0.0;
                    if ($cum > 100 + 0.0005) throw new ScError('Progres kumulatif tidak boleh lebih dari 100%.');
                    if ($cum < $pv - 0.0005) throw new ScError('Progres kumulatif tidak boleh lebih kecil dari progres sebelumnya (' . $pv . '%).');
                    $amt = round($taskVal[$tid] * ($cum - $pv) / 100, 2);
                    if ($cum > $pv + 0.0005) $changed = true;
                    $gross += $amt;
                    $dtl[$tid] = ['prev' => $pv, 'cum' => $cum, 'amt' => $amt];
                }
                // task yang tidak dikirim tetap pada progres sebelumnya
                foreach ($taskVal as $tid => $tv) {
                    if (!isset($dtl[$tid])) { $pv = isset($prev[$tid]) ? $prev[$tid] : 0.0; $dtl[$tid] = ['prev' => $pv, 'cum' => $pv, 'amt' => 0.0]; }
                    $weighted += $tv * $dtl[$tid]['cum'] / 100;
                }
                if (!$changed || $gross <= 0) throw new ScError('Tidak ada kenaikan progres pada prestasi ini.');
                $gross = sc_f($gross);
                $cumPct = $c['contract_value'] > 0 ? round($weighted / $c['contract_value'] * 100, 3) : 0;
                $tot = sc_totals($db, $cid);
                $calc = sc_calc($c, $gross, $cumPct, $tot['dp_balance']);

                if ($id > 0) {
                    $db->prepare("UPDATE subcon_progress SET progress_date = CAST(:d AS date), gross_amount=:g, ppn_amount=:ppn, pph_amount=:pph, retention_amount=:ret,
                                         dp_recovery=:dp, net_payable=:net, cum_pct=:cp, evidence_ref=:e, remark=:r, updated_at=NOW() WHERE id=:id")
                       ->execute([':d' => $date, ':g' => $gross, ':ppn' => $calc['ppn'], ':pph' => $calc['pph'], ':ret' => $calc['retention'], ':dp' => $calc['dp_recovery'],
                                  ':net' => $calc['net'], ':cp' => $cumPct, ':e' => $evid, ':r' => $remark, ':id' => $id]);
                    $db->prepare("DELETE FROM subcon_progress_dtl WHERE progress_id = :id")->execute([':id' => $id]);
                    $ref = $p0['ref_no'];
                } else {
                    $ref = CreateRefNo('subcon_progress', 'ref_no', 'SKP');
                    $st = $db->prepare("INSERT INTO subcon_progress (ref_no, contract_id, progress_date, status, gross_amount, ppn_amount, pph_amount, retention_amount,
                                               dp_recovery, net_payable, cum_pct, evidence_ref, remark, created_by)
                                        VALUES (:no,:c,CAST(:d AS date),'Draft',:g,:ppn,:pph,:ret,:dp,:net,:cp,:e,:r,:u) RETURNING id");
                    $st->execute([':no' => $ref, ':c' => $cid, ':d' => $date, ':g' => $gross, ':ppn' => $calc['ppn'], ':pph' => $calc['pph'], ':ret' => $calc['retention'],
                                  ':dp' => $calc['dp_recovery'], ':net' => $calc['net'], ':cp' => $cumPct, ':e' => $evid, ':r' => $remark, ':u' => sc_uid()]);
                    $id = (int)$st->fetchColumn();
                }
                $stI = $db->prepare("INSERT INTO subcon_progress_dtl (progress_id, contract_task_id, prev_pct, cum_pct, amount) VALUES (:p,:t,:pv,:c,:a)");
                foreach ($dtl as $tid => $d) $stI->execute([':p' => $id, ':t' => $tid, ':pv' => $d['prev'], ':c' => $d['cum'], ':a' => $d['amt']]);
                if (sc_has_qty($db)) {                                              // simpan baris klaim kuantitas (update di tempat agar riwayat inspeksi tetap utuh)
                    $st = $db->prepare("SELECT id, contract_task_id FROM subcon_progress_qty_items WHERE progress_id = :p");
                    $st->execute([':p' => $id]);
                    $have = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $have[(int)$r['contract_task_id']] = (int)$r['id'];
                    $stU = $db->prepare("UPDATE subcon_progress_qty_items SET qty_claimed = :q, unit_price_snapshot = :u, amount_claimed = :a WHERE id = :id");
                    $stN = $db->prepare("INSERT INTO subcon_progress_qty_items (progress_id, contract_task_id, work_item_id, qty_claimed, unit_price_snapshot, amount_claimed) VALUES (:p,:t,:w,:q,:u,:a)");
                    foreach ($qtyLines as $tid => $ql) {
                        if (isset($have[$tid])) { $stU->execute([':q' => $ql['qty'], ':u' => $ql['price'], ':a' => $ql['amt'], ':id' => $have[$tid]]); unset($have[$tid]); }
                        else { $stN->execute([':p' => $id, ':t' => $tid, ':w' => $ql['wid'], ':q' => $ql['qty'], ':u' => $ql['price'], ':a' => $ql['amt']]); }
                    }
                    foreach ($have as $tid => $qid) {
                        $st = $db->prepare("SELECT COUNT(*) FROM subcon_inspection_items WHERE progress_qty_item_id = :i"); $st->execute([':i' => $qid]);
                        if ((int)$st->fetchColumn() > 0) throw new ScError('Baris klaim yang sudah pernah diperiksa tidak dapat dihapus; ubah qty-nya saja.');
                        $db->prepare("DELETE FROM subcon_progress_qty_items WHERE id = :i")->execute([':i' => $qid]);
                    }
                }
                $db->commit();
                sc_send(true, ['id' => $id, 'ref_no' => $ref, 'calc' => $calc, 'gross' => $gross, 'needs_inspection' => count($qtyLines) > 0],
                        "Prestasi {$ref} tersimpan (Draft)." . (count($qtyLines) ? ' Lanjutkan dengan inspeksi: hanya qty yang lolos yang ditagihkan.' : ''));
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'approve_progress':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                sc_assert_ref_types($db);
                $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new ScError('Prestasi tidak ditemukan.');
                if ($p['status'] !== 'Draft') throw new ScError('Hanya prestasi berstatus Draft yang dapat disetujui.');
                $c = sc_contract($db, (int)$p['contract_id'], true);
                if ($c['status'] !== 'Disetujui') throw new ScError('SPK tidak berstatus Disetujui.');
                if (SC_REQUIRE_OTHER_APPROVER && (int)$p['created_by'] === (int)sc_uid()) throw new ScError('Penyetuju harus user lain selain pembuat prestasi.');

                // prestasi berbasis kuantitas: wajib sudah diinspeksi & disetujui; nilai dihitung dari qty LOLOS
                if (sc_has_qty($db)) {
                    $st = $db->prepare("SELECT COUNT(*) FROM subcon_progress_qty_items WHERE progress_id = :p");
                    $st->execute([':p' => $id]);
                    if ((int)$st->fetchColumn() > 0) {
                        $st = $db->prepare("SELECT 1 FROM subcon_inspections WHERE progress_id = :p AND status = 'APPROVED' LIMIT 1");
                        $st->execute([':p' => $id]);
                        if (!$st->fetchColumn()) throw new ScError('Prestasi ini memiliki klaim kuantitas yang belum disetujui inspeksinya. Langkah: (1) Klik "Inspeksi" di baris prestasi, (2) Isi form inspeksi dan simpan, (3) Setujui inspeksi di tabel "Inspeksi Kuantitas", (4) Baru setujui prestasi ini.');
                        sc_recompute_progress($db, $c, $id, true);
                        $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id");
                        $st->execute([':id' => $id]);
                        $p = $st->fetch(PDO::FETCH_ASSOC);
                        if ((float)$p['gross_amount'] <= 0) throw new ScError('Tidak ada qty yang lolos inspeksi pada prestasi ini. Batalkan prestasi lalu buat klaim baru setelah perbaikan.');
                    }
                }

                // progres sebelumnya harus masih sama dengan saat prestasi dibuat; hitung ulang potongan dengan sisa uang muka terkini
                $prev = sc_prev_map($db, (int)$c['id'], $id);
                $st = $db->prepare("SELECT d.contract_task_id, d.prev_pct, d.cum_pct, d.amount, t.task_name FROM subcon_progress_dtl d
                                      JOIN subcon_contract_tasks t ON t.id = d.contract_task_id WHERE d.progress_id = :p");
                $st->execute([':p' => $id]);
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) {
                    $pv = isset($prev[(int)$d['contract_task_id']]) ? $prev[(int)$d['contract_task_id']] : 0.0;
                    if (abs($pv - (float)$d['prev_pct']) > 0.0005) throw new ScError('Progres sebelumnya untuk "' . $d['task_name'] . '" berubah sejak prestasi dibuat. Edit ulang prestasi ini.');
                }
                $tot = sc_totals($db, (int)$c['id']);
                $calc = sc_calc($c, (float)$p['gross_amount'], (float)$p['cum_pct'], $tot['dp_balance']);
                $db->prepare("UPDATE subcon_progress SET ppn_amount=:ppn, pph_amount=:pph, retention_amount=:ret, dp_recovery=:dp, net_payable=:net,
                                     status='Disetujui', approved_by=:u, approved_at=NOW() WHERE id=:id")
                   ->execute([':ppn' => $calc['ppn'], ':pph' => $calc['pph'], ':ret' => $calc['retention'], ':dp' => $calc['dp_recovery'], ':net' => $calc['net'], ':u' => sc_uid(), ':id' => $id]);

                $date = $p['progress_date']; $d0 = " {$p['ref_no']} - {$c['spk_no']}";
                sc_post($db, 'SUBCON_WORK',        SC_REF_PROGRESS, $id, $date, (float)$p['gross_amount'], 'Prestasi subkon' . $d0);
                sc_post($db, 'SUBCON_PPN',         SC_REF_PROGRESS, $id, $date, $calc['ppn'],         'PPN Masukan prestasi' . $d0);
                sc_post($db, 'SUBCON_PPH',         SC_REF_PROGRESS, $id, $date, $calc['pph'],         'PPh final dipotong' . $d0);
                sc_post($db, 'SUBCON_RETENTION',   SC_REF_PROGRESS, $id, $date, $calc['retention'],   'Retensi ditahan' . $d0);
                sc_post($db, 'SUBCON_DP_RECOVERY', SC_REF_PROGRESS, $id, $date, $calc['dp_recovery'], 'Potongan uang muka' . $d0);
                $db->commit();
                sc_send(true, ['calc' => $calc], "Prestasi {$p['ref_no']} disetujui & dijurnal. Nilai bayar " . number_format($calc['net'], 2) . '.');
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'cancel_progress':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 5) throw new ScError('Alasan pembatalan wajib diisi (minimal 5 karakter).');
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new ScError('Prestasi tidak ditemukan.');
                if ($p['status'] === 'Dibatalkan') throw new ScError('Prestasi sudah dibatalkan.');
                $c = sc_contract($db, (int)$p['contract_id'], true);
                if ($p['status'] === 'Disetujui') {
                    if ($c['status'] === 'Selesai') throw new ScError('SPK sudah Selesai, prestasi tidak dapat dibatalkan.');
                    $st = $db->prepare("SELECT COUNT(*) FROM subcon_payments WHERE progress_id = :p AND status = 'Posted'");
                    $st->execute([':p' => $id]);
                    if ((int)$st->fetchColumn() > 0) throw new ScError('Prestasi sudah ada pembayaran. Batalkan pembayarannya dulu.');
                    $st = $db->prepare("SELECT MAX(id) FROM subcon_progress WHERE contract_id = :c AND status = 'Disetujui'");
                    $st->execute([':c' => (int)$c['id']]);
                    if ((int)$st->fetchColumn() !== $id) throw new ScError('Hanya prestasi disetujui yang TERAKHIR yang dapat dibatalkan (progres periode berikutnya bergantung padanya).');
                    if (sc_has_journal($db, SC_REF_PROGRESS, $id)) ReverseJournalByReference($db, SC_REF_PROGRESS, $id);
                }
                if (sc_has_qty($db) && $p['status'] === 'Draft') {
                    $db->prepare("UPDATE subcon_inspections SET status = 'CANCELLED', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = 'Prestasi dibatalkan'
                                   WHERE progress_id = :p AND status IN ('DRAFT', 'APPROVED')")->execute([':u' => sc_uid(), ':p' => $id]);
                }
                $db->prepare("UPDATE subcon_progress SET status='Dibatalkan', cancelled_by=:u, cancelled_at=NOW(), cancel_reason=:r WHERE id=:id")
                   ->execute([':u' => sc_uid(), ':r' => $reason, ':id' => $id]);
                $db->commit();
                sc_send(true, [], "Prestasi {$p['ref_no']} dibatalkan.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        /* ------------------------------------------------------------------ inspeksi (Langkah 2) */
        case 'inspection_prepare':
            // data untuk dialog inspeksi: baris klaim kuantitas satu prestasi + isi inspeksi aktif (bila ada)
            $pid = (int)($_REQUEST['progress_id'] ?? 0);
            if (!sc_has_qty($db)) throw new ScError('Fitur inspeksi belum aktif: jalankan database/subcontract_management_ddl.sql di database ini.');
            $st = $db->prepare("SELECT p.id, p.ref_no, p.status, p.contract_id FROM subcon_progress p WHERE p.id = :id");
            $st->execute([':id' => $pid]);
            $p = $st->fetch(PDO::FETCH_ASSOC);
            if (!$p) throw new ScError('Prestasi tidak ditemukan.');
            $st = $db->prepare("SELECT * FROM subcon_inspections WHERE progress_id = :p AND status IN ('DRAFT', 'APPROVED') ORDER BY id DESC LIMIT 1");
            $st->execute([':p' => $pid]);
            $ins = $st->fetch(PDO::FETCH_ASSOC);
            $st = $db->prepare("SELECT q.id AS progress_qty_item_id, q.qty_claimed, w.description, w.work_code, w.unit, t.task_name,
                                       ii.qty_inspected, ii.qty_passed, ii.qty_rejected, ii.qty_rework, ii.notes
                                  FROM subcon_progress_qty_items q
                                  JOIN spk_work_items w ON w.id = q.work_item_id
                                  JOIN subcon_contract_tasks t ON t.id = q.contract_task_id
                                  LEFT JOIN subcon_inspection_items ii ON ii.progress_qty_item_id = q.id AND ii.inspection_id = :i
                                 WHERE q.progress_id = :p ORDER BY q.id");
            $st->execute([':p' => $pid, ':i' => $ins ? (int)$ins['id'] : 0]);
            $items = $st->fetchAll(PDO::FETCH_ASSOC);
            if (!count($items)) throw new ScError('Prestasi ini tidak memiliki baris klaim kuantitas, jadi tidak perlu inspeksi.');
            foreach ($items as &$it) {
                $it['progress_qty_item_id'] = (int)$it['progress_qty_item_id']; $it['qty_claimed'] = (float)$it['qty_claimed'];
                $has = $it['qty_inspected'] !== null;
                // inspeksi baru: isian awal = seluruh qty klaim lolos (inspektur tinggal mengoreksi yang bermasalah)
                $it['qty_inspected'] = $has ? (float)$it['qty_inspected'] : $it['qty_claimed'];
                $it['qty_passed']    = $has ? (float)$it['qty_passed']    : $it['qty_claimed'];
                $it['qty_rejected']  = $has ? (float)$it['qty_rejected']  : 0.0;
                $it['qty_rework']    = $has ? (float)$it['qty_rework']    : 0.0;
            }
            unset($it);
            sc_send(true, $items, '', ['progress' => ['id' => (int)$p['id'], 'ref_no' => $p['ref_no'], 'status' => $p['status']],
                                       'inspection' => $ins ? ['id' => (int)$ins['id'], 'inspection_no' => $ins['inspection_no'], 'status' => $ins['status'], 'inspection_date' => $ins['inspection_date'],
                                                               'general_result' => $ins['general_result'], 'evidence_ref' => $ins['evidence_ref']] : null]);
            break;

        case 'save_inspection':
            $id   = (int)($_POST['id'] ?? 0);
            $pid  = (int)($_POST['progress_id'] ?? 0);
            $date = sc_date((string)($_POST['inspection_date'] ?? ''), 'Tanggal inspeksi');
            $evid = trim((string)($_POST['evidence_ref'] ?? '')) ?: null;
            $gen  = trim((string)($_POST['general_result'] ?? '')) ?: null;
            $in   = json_decode($_POST['items'] ?? '[]', true);
            if (!is_array($in) || !count($in)) throw new ScError('Isi hasil inspeksi minimal satu baris.');
            if (!sc_has_qty($db)) throw new ScError('Fitur inspeksi belum aktif: jalankan database/subcontract_management_ddl.sql di database ini.');
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $pid]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p) throw new ScError('Prestasi tidak ditemukan.');
                if ($p['status'] !== 'Draft') throw new ScError('Inspeksi hanya dapat dibuat untuk prestasi berstatus Draft.');
                $c = sc_contract($db, (int)$p['contract_id'], true);
                if ($c['status'] !== 'Disetujui') throw new ScError('SPK tidak berstatus Disetujui.');
                $st = $db->prepare("SELECT id, status FROM subcon_inspections WHERE progress_id = :p AND status IN ('DRAFT', 'APPROVED') AND id <> :x LIMIT 1");
                $st->execute([':p' => $pid, ':x' => $id]);
                if ($st->fetch()) throw new ScError('Prestasi ini sudah memiliki inspeksi aktif. Batalkan dulu bila ingin membuat ulang. (Untuk mengedit inspeksi yang ada, klik Edit di tabel "Inspeksi Kuantitas".)');
                $st = $db->prepare("SELECT id, qty_claimed FROM subcon_progress_qty_items WHERE progress_id = :p");
                $st->execute([':p' => $pid]);
                $claims = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $claims[(int)$r['id']] = (float)$r['qty_claimed'];
                if (!count($claims)) throw new ScError('Prestasi ini tidak memiliki baris klaim kuantitas.');

                $clean = []; $seen = [];
                foreach ($in as $n => $r) {
                    $qid = (int)($r['progress_qty_item_id'] ?? 0);
                    $no  = 'Baris inspeksi ' . ($n + 1);
                    if (!isset($claims[$qid])) throw new ScError("{$no}: bukan baris klaim milik prestasi ini.");
                    if (isset($seen[$qid])) throw new ScError("{$no}: baris klaim yang sama muncul dua kali.");
                    $seen[$qid] = true;
                    $ins_ = round((float)($r['qty_inspected'] ?? 0), 4); $pas = round((float)($r['qty_passed'] ?? 0), 4);
                    $rej  = round((float)($r['qty_rejected'] ?? 0), 4);  $rew = round((float)($r['qty_rework'] ?? 0), 4);
                    if ($ins_ < 0 || $pas < 0 || $rej < 0 || $rew < 0) throw new ScError("{$no}: qty tidak boleh negatif.");
                    if ($ins_ > $claims[$qid] + 0.00005) throw new ScError("{$no}: qty diperiksa ({$ins_}) melebihi qty klaim ({$claims[$qid]}).");
                    if ($pas + $rej + $rew > $ins_ + 0.00005) throw new ScError("{$no}: lolos + ditolak + perbaikan ({$pas} + {$rej} + {$rew}) melebihi qty diperiksa ({$ins_}).");
                    $clean[] = ['qid' => $qid, 'ins' => $ins_, 'pas' => $pas, 'rej' => $rej, 'rew' => $rew, 'res' => sc_insp_result($claims[$qid], $ins_, $pas, $rej, $rew),
                                'notes' => trim((string)($r['notes'] ?? '')) ?: null];
                }
                foreach ($claims as $qid => $cl) { if (!isset($seen[$qid])) throw new ScError('Semua baris klaim pada prestasi ini harus diisi hasil inspeksinya.'); }

                if ($id > 0) {
                    $st = $db->prepare("SELECT status, inspection_no FROM subcon_inspections WHERE id = :id AND progress_id = :p FOR UPDATE");
                    $st->execute([':id' => $id, ':p' => $pid]);
                    $i0 = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$i0) throw new ScError('Inspeksi tidak ditemukan.');
                    if ($i0['status'] !== 'DRAFT') throw new ScError('Hanya inspeksi berstatus DRAFT yang dapat diubah.');
                    $db->prepare("UPDATE subcon_inspections SET inspection_date = CAST(:d AS date), general_result = :g, evidence_ref = :e, inspector_id = :u WHERE id = :id")
                       ->execute([':d' => $date, ':g' => $gen, ':e' => $evid, ':u' => sc_uid(), ':id' => $id]);
                    $db->prepare("DELETE FROM subcon_inspection_items WHERE inspection_id = :id")->execute([':id' => $id]);
                    $no = $i0['inspection_no'];
                } else {
                    $no = CreateRefNo('subcon_inspections', 'inspection_no', 'INS');
                    $st = $db->prepare("INSERT INTO subcon_inspections (inspection_no, progress_id, inspection_date, inspector_id, status, general_result, evidence_ref, created_by)
                                        VALUES (:no, :p, CAST(:d AS date), :u, 'DRAFT', :g, :e, :u) RETURNING id");
                    $st->execute([':no' => $no, ':p' => $pid, ':d' => $date, ':u' => sc_uid(), ':g' => $gen, ':e' => $evid]);
                    $id = (int)$st->fetchColumn();
                }
                $stI = $db->prepare("INSERT INTO subcon_inspection_items (inspection_id, progress_qty_item_id, qty_inspected, qty_passed, qty_rejected, qty_rework, result, notes)
                                     VALUES (:i, :q, :a, :b, :c, :d, :r, :n)");
                foreach ($clean as $r) $stI->execute([':i' => $id, ':q' => $r['qid'], ':a' => $r['ins'], ':b' => $r['pas'], ':c' => $r['rej'], ':d' => $r['rew'], ':r' => $r['res'], ':n' => $r['notes']]);
                $db->commit();
                sc_send(true, ['id' => $id, 'inspection_no' => $no], "Inspeksi {$no} tersimpan (DRAFT). Langkah berikutnya: scroll ke tabel 'Inspeksi Kuantitas' di bawah → klik 'Setujui' → setelah disetujui, kembali ke tabel Prestasi → klik 'Setujui'.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'approve_inspection':
            $id = (int)($_POST['id'] ?? 0);
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM subcon_inspections WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $i = $st->fetch(PDO::FETCH_ASSOC);
                if (!$i) throw new ScError('Inspeksi tidak ditemukan.');
                if ($i['status'] !== 'DRAFT') throw new ScError('Hanya inspeksi berstatus DRAFT yang dapat disetujui.');
                $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id FOR UPDATE");
                $st->execute([':id' => (int)$i['progress_id']]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p || $p['status'] !== 'Draft') throw new ScError('Prestasi terkait tidak lagi berstatus Draft.');
                $c = sc_contract($db, (int)$p['contract_id'], true);
                if ($c['status'] !== 'Disetujui') throw new ScError('SPK tidak berstatus Disetujui.');
                if (SC_REQUIRE_OTHER_APPROVER && (int)$i['created_by'] === (int)sc_uid()) throw new ScError('Penyetuju inspeksi harus user lain selain pembuat inspeksi.');
                $st = $db->prepare("SELECT COUNT(*) FROM subcon_inspection_items WHERE inspection_id = :i");
                $st->execute([':i' => $id]);
                if ((int)$st->fetchColumn() < 1) throw new ScError('Inspeksi belum memiliki baris hasil.');
                $db->prepare("UPDATE subcon_inspections SET status = 'APPROVED', approved_by = :u, approved_at = NOW() WHERE id = :id")->execute([':u' => sc_uid(), ':id' => $id]);
                $r = sc_recompute_progress($db, $c, (int)$p['id'], true);            // nilai prestasi kini berdasarkan qty LOLOS
                $db->commit();
                sc_send(true, ['gross' => $r['gross'], 'calc' => $r['calc']], "Inspeksi {$i['inspection_no']} disetujui. Nilai prestasi (DPP) diperbarui berdasarkan qty lolos: " . number_format($r['gross'], 2) . '. — Sekarang klik \'Setujui\' di baris prestasi untuk mencatat pengakuan pendapatan.');
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'cancel_inspection':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 5) throw new ScError('Alasan pembatalan wajib diisi (minimal 5 karakter).');
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM subcon_inspections WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $i = $st->fetch(PDO::FETCH_ASSOC);
                if (!$i) throw new ScError('Inspeksi tidak ditemukan.');
                if (!in_array($i['status'], ['DRAFT', 'APPROVED'], true)) throw new ScError('Inspeksi ini sudah ' . $i['status'] . '.');
                $st = $db->prepare("SELECT * FROM subcon_progress WHERE id = :id FOR UPDATE");
                $st->execute([':id' => (int)$i['progress_id']]);
                $p = $st->fetch(PDO::FETCH_ASSOC);
                if (!$p || $p['status'] !== 'Draft') throw new ScError('Prestasi terkait sudah disetujui / dibatalkan, inspeksi tidak dapat dibatalkan.');
                $c = sc_contract($db, (int)$p['contract_id'], true);
                $db->prepare("UPDATE subcon_inspections SET status = 'CANCELLED', cancelled_by = :u, cancelled_at = NOW(), cancel_reason = :r WHERE id = :id")
                   ->execute([':u' => sc_uid(), ':r' => $reason, ':id' => $id]);
                sc_recompute_progress($db, $c, (int)$p['id'], false);                // kembali ke dasar qty KLAIM
                $db->commit();
                sc_send(true, [], "Inspeksi {$i['inspection_no']} dibatalkan. Nilai prestasi kembali ke dasar qty klaim.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        /* ------------------------------------------------------------------ pembayaran */
        case 'save_payment':
            $cid  = (int)($_POST['contract_id'] ?? 0);
            $type = (string)($_POST['pay_type'] ?? '');
            $pid  = (int)($_POST['progress_id'] ?? 0);
            $date = sc_date((string)($_POST['payment_date'] ?? ''), 'Tanggal bayar');
            $acc  = (int)($_POST['account_id'] ?? 0);
            $amt  = sc_f($_POST['amount'] ?? 0);
            $ref  = trim((string)($_POST['reference_no'] ?? '')) ?: null;
            $notes = trim((string)($_POST['notes'] ?? '')) ?: null;
            if (!in_array($type, ['DP', 'PROGRESS', 'RETENSI'], true)) throw new ScError('Jenis pembayaran tidak valid.');
            if ($amt <= 0) throw new ScError('Jumlah bayar harus lebih besar dari 0.');
            $db->beginTransaction();
            try {
                sc_assert_ref_types($db);
                $c = sc_contract($db, $cid, true);
                $st = $db->prepare("SELECT account_code FROM public.chart_of_accounts WHERE id = :a AND is_active AND account_type::text IN ('cash','bank')");
                $st->execute([':a' => $acc]);
                if ($st->fetchColumn() === false) throw new ScError('Akun kas / bank sumber pembayaran wajib dipilih.');
                $tot = sc_totals($db, $cid);

                if ($type === 'DP') {
                    if ($c['status'] !== 'Disetujui') throw new ScError('Uang muka hanya untuk SPK berstatus Disetujui.');
                    $plan = sc_f($c['contract_value'] * $c['dp_pct'] / 100);
                    if ($plan <= 0) throw new ScError('SPK ini tidak memiliki uang muka (dp% = 0).');
                    if ($tot['dp_paid'] + $amt > $plan + SC_EPS) throw new ScError('Uang muka melebihi ketentuan SPK (' . number_format($plan, 2) . ', sudah dibayar ' . number_format($tot['dp_paid'], 2) . ').');
                    $pid = 0; $rule = 'SUBCON_PAY_DP';
                } elseif ($type === 'PROGRESS') {
                    if (!in_array($c['status'], ['Disetujui', 'Selesai'], true)) throw new ScError('SPK tidak dalam status yang dapat dibayar.');
                    $st = $db->prepare("SELECT net_payable, status, contract_id, ref_no FROM subcon_progress WHERE id = :p");
                    $st->execute([':p' => $pid]);
                    $pr = $st->fetch(PDO::FETCH_ASSOC);
                    if (!$pr || (int)$pr['contract_id'] !== $cid || $pr['status'] !== 'Disetujui') throw new ScError('Pilih prestasi yang sudah disetujui pada SPK ini.');
                    $st = $db->prepare("SELECT COALESCE(SUM(amount),0) FROM subcon_payments WHERE progress_id = :p AND status = 'Posted'");
                    $st->execute([':p' => $pid]);
                    $out = sc_f((float)$pr['net_payable'] - (float)$st->fetchColumn());
                    if ($amt > $out + SC_EPS) throw new ScError('Pembayaran melebihi sisa hutang prestasi ' . $pr['ref_no'] . ' (' . number_format($out, 2) . ').');
                    $rule = 'SUBCON_PAY';
                } else {
                    if ($c['status'] !== 'Selesai') throw new ScError('Retensi hanya dapat dilepas setelah SPK berstatus Selesai.');
                    if ($amt > $tot['retention_balance'] + SC_EPS) throw new ScError('Pelepasan retensi melebihi retensi yang ditahan (' . number_format($tot['retention_balance'], 2) . ').');
                    $pid = 0; $rule = 'SUBCON_PAY_RET';
                }
                $payNo = CreateRefNo('subcon_payments', 'payment_no', 'SPY');
                $st = $db->prepare("INSERT INTO subcon_payments (payment_no, contract_id, pay_type, progress_id, payment_date, account_id, amount, reference_no, notes, status, created_by)
                                    VALUES (:no,:c,:t,:p,CAST(:d AS date),:a,:amt,:r,:n,'Posted',:u) RETURNING id");
                $st->execute([':no' => $payNo, ':c' => $cid, ':t' => $type, ':p' => $pid ?: null, ':d' => $date, ':a' => $acc, ':amt' => $amt, ':r' => $ref, ':n' => $notes, ':u' => sc_uid()]);
                $payId = (int)$st->fetchColumn();
                sc_post($db, $rule, SC_REF_PAYMENT, $payId, $date, $amt, "Pembayaran subkon {$payNo} - {$c['spk_no']}", $acc);
                $db->commit();
                sc_send(true, ['id' => $payId, 'payment_no' => $payNo], "Pembayaran {$payNo} tersimpan & dijurnal.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        case 'cancel_payment':
            $id = (int)($_POST['id'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if (strlen($reason) < 5) throw new ScError('Alasan pembatalan wajib diisi (minimal 5 karakter).');
            $db->beginTransaction();
            try {
                $st = $db->prepare("SELECT * FROM subcon_payments WHERE id = :id FOR UPDATE");
                $st->execute([':id' => $id]);
                $y = $st->fetch(PDO::FETCH_ASSOC);
                if (!$y) throw new ScError('Pembayaran tidak ditemukan.');
                if ($y['status'] !== 'Posted') throw new ScError('Pembayaran sudah dibatalkan.');
                $c = sc_contract($db, (int)$y['contract_id'], true);
                $tot = sc_totals($db, (int)$c['id']);
                if ($y['pay_type'] === 'DP' && ($tot['dp_paid'] - (float)$y['amount']) < $tot['dp_recovered'] - SC_EPS) {
                    throw new ScError('Uang muka ini sudah dipotong dari prestasi. Batalkan prestasi terkait dulu.');
                }
                if ($y['pay_type'] === 'RETENSI' && $c['status'] === 'Selesai') { /* boleh: retensi kembali ditahan */ }
                if (sc_has_journal($db, SC_REF_PAYMENT, $id)) ReverseJournalByReference($db, SC_REF_PAYMENT, $id);
                $db->prepare("UPDATE subcon_payments SET status='Dibatalkan', cancelled_by=:u, cancelled_at=NOW(), cancel_reason=:r WHERE id=:id")
                   ->execute([':u' => sc_uid(), ':r' => $reason, ':id' => $id]);
                $db->commit();
                sc_send(true, [], "Pembayaran {$y['payment_no']} dibatalkan.");
            } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
            break;

        default:
            sc_send(false, [], 'Aksi/Task tidak dikenal.');
            break;
    }
} catch (ScError $e) {
    sc_send(false, [], $e->getMessage());
} catch (PDOException $e) {
    error_log('[subconCRD] ' . $e->getMessage());
    sc_send(false, [], 'Database Error: ' . $e->getMessage());
} catch (Throwable $e) {
    error_log('[subconCRD] ' . $e->getMessage());
    sc_send(false, [], 'System Error: ' . $e->getMessage());
}