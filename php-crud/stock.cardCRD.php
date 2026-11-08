<?php
/**
* stock.cardCRD.php — Stock Card (kartu stok per item, per gudang / semua gudang)
* [FIX 2026-11-08] Tambah error handling & logging untuk mobile
*/
ob_start();
ini_set('display_errors', '0');
error_reporting(E_ALL);

// [FIX] Log error ke file untuk debugging mobile
$logFile = __DIR__ . '/../logs/stockcard_errors.log';
$logDir = dirname($logFile);
if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

function sc_log($msg) {
    global $logFile;
    @file_put_contents($logFile, '[' . date('Y-m-d H:i:s') . '] ' . $msg . "\n", FILE_APPEND);
}

require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';

$action = WhReq('action', 'card');

try {
    switch ($action) {
        case 'card':              CardLoad(); break;
        case 'lookup_warehouses': CardLookupWarehouses(); break;
        case 'lookup_items':      CardLookupItems(); break;
        default: throw new WhException('Action tidak dikenali: ' . $action);
    }
} catch (WhException $e) {
    sc_log('WhException: ' . $e->getMessage());
    WhFail($e->getMessage());
} catch (Exception $e) {
    sc_log('Exception: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    WhFail('Terjadi kesalahan pada server: ' . $e->getMessage());
} catch (Throwable $e) {
    sc_log('Throwable: ' . $e->getMessage() . ' at ' . $e->getFile() . ':' . $e->getLine());
    WhFail('System error: ' . $e->getMessage());
}

/* ------------------------------------------------------------------ */
function CardLookupWarehouses() {
    sc_log('CardLookupWarehouses called');
    
    // [FIX] Cek apakah tabel warehouses ada
    try {
        $checkTable = WhRow("SELECT 1 FROM information_schema.tables 
                             WHERE table_schema = 'public' AND table_name = 'warehouses'");
        if (!$checkTable) {
            sc_log('Tabel warehouses tidak ada');
            WhJson(array('success' => true, 'totalCount' => 1, 'data' => array(
                array('id' => 0, 'name' => '-- Tabel gudang belum dibuat --')
            )));
            return;
        }
    } catch (Exception $e) {
        sc_log('Error check table: ' . $e->getMessage());
    }
    
    try {
        $rows = WhRows("SELECT id, name FROM warehouses WHERE active = true ORDER BY name");
        sc_log('Found ' . count($rows) . ' warehouses');
        
        // [FIX] Jika tidak ada gudang aktif, beri pesan
        if (empty($rows)) {
            $allRows = WhRows("SELECT id, name, active FROM warehouses ORDER BY name");
            sc_log('Active=0 warehouses: ' . count($allRows));
            
            if (!empty($allRows)) {
                $rows = array_map(function($r) {
                    return array('id' => $r['id'], 'name' => $r['name'] . ' (non-aktif)');
                }, $allRows);
            }
        }
        
        array_unshift($rows, array('id' => 0, 'name' => '-- Semua Gudang --'));
        WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
    } catch (Exception $e) {
        sc_log('CardLookupWarehouses error: ' . $e->getMessage());
        WhJson(array('success' => false, 'message' => 'Gagal memuat gudang: ' . $e->getMessage()));
    }
}

function CardLookupItems() {
    $wid = (int)WhReq('warehouse_id');
    $q   = trim(WhReq('query'));
    
    sc_log('CardLookupItems: wid=' . $wid . ', q=' . $q);
    
    try {
        // [FIX] Cek tabel materials
        $checkTable = WhRow("SELECT 1 FROM information_schema.tables 
                             WHERE table_schema = 'public' AND table_name = 'materials'");
        if (!$checkTable) {
            sc_log('Tabel materials tidak ada');
            WhJson(array('success' => true, 'totalCount' => 0, 'data' => array()));
            return;
        }
        
        // Query: SEMUA material aktif, dengan info stok di gudang terpilih
        $sql = "SELECT m.id AS item_id, m.material_code, m.material_name, m.unit,
                COALESCE(stock_info.qty_on_hand, 0) AS qty_on_hand
                FROM materials m
                LEFT JOIN (
                    SELECT s.item_id, 
                    SUM(CASE WHEN s.movement_type = 'IN' THEN s.qty ELSE -s.qty END) AS qty_on_hand
                    FROM stock_movements s
                    WHERE ($1 = 0 OR s.warehouse_id = $2)
                    GROUP BY s.item_id
                ) stock_info ON stock_info.item_id = m.id
                WHERE m.active = true
                AND ($3 = '' OR m.material_name ILIKE $4 OR m.material_code ILIKE $5)
                ORDER BY 
                    CASE WHEN COALESCE(stock_info.qty_on_hand, 0) > 0 THEN 0 ELSE 1 END,
                    m.material_name ASC
                LIMIT 50";
        
        $params = array(
            $wid,           // $1
            $wid,           // $2
            $q,             // $3
            '%' . $q . '%', // $4
            '%' . $q . '%'  // $5
        );
        
        $rows = WhRows($sql, $params);
        sc_log('Found ' . count($rows) . ' items');
        
        foreach ($rows as &$r) {
            $r['item_id'] = (int)$r['item_id'];
            $r['qty_on_hand'] = (float)($r['qty_on_hand'] ?? 0);
        }
        unset($r);
        
        WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
    } catch (Exception $e) {
        sc_log('CardLookupItems error: ' . $e->getMessage());
        WhJson(array('success' => false, 'message' => 'Gagal memuat material: ' . $e->getMessage()));
    }
}

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
    
    $o = WhRow("SELECT COALESCE(SUM(CASE WHEN movement_type='IN' THEN qty ELSE -qty END),0) AS q
                FROM stock_movements 
                WHERE item_id=$1 AND movement_date < $2 $wf1", $p1);
    $balance = round((float)$o['q'], 4);
    
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