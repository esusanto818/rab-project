<?php
/*
Document : index.php
Description : Portal Login Modern - Dark Theme (Self-Contained)
*/
require_once __DIR__ . '/php-class/crudClass.php';
if (!isset($_SESSION)) { session_start(); }

// Jika sudah login, arahkan ke dashboard
if (isset($_SESSION["user_id"]) && !is_null($_SESSION["user_id"])) {
    header('Location: views/dashboard.php');
    exit;
}

$error_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($username) && !empty($password)) {
        try {
            $db = new crud();
            $stmt = $db->prepare("
             SELECT id, login_name, password, active 
                FROM m_user_system 
                WHERE login_name = :username AND active IS TRUE 
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            $isPasswordValid = false;
            if (function_exists('password_verify')) {
                $isPasswordValid = password_verify($password, $user['password']) || ($user['password'] === $password);
            } else {
                $isPasswordValid = ($user['password'] === $password);
            }

            if ($user && $isPasswordValid) {
                //error_log("Pesan debug: Proses A berhasil dijalankan.");

                if ($user['active'] != 1) {
                    $error_msg = "Akun Anda tidak aktif. Hubungi administrator.";
                } else {
                    $_SESSION["user_id"] = $user['id'];
                    $_SESSION["username"] = $user['username'];
                    $_SESSION["user_fullname"] = $user['user_fullname'];
                    $_SESSION["department_id"] = $user['department_id'];
                    $_SESSION["FormAccess"] = (int)$user['form_access'];
                    
                    header('Location: views/dashboard.php');
                    exit;
                }
            } else {
                $error_msg = "Username atau Password salah!";
            }
        } catch (PDOException $e) {
            error_log("Login Database Error: " . $e->getMessage());
            $error_msg = "Terjadi kesalahan sistem pada database.";
        }
    } else {
        $error_msg = "Username dan Password wajib diisi!";
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <title>Login - RAB-PROJECT Monitor</title>
    
</head>
<body>
    <div class="login-container">
        <!-- Logo Section -->
        <div class="logo-section">
            <div class="logo-icon">R</div>
            <h1 class="logo-title">RAB-<span>PROJECT</span></h1>
            <p class="logo-subtitle">Construction ERP & AI Monitoring System</p>
        </div>
        
        <!-- Login Card -->
        <div class="login-card">
            <?php if ($error_msg): ?>
                <div class="error-message">
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"></circle>
                        <line x1="12" y1="8" x2="12" y2="12"></line>
                        <line x1="12" y1="16" x2="12.01" y2="16"></line>
                    </svg>
                    <?= htmlspecialchars($error_msg); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            name="username" 
                            class="form-input" 
                            placeholder="Masukkan username Anda"
                            value="<?= htmlspecialchars($_POST['username'] ?? ''); ?>"
                            required
                            autocomplete="username"
                        >
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            name="password" 
                            class="form-input" 
                            placeholder="••••••••"
                            required
                            autocomplete="current-password"
                        >
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    Masuk ke Sistem
                </button>
            </form>
            
            <div class="back-link">
                <a href="product/index.html">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Kembali ke Halaman Produk
                </a>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer-text">
            &copy; <?= date('Y'); ?> RAB-PROJECT Monitor System. All rights reserved.
        </div>
    </div>
</body>
</html>