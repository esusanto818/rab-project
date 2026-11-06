<?php
ini_set('session.gc_maxlifetime', 86400);
session_set_cookie_params(86400);
if (!isset($_SESSION)) {
    session_start();
}

require_once __DIR__ . '/../php-class/crudClass.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $loginUsername = trim($_POST["loginUsername"] ?? "");
    $loginPassword = $_POST["loginPassword"] ?? "";
    $loginMobile   = isset($_POST["m"]) ? intval($_POST["m"]) : 0;
    $terminalId    = $_SERVER['REMOTE_ADDR'];

    if (empty($loginUsername) || empty($loginPassword)) {
        $_SESSION['login_error'] = "Username dan Password wajib diisi!";
        header("Location: ../index.php");
        exit;
    }

    try {
        $db = new crud();

        $sql = "SELECT u.id,login_name as username, password, login_name as user_fullname, department_id, employee_name as department,
	                 department_init, section_id, location_id, location_init,
                   active as is_active, login_terminal, login_status
                FROM m_user_system u
                left join m_user_login_status s on u.id=s.user_id
                left join view_employee_depart_status v on u.employee_id =v.id
                WHERE u.login_name = :username LIMIT 1";

        $stmt = $db->prepare($sql);
        $stmt->execute([':username' => $loginUsername]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($loginPassword, $user['password'])) {
            if (isset($user['is_active']) && $user['is_active'] == false) {
                $_SESSION['login_error'] = "Akun Anda tidak aktif. Hubungi administrator.";
                header("Location: ../index.php");
                exit;
            }

            // Regenerasi ID sesi untuk mencegah Session Fixation
            session_regenerate_id(true);

            $_SESSION["username"]        = $user["username"];
            $_SESSION["terminal"]        = $terminalId;
            $_SESSION["user_id"]         = $user["id"];
            $_SESSION["user"]            = $user["user_fullname"];
            $_SESSION["department"]      = $user["department"];
            $_SESSION["department_init"] = $user["department_init"];
            $_SESSION["department_id"]   = $user["department_id"];
            $_SESSION["section_id"]      = $user["section_id"];
            $_SESSION["location_id"]     = $user["location_id"];
            $_SESSION["location_init"]   = $user["location_init"];

            $updateSql = "UPDATE m_user_login_status
                          SET login_status = true, login_terminal = :terminal
                          WHERE id = :user_id";
            $updateStmt = $db->prepare($updateSql);
            $updateStmt->execute([
                ':terminal' => $terminalId,
                ':user_id'  => $user["id"]
            ]);

            $periodSql = "SELECT id, from_date, to_date FROM payroll_period WHERE status IS TRUE LIMIT 1";
            $periodStmt = $db->query($periodSql);
            $period = $periodStmt->fetch(PDO::FETCH_ASSOC);

            if ($period) {
                $_SESSION["period_id"]         = $period["id"];
                $_SESSION["period_from_date"]  = $period["from_date"];
                $_SESSION["period_to_date"]    = $period["to_date"];
            }

            header("Location: menu.php");
            exit;

        } else {
            $_SESSION['login_error'] = "Username atau Password salah!";
            header("Location: ../index.php");
            exit;
        }

    } catch (PDOException $e) {
        error_log("Login Database Error: " . $e->getMessage());
        $_SESSION['login_error'] = "Terjadi kesalahan sistem pada database.";
        header("Location: ../index.php");
        exit;
    }
} else {
    header("Location: ../index.php");
    exit;
}
?>
