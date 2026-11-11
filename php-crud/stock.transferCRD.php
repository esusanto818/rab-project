<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * stocktransferCRD.php — Stock Transfer antar gudang
 * Posting: OUT di gudang asal (alokasi FIFO) + IN di gudang tujuan dengan biaya layer yang sama.
 * Action: list | detail | lookup_warehouses | lookup_items | save | reverse
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

define('TRANSFER_REF_TYPE', 'STOCK_TRANSFER');

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
if ($action === 'reverse') $stockRequired = 4;
if ($stockAccess < $stockRequired) {
    http_response_code(403);
    WhFail('Hak akses tidak mencukupi untuk operasi persediaan ini.');
}

try {
    switch ($action) {
        case 'list':              TrfList(); break;
        case 'detail':            TrfDetail(); break;
        case 'lookup_warehouses': TrfLookupWarehouses(); break;
        case 'lookup_items':      TrfLookupItems(); break;
        case 'save':              TrfSave(); break;
        case 'reverse':           TrfReverse(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('stocktransferCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
function TrfList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR wf.name ILIKE $1 OR wt.name ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM stock_transfer_hdr h
                JOIN warehouses wf ON wf.id=h.from_warehouse_id
                JOIN warehouses wt ON wt.id=h.to_warehouse_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.transfer_date, h.from_warehouse_id, wf.name AS from_warehouse_name,
                           h.to_warehouse_id, wt.name AS to_warehouse_name, h.status, h.remark,
                           COALESCE(t.total_qty,0) AS total_qty, COALESCE(t.total_amount,0) AS total_amount
                    FROM stock_transfer_hdr h
                    JOIN warehouses wf ON wf.id=h.from_warehouse_id
                    JOIN warehouses wt ON wt.id=h.to_warehouse_id
                    LEFT JOIN (SELECT transfer_hdr_id, SUM(qty) AS total_qty, SUM(qty*unit_cost) AS total_amount
                               FROM stock_transfer_dtl GROUP BY transfer_hdr_id) t ON t.transfer_hdr_id=h.id
                    WHERE $where ORDER BY h.transfer_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function TrfDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT d.id, d.item_id, m.material_code, m.material_name, m.unit,
                           d.qty, d.unit_cost, (d.qty*d.unit_cost) AS amount, d.remark
                    FROM stock_transfer_dtl d JOIN materials m ON m.id=d.item_id
                    WHERE d.transfer_hdr_id=$1 ORDER BY d.id", array($id));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function TrfLookupWarehouses() {
    $rows = WhRows("SELECT w.id, w.code, w.name, COALESCE(p.name,'-') AS project_name
                    FROM warehouses w LEFT JOIN projects p ON p.id=w.project_id
                    WHERE w.active=true ORDER BY w.name");
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Item yang punya stok di gudang asal
function TrfLookupItems() {
    $wid = (int)WhReq('warehouse_id');
    $q   = WhReq('query');
    $rows = WhRows("SELECT m.id AS item_id, m.material_code, m.material_name, m.unit, oh.onhand
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id=$1 GROUP BY item_id) oh ON oh.item_id=m.id
                    WHERE oh.onhand > 0 AND (m.material_name ILIKE $2 OR m.material_code ILIKE $2)
                    ORDER BY m.material_name LIMIT 50", array($wid, '%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function TrfSave() {
    WhRequirePost();
    $from    = (int)WhPost('from_warehouse_id');
    $to      = (int)WhPost('to_warehouse_id');
    $date    = WhDate(WhPost('transfer_date'), 'Tanggal transfer');
    $remark  = WhPost('remark');
    $details = WhDetails();
    $userId  = WhUserId();

    if ($from < 1 || $to < 1) { throw new WhException('Gudang asal dan tujuan wajib dipilih.'); }
    if ($from === $to)        { throw new WhException('Gudang asal dan tujuan tidak boleh sama.'); }
    $c = WhRow('SELECT COUNT(*) AS n FROM warehouses WHERE active=true AND id IN ($1,$2)', array($from, $to));
    if ((int)$c['n'] !== 2) { throw new WhException('Gudang asal / tujuan tidak ditemukan atau tidak aktif.'); }

    $lines = array();   // gabung duplikat per item
    foreach ($details as $d) {
        $iid = isset($d['item_id']) ? (int)$d['item_id'] : 0;
        $qty = isset($d['qty']) ? WhNum($d['qty']) : 0;
        if ($iid < 1)  { throw new WhException('Item pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty transfer harus lebih besar dari 0.'); }
        $lines[$iid] = round((isset($lines[$iid]) ? $lines[$iid] : 0) + $qty, 4);
    }

    WhQ('BEGIN');
    $refNo = CreateRefNo('stock_transfer_hdr', 'ref_no', 'STF');
    $h = WhRow("INSERT INTO stock_transfer_hdr (ref_no, transfer_date, from_warehouse_id, to_warehouse_id, status, remark, created_by)
                VALUES ($1,$2,$3,$4,'posted',$5,$6) RETURNING id",
               array($refNo, $date, $from, $to, $remark, $userId));
    $hdrId = (int)$h['id'];

    ksort($lines);   // urutan lock konsisten
    foreach ($lines as $iid => $qty) {
        WhLock($iid, min($from, $to));
        WhLock($iid, max($from, $to));
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($iid));
        if (!$m) { throw new WhException('Item ID ' . $iid . ' tidak ditemukan.'); }
        $onhand = WhOnHand($iid, $from);
        if ($qty > $onhand + 0.00005) {
            throw new WhException('Qty transfer ' . $m['material_name'] . ' (' . WhFmt($qty) .
                ') melebihi stok gudang asal (' . WhFmt($onhand) . ').');
        }
        foreach (WhFifoAllocate($iid, $from, $qty) as $ly) {
            WhQ("INSERT INTO stock_transfer_dtl (transfer_hdr_id, item_id, qty, unit_cost) VALUES ($1,$2,$3,$4)",
                array($hdrId, $iid, $ly['qty'], $ly['unit_cost']));
            WhPostMovement($date, $iid, $from, 'OUT', $ly['qty'], $ly['unit_cost'], TRANSFER_REF_TYPE, $hdrId, $refNo, 'Transfer keluar', $userId);
            WhPostMovement($date, $iid, $to,   'IN',  $ly['qty'], $ly['unit_cost'], TRANSFER_REF_TYPE, $hdrId, $refNo, 'Transfer masuk',  $userId);
        }
    }
    WhQ('COMMIT');
    WhOk('Stock transfer berhasil diposting. No: ' . $refNo, array('id' => $hdrId, 'ref_no' => $refNo));
}

function TrfReverse() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM stock_transfer_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'posted') { throw new WhException('Hanya dokumen berstatus posted yang dapat di-reverse.'); }
    WhQ("UPDATE stock_transfer_hdr SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1",
        array($id, WhUserId()));
    WhDeleteMovementsByRef(TRANSFER_REF_TYPE, $id);   // ditolak bila stok tujuan sudah terpakai
    WhQ('COMMIT');
    WhOk('Stock transfer berhasil di-reverse.');
}