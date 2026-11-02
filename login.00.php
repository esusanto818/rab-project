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
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #051525 0%, #071d30 50%, #0a2d4a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
            overflow: hidden;
        }
        
        /* Grid Pattern Background */
        body::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-image: 
                linear-gradient(rgba(34, 211, 238, 0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(34, 211, 238, 0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }
        
        /* Glow Effects */
        body::after {
            content: '';
            position: absolute;
            top: -50%;
            left: -50%;
            width: 200%;
            height: 200%;
            background: 
                radial-gradient(circle at 20% 30%, rgba(34, 211, 238, 0.1) 0%, transparent 50%),
                radial-gradient(circle at 80% 70%, rgba(243, 156, 18, 0.08) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .login-container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            animation: fadeInUp 0.6s ease-out;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 30px;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            margin: 0 auto 15px;
            background: linear-gradient(135deg, #22d3ee 0%, #f39c12 100%);
            border-radius: 20px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 40px;
            font-weight: 900;
            color: #051525;
            box-shadow: 0 10px 30px rgba(34, 211, 238, 0.3);
            animation: float 3s ease-in-out infinite;
        }
        
        @keyframes float {
            0%, 100% { transform: translateY(0px); }
            50% { transform: translateY(-10px); }
        }
        
        .logo-title {
            font-size: 32px;
            font-weight: 900;
            color: #ffffff;
            letter-spacing: 2px;
            margin-bottom: 5px;
        }
        
        .logo-title span {
            background: linear-gradient(135deg, #22d3ee 0%, #f39c12 100%);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .logo-subtitle {
            color: #94a3b8;
            font-size: 14px;
            letter-spacing: 1px;
        }
        
        .login-card {
            background: rgba(10, 45, 74, 0.6);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(34, 211, 238, 0.2);
            border-radius: 16px;
            padding: 35px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.5), 0 0 40px rgba(34, 211, 238, 0.1);
        }
        
        .error-message {
            background: rgba(239, 68, 68, 0.1);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #ef4444;
            padding: 12px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            color: #22d3ee;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-bottom: 8px;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .input-icon {
            position: absolute;
            left: 15px;
            top: 50%;
            transform: translateY(-50%);
            color: #64748b;
            font-size: 18px;
        }
        
        .form-input {
            width: 100%;
            padding: 14px 15px 14px 45px;
            background: rgba(5, 21, 37, 0.8);
            border: 1px solid rgba(34, 211, 238, 0.2);
            border-radius: 10px;
            color: #e2e8f0;
            font-size: 15px;
            transition: all 0.3s ease;
        }
        
        .form-input:focus {
            outline: none;
            border-color: #22d3ee;
            box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.15);
            background: rgba(5, 21, 37, 0.9);
        }
        
        .form-input::placeholder {
            color: #64748b;
        }
        
        .btn-login {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #f39c12 0%, #e67e22 100%);
            color: #051525;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1px;
            cursor: pointer;
            transition: all 0.3s ease;
            box-shadow: 0 5px 20px rgba(243, 156, 18, 0.3);
            margin-top: 10px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(243, 156, 18, 0.4);
            background: linear-gradient(135deg, #e67e22 0%, #d35400 100%);
        }
        
        .btn-login:active {
            transform: translateY(0);
        }
        
        .back-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            padding-top: 20px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
        }
        
        .back-link a {
            color: #94a3b8;
            text-decoration: none;
            font-size: 13px;
            transition: color 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        
        .back-link a:hover {
            color: #22d3ee;
        }
        
        .footer-text {
            text-align: center;
            margin-top: 25px;
            color: #64748b;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        
        @media (max-width: 480px) {
            .login-card {
                padding: 25px;
            }
            
            .logo-title {
                font-size: 28px;
            }
        }
    </style>
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