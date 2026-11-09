<?php
/**
* stock.cardCRD.php — Stock Card (kartu stok per item, per gudang / semua gudang)
* Output: baris "Saldo Awal" + mutasi periode + running balance, plus ringkasan.
* Action: card | lookup_warehouses | lookup_items
*
* [FINAL FIX 2026-11-08]
* RULE:
*   - lookup_warehouses: HANYA gudang yang punya stok (ada di stock_movements)
*   - lookup_items: HANYA material yang punya stok di gudang terpilih
*     (pakai EXISTS stock_movements dengan filter warehouse_id)
*   - Posisi parameter: $1, $2, $3, ... (bukan :named) untuk pg_query_params()
*/
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

$action = WhReq('action', 'card');

try {
    switch ($action) {
        case 'card':              CardLoad(); break;
        case 'lookup_warehouses': CardLookupWarehouses(); break;
        case 'lookup_items':      CardLookupItems(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    WhFail($e->getMessage());
} catch (Exception $e) {
    WhLog('stockcardCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------
 * [RULE 1] Hanya gudang yang memiliki stok (pernah ada mutasi)
 * ------------------------------------------------------------------ */
function CardLookupWarehouses() {
    $sql = "SELECT DISTINCT w.id, w.name 
            FROM warehouses w 
            WHERE w.active = true 
            AND EXISTS (
                SELECT 1 FROM stock_movements s 
                WHERE s.warehouse_id = w.id
            )
            ORDER BY w.name";
    
    $rows = WhRows($sql);
    
    // Tambahkan opsi "-- Semua Gudang --" di awal
    array_unshift($rows, array('id' => 0, 'name' => '-- Semua Gudang --'));
    
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

/* ------------------------------------------------------------------
 * [RULE 2] Material HANYA yang punya stok di gudang terpilih
 * warehouse_id WAJIB diisi (tidak boleh 0/kosong)
 * Posisi parameter: $1 = query, $2 = warehouse_id
 * ------------------------------------------------------------------ */
function CardLookupItems() {
    $wid = (int)WhReq('warehouse_id');
    $q   = trim(WhReq('query'));
    
    // [RULE] warehouse_id wajib untuk filter material
    if ($wid <= 0) {
        WhJson(array('success' => true, 'totalCount' => 0, 'data' => array(), 
                     'message' => 'Pilih gudang terlebih dahulu.'));
        return;
    }
    
    // Query: material yang PERNAH ada mutasi di gudang terpilih
    // Posisi parameter: $1 = pattern query, $2 = warehouse_id
    $sql = "SELECT m.id AS item_id, m.material_code, m.material_name, m.unit
            FROM materials m
            WHERE (m.material_name ILIKE $1 OR m.material_code ILIKE $1)
            AND EXISTS (
                SELECT 1 FROM stock_movements s 
                WHERE s.item_id = m.id 
                AND s.warehouse_id = $2
            )
            ORDER BY m.material_name 
            LIMIT 50";
    
    $params = array('%' . $q . '%', $wid);
    $rows = WhRows($sql, $params);
    
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

/* ------------------------------------------------------------------
 * Card Load (tidak berubah)
 * ------------------------------------------------------------------ */
function CardLoad() {
    $itemId = (int)WhReq('item_id');
    $wid    = (int)WhReq('warehouse_id');
    
    if ($itemId < 1) { throw new WhException('Item wajib dipilih.'); }
    
    $from = WhDate(WhReq('date_from'), 'Tanggal awal');
    $to   = WhDate(WhReq('date_to'), 'Tanggal akhir');
    
    if ($from > $to) { throw new WhException('Tanggal awal tidak boleh melebihi tanggal akhir.'); }
    
    $wf1 = ''; $wf2 = '';
    $p1 = array($itemId, $from);
    $p2 = array($itemId, $from, $to);
    
    if ($wid > 0) {
        $p1[] = $wid; $wf1 = ' AND warehouse_id=$3';
        $p2[] = $wid; $wf2 = ' AND s.warehouse_id=$4';
    }
    
    // Saldo awal
    $o = WhRow("SELECT COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END),0) AS q
                FROM stock_movements 
                WHERE item_id=$1 AND movement_date < $2 $wf1", $p1);
    $balance = round((float)$o['q'], 4);
    
    // Mutasi periode
    $mv = WhRows("SELECT s.id, s.movement_date, s.movement_type, s.qty, s.unit_cost,
                         s.reference_type, s.reference_no, s.remark, w.name AS warehouse_name
                  FROM stock_movements s 
                  JOIN warehouses w ON w.id=s.warehouse_id
                  WHERE s.item_id=$1 AND s.movement_date BETWEEN $2 AND $3 $wf2
                  ORDER BY s.movement_date, s.id", $p2);
    
    $data = array(array(
        'row_type' => 'OPEN', 'movement_date' => $from, 'reference_type' => '', 'reference_no' => '',
        'warehouse_name' => '', 'remark' => 'Saldo Awal', 'qty_in' => null, 'qty_out' => null,
        'unit_cost' => null, 'balance' => $balance
    ));
    
    $totIn = 0; $totOut = 0;
    foreach ($mv as $r) {
        $q = (float)$r['qty'];
        $isIn = ($r['movement_type'] === 'IN');
        $balance = round($balance + ($isIn ? $q : -$q), 4);
        if ($isIn) { $totIn += $q; } else { $totOut += $q; }
        $data[] = array(
            'row_type' => 'MOV', 'movement_date' => $r['movement_date'],
            'reference_type' => $r['reference_type'], 'reference_no' => $r['reference_no'],
            'warehouse_name' => $r['warehouse_name'], 'remark' => $r['remark'],
            'qty_in' => $isIn ? $q : null, 'qty_out' => $isIn ? null : $q,
            'unit_cost' => (float)$r['unit_cost'], 'balance' => $balance
        );
    }
    
    WhJson(array('success' => true, 'totalCount' => count($data), 'data' => $data,
        'summary' => array('opening' => (float)$o['q'], 'total_in' => round($totIn, 4),
            'total_out' => round($totOut, 4), 'ending' => $balance)));
}