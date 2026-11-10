<?php
/**
 * m.materialsCRD.php
 * Backend Controller CRUD Master Material (materials) PostgreSQL
 */

require_once __DIR__ . '/../php-func/function.all.php';
require_once __DIR__ . '/../php-class/crudClass.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
header('Content-Type: application/json');

$task = $_REQUEST['task'] ?? '';

// Otorisasi backend mandiri: login wajib dan hak operasi mengikuti FormAccess menu.
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Sesi berakhir, silakan login ulang.']);
    exit;
}
$requiredAccess = 1;
if (in_array($task, ['create', 'add', 'save'], true)) $requiredAccess = 2;
if (in_array($task, ['update', 'edit'], true)) $requiredAccess = 3;
if (in_array($task, ['delete'], true)) $requiredAccess = 4;
if ((int)($_SESSION['FormAccess'] ?? 0) < $requiredAccess) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Hak akses tidak mencukupi untuk operasi ini.']);
    exit;
}

// Helper error handling PostgreSQL
function PgOrFail($result, $context = '') {
    if ($result === false) {
        throw new Exception(($context ? "[{$context}] " : '') . pg_last_error());
    }
    return $result;
}

try {
    switch ($task) {

        // ==========================================================
        // 1. LIST -- Load Data Grid
        // ==========================================================
        case 'list':
            $search = trim($_REQUEST['query'] ?? '');
            $where  = '1=1';
            $params = [];

            if ($search !== '') {
                $where .= " AND (material_code ILIKE $1 OR material_name ILIKE $1)";
                $params[] = '%' . $search . '%';
            }

            $sql = "SELECT id, material_code, material_name, unit, estimated_price, active, created_at
                    FROM materials
                    WHERE {$where}
                    ORDER BY material_name ASC";
                    
            $result = PgOrFail(pg_query_params($sql, $params), 'list');
            $rows = pg_fetch_all($result) ?: [];

            foreach ($rows as &$r) {
                $r['active'] = ($r['active'] === 't' || $r['active'] === 'true' || $r['active'] === true);
            }

            echo json_encode(['success' => true, 'data' => $rows]);
            break;

        // ==========================================================
        // 2. READ EDIT -- Load Single Record
        // ==========================================================
        case 'read_edit':
            $id = (int)($_REQUEST['id'] ?? 0);
            $result = PgOrFail(pg_query_params(
                "SELECT id, material_code, material_name, unit, estimated_price, active
                 FROM materials WHERE id = $1",
                [$id]
            ), 'read_edit');
            
            $row = pg_fetch_assoc($result);
            if (!$row) throw new Exception('Data material tidak ditemukan');

            $row['active'] = ($row['active'] === 't' || $row['active'] === 'true' || $row['active'] === true);

            echo json_encode(['success' => true, 'data' => $row]);
            break;

        // ==========================================================
        // 3. CREATE -- Insert Material Baru
        // ==========================================================
        case 'create':
            $code  = trim($_REQUEST['material_code'] ?? '');
            $name  = trim($_REQUEST['material_name'] ?? '');
            $unit  = trim($_REQUEST['unit'] ?? '');
            $price = (float)($_REQUEST['estimated_price'] ?? 0);

            // Parsing status active
            $rawActive = $_REQUEST['is_active'] ?? 'false';
            $active = ($rawActive === 'true' || $rawActive === 't' || $rawActive === '1' || $rawActive === true || $rawActive === 'on') ? 't' : 'f';

            if ($code === '') throw new Exception('Kode Material wajib diisi');
            if ($name === '') throw new Exception('Nama Material wajib diisi');
            if ($unit === '') throw new Exception('Satuan wajib diisi');

            pg_query('BEGIN');
            try {
                $result = PgOrFail(pg_query_params(
                    "INSERT INTO materials (material_code, material_name, unit, estimated_price, active)
                     VALUES ($1, $2, $3, $4, $5) RETURNING id",
                    [$code, $name, $unit, $price, $active]
                ), 'insert materials');
                
                $newId = pg_fetch_result($result, 0, 'id');
                pg_query('COMMIT');
            } catch (Exception $e) {
                @pg_query('ROLLBACK');
                if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), 'unique') !== false) {
                    throw new Exception("Kode Material '{$code}' sudah dipakai. Gunakan kode lain.");
                }
                throw $e;
            }

            echo json_encode(['success' => true, 'id' => $newId, 'material_code' => $code]);
            break;

        // ==========================================================
        // 4. UPDATE -- Perbarui Data Material
        // ==========================================================
        case 'update':
            $id    = (int)($_REQUEST['id'] ?? 0);
            $code  = trim($_REQUEST['material_code'] ?? '');
            $name  = trim($_REQUEST['material_name'] ?? '');
            $unit  = trim($_REQUEST['unit'] ?? '');
            $price = (float)($_REQUEST['estimated_price'] ?? 0);

            // Parsing status active
            $rawActive = $_REQUEST['is_active'] ?? 'false';
            $active = ($rawActive === 'true' || $rawActive === 't' || $rawActive === '1' || $rawActive === true || $rawActive === 'on') ? 't' : 'f';

            if ($id <= 0) throw new Exception('ID Material tidak valid');
            if ($code === '') throw new Exception('Kode Material wajib diisi');
            if ($name === '') throw new Exception('Nama Material wajib diisi');
            if ($unit === '') throw new Exception('Satuan wajib diisi');

            pg_query('BEGIN');
            try {
                PgOrFail(pg_query_params(
                    "UPDATE materials
                     SET material_code = $1, material_name = $2, unit = $3, estimated_price = $4, active = $5
                     WHERE id = $6",
                    [$code, $name, $unit, $price, $active, $id]
                ), 'update materials');
                
                pg_query('COMMIT');
            } catch (Exception $e) {
                @pg_query('ROLLBACK');
                if (stripos($e->getMessage(), 'duplicate') !== false || stripos($e->getMessage(), 'unique') !== false) {
                    throw new Exception("Kode Material '{$code}' sudah dipakai oleh material lain.");
                }
                throw $e;
            }

            echo json_encode(['success' => true, 'id' => $id]);
            break;

        // ==========================================================
        // 5. DELETE -- Soft Delete
        // ==========================================================
        case 'delete':
            $id = (int)($_REQUEST['id'] ?? 0);
            if ($id <= 0) throw new Exception('ID Material tidak valid');

            PgOrFail(pg_query_params(
                "UPDATE materials SET active = 'f' WHERE id = $1",
                [$id]
            ), 'soft delete materials');

            echo json_encode(['success' => true]);
            break;

        default:
            throw new Exception('Task tidak dikenali: ' . htmlspecialchars($task));
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}