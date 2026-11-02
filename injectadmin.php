<?php
require_once __DIR__ . '/php-class/crudClass.php';
require_once __DIR__ . '/php-func/function.all.php';

$db = new crud();

try {
    $username = 'demo';
    $raw_pass = 'xyz789';
    // Menggunakan enkripsi yang dibaca sistem (misal md5 atau EncodeData)
    $hashed_pass00 = md5($raw_pass); 
	$hashed_pass = password_hash($raw_pass, PASSWORD_DEFAULT);

    // Cek apakah user admin sudah ada
    $stmt = $db->prepare("SELECT id FROM m_user_system WHERE login_name = :username");
    $stmt->execute(['username' => $username]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($existing) {
        echo "User 'admin' sudah ada di dalam sistem (ID: {$existing['id']}).";
    } else {
        // Ambil ID employee pertama atau buat dummy
        $empStmt = $db->prepare("SELECT id FROM m_employee ORDER BY id ASC LIMIT 1");
        $empStmt->execute();
        $emp = $empStmt->fetch(PDO::FETCH_ASSOC);
        $employee_id = $emp ? $emp['id'] : 1;

        // Query Insert ke m_user_system
        $query = "INSERT INTO m_user_system (login_name, employee_id, password, active) 
                  VALUES ('{$username}', {$employee_id}, '{$hashed_pass}', 'true');";
        
        // Insert login status
        $query .= "INSERT INTO m_user_login_status (user_id) VALUES (currval('m_user_system_id_seq'::regclass));";

        $db->cmdQ($query);
        echo "Berhasil! User <b>demo</b> dengan password <b>xyz789</b> berhasil di-inject ke database.";
    }
} catch (Exception $e) {
    echo "Gagal melakukan injection: " . $e->getMessage();
}
?>
