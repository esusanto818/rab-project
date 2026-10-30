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
            throw new ScError("Tipe jurnal '{$need}' belum terdaftar di database. Jalankan ALTER TYPE pada sql/2026-10-30.subcon.sql terlebih dahulu.");
        }
    }
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

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    sc_send(false, [], 'Sesi berakhir, silakan login ulang.');
}

$task = $_REQUEST['task'] ?? '';

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
        case 'list_contracts':
            $start = (int)($_REQUEST['start'] ?? 0);
            $limit = (int)($_REQUEST['limit'] ?? 250);
            $query = trim($_REQUEST['query'] ?? '');
            $where = ''; $params = [];
            if ($query !== '') {
                $where = "WHERE (c.spk_no ILIKE :q OR c.title ILIKE :q OR p.name ILIKE :q OR s.name ILIKE :q)";
                $params[':q'] = '%' . $query . '%';
            }
            $st = $db->prepare("SELECT COUNT(*) FROM subcon_contracts c JOIN public.projects p ON p.id = c.project_id
                                  JOIN subcontractors s ON s.id = c.subcontractor_id {$where}");
            $st->execute($params);
            $total = (int)$st->fetchColumn();

            $st = $db->prepare("
                SELECT c.id, c.spk_no, c.project_id, p.name AS project_name, c.subcontractor_id, s.name AS subcontractor_name, c.title,
                       c.contract_value, c.ppn_rate, c.pph_rate, c.retention_pct, c.dp_pct, c.start_date, c.end_date, c.status,
                       COALESCE(pr.gross,0) AS billed_gross, COALESCE(pr.net,0) AS billed_net, COALESCE(pr.ret,0) AS retention_held,
                       COALESCE(pay.dp,0) AS dp_paid, COALESCE(pay.pr,0) AS progress_paid, COALESCE(pay.rt,0) AS retention_paid
                  FROM subcon_contracts c
                  JOIN public.projects p ON p.id = c.project_id
                  JOIN subcontractors s ON s.id = c.subcontractor_id
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

            $st = $db->prepare("SELECT p.*, cu.login_name AS created_by_name, au.login_name AS approved_by_name,
                                       COALESCE((SELECT SUM(y.amount) FROM subcon_payments y WHERE y.progress_id = p.id AND y.status = 'Posted'), 0) AS paid_amount
                                  FROM subcon_progress p LEFT JOIN public.m_user_system cu ON cu.id = p.created_by LEFT JOIN public.m_user_system au ON au.id = p.approved_by
                                 WHERE p.contract_id = :id ORDER BY p.id");
            $st->execute([':id' => $id]);
            $progress = $st->fetchAll(PDO::FETCH_ASSOC);
            foreach ($progress as &$p) {
                $p['id'] = (int)$p['id'];
                foreach (['gross_amount', 'ppn_amount', 'pph_amount', 'retention_amount', 'dp_recovery', 'net_payable', 'cum_pct', 'paid_amount'] as $k) $p[$k] = (float)$p[$k];
            }
            unset($p);

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
            sc_send(true, ['header' => $hdr, 'tasks' => $tasks, 'progress' => $progress, 'payments' => $payments, 'journals' => $journals,
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

                // pembagian ke task proyek (kosong = seluruh pekerjaan satu baris)
                $clean = []; $seen = []; $sum = 0.0;
                if (!count($rowsIn)) $rowsIn = [['task_id' => 0, 'task_name' => $title, 'task_value' => $val]];
                $stT = $db->prepare("SELECT task_name FROM public.project_tasks WHERE id = :t AND project_id = :p");
                foreach ($rowsIn as $n => $r) {
                    $tid = (int)($r['task_id'] ?? 0) ?: null;
                    $tv  = sc_f($r['task_value'] ?? 0);
                    $no  = 'Baris task ' . ($n + 1);
                    if ($tv <= 0) throw new ScError("{$no}: nilai harus lebih besar dari 0.");
                    if ($tid) {
                        if (isset($seen[$tid])) throw new ScError("{$no}: task yang sama dipilih dua kali.");
                        $seen[$tid] = true;
                        $stT->execute([':t' => $tid, ':p' => $prj]);
                        $tn = $stT->fetchColumn();
                        if ($tn === false) throw new ScError("{$no}: task bukan milik proyek ini.");
                    } else {
                        $tn = trim((string)($r['task_name'] ?? '')) ?: $title;
                    }
                    $sum += $tv;
                    $clean[] = ['tid' => $tid, 'name' => $tn, 'val' => $tv, 'notes' => trim((string)($r['notes'] ?? '')) ?: null];
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
                $stI = $db->prepare("INSERT INTO subcon_contract_tasks (contract_id, task_id, task_name, task_value, notes) VALUES (:c,:t,:n,:v,:no)");
                foreach ($clean as $r) $stI->execute([':c' => $id, ':t' => $r['tid'], ':n' => $r['name'], ':v' => $r['val'], ':no' => $r['notes']]);
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
                    if ((int)$st->fetchColumn() === 0) throw new ScError('SPK belum memiliki pembagian task.');
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
            foreach ($rows as &$r) {
                $r['id'] = (int)$r['id']; $r['task_value'] = (float)$r['task_value'];
                $r['prev_pct'] = isset($prev[$r['id']]) ? $prev[$r['id']] : 0.0;
                $r['cum_pct'] = isset($cur[$r['id']]) ? $cur[$r['id']] : $r['prev_pct'];
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
                }
                $prev = sc_prev_map($db, $cid, $id);
                $st = $db->prepare("SELECT id, task_value FROM subcon_contract_tasks WHERE contract_id = :c");
                $st->execute([':c' => $cid]);
                $taskVal = []; foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) $taskVal[(int)$t['id']] = (float)$t['task_value'];

                $dtl = []; $gross = 0.0; $weighted = 0.0; $changed = false;
                foreach ($rowsIn as $r) {
                    $tid = (int)($r['contract_task_id'] ?? 0);
                    if (!isset($taskVal[$tid])) throw new ScError('Task prestasi tidak termasuk dalam SPK ini.');
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
                $db->commit();
                sc_send(true, ['id' => $id, 'ref_no' => $ref, 'calc' => $calc, 'gross' => $gross], "Prestasi {$ref} tersimpan (Draft).");
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
                $db->prepare("UPDATE subcon_progress SET status='Dibatalkan', cancelled_by=:u, cancelled_at=NOW(), cancel_reason=:r WHERE id=:id")
                   ->execute([':u' => sc_uid(), ':r' => $reason, ':id' => $id]);
                $db->commit();
                sc_send(true, [], "Prestasi {$p['ref_no']} dibatalkan.");
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