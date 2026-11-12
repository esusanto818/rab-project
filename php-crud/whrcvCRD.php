<?php
require_once __DIR__ . '/../php-func/user.valid.php';
/**
 * whrcvCRD.php
 * ============================================================
 * Backend Controller untuk WH Receiving (Penerimaan Fisik Gudang)
 * Mengontrol alur penerimaan barang berdasarkan GRN, pencatatan
 * ke stock_movements, serta integrasi Auto Journal.
 *
 * PERBAIKAN (dari versi sebelumnya):
 * - [FIX UTAMA] Versi lama memakai DUA KONEKSI/TRANSAKSI SEKALIGUS: pg_query
 *   native (BEGIN/COMMIT) untuk header+detail, dan $db PDO (beginTransaction/
 *   commit) untuk PostStockMovement()/AutoPostJournal(). Kalau salah satu sisi
 *   gagal commit SETELAH sisi lain sudah berhasil, hasilnya header WH Rcv
 *   tersimpan "posted" tapi detail/stok/jurnalnya hilang -- persis gejala
 *   "total nilai 0.00, tidak ada item detail" yang muncul di produksi.
 *   Sekarang SEMUA query (termasuk yang tadinya pg_query_params) memakai satu
 *   koneksi PDO ($db) yang sama, satu transaksi tunggal.
 * - m_bin_location -> bin_locations (perbaikan sebelumnya, dipertahankan).
 * - Validasi "total qty diterima harus lebih dari 0" sekarang dicek SEBELUM
 *   ada INSERT apa pun (dari total qty, bukan dari qty*unit_cost) -- supaya
 *   item dengan unit_cost=0 (data GRN yang belum diisi harga) tetap valid
 *   tersimpan, tidak keliru dianggap "tidak ada yang diterima".
 *
 * [FIX] Versi sebelumnya memuat BLOK TEMPELAN dari Master Proyek CRD (switch ($action) ... case 'read','save',
 *   'delete' milik m_proyek) tepat sebelum kode ini. Untuk request ber-'task' blok itu mencetak
 *   {"success":false,"message":"Aksi tidak valid!"} LALU kode WH Rcv mencetak respons asli -> dua JSON
 *   tersambung, ExtJS gagal decode. Blok tempelan sudah dibuang.
 *
 * MULTI CURRENCY (sql/2026-10-01.grn_currency.sql):
 *  - wh_receiving_dtl.unit_cost = harga per unit dalam MATA UANG DASAR, diambil dari grn_dtl.unit_cost_base
 *    (server yang menentukan; unit_cost kiriman klien diabaikan). Inilah dasar layer FIFO, stock_movements,
 *    dan jurnal GRN_RCV.
 *  - unit_cost_txn / currency_id / exch_rate menyimpan harga & kurs asal sebagai jejak audit.
 * ============================================================
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) { session_start(); }
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Sesi berakhir, silakan login ulang.']); exit; }
$whrcvRequiredAccess = 1;
if ($task === 'create') $whrcvRequiredAccess = 2;
if ($task === 'reverse') $whrcvRequiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $whrcvRequiredAccess) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak akses tidak mencukupi untuk penerimaan gudang.']); exit; }
if (in_array($task, ['create', 'reverse'], true) && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Metode harus POST untuk transaksi penerimaan gudang.']);
    exit;
}
$db   = new crud(); // PDO Connection (satu-satunya koneksi yang dipakai file ini)

function PrepOrFail($stmt, $context = '') {
    if ($stmt === false) {
        throw new Exception(($context ? "[{$context}] " : '') . 'Query gagal dijalankan.');
    }
    return $stmt;
}

/** SELECT dan fetchAll dalam satu langkah. */
function fetchAll($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFail($stmt, $ctx);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/** SELECT dan fetch satu baris. */
function fetchOne($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFail($stmt, $ctx);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}

/** INSERT/UPDATE/DELETE biasa (tanpa perlu baris kembalian). */
function execSql($db, $sql, array $params = [], $ctx = '') {
    $stmt = $db->prepare($sql);
    PrepOrFail($stmt, $ctx);
    $stmt->execute($params);
    return $stmt;
}

try {
    switch ($task) {

        case 'list_grn_receivable':
            $sql = "SELECT g.id, g.ref_no, g.grn_date, g.status,
                           s.name AS supplier_name,
                           UPPER(TRIM(cur.init)) AS currency_code, COALESCE(NULLIF(g.exch_rate,0),1) AS exch_rate
                    FROM grn_hdr g
                    LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                    LEFT JOIN m_currency cur ON cur.id = g.currency_id
                    JOIN grn_dtl gd ON gd.grn_hdr_id = g.id
                    LEFT JOIN (
                        SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                        FROM wh_receiving_dtl d
                        JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                        GROUP BY d.grn_dtl_id
                    ) wd ON wd.grn_dtl_id = gd.id
                    WHERE g.status IN ('open','partial')
                    GROUP BY g.id, g.ref_no, g.grn_date, g.status, s.name, cur.init, g.exch_rate
                    HAVING SUM(gd.qty_received) - COALESCE(SUM(wd.qty_wh_sum), 0) > 0.0001
                    ORDER BY g.grn_date DESC";
            echo json_encode(['success' => true, 'data' => fetchAll($db, $sql, [], 'list_grn_receivable')]);
            break;

        case 'load_grn_dtl':
            $grn_hdr_id = (int)($_REQUEST['grn_hdr_id'] ?? 0);
            $sql = "SELECT gd.id AS grn_dtl_id, gd.item_id, gd.item_name,
                           gd.qty_received AS qty_grn, gd.grade_id, gd.unit_cost,
                           COALESCE(NULLIF(gd.unit_cost_base, 0), gd.unit_cost * COALESCE(NULLIF(g.exch_rate,0), 1)) AS unit_cost_base,
                           COALESCE(wd.qty_wh_sum, 0) AS qty_wh_received,
                           (gd.qty_received - COALESCE(wd.qty_wh_sum, 0)) AS qty_remaining
                    FROM grn_dtl gd
                    LEFT JOIN (
                        SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                        FROM wh_receiving_dtl d
                        JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                        GROUP BY d.grn_dtl_id
                    ) wd ON wd.grn_dtl_id = gd.id
                    JOIN grn_hdr g ON g.id = gd.grn_hdr_id
                    WHERE gd.grn_hdr_id = :grn_hdr_id
                      AND (gd.qty_received - COALESCE(wd.qty_wh_sum, 0)) > 0.0001
                    ORDER BY gd.id";
            $data = fetchAll($db, $sql, ['grn_hdr_id' => $grn_hdr_id], 'load_grn_dtl');
            $curInfo = fetchOne($db,
                "SELECT UPPER(TRIM(c.init)) AS code, COALESCE(NULLIF(g.exch_rate,0), 1) AS exch_rate
                   FROM grn_hdr g LEFT JOIN m_currency c ON c.id = g.currency_id WHERE g.id = :id",
                ['id' => $grn_hdr_id], 'load_grn_dtl currency');
            $baseCode = 'IDR';
            if (fetchOne($db, "SELECT to_regclass('public.finance_settings') AS t", [], 'cek finance_settings')['t']) {
                $b = fetchOne($db, "SELECT UPPER(TRIM(c.init)) AS code FROM finance_settings f JOIN m_currency c ON c.id = f.base_currency_id WHERE f.id = 1", [], 'base currency');
                if ($b && $b['code']) $baseCode = $b['code'];
            }
            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data,
                              'currency' => ['code' => ($curInfo['code'] ?? null) ?: $baseCode, 'base_code' => $baseCode,
                                             'exch_rate' => (float)($curInfo['exch_rate'] ?? 1)]]);
            break;

        case 'list_bin_locations':
            $warehouse_id = (int)($_REQUEST['warehouse_id'] ?? 0);
            $sql = "SELECT id, code, name, zone, rack, level
                    FROM bin_locations
                    WHERE warehouse_id = :warehouse_id AND active IS TRUE
                    ORDER BY code";
            echo json_encode(['success' => true, 'data' => fetchAll($db, $sql, ['warehouse_id' => $warehouse_id], 'list_bin_locations')]);
            break;

        case 'list_warehouses':
            $sql = "SELECT id, code AS init, name
                    FROM warehouses
                    WHERE active IS TRUE AND id IN (SELECT warehouse_id FROM bin_locations WHERE active IS TRUE)
                    ORDER BY name";
            echo json_encode(['success' => true, 'data' => fetchAll($db, $sql, [], 'list_warehouses')]);
            break;

        case 'list':
            $sql = "SELECT w.id, w.ref_no, w.received_date, w.status,
                           wh.name AS warehouse_name, g.ref_no AS grn_ref_no,
                           g.supplier_do_no,      -- [NEW] No. DO Supplier (dari GRN, read-only)
                           s.name AS supplier_name,
                           (SELECT COALESCE(SUM(qty_received * unit_cost), 0)
                            FROM wh_receiving_dtl d
                            WHERE d.wh_receiving_hdr_id = w.id) AS total_amount,
                           (SELECT COALESCE(SUM(qty_received * COALESCE(unit_cost_txn, unit_cost)), 0)
                            FROM wh_receiving_dtl d
                            WHERE d.wh_receiving_hdr_id = w.id) AS total_txn,
                           UPPER(TRIM(cur.init)) AS currency_code
                    FROM wh_receiving_hdr w
                    JOIN grn_hdr g ON g.id = w.grn_hdr_id
                    LEFT JOIN m_currency cur ON cur.id = g.currency_id
                    LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                    LEFT JOIN warehouses wh ON wh.id = w.warehouse_id
                    ORDER BY w.received_date DESC, w.id DESC";
            $all = fetchAll($db, $sql, [], 'list');
            echo json_encode(['success' => true, 'totalCount' => count($all), 'data' => $all]);
            break;

        case 'read_edit':
            $id = (int)($_REQUEST['id'] ?? 0);
            $hdr = fetchOne($db, "SELECT * FROM wh_receiving_hdr WHERE id = :id", ['id' => $id], 'read_edit hdr');
            if (!$hdr) throw new Exception('Data tidak ditemukan');

            $hdr['items'] = fetchAll($db,
                "SELECT d.*, b.code AS bin_code, b.name AS bin_name
                   FROM wh_receiving_dtl d
                   LEFT JOIN bin_locations b ON b.id = d.bin_location_id
                  WHERE d.wh_receiving_hdr_id = :id
                  ORDER BY d.id",
                ['id' => $id], 'read_edit dtl');

            $curRow = fetchOne($db,
                "SELECT UPPER(TRIM(c.init)) AS code FROM grn_hdr g LEFT JOIN m_currency c ON c.id = g.currency_id WHERE g.id = :id",
                ['id' => $hdr['grn_hdr_id']], 'read_edit currency');
            $hdr['currency_code'] = $curRow['code'] ?? null;

            echo json_encode(['success' => true, 'data' => $hdr]);
            break;

        case 'create':
            $grn_hdr_id    = (int)($_REQUEST['grn_hdr_id'] ?? 0);
            $warehouse_id  = (int)($_REQUEST['warehouse_id'] ?? 0);
            $received_date = $_REQUEST['received_date'] ?? date('Y-m-d');
            $remark        = $_REQUEST['remark'] ?? null;
            $received_by   = $_SESSION['user_id'] ?? null;
            $items         = json_decode($_REQUEST['items'] ?? '[]', true);

            if (!$grn_hdr_id) throw new Exception('GRN belum dipilih');
            if (!$warehouse_id) throw new Exception('Gudang penerima belum dipilih');
            if (empty($items)) throw new Exception('Minimal 1 item harus diisi qty diterima');

            $totalQtyCheck = 0.0;
            foreach ($items as $it) { $totalQtyCheck += (float)($it['qty_received'] ?? 0); }
            if ($totalQtyCheck <= 0) {
                throw new Exception('Total qty diterima harus lebih dari 0');
            }

            // Split bin: satu baris GRN boleh dipecah ke beberapa bin (beberapa baris dengan grn_dtl_id sama).
            // Aturan: tiap baris hasil pecahan wajib punya bin, bin tidak boleh dobel pada item yang sama,
            // dan bin harus milik gudang penerima. Total qty per baris GRN tetap dijaga oleh cek sisa di bawah.
            $perDtl = []; $usedBins = [];
            foreach ($items as $it) {
                if ((float)($it['qty_received'] ?? 0) <= 0) continue;
                $perDtl[(int)$it['grn_dtl_id']][] = $it;
            }
            foreach ($perDtl as $rowsOfDtl) {
                if (count($rowsOfDtl) < 2) continue;
                $seenBin = [];
                foreach ($rowsOfDtl as $r) {
                    $b = !empty($r['bin_location_id']) ? (int)$r['bin_location_id'] : 0;
                    if (!$b) throw new Exception("Item {$r['item_name']} dipecah ke beberapa baris: Bin Location wajib diisi di setiap baris.");
                    if (isset($seenBin[$b])) throw new Exception("Item {$r['item_name']}: bin yang sama dipakai dua kali. Gabungkan qty-nya ke satu baris.");
                    $seenBin[$b] = true;
                }
            }
            foreach ($items as $it) {
                if ((float)($it['qty_received'] ?? 0) > 0 && !empty($it['bin_location_id'])) $usedBins[(int)$it['bin_location_id']] = true;
            }
            if ($usedBins) {
                $ph = []; $binParams = ['wh' => $warehouse_id]; $bn = 0;
                foreach (array_keys($usedBins) as $bId) { $ph[] = ':b' . $bn; $binParams['b' . $bn] = $bId; $bn++; }
                $okBins = fetchAll($db, "SELECT id FROM bin_locations WHERE warehouse_id = :wh AND id IN (" . implode(',', $ph) . ")", $binParams, 'cek bin location');
                if (count($okBins) !== count($usedBins)) throw new Exception('Ada Bin Location yang bukan milik gudang penerima.');
            }

            $db->beginTransaction();
            try {
                $ref_no = function_exists('CreateRefNoDb')
                    ? CreateRefNoDb($db, 'wh_receiving_hdr', 'ref_no', 'WHR')
                    : CreateRefNo('wh_receiving_hdr', 'ref_no', 'WHR');
                if ($ref_no === '' || $ref_no === null) throw new Exception('Gagal generate No. Ref WH Rcv.');

                $wh_receiving_hdr_id = (int)fetchOne($db,
                    "INSERT INTO wh_receiving_hdr
                        (ref_no, grn_hdr_id, warehouse_id, received_date, remark, received_by, status, created_at)
                     VALUES (:ref_no, :grn_hdr_id, :warehouse_id, :received_date, :remark, :received_by, 'posted', NOW())
                     RETURNING id",
                    [
                        'ref_no' => $ref_no, 'grn_hdr_id' => $grn_hdr_id, 'warehouse_id' => $warehouse_id,
                        'received_date' => $received_date, 'remark' => $remark, 'received_by' => $received_by
                    ],
                    'insert wh_receiving_hdr')['id'];

                $total_amount = 0.0;

                foreach ($items as $it) {
                    $qty = (float)($it['qty_received'] ?? 0);
                    if ($qty <= 0) continue;

                    $chk = fetchOne($db,
                        "SELECT (gd.qty_received - COALESCE(wd.qty_wh_sum,0)) AS qty_remaining, gd.grade_id,
                                gd.unit_cost AS unit_cost_txn,
                                COALESCE(NULLIF(gd.unit_cost_base, 0), gd.unit_cost * COALESCE(NULLIF(g.exch_rate,0), 1)) AS unit_cost_base,
                                g.currency_id, COALESCE(NULLIF(g.exch_rate,0), 1) AS exch_rate
                           FROM grn_dtl gd
                           JOIN grn_hdr g ON g.id = gd.grn_hdr_id
                           LEFT JOIN (
                               SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                                 FROM wh_receiving_dtl d
                                 JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                                WHERE d.grn_dtl_id = :grn_dtl_id
                                GROUP BY d.grn_dtl_id
                           ) wd ON wd.grn_dtl_id = gd.id
                          WHERE gd.id = :grn_dtl_id AND gd.grn_hdr_id = :grn_hdr_id
                          FOR UPDATE OF gd",
                        ['grn_dtl_id' => $it['grn_dtl_id'], 'grn_hdr_id' => $grn_hdr_id], 'cek sisa qty GRN');
                    if (!$chk) throw new Exception('Baris GRN tidak ditemukan (grn_dtl_id=' . $it['grn_dtl_id'] . ')');
                    if ($qty > (float)$chk['qty_remaining'] + 0.0001) {
                        throw new Exception("Qty WH Rcv untuk {$it['item_name']} ({$qty}) melebihi sisa GRN (" . round((float)$chk['qty_remaining'], 4) . ")");
                    }

                    // Biaya MATA UANG DASAR ditentukan server dari GRN (unit_cost kiriman klien diabaikan)
                    $cost    = round((float)$chk['unit_cost_base'], 4);
                    $costTxn = (float)$chk['unit_cost_txn'];
                    $total_amount += round($qty * $cost, 2);
                    $grade_id = !empty($it['grade_id']) ? $it['grade_id'] : $chk['grade_id'];

                    execSql($db,
                        "INSERT INTO wh_receiving_dtl
                            (wh_receiving_hdr_id, grn_dtl_id, item_id, item_name, qty_received, unit_cost,
                             unit_cost_txn, currency_id, exch_rate,
                             bin_location_id, lot_no, grade_id, remark)
                         VALUES (:hdr_id, :grn_dtl_id, :item_id, :item_name, :qty, :cost,
                                 :cost_txn, :currency_id, :exch_rate,
                                 :bin_location_id, :lot_no, :grade_id, :remark)",
                        [
                            'hdr_id' => $wh_receiving_hdr_id, 'grn_dtl_id' => $it['grn_dtl_id'],
                            'item_id' => $it['item_id'], 'item_name' => $it['item_name'],
                            'qty' => $qty, 'cost' => $cost,
                            'cost_txn' => $costTxn, 'currency_id' => $chk['currency_id'], 'exch_rate' => (float)$chk['exch_rate'],
                            'bin_location_id' => $it['bin_location_id'] ?? null, 'lot_no' => $it['lot_no'] ?? null,
                            'grade_id' => $grade_id, 'remark' => $it['remark'] ?? null
                        ],
                        'insert wh_receiving_dtl');

                    PostStockMovement($db, [
                        'movement_date'  => $received_date,
                        'item_id'        => $it['item_id'],
                        'grade_id'       => $grade_id,
                        'warehouse_id'   => $warehouse_id,
                        'movement_type'  => 'IN',
                        'qty'            => $qty,
                        'unit_cost'      => $cost,
                        'reference_type' => 'wh_receiving',
                        'reference_id'   => $wh_receiving_hdr_id,
                        'reference_no'   => $ref_no,
                        'remark'         => $it['remark'] ?? 'Terima Fisik Gudang'
                    ]);
                }

                $journalResult = AutoPostJournal($db, [
                    'rule_code'      => 'GRN_RCV',
                    'reference_type' => 'wh_receiving',
                    'reference_id'   => $wh_receiving_hdr_id,
                    'journal_date'   => $received_date,
                    'amount'         => round($total_amount, 2),
                    'description'    => "Penerimaan fisik gudang - {$ref_no}"
                ]);
                if (empty($journalResult['success'])) {
                    throw new Exception('Gagal posting jurnal: ' . ($journalResult['message'] ?? 'tanpa pesan'));
                }

                RecalcGrnStatus($grn_hdr_id, $db);

                $db->commit();
                echo json_encode(['success' => true, 'id' => $wh_receiving_hdr_id, 'ref_no' => $ref_no, 'message' => "Penerimaan gudang {$ref_no} berhasil disimpan."]);
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        case 'reverse':
            $id = (int)($_REQUEST['id'] ?? 0);
            if (!$id) throw new Exception('ID WH Receiving tidak valid');

            $db->beginTransaction();
            try {
                $hdr = fetchOne($db,
                    "SELECT * FROM wh_receiving_hdr WHERE id = :id AND status = 'posted' FOR UPDATE",
                    ['id' => $id], 'reverse hdr check');
                if (!$hdr) throw new Exception('Data tidak ditemukan atau sudah di-reverse');

                ReverseStockMovementByReference($db, 'wh_receiving', $id);
                ReverseJournalByReference($db, 'wh_receiving', $id);

                execSql($db,
                    "UPDATE wh_receiving_hdr SET status = 'reversed', reversed_at = NOW(), reversed_by = :uid WHERE id = :id",
                    ['uid' => $_SESSION['user_id'] ?? null, 'id' => $id],
                    'update status reversed');

                RecalcGrnStatus($hdr['grn_hdr_id'], $db);

                $db->commit();
                echo json_encode(['success' => true, 'message' => 'Transaksi WH Receiving berhasil di-reverse.']);
            } catch (Throwable $e) {
                if ($db->inTransaction()) { $db->rollBack(); }
                throw $e;
            }
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Throwable $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage() !== '' ? $e->getMessage() : 'Terjadi kesalahan pada server.']);
}