<?php
// views/router.php
// Router untuk halaman dinamis RAB-Project
// [REVISI 2026-11-24] Penambahan cache-busting pada asset

if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }

// Ambil ID form dari parameter
$form_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
if ($form_id <= 0) {
  header('Location: dashboard.php');
  exit;
}

// Include class dan koneksi DB
require_once __DIR__ . '/../php-class/crudClass.php';
$db = new crud();

// Ambil data form dari database
try {
  $sql = "SELECT f.title, f.php_url, f.group_id, g.name as group_name, g.seq as group_seq
             FROM m_form_name f
             JOIN m_form_group g ON f.group_id = g.id
             WHERE f.id = :form_id AND f.active = true";
  $stmt = $db->prepare($sql);
  $stmt->execute(['form_id' => $form_id]);
  $form = $stmt->fetch(PDO::FETCH_ASSOC);

  if (!$form) {
    header('Location: dashboard.php');
    exit;
  }

  // Validasi hak akses
  $access_sql = "SELECT 1 FROM m_user_access_menu
                    WHERE user_id = :user_id AND form_id = :form_id";
  $access_stmt = $db->prepare($access_sql);
  $access_stmt->execute(['user_id' => $_SESSION['user_id'], 'form_id' => $form_id]);
  if (!$access_stmt->fetch()) {
    header('Location: dashboard.php');
    exit;
  }

  // Set judul halaman
  $page_title = htmlspecialchars($form['title']) . ' - RAB Project';
  $favVer = date('YmdHis');
} catch (Exception $e) {
  header('Location: dashboard.php');
  exit;
}

// Output HTML
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= $page_title ?></title>
  <script src="https://cdn.tailwindcss.com"></script>
  <script>
    tailwind.config = { corePlugins: { preflight: false } };
  </script>
  <link rel="stylesheet" href="../css/theme-sync.css?v=<?=$favVer?>">
  <script src="../js/theme-sync.js?v=<?=$favVer?>"></script>
  <link rel="shortcut icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
  <link rel="icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
  <link rel="apple-touch-icon" href="../favicon.ico?v=<?=$favVer?>">
</head>
<body class="min-h-screen bg-[var(--background-color)] text-[var(--text-color)]">
  <!-- HEADER -->
  <header class="sticky top-0 z-50 bg-[var(--header-color)] text-[var(--text-light)] shadow-lg">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
      <div class="flex items-center justify-between h-14">
        <div class="flex items-center space-x-4">
          <a href="dashboard.php" class="flex items-center space-x-2">
            <?php if (file_exists(__DIR__ . '/../assets/favicon.ico')): ?>
              <img src="../assets/favicon.ico" alt="Logo" class="h-8 w-auto object-contain">
            <?php else: ?>
              <span class="text-lg font-normal tracking-wider">[XYZ]SYS</span>
              <span class="text-xs px-1.5 py-0.5 rounded border" style="background: var(--bg-deep); color: var(--accent); border-color: var(--border-strong);">v1.0</span>
            <?php endif; ?>
          </a>
          <span class="text-sm font-medium"><?= htmlspecialchars($form['group_name']) ?></span>
          <span class="text-sm">/</span>
          <span class="text-sm font-semibold"><?= htmlspecialchars($form['title']) ?></span>
        </div>
        <div class="flex items-center space-x-3">
          <button id="theme_toggle_btn" type="button" onclick="toggleTheme()" class="btn-ghost px-2.5 py-1 rounded text-sm font-semibold transition">
            <span id="theme_icon">🌙</span> <span id="theme_lbl">Dark</span>
          </button>
          <a href="dashboard.php" class="btn-ghost px-2.5 py-1 rounded transition">Dashboard</a>
        </div>
      </div>
    </div>
  </header>

  <!-- MAIN CONTENT -->
  <main class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-6">
    <?php
    // Include halaman yang diminta
    $php_url = __DIR__ . '/../' . $form['php_url'];
    if (file_exists($php_url)) {
      require_once $php_url;
    } else {
      echo '<div class="text-center py-12">Halaman tidak ditemukan.</div>';
    }
    ?>
  </main>

  <!-- FOOTER -->
  <footer class="bg-[var(--header-color)] text-[var(--text-light)] py-4 mt-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center text-sm">
      &copy; <?= date('Y') ?> RAB Project Monitoring System
    </div>
  </footer>

  <!-- JAVASCRIPT -->
  <script>
    // Inisialisasi tema
    document.addEventListener('DOMContentLoaded', function() {
      const theme = localStorage.getItem('app-theme') || 'default-california-dream';
      document.documentElement.setAttribute('data-theme', theme);
      updateThemeUI(theme);
    });
  </script>
</body>
</html>