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

$task = $_POST['task'] ?? $_GET['task'] ?? '';

if (in_array($task, ['save_role', 'update_role', 'delete_role', 'clone_role', 'save_role_matrix', 'sync_role_to_users'], true)
    && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
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
$offset      = isset($_POST["start"]) ? intval($_POST["start"]) : 0;
$limit       = isset($_POST["limit"]) ? intval($_POST["limit"]) : 250;

$db = new crud();
global $conn;

try {
    switch ($task) {

        // ==========================================
        // 1. READ ROLES (Daftar Role Dinamis + Statistik User & Form)
        // ==========================================
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

            if (!empty($params)) {
                $res = pg_query_params($conn, $sql, $params);
            } else {
                $res = pg_query($conn, $sql);
            }

            if (!$res) {
                throw new Exception(pg_last_error($conn));
            }

            $rows = array();
            while ($row = pg_fetch_assoc($res)) {
                $row['id'] = intval($row['id']);
                $row['user_count'] = intval($row['user_count']);
                $row['form_count'] = intval($row['form_count']);
                $row['is_active'] = ($row['is_active'] === 't' || $row['is_active'] === true || $row['is_active'] === '1');
                $rows[] = $row;
            }

            echo json_encode([
                "success" => true,
                "totalRecord" => count($rows),
                "data" => $rows
            ]);
            break;

        // ==========================================
        // 2. SAVE ROLE (Tambah Role Baru)
        // ==========================================
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
                echo json_encode(["success" => false, "message" => "Kode Role '{$roleCode}' sudah terdaftar. Gunakan kode lain."]);
                exit;
            }

            $ins = pg_query_params(
                $conn,
                "INSERT INTO m_role (role_code, role_name, description, is_active, created_at, updated_at) VALUES ($1, $2, $3, $4, NOW(), NOW()) RETURNING id",
                [$roleCode, $roleName, $description, $isActive]
            );
            if (!$ins) {
                throw new Exception(pg_last_error($conn));
            }
            $newRoleRow = pg_fetch_assoc($ins);
            $newRoleId = intval($newRoleRow['id']);

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Role baru berhasil disimpan.",
                "id" => $newRoleId
            ]);
            break;

        // ==========================================
        // 3. UPDATE ROLE (Ubah Data Role)
        // ==========================================
        case 'update_role':
        case 'update':
            $roleId = intval($_POST['id'] ?? 0);
            $roleCode = trim($_POST['role_code'] ?? '');
            $roleName = trim($_POST['role_name'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $isActive = ($_POST['is_active'] === 'true' || $_POST['is_active'] === true || $_POST['is_active'] === '1') ? 'true' : 'false';

            if ($roleId <= 0 || empty($roleCode) || empty($roleName)) {
                echo json_encode(["success" => false, "message" => "ID Role, Kode Role, dan Nama Role tidak valid!"]);
                exit;
            }

            pg_query($conn, "BEGIN");

            $chk = pg_query_params($conn, "SELECT id FROM m_role WHERE role_code = $1 AND id <> $2", [$roleCode, $roleId]);
            if ($chk && pg_num_rows($chk) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode(["success" => false, "message" => "Kode Role '{$roleCode}' sudah digunakan oleh role lain."]);
                exit;
            }

            $upd = pg_query_params(
                $conn,
                "UPDATE m_role SET role_code = $1, role_name = $2, description = $3, is_active = $4, updated_at = NOW() WHERE id = $5",
                [$roleCode, $roleName, $description, $isActive, $roleId]
            );
            if (!$upd) {
                throw new Exception(pg_last_error($conn));
            }

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Data role berhasil diperbarui."
            ]);
            break;

        // ==========================================
        // 4. DELETE ROLE (Hapus Role dengan Proteksi User Aktif)
        // ==========================================
        case 'delete_role':
        case 'delete':
            $roleId = intval($_POST['id'] ?? 0);
            if ($roleId <= 0) {
                echo json_encode(["success" => false, "message" => "ID Role tidak valid untuk dihapus."]);
                exit;
            }

            pg_query($conn, "BEGIN");

            // Check if any active user uses this role
            $userCheck = pg_query_params($conn, "SELECT COUNT(*) AS cnt FROM m_user_system WHERE role_id = $1", [$roleId]);
            $uRow = pg_fetch_assoc($userCheck);
            if ($uRow && intval($uRow['cnt']) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode([
                    "success" => false,
                    "message" => "Gagal Hapus: Role ini masih dipakai oleh " . $uRow['cnt'] . " akun user system. Harap ubah/lepaskan role user tersebut terlebih dahulu atau nonaktifkan role ini."
                ]);
                exit;
            }

            // Cascade delete role access menu is handled by foreign key ON DELETE CASCADE, but let's be explicit
            pg_query_params($conn, "DELETE FROM m_role_access_menu WHERE role_id = $1", [$roleId]);
            $del = pg_query_params($conn, "DELETE FROM m_role WHERE id = $1", [$roleId]);
            if (!$del) {
                throw new Exception(pg_last_error($conn));
            }

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Role berhasil dihapus dari sistem."
            ]);
            break;

        // ==========================================
        // 5. CLONE ROLE (Duplikasi Role & Matrix Aksesnya)
        // ==========================================
        case 'clone_role':
            $sourceRoleId = intval($_POST['source_role_id'] ?? 0);
            $newCode = trim($_POST['new_role_code'] ?? '');
            $newName = trim($_POST['new_role_name'] ?? '');

            if ($sourceRoleId <= 0 || empty($newCode) || empty($newName)) {
                echo json_encode(["success" => false, "message" => "Source Role ID, Kode Role Baru, dan Nama Role Baru wajib diisi."]);
                exit;
            }

            pg_query($conn, "BEGIN");

            $chk = pg_query_params($conn, "SELECT id FROM m_role WHERE role_code = $1", [$newCode]);
            if ($chk && pg_num_rows($chk) > 0) {
                pg_query($conn, "ROLLBACK");
                echo json_encode(["success" => false, "message" => "Kode Role baru '{$newCode}' sudah digunakan."]);
                exit;
            }

            // Get source role info
            $srcRes = pg_query_params($conn, "SELECT description FROM m_role WHERE id = $1", [$sourceRoleId]);
            $srcRow = pg_fetch_assoc($srcRes);
            $desc = $srcRow ? ("Cloned from role ID " . $sourceRoleId . ". " . $srcRow['description']) : '';

            $ins = pg_query_params(
                $conn,
                "INSERT INTO m_role (role_code, role_name, description, is_active, created_at, updated_at) VALUES ($1, $2, $3, true, NOW(), NOW()) RETURNING id",
                [$newCode, $newName, $desc]
            );
            if (!$ins) {
                throw new Exception(pg_last_error($conn));
            }
            $newRoleRow = pg_fetch_assoc($ins);
            $newRoleId = intval($newRoleRow['id']);

            // Copy access matrix
            pg_query_params(
                $conn,
                "INSERT INTO m_role_access_menu (role_id, form_id, access_level) SELECT $1, form_id, access_level FROM m_role_access_menu WHERE role_id = $2",
                [$newRoleId, $sourceRoleId]
            );

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Role berhasil diduplikasi beserta matriks aksesnya.",
                "id" => $newRoleId
            ]);
            break;

        // ==========================================
        // 6. READ ROLE MATRIX (Muat Seluruh Form & Level Akses untuk Role Terpilih)
        // ==========================================
        case 'read_role_matrix':
            $roleId = intval($_POST['role_id'] ?? $_GET['role_id'] ?? 0);

            $sql = "
                SELECT
                    f.id AS form_id,
                    COALESCE(g.id, 0) AS group_id,
                    COALESCE(g.name, 'Uncategorized') AS group_name,
                    COALESCE(g.seq, '0000') AS group_seq,
                    f.seq AS form_seq,
                    f.title AS form_name,
                    f.php_url,
                    COALESCE(f.remark, '') AS remark,
                    COALESCE(ram.access_level, 0) AS access_level,
                    COALESCE(ram.id, 0) AS access_id
                FROM m_form_name f
                LEFT JOIN m_form_group g ON g.id = f.group_id
                LEFT JOIN m_role_access_menu ram ON ram.form_id = f.id AND ram.role_id = $1
                WHERE f.active = true
                ORDER BY g.seq ASC, f.seq ASC
            ";

            $res = pg_query_params($conn, $sql, [$roleId]);
            if (!$res) {
                throw new Exception(pg_last_error($conn));
            }

            $rows = array();
            while ($row = pg_fetch_assoc($res)) {
                $row['form_id'] = intval($row['form_id']);
                $row['group_id'] = intval($row['group_id']);
                $row['access_level'] = intval($row['access_level']);
                $row['access_id'] = intval($row['access_id']);
                $row['active'] = ($row['access_level'] > 0);
                $rows[] = $row;
            }

            echo json_encode([
                "success" => true,
                "totalRecord" => count($rows),
                "data" => $rows
            ]);
            break;

        // ==========================================
        // 7. SAVE ROLE MATRIX (Simpan Perubahan Matriks Akses Massal)
        // ==========================================
        case 'save_role_matrix':
            $roleId = intval($_POST['role_id'] ?? 0);
            $matrixPayload = $_POST['matrix_data'] ?? $_POST['arrForm'] ?? null;

            if ($roleId <= 0) {
                echo json_encode(["success" => false, "message" => "ID Role tidak valid untuk penyimpanan matriks."]);
                exit;
            }

            if (empty($matrixPayload)) {
                echo json_encode(["success" => false, "message" => "Data matriks akses kosong."]);
                exit;
            }

            $cleanJson = stripslashes($matrixPayload);
            $matrixData = json_decode($cleanJson, true);

            if (!is_array($matrixData)) {
                echo json_encode(["success" => false, "message" => "Format payload JSON matriks tidak valid."]);
                exit;
            }

            pg_query($conn, "BEGIN");

            foreach ($matrixData as $row) {
                $formId = intval($row['form_id'] ?? 0);
                $accessLevel = intval($row['access_level'] ?? 0);

                if ($accessLevel < 0 || $accessLevel > 4) {
                    $accessLevel = 0;
                }

                if ($formId > 0) {
                    if ($accessLevel > 0) {
                        // Upsert
                        $upd = pg_query_params(
                            $conn,
                            "INSERT INTO m_role_access_menu (role_id, form_id, access_level) VALUES ($1, $2, $3)
                             ON CONFLICT (role_id, form_id) DO UPDATE SET access_level = $3",
                            [$roleId, $formId, $accessLevel]
                        );
                        if (!$upd) throw new Exception(pg_last_error($conn));
                    } else {
                        // Delete if level is 0 / unchecked
                        $del = pg_query_params(
                            $conn,
                            "DELETE FROM m_role_access_menu WHERE role_id = $1 AND form_id = $2",
                            [$roleId, $formId]
                        );
                        if (!$del) throw new Exception(pg_last_error($conn));
                    }
                }
            }

            // Update role timestamp
            pg_query_params($conn, "UPDATE m_role SET updated_at = NOW() WHERE id = $1", [$roleId]);

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Matriks akses role berhasil disimpan."
            ]);
            break;

        // ==========================================
        // 8. SYNC ROLE TO USERS (Salin Matriks Role ke m_user_access_menu seluruh user dengan role tersebut)
        // ==========================================
        case 'sync_role_to_users':
            $roleId = intval($_POST['role_id'] ?? 0);
            if ($roleId <= 0) {
                echo json_encode(["success" => false, "message" => "ID Role tidak valid untuk sinkronisasi."]);
                exit;
            }

            pg_query($conn, "BEGIN");

            // Find all users assigned to this role
            $usersRes = pg_query_params($conn, "SELECT id FROM m_user_system WHERE role_id = $1 AND active = true", [$roleId]);
            if (!$usersRes) {
                throw new Exception(pg_last_error($conn));
            }

            $userCount = 0;
            while ($u = pg_fetch_assoc($usersRes)) {
                $userId = intval($u['id']);
                $userCount++;

                // Clear existing user access menu for this user and replace with role access menu
                pg_query_params($conn, "DELETE FROM m_user_access_menu WHERE user_id = $1", [$userId]);
                pg_query_params(
                    $conn,
                    "INSERT INTO m_user_access_menu (user_id, form_id, access_level)
                     SELECT $1, form_id, access_level FROM m_role_access_menu WHERE role_id = $2 AND access_level > 0",
                    [$userId, $roleId]
                );
            }

            pg_query($conn, "COMMIT");

            echo json_encode([
                "success" => true,
                "message" => "Sinkronisasi berhasil! Matriks akses role telah diterapkan ke {$userCount} akun user aktif."
            ]);
            break;

        default:
            echo json_encode([
                "success" => false,
                "message" => "Task '{$task}' tidak dikenali di roleCRD.php."
            ]);
            break;
    }
} catch (Throwable $e) {
    if (pg_connection_status($conn) === PGSQL_CONNECTION_OK) {
        @pg_query($conn, "ROLLBACK");
    }
    error_log("Error in roleCRD.php [task: {$task}]: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        "success" => false,
        "message" => "Database error: " . $e->getMessage()
    ]);
}
?>
