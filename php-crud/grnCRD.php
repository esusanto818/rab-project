<?php
/**
 * grn00CRD.php
 * ============================================================
 * Backend untuk grn00.js (Proses GRN tanpa Simpan Warehouse).
 * Kontrak task: list_po_open, read_po_items, read, 
 *               read_grn_items, read_edit, read_grn_print, create, update, delete
 *
 * MULTI CURRENCY (sql/2026-10-01.grn_currency.sql):
 *  - GRN membekukan mata uang & kurs PO (grn_hdr.currency_id / exch_rate).
 *  - grn_dtl.unit_cost      = harga per unit dalam MATA UANG PO (bisa diedit saat GRN).
 *  - grn_dtl.unit_cost_base = unit_cost x kurs PO = MATA UANG DASAR (dihitung server, bukan dari klien);
 *    inilah yang dipakai WH Rcv untuk FIFO, stok, dan jurnal.
 *  - GRN hanya boleh dibuat dari PO berstatus Disetujui (GRN_PO_STATUS_LIST). PO Draft/Diajukan/Dibatalkan ditolak.
 *
 * [NEW 2026-10-08] KENDARAAN & DRIVER (sql/2026-10-08.grn_vehicle_driver.sql):
 *  - grn_hdr.vehicle_plate_no (varchar 20) & grn_hdr.driver_name (varchar 100), opsional.
 *  - Dinormalisasi server (plat = huruf besar, spasi dirapikan) di GrnCleanVehicle().
 *  - Task baru 'read_grn_print': data header + item untuk cetak Tanda Terima GRN (tanpa harga).
 *
 * [NEW 2026-10-12] NO. DO SUPPLIER (sql/2026-10-12.grn_supplier_do.sql):
 *  - grn_hdr.supplier_do_no (varchar 100). Satu-satunya tempat input No. DO supplier; W/H RCV dan Retur Pembelian
 *    hanya MENURUNKAN nomor ini (join grn_hdr) sehingga bisa di-trace GRN -> W/H RCV -> Retur.
 *  - Dinormalisasi di GrnCleanDo(); GRN_REQUIRE_DO = true -> wajib diisi untuk GRN baru / hasil edit.
 *
 * [NEW 2026-10-27] PPN - PO MODE INCLUDE (purchase_orders.ppn_mode = 'INCL', sql/2026-10-26.ppn_po.sql):
 *  - Harga di PO mode INCL sudah termasuk PPN, sedangkan nilai GRN (grn_dtl.unit_cost) harus DPP agar stok, jurnal WH Rcv,
 *    dan nilai match invoice benar. Default unit_cost_this_grn = unit_price / (1 + tarif); unit_price_dpp, ppn_mode, ppn_rate ikut dikirim.
 *  - InsertGrnItems menolak unit_cost yang sama dengan harga PO inklusif PPN (klien lama / input keliru).
 *  - Mode EXCL / NONE: harga PO sudah DPP, tidak ada perubahan.
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

session_start();
header('Content-Type: application/json');

const GRN_PO_STATUS_LIST = ['Disetujui'];     // status PO yang boleh di-GRN
const GRN_REQUIRE_DO = false;                 // [NEW] true = No. DO Supplier wajib diisi (aktifkan setelah form grn00.js punya field-nya)
const GRN_REQUIRE_VEHICLE = false;            // [NEW] true = plat no & nama driver wajib diisi saat simpan GRN

$task = $_REQUEST['task'] ?? '';
$db = new crud();

function PgOrFail($result, $context = '') {
    if ($result === false) {
        throw new Exception(($context ? "[{$context}] " : '') . pg_last_error());
    }
    return $result;
}

/** Kode mata uang dasar (Pengaturan Sistem); belum diatur -> IDR. Hanya untuk tampilan. */
function GrnBaseCode() {
    $r = pg_query("SELECT to_regclass('public.finance_settings') AS t");
    if ($r && ($row = pg_fetch_assoc($r)) && $row['t']) {
        $q = pg_query("SELECT UPPER(TRIM(c.init)) AS code FROM finance_settings f
                         JOIN m_currency c ON c.id = f.base_currency_id WHERE f.id = 1");
        if ($q && ($rw = pg_fetch_assoc($q)) && $rw['code']) return $rw['code'];
    }
    return 'IDR';
}

/** Menetapkan variabel sesi PostgreSQL untuk pencatatan log audit/trigger */
/*function SetPgAppUser() {
    $username = $_SESSION['username'] ?? $_SESSION['login_name'] ?? 'system';
    $res = pg_query_params("SELECT set_config('app.username', $1, true)", [$username]);
    PgOrFail($res, 'Gagal menetapkan app.username PostgreSQL');
}*/
/** Info mata uang untuk tampilan: dari snapshot GRN bila $grn_id > 0, selain itu dari PO. */
function GrnCurrencyInfo($po_id, $grn_id) {
    if ($grn_id > 0) {
        $res = pg_query_params("SELECT UPPER(TRIM(c.init)) AS code, COALESCE(NULLIF(g.exch_rate,0),1) AS exch_rate
                                  FROM grn_hdr g LEFT JOIN m_currency c ON c.id = g.currency_id WHERE g.id = $1", [$grn_id]);
    } else {
        $res = pg_query_params("SELECT UPPER(TRIM(c.init)) AS code, COALESCE(NULLIF(p.exch_rate,0),1) AS exch_rate
                                  FROM purchase_orders p LEFT JOIN m_currency c ON c.id = p.currency_id WHERE p.id = $1", [$po_id]);
    }
    PgOrFail($res, 'Gagal load mata uang');
    $row = pg_fetch_assoc($res) ?: [];
    $base = GrnBaseCode();
    return ['code' => $row['code'] ?: $base, 'base_code' => $base, 'exch_rate' => (float)($row['exch_rate'] ?? 1)];
}

function GetPoDtlTolerance($po_dtl_id, $po_qty, $exclude_grn_hdr_id = 0) {
    // Total Qty yang sudah diterima GRN lain untuk item PO ini
    $sql = "SELECT COALESCE(SUM(qty_received),0) AS received_other
            FROM grn_dtl 
            WHERE po_item_id = $1 AND grn_hdr_id <> $2";
    $res = pg_query_params($sql, [$po_dtl_id, (int)$exclude_grn_hdr_id]);
    PgOrFail($res, 'Gagal cek akumulasi qty GRN');
    $row = pg_fetch_assoc($res);
    $receivedOther = (float)($row['received_other'] ?? 0);

    $qtyMaxAllowed = round($po_qty * 1.10, 4);
    $qtyReceivableMax = max(0, $qtyMaxAllowed - $receivedOther);

    return [
        'others_qty'         => $receivedOther,
        'qty_max_allowed'    => $qtyMaxAllowed,
        'qty_receivable_max' => $qtyReceivableMax
    ];
}

try {
    switch ($task) {

        case 'list_po_open':
            // HANYA MENGAMBIL PO YANG MEMILIKI SISA QTY BELUM DI-GRN
            $sql = "SELECT p.id, p.po_number AS ref_no, p.po_date AS tx_date, 
                           p.supplier_id, 
                           COALESCE(sup.id, p.supplier_id) AS legacy_supplier_id, 
                           sup.name AS supplier_name,
                           p.currency_id, UPPER(TRIM(cur.init)) AS currency_code,
                           COALESCE(NULLIF(p.exch_rate,0),1) AS exch_rate,
                           p.ppn_mode, p.ppn_rate              -- [PPN]
                    FROM purchase_orders p
                    LEFT JOIN m_suppliers sup ON sup.id = p.supplier_id
                    LEFT JOIN m_currency cur ON cur.id = p.currency_id
                    JOIN po_items pd ON pd.po_id = p.id
                    LEFT JOIN (
                        SELECT po_item_id, SUM(qty_received) AS total_rcv
                        FROM grn_dtl
                        GROUP BY po_item_id
                    ) g ON g.po_item_id = pd.id
                    WHERE p.status IN ('" . implode("','", GRN_PO_STATUS_LIST) . "')
                    GROUP BY p.id, p.po_number, p.po_date, p.supplier_id, sup.id, sup.name, p.currency_id, cur.init, p.exch_rate, p.ppn_mode, p.ppn_rate
                    HAVING SUM(pd.qty) - COALESCE(SUM(g.total_rcv), 0) > 0.0001
                    ORDER BY p.po_date DESC";
            $result = pg_query($sql);
            PgOrFail($result, 'Gagal load daftar PO');
            echo json_encode(['success' => true, 'data' => pg_fetch_all($result) ?: []]);
            break;

        case 'read_po_items':
            $po_id  = (int)($_REQUEST['po_id'] ?? 0);
            $grn_id = (int)($_REQUEST['grn_id'] ?? 0);

            $sql = "SELECT pd.id AS po_dtl_id, pd.material_id AS item_id, pd.description AS item_name, 
                           pd.qty AS qty_ordered, pd.unit_price,
                           po.ppn_mode, po.ppn_rate                -- [PPN]
                    FROM po_items pd
                    JOIN purchase_orders po ON po.id = pd.po_id
                    WHERE pd.po_id = $1
                    ORDER BY pd.id";
            $result = pg_query_params($sql, [$po_id]);
            PgOrFail($result, 'Gagal load detail PO');
            $rows = pg_fetch_all($result) ?: [];

            $data = [];
            foreach ($rows as $r) {
                $poQty = (float)$r['qty_ordered'];
                $tol   = GetPoDtlTolerance($r['po_dtl_id'], $poQty, $grn_id);

                // Cek apakah item ini milik GRN yang sedang diedit
                $ownResult = pg_query_params(
                    "SELECT qty_received, unit_cost FROM grn_dtl WHERE po_item_id = $1 AND grn_hdr_id = $2",
                    [$r['po_dtl_id'], $grn_id]
                );
                PgOrFail($ownResult, 'Gagal cek baris grn_dtl existing');
                $own = pg_fetch_assoc($ownResult);

                // Hitung sisa murni PO (PO_Qty - Received_Others)
                $sisaQtyPure = max(0, $poQty - $tol['others_qty']);

                // Sisa maksimal yang diizinkan (Toleransi 10%)
                $sisaQtyBolehditerima = $tol['qty_receivable_max'];

                // Jika Sisa Qty Murni <= 0 dan bukan dalam mode Edit item terkait, skip (jangan tampilkan)
                if ($sisaQtyPure <= 0.0001 && !$own) {
                    continue;
                }

                // [PPN] PO mode Include: harga PO sudah termasuk PPN -> default biaya GRN = DPP (harga / (1 + tarif))
                $ppnMode  = strtoupper((string)$r['ppn_mode']);
                $ppnRate  = (float)$r['ppn_rate'];
                $dppPrice = ($ppnMode === 'INCL' && $ppnRate > 0) ? round((float)$r['unit_price'] / (1 + $ppnRate / 100), 4) : (float)$r['unit_price'];

                $data[] = [
                    'po_dtl_id'             => $r['po_dtl_id'],
                    'item_id'               => $r['item_id'],
                    'item_name'             => $r['item_name'],
                    'qty_ordered'           => $poQty,
                    'unit_price'            => (float)$r['unit_price'],
                    'qty_received'          => $tol['others_qty'],
                    'sisa_qty_po'           => $sisaQtyPure,
                    'qty_max_allowed'       => $tol['qty_max_allowed'],
                    'qty_receivable_max'    => $sisaQtyBolehditerima,
                    'qty_received_this_grn' => $own ? (float)$own['qty_received'] : $sisaQtyPure,
                    'unit_cost_this_grn'    => $own ? (float)$own['unit_cost'] : $dppPrice,       // [PPN] default = DPP
                    'unit_price_dpp'        => $dppPrice,                                          // [PPN]
                    'ppn_mode'              => $ppnMode,                                           // [PPN]
                    'ppn_rate'              => $ppnRate                                            // [PPN]
                ];
            }

            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data,
                              'currency' => GrnCurrencyInfo($po_id, $grn_id)]);
            break;

        case 'read':
            // [CHG] tambah g.vehicle_plate_no, g.driver_name untuk kolom grid & panel detail
            $sql = "SELECT g.id, g.ref_no, g.grn_date, g.status, g.remark,
                           g.vehicle_plate_no, g.driver_name, g.supplier_do_no,      -- [NEW] No. DO Supplier
                           p.po_number AS po_ref_no, g.po_id, s.id AS supplier_id, s.name AS supplier_name,
                           (SELECT COALESCE(SUM(qty_received),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_qty,
                           (SELECT COALESCE(SUM(qty_received * unit_cost),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_amount,
                           (SELECT COALESCE(SUM(qty_received * unit_cost_base),0) FROM grn_dtl d WHERE d.grn_hdr_id = g.id) AS total_base,
                           UPPER(TRIM(cur.init)) AS currency_code, COALESCE(NULLIF(g.exch_rate,0),1) AS exch_rate
                    FROM grn_hdr g
                    LEFT JOIN purchase_orders p ON p.id = g.po_id
                    LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                    LEFT JOIN m_currency cur ON cur.id = g.currency_id
                    ORDER BY g.grn_date DESC, g.id DESC";
            $result = pg_query($sql);
            PgOrFail($result, 'Gagal load daftar GRN');
            $all = pg_fetch_all($result) ?: [];
            echo json_encode(['success' => true, 'totalCount' => count($all), 'data' => $all]);
            break;

        case 'read_grn_items':
            $grn_id = (int)($_REQUEST['grn_id'] ?? 0);
            $sql = "SELECT item_name, qty_received AS qty, unit_cost AS unit_price, unit_cost_base
                    FROM grn_dtl WHERE grn_hdr_id = $1 ORDER BY id";
            $result = pg_query_params($sql, [$grn_id]);
            PgOrFail($result, 'Gagal load item GRN');
            echo json_encode(['success' => true, 'data' => pg_fetch_all($result) ?: [],
                              'currency' => GrnCurrencyInfo(0, $grn_id)]);
            break;

        case 'read_edit':
            // SELECT * -> kolom vehicle_plate_no & driver_name otomatis ikut terisi ke form (name field sama)
            $id = (int)($_REQUEST['id'] ?? 0);
            $hdrResult = pg_query_params("SELECT * FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($hdrResult, 'Gagal load GRN');
            $hdr = pg_fetch_assoc($hdrResult);
            if (!$hdr) throw new Exception('Data tidak ditemukan');
            echo json_encode(['success' => true, 'data' => $hdr]);
            break;

        case 'read_grn_print':
            // [NEW] Data Tanda Terima GRN. Sengaja TANPA harga (dokumen untuk driver/supplier).
            $grn_id = (int)($_REQUEST['grn_id'] ?? 0);
            if (!$grn_id) throw new Exception('GRN tidak valid');

            $hRes = pg_query_params(
                "SELECT g.id, g.ref_no, g.grn_date, g.status, g.remark,
                        g.vehicle_plate_no, g.driver_name, g.supplier_do_no,      -- [NEW]
                        p.po_number AS po_ref_no, p.po_date AS po_date,
                        pr.name AS project_name,
                        s.name AS supplier_name,
                        COALESCE(e.employee_name, u.login_name) AS created_by_name
                   FROM grn_hdr g
                   LEFT JOIN purchase_orders p ON p.id = g.po_id
                   LEFT JOIN projects pr ON pr.id = p.project_id
                   LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                   LEFT JOIN m_user_system u ON u.id = g.created_by
                   LEFT JOIN m_employee e ON e.id = u.employee_id
                  WHERE g.id = $1",
                [$grn_id]
            );
            PgOrFail($hRes, 'Gagal load header cetak GRN');
            $hdr = pg_fetch_assoc($hRes);
            if (!$hdr) throw new Exception('GRN tidak ditemukan');

            // qty_cum = akumulasi diterima untuk baris PO tsb s/d GRN ini (GRN dengan id <= id ini)
            $dRes = pg_query_params(
                "SELECT d.id, d.item_name, m.material_code,
                        COALESCE(NULLIF(pi.unit,''), '') AS unit,
                        COALESCE(pi.qty, 0) AS qty_po,
                        d.qty_received AS qty,
                        (SELECT COALESCE(SUM(x.qty_received),0) FROM grn_dtl x
                          WHERE x.po_item_id = d.po_item_id AND x.grn_hdr_id <= d.grn_hdr_id) AS qty_cum,
                        d.remark
                   FROM grn_dtl d
                   LEFT JOIN po_items pi ON pi.id = d.po_item_id
                   LEFT JOIN materials m ON m.id = d.item_id
                  WHERE d.grn_hdr_id = $1
                  ORDER BY d.id",
                [$grn_id]
            );
            PgOrFail($dRes, 'Gagal load item cetak GRN');

            echo json_encode(['success' => true, 'data' => ['header' => $hdr, 'items' => pg_fetch_all($dRes) ?: []]]);
            break;

        case 'create':
            $po_id       = (int)($_REQUEST['po_id'] ?? 0);
            $supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
            $grn_date    = $_REQUEST['grn_date'] ?? date('Y-m-d');
            $remark      = $_REQUEST['remark'] ?? null;
            $arrItems    = json_decode($_REQUEST['arrItems'] ?? '[]', true);
            $created_by  = $_SESSION['user_id'] ?? null;
            // [NEW] plat no kendaraan & nama driver (dinormalisasi / divalidasi)
            $veh         = GrnCleanVehicle($_REQUEST['vehicle_plate_no'] ?? '', $_REQUEST['driver_name'] ?? '');
            $doNo        = GrnCleanDo($_REQUEST['supplier_do_no'] ?? '');      // [NEW] No. DO Supplier

            if (!$po_id) throw new Exception('PO belum dipilih');

            if (!$supplier_id) {
                $supRes = pg_query_params("SELECT supplier_id FROM purchase_orders WHERE id = $1", [$po_id]);
                if ($supRes && $supRow = pg_fetch_assoc($supRes)) {
                    $supplier_id = (int)$supRow['supplier_id'];
                }
            }

            if (!$supplier_id) throw new Exception('Supplier tidak ditemukan dari PO');
            if (empty($arrItems)) throw new Exception('Minimal 1 item harus diisi qty diterima');

            // PO harus berstatus Disetujui; mata uang & kurs PO dibekukan di GRN
            $poRes = pg_query_params("SELECT status, currency_id, COALESCE(NULLIF(exch_rate,0),1) AS exch_rate
                                        FROM purchase_orders WHERE id = $1", [$po_id]);
            PgOrFail($poRes, 'Gagal cek PO');
            $poRow = pg_fetch_assoc($poRes);
            if (!$poRow) throw new Exception('PO tidak ditemukan');
            if (!in_array($poRow['status'], GRN_PO_STATUS_LIST, true)) {
                throw new Exception('GRN hanya dapat dibuat dari PO berstatus ' . implode('/', GRN_PO_STATUS_LIST) .
                                    ' (status PO saat ini: ' . $poRow['status'] . ').');
            }
            $po_currency = $poRow['currency_id'] !== null ? (int)$poRow['currency_id'] : null;
            $po_rate     = (float)$poRow['exch_rate'];

            pg_query('BEGIN');
            SetPgAppUser();
            $ref_no = CreateRefNo('grn_hdr', 'ref_no', 'GRN');

            // [CHG] INSERT + vehicle_plate_no ($9), driver_name ($10)
            $hdrResult = pg_query_params(
                "INSERT INTO grn_hdr (ref_no, grn_date, po_id, supplier_id, status, remark, created_by, created_at, currency_id, exch_rate,
                                      vehicle_plate_no, driver_name, supplier_do_no)
                 VALUES ($1,$2,$3,$4,'open',$5,$6,NOW(),$7,$8,$9,$10,$11) RETURNING id",      // [CHG] + supplier_do_no ($11)
                [$ref_no, $grn_date, $po_id, $supplier_id, $remark, $created_by, $po_currency, $po_rate,
                 $veh['plate'], $veh['driver'], $doNo]
            );
            PgOrFail($hdrResult, 'Gagal insert grn_hdr');
            $grn_hdr_id = pg_fetch_result($hdrResult, 0, 'id');

            InsertGrnItems($grn_hdr_id, $arrItems, 0, $po_rate);

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => "GRN {$ref_no} berhasil disimpan.", 'id' => $grn_hdr_id, 'ref_no' => $ref_no]);
            break;

        case 'update':
            $id          = (int)($_REQUEST['id'] ?? 0);
            $po_id       = (int)($_REQUEST['po_id'] ?? 0);
            $supplier_id = (int)($_REQUEST['supplier_id'] ?? 0);
            $grn_date    = $_REQUEST['grn_date'] ?? date('Y-m-d');
            $remark      = $_REQUEST['remark'] ?? null;
            $arrItems    = json_decode($_REQUEST['arrItems'] ?? '[]', true);
            // [NEW] plat no kendaraan & nama driver
            $veh         = GrnCleanVehicle($_REQUEST['vehicle_plate_no'] ?? '', $_REQUEST['driver_name'] ?? '');
            $doNo        = GrnCleanDo($_REQUEST['supplier_do_no'] ?? '');      // [NEW] No. DO Supplier

            if (!$id) throw new Exception('GRN tidak valid');

            if (!$supplier_id && $po_id) {
                $supRes = pg_query_params("SELECT supplier_id FROM purchase_orders WHERE id = $1", [$po_id]);
                if ($supRes && $supRow = pg_fetch_assoc($supRes)) {
                    $supplier_id = (int)$supRow['supplier_id'];
                }
            }

            if (!$supplier_id) throw new Exception('Supplier belum dipilih');
            if (empty($arrItems)) throw new Exception('Minimal 1 item harus diisi qty diterima');

            $hRes = pg_query_params("SELECT COALESCE(NULLIF(exch_rate,0),1) AS exch_rate FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($hRes, 'Gagal load kurs GRN');
            $hRow = pg_fetch_assoc($hRes);
            if (!$hRow) throw new Exception('GRN tidak ditemukan');
            $grn_rate = (float)$hRow['exch_rate'];                       // kurs snapshot GRN tidak berubah saat edit

            pg_query('BEGIN');
            SetPgAppUser();

            GuardNoWhReceiving($id, 'diedit');

            $delResult = pg_query_params("DELETE FROM grn_dtl WHERE grn_hdr_id = $1", [$id]);
            PgOrFail($delResult, 'Gagal hapus baris lama grn_dtl');

            // [CHG] UPDATE + vehicle_plate_no ($5), driver_name ($6); id jadi $7
            $upd = pg_query_params(
                "UPDATE grn_hdr SET supplier_id = $1, grn_date = $2, remark = $3,
                        vehicle_plate_no = $4, driver_name = $5, supplier_do_no = $6
                  WHERE id = $7",      // [CHG] + supplier_do_no ($6); id jadi $7
                [$supplier_id, $grn_date, $remark, $veh['plate'], $veh['driver'], $doNo, $id]
            );
            PgOrFail($upd, 'Gagal update grn_hdr');

            InsertGrnItems($id, $arrItems, $id, $grn_rate);

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => 'GRN berhasil diupdate.', 'id' => $id]);
            break;

        case 'delete':
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('GRN tidak valid');

            pg_query('BEGIN');
            SetPgAppUser();

            GuardNoWhReceiving($id, 'dihapus');

            $delDtl = pg_query_params("DELETE FROM grn_dtl WHERE grn_hdr_id = $1", [$id]);
            PgOrFail($delDtl, 'Gagal hapus grn_dtl');

            $delHdr = pg_query_params("DELETE FROM grn_hdr WHERE id = $1", [$id]);
            PgOrFail($delHdr, 'Gagal hapus grn_hdr');

            pg_query('COMMIT');
            echo json_encode(['success' => true, 'message' => 'GRN berhasil dihapus.']);
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Exception $e) {
    @pg_query('ROLLBACK');
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

/**
 * [NEW] Normalisasi & validasi Plat No. Kendaraan + Nama Driver.
 *  - Plat: huruf besar, spasi ganda dirapikan, hanya huruf/angka/spasi/strip, maks 20 karakter.
 *  - Driver: spasi dirapikan, maks 100 karakter.
 *  - Kosong disimpan NULL, kecuali GRN_REQUIRE_VEHICLE = true (maka wajib diisi).
 * @return array ['plate' => string|null, 'driver' => string|null]
 */
function GrnCleanVehicle($plate, $driver) {
    $plate  = strtoupper(trim(preg_replace('/\s+/', ' ', (string)$plate)));
    $driver = trim(preg_replace('/\s+/', ' ', (string)$driver));

    if (GRN_REQUIRE_VEHICLE) {
        if ($plate === '')  throw new Exception('Plat No. Kendaraan wajib diisi.');
        if ($driver === '') throw new Exception('Nama Driver wajib diisi.');
    }
    if ($plate !== '') {
        if (strlen($plate) > 20) throw new Exception('Plat No. Kendaraan maksimal 20 karakter.');
        if (!preg_match('/^[A-Z0-9 \-]+$/', $plate)) throw new Exception('Plat No. Kendaraan hanya boleh huruf, angka, spasi, dan strip (-).');
    }
    // [FIX 2026-10-09] mb_strlen() tidak tersedia (ekstensi mbstring belum terpasang di server) -> Fatal error.
    // Pakai mb_strlen hanya bila ada; fallback ke hitung karakter UTF-8 via preg_match_all('/./u').
    $drvLen = function_exists('mb_strlen') ? mb_strlen($driver, 'UTF-8') : preg_match_all('/./us', $driver);
    if ($driver !== '' && $drvLen > 100) throw new Exception('Nama Driver maksimal 100 karakter.');

    return ['plate' => ($plate === '' ? null : $plate), 'driver' => ($driver === '' ? null : $driver)];
}

/**
 * [NEW] Normalisasi & validasi No. DO Supplier (nomor surat jalan dari supplier).
 *  - Spasi dirapikan, maks 100 karakter. Kosong disimpan NULL, kecuali GRN_REQUIRE_DO = true (wajib diisi).
 */
function GrnCleanDo($do) {
    $do = trim(preg_replace('/\s+/', ' ', (string)$do));
    if ($do === '') {
        if (GRN_REQUIRE_DO) throw new Exception('No. DO Supplier wajib diisi.');
        return null;
    }
    $len = function_exists('mb_strlen') ? mb_strlen($do, 'UTF-8') : preg_match_all('/./us', $do);
    if ($len > 100) throw new Exception('No. DO Supplier maksimal 100 karakter.');
    return $do;
}

function InsertGrnItems($grn_hdr_id, $arrItems, $exclude_grn_hdr_id, $rate = 1.0) {
    $any_line = false;

    foreach ($arrItems as $it) {
        $qty = (float)($it['qty_received'] ?? 0);
        if ($qty <= 0) continue;

        $poResult = pg_query_params("SELECT pi.qty AS po_qty, pi.unit_price, po.ppn_mode, po.ppn_rate
                                       FROM po_items pi JOIN purchase_orders po ON po.id = pi.po_id
                                      WHERE pi.id = $1", [$it['po_dtl_id']]);        // [CHG][PPN] + harga & mode PPN PO
        PgOrFail($poResult, 'Gagal cek qty PO');
        $poRow = pg_fetch_assoc($poResult);
        if (!$poRow) throw new Exception('Baris PO tidak ditemukan (po_dtl_id=' . $it['po_dtl_id'] . ')');

        // [PPN] PO mode Include: unit_cost GRN harus DPP; tolak bila sama dengan harga PO yang sudah termasuk PPN
        if (strtoupper((string)$poRow['ppn_mode']) === 'INCL' && (float)$poRow['ppn_rate'] > 0) {
            $gross = (float)$poRow['unit_price'];
            $dpp   = round($gross / (1 + (float)$poRow['ppn_rate'] / 100), 4);
            if (abs($gross - $dpp) > 0.005 && abs((float)($it['unit_cost'] ?? 0) - $gross) < 0.005) {
                throw new Exception("Harga PO {$it['item_name']} sudah termasuk PPN (" . (float)$poRow['ppn_rate'] .
                                    "%). Biaya per unit GRN harus DPP: " . $dpp . ".");
            }
        }

        $tol = GetPoDtlTolerance($it['po_dtl_id'], (float)$poRow['po_qty'], $exclude_grn_hdr_id);
        if ($qty > $tol['qty_receivable_max'] + 0.0001) {
            throw new Exception("Qty GRN untuk {$it['item_name']} ({$qty}) melebihi batas toleransi +10% PO (sisa maksimal: " . round($tol['qty_receivable_max'], 4) . ")");
        }

        $dtlResult = pg_query_params(
            "INSERT INTO grn_dtl (grn_hdr_id, po_item_id, item_id, item_name, qty_received, unit_cost, unit_cost_base, remark)
             VALUES ($1,$2,$3,$4,$5,$6,$7,$8)",
            [
                $grn_hdr_id, $it['po_dtl_id'], $it['item_id'], $it['item_name'],
                $qty, $it['unit_cost'], round((float)$it['unit_cost'] * $rate, 4), $it['remark'] ?? null
            ]
        );
        PgOrFail($dtlResult, 'Gagal insert grn_dtl');

        $any_line = true;
    }

    if (!$any_line) {
        throw new Exception('Tidak ada baris dengan qty > 0');
    }
}

function GuardNoWhReceiving($grn_hdr_id, $actionLabel) {
    $chkResult = pg_query_params(
        "SELECT COALESCE(SUM(d.qty_received),0) AS total_wh
         FROM wh_receiving_dtl d
         JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
         JOIN grn_dtl gd ON gd.id = d.grn_dtl_id
         WHERE gd.grn_hdr_id = $1",
        [$grn_hdr_id]
    );
    PgOrFail($chkResult, 'Gagal cek qty yang sudah di-WH Rcv');
    $chk = pg_fetch_assoc($chkResult);
    if ((float)$chk['total_wh'] > 0) {
        throw new Exception("GRN tidak bisa {$actionLabel} -- sudah ada WH Rcv terkait. Reverse WH Rcv-nya dulu.");
    }
}