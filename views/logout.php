<?php
// views/logout.php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ambil user_id dari session
$user_id = $_SESSION['user_id'] ?? null;

if ($user_id) {
    // Pastikan path ke crudClass.php sesuai dengan struktur folder Anda
    require_once __DIR__ . '/../php-class/crudClass.php';
    $db = new crud();
    
    try {
        // Update status login berdasarkan user_id (bukan id primary key tabel login_status)
        // Sesuai schema: m_user_login_status memiliki kolom user_id sebagai FK
        $stmt = $db->prepare("UPDATE m_user_login_status SET login_status = false, login_terminal = NULL WHERE user_id = :user_id");
        $stmt->execute(['user_id' => $user_id]);
    } catch (Exception $e) {
        // Jika gagal update (misal user tidak ada di tabel status), abaikan dan tetap lanjut logout
        error_log("Logout DB Error: " . $e->getMessage());
    }
}

// Hapus semua data session
$_SESSION = array();

// Hapus cookie session jika ada
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"] ?? false, $params["httponly"] ?? true
    );
}

// Destroy session
session_destroy();

// Redirect to main page
$protocol = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$script_dir = dirname($_SERVER['SCRIPT_NAME']);
$base_dir = rtrim($script_dir, '/\\') . '/';
$base_url = $protocol . $host . $base_dir;

error_log('HOST = '.$host);
error_log('BASE DIR = '.$base_dir);
error_log('BASE URL = '.$base_url);


header('Location: ../index.php');
?>
