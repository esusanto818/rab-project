<?php
/*
  Document      : Bin Location (Lokasi Rak) - Backend Controller
  Description   : Master data lokasi rak per gudang & filtering dynamic bin per warehouse
  Depends on    : bin_location_schema.sql, crudClass.php
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION["user_id"]) || empty($_SESSION["user_id"])) {
    http_response_code(401);
    echo json_encode(["success" => false, "message" => "Session expired. Silakan login kembali."]);
    exit;
}

$task = $_POST['task'] ?? $_REQUEST['task'] ?? 'read';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Hak baca Lokasi Rak tidak tersedia.']); exit; }
$db   = new crud();

switch ($task) {

    // ------------------------------------------------------------
    // 1. READ (Grid Utama Filter / Search)
    // ------------------------------------------------------------
    case 'read':
        $query       = $_POST['query'] ?? null;
        $warehouseId = intval($_POST['warehouse_id'] ?? 0);
        $srch        = searchData($query, ['bl.code', 'bl.name', 'wh.name']);
        
        $where = "1=1" . ($srch['searchSQL'] ?? '');
        if ($warehouseId > 0) {
            $where .= " AND bl.warehouse_id = " . $warehouseId;
        }
        $limit = $srch['limit'] ?? 250;

        $sql = "
        SELECT bl.id, bl.warehouse_id, wh.name AS warehouse_name,
               loc.name AS location_name, 
               -- Atau wh.location AS location_name (sesuai struktur DB)
               bl.code, bl.name, bl.zone, bl.rack, bl.level, bl.active
        FROM bin_locations bl
        LEFT JOIN warehouses wh ON wh.id = bl.warehouse_id
        LEFT JOIN m_location loc ON loc.id = wh.location_id -- Atyesikan relasi tabel location jika ada
        WHERE {$where}
        ORDER BY wh.name ASC, bl.code ASC
        LIMIT " . intval($limit);
            
        $stmt = $db->query($sql);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($data as &$row) {
            $row['active'] = ($row['active'] === true || $row['active'] === 't' || $row['active'] == 1 || $row['active'] === 'true');
        }

        echo json_encode(['success' => true, 'totalCount' => count($data), 'data' => $data]);
        break;

    // ------------------------------------------------------------
    // 2. READ BIN DETAIL (Detail Stok Barang Realtime di Bin)
    // ------------------------------------------------------------
    case 'read_bin_detail':
        $binId = intval($_POST['bin_id'] ?? 0);
        if ($binId <= 0) {
            echo json_encode(['success' => false, 'message' => 'Bin ID tidak valid.', 'data' => []]);
            exit;
        }

        $sql = "
            SELECT 
                sm.bin_location_id,
                i.init as item_code,
                i.name as item_name,
                COALESCE(g.name, '-') AS grade_name,
                SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.qty ELSE -sm.qty END) AS qty
            FROM stock_movements sm
            JOIN items i ON i.id = sm.item_id
            LEFT JOIN item_grade g ON g.id = sm.grade_id
            WHERE sm.bin_location_id::int = :bin_id::int
            GROUP BY sm.bin_location_id, i.init, i.name, g.name
            HAVING SUM(CASE WHEN sm.movement_type = 'IN' THEN sm.qty ELSE -sm.qty END) > 0
            ORDER BY i.init ASC
        ";

        $stmt = $db->prepare($sql);
        $stmt->execute(['bin_id' => $binId]);
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'data' => $data]);
        break;

    // ------------------------------------------------------------
    // 3. READ COMBO: Gudang
    // ------------------------------------------------------------
    case 'read_warehouse':
        $sql = "SELECT id, name FROM warehouses WHERE active = true ORDER BY name ASC";
        $stmt = $db->query($sql);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ------------------------------------------------------------
    // 4. READ COMBO: Bin Location Cascading Filter per Gudang
    // ------------------------------------------------------------
    case 'read_bin_by_warehouse':
        $warehouseId = intval($_POST['warehouse_id'] ?? $_GET['warehouse_id'] ?? 0);
        if ($warehouseId <= 0) {
            echo json_encode(['success' => true, 'data' => []]);
            exit;
        }

        $stmt = $db->prepare("
            SELECT id, code, name, zone, rack, level
            FROM bin_locations
            WHERE warehouse_id = :warehouse_id AND active = true
            ORDER BY code ASC
        ");
        $stmt->execute(['warehouse_id' => $warehouseId]);
        echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
        break;

    // ------------------------------------------------------------
    // 5. CREATE
    // ------------------------------------------------------------
    case 'create':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 2) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        SaveBinLocation($db, null, $_POST);
        break;

    // ------------------------------------------------------------
    // 6. UPDATE
    // ------------------------------------------------------------
    case 'update':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 3) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
            exit;
        }
        SaveBinLocation($db, $id, $_POST);
        break;

    // ------------------------------------------------------------
    // 7. DELETE
    // ------------------------------------------------------------
    case 'delete':
        $access = intval($_SESSION['FormAccess'] ?? 0);
        if ($access < 4) {
            echo json_encode(['success' => false, 'message' => 'Akses ditolak.']);
            exit;
        }
        $id = intval($_POST['id'] ?? 0);
        if ($id <= 0) {
            echo json_encode(['success' => false, 'message' => 'ID tidak valid.']);
            exit;
        }

        $stmtChk = $db->prepare("SELECT COUNT(*) FROM stock_movements WHERE bin_location_id = :id");
        $stmtChk->execute(['id' => $id]);
        if (intval($stmtChk->fetchColumn()) > 0) {
            echo json_encode(['success' => false, 'message' => 'Bin Location ini sudah dipakai di transaksi stok. Silakan nonaktifkan saja.']);
            exit;
        }

        $db->query("DELETE FROM bin_locations WHERE id = " . intval($id));
        echo json_encode(['success' => true, 'message' => 'Bin Location berhasil dihapus.']);
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
}

function SaveBinLocation($db, $id, $post) {
    $warehouseId = intval($post['warehouse_id'] ?? 0);
    $code        = trim($post['code'] ?? '');
    $name        = trim($post['name'] ?? '');
    $zone        = trim($post['zone'] ?? '');
    $rack        = trim($post['rack'] ?? '');
    $level       = trim($post['level'] ?? '');
    $active      = isset($post['active']) && ($post['active'] === '1' || $post['active'] === 'true' || $post['active'] === true);

    if ($warehouseId <= 0) {
        echo json_encode(['success' => false, 'message' => 'Gudang wajib dipilih.']);
        return;
    }
    if ($code === '') {
        echo json_encode(['success' => false, 'message' => 'Kode Bin Location wajib diisi.']);
        return;
    }

    $sqlDup = "SELECT id FROM bin_locations WHERE warehouse_id = :warehouse_id AND code = :code";
    $paramsDup = ['warehouse_id' => $warehouseId, 'code' => $code];
    if ($id) {
        $sqlDup .= " AND id != :id";
        $paramsDup['id'] = $id;
    }
    $stmtDup = $db->prepare($sqlDup);
    $stmtDup->execute($paramsDup);
    if ($stmtDup->fetch()) {
        echo json_encode(['success' => false, 'message' => "Kode '{$code}' sudah ada di gudang ini."]);
        return;
    }

    $data = [
        'warehouse_id' => $warehouseId,
        'code'         => $code,
        'name'         => $name !== '' ? $name : null,
        'zone'         => $zone !== '' ? $zone : null,
        'rack'         => $rack !== '' ? $rack : null,
        'level'        => $level !== '' ? $level : null,
        'active'       => $active ? 'true' : 'false'
    ];

    if ($id === null) {
        DbOrFail($db, $db->insert('bin_locations', $data), 'insert bin_locations');
        echo json_encode(['success' => true, 'message' => "Bin Location '{$code}' berhasil ditambahkan."]);
    } else {
        DbOrFail($db, $db->update('bin_locations', $data, "id = {$id}"), 'update bin_locations');
        echo json_encode(['success' => true, 'message' => "Bin Location '{$code}' berhasil diperbarui."]);
    }
}
exit;