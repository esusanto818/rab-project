<?php
require_once __DIR__ . '/../php-func/user.valid.php';
if ((int)($_SESSION['FormAccess'] ?? 0) < 1) {
    http_response_code(403);
    exit('Hak baca kartu stok tidak tersedia.');
}

/**
 * views/stock.card.m.php  —  Kartu Stok Mobile v2.1 (bisa offline)
 * Pola sama dengan project.progress.m.php: wajib login (user.valid.php) dan alamat dari BASE_URL.
 *
 * File yang berpasangan:
 *   js/stock.card.m.js            (UI + logika offline, dimuat halaman ini)
 *   php-crud/stock.cardCRD.php    (backend; action: lookup_warehouses | lookup_items | card | snapshot)
 *   sw.stockcard.js               (Service Worker - HARUS di root aplikasi, sejajar index.php)
 *   stock.card.m.menu.sql         (daftar menu + hak akses; wajib dijalankan sekali)
 *
 * Catatan akses: user.valid.php mengotorisasi halaman view berdasarkan NAMA FILE di m_form_name.
 * Jika stock.card.m.php belum terdaftar untuk user, halaman menjawab 403 "Akses ditolak".
 */
header('ngrok-skip-browser-warning: 1');
include __DIR__ . "/../php-func/user.valid.php";   // wajib login; set $_SESSION["FormAccess"]
header('Cache-Control: no-cache, must-revalidate'); // update JS/CSS sampai ke HP tanpa hapus cache

$appBase = rtrim(BASE_URL, '/') . '/';

$jsFile = __DIR__ . '/../js/stock.card.m.js';
$ver    = file_exists($jsFile) ? filemtime($jsFile) : time();

// Semua alamat dikirim ke JS lewat window.SC_CFG (JS tidak menebak path sendiri)
$cfg = array(
    'base'  => $appBase,
    'crd'   => $appBase . 'php-crud/stock.cardCRD.php',
    'sw'    => $appBase . 'sw.stockcard.js',
    'scope' => $appBase,
    'ver'   => (string)$ver,
    'js'    => $appBase . 'js/stock.card.m.js?v=' . $ver,
);
$jsSrc = $cfg['js'];

// Menu bawah: halaman mobile yang sudah ada
$NAV = array(
    array('icon' => '🏠', 'label' => 'Dashboard', 'href' => 'dashboard.php',        'active' => false),
    array('icon' => '📦', 'label' => 'Stok',      'href' => 'stock.card.m.php',       'active' => true),
    array('icon' => '📊', 'label' => 'Progress',  'href' => 'project.progress.m.php', 'active' => false),
);
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kartu Stok</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#020812">
<meta name="mobile-web-app-capable" content="yes">
<link rel="apple-touch-icon" href="<?php echo htmlspecialchars($appBase); ?>assets/icon-192.png">
<link rel="stylesheet" href="../css/app-theme.css">
<meta name="apple-mobile-web-app-capable" content="yes">

</head>
<body>
<div class="app">
    <div class="hero">
        <h1>📦 Kartu Stok</h1>
        <p>Monitoring mutasi stok per gudang</p>
    </div>

    <div class="bar"><span id="pillNet" class="pill">online</span><span>Stock Card Mobile v2.1</span></div>

    <div id="msg" class="msg hidden"></div>

    <div class="panel">
        <label for="fWh">🏭 Gudang</label>
        <select id="fWh"><option value="0">-- Memuat gudang... --</option></select>

        <label for="fItem">📦 Material</label>
        <select id="fItem"><option value="">-- Pilih gudang dulu --</option></select>

        <div class="row2">
            <div><label for="fFrom">📅 Dari</label><input type="date" id="fFrom"></div>
            <div><label for="fTo">📅 Sampai</label><input type="date" id="fTo"></div>
        </div>
        <button id="btnLoad" type="button">Tampilkan</button>
    </div>

    <div id="resultBox"></div>
    <noscript><div class="msg err">JavaScript harus aktif untuk memakai halaman ini.</div></noscript>
</div>

<nav class="nav">
<?php foreach ($NAV as $n): ?>
    <a href="<?php echo htmlspecialchars($n['href']); ?>"<?php echo $n['active'] ? ' class="on"' : ''; ?>><?php echo $n['icon']; ?><br><?php echo htmlspecialchars($n['label']); ?></a>
<?php endforeach; ?>
</nav>

<!-- penangkap error: di HP tidak ada console, jadi error JS ditampilkan di layar -->
<script>
function scShowErr(t){var m=document.getElementById('msg');if(m){m.className='msg err';m.textContent=t;}}
window.addEventListener('error',function(e){if(e&&e.message)scShowErr('Error JS: '+e.message+' ('+String(e.filename||'').split('/').pop()+':'+e.lineno+')');});
window.SC_CFG = <?php echo json_encode($cfg, JSON_UNESCAPED_SLASHES); ?>;
</script>
<script src="<?php echo htmlspecialchars($jsSrc); ?>" onerror="scShowErr('File JS gagal dimuat: <?php echo htmlspecialchars($jsSrc); ?>')"></script>
<script>
// Apply saved theme to mobile pages
const savedTheme = localStorage.getItem('app_theme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
</script>
</body>
</html>