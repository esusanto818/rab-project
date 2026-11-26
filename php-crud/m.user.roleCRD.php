<?php
/**
 * Backend CRUD Endpoint for Dynamic User Roles & Access Matrix
 * Endpoint: php-crud/roleCRD.php
 * Supports: read_roles, save_role, update_role, delete_role, clone_role,
 *           read_role_matrix, save_role_matrix, sync_role_to_users
 */
require_once __DIR__ . '/../php-func/user.valid.php';
set_time_limit(0);
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set('Asia/Jakarta');

include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

$task = $_POST['task'] ?? $_REQUEST['task'] ?? '';

if (in_array($task, ['save_role', 'update_role', 'delete_role', 'clone_role', 'save_role_matrix', 'sync_role_to_users'], true)
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(["success" => false, "status" => "Error", "message" => "Operasi perubahan data wajib menggunakan POST."]);
    exit;
}

if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) {
    http_response_code(401);
    echo json_encode(["success" => false, "status" => "Error", "message" => "Sesi berakhir. Silakan login kembali."]);
    exit;
}

$userAccess = (int)($_SESSION['FormAccess'] ?? 0);
$requiredAccess = 1;
if (in_array($task, ['save_role', 'clone_role', 'save_role_matrix', 'sync_role_to_users'], true)) {
    $requiredAccess = 3;
}
if ($task === 'update_role') {
    $requiredAccess = 3;
}
if ($task === 'delete_role') {
    $requiredAccess = 4;
}

if ($userAccess < $requiredAccess) {
    http_response_code(403);
    echo json_encode(["success" => false, "status" => "Error", "message" => "Hak akses tidak mencukupi untuk operasi ini (Access level: {$userAccess}, Required: {$requiredAccess})."]);
    exit;
}

$searchField = $_POST["query"] ?? $_REQUEST["query"] ?? '';
$db = new crud();
global $conn;

try {
    switch ($task) {
        case 'read_roles':
        case 'read':
            $searchSQL = "";
            $params = array();
            if (!empty($searchField)) {
                $params[] = '%' . $searchField . '%';
                $searchSQL = " AND (r.role_code ILIKE $1 OR r.role_name ILIKE $1 OR r.description ILIKE $1)";
            }

            $sql = "
                SELECT
                    r.id,
                    r.role_code,
                    r.role_name,
                    r.description,
                    r.is_active,
                    r.created_at,
                    r.updated_at,
                    COALESCE(u.user_count, 0) AS user_count,
                    COALESCE(m.form_count, 0) AS form_count
                FROM m_role r
                LEFT JOIN (
                    SELECT role_id, COUNT(*) AS user_count
                    FROM m_user_system
                    GROUP BY role_id
                ) u ON u.role_id = r.id
                LEFT JOIN (
                    SELECT role_id, COUNT(*) AS form_count
                    FROM m_role_access_menu
                    WHERE access_level > 0
                    GROUP BY role_id
                ) m ON m.role_id = r.id
                WHERE 1=1 {$searchSQL}
                ORDER BY r.id ASC
            ";

            $res = !empty($params) ? pg_query_params($conn, $sql, $params) : pg_query($conn, $sql);
            if (!$res) throw new Exception(pg_last_error($conn));

            $rows = array();
            while ($row = pg_fetch_assoc($res)) {
                $row['id'] = intval($row['id']);
                $row['user_count'] = intval($row['user_count']);
                $row['form_count'] = intval($row['form_count']);
                $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true || $row['is_active'] === '1');
                $rows[] = $row;
            }

            echo json_encode(["success" => true, "totalRecord" => count($rows), "data" => $rows]);
            break;

        case 'save_role':
        case 'create':
            $roleCode = trim($_POST['role_code'] ?? '');
            $roleName = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $isActive = ($_POST['is_active'] === 'true' || $_POST['is_active'] === true || $_POST['is_active'] === '1') ? 'true' : 'false';

            if (empty($roleCode) || empty($roleName)) {
                echo json_encode(["success" => false, "message" => "Kode Role dan Nama Role wajib diisi!"]);
                exit;
            }

            pg_query($conn, "BEGIN");
            $chk = pg_query_params($conn, "SELECT id FROM m_role WHERE role_code = $1", [$roleCode]);
            if ($chk && pg_num_rows($chk) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode(["success" => false, "message" => "Kode Role '{$roleCode}' sudah terdaftar."]);
                exit;
            }

            $ins = pg_query_params($conn, "INSERT INTO m_role (role_code, role_name, description, is_active, created_at, updated_at) VALUES ($1, $2, $3, $4, NOW(), NOW()) RETURNING id", [$roleCode, $roleName, $description, $isActive]);
            if (!$ins) throw new Exception(pg_last_error($conn));
            $newRoleId = intval(pg_fetch_result($ins, 0, 'id'));
            pg_query($conn, "COMMIT");
            echo json_encode(["success" => true, "message" => "Role baru berhasil disimpan.", "id" => $newRoleId]);
            break;

        case 'update_role':
        case 'update':
            $roleId = intval($_POST['id'] ?? 0);
            $roleCode = trim($_POST['role_code'] ?? '');
            $roleName = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $isActive = ($_POST['is_active'] === 'true' || $_POST['is_active'] === true || $_POST['is_active'] === '1') ? 'true' : 'false';

            if ($roleId <= 0 || empty($roleCode) || empty($roleName)) {
                echo json_encode(["success" => false, "message" => "Data role tidak valid."]);
                exit;
            }
            pg_query($conn, "BEGIN");
            $chk = pg_query_params($conn, "SELECT id FROM m_role WHERE role_code = $1 AND id <> $2", [$roleCode, $roleId]);
            if ($chk && pg_num_rows($chk) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode(["success" => false, "message" => "Kode Role sudah digunakan."]);
                exit;
            }
            $upd = pg_query_params($conn, "UPDATE m_role SET role_code = $1, role_name = $2, description = $3, is_active = $4, updated_at = NOW() WHERE id = $5", [$roleCode, $roleName, $description, $isActive, $roleId]);
            if (!$upd) throw new Exception(pg_last_error($conn));
            pg_query($conn, "COMMIT");
            echo json_encode(["success" => true, "message" => "Data role berhasil diperbarui."]);
            break;

        case 'delete_role':
        case 'delete':
            $roleId = intval($_POST['id'] ?? 0);
            if ($roleId <= 0) {
                echo json_encode(["success" => false, "message" => "ID tidak valid."]);
                exit;
            }
            pg_query($conn, "BEGIN");
            $userCheck = pg_query_params($conn, "SELECT COUNT(*) AS cnt FROM m_user_system WHERE role_id = $1", [$roleId]);
            $uRow = pg_fetch_assoc($userCheck);
            if ($uRow && intval($uRow['cnt']) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode(["success" => false, "message" => "Role masih dipakai oleh " . $uRow['cnt'] . " user."]);
                exit;
            }
            pg_query_params($conn, "DELETE FROM m_role_access_menu WHERE role_id = $1", [$roleId]);
            pg_query_params($conn, "DELETE FROM m_role WHERE id = $1", [$roleId]);
            pg_query($conn, "COMMIT");
            echo json_encode(["success" => true, "message" => "Role berhasil dihapus."]);
            break;

        case 'sync_role_to_users':
            $roleId = intval($_POST['role_id'] ?? 0);
            if ($roleId <= 0) { echo json_encode(["success" => false, "message" => "ID Role tidak valid."]); exit; }
            pg_query($conn, "BEGIN");
            $usersRes = pg_query_params($conn, "SELECT id FROM m_user_system WHERE role_id = $1 AND active = true", [$roleId]);
            while ($u = pg_fetch_assoc($usersRes)) {
                $userId = intval($u['id']);
                // AMAN: Hanya menghapus akses role tersebut untuk user ini, lalu sinkronisasi kembali
                pg_query_params($conn, "DELETE FROM m_user_access_menu WHERE user_id = $1", [$userId]);
                pg_query_params($conn, "INSERT INTO m_user_access_menu (user_id, form_id, access_level) SELECT $1, form_id, access_level FROM m_role_access_menu WHERE role_id = $2 AND access_level > 0", [$userId, $roleId]);
            }
            pg_query($conn, "COMMIT");
            echo json_encode(["success" => true, "message" => "Sinkronisasi berhasil."]);
            break;

        case 'read_role_matrix':
            $roleId = intval($_POST['role_id'] ?? $_GET['role_id'] ?? 0);
            $res = pg_query_params($conn, "SELECT f.id AS form_id, COALESCE(g.id, 0) AS group_id, COALESCE(g.name, 'Uncategorized') AS group_name, f.title AS form_name, COALESCE(ram.access_level, 0) AS access_level FROM m_form_name f LEFT JOIN m_form_group g ON g.id = f.group_id LEFT JOIN m_role_access_menu ram ON ram.form_id = f.id AND ram.role_id = $1 WHERE f.active = true ORDER BY g.seq ASC, f.seq ASC", [$roleId]);
            $rows = [];
            while ($row = pg_fetch_assoc($res)) { $row['form_id'] = intval($row['form_id']); $rows[] = $row; }
            echo json_encode(["success" => true, "data" => $rows]);
            break;

        case 'save_role_matrix':
            $roleId = intval($_POST['role_id'] ?? 0);
            $matrixPayload = json_decode(stripslashes($_POST['matrix_data'] ?? '[]'), true);
            if ($roleId <= 0 || !is_array($matrixPayload)) { echo json_encode(["success" => false, "message" => "Data tidak valid."]); exit; }
            pg_query($conn, "BEGIN");
            foreach ($matrixPayload as $row) {
                $formId = intval($row['form_id'] ?? 0);
                $lvl = intval($row['access_level'] ?? 0);
                if ($formId <= 0) continue;
                if ($lvl > 0) pg_query_params($conn, "INSERT INTO m_role_access_menu (role_id, form_id, access_level) VALUES ($1, $2, $3) ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = $3", [$roleId, $formId, $lvl]);
                else pg_query_params($conn, "DELETE FROM m_role_access_menu WHERE role_id = $1 AND form_id = $2", [$roleId, $formId]);
            }
            pg_query_params($conn, "UPDATE m_role SET updated_at = NOW() WHERE id = $1", [$roleId]);
            pg_query($conn, "COMMIT");
            echo json_encode(["success" => true, "message" => "Matriks akses tersimpan."]);
            break;

        default:
            echo json_encode(["success" => false, "message" => "Task tidak dikenal."]);
            break;
    }
} catch (Throwable $e) {
    if (pg_connection_status($conn) === PGSQL_CONNECTION_OK) @pg_query($conn, "ROLLBACK");
    http_response_code(500);
    echo json_encode(["success" => false, "message" => "Server error: " . $e->getMessage()]);
}
