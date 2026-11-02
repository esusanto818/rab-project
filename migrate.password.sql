<?php
/*
Document : php-crud/migrate_passwords.php
Description : Migrate old passwords (BASE64/MD5) to bcrypt
Usage : php php-crud/migrate_passwords.php (dari CLI saja)
WARNING : HAPUS file ini setelah selesai migrasi!
*/

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Script ini hanya boleh dijalankan via CLI.');
}

chdir(__DIR__);
require_once __DIR__ . '/../php-class/crudClass.php';
require_once __DIR__ . '/../php-func/function.all.php';

$db = new crud();

echo "=== Password Migration Script ===\n";
echo "Migrasi password dari BASE64/MD5 ke bcrypt...\n\n";

try {
    // Ambil semua user
    $stmt = $db->query("SELECT id, login_name, password FROM m_user_system WHERE password IS NOT NULL AND password != '' and login_name!=all(array['admin,'eddy','demo'])");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $migrated = 0;
    $skipped = 0;
    $failed = 0;
    
    foreach ($users as $user) {
        $userId = $user['id'];
        $username = $user['login_name'];
        $currentPass = $user['password'];
        
        // Cek apakah sudah bcrypt
        if (strpos($currentPass, '$2y$') === 0 || strpos($currentPass, '$2a$') === 0) {
            echo "[SKIP] User: {$username} (ID: {$userId}) - Sudah bcrypt\n";
            $skipped++;
            continue;
        }
        
        // Decode BASE64 jika perlu
        $decodedPass = $currentPass;
        if (base64_encode(base64_decode($currentPass, true)) === $currentPass) {
            $decodedPass = base64_decode($currentPass);
        }
        
        // Hash dengan bcrypt
        $hashedPassword = password_hash($decodedPass, PASSWORD_DEFAULT, ['cost' => 12]);
        
        // Update
        $updateStmt = $db->prepare("UPDATE m_user_system SET password = :password WHERE id = :id");
        $updateStmt->execute([
            ':password' => $hashedPassword,
            ':id' => $userId
        ]);
        
        if ($updateStmt->rowCount() > 0) {
            echo "[OK] User: {$username} (ID: {$userId}) - Berhasil di-migrate\n";
            $migrated++;
        } else {
            echo "[FAIL] User: {$username} (ID: {$userId}) - Gagal update\n";
            $failed++;
        }
    }
    
    echo "\n=== Migration Complete ===\n";
    echo "Migrated: {$migrated}\n";
    echo "Skipped: {$skipped}\n";
    echo "Failed: {$failed}\n";
    echo "Total: " . count($users) . "\n\n";
    
    echo "WARNING: Hapus file migrate_passwords.php ini dari server!\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
?>
