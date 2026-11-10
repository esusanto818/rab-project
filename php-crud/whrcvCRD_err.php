<?php
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
 * ============================================================
 */

/*
Document    : Master Proyek CRD (Backend API)
Description : Mengelola operasi Create, Read, Update, Delete untuk Master Data Proyek
              Terhubung ke tabel projects, clients, m_location, dan m_employee
File related: m_proyek.php, m_proyek.js
*/

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

// Koneksi ke Database (Sesuaikan dengan koneksi PDO/PostgreSQL aplikasi Anda)
//require_once "../config/db.php"; 

if (session_status() === PHP_SESSION_NONE) { session_start(); }
$legacyUid = (int)($_SESSION['user_id'] ?? 0);
if ($legacyUid <= 0) { http_response_code(401); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Sesi berakhir, silakan login ulang.']); exit; }
$legacyAction = $_REQUEST['action'] ?? '';
$legacyTask = $_REQUEST['task'] ?? '';
$legacyRequiredAccess = 1;
if (in_array($legacyAction, ['save'], true) || $legacyTask === 'create') $legacyRequiredAccess = 2;
if ($legacyAction === 'update') $legacyRequiredAccess = 3;
if (in_array($legacyAction, ['delete'], true) || $legacyTask === 'reverse') $legacyRequiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $legacyRequiredAccess) { http_response_code(403); header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'Hak akses tidak mencukupi untuk penerimaan gudang.']); exit; }
$action =$_REQUEST['action'] ?? '';

switch ($action) {

    // =========================================================================
    // 1. READ LIST DATA PROYEK (GRID MAIN)
    // =========================================================================
    case 'read':
        try {
            $page  = isset($_POST['page']) ? intval($_POST['page']) : 1;
            $limit = isset($_POST['rows']) ? intval($_POST['rows']) : 20;
            $offset = ($page - 1) *$limit;
            $search =$_POST['search'] ?? '';

            $whereClause = "";
            $params = [];

            if (!empty($search)) {$whereClause = "WHERE p.name ILIKE :search OR c.name ILIKE :search OR p.status ILIKE :search";
                $params[':search'] = '\%' .$search . '%';
            }

            // Count total rows
            $stmtCount =$db->prepare("
                SELECT COUNT(*) 
                FROM projects p
                LEFT JOIN clients c ON p.client_id = c.id
                $whereClause
            ");
            $stmtCount->execute($params);
            $total =$stmtCount->fetchColumn();

            // Query Data Main Grid
            $stmtData =$db->prepare("
                SELECT 
                    p.id,
                    p.name AS project_name,
                    p.client_id,
                    c.name AS client_name,
                    p.location_id,
                    loc.name AS location_name,
                    p.location AS location_detail,
                    p.pic_id,
                    emp.employee_name AS pic_name,
                    p.start_date,
                    p.end_date,
                    p.budget,
                    p.contract_value,
                    p.spent,
                    p.progress,
                    p.status,
                    p.notes
                FROM projects p
                LEFT JOIN clients c ON p.client_id = c.id
                LEFT JOIN m_location loc ON p.location_id = loc.id
                LEFT JOIN m_employee emp ON p.pic_id = emp.id
                $whereClause
                ORDER BY p.id DESC
                LIMIT :limit OFFSET :offset
            ");

            foreach ($params as$key => $val) {$stmtData->bindValue($key,$val);
            }
            $stmtData->bindValue(':limit', $limit, PDO::PARAM_INT);$stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);$stmtData->execute();

            $rows =$stmtData->fetchAll(PDO::FETCH_ASSOC);

            echo json_encode([
                'success' => true,
                'total'   => $total,
                'rows'    => $rows
            ]);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Database Error: ' . $e->getMessage()]);
        }
        break;

    // =========================================================================
    // 2. READ LOOKUP / COMBOBOX DATA (CLIENTS, LOCATIONS, PICS)
    // =========================================================================
    case 'get_clients':
        $stmt =$db->query("SELECT id, name FROM clients ORDER BY name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'get_locations':
        $stmt =$db->query("SELECT id, name FROM m_location ORDER BY name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    case 'get_pics':
        $stmt =$db->query("SELECT id, employee_name AS name FROM m_employee WHERE active = true ORDER BY employee_name ASC");
        echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
        break;

    // =========================================================================
    // 3. CREATE & UPDATE (SAVE DATA)
    // =========================================================================
    case 'save':
        try {
            $id             =$_POST['id'] ?? null;
            $name           = trim($_POST['project_name'] ?? '');$client_id      = !empty($_POST['client_id']) ? intval($_POST['client_id']) : null;
            $location_id    = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
            $location_detail= trim($_POST['location_detail'] ?? '');$pic_id         = !empty($_POST['pic_id']) ? intval($_POST['pic_id']) : null;
            $start_date     = !empty($_POST['start_date']) ?$_POST['start_date'] : null;
            $end_date       = !empty($_POST['end_date']) ?$_POST['end_date'] : null;
            $budget         = !empty($_POST['budget']) ? floatval($_POST['budget']) : 0;
            $contract_value = !empty($_POST['contract_value']) ? floatval($_POST['contract_value']) : 0;
            $status         =$_POST['status'] ?? 'Berjalan';
            $notes          = trim($_POST['notes'] ?? '');

            if (empty($name)) {
                echo json_encode(['success' => false, 'message' => 'Nama proyek wajib diisi!']);
                exit;
            }

            if (!empty($id)) {
                // UPDATE
                $stmt =$db->prepare("
                    UPDATE projects SET
                        name = :name,
                        client_id = :client_id,
                        location_id = :location_id,
                        location = :location_detail,
                        pic_id = :pic_id,
                        start_date = :start_date,
                        end_date = :end_date,
                        budget = :budget,
                        contract_value = :contract_value,
                        status = :status,
                        notes = :notes,
                        updated_at = NOW()
                    WHERE id = :id
                ");
                $stmt->bindValue(':id',$id, PDO::PARAM_INT);
            } else {
                // INSERT
                $stmt =$db->prepare("
                    INSERT INTO projects (
                        name, client_id, location_id, location, pic_id, 
                        start_date, end_date, budget, contract_value, status, notes, created_at, updated_at
                    ) VALUES (
                        :name, :client_id, :location_id, :location_detail, :pic_id, 
                        :start_date, :end_date, :budget, :contract_value, :status, :notes, NOW(), NOW()
                    )
                ");
            }

            $stmt->bindValue(':name', $name);$stmt->bindValue(':client_id', $client_id,$client_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':location_id', $location_id,$location_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':location_detail', $location_detail);$stmt->bindValue(':pic_id', $pic_id,$pic_id ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':start_date', $start_date,$start_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':end_date', $end_date,$end_date ? PDO::PARAM_STR : PDO::PARAM_NULL);
            $stmt->bindValue(':budget', $budget);$stmt->bindValue(':contract_value', $contract_value);$stmt->bindValue(':status', $status);$stmt->bindValue(':notes', $notes);

            $stmt->execute();

            echo json_encode(['success' => true, 'message' => 'Data proyek berhasil disimpan.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menyimpan data: ' . $e->getMessage()]);
        }
        break;

    // =========================================================================
    // 4. DELETE DATA PROYEK
    // =========================================================================
    case 'delete':
        try {
            $id = intval($_POST['id'] ?? 0);

            if ($id <= 0) {
                echo json_encode(['success' => false, 'message' => 'ID tidak valid!']);
                exit;
            }

            // Cek constraint relasi (misal jika proyek sudah ada transaksi)
            $checkStmt =$db->prepare("SELECT COUNT(*) FROM purchase_orders WHERE project_id = :id");
            $checkStmt->execute([':id' =>$id]);
            if ($checkStmt->fetchColumn() > 0) {
                echo json_encode(['success' => false, 'message' => 'Proyek tidak dapat dihapus karena sudah memiliki Purchase Order terpaut.']);
                exit;
            }

            $stmt =$db->prepare("DELETE FROM projects WHERE id = :id");
            $stmt->execute([':id' =>$id]);

            echo json_encode(['success' => true, 'message' => 'Proyek berhasil dihapus.']);
        } catch (PDOException $e) {
            echo json_encode(['success' => false, 'message' => 'Gagal menghapus data: ' . $e->getMessage()]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Aksi tidak valid!']);
        break;
}
session_start();
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';
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
                           s.name AS supplier_name
                    FROM grn_hdr g
                    LEFT JOIN m_suppliers s ON s.id = g.supplier_id
                    JOIN grn_dtl gd ON gd.grn_hdr_id = g.id
                    LEFT JOIN (
                        SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                        FROM wh_receiving_dtl d
                        JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                        GROUP BY d.grn_dtl_id
                    ) wd ON wd.grn_dtl_id = gd.id
                    WHERE g.status IN ('open','partial')
                    GROUP BY g.id, g.ref_no, g.grn_date, g.status, s.name
                    HAVING SUM(gd.qty_received) - COALESCE(SUM(wd.qty_wh_sum), 0) > 0.0001
                    ORDER BY g.grn_date DESC";
            echo json_encode(['success' => true, 'data' => fetchAll($db, $sql, [], 'list_grn_receivable')]);
            break;

        case 'load_grn_dtl':
            $grn_hdr_id = (int)($_REQUEST['grn_hdr_id'] ?? 0);
            $sql = "SELECT gd.id AS grn_dtl_id, gd.item_id, gd.item_name,
                           gd.qty_received AS qty_grn, gd.grade_id, gd.unit_cost,
                           COALESCE(wd.qty_wh_sum, 0) AS qty_wh_received,
                           (gd.qty_received - COALESCE(wd.qty_wh_sum, 0)) AS qty_remaining
                    FROM grn_dtl gd
                    LEFT JOIN (
                        SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                        FROM wh_receiving_dtl d
                        JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                        GROUP BY d.grn_dtl_id
                    ) wd ON wd.grn_dtl_id = gd.id
                    WHERE gd.grn_hdr_id = :grn_hdr_id
                      AND (gd.qty_received - COALESCE(wd.qty_wh_sum, 0)) > 0.0001
                    ORDER BY gd.id";
            $data = fetchAll($db, $sql, ['grn_hdr_id' => $grn_hdr_id], 'load_grn_dtl');
            echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
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
                           s.name AS supplier_name,
                           (SELECT COALESCE(SUM(qty_received * unit_cost), 0)
                            FROM wh_receiving_dtl d
                            WHERE d.wh_receiving_hdr_id = w.id) AS total_amount
                    FROM wh_receiving_hdr w
                    JOIN grn_hdr g ON g.id = w.grn_hdr_id
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
                        "SELECT (gd.qty_received - COALESCE(wd.qty_wh_sum,0)) AS qty_remaining, gd.grade_id
                           FROM grn_dtl gd
                           LEFT JOIN (
                               SELECT d.grn_dtl_id, SUM(d.qty_received) AS qty_wh_sum
                                 FROM wh_receiving_dtl d
                                 JOIN wh_receiving_hdr h ON h.id = d.wh_receiving_hdr_id AND h.status = 'posted'
                                WHERE d.grn_dtl_id = :grn_dtl_id
                                GROUP BY d.grn_dtl_id
                           ) wd ON wd.grn_dtl_id = gd.id
                          WHERE gd.id = :grn_dtl_id
                          FOR UPDATE OF gd",
                        ['grn_dtl_id' => $it['grn_dtl_id']], 'cek sisa qty GRN');
                    if (!$chk) throw new Exception('Baris GRN tidak ditemukan (grn_dtl_id=' . $it['grn_dtl_id'] . ')');
                    if ($qty > (float)$chk['qty_remaining'] + 0.0001) {
                        throw new Exception("Qty WH Rcv untuk {$it['item_name']} ({$qty}) melebihi sisa GRN (" . round((float)$chk['qty_remaining'], 4) . ")");
                    }

                    $cost = (float)($it['unit_cost'] ?? 0);
                    $total_amount += ($qty * $cost);
                    $grade_id = !empty($it['grade_id']) ? $it['grade_id'] : $chk['grade_id'];

                    execSql($db,
                        "INSERT INTO wh_receiving_dtl
                            (wh_receiving_hdr_id, grn_dtl_id, item_id, item_name, qty_received, unit_cost,
                             bin_location_id, lot_no, grade_id, remark)
                         VALUES (:hdr_id, :grn_dtl_id, :item_id, :item_name, :qty, :cost,
                                 :bin_location_id, :lot_no, :grade_id, :remark)",
                        [
                            'hdr_id' => $wh_receiving_hdr_id, 'grn_dtl_id' => $it['grn_dtl_id'],
                            'item_id' => $it['item_id'], 'item_name' => $it['item_name'],
                            'qty' => $qty, 'cost' => $cost,
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
                    'amount'         => $total_amount,
                    'description'    => "Penerimaan fisik gudang - {$ref_no}"
                ]);
                if (empty($journalResult['success'])) {
                    throw new Exception('Gagal posting jurnal: ' . ($journalResult['message'] ?? 'tanpa pesan'));
                }

                RecalcGrnStatus($grn_hdr_id);

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

                RecalcGrnStatus($hdr['grn_hdr_id']);

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