<?php
/**
 * spkCRD.php — SPK (Surat Perintah Kerja)
 *
 * SPK = izin mengeluarkan material gudang untuk satu proyek. Hanya bisa DITERBITKAN setelah proyek menerima pembayaran
 * termin, dan qty per material dibatasi: kuota kumulatif = qty RAB x (% kontrak yang sudah dibayar x 2), maks 100%
 * (lihat php-func/function.spk.php). Warehouse Issuing proyek wajib memilih SPK dan qty issue dibatasi sisa jatah SPK.
 *
 * Status : Draft -> Diterbitkan -> Selesai ; Dibatalkan (dari Draft / Diterbitkan yang belum dipakai issue)
 * Action : list | detail | lookup_projects | lookup_tasks | lookup_subcontractors | lookup_materials | quota | save | issue | close | cancel | delete
 *
 * [2026-11-11] SPK INTERNAL atau SUBCON (database/subcontract_management_ddl.sql, Langkah 1 SUBCONTRACT_MANAGEMENT_DESIGN.md)
 *   - spk_type INTERNAL (default, perilaku lama tidak berubah) atau SUBCON (execution_subcontractor_id wajib).
 *   - SPK SUBCON boleh tanpa baris material (pekerjaan murni). Kalau ada material, aturan kuota + stok tetap sama.
 *   - Syarat 'harus ada pembayaran klien' tetap untuk semua SPK yang melepas material; SPK SUBCON tanpa material
 *     tidak dikenai syarat itu (ubah lewat SPK_SUBCON_REQUIRE_CLIENT_PAYMENT).
 *   - Tipe SPK tidak bisa diubah setelah disimpan. Kontrak subkon (subcon_contracts.spk_id) menempel ke SPK SUBCON.
 *   - Kode tetap jalan di database yang belum menjalankan DDL tersebut (kolom baru dicek dulu).
 * Akses  : buat / ubah >= 2 (ubah form tersimpan perlu 3) ; terbitkan / tutup / batalkan >= 3 ; hapus >= 4
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';
require_once '../php-func/function.spk.php';

define('SPK_ACCESS_EDIT', 2);
define('SPK_ACCESS_ACTION', 3);
define('SPK_ACCESS_DELETE', 4);
// SPK SUBCON tanpa material gudang: apakah tetap wajib menunggu pembayaran klien sebelum diterbitkan?
define('SPK_SUBCON_REQUIRE_CLIENT_PAYMENT', false);
// SPK SUBCON hanya boleh diterbitkan bila kontrak subkon yang terhubung berstatus Disetujui (set false untuk mematikan aturan ini)
define('SPK_SUBCON_REQUIRE_CONTRACT_ON_ISSUE', true);

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$spkUserId = (int)($_SESSION['user_id'] ?? 0);
if ($spkUserId <= 0) { http_response_code(401); WhFail('Sesi berakhir, silakan login ulang.'); }
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); WhFail('Anda tidak memiliki hak baca SPK.'); }
$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':             SpkList(); break;
        case 'detail':           SpkDetail(); break;
        case 'lookup_projects':  SpkLookupProjects(); break;
        case 'lookup_tasks':     SpkLookupTasks(); break;
        case 'lookup_subcontractors': SpkLookupSubcontractors(); break;
        case 'lookup_materials': SpkLookupMaterials(); break;
        case 'quota':            SpkQuotaInfo(); break;
        case 'save':             SpkSave(); break;
        case 'issue':            SpkIssue(); break;
        case 'close':            SpkClose(); break;
        case 'cancel':           SpkCancel(); break;
        case 'delete':           SpkDelete(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('spkCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
function SpkNeed($level, $msg) {
    $a = isset($_SESSION['FormAccess']) ? (int)$_SESSION['FormAccess'] : 0;
    if ($a < $level) { throw new WhException($msg); }
}

// Apakah database ini sudah menjalankan DDL SPK subkon (kolom execution_subcontractor_id ada)?
function SpkHasSubconSchema() {
    static $ok = null;
    if ($ok === null) {
        $r = WhRow("SELECT 1 AS x FROM information_schema.columns
                    WHERE table_schema='public' AND table_name='spk' AND column_name='execution_subcontractor_id' LIMIT 1");
        $ok = $r ? true : false;
    }
    return $ok;
}

function SpkGet($id, $lock = false) {
    $s = WhRow('SELECT * FROM spk WHERE id=$1' . ($lock ? ' FOR UPDATE' : ''), array((int)$id));
    if (!$s) { throw new WhException('SPK tidak ditemukan.'); }
    return $s;
}

// jumlah baris issue (posted) yang memakai SPK
function SpkIssueCount($spkId) {
    $r = WhRow("SELECT COUNT(*) AS n FROM material_out_header WHERE spk_id=$1 AND status='posted'", array((int)$spkId));
    return (int)$r['n'];
}

// item SPK per material + terpakai + sisa
function SpkItemsWithUsage($spkId) {
    $rows = WhRows("SELECT si.material_id, m.material_code, m.material_name, COALESCE(NULLIF(si.unit,''), m.unit, '') AS unit,
                           SUM(si.qty) AS qty, MAX(si.notes) AS notes
                    FROM spk_items si JOIN materials m ON m.id=si.material_id
                    WHERE si.spk_id=$1 GROUP BY si.material_id, m.material_code, m.material_name, COALESCE(NULLIF(si.unit,''), m.unit, '')
                    ORDER BY m.material_name", array((int)$spkId));
    foreach ($rows as &$r) {
        $u = WhRow("SELECT COALESCE(SUM(i.qty_out),0) AS q FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                    WHERE h.spk_id=$1 AND i.material_id=$2 AND h.status='posted'", array((int)$spkId, (int)$r['material_id']));
        $r['qty'] = round((float)$r['qty'], 4);
        $r['used'] = round((float)$u['q'], 4);
        $r['remaining'] = max(0, round($r['qty'] - $r['used'], 4));
    }
    unset($r);
    return $rows;
}

function SpkList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array(); $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (s.spk_no ILIKE $1 OR p.name ILIKE $1 OR s.foreman ILIKE $1 OR s.description ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM spk s JOIN projects p ON p.id=s.project_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $ready = SpkHasSubconSchema();
    $sx = $ready
        ? ", s.spk_type, s.execution_subcontractor_id, sb.name AS subcontractor_name, sc.id AS contract_id, sc.spk_no AS contract_no, sc.status AS contract_status"
        : ", 'INTERNAL'::text AS spk_type, NULL::int AS execution_subcontractor_id, NULL::text AS subcontractor_name, NULL::int AS contract_id, NULL::text AS contract_no, NULL::text AS contract_status";
    $jx = $ready
        ? " LEFT JOIN subcontractors sb ON sb.id=s.execution_subcontractor_id LEFT JOIN subcon_contracts sc ON sc.spk_id=s.id"
        : "";
    $rows = WhRows("SELECT s.id, s.spk_no, s.spk_date, s.project_id, p.name AS project_name, s.foreman, s.description, s.status, s.remark $sx,
                           (SELECT COUNT(DISTINCT x.material_id) FROM spk_items x WHERE x.spk_id=s.id) AS item_count
                    FROM spk s JOIN projects p ON p.id=s.project_id $jx
                    WHERE $where ORDER BY s.spk_date DESC, s.id DESC LIMIT $pl OFFSET $ps", $params);
    // persen terpakai = rata-rata (issue / qty SPK, maks 100%) per material
    foreach ($rows as &$r) {
        $items = SpkItemsWithUsage($r['id']);
        $sum = 0.0;
        foreach ($items as $it) { $sum += ($it['qty'] > 0) ? min(1.0, $it['used'] / $it['qty']) : 0.0; }
        $r['used_pct'] = count($items) ? round($sum / count($items) * 100, 1) : 0;
    }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function SpkDetail() {
    $id = (int)WhReq('id');
    $s = SpkGet($id);
    $p = WhRow('SELECT p.name, c.name AS client_name FROM projects p LEFT JOIN clients c ON c.id=p.client_id WHERE p.id=$1', array((int)$s['project_id']));
    $pay = SpkProjectPaid($s['project_id']);
    $tasks = WhRows("SELECT t.id, t.task_name, COALESCE(t.description,'') AS description, t.start_date, t.end_date, COALESCE(t.status,'') AS status
                     FROM spk_tasks st JOIN public.project_tasks t ON t.id=st.task_id WHERE st.spk_id=$1 ORDER BY t.start_date NULLS LAST, t.id", array($id));
    $sub = null; $contract = null;
    if (SpkHasSubconSchema()) {
        if (!empty($s['execution_subcontractor_id'])) {
            $sub = WhRow("SELECT id, name, COALESCE(code,'') AS code FROM subcontractors WHERE id=$1", array((int)$s['execution_subcontractor_id']));
        }
        $contract = WhRow("SELECT id, spk_no AS contract_no, title, contract_value, status FROM subcon_contracts WHERE spk_id=$1", array($id));
    }
    WhJson(array('success' => true, 'header' => array_merge($s, array('project_name' => $p['name'], 'client_name' => $p['client_name'])),
                 'pay' => $pay, 'items' => SpkItemsWithUsage($id), 'tasks' => $tasks, 'issue_count' => SpkIssueCount($id),
                 'subcontractor' => $sub ? $sub : null, 'contract' => $contract ? $contract : null));
}

function SpkLookupProjects() {
    $q = WhReq('query');
    $rows = WhRows("SELECT p.id, p.name FROM projects p WHERE p.name ILIKE $1 ORDER BY p.name LIMIT 100", array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function SpkLookupSubcontractors() {
    if (!SpkHasSubconSchema()) { WhJson(array('success' => true, 'totalCount' => 0, 'data' => array())); return; }
    $q = WhReq('query');
    $rows = WhRows("SELECT id, name, COALESCE(code,'') AS code FROM subcontractors
                    WHERE active AND (name ILIKE $1 OR COALESCE(code,'') ILIKE $1) ORDER BY name LIMIT 100", array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function SpkLookupTasks() {
    $pid = (int)WhReq('project_id'); $exclude = (int)WhReq('exclude_id');
    if ($pid < 1 || !WhRow('SELECT 1 FROM projects WHERE id=$1', array($pid))) { throw new WhException('Pilih proyek yang valid.'); }
    // Task boleh digunakan pada beberapa SPK. Kuantitas per Task dikontrol melalui tabel alokasi.
    $rows = WhRows("SELECT t.id, t.task_name, COALESCE(t.description,'') AS description, t.start_date, t.end_date, COALESCE(t.status,'') AS status
                    FROM public.project_tasks t WHERE t.project_id=$1
                    ORDER BY t.start_date NULLS LAST, t.id", array($pid));
    WhJson(array('success'=>true,'totalCount'=>count($rows),'data'=>$rows));
}

function SpkQuotaInfo() {
    $pid = (int)WhReq('project_id');
    WhJson(array('success' => true, 'data' => SpkProjectPaid($pid)));
}

// Material RAB proyek + kuota tersedia untuk SPK baru
function SpkLookupMaterials() {
    $pid = (int)WhReq('project_id'); $excl = (int)WhReq('exclude_id'); $q = strtolower(WhReq('query'));
    $qt = SpkMaterialQuota($pid, $excl);
    $rows = array();
    foreach ($qt['materials'] as $m) {
        if ($q !== '' && strpos(strtolower($m['material_name'] . ' ' . $m['material_code']), $q) === false) { continue; }
        $rows[] = $m;
    }
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows, 'pay' => $qt['pay']));
}

function SpkSave() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membuat / mengubah SPK.');
    $id = (int)WhPost('id');
    $projectId = (int)WhPost('project_id');
    $date = WhDate(WhPost('spk_date'), 'Tanggal SPK');
    $foreman = trim(WhPost('foreman')); $desc = trim(WhPost('description')); $remark = trim(WhPost('remark'));
    $type = strtoupper(trim((string)WhPost('spk_type')));
    if ($type === '') { $type = 'INTERNAL'; }
    if ($type !== 'INTERNAL' && $type !== 'SUBCON') { throw new WhException('Tipe SPK tidak valid.'); }
    $subId = (int)WhPost('execution_subcontractor_id');
    $ws = trim((string)WhPost('work_start_date')); $we = trim((string)WhPost('work_end_date'));
    $ws = ($ws === '') ? null : WhDate($ws, 'Tanggal mulai kerja');
    $we = ($we === '') ? null : WhDate($we, 'Tanggal selesai kerja');
    if ($ws !== null && $we !== null && $we < $ws) { throw new WhException('Tanggal selesai kerja tidak boleh lebih awal dari tanggal mulai.'); }
    $subconReady = SpkHasSubconSchema();
    if ($type === 'SUBCON') {
        if (!$subconReady) { throw new WhException('SPK subkon belum aktif: jalankan database/subcontract_management_ddl.sql di database ini.'); }
        if ($subId < 1 || !WhRow('SELECT 1 FROM subcontractors WHERE id=$1 AND active', array($subId))) {
            throw new WhException('Pilih subkontraktor yang aktif untuk SPK subkon.');
        }
    } else { $subId = 0; }
    $detailsRaw = isset($_POST['details']) ? $_POST['details'] : '[]';
    $details = is_array($detailsRaw) ? $detailsRaw : json_decode((string)$detailsRaw, true);
    if (!is_array($details)) { $details = array(); }
    // SPK internal wajib punya material (izin issue gudang). SPK subkon boleh pekerjaan murni tanpa material.
    if ($type === 'INTERNAL' && !count($details)) { throw new WhException('Tambahkan minimal satu material.'); }
    $taskRaw = isset($_POST['task_details']) ? $_POST['task_details'] : '[]';
    $taskDetails = is_array($taskRaw) ? $taskRaw : json_decode((string)$taskRaw, true);
    if (!is_array($taskDetails) || !count($taskDetails)) { throw new WhException('Pilih minimal satu task proyek untuk SPK.'); }
    $userId = WhUserId();
    if ($projectId < 1) { throw new WhException('Proyek wajib dipilih.'); }
    if (!WhRow('SELECT 1 FROM projects WHERE id=$1', array($projectId))) { throw new WhException('Proyek tidak ditemukan.'); }

    // gabungkan baris duplikat per material; wajib material yang ada di RAB proyek
    $lines = array();
    foreach ($details as $d) {
        $mid = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        $qty = isset($d['qty']) ? WhNum($d['qty']) : 0;
        if ($mid < 1) { throw new WhException('Material pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty SPK harus lebih besar dari 0.'); }
        if (!WhRow('SELECT 1 FROM budget_items WHERE project_id=$1 AND material_id=$2', array($projectId, $mid))) {
            $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
            throw new WhException('Material ' . ($m ? $m['material_name'] : $mid) . ' tidak ada di RAB proyek ini.');
        }
        if (!isset($lines[$mid])) { $lines[$mid] = array('qty' => 0, 'notes' => ''); }
        $lines[$mid]['qty'] = round($lines[$mid]['qty'] + $qty, 4);
        if (!empty($d['notes'])) { $lines[$mid]['notes'] = trim($d['notes']); }
    }
    if ($type === 'INTERNAL' && !count($lines)) { throw new WhException('Tambahkan minimal satu material.'); }

    WhQ('BEGIN');
    // Lock proyek agar kalkulasi kuota tidak berubah saat SPK disimpan.
    WhLock(0, 900000 + $projectId);
    $taskIds = array();
    foreach ($taskDetails as $td) {
        $tid = isset($td['task_id']) ? (int)$td['task_id'] : 0;
        if ($tid < 1 || isset($taskIds[$tid])) { throw new WhException('Task SPK tidak valid atau dipilih lebih dari sekali.'); }
        if (!WhRow('SELECT 1 FROM public.project_tasks WHERE id=$1 AND project_id=$2 FOR UPDATE', array($tid, $projectId))) {
            throw new WhException('Task yang dipilih bukan bagian dari proyek ini.');
        }
        // Task dapat dibagi ke beberapa SPK; validasi kuantitas dilakukan pada alokasi Task.
        $taskIds[$tid] = true;
    }
    $qt = SpkMaterialQuota($projectId, $id);
    foreach ($lines as $mid => $ln) {
        if (!isset($qt['materials'][$mid])) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan dalam RAB proyek.'); }
        $maxQty = (float)$qt['materials'][$mid]['available'];
        if ($ln['qty'] > $maxQty + 0.00005) {
            throw new WhException('Qty SPK ' . $qt['materials'][$mid]['material_name'] . ' (' . WhFmt($ln['qty']) . ') melebihi maksimum tersedia (' . WhFmt($maxQty) . ').');
        }
    }
    if ($id > 0) {
        $s = SpkGet($id, true);
        if ($s['status'] !== 'Draft') { throw new WhException('Hanya SPK berstatus Draft yang dapat diubah.'); }
        $oldType = isset($s['spk_type']) ? $s['spk_type'] : 'INTERNAL';
        if ($oldType !== $type) { throw new WhException('Tipe SPK tidak dapat diubah. Buat SPK baru dengan tipe yang diinginkan.'); }
        if ($subconReady) {
            $lc = WhRow('SELECT 1 AS x FROM subcon_contracts WHERE spk_id=$1', array($id));
            if ($lc && ((int)$s['project_id'] !== $projectId || (int)$s['execution_subcontractor_id'] !== $subId)) {
                throw new WhException('SPK ini sudah terhubung ke kontrak subkon: proyek dan subkontraktor tidak dapat diubah.');
            }
            WhQ('UPDATE spk SET spk_date=$2, project_id=$3, foreman=$4, description=$5, remark=$6, execution_subcontractor_id=$7, work_start_date=$8, work_end_date=$9, updated_at=now() WHERE id=$1',
                array($id, $date, $projectId, $foreman, $desc, $remark, $subId > 0 ? $subId : null, $ws, $we));
        } else {
            WhQ('UPDATE spk SET spk_date=$2, project_id=$3, foreman=$4, description=$5, remark=$6, updated_at=now() WHERE id=$1',
                array($id, $date, $projectId, $foreman, $desc, $remark));
        }
        WhQ('DELETE FROM spk_items WHERE spk_id=$1', array($id));
        WhQ('DELETE FROM spk_tasks WHERE spk_id=$1', array($id));
        $spkNo = $s['spk_no'];
    } else {
        $spkNo = CreateRefNo('spk', 'spk_no', 'SPK');
        if ($subconReady) {
            $h = WhRow("INSERT INTO spk (spk_no, spk_date, project_id, foreman, description, status, remark, created_by, spk_type, execution_subcontractor_id, work_start_date, work_end_date)
                        VALUES ($1,$2,$3,$4,$5,'Draft',$6,$7,$8,$9,$10,$11) RETURNING id",
                       array($spkNo, $date, $projectId, $foreman, $desc, $remark, $userId, $type, $subId > 0 ? $subId : null, $ws, $we));
        } else {
            $h = WhRow("INSERT INTO spk (spk_no, spk_date, project_id, foreman, description, status, remark, created_by)
                        VALUES ($1,$2,$3,$4,$5,'Draft',$6,$7) RETURNING id",
                       array($spkNo, $date, $projectId, $foreman, $desc, $remark, $userId));
        }
        $id = (int)$h['id'];
    }
    foreach (array_keys($taskIds) as $tid) {
        WhQ('INSERT INTO spk_tasks (spk_id, task_id) VALUES ($1,$2)', array($id, (int)$tid));
    }
    foreach ($lines as $mid => $ln) {
        $m = WhRow('SELECT unit FROM materials WHERE id=$1', array($mid));
        WhQ('INSERT INTO spk_items (spk_id, material_id, qty, unit, notes) VALUES ($1,$2,$3,$4,$5)',
            array($id, $mid, $ln['qty'], $m ? $m['unit'] : '', $ln['notes']));
    }
    WhQ('COMMIT');
    WhOk('SPK tersimpan (Draft). No: ' . $spkNo, array('id' => $id, 'spk_no' => $spkNo));
}

// Terbitkan: syarat sudah ada pembayaran termin + qty tiap material dalam kuota
function SpkIssue() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk menerbitkan SPK.');
    $id = (int)WhPost('id'); $userId = WhUserId();
    WhQ('BEGIN');
    $s = SpkGet($id, true);
    if ($s['status'] !== 'Draft') { throw new WhException('Hanya SPK berstatus Draft yang dapat diterbitkan.'); }
    $pid = (int)$s['project_id'];
    WhLock(0, 900000 + $pid);      // kunci kuota proyek agar dua SPK tidak menghabiskan kuota yang sama

    $isSubcon = (isset($s['spk_type']) && $s['spk_type'] === 'SUBCON');
    $spkItems = SpkItemsWithUsage($id);
    // SPK subkon tanpa material gudang tidak melepas stok, jadi tidak ikut syarat "harus ada pembayaran klien"
    $needPay  = !$isSubcon || count($spkItems) > 0 || SPK_SUBCON_REQUIRE_CLIENT_PAYMENT;
    if ($isSubcon && SPK_SUBCON_REQUIRE_CONTRACT_ON_ISSUE) {
        $kc = WhRow('SELECT status FROM subcon_contracts WHERE spk_id=$1', array($id));
        if (!$kc || $kc['status'] !== 'Disetujui') { throw new WhException('SPK subkon baru dapat diterbitkan setelah kontrak subkon yang terhubung berstatus Disetujui.'); }
    }
    $qt = SpkMaterialQuota($pid, $id);
    $pay = $qt['pay'];
    if ($needPay) {
        if ($pay['paid_amount'] <= 0) { throw new WhException('SPK belum dapat diterbitkan: proyek ini belum menerima pembayaran termin.'); }
        if ($pay['allowed_pct'] <= 0) { throw new WhException('SPK belum dapat diterbitkan: nilai kontrak proyek belum diisi atau pembayaran belum tercatat.'); }
    }

    foreach ($spkItems as $it) {
        $mid = (int)$it['material_id'];
        if (!isset($qt['materials'][$mid])) { throw new WhException($it['material_name'] . ' tidak ada di RAB proyek.'); }
        $a = $qt['materials'][$mid]['available'];
        if ($it['qty'] > $a + 0.00005) {
            throw new WhException("Qty {$it['material_name']} di SPK (" . WhFmt($it['qty']) . ") melebihi kuota yang tersedia (" . WhFmt($a) .
                "). Kuota = RAB " . WhFmt($qt['materials'][$mid]['rab_qty']) . ' x ' . WhFmt($pay['allowed_pct']) . '% (dibayar ' .
                WhFmt($pay['paid_pct']) . '% x ' . SPK_PAID_MULTIPLIER . '), dikurangi SPK aktif dan pemakaian yang sudah ada.');
        }
    }
    WhQ("UPDATE spk SET status='Diterbitkan', issued_at=now(), issued_by=$2, updated_at=now() WHERE id=$1", array($id, $userId));
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' diterbitkan.');
}

function SpkClose() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk menutup SPK.');
    $id = (int)WhPost('id'); $userId = WhUserId();
    WhQ('BEGIN');
    $s = SpkGet($id, true);
    if ($s['status'] !== 'Diterbitkan') { throw new WhException('Hanya SPK berstatus Diterbitkan yang dapat ditutup.'); }
    WhQ("UPDATE spk SET status='Selesai', closed_at=now(), closed_by=$2, updated_at=now() WHERE id=$1", array($id, $userId));
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' ditutup. Sisa jatah yang tidak terpakai kembali menjadi kuota proyek.');
}

function SpkCancel() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk membatalkan SPK.');
    $id = (int)WhPost('id');
    WhQ('BEGIN');
    $s = SpkGet($id, true);
    if ($s['status'] !== 'Draft' && $s['status'] !== 'Diterbitkan') { throw new WhException('SPK berstatus ' . $s['status'] . ' tidak dapat dibatalkan.'); }
    if (SpkIssueCount($id) > 0) { throw new WhException('SPK ini sudah dipakai issue material. Reverse issue terkait dulu, atau gunakan Tutup SPK.'); }
    if (SpkHasSubconSchema()) {
        $kc = WhRow('SELECT id FROM subcon_contracts WHERE spk_id=$1', array($id));
        if ($kc) {
            $u = WhRow("SELECT (SELECT COUNT(*) FROM subcon_progress WHERE contract_id=$1 AND status<>'Dibatalkan') AS prg,
                               (SELECT COUNT(*) FROM subcon_payments WHERE contract_id=$1 AND status='Posted') AS pay", array((int)$kc['id']));
            if ((int)$u['prg'] > 0 || (int)$u['pay'] > 0) {
                throw new WhException('Kontrak subkon yang terhubung sudah memiliki prestasi atau pembayaran. Batalkan prestasi / pembayaran itu dulu.');
            }
        }
    }
    WhQ("UPDATE spk SET status='Dibatalkan', updated_at=now() WHERE id=$1", array($id));
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' dibatalkan.');
}

function SpkDelete() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_DELETE, 'Anda tidak memiliki hak akses untuk menghapus SPK.');
    $id = (int)WhPost('id');
    WhQ('BEGIN');
    $s = SpkGet($id, true);
    if ($s['status'] !== 'Draft') { throw new WhException('Hanya SPK berstatus Draft yang dapat dihapus. Gunakan Batalkan untuk SPK lain.'); }
    if (SpkHasSubconSchema() && WhRow('SELECT 1 AS x FROM subcon_contracts WHERE spk_id=$1', array($id))) {
        throw new WhException('SPK ini sudah terhubung ke kontrak subkon. Putuskan atau hapus kontrak subkonnya dulu.');
    }
    WhQ('DELETE FROM spk WHERE id=$1', array($id));       // spk_items ikut terhapus (ON DELETE CASCADE)
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' dihapus.');
}