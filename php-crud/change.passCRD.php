<?php
/*
Document : php-crud/change.passCRD.php
Description : Change Password Handler - Fixed
*/
if (session_status() === PHP_SESSION_NONE) { session_start(); }
include_once "../php-class/crudClass.php";
include_once "../php-func/function.all.php";

// Validasi session
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Session expired.']);
    exit;
}

// Endpoint ubah password hanya boleh menyentuh akun milik sesi aktif.
$userId = (int)$_SESSION['user_id'];
$task = $_POST['task'] ?? '';

switch ($task) {
    case 'read_edit':
        try {
            $db = new crud();
            $field = "
                s.id,s.login_name,s.employee_id,s.active,'' as password,'' as confirm_password,e.employee_name,e.gender,e.from_date,
				d.department_name,d.status_name
            ";
            $table = "m_user_system s
                LEFT JOIN m_employee e ON e.id = s.employee_id
                LEFT JOIN view_employee_depart_status d ON COALESCE(d.id, 0) = COALESCE(e.id, 0)";
            $where = "s.id = " . $userId;
            
            $db->select($table, $field, $where);
            echo $db->getResult();
        } catch (Exception $e) {
            error_log("[ChangePass] read_edit error: " . $e->getMessage());
            echo json_encode(['success' => false, 'message' => 'Gagal memuat data.']);
        }
        break;

    case 'update':
        try {
            $db = new crud();
            
            $newPassword = trim($_POST['password'] ?? '');
            $confirmPassword = trim($_POST['confirm_password'] ?? '');
            
            // Validasi
            if (empty($newPassword) || empty($confirmPassword)) {
                echo json_encode(['success' => false, 'message' => 'Password wajib diisi.']);
                exit;
            }
            
            if ($newPassword !== $confirmPassword) {
                echo json_encode(['success' => false, 'message' => 'Password tidak cocok.']);
                exit;
            }
            
            if (strlen($newPassword) < 6) {
                echo json_encode(['success' => false, 'message' => 'Password minimal 6 karakter.']);
                exit;
            }
            
            if (strlen($newPassword) > 50) {
                echo json_encode(['success' => false, 'message' => 'Password maksimal 50 karakter.']);
                exit;
            }
            
            // [FIX] Hash dengan bcrypt (lebih aman dari BASE64)
            $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT, ['cost' => 12]);
            
            // [FIX] Update by user_id, bukan employee_id
            $updateStmt = $db->prepare("
                UPDATE m_user_system 
                SET password = :password
                WHERE id = :user_id
            ");
            
            $updateStmt->execute([
                ':password' => $hashedPassword,
                ':user_id' => $userId
            ]);
            
            if ($updateStmt->rowCount() === 0) {
                throw new Exception('User tidak ditemukan.');
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'Password berhasil diubah. Silakan login ulang.'
            ]);
            
        } catch (Exception $e) {
            error_log("[ChangePass] update error: " . $e->getMessage());
            echo json_encode([
                'success' => false,
                'message' => 'Gagal mengubah password: ' . $e->getMessage()
            ]);
        }
        break;

    default:
        echo json_encode(['success' => false, 'message' => 'Task tidak dikenali.']);
        break;
}
?>