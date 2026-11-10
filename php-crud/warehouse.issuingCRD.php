<?php
/**
 * warehouse.issuingCRD.php — Warehouse Issuing (material out ke proyek)
 *
 * Aturan issue per material:
 *   1. Material harus sudah di-RCV di gudang (stok W/H > 0).
 *   2. qty issue <= stok di W/H.
 *   3. Total issue proyek (posted) + qty ini <= RAB qty_plan x (1 + 5%).
 *      Bila melewati qty RAB tapi masih dalam +5% -> is_overbudget = true (allowance terpakai).
 *   4. [UPGRADE PROGRESS-GATED BUDGET] Total kumulatif actual cost tidak boleh melebihi plafon
 *      anggaran berjalan berdasarkan progress fisik proyek di lapangan (projects.progress) + 5% toleransi.
 *   Nilai issue = FIFO dari layer RCV (stock_movements IN).
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';
require_once '../php-func/journal.stock.php';

define('ISSUE_ALLOWANCE_PCT', 5.0);          
define('ISSUE_REF_TYPE', 'MATERIAL_OUT');    
define('ISSUE_TYPE_RETURN', 'SUPPLIER_RETURN');   

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$action = WhReq('action', 'list');

$stockUserId = (int)($_SESSION['user_id'] ?? 0);
if (!$stockUserId || $stockUserId <= 0) {
    http_response_code(401);
    WhFail('Sesi berakhir, silakan login kembali.');
}
$stockAccess = (int)($_SESSION['FormAccess'] ?? 0);
$stockRequired = 1;
if ($action === 'save') $stockRequired = 2;
if ($action === 'upload_proof') $stockRequired = 2;
if ($action === 'deliver') $stockRequired = 3;
if ($action === 'prepare') $stockRequired = 3;
if ($action === 'reverse') $stockRequired = 4;
if ($action === 'backfill_return_journal') $stockRequired = 4;
if ($stockAccess < $stockRequired) {
    http_response_code(403);
    WhFail('Hak akses tidak mencukupi untuk operasi persediaan ini.');
}

try {
    switch ($action) {
        case 'list':              IssList(); break;
        case 'detail':            IssDetail(); break;
        case 'lookup_projects':   IssLookupProjects(); break;
        case 'lookup_warehouses': IssLookupWarehouses(); break;
        case 'lookup_materials':  IssLookupMaterials(); break;
        case 'save':              IssSave(); break;
        case 'reverse':           IssReverse(); break;
        case 'prepare':           IssPrepare(); break;   
        case 'deliver':           IssDeliver(); break;   
        case 'upload_proof':      IssUploadProof(); break;   
        case 'backfill_return_journal': IssBackfillReturnJournal(); break;   // [BACKFILL-RETUR][NEW] sekali jalan: jurnal retur lama yang belum berjurnal
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('warehouse.issuingCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

function IssRabInfo($projectId, $materialId) {
    $rab = WhRow("SELECT COALESCE(SUM(qty_plan),0) AS q FROM budget_items
                  WHERE project_id=$1 AND material_id=$2", array((int)$projectId, (int)$materialId));
    $iss = WhRow("SELECT COALESCE(SUM(i.qty_out),0) AS q
                  FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                  WHERE h.project_id=$1 AND i.material_id=$2 AND h.status='posted'
                    AND h.issue_type='PROJECT'", 
                 array((int)$projectId, (int)$materialId));
    return array('rab_qty' => round((float)$rab['q'], 4), 'issued' => round((float)$iss['q'], 4));
}

/**
 * [NEW PROGRESS-GATED VALIDATION] Validasi pengeluaran material terhadap progress fisik proyek
 */
function validateMaterialOutAgainstProgress($projectId, $newMaterialCost) {
    $proj = WhRow("SELECT budget, COALESCE(progress, 0) AS progress FROM public.projects WHERE id = $1", array((int)$projectId));
    if (!$proj) {
        throw new WhException('Proyek tidak ditemukan.');
    }

    $totalBudget = floatval($proj['budget']);
    $currentProgress = intval($proj['progress']); 
    $tolerancePct = 5.0; 

    if ($totalBudget <= 0) {
        return;
    }

    $allowedBudgetLimit = ($totalBudget * ($currentProgress / 100.0)) * (1 + ($tolerancePct / 100.0));

    $spentData = WhRow("SELECT COALESCE(SUM(i.qty_out * i.unit_cost), 0) AS total_spent 
                        FROM material_out_header h 
                        JOIN material_out_items i ON i.material_out_id = h.id 
                        WHERE h.project_id = $1 AND h.status = 'posted' AND h.issue_type = 'PROJECT'", 
                       array((int)$projectId));
    $currentSpent = floatval($spentData['total_spent']);
    $projectedSpent = $currentSpent + $newMaterialCost;

    if ($projectedSpent > $allowedBudgetLimit) {
        throw new WhException(sprintf(
            "TRANSAKSI DITOLAK: Pemakaian anggaran melampaui batas progress fisik saat ini! Progress fisik: %%d%%%. Plafon diizinkan: Rp %%s. Total pengajuan: Rp %%s.",
            $currentProgress,
            number_format($allowedBudgetLimit, 2, ',', '.'),
            number_format($projectedSpent, 2, ',', '.')
        ));
    }
}

function IssNextSjNo($date) {
    $ts = strtotime($date);
    $prefix = 'SJ-' . date('ym', $ts ? $ts : time()) . '-';
    WhRow('SELECT pg_advisory_xact_lock(hashtext($1)) AS l', array('material_out_sj_no'));
    $r = WhRow('SELECT COALESCE(MAX(CAST(RIGHT(delivery_note_no, 5) AS integer)), 0) AS n
                FROM material_out_header WHERE delivery_note_no ~ $1', array('^' . $prefix . '[0-9]{5}$'));
    return $prefix . str_pad((string)((int)$r['n'] + 1), 5, '0', STR_PAD_LEFT);
}

function IssList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR p.name ILIKE $1 OR h.received_by_mandor ILIKE $1 OR rt.ref_no ILIKE $1
                   OR h.delivery_note_no ILIKE $1 OR h.delivery_receiver ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM material_out_header h
                JOIN projects p ON p.id=h.project_id
                LEFT JOIN supplier_return_hdr rt ON rt.material_out_id=h.id
                WHERE $where", $params);

    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.project_id, p.name AS project_name,
                           h.warehouse_id, w.name AS warehouse_name, h.issue_date,
                           h.received_by_mandor, h.status, h.remark, h.issue_type,
                           h.prepared_at, h.delivery_date, h.delivery_note_no, h.delivery_receiver, h.delivery_proof,
                           rt.ref_no AS return_ref_no, rt.status AS return_status,
                           rt.sent_date, rt.carrier, rs.name AS return_supplier_name,
                           COALESCE(t.total_amount,0) AS total_amount,
                           COALESCE(t.line_count,0)   AS line_count,
                           COALESCE(t.has_over,false) AS has_overbudget
                    FROM material_out_header h
                    JOIN projects   p ON p.id=h.project_id
                    JOIN warehouses w ON w.id=h.warehouse_id
                    LEFT JOIN supplier_return_hdr rt ON rt.material_out_id=h.id
                    LEFT JOIN m_suppliers rs ON rs.id=rt.supplier_id
                    LEFT JOIN (SELECT material_out_id, SUM(qty_out*unit_cost) AS total_amount,
                                      COUNT(*) AS line_count, BOOL_OR(is_overbudget) AS has_over
                               FROM material_out_items GROUP BY material_out_id) t ON t.material_out_id=h.id
                    WHERE $where
                    ORDER BY h.issue_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    foreach ($rows as &$r) { $r['has_overbudget'] = WhBool($r['has_overbudget']); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function IssDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT i.id, i.material_id, m.material_code, m.material_name, m.unit,
                           i.qty_out, i.unit_cost, (i.qty_out*i.unit_cost) AS amount,
                           i.is_overbudget, i.overbudget_reason, i.notes
                    FROM material_out_items i JOIN materials m ON m.id=i.material_id
                    WHERE i.material_out_id=$1 ORDER BY i.id", array($id));
    foreach ($rows as &$r) { $r['is_overbudget'] = WhBool($r['is_overbudget']); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssLookupProjects() {
    $q = WhReq('query');
    $rows = WhRows("SELECT id, name FROM projects WHERE name ILIKE $1 ORDER BY name LIMIT 100",
                   array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssLookupWarehouses() {
    $pid = (int)WhReq('project_id');
    $rows = WhRows("SELECT w.id, w.code,
                           (w.name || ' (' || oh.item_count || ' material)') AS name
                    FROM warehouses w
                    JOIN (SELECT warehouse_id, COUNT(*) AS item_count
                          FROM (SELECT warehouse_id, item_id
                                FROM stock_movements
                                GROUP BY warehouse_id, item_id
                                HAVING SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) > 0) x
                          GROUP BY warehouse_id) oh ON oh.warehouse_id = w.id
                    WHERE w.active = true
                    ORDER BY (w.project_id = $1) DESC NULLS LAST, w.name", array($pid));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssLookupMaterials() {
    $pid = (int)WhReq('project_id');
    $wid = (int)WhReq('warehouse_id');
    $q   = WhReq('query');
    $rows = WhRows("SELECT m.id AS material_id, m.material_code, m.material_name, m.unit,
                           oh.onhand,
                           COALESCE(rab.rab_qty,0) AS rab_qty,
                           COALESCE(iss.issued,0)  AS issued
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id=$2 GROUP BY item_id) oh ON oh.item_id=m.id
                    LEFT JOIN (SELECT material_id, SUM(qty_plan) AS rab_qty FROM budget_items
                               WHERE project_id=$1 AND material_id IS NOT NULL GROUP BY material_id) rab
                           ON rab.material_id=m.id
                    LEFT JOIN (SELECT i.material_id, SUM(i.qty_out) AS issued
                               FROM material_out_items i JOIN material_out_header h ON h.id=i.material_out_id
                               WHERE h.project_id=$1 AND h.status='posted' AND h.issue_type='PROJECT'
                               GROUP BY i.material_id) iss
                           ON iss.material_id=m.id
                    WHERE oh.onhand > 0 AND (m.material_name ILIKE $3 OR m.material_code ILIKE $3)
                    ORDER BY m.material_name LIMIT 50",
                   array($pid, $wid, '%' . $q . '%'));
    foreach ($rows as &$r) {
        $rab = (float)$r['rab_qty'];
        $limit = round($rab * (1 + ISSUE_ALLOWANCE_PCT / 100), 4);
        $remain = max(0, round($limit - (float)$r['issued'], 4));
        $r['limit_qty'] = $limit;                                   
        $r['max_issue'] = ($rab > 0) ? min((float)$r['onhand'], $remain) : 0;   
    }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function IssSave() {
    WhRequirePost();
    $projectId = (int)WhPost('project_id');
    $whId      = (int)WhPost('warehouse_id');
    $date      = WhDate(WhPost('issue_date'), 'Tanggal issue');
    $mandor    = WhPost('received_by_mandor');
    $remark    = WhPost('remark');
    $details   = WhDetails();
    $userId    = WhUserId();

    if ($projectId < 1) { throw new WhException('Proyek wajib dipilih.'); }
    if ($whId < 1)      { throw new WhException('Gudang wajib dipilih.'); }

    $wh = WhRow('SELECT id, project_id, active FROM warehouses WHERE id=$1', array($whId));
    if (!$wh || !WhBool($wh['active'])) { throw new WhException('Gudang tidak ditemukan / tidak aktif.'); }

    $lines = array();
    foreach ($details as $d) {
        $mid = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        $qty = isset($d['qty_out']) ? WhNum($d['qty_out']) : 0;
        if ($mid < 1) { throw new WhException('Material pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty issue harus lebih besar dari 0.'); }
        if (!isset($lines[$mid])) { $lines[$mid] = array('qty' => 0, 'notes' => ''); }
        $lines[$mid]['qty'] = round($lines[$mid]['qty'] + $qty, 4);
        if (!empty($d['notes'])) { $lines[$mid]['notes'] = trim($d['notes']); }
    }

    WhQ('BEGIN');
    
    $estimatedTotalValue = 0.0;
    foreach ($lines as $mid => $ln) {
        $qty = $ln['qty'];
        $lyEst = WhFifoAllocate($mid, $whId, $qty);
        foreach ($lyEst as $ly) {
            $estimatedTotalValue += ($ly['qty'] * $ly['unit_cost']);
        }
    }

    validateMaterialOutAgainstProgress($projectId, $estimatedTotalValue);

    $refNo = CreateRefNo('material_out_header', 'ref_no', 'GI');
    $h = WhRow("INSERT INTO material_out_header
                    (ref_no, project_id, warehouse_id, issue_date, issued_by, received_by_mandor, status, remark)
                VALUES ($1,$2,$3,$4,$5,$6,'posted',$7) RETURNING id",
               array($refNo, $projectId, $whId, $date, $userId, $mandor, $remark));
    $hdrId = (int)$h['id'];
    $issueValue = 0.0;                              

    foreach ($lines as $mid => $ln) {
        $qty = $ln['qty'];
        WhLock($mid, $whId);          
        WhLock($mid, 0);              
        $mat = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        if (!$mat) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan.'); }
        $name = $mat['material_name'];

        $onhand = WhOnHand($mid, $whId);
        if ($onhand <= 0) { throw new WhException("Material {$name} belum di-RCV / stok di gudang kosong."); }
        if ($qty > $onhand + 0.00005) {
            throw new WhException("Qty issue {$name} (" . WhFmt($qty) . ") melebihi stok di gudang (" . WhFmt($onhand) . ").");
        }
        
        $info = IssRabInfo($projectId, $mid);
        if ($info['rab_qty'] <= 0) { throw new WhException("Material {$name} tidak ada di RAB proyek ini."); }
        $limit = round($info['rab_qty'] * (1 + ISSUE_ALLOWANCE_PCT / 100), 4);
        $after = round($info['issued'] + $qty, 4);
        if ($after > $limit + 0.00005) {
            throw new WhException("Issue {$name} melebihi batas RAB +" . ISSUE_ALLOWANCE_PCT . "% (RAB " .
                WhFmt($info['rab_qty']) . ", batas " . WhFmt($limit) . ", sudah di-issue " . WhFmt($info['issued']) . ").");
        }
        $over   = ($after > $info['rab_qty'] + 0.00005);
        $reason = $over ? ('Allowance +' . ISSUE_ALLOWANCE_PCT . '% dari RAB') : null;

        $layers = WhFifoAllocate($mid, $whId, $qty);
        foreach ($layers as $ly) {
            WhQ("INSERT INTO material_out_items
                    (material_out_id, material_id, qty_out, unit_cost, is_overbudget, overbudget_reason, notes)
                 VALUES ($1,$2,$3,$4,$5,$6,$7)",
                array($hdrId, $mid, $ly['qty'], round($ly['unit_cost'], 2), $over ? 't' : 'f', $reason, $ln['notes']));
            WhPostMovement($date, $mid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'],
                           ISSUE_REF_TYPE, $hdrId, $refNo, 'Issue ke proyek', $userId);
            $issueValue += $ly['qty'] * $ly['unit_cost'];   
        }
    }
    
    JrnPost('ISSUE_PROJECT', JRN_REF_ISSUE, $hdrId, $date, $issueValue,
            'Issue material ke proyek - ' . $refNo, $userId);
    WhQ('COMMIT');
    WhOk('Issuing berhasil diposting. No: ' . $refNo, array('id' => $hdrId, 'ref_no' => $refNo));
}

function IssReverse() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, issue_type FROM material_out_header WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'posted') { throw new WhException('Hanya dokumen berstatus posted yang dapat di-reverse.'); }
    if ($h['issue_type'] !== 'PROJECT') { throw new WhException('Dokumen ini adalah issue Retur Supplier. Gunakan Batal Kirim di modul Retur Supplier.'); }
    WhQ("UPDATE material_out_header SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1",
        array($id, WhUserId()));
    JrnReverseByRef(JRN_REF_ISSUE, $id, date('Y-m-d'), WhUserId(), 'Reverse issue');
    WhDeleteMovementsByRef(ISSUE_REF_TYPE, $id);   
    WhQ('COMMIT');
    WhOk('Dokumen issuing berhasil di-reverse.');
}

function IssPrepare() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, issue_type FROM material_out_header WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['issue_type'] !== ISSUE_TYPE_RETURN) { throw new WhException('Siap Kirim hanya untuk issue Retur Supplier.'); }
    if ($h['status'] !== 'pending') { throw new WhException('Hanya dokumen berstatus pending yang dapat ditandai Siap Kirim.'); }
    $sjNo = IssNextSjNo(date('Y-m-d'));       
    WhQ("UPDATE material_out_header SET status='prepared', prepared_at=now(), prepared_by=$2, delivery_note_no=$3 WHERE id=$1",
        array($id, WhUserId(), $sjNo));
    WhQ("UPDATE supplier_return_hdr SET status='Disiapkan', updated_at=now() WHERE material_out_id=$1 AND status='Diperintahkan'",
        array($id));
    WhQ('COMMIT');
    WhOk('Barang ditandai sudah disiapkan (Siap Kirim). No. Surat Jalan: ' . $sjNo . '. Menunggu konfirmasi pengiriman.', array('delivery_note_no' => $sjNo));   
}

function IssDeliver() {
    WhRequirePost();
    $id       = (int)WhPost('id');
    $date     = WhDate(WhPost('delivery_date'), 'Tanggal kirim');
    $carrier  = trim(WhPost('carrier'));
    $receiver = trim(WhPost('receiver'));
    $proof    = trim(WhPost('delivery_proof'));
    $userId   = WhUserId();
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    if ($carrier === '')  { throw new WhException('Kurir / kendaraan pengiriman wajib diisi.'); }
    if ($receiver === '') { throw new WhException('Nama penerima wajib diisi.'); }
    
    $proofs = array();
    foreach (preg_split('/\r\n|\r|\n/', $proof) as $u) {
        $u = trim($u);
        if ($u === '') { continue; }
        if (!preg_match('#^(https?://|/)#i', $u)) { throw new WhException('Bukti harus berupa file yang diunggah atau link http(s).'); }
        $proofs[] = $u;
    }
    if (count($proofs) > 5) { throw new WhException('Bukti maksimal 5 file.'); }
    $proof = implode("\n", $proofs);

    WhQ('BEGIN');
    $h = WhRow('SELECT id, ref_no, status, issue_type, warehouse_id, delivery_note_no FROM material_out_header WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['issue_type'] !== ISSUE_TYPE_RETURN) { throw new WhException('Konfirmasi dikirim hanya untuk issue Retur Supplier.'); }
    if ($h['status'] === 'pending') { throw new WhException('Tandai Siap Kirim terlebih dahulu sebelum konfirmasi dikirim.'); }
    if ($h['status'] !== 'prepared') { throw new WhException('Hanya dokumen berstatus prepared yang dapat dikonfirmasi dikirim.'); }
    $rt = WhRow('SELECT id, ref_no FROM supplier_return_hdr WHERE material_out_id=$1 FOR UPDATE', array($id));
    if (!$rt) { throw new WhException('Dokumen retur asal tidak ditemukan.'); }
    $whId  = (int)$h['warehouse_id'];
    $refNo = $h['ref_no'];
    
    $sjNo = trim((string)$h['delivery_note_no']);
    if ($sjNo === '') { $sjNo = IssNextSjNo($date); }
    $dtl = WhRows('SELECT id, material_id, qty_claim, defect_type FROM supplier_return_dtl WHERE return_hdr_id=$1 ORDER BY material_id, id',
                  array((int)$rt['id']));
    if (!count($dtl)) { throw new WhException('Retur belum memiliki item.'); }

    WhQ('DELETE FROM material_out_items WHERE material_out_id=$1', array($id));   
    $value = 0.0;
    foreach ($dtl as $d) {
        $mid = (int)$d['material_id'];
        $qty = round((float)$d['qty_claim'], 4);
        WhLock($mid, $whId);
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        $onhand = WhOnHand($mid, $whId);
        if ($qty > $onhand + 0.00005) {
            throw new WhException('Stok ' . $m['material_name'] . ' di gudang (' . WhFmt($onhand) .
                ') tidak cukup untuk dikirim (' . WhFmt($qty) . ').');
        }
        $tot = 0.0;
        foreach (WhFifoAllocate($mid, $whId, $qty) as $ly) {
            WhQ("INSERT INTO material_out_items (material_out_id, material_id, qty_out, unit_cost, is_overbudget, notes)
                 VALUES ($1,$2,$3,$4,'f',$5)",
                array($id, $mid, $ly['qty'], round($ly['unit_cost'], 2), 'Retur cacat: ' . $d['defect_type']));
            WhPostMovement($date, $mid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'], ISSUE_REF_TYPE, $id, $refNo,
                           'Retur supplier ' . $rt['ref_no'], $userId);
            $tot += $ly['qty'] * $ly['unit_cost'];
        }
        WhQ('UPDATE supplier_return_dtl SET unit_cost=$2 WHERE id=$1', array((int)$d['id'], round($tot / $qty, 4)));
        $value += $tot;
    }
    
    JrnPost('RETURN_SUPPLIER', JRN_REF_RETURN, (int)$rt['id'], $date, $value, 'Retur supplier ' . $rt['ref_no'], $userId);

    WhQ("UPDATE material_out_header SET status='posted', issue_date=$2, received_by_mandor=$3, delivery_date=$2,
                delivery_note_no=$4, delivery_receiver=$5, delivery_proof=$6 WHERE id=$1",
        array($id, $date, $carrier, $sjNo, $receiver, $proof !== '' ? $proof : null));      
    WhQ("UPDATE supplier_return_hdr SET status='Dikirim', sent_date=$2, carrier=$3, updated_at=now() WHERE id=$1",
        array((int)$rt['id'], $date, $carrier));
    WhQ('COMMIT');
    WhOk('Pengiriman dikonfirmasi. No. Surat Jalan: ' . $sjNo . '. Stok keluar dari gudang dan retur ' . $rt['ref_no'] . ' berstatus Dikirim.', array('delivery_note_no' => $sjNo));   
}

function IssUploadProof() {
    WhRequirePost();
    WhUserId();                                              
    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $code = isset($_FILES['file']['error']) ? (int)$_FILES['file']['error'] : -1;
        throw new WhException('File gagal diunggah (kode ' . $code . '). Cek batas upload_max_filesize / post_max_size di php.ini.');
    }
    $f = $_FILES['file'];
    if ($f['size'] > 5 * 1024 * 1024) { throw new WhException('Ukuran file maksimal 5 MB.'); }
    $allowed = array('jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png',
                     'webp' => 'image/webp', 'gif' => 'image/gif', 'pdf' => 'application/pdf');
    $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
    if (!isset($allowed[$ext])) { throw new WhException('Tipe file tidak diizinkan. Gunakan JPG, PNG, WEBP, GIF atau PDF.'); }
    if (function_exists('finfo_open')) {                     
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
        if ($mime !== $allowed[$ext]) { throw new WhException('Isi file tidak sesuai dengan ekstensinya.'); }
    }
    $dir = dirname(__DIR__) . '/uploads/delivery';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new WhException('Folder uploads/delivery tidak bisa dibuat. Buat manual dan beri izin tulis untuk user web server.');
    }
    $ht = $dir . '/.htaccess';                               
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|pl|cgi)$\">\n    Require all denied\n</FilesMatch>\n");   // [FIX-HTACCESS] tanda kutip penutup pola FilesMatch sebelumnya hilang ($\>)
    }
    $name = 'dlv_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new WhException('Gagal menyimpan file. Cek izin tulis folder uploads/delivery.');
    }
    WhJson(array('success' => true, 'url' => '/rab-project/uploads/delivery/' . $name, 'name' => $name));
}

// [BACKFILL-RETUR][NEW] Backfill sekali jalan: jurnal retur supplier (Dr GRN Clearing / Cr Persediaan, rule RETURN_SUPPLIER)
// untuk retur yang sudah dikirim (issue posted) tetapi belum punya jurnal -- yaitu retur yang dikirim sebelum fitur jurnal ada.
// Idempotent (retur yang sudah berjurnal dilewati). Satu transaksi: gagal satu (mis. periode ditutup) = batal semua.
// Tanggal jurnal = tanggal kirim (delivery_date, bila kosong issue_date).
function IssBackfillReturnJournal() {
    // Backfill mengubah jurnal: wajib POST agar tidak dapat dipicu melalui tautan / prefetch browser.
    WhRequirePost();
    $userId = WhUserId();
    if (intval(isset($_SESSION['FormAccess']) ? $_SESSION['FormAccess'] : 0) < 3) { throw new WhException('Akses ditolak.'); }
    WhQ('BEGIN');
    $rows = WhRows("SELECT r.id, r.ref_no, COALESCE(mo.delivery_date, mo.issue_date, r.sent_date) AS jdate,
                           COALESCE((SELECT SUM(i.qty_out*i.unit_cost) FROM material_out_items i WHERE i.material_out_id=mo.id),0) AS total
                    FROM supplier_return_hdr r
                    JOIN material_out_header mo ON mo.id=r.material_out_id AND mo.status='posted'
                    WHERE r.status IN ('Dikirim','Diganti','Kredit')
                      AND NOT EXISTS (SELECT 1 FROM journal_entries je
                                       WHERE je.reference_type::text=$1 AND je.reference_id=r.id)
                    ORDER BY jdate ASC, r.id ASC", array(JRN_REF_RETURN));
    $done = array(); $skipped = array();
    foreach ($rows as $r) {
        $jid = JrnPost('RETURN_SUPPLIER', JRN_REF_RETURN, (int)$r['id'], $r['jdate'], $r['total'],
                       'Retur supplier ' . $r['ref_no'], $userId);
        if ($jid) { $done[] = $r['ref_no']; } else { $skipped[] = $r['ref_no']; }     // nilai 0 -> tidak dijurnal
    }
    WhQ('COMMIT');
    $msg = count($done) . ' jurnal retur diposting' . (count($done) ? ': ' . implode(', ', $done) : '.');
    if (count($skipped)) { $msg .= ' | dilewati (nilai 0): ' . implode(', ', $skipped); }
    WhOk($msg, array('posted' => $done, 'skipped' => $skipped));
}
?>