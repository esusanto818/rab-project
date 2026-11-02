<?php
// Include file fungsi dasar dan class database yang digunakan sistem
require_once __DIR__ . '/php-class/crudClass.php';
require_once __DIR__ . '/php-func/function.all.php';

// Inisialisasi koneksi database
$db = new crud();

try {
    // 1. PASTIKAN USERNAME INI SESUAI DENGAN YANG ANDA KETIK DI HALAMAN LOGIN
    $username = 'demo'; 
    $new_password = 'xyz789';

    // 2. Cek enkripsi yang dipakai aplikasi Anda
    // Jika sistem login Anda memakai md5 murni, langsung gunakan md5($new_password)
    $encrypted_password = function_exists('EncodeData') ? EncodeData($new_password) : md5($new_password);

    // Cek apakah user tersedia di tabel m_user_system
    $stmt = $db->prepare("SELECT id, login_name FROM m_user_system WHERE login_name = :username");
    $stmt->execute(['username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user) {
        $user_id = $user['id'];
        $actual_username = $user['login_name'];

        // Update password user di database
        $updateQuery = "UPDATE m_user_system SET password = :password WHERE id = :id";
        $updateStmt = $db->prepare($updateQuery);
        $updateResult = $updateStmt->execute([
            'password' => $encrypted_password,
            'id' => $user_id
        ]);

        // Cek apakah ada baris data di database yang benar-benar berubah
        if ($updateStmt->rowCount() > 0) {
            echo "<h3>Berhasil! Password untuk user <b>'" . htmlspecialchars($actual_username) . "'</b> telah diperbarui menjadi <b>" . htmlspecialchars($new_password) . "</b>.</h3>";
            echo "<p>Hasil enkripsi yang masuk ke database: <code>" . htmlspecialchars($encrypted_password) . "</code></p>";
            echo "<p style='color:red;'><b>PENTING:</b> Silakan hapus file ini demi keamanan setelah selesai digunakan.</p>";
        } else {
            echo "<h3>Informasi: Query berhasil dijalankan, namun password tidak berubah.</h3>";
            echo "<p>Hal ini terjadi kemungkinan karena password baru yang Anda input (setelah dienkripsi) <b>sama persis</b> dengan password lama yang sudah ada di database.</p>";
        }
    } else {
        echo "<h3>Gagal: User dengan nama login <b>'" . htmlspecialchars($username) . "'</b> tidak ditemukan di dalam tabel m_user_system.</h3>";
        
        // Menampilkan list user yang tersedia di database untuk mempermudah pengecekan Anda
        echo "<p><b>Daftar user yang ada di database Anda:</b></p><ul>";
        $list = $db->query("SELECT login_name FROM m_user_system LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($list as $row) {
            echo "<li>" . htmlspecialchars($row['login_name']) . "</li>";
        }
        echo "</ul>";
    }

} catch (Exception $e) {
    echo "<h3>Terjadi kesalahan sistem saat memperbarui password:</h3> " . $e->getMessage();
}
?>
