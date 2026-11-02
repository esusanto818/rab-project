<?php
/**
 * spkCRD.php — SPK (Surat Perintah Kerja)
 *
 * SPK = izin mengeluarkan material gudang untuk satu proyek. Hanya bisa DITERBITKAN setelah proyek menerima pembayaran
 * termin, dan qty per material dibatasi: kuota kumulatif = qty RAB x (% kontrak yang sudah dibayar x 2), maks 100%
 * (lihat php-func/function.spk.php). Warehouse Issuing proyek wajib memilih SPK dan qty issue dibatasi sisa jatah SPK.
 *
 * Status : Draft -> Diterbitkan -> Selesai ; Dibatalkan (dari Draft / Diterbitkan yang belum dipakai issue)
 * Action : list | detail | lookup_projects | lookup_materials | quota | save | issue | close | cancel | delete
 * Akses  : buat / ubah >= 2 ; terbitkan / tutup / batalkan / hapus >= 3 ($_SESSION['FormAccess'])
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';
require_once '../php-func/function.spk.php';

define('SPK_ACCESS_EDIT', 2);
define('SPK_ACCESS_ACTION', 3);

$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':             SpkList(); break;
        case 'detail':           SpkDetail(); break;
        case 'lookup_projects':  SpkLookupProjects(); break;
        case 'lookup_tasks':     SpkLookupTasks(); break;
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
    $rows = WhRows("SELECT s.id, s.spk_no, s.spk_date, s.project_id, p.name AS project_name, s.foreman, s.description, s.status, s.remark,
                           (SELECT COUNT(DISTINCT x.material_id) FROM spk_items x WHERE x.spk_id=s.id) AS item_count
                    FROM spk s JOIN projects p ON p.id=s.project_id
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
    WhJson(array('success' => true, 'header' => array_merge($s, array('project_name' => $p['name'], 'client_name' => $p['client_name'])),
                 'pay' => $pay, 'items' => SpkItemsWithUsage($id), 'tasks' => $tasks, 'issue_count' => SpkIssueCount($id)));
}

function SpkLookupProjects() {
    $q = WhReq('query');
    $rows = WhRows("SELECT p.id, p.name FROM projects p WHERE p.name ILIKE $1 ORDER BY p.name LIMIT 100", array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function SpkLookupTasks() {
    $pid = (int)WhReq('project_id'); $exclude = (int)WhReq('exclude_id');
    if ($pid < 1 || !WhRow('SELECT 1 FROM projects WHERE id=$1', array($pid))) { throw new WhException('Pilih proyek yang valid.'); }
    $rows = WhRows("SELECT t.id, t.task_name, COALESCE(t.description,'') AS description, t.start_date, t.end_date, COALESCE(t.status,'') AS status
                    FROM public.project_tasks t WHERE t.project_id=$1
                      AND NOT EXISTS (SELECT 1 FROM spk_tasks st JOIN spk s ON s.id=st.spk_id
                                      WHERE st.task_id=t.id AND s.id<>$2 AND s.status NOT IN ('Dibatalkan','Selesai'))
                    ORDER BY t.start_date NULLS LAST, t.id", array($pid, $exclude));
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
    $detailsRaw = isset($_POST['details']) ? $_POST['details'] : '[]';
    $details = is_array($detailsRaw) ? $detailsRaw : json_decode((string)$detailsRaw, true);
    if (!is_array($details) || !count($details)) { throw new WhException('Tambahkan minimal satu material.'); }
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
    if (!count($lines)) { throw new WhException('Tambahkan minimal satu material.'); }

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
        if (WhRow("SELECT 1 FROM spk_tasks st JOIN spk s ON s.id=st.spk_id WHERE st.task_id=$1 AND s.id<>$2 AND s.status NOT IN ('Dibatalkan','Selesai') LIMIT 1", array($tid, $id))) {
            throw new WhException('Task sudah terikat pada SPK aktif lain.');
        }
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
        WhQ('UPDATE spk SET spk_date=$2, project_id=$3, foreman=$4, description=$5, remark=$6, updated_at=now() WHERE id=$1',
            array($id, $date, $projectId, $foreman, $desc, $remark));
        WhQ('DELETE FROM spk_items WHERE spk_id=$1', array($id));
        WhQ('DELETE FROM spk_tasks WHERE spk_id=$1', array($id));
        $spkNo = $s['spk_no'];
    } else {
        $spkNo = CreateRefNo('spk', 'spk_no', 'SPK');
        $h = WhRow("INSERT INTO spk (spk_no, spk_date, project_id, foreman, description, status, remark, created_by)
                    VALUES ($1,$2,$3,$4,$5,'Draft',$6,$7) RETURNING id",
                   array($spkNo, $date, $projectId, $foreman, $desc, $remark, $userId));
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

    $qt = SpkMaterialQuota($pid, $id);
    $pay = $qt['pay'];
    if ($pay['paid_amount'] <= 0) { throw new WhException('SPK belum dapat diterbitkan: proyek ini belum menerima pembayaran termin.'); }
    if ($pay['allowed_pct'] <= 0) { throw new WhException('SPK belum dapat diterbitkan: nilai kontrak proyek belum diisi atau pembayaran belum tercatat.'); }

    foreach (SpkItemsWithUsage($id) as $it) {
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
    WhQ("UPDATE spk SET status='Dibatalkan', updated_at=now() WHERE id=$1", array($id));
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' dibatalkan.');
}

function SpkDelete() {
    WhRequirePost();
    SpkNeed(SPK_ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk menghapus SPK.');
    $id = (int)WhPost('id');
    WhQ('BEGIN');
    $s = SpkGet($id, true);
    if ($s['status'] !== 'Draft') { throw new WhException('Hanya SPK berstatus Draft yang dapat dihapus. Gunakan Batalkan untuk SPK lain.'); }
    WhQ('DELETE FROM spk WHERE id=$1', array($id));       // spk_items ikut terhapus (ON DELETE CASCADE)
    WhQ('COMMIT');
    WhOk('SPK ' . $s['spk_no'] . ' dihapus.');
}