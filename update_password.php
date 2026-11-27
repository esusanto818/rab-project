<?php
// Pastikan sesi aktif dan load koneksi database menggunakan kelas CRUD (PDO)
//xdebug_break();
if (!isset($_SESSION)) {
    session_start();
    //xdebug_break();

}
/*require_once   '/php-func/function.all.php';
require_once   '/php-class/crudClass.php';*/
require_once __DIR__ . '/php-func/function.all.php';
require_once __DIR__ . '/php-class/crudClass.php';



try {
    $db = new crud();

    // 1. Ambil seluruh data pengguna dari tabel (Sesuaikan nama tabel jika m_user atau m_user_system)
    // Berdasarkan file sistem lama Anda, tabel user umumnya m_user
    $stmt = $db->query("SELECT id, login_name, password FROM m_user_system where id=1");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<h3>Memulai Proses update Password ke Format Modern...</h3>";
    $count = 0;

    foreach ($users as $user) {
        $userId   = $user['id'];
        $username = $user['login_name'];
        $currentPass = 'admin789';

        // Cek apakah password sudah berformat hash modern (biasanya diawali $2y$ untuk bcrypt)
        $info = password_get_info($currentPass);




        if (!isset($info['algo']) || $info['algo'] === 0) {
            // Jika belum di-hash (masih plaintext atau enkripsi lama), buat hash baru dengan password_hash() PHP
            $newHashedPassword = password_hash($currentPass, PASSWORD_DEFAULT);

            // Update kembali ke database secara aman menggunakan Prepared Statements
            $updateStmt = $db->prepare("UPDATE m_user_system SET password = :new_pass WHERE id = :id");
            $updateStmt->execute([
                ':new_pass' => $newHashedPassword,
                ':id'       => $userId
            ]);

            echo "User: <b>{$username}</b> (ID: {$userId}) berhasil di-hash. Oldpassword: {{$currentPass}} and Newpassword: {{$newHashedPassword}}<br>";
            $count++;
        } else {
            echo "User: <b>{$username}</b> sudah menggunakan format hash modern, dilewati. dan Oldpassword: {{$currentPass}}<br>";
        }
    }

    echo "<br><b>Selesai! Total {$count} password berhasil diperbarui.</b>";
    echo "<br><span ><b>PENTING:</b> Segera hapus file migrasi ini setelah selesai demi keamanan!</span>";

} catch (PDOException $e) {
    echo "Terjadi kesalahan database: " . $e->getMessage();
}
?>
