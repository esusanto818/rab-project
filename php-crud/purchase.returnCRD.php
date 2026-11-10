<?php
/**
 * purchase.returnCRD.php — Retur Pembelian (Retur Supplier) untuk material cacat / rusak
 *
 * [CHANGED] Entry retur kini BERBASIS DOKUMEN W/H RCV:
 *   pilih satu RCV -> supplier, gudang, proyek (via GRN -> PO) dan PO terisi otomatis;
 *   daftar item = item pada RCV tsb; qty retur dibatasi:
 *      min( qty RCV - sudah diretur dari RCV ini,  stok gudang - klaim retur lain berstatus Diajukan )
 *   Gudang TIDAK lagi difilter berdasarkan warehouses.project_id (gudang utama dipakai bersama).
 *
 * Alur   : Diajukan --(Perintahkan W/H)--> Diperintahkan --(W/H: Siap Kirim)--> Disiapkan
 *          --(W/H: Konfirmasi Dikirim)--> Dikirim --(Selesaikan)--> Diganti | Kredit
 *          Diajukan --(Batalkan)--> Dibatalkan ; Diperintahkan/Disiapkan --(Batalkan Perintah)--> Diajukan
 *          Dikirim --(Batal Kirim)--> Diajukan (issue di-reverse, stok & jurnal dibalik)
 * [CHANGED 2026-10-11] 'Kirim' dipecah: Pembelian hanya MEMERINTAHKAN issue (dokumen issue status 'pending',
 *          stok belum keluar). Siap Kirim + Konfirmasi Dikirim dilakukan GUDANG di Warehouse Issuing
 *          (warehouse.issuingCRD.php: prepare / deliver) -> di situlah stok keluar FIFO + jurnal dibuat.
 * Order  : membuat dokumen ISSUE W/H (material_out_header.issue_type = 'SUPPLIER_RETURN'),
 *          stok keluar FIFO lewat stock_movements (reference_type 'MATERIAL_OUT', sama dengan Warehouse Issuing)
 *          sehingga tampil di Stock Card. Issue retur TIDAK menghitung kuota RAB +5%.
 * [NEW 2026-10-11] Jurnal otomatis (posted): Konfirmasi Dikirim di W/H -> Dr 2600 GRN Clearing / Cr 1500 Persediaan; Batal Kirim -> jurnal pembalik;
 *                  Selesaikan 'Kredit' -> Dr 2100 Hutang Usaha / Cr 2600. 'Ganti Barang' tanpa jurnal (RCV pengganti sudah menjurnal).
 *                  Mapping akun di fiscal_config (RETURN_SUPPLIER, RETURN_CREDIT_NOTE). Helper: php-func/journal.stock.php
 * Action : list | detail | lookup_rcv | rcv_items | save | order | cancel_order | unsend | resolve | cancel | upload_evidence
 * Butuh  : sql/2026-10-09.issue_return_fix.sql + sql/2026-10-09b.return_from_rcv.sql
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';
require_once '../php-func/journal.stock.php';     // [NEW 2026-10-11] jurnal otomatis (JrnPost / JrnReverseByRef)

define('ISSUE_REF_TYPE', 'MATERIAL_OUT');        // harus sama dengan warehouse.issuingCRD.php
define('ISSUE_TYPE_RETURN', 'SUPPLIER_RETURN');

$DEFECT_TYPES = array('Cacat Produksi', 'Rusak Saat Pengiriman', 'Tidak Sesuai Spesifikasi',
                      'Kualitas Di Bawah Standar', 'Lainnya');
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$action = WhReq('action', 'list');

$returnUserId = (int)($_SESSION['user_id'] ?? 0);
if ($returnUserId <= 0) { http_response_code(401); WhFail('Sesi berakhir, silakan login ulang.'); }
$returnRequiredAccess = 1;
if (in_array($action, ['save', 'upload_evidence'], true)) $returnRequiredAccess = 2;
if (in_array($action, ['order', 'resolve'], true)) $returnRequiredAccess = 3;
if (in_array($action, ['cancel_order', 'unsend', 'cancel'], true)) $returnRequiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $returnRequiredAccess) {
    http_response_code(403);
    WhFail('Hak akses tidak mencukupi untuk proses retur pembelian.');
}


try {
    switch ($action) {
        case 'list':        RtList(); break;
        case 'detail':      RtDetail(); break;
        case 'lookup_rcv':  RtLookupRcv(); break;   // [CHANGED] pengganti lookup proyek/supplier/gudang/PO/material
        case 'rcv_items':   RtRcvItems(); break;    // [CHANGED] item RCV + qty yang masih boleh diretur
        case 'save':        RtSave(); break;
        case 'order':       RtOrder(); break;          // [CHANGED] pengganti 'send': buat perintah issue (pending)
        case 'cancel_order': RtCancelOrder(); break;   // [NEW] batalkan perintah issue sebelum dikirim
        case 'unsend':      RtUnsend(); break;
        case 'resolve':     RtResolve(); break;
        case 'cancel':      RtCancel(); break;
        case 'upload_evidence': RtUploadEvidence(); break;   // [CHANGED] unggah foto / PDF bukti cacat
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('purchase.returnCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
// Qty yang sudah diklaim di retur lain yang stoknya BELUM keluar -> dicadangkan
// [CHANGED] status yang dicadangkan: Diajukan / Diperintahkan / Disiapkan
function RtOpenClaim($whId, $materialId, $exclHdrId) {
    $r = WhRow("SELECT COALESCE(SUM(d.qty_claim),0) AS q
                FROM supplier_return_dtl d JOIN supplier_return_hdr h ON h.id=d.return_hdr_id
                WHERE h.warehouse_id=$1 AND d.material_id=$2
                  AND h.status IN ('Diajukan','Diperintahkan','Disiapkan') AND h.id<>$3",
               array((int)$whId, (int)$materialId, (int)$exclHdrId));
    return round((float)$r['q'], 4);
}

// [CHANGED] Qty material yang sudah diretur dari RCV tertentu (retur Dibatalkan tidak dihitung)
function RtReturnedFromRcv($rcvId, $materialId, $exclHdrId) {
    $r = WhRow("SELECT COALESCE(SUM(d.qty_claim),0) AS q
                FROM supplier_return_dtl d JOIN supplier_return_hdr h ON h.id=d.return_hdr_id
                WHERE h.wh_receiving_hdr_id=$1 AND d.material_id=$2 AND h.status<>'Dibatalkan' AND h.id<>$3",
               array((int)$rcvId, (int)$materialId, (int)$exclHdrId));
    return round((float)$r['q'], 4);
}

// [CHANGED] Header RCV posted + turunan proyek / PO / supplier (via GRN -> PO)
function RtRcvHeader($rcvId) {
    return WhRow("SELECT r.id, r.ref_no, r.received_date, r.warehouse_id, w.name AS warehouse_name,
                         g.ref_no AS grn_no, po.id AS po_id, po.po_number, po.project_id, p.name AS project_name,
                         COALESCE(g.supplier_id, po.supplier_id) AS supplier_id, s.name AS supplier_name
                  FROM wh_receiving_hdr r
                  JOIN grn_hdr g ON g.id=r.grn_hdr_id
                  JOIN purchase_orders po ON po.id=g.po_id
                  JOIN projects p ON p.id=po.project_id
                  JOIN warehouses w ON w.id=r.warehouse_id
                  LEFT JOIN m_suppliers s ON s.id=COALESCE(g.supplier_id, po.supplier_id)
                  WHERE r.id=$1 AND r.status='posted'", array((int)$rcvId));
}

function RtList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR p.name ILIKE $1 OR s.name ILIKE $1 OR h.supplier_do_no ILIKE $1 OR rc.ref_no ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM supplier_return_hdr h
                JOIN projects p ON p.id=h.project_id JOIN m_suppliers s ON s.id=h.supplier_id
                LEFT JOIN wh_receiving_hdr rc ON rc.id=h.wh_receiving_hdr_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.return_date, h.project_id, p.name AS project_name,
                           h.warehouse_id, w.name AS warehouse_name, h.supplier_id, s.name AS supplier_name,
                           h.po_id, po.po_number, h.supplier_do_no, h.status, h.remark,
                           h.wh_receiving_hdr_id, rc.ref_no AS rcv_ref_no,
                           h.material_out_id, mo.ref_no AS issue_ref_no, h.sent_date, h.carrier,
                           mo.status AS issue_status, mo.prepared_at, mo.delivery_note_no, mo.delivery_receiver, mo.delivery_proof,   -- [NEW]
                           h.resolution_type, h.resolution_date, h.credit_note_no, h.credit_amount, h.resolution_note,
                           COALESCE(t.line_count,0) AS line_count, COALESCE(t.total_qty,0) AS total_qty,
                           COALESCE(t.total_amount,0) AS total_amount
                    FROM supplier_return_hdr h
                    JOIN projects p ON p.id=h.project_id
                    JOIN warehouses w ON w.id=h.warehouse_id
                    JOIN m_suppliers s ON s.id=h.supplier_id
                    LEFT JOIN purchase_orders po ON po.id=h.po_id
                    LEFT JOIN wh_receiving_hdr rc ON rc.id=h.wh_receiving_hdr_id
                    LEFT JOIN material_out_header mo ON mo.id=h.material_out_id
                    LEFT JOIN (SELECT return_hdr_id, COUNT(*) AS line_count, SUM(qty_claim) AS total_qty,
                                      SUM(qty_claim*unit_cost) AS total_amount
                               FROM supplier_return_dtl GROUP BY return_hdr_id) t ON t.return_hdr_id=h.id
                    WHERE $where ORDER BY h.return_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

function RtDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $rows = WhRows("SELECT d.id, d.material_id, m.material_code, m.material_name, m.unit,
                           d.qty_claim, d.unit_cost, (d.qty_claim*d.unit_cost) AS amount,
                           d.defect_type, d.defect_note, d.evidence_ref
                    FROM supplier_return_dtl d JOIN materials m ON m.id=d.material_id
                    WHERE d.return_hdr_id=$1 ORDER BY d.id", array($id));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// [CHANGED] Daftar dokumen RCV posted (dengan proyek, supplier, gudang, PO) untuk dipilih di editor retur
function RtLookupRcv() {
    $q = WhReq('query');
    $rows = WhRows("SELECT r.id, r.ref_no, r.received_date, r.warehouse_id, w.name AS warehouse_name,
                           g.ref_no AS grn_no, po.id AS po_id, po.po_number, po.project_id, p.name AS project_name,
                           COALESCE(g.supplier_id, po.supplier_id) AS supplier_id, s.name AS supplier_name,
                           (r.ref_no || ' | ' || p.name || ' | ' || COALESCE(s.name,'-') || ' | ' || r.received_date) AS label
                    FROM wh_receiving_hdr r
                    JOIN grn_hdr g ON g.id=r.grn_hdr_id
                    JOIN purchase_orders po ON po.id=g.po_id
                    JOIN projects p ON p.id=po.project_id
                    JOIN warehouses w ON w.id=r.warehouse_id
                    LEFT JOIN m_suppliers s ON s.id=COALESCE(g.supplier_id, po.supplier_id)
                    WHERE r.status='posted'
                      AND (r.ref_no ILIKE $1 OR g.ref_no ILIKE $1 OR po.po_number ILIKE $1 OR p.name ILIKE $1 OR s.name ILIKE $1)
                    ORDER BY r.received_date DESC, r.id DESC LIMIT 100", array('%' . $q . '%'));
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// [CHANGED] Item pada satu RCV + qty yang masih boleh diretur (exclude_id = retur yang sedang diedit)
function RtRcvItems() {
    $rcvId = (int)WhReq('rcv_id');
    $excl  = (int)WhReq('exclude_id');
    if ($rcvId < 1) { throw new WhException('Dokumen RCV tidak valid.'); }
    $rcv = RtRcvHeader($rcvId);
    if (!$rcv) { throw new WhException('Dokumen RCV tidak ditemukan / belum posted.'); }
    $whId = (int)$rcv['warehouse_id'];
    $rows = WhRows("SELECT d.item_id AS material_id, m.material_code, m.material_name, m.unit,
                           SUM(d.qty_received) AS qty_rcv
                    FROM wh_receiving_dtl d JOIN materials m ON m.id=d.item_id
                    WHERE d.wh_receiving_hdr_id=$1
                    GROUP BY d.item_id, m.material_code, m.material_name, m.unit
                    ORDER BY m.material_name", array($rcvId));
    global $DEFECT_TYPES;
    foreach ($rows as &$r) {
        $mid = (int)$r['material_id'];
        $ret = RtReturnedFromRcv($rcvId, $mid, $excl);
        $stk = round(WhOnHand($mid, $whId) - RtOpenClaim($whId, $mid, $excl), 4);
        $r['qty_rcv']      = round((float)$r['qty_rcv'], 4);
        $r['qty_returned'] = $ret;
        $r['available']    = max(0, min(round($r['qty_rcv'] - $ret, 4), $stk));
        $r['qty_claim']    = 0;                              // diisi user
        $r['defect_type']  = $DEFECT_TYPES[0];
        $r['defect_note']  = '';
        $r['evidence_ref'] = '';
    }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

function RtSave() {
    global $DEFECT_TYPES;
    WhRequirePost();
    $id      = (int)WhPost('id');
    $rcvId   = (int)WhPost('rcv_id');                         // [CHANGED] dasar retur = dokumen RCV
    $date    = WhDate(WhPost('return_date'), 'Tanggal retur');
    $doNo    = WhPost('supplier_do_no');
    $remark  = WhPost('remark');
    $details = WhDetails();
    $userId  = WhUserId();

    if ($rcvId < 1) { throw new WhException('Dokumen RCV wajib dipilih.'); }
    $rcv = RtRcvHeader($rcvId);
    if (!$rcv) { throw new WhException('Dokumen RCV tidak ditemukan / belum posted.'); }
    $projectId = (int)$rcv['project_id'];                     // diturunkan dari RCV -> GRN -> PO
    $whId      = (int)$rcv['warehouse_id'];
    $supId     = (int)$rcv['supplier_id'];
    $poId      = (int)$rcv['po_id'];
    if ($supId < 1) { throw new WhException('Supplier pada GRN / PO untuk RCV ini belum terisi.'); }

    // Validasi baris; total klaim per material dibatasi qty RCV dan stok gudang
    $lines = array(); $sumQty = array();
    foreach ($details as $d) {
        $mid  = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        $qty  = isset($d['qty_claim']) ? WhNum($d['qty_claim']) : 0;
        $type = isset($d['defect_type']) ? trim($d['defect_type']) : '';
        if ($mid < 1)  { throw new WhException('Material pada detail tidak valid.'); }
        if ($qty <= 0) { throw new WhException('Qty retur harus lebih besar dari 0.'); }
        if (!in_array($type, $DEFECT_TYPES, true)) { throw new WhException('Jenis cacat wajib dipilih untuk setiap baris.'); }
        $sumQty[$mid] = round((isset($sumQty[$mid]) ? $sumQty[$mid] : 0) + $qty, 4);
        $lines[] = array('mid' => $mid, 'qty' => $qty, 'type' => $type,
                         'note' => isset($d['defect_note']) ? trim($d['defect_note']) : '',
                         'evid' => isset($d['evidence_ref']) ? trim($d['evidence_ref']) : '');
    }
    if (!count($lines)) { throw new WhException('Isi qty retur minimal untuk satu material.'); }

    WhQ('BEGIN');
    if ($id > 0) {
        $h = WhRow('SELECT id, ref_no, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
        if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
        if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat diubah.'); }
        $refNo = $h['ref_no'];
    }
    foreach ($sumQty as $mid => $tot) {
        WhLock($mid, $whId);
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        if (!$m) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan.'); }
        // [CHANGED] batas 1: qty pada RCV - sudah diretur dari RCV ini
        $rv = WhRow('SELECT COALESCE(SUM(qty_received),0) AS q FROM wh_receiving_dtl WHERE wh_receiving_hdr_id=$1 AND item_id=$2',
                    array($rcvId, $mid));
        if ((float)$rv['q'] <= 0) { throw new WhException($m['material_name'] . ' tidak ada pada dokumen RCV ' . $rcv['ref_no'] . '.'); }
        $left = round((float)$rv['q'] - RtReturnedFromRcv($rcvId, $mid, $id), 4);
        if ($tot > $left + 0.00005) {
            throw new WhException('Qty retur ' . $m['material_name'] . ' (' . WhFmt($tot) . ') melebihi sisa qty RCV yang boleh diretur (' .
                WhFmt(max(0, $left)) . ').');
        }
        // batas 2: stok gudang - klaim retur lain yang masih Diajukan
        $onhand = WhOnHand($mid, $whId);
        if ($onhand <= 0) { throw new WhException($m['material_name'] . ' stok di gudang kosong.'); }
        $avail = round($onhand - RtOpenClaim($whId, $mid, $id), 4);
        if ($tot > $avail + 0.00005) {
            throw new WhException('Qty retur ' . $m['material_name'] . ' (' . WhFmt($tot) . ') melebihi stok tersedia (' .
                WhFmt($avail) . ', sudah dikurangi klaim retur lain yang masih Diajukan).');
        }
    }
    if ($id > 0) {
        WhQ("UPDATE supplier_return_hdr SET return_date=$2, project_id=$3, warehouse_id=$4, supplier_id=$5, po_id=$6,
                    supplier_do_no=$7, remark=$8, wh_receiving_hdr_id=$9, updated_at=now() WHERE id=$1",
            array($id, $date, $projectId, $whId, $supId, $poId > 0 ? $poId : null, $doNo, $remark, $rcvId));
        WhQ('DELETE FROM supplier_return_dtl WHERE return_hdr_id=$1', array($id));
    } else {
        $refNo = CreateRefNo('supplier_return_hdr', 'ref_no', 'RTN');
        $h = WhRow("INSERT INTO supplier_return_hdr
                        (ref_no, return_date, project_id, warehouse_id, supplier_id, po_id, supplier_do_no, status, remark,
                         created_by, wh_receiving_hdr_id)
                    VALUES ($1,$2,$3,$4,$5,$6,$7,'Diajukan',$8,$9,$10) RETURNING id",
                   array($refNo, $date, $projectId, $whId, $supId, $poId > 0 ? $poId : null, $doNo, $remark, $userId, $rcvId));
        $id = (int)$h['id'];
    }
    foreach ($lines as $ln) {
        WhQ("INSERT INTO supplier_return_dtl (return_hdr_id, material_id, qty_claim, defect_type, defect_note, evidence_ref)
             VALUES ($1,$2,$3,$4,$5,$6)", array($id, $ln['mid'], $ln['qty'], $ln['type'], $ln['note'], $ln['evid']));
    }
    WhQ('COMMIT');
    WhOk('Retur supplier tersimpan. No: ' . $refNo, array('id' => $id, 'ref_no' => $refNo));
}

// [CHANGED] Perintahkan W/H = buat dokumen ISSUE W/H (issue_type 'SUPPLIER_RETURN') berstatus 'pending'.
//           Stok dan jurnal BELUM berubah. Gudang menyiapkan barang berdasarkan dokumen ini, lalu mengonfirmasi
//           pengiriman di Warehouse Issuing (IssPrepare / IssDeliver di warehouse.issuingCRD.php).
function RtOrder() {
    WhRequirePost();
    $id     = (int)WhPost('id');
    $date   = WhDate(WhPost('order_date'), 'Tanggal perintah');
    $userId = WhUserId();
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }

    WhQ('BEGIN');
    $h = WhRow('SELECT * FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat diperintahkan ke W/H.'); }
    $whId = (int)$h['warehouse_id'];
    $dtl = WhRows('SELECT id, material_id, qty_claim, defect_type FROM supplier_return_dtl WHERE return_hdr_id=$1 ORDER BY material_id, id', array($id));
    if (!count($dtl)) { throw new WhException('Retur belum memiliki item.'); }

    // stok harus masih cukup (dikurangi klaim retur lain yang belum keluar stok)
    $sum = array();
    foreach ($dtl as $d) {
        $mid = (int)$d['material_id'];
        $sum[$mid] = round((isset($sum[$mid]) ? $sum[$mid] : 0) + (float)$d['qty_claim'], 4);
    }
    foreach ($sum as $mid => $tot) {
        WhLock($mid, $whId);
        $m = WhRow('SELECT material_name FROM materials WHERE id=$1', array($mid));
        $avail = round(WhOnHand($mid, $whId) - RtOpenClaim($whId, $mid, $id), 4);
        if ($tot > $avail + 0.00005) {
            throw new WhException('Stok tersedia ' . $m['material_name'] . ' (' . WhFmt($avail) .
                ') tidak cukup untuk diperintahkan (' . WhFmt($tot) . ').');
        }
    }

    $issRef = CreateRefNo('material_out_header', 'ref_no', 'RTS');
    $mo = WhRow("INSERT INTO material_out_header
                    (ref_no, project_id, warehouse_id, issue_date, issued_by, status, remark, issue_type)
                 VALUES ($1,$2,$3,$4,$5,'pending',$6,$7) RETURNING id",
                array($issRef, (int)$h['project_id'], $whId, $date, $userId,
                      'Retur supplier ' . $h['ref_no'], ISSUE_TYPE_RETURN));
    $moId = (int)$mo['id'];
    // item rencana (belum ada biaya FIFO); diganti item aktual per layer saat Konfirmasi Dikirim
    foreach ($dtl as $d) {
        WhQ("INSERT INTO material_out_items (material_out_id, material_id, qty_out, unit_cost, is_overbudget, notes)
             VALUES ($1,$2,$3,0,'f',$4)",
            array($moId, (int)$d['material_id'], round((float)$d['qty_claim'], 4), 'Retur cacat: ' . $d['defect_type']));
    }
    WhQ("UPDATE supplier_return_hdr SET status='Diperintahkan', material_out_id=$2, updated_at=now() WHERE id=$1",
        array($id, $moId));
    WhQ('COMMIT');
    WhOk('Perintah issue W/H dibuat: ' . $issRef . '. Menunggu gudang menyiapkan dan mengonfirmasi pengiriman.',
         array('issue_ref_no' => $issRef));
}

// [NEW] Batalkan perintah issue (selama gudang belum mengonfirmasi dikirim): issue 'cancelled', retur kembali Diajukan
function RtCancelOrder() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, material_out_id FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Diperintahkan' && $h['status'] !== 'Disiapkan') {
        throw new WhException('Hanya retur berstatus Diperintahkan / Disiapkan yang dapat dibatalkan perintahnya (untuk Dikirim gunakan Batal Kirim).');
    }
    $moId = (int)$h['material_out_id'];
    $mo = WhRow('SELECT status FROM material_out_header WHERE id=$1 FOR UPDATE', array($moId));
    if ($mo && $mo['status'] !== 'pending' && $mo['status'] !== 'prepared') {
        throw new WhException('Dokumen issue W/H sudah diproses gudang, tidak dapat dibatalkan dari sini.');
    }
    WhQ("UPDATE material_out_header SET status='cancelled' WHERE id=$1", array($moId));
    WhQ("UPDATE supplier_return_hdr SET status='Diajukan', material_out_id=NULL, updated_at=now() WHERE id=$1", array($id));
    WhQ('COMMIT');
    WhOk('Perintah issue W/H dibatalkan. Retur kembali berstatus Diajukan.');
}

// Batal kirim: reverse issue W/H, retur kembali ke Diajukan
function RtUnsend() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status, material_out_id FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Dikirim') { throw new WhException('Hanya retur berstatus Dikirim yang dapat dibatalkan pengirimannya.'); }
    $moId = (int)$h['material_out_id'];
    WhQ("UPDATE material_out_header SET status='reversed', reversed_at=now(), reversed_by=$2 WHERE id=$1", array($moId, WhUserId()));
    // [NEW] jurnal pembalik pengiriman retur
    JrnReverseByRef(JRN_REF_RETURN, $id, date('Y-m-d'), WhUserId(), 'Batal kirim retur');
    WhDeleteMovementsByRef(ISSUE_REF_TYPE, $moId);      // stok kembali ke gudang
    WhQ("UPDATE supplier_return_dtl SET unit_cost=0 WHERE return_hdr_id=$1", array($id));
    WhQ("UPDATE supplier_return_hdr SET status='Diajukan', material_out_id=NULL, sent_date=NULL, carrier=NULL, updated_at=now() WHERE id=$1",
        array($id));
    WhQ('COMMIT');
    WhOk('Pengiriman dibatalkan, stok dikembalikan ke gudang. Retur kembali berstatus Diajukan.');
}

// Selesaikan: Ganti Barang (barang pengganti diterima lewat RCV biasa) atau Kredit (nota kredit supplier)
function RtResolve() {
    WhRequirePost();
    $id    = (int)WhPost('id');
    $type  = WhPost('resolution_type');
    $date  = WhDate(WhPost('resolution_date'), 'Tanggal penyelesaian');
    $cnNo  = WhPost('credit_note_no');
    $cnAmt = WhNum(WhPost('credit_amount'));
    $note  = WhPost('resolution_note');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    if ($type !== 'Ganti Barang' && $type !== 'Kredit') { throw new WhException('Jenis penyelesaian harus Ganti Barang atau Kredit.'); }
    if ($type === 'Kredit' && ($cnNo === '' || $cnAmt <= 0)) {
        throw new WhException('Penyelesaian Kredit wajib mengisi no. nota kredit dan nilai kredit (> 0).');
    }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Dikirim') { throw new WhException('Hanya retur berstatus Dikirim yang dapat diselesaikan.'); }
    $newStatus = ($type === 'Kredit') ? 'Kredit' : 'Diganti';
    WhQ("UPDATE supplier_return_hdr SET status=$2, resolution_type=$3, resolution_date=$4, credit_note_no=$5,
                credit_amount=$6, resolution_note=$7, updated_at=now() WHERE id=$1",
        array($id, $newStatus, $type, $date, $type === 'Kredit' ? $cnNo : null, $type === 'Kredit' ? $cnAmt : null, $note));
    // [NEW] hanya untuk Kredit: Dr Hutang Usaha (2100) / Cr GRN Clearing (2600); Ganti Barang tidak perlu jurnal
    if ($type === 'Kredit') {
        JrnPost('RETURN_CREDIT_NOTE', JRN_REF_RETURN, $id, $date, $cnAmt, 'Nota kredit ' . $cnNo, WhUserId());
    }
    WhQ('COMMIT');
    WhOk('Retur diselesaikan dengan status ' . $newStatus . '.');
}

function RtCancel() {
    WhRequirePost();
    $id = (int)WhPost('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    WhQ('BEGIN');
    $h = WhRow('SELECT id, status FROM supplier_return_hdr WHERE id=$1 FOR UPDATE', array($id));
    if (!$h) { throw new WhException('Dokumen tidak ditemukan.'); }
    if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya retur berstatus Diajukan yang dapat dibatalkan (untuk Dikirim gunakan Batal Kirim).'); }
    WhQ("UPDATE supplier_return_hdr SET status='Dibatalkan', updated_at=now() WHERE id=$1", array($id));
    WhQ('COMMIT');
    WhOk('Retur dibatalkan.');
}

// [CHANGED] Unggah bukti cacat (foto / PDF, maks 5 MB) -> disimpan di /rab-project/uploads/return/, kembalikan URL
function RtUploadEvidence() {
    WhRequirePost();
    WhUserId();                                              // pastikan sesi valid
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
    if (function_exists('finfo_open')) {                     // cek isi file, bukan hanya ekstensi
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        $mime = finfo_file($fi, $f['tmp_name']);
        finfo_close($fi);
        if ($mime !== $allowed[$ext]) { throw new WhException('Isi file tidak sesuai dengan ekstensinya.'); }
    }
    $dir = dirname(__DIR__) . '/uploads/return';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
        throw new WhException('Folder uploads/return tidak bisa dibuat. Buat manual dan beri izin tulis untuk user web server.');
    }
    $ht = $dir . '/.htaccess';                               // larang eksekusi script di folder upload
    if (!file_exists($ht)) {
        @file_put_contents($ht, "<FilesMatch \"\\.(php|phtml|phar|pl|cgi)$\">\n    Require all denied\n</FilesMatch>\n");
    }
    $name = 'rtn_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
        throw new WhException('Gagal menyimpan file. Cek izin tulis folder uploads/return.');
    }
    WhJson(array('success' => true, 'url' => '/rab-project/uploads/return/' . $name, 'name' => $name));
}