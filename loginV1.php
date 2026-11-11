<?php
/*
Document : login.php
Description : Login Page - RAB-PROJECT (Dark Theme, selaras dashboard + Show/Hide Password Icon)
*/

require_once __DIR__ . '/php-class/crudClass.php';
require_once __DIR__ . '/php-func/function.all.php';

if (!isset($_SESSION)) { session_start(); }

// Jika sudah login, langsung ke dashboard
if (isset($_SESSION["user_id"]) && !is_null($_SESSION["user_id"])) {
    header('Location: views/dashboard.php');
    exit;
}

// Protect the login form from cross-origin POST requests.
if (function_exists('app_require_same_origin_post')) {
    app_require_same_origin_post();
}

$error_msg = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (!empty($username) && !empty($password)) {
        try {
            $db = new crud();
            $stmt = $db->prepare("
                SELECT id, login_name, password, active
                FROM m_user_system
                WHERE LOWER(login_name) = LOWER(:username)
                LIMIT 1
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) {
                $error_msg = "Username atau Password salah!";
            } else {
                if ((int)$user['active'] !== 1) {
                    $error_msg = "Akun Anda tidak aktif. Hubungi administrator.";
                } else {
                    $isPasswordValid = false;
                    
                    // Cek multiple methods
                    if ($user['password'] === $password) $isPasswordValid = true;
                    if (!$isPasswordValid && function_exists('EncodeData')) {
                        if ($user['password'] === EncodeData($password)) $isPasswordValid = true;
                    }
                    if (!$isPasswordValid && $user['password'] === md5($password)) $isPasswordValid = true;
                    if (!$isPasswordValid && function_exists('password_verify')) {
                        if (password_verify($password, $user['password'])) $isPasswordValid = true;
                    }
                    
                    if ($isPasswordValid) {
                        // Upgrade legacy plaintext / Base64 / MD5 credentials after successful authentication.
                        $passwordInfo = password_get_info((string)$user['password']);
                        $passwordAlgo = $passwordInfo['algo'] ?? 0;
                        if (empty($passwordAlgo) || !password_verify($password, (string)$user['password'])
                            || password_needs_rehash((string)$user['password'], PASSWORD_DEFAULT, ['cost' => 12])) {
                            $upgradeHash = password_hash($password, PASSWORD_DEFAULT, ['cost' => 12]);
                            $upgradeStmt = $db->prepare("UPDATE m_user_system SET password = :password WHERE id = :id");
                            $upgradeStmt->execute([':password' => $upgradeHash, ':id' => (int)$user['id']]);
                        }

                        // Rotasi session saat login; hak modul tidak ditentukan dari kolom user.
                        session_regenerate_id(true);
                        $_SESSION["user_id"] = $user['id'];
                        $_SESSION["username"] = $user['login_name'];
                        unset($_SESSION['FormAccess'], $_SESSION['form_id'], $_SESSION['current_menu_id']);
                        header('Location: views/dashboard.php');
                        exit;
                    } else {
                        $error_msg = "Username atau Password salah!";
                    }
                }
            }
        } catch (Exception $e) {
            error_log("[Login Error] " . $e->getMessage());
            $error_msg = "Terjadi kesalahan sistem.";
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
    <title>Login - RAB-PROJECT</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Orbitron:wght@500;700;900&display=swap" rel="stylesheet">
    <link rel="icon" href="assets/favicon.ico?v=<?= date('YmdHis') ?>" type="image/x-icon">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', sans-serif;
            background-color: #0a1929;
            background-image: 
                linear-gradient(rgba(34, 211, 238, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(34, 211, 238, 0.03) 1px, transparent 1px);
            background-size: 30px 30px;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            color: #e2e8f0;
        }
        
        .login-container {
            width: 100%;
            max-width: 420px;
            padding: 20px;
        }
        
        /* Logo */
        .logo-wrapper {
            text-align: center;
            margin-bottom: 24px;
        }
        
        .logo-icon {
            width: 72px;
            height: 72px;
            margin: 0 auto 16px;
            background: linear-gradient(135deg, #22d3ee 0%, #f39c12 100%);
            border-radius: 18px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Orbitron', sans-serif;
            font-size: 36px;
            font-weight: 900;
            color: #0a1929;
            box-shadow: 0 4px 20px rgba(34, 211, 238, 0.2);
        }
        
        .logo-title {
            font-family: 'Orbitron', sans-serif;
            font-size: 28px;
            font-weight: 700;
            letter-spacing: 3px;
            margin-bottom: 6px;
        }
        
        .logo-title .rab {
            color: #ffffff;
        }
        
        .logo-title .project {
            background: linear-gradient(135deg, #22d3ee 0%, #f39c12 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .logo-subtitle {
            color: #64748b;
            font-size: 13px;
            letter-spacing: 0.5px;
        }
        
        /* Login Card */
        .login-card {
            background: #0d2137;
            border: 1px solid #1e3a5f;
            border-radius: 12px;
            padding: 32px;
        }
        
        /* Error Message */
        .error-box {
            background: rgba(239, 68, 68, 0.08);
            border: 1px solid rgba(239, 68, 68, 0.3);
            border-radius: 8px;
            padding: 12px 16px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
            color: #ef4444;
            font-size: 14px;
        }
        
        .error-icon {
            width: 20px;
            height: 20px;
            border: 2px solid #ef4444;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 12px;
            font-weight: bold;
        }
        
        /* Form */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            color: #22d3ee;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 14px;
            top: 50%;
            transform: translateY(-50%);
            color: #475569;
            display: flex;
            align-items: center;
            z-index: 2;
        }
        
        .input-icon svg {
            width: 18px;
            height: 18px;
        }
        
        .form-input {
            width: 100%;
            padding: 12px 40px 12px 44px;
            background: #0a1929;
            border: 1px solid #1e3a5f;
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.2s ease;
            outline: none;
        }
        
        .form-input:focus {
            border-color: #22d3ee;
            background: #0a1929;
            color: #e2e8f0;
        }
        
        .form-input:focus ~ .input-icon,
        .input-wrapper:focus-within .input-icon {
            color: #22d3ee;
        }
        
        .form-input::placeholder {
            color: #475569;
        }
        
        /* Toggle Password Button */
        .toggle-password {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            cursor: pointer;
            padding: 4px;
            z-index: 10;
            display: flex;
            align-items: center;
            justify-content: center;
            background: transparent;
            border: none;
        }
        
        .toggle-password:hover {
            background: rgba(34, 211, 238, 0.1);
            border-radius: 4px;
        }
        
        /* Submit Button */
        .btn-submit {
            width: 100%;
            padding: 14px;
            background: #f39c12;
            color: #0a1929;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.2s ease;
            margin-top: 8px;
            font-family: 'Inter', sans-serif;
        }
        
        .btn-submit:hover {
            background: #e67e22;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(243, 156, 18, 0.3);
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        /* Back Link */
        .back-link {
            text-align: center;
            margin-top: 20px;
            padding-top: 16px;
            border-top: 1px solid #1e3a5f;
        }
        
        .back-link a {
            color: #64748b;
            text-decoration: none;
            font-size: 13px;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: color 0.2s ease;
        }
        
        .back-link a:hover {
            color: #22d3ee;
        }
        
        /* Footer */
        .footer-text {
            text-align: center;
            margin-top: 24px;
            color: #475569;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
    </style>
</head>
<body>

    <div class="login-container">
        
        <!-- Logo Section -->
        <div class="logo-wrapper">
            <div class="logo-icon">R</div>
            <div class="logo-title">
                <span class="rab">RAB-</span><span class="project">PROJECT</span>
            </div>
            <div class="logo-subtitle">Construction ERP & AI Monitoring System</div>
        </div>
        
        <!-- Login Card -->
        <div class="login-card">
            
            <!-- Error Message -->
            <?php if ($error_msg): ?>
                <div class="error-box">
                    <div class="error-icon">✕</div>
                    <span><?= htmlspecialchars($error_msg); ?></span>
                </div>
            <?php endif; ?>
            
            <!-- Login Form -->
            <form method="POST" action="">
                
                <!-- Username -->
                <div class="form-group">
                    <label class="form-label">Username</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"></path>
                                <circle cx="12" cy="7" r="4"></circle>
                            </svg>
                        </span>
                        <input 
                            type="text" 
                            name="username" 
                            class="form-input"
                            placeholder="Masukkan username"
                            value="<?= htmlspecialchars($username); ?>"
                            required
                            autocomplete="username"
                            autofocus
                        >
                    </div>
                </div>
                
                <!-- Password -->
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"></rect>
                                <path d="M7 11V7a5 5 0 0 1 10 0v4"></path>
                            </svg>
                        </span>
                        <input 
                            type="password" 
                            name="password" 
                            id="login-password"
                            class="form-input"
                            placeholder="Masukkan password"
                            required
                            autocomplete="current-password"
                        >
                        <!-- Tombol Toggle Mata -->
                        <button type="button" id="toggle-password-btn" class="toggle-password" title="Tampilkan password">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path>
                                <line x1="1" y1="1" x2="23" y2="23"></line>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <!-- Submit Button -->
                <button type="submit" class="btn-submit">
                    Masuk ke Sistem
                </button>
            </form>
            
            <!-- Back Link -->
            <div class="back-link">
                <a href="index.php">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="19" y1="12" x2="5" y2="12"></line>
                        <polyline points="12 19 5 12 12 5"></polyline>
                    </svg>
                    Kembali ke Halaman Produk
                </a>
            </div>
        </div>
        
        <!-- Footer -->
        <div class="footer-text">
            &copy; 2026 RAB-PROJECT Monitor System. All rights reserved.
        </div>
    </div>

    <!-- Script Logika Toggle Show/Hide Password -->
    <script>
        document.addEventListener('DOMContentLoaded', function() {
            var passwordInput = document.getElementById('login-password');
            var toggleBtn = document.getElementById('toggle-password-btn');

            if (!passwordInput || !toggleBtn) return;

            var ICON_EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
            var ICON_EYE_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';

            toggleBtn.onclick = function(e) {
                e.preventDefault();
                var isPassword = (passwordInput.type === 'password');
                
                if (isPassword) {
                    passwordInput.type = 'text';
                    toggleBtn.innerHTML = ICON_EYE_OPEN;
                    toggleBtn.title = 'Sembunyikan password';
                } else {
                    passwordInput.type = 'password';
                    toggleBtn.innerHTML = ICON_EYE_CLOSED;
                    toggleBtn.title = 'Tampilkan password';
                }
            };
        });
    </script>
</body>
</html>