<?php
/*
    Document        : index.php
    Description     : Halaman Utama / Portal Login Sistem Monitoring Kontraktor & WMS (Theme Matched)
*/

require_once __DIR__ . '/php-class/crudClass.php';

if (!isset($_SESSION)) { session_start(); }

if (isset($_SESSION["user_id"]) && !is_null($_SESSION["user_id"])) {
    //header('Location: views/dashboard.php');
    //exit;
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
            $stmt->execute(['username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user && (password_verify($password, $user['password']) || md5($password) === $user['password'])) {
                $_SESSION['user_id']    = $user['id'];
                $_SESSION['username']   = $user['login_name'];
                $_SESSION['department'] = $user['department'] ?? 'General';
                $_SESSION['FormAccess'] = 3;

                header('Location: views/dashboard.php');
                exit;
            } else {
                $error_msg = 'Username atau Password salah!';
            }
        } catch (Exception $e) {
            $error_msg = 'Terjadi kesalahan koneksi database.';
        }
    } else {
        $error_msg = 'Harap isi username dan password.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - [XYZ] System</title>
    <!-- Tailwind CSS CDN -->
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@600;700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        gold: ['Cinzel', 'serif'],
                        body: ['Inter', 'sans-serif'],
                    },
                    colors: {
                        gold: {
                            300: '#f3e5ab',
                            400: '#dfb76c',
                            500: '#c59b27',
                            600: '#9e7811',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gradient-to-br from-slate-950 via-[#0d2530] to-[#123847] text-slate-100 font-body min-h-screen flex items-center justify-center p-4">

    <!-- Card Form Login dengan Latar Glassmorphism Dark Teal -->
    <div class="w-full max-w-md bg-[#0b1c24]/85 backdrop-blur-md p-8 rounded-2xl border border-gold-500/40 shadow-[0_10px_35px_rgba(0,0,0,0.8)]">
        
        <!-- Header / Logo Area -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center p-2 rounded-2xl bg-[#000000]/90 border border-gold-500/40 mb-4 shadow-inner">
                <img src="assets/new_logo1.png" alt="System Logo" class="w-24 h-28 object-contain rounded-xl">
            </div>
            <h1 class="text-2xl font-bold font-gold text-gold-400 tracking-wider">[XYZ]</h1>
            <p class="text-xs text-slate-300 uppercase tracking-widest mt-1">Material & Budget Monitoring</p>
        </div>

        <!-- Alert Error -->
        <?php if (!empty($error_msg)): ?>
            <div class="bg-rose-950/80 border border-rose-500/40 text-rose-300 text-sm p-3.5 rounded-xl mb-6 text-center">
                <?= htmlspecialchars($error_msg); ?>
            </div>
        <?php endif; ?>

        <!-- Form Login -->
        <form method="POST" action="" class="space-y-5">
            <div>
                <label for="username" class="block text-xs font-semibold text-gold-400/90 uppercase tracking-wider mb-2">Username</label>
                <input type="text" id="username" name="username" required autofocus 
                       placeholder="Masukkan username" 
                       class="w-full bg-[#061217]/90 border border-slate-700 text-white rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-gold-400 focus:ring-1 focus:ring-gold-400 transition placeholder:text-slate-500">
            </div>

            <div>
                <label for="password" class="block text-xs font-semibold text-gold-400/90 uppercase tracking-wider mb-2">Password</label>
                <input type="password" id="password" name="password" required 
                       placeholder="••••••••" 
                       class="w-full bg-[#061217]/90 border border-slate-700 text-white rounded-xl px-4 py-3 text-sm focus:outline-none focus:border-gold-400 focus:ring-1 focus:ring-gold-400 transition placeholder:text-slate-500">
            </div>

            <button type="submit" 
                    class="w-full bg-gold-500 hover:bg-gold-400 text-slate-950 font-bold py-3 px-4 rounded-xl transition duration-200 tracking-wider uppercase text-sm mt-2 shadow-lg hover:shadow-gold-500/20">
                Masuk ke Sistem
            </button>
        </form>

        <!-- Footer -->
        <div class="mt-8 text-center border-t border-slate-800/80 pt-4">
            <p class="text-[11px] text-slate-400 tracking-wider">&copy; <?= date('Y'); ?> Enterprise Control System</p>
        </div>

    </div>

</body>
</html>