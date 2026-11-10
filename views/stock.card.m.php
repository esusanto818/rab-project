<?php
/**
 * views/stock.card.m.php  —  Stock Card Mobile v2.0 (bisa offline)
 *
 * File yang berpasangan:
 *   views/stock.card.m.js         (halaman ini memuatnya)
 *   php-crud/stock.cardCRD.php    (backend)
 *   sw.stockcard.js               (ROOT aplikasi, sejajar views/ dan php-crud/)
 *
 * Semua URL dihitung di sini dan dikirim ke JS lewat window.SC_CFG, jadi tidak bergantung pada
 * APP_BASE_URL, 'localhost', atau cara halaman dibuka (langsung / lewat router?id=NN).
 */

// Selalu tanya server dulu supaya update JS/CSS sampai ke HP tanpa user menghapus cache
header('Cache-Control: no-cache, must-revalidate');

// ---------------------------------------------------------------
// KONFIGURASI (ubah bila perlu)
// ---------------------------------------------------------------
// Diisi hanya jika path otomatis di bawah salah, mis. '/rab-project/dev/'
$WEB_BASE_OVERRIDE = '';

// Menu bawah. Isi href dengan alamat halaman yang sesuai di aplikasi Anda.
$NAV = array(
    array('icon' => '🏠', 'label' => 'Dashboard', 'href' => '#',  'active' => false),
    array('icon' => '📦', 'label' => 'Stok',      'href' => '#',  'active' => true),
    array('icon' => '📊', 'label' => 'Progress',  'href' => '#',  'active' => false),
);

// ---------------------------------------------------------------
// Hitung base URL aplikasi (folder yang berisi views/ dan php-crud/)
// ---------------------------------------------------------------
$appRoot = str_replace('\\', '/', (string)realpath(__DIR__ . '/..'));
$docRoot = str_replace('\\', '/', (string)realpath(isset($_SERVER['DOCUMENT_ROOT']) ? $_SERVER['DOCUMENT_ROOT'] : ''));

if ($WEB_BASE_OVERRIDE !== '') {
    $WEB_BASE = '/' . trim($WEB_BASE_OVERRIDE, '/') . '/';
} elseif ($docRoot !== '' && strpos($appRoot, $docRoot) === 0) {
    $WEB_BASE = rtrim(substr($appRoot, strlen($docRoot)), '/') . '/';
} else {
    $WEB_BASE = rtrim(str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME']))), '/') . '/';
}
$WEB_BASE = preg_replace('#/+#', '/', $WEB_BASE);

$jsFile = __DIR__ . '/stock.card.m.js';
$ver    = file_exists($jsFile) ? filemtime($jsFile) : time();

$cfg = array(
    'base'  => $WEB_BASE,
    'crd'   => $WEB_BASE . 'php-crud/stock.cardCRD.php',
    'sw'    => $WEB_BASE . 'sw.stockcard.js',
    'scope' => $WEB_BASE,
    'ver'   => (string)$ver,
);
$jsSrc = $WEB_BASE . 'views/stock.card.m.js?v=' . $ver;
?><!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<title>Kartu Stok</title>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#020812">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<style>
:root{--bg:#020812;--card:#0a1624;--card2:#0d1e30;--line:#16324a;--txt:#e6eef5;--mut:#7f96aa;--acc:#22b8cf;--acc2:#0e7490;--in:#2e9e4f;--out:#e0573f;--warn:#d9822b;color-scheme:dark}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--txt);font-family:system-ui,-apple-system,Roboto,"Segoe UI",sans-serif;font-size:15px}
.app{max-width:560px;margin:0 auto;padding:12px 12px calc(84px + env(safe-area-inset-bottom))}
.hero{background:linear-gradient(135deg,#0b5566,#06222c 70%);border-radius:18px;padding:18px 18px 16px;margin-bottom:12px}
.hero h1{margin:0;font-size:22px;color:var(--acc)}
.hero p{margin:6px 0 0;color:#9db2c2;font-size:13px}
.bar{display:flex;justify-content:space-between;align-items:center;margin:0 4px 10px;font-size:12px;color:var(--mut)}
.pill{background:#10283c;color:var(--acc);border-radius:99px;padding:5px 12px;font-weight:600}
.pill.off{background:#3b2410;color:#f5a14a}
.panel{background:var(--card);border:1px solid var(--line);border-radius:18px;padding:14px;margin-bottom:12px}
label{display:block;font-size:13px;font-weight:600;color:#9db2c2;margin:10px 0 5px}
select,input[type=date],input[type=search]{width:100%;padding:12px;background:#050d16;color:var(--txt);border:1px solid var(--line);border-radius:10px;font-size:15px}
select:focus,input:focus{outline:0;border-color:var(--acc)}
.row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
#btnLoad{width:100%;margin-top:14px;padding:13px;border:0;border-radius:10px;background:var(--acc2);color:#fff;font-weight:700;font-size:15px}
#btnLoad:disabled{opacity:.6}
.msg{border-radius:10px;padding:10px 12px;margin-bottom:12px;font-size:13px;line-height:1.4}
.msg.ok{background:#0c2a1a;color:#7ee2a0;border:1px solid #1b5a36}
.msg.err{background:#331414;color:#ff9b8a;border:1px solid #6b2a2a}
.hidden{display:none}
.card{background:var(--card);border:1px solid var(--line);border-radius:14px;padding:12px;margin-bottom:10px}
.card.err{color:#ff9b8a}
.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px}
.sum-card{background:var(--card2);border:1px solid var(--line);border-radius:12px;padding:10px}
.sum-card.in{border-left:4px solid var(--in)}.sum-card.out{border-left:4px solid var(--out)}.sum-card.end{border-left:4px solid var(--acc)}
.sum-lbl{font-size:11px;color:var(--mut);text-transform:uppercase;letter-spacing:.4px}
.sum-val{font-size:17px;font-weight:700;margin-top:3px;word-break:break-all}
.section-title{font-weight:700;margin:14px 2px 8px;color:#9db2c2;font-size:13px}
.tx-card{background:var(--card);border:1px solid var(--line);border-left:4px solid var(--line);border-radius:12px;margin-bottom:8px;overflow:hidden}
.tx-card.in{border-left-color:var(--in)}.tx-card.out{border-left-color:var(--out)}.tx-card.open{border-left-color:var(--acc)}
.tx-head{display:flex;justify-content:space-between;padding:9px 12px;background:var(--card2);font-size:13px;font-weight:600}
.tx-body{padding:8px 12px}
.tx-row{display:flex;justify-content:space-between;gap:10px;padding:3px 0;font-size:13px}
.tx-lbl{color:var(--mut);flex:0 0 auto}.tx-row span:last-child{text-align:right;word-break:break-word}
.tx-in{color:var(--in);font-weight:600}.tx-out{color:var(--out);font-weight:600}.tx-bal{color:var(--acc)}
details summary{cursor:pointer;color:var(--mut)}
pre{background:#050d16;border:1px solid var(--line);border-radius:8px;padding:8px;color:#9db2c2}
.nav{position:fixed;left:0;right:0;bottom:0;display:flex;justify-content:space-around;background:#08121d;border-top:1px solid var(--line);padding:10px 0 calc(10px + env(safe-area-inset-bottom))}
.nav a{color:var(--mut);text-decoration:none;font-size:12px;text-align:center;flex:1}
.nav a.on{color:var(--acc);font-weight:700}
</style>
</head>
<body>
<div class="app">
    <div class="hero">
        <h1>📦 Kartu Stok</h1>
        <p>Monitoring mutasi stok per gudang</p>
    </div>

    <div class="bar"><span id="pillNet" class="pill">online</span><span>Stock Card Mobile v2.0</span></div>

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
</body>
</html>