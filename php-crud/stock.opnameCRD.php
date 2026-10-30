<?php
/**
 * stock.opnameCRD.php -- Stock Opname (hitung fisik per gudang -> penyesuaian stok + jurnal)
 *
 * Alur   : Draft --(Ajukan)--> Diajukan --(Setujui & Posting)--> Disetujui
 *          Draft / Diajukan --(Batalkan)--> Dibatalkan ; Diajukan --(Refresh / Kembali ke Draft)--> Draft
 *          Disetujui --(Batalkan = reverse)--> Dibatalkan (hanya bila stok hasil penyesuaian belum terpakai)
 * Aturan :
 *   1. Satu gudang hanya boleh punya satu opname berjalan (Draft / Diajukan) -- unique index uq_so_open_per_wh.
 *   2. system_qty = snapshot stok sistem saat disimpan / di-refresh. Saat Setujui, stok sistem sekarang HARUS sama dengan
 *      snapshot; bila ada transaksi masuk / keluar sejak itu -> ditolak, minta Refresh Stok lalu hitung ulang.
 *   3. Baris tanpa hasil hitung (counted_qty kosong) tidak disesuaikan. Selisih <> 0 wajib punya alasan; selisih lebih wajib biaya satuan > 0.
 *   4. Selisih lebih  : stok masuk (IN) pada biaya satuan; jurnal STOCK_ADJ_GAIN (Dr Persediaan / Cr Selisih Persediaan).
 *      Selisih kurang : stok keluar (OUT) FIFO; jurnal STOCK_ADJ_LOSS (Dr Selisih Persediaan / Cr Persediaan).
 *   5. Semua pergerakan stok memakai reference_type 'STOCK_OPNAME' (tampil di Kartu Stok); jurnal reference_type 'stock_adjustment'.
 *   6. Jejak: pembuat, pengaju, penyetuju, pembatal + alasan tersimpan di header; dokumen tidak pernah dihapus (hanya Dibatalkan).
 * Migrasi: sql/2026-10-30.stock_opname.sql
 * Action : list | detail | lookup_warehouses | lookup_materials | snapshot | save | submit | refresh | approve | cancel
 */
ob_start();
ini_set('display_errors', '0');
require_once '../php-func/function.all.php';
require_once '../php-class/crudClass.php';
require_once '../php-func/function.stock.php';
require_once '../php-func/journal.stock.php';           // JrnPost / JrnReverseByRef

define('SO_REF_TYPE', 'STOCK_OPNAME');                  // stock_movements.reference_type
define('SO_JRN_REF', 'stock_adjustment');               // journal_entries.reference_type (enum)
define('SO_REQUIRE_OTHER_APPROVER', false);             // true = penyetuju harus user lain selain pengaju
define('SO_EPS', 0.00005);

$SO_REASONS = array('Salah Hitung / Salah Catat', 'Rusak', 'Hilang / Susut', 'Kelebihan Penerimaan', 'Salah Pengeluaran', 'Lainnya');
$action = WhReq('action', 'list');

try {
    switch ($action) {
        case 'list':              SoList(); break;
        case 'detail':            SoDetail(); break;
        case 'lookup_warehouses': SoLookupWarehouses(); break;
        case 'lookup_materials':  SoLookupMaterials(); break;
        case 'snapshot':          SoSnapshot(); break;
        case 'save':              SoSave(); break;
        case 'submit':            SoSubmit(); break;
        case 'refresh':           SoRefresh(); break;
        case 'approve':           SoApprove(); break;
        case 'cancel':            SoCancel(); break;
        default: throw new WhException('Action tidak dikenali.');
    }
} catch (WhException $e) {
    @pg_query($conn, 'ROLLBACK');
    WhFail($e->getMessage());
} catch (Exception $e) {
    @pg_query($conn, 'ROLLBACK');
    WhLog('stock.opnameCRD: ' . $e->getMessage());
    WhFail('Terjadi kesalahan pada server. Hubungi administrator.');
}

/* ------------------------------------------------------------------ */
// Biaya satuan rata-rata tertimbang seluruh layer masuk di gudang (dasar penilaian selisih lebih); fallback harga estimasi master
function SoAvgCost($mid, $whId) {
    $r = WhRow("SELECT CASE WHEN SUM(qty) > 0 THEN SUM(qty * unit_cost) / SUM(qty) ELSE 0 END AS c
                FROM stock_movements WHERE item_id = $1 AND warehouse_id = $2 AND movement_type = 'IN'",
               array((int)$mid, (int)$whId));
    $c = round((float)$r['c'], 4);
    if ($c <= 0) {
        $m = WhRow('SELECT COALESCE(estimated_price, 0) AS p FROM materials WHERE id = $1', array((int)$mid));
        $c = $m ? round((float)$m['p'], 4) : 0;
    }
    return $c;
}

function SoHeader($id, $lock = false) {
    $h = WhRow('SELECT h.*, w.name AS warehouse_name FROM stock_opname_hdr h JOIN warehouses w ON w.id = h.warehouse_id
                WHERE h.id = $1' . ($lock ? ' FOR UPDATE OF h' : ''), array((int)$id));
    if (!$h) { throw new WhException('Dokumen opname tidak ditemukan.'); }
    return $h;
}

function SoList() {
    list($start, $limit) = WhPage();
    $q = WhReq('query');
    $params = array();
    $where = '1=1';
    if ($q !== '') {
        $params[] = '%' . $q . '%';
        $where .= ' AND (h.ref_no ILIKE $1 OR w.name ILIKE $1 OR h.remark ILIKE $1)';
    }
    $c = WhRow("SELECT COUNT(*) AS n FROM stock_opname_hdr h JOIN warehouses w ON w.id = h.warehouse_id WHERE $where", $params);
    $params[] = $limit;  $pl = '$' . count($params);
    $params[] = $start;  $ps = '$' . count($params);
    $rows = WhRows("SELECT h.id, h.ref_no, h.warehouse_id, w.name AS warehouse_name, h.opname_date, h.status, h.remark,
                           h.total_gain, h.total_loss, h.cancel_reason,
                           COALESCE(t.line_count, 0) AS line_count, COALESCE(t.counted_count, 0) AS counted_count,
                           COALESCE(t.diff_count, 0) AS diff_count,
                           cu.login_name AS created_by_name
                    FROM stock_opname_hdr h
                    JOIN warehouses w ON w.id = h.warehouse_id
                    LEFT JOIN (SELECT opname_id, COUNT(*) AS line_count, COUNT(counted_qty) AS counted_count,
                                      SUM(CASE WHEN counted_qty IS NOT NULL AND diff_qty <> 0 THEN 1 ELSE 0 END) AS diff_count
                                 FROM stock_opname_dtl GROUP BY opname_id) t ON t.opname_id = h.id
                    LEFT JOIN m_user_system cu ON cu.id = h.created_by
                    WHERE $where
                    ORDER BY h.opname_date DESC, h.id DESC
                    LIMIT $pl OFFSET $ps", $params);
    WhJson(array('success' => true, 'totalCount' => (int)$c['n'], 'data' => $rows));
}

// Header + baris + jejak audit + pergerakan stok yang dihasilkan (bila sudah Disetujui)
function SoDetail() {
    $id = (int)WhReq('id');
    if ($id < 1) { throw new WhException('ID dokumen tidak valid.'); }
    $h = WhRow("SELECT h.*, w.name AS warehouse_name,
                       cu.login_name AS created_by_name, su.login_name AS submitted_by_name,
                       au.login_name AS approved_by_name, xu.login_name AS cancelled_by_name
                FROM stock_opname_hdr h
                JOIN warehouses w ON w.id = h.warehouse_id
                LEFT JOIN m_user_system cu ON cu.id = h.created_by
                LEFT JOIN m_user_system su ON su.id = h.submitted_by
                LEFT JOIN m_user_system au ON au.id = h.approved_by
                LEFT JOIN m_user_system xu ON xu.id = h.cancelled_by
                WHERE h.id = $1", array($id));
    if (!$h) { throw new WhException('Dokumen opname tidak ditemukan.'); }
    $lines = WhRows("SELECT d.id, d.material_id, m.material_code, m.material_name, m.unit, d.system_qty, d.counted_qty,
                            d.diff_qty, d.unit_cost, d.diff_value, d.reason, d.note
                     FROM stock_opname_dtl d JOIN materials m ON m.id = d.material_id
                     WHERE d.opname_id = $1 ORDER BY m.material_name", array($id));
    $mov = WhRows("SELECT s.id, s.movement_date, s.movement_type, m.material_code, m.material_name, s.qty, s.unit_cost, s.reference_no
                   FROM stock_movements s JOIN materials m ON m.id = s.item_id
                   WHERE s.reference_type = $1 AND s.reference_id = $2 ORDER BY s.id", array(SO_REF_TYPE, $id));
    $jr = WhRows("SELECT ref_no, journal_date, status::text AS status FROM journal_entries
                  WHERE reference_type::text = $1 AND reference_id = $2 ORDER BY id", array(SO_JRN_REF, $id));
    WhJson(array('success' => true, 'totalCount' => count($lines), 'header' => $h, 'data' => $lines, 'movements' => $mov, 'journals' => $jr));
}

function SoLookupWarehouses() {
    $rows = WhRows("SELECT id, code, name FROM warehouses WHERE active = true ORDER BY name");
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Material untuk ditambahkan manual (termasuk yang stok sistemnya 0 -> barang ditemukan di gudang)
function SoLookupMaterials() {
    $wid = (int)WhReq('warehouse_id');
    $q = WhReq('query');
    $rows = WhRows("SELECT m.id AS material_id, m.material_code, m.material_name, m.unit,
                           COALESCE(oh.onhand, 0) AS system_qty
                    FROM materials m
                    LEFT JOIN (SELECT item_id, SUM(CASE WHEN movement_type = 'IN' THEN qty ELSE -qty END) AS onhand
                               FROM stock_movements WHERE warehouse_id = $1 GROUP BY item_id) oh ON oh.item_id = m.id
                    WHERE m.active IS NOT FALSE AND (m.material_name ILIKE $2 OR m.material_code ILIKE $2)
                    ORDER BY m.material_name LIMIT 50", array($wid, '%' . $q . '%'));
    foreach ($rows as &$r) { $r['unit_cost'] = SoAvgCost($r['material_id'], $wid); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Semua material yang punya stok di gudang (stok sistem <> 0) -> baris awal lembar hitung
function SoSnapshot() {
    $wid = (int)WhReq('warehouse_id');
    if ($wid < 1) { throw new WhException('Gudang wajib dipilih.'); }
    $rows = WhRows("SELECT m.id AS material_id, m.material_code, m.material_name, m.unit, oh.onhand AS system_qty
                    FROM materials m
                    JOIN (SELECT item_id, SUM(CASE WHEN movement_type = 'IN' THEN qty ELSE -qty END) AS onhand
                          FROM stock_movements WHERE warehouse_id = $1 GROUP BY item_id) oh ON oh.item_id = m.id
                    WHERE oh.onhand <> 0 ORDER BY m.material_name", array($wid));
    foreach ($rows as &$r) { $r['unit_cost'] = SoAvgCost($r['material_id'], $wid); }
    unset($r);
    WhJson(array('success' => true, 'totalCount' => count($rows), 'data' => $rows));
}

// Simpan lembar hitung (baru / ubah Draft). system_qty selalu diambil ulang dari stok saat ini (klien tidak menentukan).
function SoSave() {
    global $SO_REASONS;
    WhRequirePost();
    $id      = (int)WhPost('id');
    $whId    = (int)WhPost('warehouse_id');
    $date    = WhDate(WhPost('opname_date'), 'Tanggal opname');
    $remark  = WhPost('remark');
    $details = WhDetails();
    $userId  = WhUserId();

    if ($whId < 1) { throw new WhException('Gudang wajib dipilih.'); }
    $wh = WhRow('SELECT id, active FROM warehouses WHERE id = $1', array($whId));
    if (!$wh || !WhBool($wh['active'])) { throw new WhException('Gudang tidak ditemukan / tidak aktif.'); }
    if (!count($details)) { throw new WhException('Tambahkan minimal satu material.'); }

    $lines = array();
    foreach ($details as $d) {
        $mid = isset($d['material_id']) ? (int)$d['material_id'] : 0;
        if ($mid < 1) { throw new WhException('Material pada detail tidak valid.'); }
        if (isset($lines[$mid])) { throw new WhException('Material yang sama tidak boleh muncul dua kali dalam satu opname.'); }
        $cnt = (isset($d['counted_qty']) && $d['counted_qty'] !== '' && $d['counted_qty'] !== null) ? round(WhNum($d['counted_qty']), 4) : null;
        if ($cnt !== null && $cnt < 0) { throw new WhException('Qty hitung tidak boleh negatif.'); }
        $reason = isset($d['reason']) ? trim($d['reason']) : '';
        if ($reason !== '' && !in_array($reason, $SO_REASONS, true)) { throw new WhException('Alasan selisih tidak valid.'); }
        $lines[$mid] = array('counted' => $cnt, 'reason' => $reason, 'note' => isset($d['note']) ? trim($d['note']) : '',
                             'cost' => isset($d['unit_cost']) ? round(WhNum($d['unit_cost']), 4) : 0);
    }

    WhQ('BEGIN');
    if ($id > 0) {
        $h = SoHeader($id, true);
        if ($h['status'] !== 'Draft') { throw new WhException('Hanya opname berstatus Draft yang dapat diubah.'); }
        if ((int)$h['warehouse_id'] !== $whId) { throw new WhException('Gudang tidak dapat diubah. Batalkan dokumen ini lalu buat opname baru.'); }
        WhQ('UPDATE stock_opname_hdr SET opname_date = $2, remark = $3, updated_at = now() WHERE id = $1', array($id, $date, $remark));
        WhQ('DELETE FROM stock_opname_dtl WHERE opname_id = $1', array($id));
        $refNo = $h['ref_no'];
    } else {
        $open = WhRow("SELECT ref_no FROM stock_opname_hdr WHERE warehouse_id = $1 AND status IN ('Draft','Diajukan') LIMIT 1", array($whId));
        if ($open) { throw new WhException('Gudang ini masih punya opname berjalan (' . $open['ref_no'] . '). Selesaikan atau batalkan dulu.'); }
        $refNo = CreateRefNo('stock_opname_hdr', 'ref_no', 'SOP');
        $h = WhRow("INSERT INTO stock_opname_hdr (ref_no, warehouse_id, opname_date, status, remark, created_by)
                    VALUES ($1, $2, $3, 'Draft', $4, $5) RETURNING id", array($refNo, $whId, $date, $remark, $userId));
        $id = (int)$h['id'];
    }
    foreach ($lines as $mid => $ln) {
        $m = WhRow('SELECT material_name FROM materials WHERE id = $1', array($mid));
        if (!$m) { throw new WhException('Material ID ' . $mid . ' tidak ditemukan.'); }
        WhLock($mid, $whId);
        $sys  = round(WhOnHand($mid, $whId), 4);
        $diff = ($ln['counted'] === null) ? 0 : round($ln['counted'] - $sys, 4);
        $cost = ($ln['cost'] > 0) ? $ln['cost'] : SoAvgCost($mid, $whId);
        WhQ("INSERT INTO stock_opname_dtl (opname_id, material_id, system_qty, counted_qty, diff_qty, unit_cost, reason, note)
             VALUES ($1, $2, $3, $4, $5, $6, $7, $8)",
            array($id, $mid, $sys, $ln['counted'], $diff, $cost, $ln['reason'] !== '' ? $ln['reason'] : null, $ln['note'] !== '' ? $ln['note'] : null));
    }
    WhQ('COMMIT');
    WhOk('Lembar opname tersimpan. No: ' . $refNo, array('id' => $id, 'ref_no' => $refNo));
}

// Validasi kelengkapan baris (dipakai saat Ajukan & Setujui)
function SoValidateLines($id) {
    global $SO_REASONS;
    $rows = WhRows("SELECT d.*, m.material_name FROM stock_opname_dtl d JOIN materials m ON m.id = d.material_id
                    WHERE d.opname_id = $1", array((int)$id));
    $counted = 0;
    foreach ($rows as $r) {
        if ($r['counted_qty'] === null) { continue; }
        $counted++;
        $diff = round((float)$r['diff_qty'], 4);
        if (abs($diff) <= SO_EPS) { continue; }
        if (!in_array((string)$r['reason'], $SO_REASONS, true)) {
            throw new WhException('Alasan selisih wajib dipilih untuk ' . $r['material_name'] . '.');
        }
        if ($diff > 0 && (float)$r['unit_cost'] <= 0) {
            throw new WhException('Biaya satuan wajib diisi (> 0) untuk selisih lebih ' . $r['material_name'] . '.');
        }
    }
    if ($counted < 1) { throw new WhException('Belum ada material yang dihitung. Isi qty hitung minimal satu baris.'); }
    return $rows;
}

function SoSubmit() {
    WhRequirePost();
    $id = (int)WhPost('id');
    WhQ('BEGIN');
    $h = SoHeader($id, true);
    if ($h['status'] !== 'Draft') { throw new WhException('Hanya opname berstatus Draft yang dapat diajukan.'); }
    SoValidateLines($id);
    WhQ("UPDATE stock_opname_hdr SET status = 'Diajukan', submitted_by = $2, submitted_at = now() WHERE id = $1", array($id, WhUserId()));
    WhQ('COMMIT');
    WhOk('Opname diajukan untuk persetujuan.');
}

// Ambil ulang stok sistem (snapshot baru) untuk semua baris; status kembali ke Draft agar hasil hitung ditinjau ulang
function SoRefresh() {
    WhRequirePost();
    $id = (int)WhPost('id');
    WhQ('BEGIN');
    $h = SoHeader($id, true);
    if ($h['status'] !== 'Draft' && $h['status'] !== 'Diajukan') { throw new WhException('Refresh hanya untuk opname Draft / Diajukan.'); }
    $rows = WhRows('SELECT id, material_id, counted_qty FROM stock_opname_dtl WHERE opname_id = $1', array($id));
    foreach ($rows as $r) {
        WhLock((int)$r['material_id'], (int)$h['warehouse_id']);
        $sys  = round(WhOnHand((int)$r['material_id'], (int)$h['warehouse_id']), 4);
        $diff = ($r['counted_qty'] === null) ? 0 : round((float)$r['counted_qty'] - $sys, 4);
        WhQ('UPDATE stock_opname_dtl SET system_qty = $2, diff_qty = $3 WHERE id = $1', array((int)$r['id'], $sys, $diff));
    }
    WhQ("UPDATE stock_opname_hdr SET status = 'Draft', submitted_by = NULL, submitted_at = NULL, updated_at = now() WHERE id = $1", array($id));
    WhQ('COMMIT');
    WhOk('Stok sistem diperbarui. Periksa kembali selisih lalu ajukan ulang.');
}

function SoApprove() {
    WhRequirePost();
    $id = (int)WhPost('id');
    $userId = WhUserId();
    WhQ('BEGIN');
    $h = SoHeader($id, true);
    if ($h['status'] !== 'Diajukan') { throw new WhException('Hanya opname berstatus Diajukan yang dapat disetujui.'); }
    if (SO_REQUIRE_OTHER_APPROVER && (int)$h['submitted_by'] === (int)$userId) {
        throw new WhException('Penyetuju harus user lain selain pengaju opname.');
    }
    $whId = (int)$h['warehouse_id'];
    $date = $h['opname_date'];
    $rows = SoValidateLines($id);

    $gain = 0.0; $loss = 0.0;
    foreach ($rows as $r) {
        if ($r['counted_qty'] === null) { continue; }
        $mid  = (int)$r['material_id'];
        $diff = round((float)$r['diff_qty'], 4);
        if (abs($diff) <= SO_EPS) { continue; }
        WhLock($mid, $whId);
        $onhand = round(WhOnHand($mid, $whId), 4);
        if (abs($onhand - round((float)$r['system_qty'], 4)) > SO_EPS) {
            throw new WhException('Stok ' . $r['material_name'] . ' berubah sejak snapshot (sekarang ' . WhFmt($onhand) . ', snapshot ' . WhFmt($r['system_qty']) .
                '). Klik Refresh Stok, lalu hitung ulang dan ajukan kembali.');
        }
        if ($diff > 0) {
            $cost = round((float)$r['unit_cost'], 4);
            WhPostMovement($date, $mid, $whId, 'IN', $diff, $cost, SO_REF_TYPE, $id, $h['ref_no'], 'Selisih opname (lebih)', $userId);
            $val = round($diff * $cost, 2);
            $gain += $val;
            WhQ('UPDATE stock_opname_dtl SET diff_value = $2 WHERE id = $1', array((int)$r['id'], $val));
        } else {
            $qty = -$diff;
            $val = 0.0;
            foreach (WhFifoAllocate($mid, $whId, $qty) as $ly) {
                WhPostMovement($date, $mid, $whId, 'OUT', $ly['qty'], $ly['unit_cost'], SO_REF_TYPE, $id, $h['ref_no'], 'Selisih opname (kurang)', $userId);
                $val += $ly['qty'] * $ly['unit_cost'];
            }
            $val = round($val, 2);
            $loss += $val;
            WhQ('UPDATE stock_opname_dtl SET diff_value = $2 WHERE id = $1', array((int)$r['id'], -$val));
        }
    }
    $gain = round($gain, 2); $loss = round($loss, 2);
    if ($gain > 0) { JrnPost('STOCK_ADJ_GAIN', SO_JRN_REF, $id, $date, $gain, 'Selisih lebih opname ' . $h['ref_no'], $userId); }
    if ($loss > 0) { JrnPost('STOCK_ADJ_LOSS', SO_JRN_REF, $id, $date, $loss, 'Selisih kurang opname ' . $h['ref_no'], $userId); }
    WhQ("UPDATE stock_opname_hdr SET status = 'Disetujui', approved_by = $2, approved_at = now(), total_gain = $3, total_loss = $4 WHERE id = $1",
        array($id, $userId, $gain, $loss));
    WhQ('COMMIT');
    WhOk('Opname disetujui & diposting. Nilai lebih ' . WhFmt($gain) . ', nilai kurang ' . WhFmt($loss) . '.');
}

function SoCancel() {
    WhRequirePost();
    $id = (int)WhPost('id');
    $reason = trim(WhPost('reason'));
    $userId = WhUserId();
    if (strlen($reason) < 5) { throw new WhException('Alasan pembatalan wajib diisi (minimal 5 karakter).'); }
    WhQ('BEGIN');
    $h = SoHeader($id, true);
    if ($h['status'] === 'Dibatalkan') { throw new WhException('Dokumen sudah dibatalkan.'); }
    if ($h['status'] === 'Disetujui') {
        // Reverse: selisih lebih hanya boleh dibalik bila stok hasil penyesuaian belum terpakai
        $whId = (int)$h['warehouse_id'];
        $rows = WhRows("SELECT d.material_id, d.diff_qty, m.material_name FROM stock_opname_dtl d JOIN materials m ON m.id = d.material_id
                        WHERE d.opname_id = $1 AND d.counted_qty IS NOT NULL AND d.diff_qty > 0", array($id));
        foreach ($rows as $r) {
            WhLock((int)$r['material_id'], $whId);
            if (round(WhOnHand((int)$r['material_id'], $whId), 4) + SO_EPS < round((float)$r['diff_qty'], 4)) {
                throw new WhException('Tidak dapat dibatalkan: stok lebih ' . $r['material_name'] . ' hasil opname sudah terpakai. Lakukan opname koreksi.');
            }
        }
        JrnReverseByRef(SO_JRN_REF, $id, date('Y-m-d'), $userId, 'Batal opname: ' . $reason);
        WhDeleteMovementsByRef(SO_REF_TYPE, $id);
        WhQ('UPDATE stock_opname_dtl SET diff_value = 0 WHERE opname_id = $1', array($id));
    }
    WhQ("UPDATE stock_opname_hdr SET status = 'Dibatalkan', cancelled_by = $2, cancelled_at = now(), cancel_reason = $3 WHERE id = $1",
        array($id, $userId, $reason));
    WhQ('COMMIT');
    WhOk('Opname dibatalkan.');
}