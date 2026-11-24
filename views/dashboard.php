<?php
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
// views/dashboard.php
// [REVISI 2026-11-24] PALETTE: #373647 #728E7E #949C8B #C2B8AD #B1A8A8
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../php-class/crudClass.php';
$db = new crud();
$user_id = $_SESSION['user_id'] ?? 0;
if (!function_exists('toRoman')) {
function toRoman($num) {
$romans = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'XIV', 'XV'];
return isset($romans[intval($num)]) ? $romans[intval($num)] : intval($num);
}
}
$mainMenus = [];
try {
$hsql = "SELECT id, upper(name) as name, seq FROM m_form_group WHERE active = true AND substring(seq,3,2)='00' AND substring(seq,1,2) IN (SELECT DISTINCT substring(g.seq,1,2) seqn FROM m_form_group g LEFT JOIN m_form_name f ON g.id = f.group_id LEFT JOIN m_user_access_menu u ON u.form_id = f.id WHERE g.active = true AND u.user_id = :user_id GROUP BY substring(g.seq,1,2)) ORDER BY seq::int";
$stmt = $db->prepare($hsql);
$stmt->execute(['user_id' => $user_id]);
$mainMenus = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Exception $e) { $mainMenus = []; }
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Dashboard - RAB Project Monitor</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<style>
/* ===== PALETTE =====
   --bg-deep:    #373647  (background utama)
   --bg-card:    #3f3e52  (card/panel)
   --bg-inner:   #4a4960  (inner card / hover)
   --bg-deeper:  #2e2d3d  (dropdown / header)
   --accent:     #728E7E  (sage green - primary)
   --accent-2:   #949C8B  (muted sage - secondary)
   --text-main:  #C2B8AD  (warm beige - teks utama)
   --text-sub:   #B1A8A8  (dusty mauve - teks sekunder)
*/
:root {
  --bg-deep: #373647;
  --bg-card: #3f3e52;
  --bg-inner: #4a4960;
  --bg-deeper: #2e2d3d;
  --accent: #728E7E;
  --accent-2: #949C8B;
  --text-main: #C2B8AD;
  --text-sub: #B1A8A8;
}
body { font-family: 'Inter', sans-serif; background-color: var(--bg-deep); color: var(--text-main); }
.font-orbitron { font-family: 'Orbitron', sans-serif; }
.font-rajdhani { font-family: 'Rajdhani', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

.nav-item { position: relative; }

.dropdown-menu {
position: absolute; top: 100%; left: 0; z-index: 60;
width: max-content; max-width: calc(100vw - 16px); box-sizing: border-box;
max-height: calc(100vh - 90px); overflow-y: auto;
opacity: 0; visibility: hidden; transform: translateY(-6px);
pointer-events: none;
transition: opacity .18s ease, transform .18s ease, visibility .18s;
}
.dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto; }
.dropdown-menu .menu-wrap { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0 2rem; }
.menu-column { flex: 0 0 auto; min-width: 180px; max-width: 220px; margin-bottom: 1rem; }
.menu-column h3 { font-size: 20px; font-weight: 500; color: var(--accent); text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid var(--accent); padding-bottom: 0.5rem; margin-bottom: 0.5rem; }
.menu-column ul { list-style: none; padding: 0; margin: 0; }
.menu-column ul li { margin-bottom: 0.25rem; }
.menu-column ul li a { display: block; padding: 0.2rem 0.4rem; color: var(--text-main); text-decoration: none; font-size: 16px; font-weight: 300; transition: all 0.2s; border-radius: 0.25rem; }
.menu-column ul li a:hover { color: #ffffff; background: rgba(194,184,173,0.15); transform: translateX(2px); }

#hamburger_btn { display: none; }
#mobile_dropdown { min-width: 220px; }

.tab-btn { background: var(--bg-inner); color: var(--text-main); font-weight:700; font-size:15px; padding:8px 20px; border:none; border-radius:4px; cursor:pointer; transition:background .15s, color .15s; }
.tab-btn:hover { background: #5a5970; color: #ffffff; }
.tab-btn.active { background: var(--accent); color: #ffffff; }
.tab-btn:focus-visible { outline:2px solid var(--accent-2); outline-offset:2px; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

@media (max-width: 768px) {
.desktop-menu { display: none !important; }
#header_logout { display: none !important; }
#hamburger_btn { display: flex !important; }
section h2 { font-size: 1.25rem !important; }
.tab-btn { padding: 6px 12px; font-size: 13px; }
}
.is-mobile-hw .desktop-menu { display: none !important; }
.is-mobile-hw #header_logout { display: none !important; }
.is-mobile-hw #hamburger_btn { display: flex !important; }
html, body { overflow-x: hidden; max-width: 100vw; }
</style>
<link rel="stylesheet" href="../css/app-theme.css">
<script>document.documentElement.setAttribute('data-theme', localStorage.getItem('app_theme') || 'dark');</script>
</head>
<?php $favVer = date('YmdHis'); ?>
<link rel="shortcut icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="apple-touch-icon" href="../favicon.ico?v=<?=$favVer?>">
<body class="dashboard-scope min-h-screen flex flex-col" style="background-color:#373647;">
<!-- HEADER -->
<header class="border-b sticky top-0 z-50 shadow-lg" style="background-color:#2e2d3d; border-color:#728E7E40;">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-14">
<div class="flex items-center space-x-6">
<a href="dashboard.php" class="flex items-center space-x-3 group">
<?php if (file_exists(__DIR__ . '/../assets/favicon.ico')): ?>
<img src="../assets/favicon.ico" alt="Logo" class="h-10 w-auto object-contain">
<?php else: ?>
<span class="font-arial text-xl font-normal tracking-wider" style="color:#C2B8AD;">[XYZ]SYS</span>
<span class="text-base px-2 py-0.5 rounded border" style="background:#373647; color:#728E7E; border-color:#728E7E80;">v1.0</span>
<?php endif; ?>
</a>
<!-- Desktop Menu -->
<ul class="desktop-menu flex items-center space-x-1 sm:space-x-3 font-rajdhani text-base tracking-wider font-semibold border-l pl-4" style="border-color:#728E7E40;">
<?php
$i = 0;
foreach ($mainMenus as $main): $i++;
$romanNum = toRoman($i);
$mainSeq = substr($main["seq"], 0, 2);
$csql = "SELECT id, upper(name) as name, seq FROM m_form_group WHERE active = true AND substring(seq,3,2)!='00' AND substring(seq,1,2) = :sub_seq AND id IN (SELECT DISTINCT g.id FROM m_form_group g LEFT JOIN m_form_name f ON g.id = f.group_id LEFT JOIN m_user_access_menu u ON u.form_id = f.id WHERE g.active = true AND f.active = true AND u.user_id = :user_id) ORDER BY seq::int";
$cStmt = $db->prepare($csql); $cStmt->execute(['sub_seq' => $mainSeq, 'user_id' => $user_id]);
$childGroups = $cStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$hasChildren = count($childGroups) > 0;
?>
<li class="nav-item h-14 flex items-center">
<a href="#" class="py-2 px-2.5 font-normal text-xl flex items-center gap-1 transition-all rounded" style="color:#C2B8AD;" onmouseover="this.style.color='#ffffff'; this.style.background='#4a4960';" onmouseout="this.style.color='#C2B8AD'; this.style.background='transparent';">
<?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
</a>
<?php if ($hasChildren): ?>
<div class="dropdown-menu border rounded-b-xl shadow-2xl p-4 text-xl font-normal" style="background-color:#3f3e52; border-color:#728E7E60; color:#C2B8AD;">
<div class="menu-wrap">
<?php foreach ($childGroups as $child):
$fsql = "SELECT f.id, upper(f.title) as title, f.php_url FROM m_form_name f LEFT JOIN m_user_access_menu u ON u.form_id = f.id WHERE f.active = true AND group_id = :group_id AND u.id IS NOT NULL AND u.user_id = :user_id ORDER BY seq::int";
$fStmt = $db->prepare($fsql); $fStmt->execute(['group_id' => $child["id"], 'user_id' => $user_id]);
$formItems = $fStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
?>
<div class="menu-column">
<h3><?= htmlspecialchars($child['name']); ?></h3>
<ul>
<?php foreach ($formItems as $item): ?>
<li><a href="router?id=<?= $item['id']; ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($item['title']); ?></a></li>
<?php endforeach; ?>
</ul>
</div>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>
</li>
<?php endforeach; ?>
</ul>
</div>
<!-- KANAN: user + logout (desktop) / hamburger (mobile) -->
<div class="flex items-center space-x-3 text-base">
<button id="theme_toggle_btn" type="button" onclick="(function(){var c=document.documentElement.getAttribute('data-theme')||'dark';var n=c==='dark'?'light':'dark';document.documentElement.setAttribute('data-theme',n);localStorage.setItem('app_theme',n);var l=document.getElementById('theme_lbl');if(l)l.textContent=n==='light'?'Dark':'Light';})()" class="px-2.5 py-1 rounded border text-sm font-semibold transition" style="background:#4a4960; border-color:#949C8B; color:#C2B8AD;" onmouseover="this.style.background='#5a5970';" onmouseout="this.style.background='#4a4960';">🌓 <span id="theme_lbl">Theme</span></button>
<span class="hidden sm:inline" style="color:#B1A8A8;">User: <strong style="color:#728E7E;"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></strong></span>
<a id="header_logout" href="logout.php" class="px-2.5 py-1 border rounded transition" style="background:#37364780; color:#B1A8A8; border-color:#B1A8A880;" onmouseover="this.style.background='#4a4960'; this.style.color='#ffffff';" onmouseout="this.style.background='#37364780'; this.style.color='#B1A8A8';">Logout</a>
<div class="relative">
<button id="hamburger_btn" class="items-center justify-center w-10 h-10 rounded-lg border transition text-slate-200" style="background:#728E7E30; border-color:#728E7E60; color:#C2B8AD;" onmouseover="this.style.background='#728E7E60';" onmouseout="this.style.background='#728E7E30';" aria-label="Menu" aria-haspopup="true" aria-expanded="false" onclick="toggleMobileMenu(event)">
<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div id="mobile_dropdown" class="hidden absolute right-0 top-full mt-2 w-60 border rounded-xl shadow-2xl py-2 z-50 font-rajdhani text-lg" style="background-color:#3f3e52; border-color:#728E7E60;">
<a href="project.progress.m.php" class="block px-4 py-2.5 border-b" style="color:#C2B8AD; border-color:#728E7E30;" onmouseover="this.style.background='#4a4960';" onmouseout="this.style.background='transparent';">📊 [M] Project Progress</a>
<a href="stock.card.m.php" class="block px-4 py-2.5 border-b" style="color:#C2B8AD; border-color:#728E7E30;" onmouseover="this.style.background='#4a4960';" onmouseout="this.style.background='transparent';">📦 Stock Card Mobile</a>
<a href="logout.php" class="block px-4 py-2.5" style="color:#B1A8A8;" onmouseover="this.style.background='#4a4960';" onmouseout="this.style.background='transparent';">🚪 Logout</a>
</div>
</div>
</div>
</div>
</div>
</header>
<!-- MAIN -->
<main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 space-y-6">
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center p-4 rounded-xl border gap-4" style="background-color:#3f3e52; border-color:#728E7E40;">
<div>
<h1 class="text-3xl font-medium tracking-wide font-rajdhani uppercase" style="color:#C2B8AD;">RAB-PROJECT MONITORING DASHBOARD</h1>
<p class="text-base" style="color:#B1A8A8;">Monitoring Proyek, Arus Kas, Inventory & Profitabilitas Real-time</p>
</div>
<div class="flex items-center space-x-3 w-full sm:w-auto">
<label for="project_select" class="text-base whitespace-nowrap" style="color:#B1A8A8;">Filter Proyek:</label>
<select id="project_select" onchange="onProjectChange(this.value)" class="border text-base rounded-lg p-2 focus:ring-2 w-full sm:w-64" style="background-color:#4a4960; border-color:#728E7E60; color:#C2B8AD;" onfocus="this.style.borderColor='#728E7E';" onblur="this.style.borderColor='#728E7E60';">
<option value="0">Loading proyek...</option>
</select>
</div>
</div>
<div id="tab_bar" class="flex flex-wrap gap-2 p-3 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;" role="tablist">
<button type="button" class="tab-btn active" id="tabbtn_ringkasan" data-tab="ringkasan">Ringkasan Proyek</button>
<button type="button" class="tab-btn" id="tabbtn_keuangan" data-tab="keuangan">Keuangan</button>
<button type="button" class="tab-btn" id="tabbtn_material" data-tab="material">Material &amp; RAB</button>
</div>
<!-- TAB 1: RINGKASAN -->
<section id="tab_ringkasan" class="tab-panel active space-y-6">
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;" id="health_panel">
<div class="pb-3 border-b mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">🩺 Kesehatan Proyek (Progres vs Pemakaian Biaya)</h2>
<span class="text-[11px]" id="health_legend" style="color:#B1A8A8;">Klik kartu untuk analisis AI</span>
</div>
<div id="health_cards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 max-h-96 overflow-y-auto no-scrollbar">
<div class="text-center text-xs py-4 col-span-full" style="color:#B1A8A8;">Memuat kesehatan proyek...</div>
</div>
<div id="health_reco" class="mt-3"></div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#B1A8A8;">⚡ Action Center (Butuh Tindakan)</h2>
</div>
<ul class="space-y-2 text-sm max-h-72 overflow-y-auto no-scrollbar" id="action_list">
<li class="text-center text-xs py-4" style="color:#B1A8A8;">Memuat indikator...</li>
</ul>
</div>
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">🎯 Milestone Task Proyek (14 Hari &amp; Terlambat)</h2>
</div>
<div class="space-y-2 max-h-48 overflow-y-auto no-scrollbar" id="milestone_list">
<div class="text-center text-xs py-4" style="color:#B1A8A8;">Memuat milestone...</div>
</div>
</div>
</div>
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">📊 Profitabilitas Proyek (Biaya Riil vs Tertagih)</h2>
</div>
<div class="overflow-x-auto max-h-64 no-scrollbar">
<table class="w-full text-left text-xs">
<thead class="border-b sticky top-0" style="color:#B1A8A8; border-color:#728E7E40; background-color:#3f3e52;">
<tr><th class="pb-2">Proyek</th><th class="pb-2 text-right">Progres</th><th class="pb-2 text-right">Kontrak (DPP)</th><th class="pb-2 text-right">Tertagih (DPP)</th><th class="pb-2 text-right">Biaya Riil</th><th class="pb-2 text-right">Biaya/Kontrak</th><th class="pb-2 text-right">Margin</th></tr>
</thead>
<tbody id="profit_tbody" class="divide-y font-mono" style="color:#C2B8AD;">
<tr><td colspan="7" class="py-4 text-center text-xs" style="color:#B1A8A8;">Memuat...</td></tr>
</tbody>
</table>
</div>
</div>
</section>
<!-- TAB 2: KEUANGAN -->
<section id="tab_keuangan" class="tab-panel space-y-6">
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#C2B8AD;">Financial Aging Analysis (Umur Piutang & Hutang)</h2>
</div>
<div class="overflow-x-auto no-scrollbar">
<table class="w-full text-left text-[11px]">
<thead><tr class="border-b font-semibold text-[10px] uppercase tracking-wider" style="color:#B1A8A8; border-color:#728E7E40;">
<th class="pb-2">Kategori</th><th class="pb-2 text-right">&lt;30 HARI</th><th class="pb-2 text-right">31-60 HARI</th><th class="pb-2 text-right">61-90 HARI</th><th class="pb-2 text-right">&gt;90 HARI</th><th class="pb-2 text-right">TOTAL</th>
</tr></thead>
<tbody id="aging_tbody" class="divide-y font-mono text-[11px]" style="color:#C2B8AD;">
<tr><td colspan="6" class="py-4 text-center font-sans" style="color:#B1A8A8;">Memuat data aging...</td></tr>
</tbody>
</table>
</div>
</div>
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#C2B8AD;">💰 Invoice Pipeline & Proyeksi Kas Masuk</h2>
</div>
<div class="grid grid-cols-2 gap-3">
<div class="p-3 rounded border-l-4" style="background-color:#4a4960; border-left-color:#B1A8A8;"><div class="text-[10px] uppercase" style="color:#B1A8A8;">Jatuh Tempo (Overdue)</div><div class="text-lg font-bold font-mono" id="pip_overdue_val" style="color:#B1A8A8;">Rp 0</div><div class="text-[10px]" id="pip_overdue_cnt" style="color:#B1A8A8;">0 invoice</div></div>
<div class="p-3 rounded border-l-4" style="background-color:#4a4960; border-left-color:#728E7E;"><div class="text-[10px] uppercase" style="color:#B1A8A8;">Belum Bayar (Aktif)</div><div class="text-lg font-bold font-mono" id="pip_unpaid_val" style="color:#728E7E;">Rp 0</div><div class="text-[10px]" id="pip_unpaid_cnt" style="color:#B1A8A8;">0 invoice</div></div>
<div class="p-3 rounded border-l-4" style="background-color:#4a4960; border-left-color:#C2B8AD;"><div class="text-[10px] uppercase" style="color:#B1A8A8;">Terbayar Sebagian</div><div class="text-lg font-bold font-mono" id="pip_partial_val" style="color:#C2B8AD;">Rp 0</div><div class="text-[10px]" id="pip_partial_cnt" style="color:#B1A8A8;">0 invoice</div></div>
<div class="p-3 rounded border-l-4" style="background-color:#4a4960; border-left-color:#949C8B;"><div class="text-[10px] uppercase" style="color:#B1A8A8;">Lunas (Closed)</div><div class="text-lg font-bold font-mono" id="pip_paid_cnt" style="color:#949C8B;">0 invoice</div><div class="text-[10px]" style="color:#B1A8A8;">Selesai</div></div>
</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4" id="extra_row">
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#728E7E;">
<div class="text-base uppercase font-bold tracking-wider" style="color:#B1A8A8;">🏦 Saldo Kas &amp; Bank</div>
<div class="text-xl font-bold font-mono mt-2" id="extra_cash" style="color:#728E7E;">Rp 0</div>
<div class="text-[10px] mt-1" style="color:#949C8B;">Dari jurnal terposting (semua proyek)</div>
</div>
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#C2B8AD;">
<div class="text-base uppercase font-bold tracking-wider" style="color:#B1A8A8;">🧾 PPN Bulan Ini <span id="extra_ppn_period" class="text-xs"></span></div>
<div class="text-xl font-bold font-mono mt-2" id="extra_ppn">Rp 0</div>
<div class="text-[10px] mt-1" id="extra_ppn_sub" style="color:#C2B8AD;">Keluaran - Masukan</div>
</div>
<div class="p-4 rounded-xl border-l-4" id="extra_subcon_card" style="background-color:#4a4960; border-left-color:#949C8B;">
<div class="text-base uppercase font-bold tracking-wider" style="color:#B1A8A8;">👷 Subkontraktor</div>
<div class="text-xl font-bold font-mono mt-2" id="extra_subcon" style="color:#949C8B;">Rp 0</div>
<div class="text-[10px] mt-1" id="extra_subcon_sub" style="color:#949C8B;">Sisa hutang prestasi</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="rounded-xl border p-4" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="flex justify-between items-center pb-3 border-b mb-3" style="border-color:#728E7E40;">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">Performa Keuangan (Bulanan)</h2>
<select id="fin_year_select" onchange="loadFinancialPerformance()" class="border text-[10px] rounded px-2 py-0.5" style="background-color:#4a4960; border-color:#728E7E60; color:#C2B8AD;">
<option value="2026" selected>2026</option><option value="2025">2025</option>
</select>
</div>
<div class="relative w-full h-52">
<canvas id="finChartCanvas"></canvas>
<div id="fin_empty" class="hidden absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] font-sans pointer-events-none" style="color:#B1A8A8;"></div>
</div>
</div>
<div class="rounded-xl border p-4" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 border-b mb-3 gap-2" style="border-color:#728E7E40;">
<div>
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">Rate Kurs Pajak (KMK)</h2>
<div class="flex items-center gap-2 mt-1">
<span id="kmk_badge" class="text-[9px] border px-1.5 py-0.5 rounded inline-block" style="background:#373647; color:#728E7E; border-color:#728E7E80;">Memuat...</span>
<span id="kmk_valid_date" class="text-[11px]" style="color:#C2B8AD;"></span>
</div>
</div>
<div class="flex items-center space-x-2 w-full sm:w-auto">
<div class="relative flex-1 sm:w-44">
<input type="text" id="search_tax_currency" onkeyup="filterTaxRates()" placeholder="Cari mata uang..." class="w-full border text-[11px] rounded px-2.5 py-1 pl-7 focus:outline-none placeholder-slate-500" style="background-color:#4a4960; border-color:#728E7E60; color:#C2B8AD;" onfocus="this.style.borderColor='#728E7E';" onblur="this.style.borderColor='#728E7E60';">
<svg class="w-3.5 h-3.5 absolute left-2 top-1.5" style="color:#B1A8A8;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
</div>
<button id="btn_sync_tax" onclick="syncTaxRates()" class="text-[10px] text-white px-2 py-1 rounded transition whitespace-nowrap" style="background-color:#728E7E;" onmouseover="this.style.background='#5a7264';" onmouseout="this.style.background='#728E7E';">Update Kurs</button>
</div>
</div>
<div class="max-h-56 overflow-y-auto no-scrollbar">
<table class="w-full text-left text-[13px]">
<thead class="text-cyan-300 sticky top-0" style="background-color:#4a4960; color:#C2B8AD;">
<tr><th class="p-1.5">Mata Uang</th><th class="p-1.5 text-right">Kurs (IDR)</th><th class="p-1.5 text-center">Ubah</th></tr>
</thead>
<tbody id="tax_rate_tbody" class="divide-y text-[12px]" style="color:#C2B8AD;">
<tr><td colspan="3" class="p-3 text-center" style="color:#B1A8A8;">Memuat data kurs pajak...</td></tr>
</tbody>
</table>
</div>
</div>
</div>
</section>
<!-- TAB 3: MATERIAL -->
<section id="tab_material" class="tab-panel space-y-6">
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#728E7E;">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color:#B1A8A8;">
<svg class="w-4 h-4 flex-shrink-0" style="color:#728E7E;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
<span>Total Budget RAB</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_total_budget" style="color:#C2B8AD;">Rp 0</div>
<div class="text-[10px] mt-1" style="color:#949C8B;">Alokasi Anggaran Disetujui</div>
</div>
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#949C8B;">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color:#B1A8A8;">
<svg class="w-4 h-4 flex-shrink-0" style="color:#949C8B;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
<span>Material Out (Realisasi)</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_material_out" style="color:#949C8B;">Rp 0</div>
<div class="text-[10px] mt-1" style="color:#949C8B;">Total Pemakaian Material</div>
</div>
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#C2B8AD;">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color:#B1A8A8;">
<svg class="w-4 h-4 flex-shrink-0" style="color:#C2B8AD;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
<span>Sisa Budget</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_sisa_budget" style="color:#C2B8AD;">Rp 0</div>
<div class="text-[10px] mt-1" style="color:#C2B8AD;">Sisa Anggaran Material</div>
</div>
<div class="p-4 rounded-xl border-l-4" style="background-color:#4a4960; border-left-color:#B1A8A8;">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color:#B1A8A8;">
<svg class="w-4 h-4 flex-shrink-0" style="color:#B1A8A8;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
<span>Persentase Penyerapan</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_usage_pct" style="color:#B1A8A8;">0.00%</div>
<div class="text-[10px] mt-1" id="kpi_status_label" style="color:#B1A8A8;">Status Penyerapan</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<div class="lg:col-span-4 p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<h3 class="text-base font-bold uppercase tracking-wider mb-2 font-rajdhani text-center" style="color:#728E7E;">Distribusi Status Penyerapan</h3>
<div class="relative w-full h-56 flex items-center justify-center"><canvas id="doughnutChartCanvas"></canvas></div>
</div>
<div class="lg:col-span-8 p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<h3 class="text-base font-bold uppercase tracking-wider mb-2 font-rajdhani" style="color:#728E7E;">Top Material Out vs Budget Qty</h3>
<div class="relative w-full h-56"><canvas id="barMaterialCanvas"></canvas></div>
</div>
</div>
<div class="p-4 rounded-xl border" style="background-color:#3f3e52; border-color:#728E7E40;">
<div class="flex justify-between items-center mb-4">
<h3 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color:#728E7E;">Rincian Penggunaan Material Out Proyek</h3>
<span class="text-base" id="table_count_label" style="color:#B1A8A8;">Total Material: 0 Item</span>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead class="text-cyan-300" style="background-color:#4a4960; color:#C2B8AD;">
<tr>
<th class="p-3 text-base">Nama / Kode Material</th>
<th class="p-3 text-base text-center">Satuan</th>
<th class="p-3 text-base text-right">Budget Qty</th>
<th class="p-3 text-base text-right">Total Budget</th>
<th class="p-3 text-base text-right">Material Out</th>
<th class="p-3 text-base text-right">Total Realisasi</th>
<th class="p-3 text-base text-right">Sisa Qty</th>
<th class="p-3 text-base text-center">% Terpakai</th>
<th class="p-3 text-base text-center">Status</th>
</tr>
</thead>
<tbody id="material_tbody" class="divide-y font-mono" style="color:#C2B8AD;">
<tr><td colspan="9" class="p-4 text-center" style="color:#B1A8A8;">Memuat data material proyek...</td></tr>
</tbody>
</table>
</div>
</div>
</section>
</main>
<!-- JAVASCRIPT -->
<script>
const API_ENDPOINT = '../php-crud/dashboardCRD.php';
let currentProjectId = 0;
let finChart = null, doughnutChart = null, barMaterialChart = null;
let globalTaxRates = [];
const formatIDR = (num) => 'Rp ' + Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatNum = (num) => Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
async function apiFetch(url, opts = {}) {
const res = await fetch(url, opts);
if (res.status === 401) { alert('Sesi berakhir. Silakan login kembali.'); location.href = 'login.php'; throw new Error('401'); }
if (!res.ok) throw new Error("HTTP " + res.status + " " + res.statusText);
let json;
try { json = await res.json(); } catch (e) { throw new Error("Respons bukan JSON dari server."); }
if (json === null || typeof json !== 'object') throw new Error("Format respons server tidak valid.");
return json;
}
/* MOBILE: hamburger + deteksi perangkat */
function toggleMobileMenu(ev) {
if (ev) ev.stopPropagation();
const m = document.getElementById('mobile_dropdown');
const b = document.getElementById('hamburger_btn');
m.classList.toggle('hidden');
b.setAttribute('aria-expanded', String(!m.classList.contains('hidden')));
}
document.addEventListener('click', function (event) {
const menu = document.getElementById('mobile_dropdown');
const btn = document.getElementById('hamburger_btn');
if (!menu || !btn) return;
if (!menu.classList.contains('hidden') && !menu.contains(event.target) && !btn.contains(event.target)) {
menu.classList.add('hidden');
btn.setAttribute('aria-expanded', 'false');
}
});
document.querySelectorAll('#mobile_dropdown a').forEach(a => a.addEventListener('click', () => document.getElementById('mobile_dropdown').classList.add('hidden')));
if (navigator.maxTouchPoints > 0 || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
document.body.classList.add('is-mobile-hw');
}
/* MEGA MENU */
function closeAllDropdowns(except) {
document.querySelectorAll('.dropdown-menu.open').forEach(d => { if (d !== except) d.classList.remove('open'); });
}
function positionDropdown(li, dd) {
dd.style.left = '0px';
const rect = dd.getBoundingClientRect();
const vw = document.documentElement.clientWidth;
let shift = 0;
if (rect.right > vw - 8) shift = (vw - 8) - rect.right;
if (rect.left + shift < 8) shift = 8 - rect.left;
dd.style.left = shift + 'px';
}
function initMegaMenu() {
document.querySelectorAll('.nav-item').forEach(li => {
const dd = li.querySelector('.dropdown-menu');
if (!dd) return;
li.addEventListener('mouseenter', () => { closeAllDropdowns(dd); dd.classList.add('open'); positionDropdown(li, dd); });
li.addEventListener('mouseleave', () => dd.classList.remove('open'));
li.addEventListener('focusin',  () => { closeAllDropdowns(dd); dd.classList.add('open'); positionDropdown(li, dd); });
li.addEventListener('focusout', (e) => { if (!li.contains(e.relatedTarget)) dd.classList.remove('open'); });
});
document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeAllDropdowns(null); });
}
document.addEventListener('DOMContentLoaded', () => { initDashboard(); });
/* TAB ENGINE */
const TAB_LOADERS = {
ringkasan: () => { loadProjectHealth(); loadActionCenter(); loadMilestones(); loadProfitability(); },
keuangan:  () => { if (!globalTaxRates.length) loadTaxRates(); loadFinancialAging(); loadInvoicePipeline(); loadExtra(); loadFinancialPerformance(); },
material:  () => { loadMaterialMonitoring(); }
};
const TAB_CHARTS = { keuangan: () => [finChart], material: () => [doughnutChart, barMaterialChart] };
const tabDirty = { ringkasan: true, keuangan: true, material: true };
let activeTab = 'ringkasan';
function tabFromHash() {
const h = (location.hash || '').replace('#', '');
return TAB_LOADERS[h] ? h : 'ringkasan';
}
function switchTab(name, skipHash) {
if (!TAB_LOADERS[name]) name = 'ringkasan';
activeTab = name;
document.querySelectorAll('.tab-btn').forEach(b => {
const on = b.getAttribute('data-tab') === name;
b.classList.toggle('active', on);
b.setAttribute('aria-selected', on ? 'true' : 'false');
b.tabIndex = on ? 0 : -1;
});
document.querySelectorAll('.tab-panel').forEach(p => p.classList.toggle('active', p.id === 'tab_' + name));
if (!skipHash && location.hash !== '#' + name) history.replaceState(null, '', '#' + name);
if (tabDirty[name]) {
tabDirty[name] = false;
TAB_LOADERS[name]();
} else if (TAB_CHARTS[name]) {
TAB_CHARTS[name]().forEach(c => { if (c) c.resize(); });
}
}
function refreshDashboard() {
Object.keys(tabDirty).forEach(k => { tabDirty[k] = true; });
switchTab(activeTab, true);
}
document.querySelectorAll('.tab-btn').forEach(btn => {
btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
});
window.addEventListener('hashchange', () => { const t = tabFromHash(); if (t !== activeTab) switchTab(t, true); });
async function initDashboard() {
await loadProjects();
initMegaMenu();
switchTab(tabFromHash(), true);
}
async function loadProjects() {
try {
const json = await apiFetch(`${API_ENDPOINT}?action=get_projects`);
const select = document.getElementById('project_select');
if (json.success && json.data.length > 0) {
select.innerHTML = '<option value="0">-- Semua Proyek --</option>' + json.data.map(p => `<option value="${p.id}">${p.project_name}</option>`).join('');
} else { select.innerHTML = '<option value="0">Tidak ada proyek</option>'; }
} catch (err) { console.error('Error load projects:', err); }
}
function onProjectChange(val) {
currentProjectId = parseInt(val) || 0;
refreshDashboard();
}
/* LOADERS */
async function loadFinancialAging() {
const tbody = document.getElementById('aging_tbody');
const showMsg = (msg) => { tbody.innerHTML = `<tr><td colspan="6" class="p-3 text-center font-sans" style="color:#B1A8A8;">${msg}</td></tr>`; };
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_financial_aging&project_id=${currentProjectId}`);
if (!json.success) { showMsg(json.message || 'Gagal memuat data aging.'); return; }
const ar = json.ar, ap = json.ap;
const row = (label, labelColor, d, totalColor) => `
<tr class="hover:bg-[#4a4960]/50 transition">
<td class="py-2.5 font-sans font-semibold" style="color:${labelColor};">${label}<span class="block text-[9px] font-normal" style="color:#B1A8A8;">${d.count} invoice outstanding</span></td>
<td class="py-2.5 text-right" style="color:#C2B8AD;">${formatNum(d.age_30)}</td>
<td class="py-2.5 text-right font-medium" style="color:#C2B8AD;">${formatNum(d.age_60)}</td>
<td class="py-2.5 text-right font-medium" style="color:#C2B8AD;">${formatNum(d.age_90)}</td>
<td class="py-2.5 text-right font-semibold" style="color:#B1A8A8;">${formatNum(d.age_over_90)}</td>
<td class="py-2.5 text-right font-bold" style="color:${totalColor};">${formatNum(d.total)}</td>
</tr>`;
const net = (k) => (ar[k] || 0) - (ap[k] || 0);
tbody.innerHTML = row('Piutang (AR)', '#728E7E', ar, '#728E7E') + row('Hutang (AP)', '#949C8B', ap, '#C2B8AD') + `
<tr class="border-t" style="border-color:#728E7E40;"><td class="py-2.5 font-sans font-semibold" style="color:#C2B8AD;">Netto (AR - AP)</td>
<td class="py-2.5 text-right" style="color:#C2B8AD;">${formatNum(net('age_30'))}</td>
<td class="py-2.5 text-right" style="color:#C2B8AD;">${formatNum(net('age_60'))}</td>
<td class="py-2.5 text-right" style="color:#C2B8AD;">${formatNum(net('age_90'))}</td>
<td class="py-2.5 text-right" style="color:#C2B8AD;">${formatNum(net('age_over_90'))}</td>
<td class="py-2.5 text-right font-bold" style="color:#C2B8AD;">${formatNum(net('total'))}</td></tr>
<tr><td colspan="6" class="pt-2 text-right text-[9px] font-sans" style="color:#B1A8A8;">Umur dihitung dari tanggal invoice, per ${json.as_of}</td></tr>`;
} catch (err) { console.error('Error load financial aging:', err); showMsg('Gagal memuat data aging.'); }
}
async function loadInvoicePipeline() {
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_invoice_pipeline&project_id=${currentProjectId}`);
if (json.success) {
const d = json.data;
document.getElementById('pip_overdue_val').innerText = fmtRp(d.values['Jatuh Tempo (Overdue)']);
document.getElementById('pip_overdue_cnt').innerText = d.counts['Jatuh Tempo (Overdue)'] + ' invoice';
document.getElementById('pip_unpaid_val').innerText = fmtRp(d.values['Belum Bayar']);
document.getElementById('pip_unpaid_cnt').innerText = d.counts['Belum Bayar'] + ' invoice';
document.getElementById('pip_partial_val').innerText = fmtRp(d.values['Sebagian']);
document.getElementById('pip_partial_cnt').innerText = d.counts['Sebagian'] + ' invoice';
document.getElementById('pip_paid_cnt').innerText = d.counts['Lunas'] + ' invoice';
}
} catch (err) { console.error('Error load invoice pipeline:', err); }
}
const LEVEL_STYLE = { danger: 'border-rose-500 text-rose-200', warn: 'border-amber-500 text-amber-200', info: 'border-cyan-500 text-cyan-200' };
async function loadActionCenter() {
const ul = document.getElementById('action_list');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_action_center&project_id=${currentProjectId}`);
if (!json.success) { ul.innerHTML = `<li class="text-center text-xs py-3" style="color:#B1A8A8;">${esc(json.message || 'Gagal memuat Action Center')}</li>`; return; }
const order = { danger: 0, warn: 1, info: 2 };
const active = json.items.filter(i => i.count > 0).sort((a, b) => (order[a.level] - order[b.level]) || (b.count - a.count));
const calm = json.items.length - active.length;
const levelBorder = { danger: '#B1A8A8', warn: '#C2B8AD', info: '#728E7E' };
const levelText = { danger: '#B1A8A8', warn: '#C2B8AD', info: '#728E7E' };
let html = active.map(i => `
<li class="flex justify-between items-center p-2 rounded border-l-4" style="background-color:#4a4960; border-left-color:${levelBorder[i.level] || '#728E7E'}; color:${levelText[i.level] || '#728E7E'};" title="${esc(i.hint)}">
<span>${esc(i.label)}<span class="block text-[10px] opacity-60">${esc(i.hint)}</span></span>
<span class="font-bold font-mono text-lg">${i.count}</span>
</li>`).join('');
if (!active.length) html = '<li class="text-center text-sm py-4" style="color:#728E7E;">✔ Tidak ada yang perlu ditindaklanjuti.</li>';
else if (calm > 0) html += `<li class="text-center text-[11px] pt-1" style="color:#B1A8A8;">✔ ${calm} indikator lain aman</li>`;
ul.innerHTML = html;
} catch (err) { console.error('Error load action center:', err); ul.innerHTML = '<li class="text-center text-xs py-3" style="color:#B1A8A8;">Error memuat Action Center</li>'; }
}
async function loadMilestones() {
const list = document.getElementById('milestone_list');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_upcoming_milestones&project_id=${currentProjectId}`);
if (!json.success) { list.innerHTML = `<div class="text-center text-xs py-4" style="color:#B1A8A8;">${esc(json.message || 'Error memuat milestone')}</div>`; return; }
if (json.data.length > 0) {
list.innerHTML = json.data.map(t => {
const late = t.late === true;
const info = late ? `Terlambat ${Math.abs(t.days_left)} hari` : (t.days_left === 0 ? 'Hari ini' : `${t.days_left} hari lagi`);
const border = late ? '#B1A8A8' : '#728E7E';
const textColor = late ? '#B1A8A8' : '#C2B8AD';
const subColor = late ? '#B1A8A8' : '#B1A8A8';
return `
<div class="flex justify-between items-center p-2 rounded border-l-2" style="background-color:#4a4960; border-left-color:${border};">
<div><div class="font-bold text-xs" style="color:#C2B8AD;">${esc(t.task_name)}</div><div class="text-[10px]" style="color:#B1A8A8;">${esc(t.project_name)}</div></div>
<div class="text-right"><div class="font-mono text-xs font-bold" style="color:${textColor};">${esc(t.deadline)}</div><div class="text-[9px]" style="color:${subColor};">${esc(t.status)} - ${info}</div></div>
</div>`;
}).join('');
} else { list.innerHTML = '<div class="text-center text-xs py-4" style="color:#B1A8A8;">Tidak ada milestone terdekat.</div>'; }
} catch (err) { list.innerHTML = '<div class="text-center text-xs py-4" style="color:#B1A8A8;">Error memuat milestone</div>'; }
}
async function loadProfitability() {
const tbody = document.getElementById('profit_tbody');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_project_profitability&project_id=${currentProjectId}`);
if (!json.success) { tbody.innerHTML = `<tr><td colspan="7" class="py-4 text-center text-xs" style="color:#B1A8A8;">${esc(json.message || 'Error memuat data')}</td></tr>`; return; }
if (json.data.length > 0) {
tbody.innerHTML = json.data.map(p => {
const hasMargin = p.margin_pct !== null && p.margin_pct !== undefined;
const margin = hasMargin ? parseFloat(p.margin_pct) : 0;
let mColor = '#B1A8A8';
if (hasMargin) {
if (margin < 10) mColor = '#B1A8A8';
else if (margin < 20) mColor = '#C2B8AD';
else mColor = '#728E7E';
}
const cPct = parseFloat(p.cost_pct) || 0;
let cColor = '#C2B8AD';
if (cPct > 100) cColor = '#B1A8A8';
else if (cPct > 85) cColor = '#C2B8AD';
const detail = `Material ${fmtRp(p.cost_material)} | Pengeluaran ${fmtRp(p.cost_expense)} | Subkon ${fmtRp(p.cost_subcon)}`;
return `<tr class="hover:bg-[#4a4960]/50"><td class="py-2 font-sans text-xs" style="color:#C2B8AD;">${esc(p.name)}</td>
<td class="py-2 text-right" style="color:#728E7E;">${p.progress}%</td>
<td class="py-2 text-right" style="color:#C2B8AD;">${fmtRp(p.contract_dpp)}</td>
<td class="py-2 text-right" style="color:#C2B8AD;">${fmtRp(p.billed_dpp)}</td>
<td class="py-2 text-right" style="color:#C2B8AD;" title="${esc(detail)}">${fmtRp(p.cost_total)}</td>
<td class="py-2 text-right" style="color:${cColor};">${cPct.toFixed(1)}%</td>
<td class="py-2 text-right font-bold" style="color:${mColor};">${hasMargin ? margin.toFixed(1) + '%' : '-'}</td></tr>`;
}).join('');
} else { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-xs" style="color:#B1A8A8;">Tidak ada data profitabilitas.</td></tr>'; }
} catch (err) { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-xs" style="color:#B1A8A8;">Error memuat data</td></tr>'; }
}
const HEALTH_STYLE = {
KRITIS:  { badge: 'bg-rose-950 text-rose-300 border-rose-800', icon: '🔴', label: 'KRITIS', num: 'text-rose-400', bar: 'bg-rose-500' },
WASPADA: { badge: 'bg-amber-950 text-amber-300 border-amber-800', icon: '🟡', label: 'WASPADA', num: 'text-yellow-300', bar: 'bg-yellow-400' },
NORMAL:  { badge: 'bg-emerald-950 text-emerald-300 border-emerald-800', icon: '🟢', label: 'NORMAL', num: 'text-emerald-400', bar: 'bg-emerald-500' },
NA:      { badge: 'bg-slate-800 text-slate-300 border-slate-600', icon: '⚪', label: 'BUDGET KOSONG', num: 'text-slate-400', bar: 'bg-slate-500' }
};
const HEALTH_ICONS = ['🏗️', '🏢', '🏘️', '🏭', '🏠'];
const RISK_STYLE = {
HIGH:   { badge: 'bg-rose-950 text-rose-300 border-rose-800', text: 'text-rose-300' },
MEDIUM: { badge: 'bg-amber-950 text-amber-300 border-amber-800', text: 'text-amber-300' },
LOW:    { badge: 'bg-emerald-950 text-emerald-300 border-emerald-800', text: 'text-emerald-300' }
};
const SEV_STYLE = { high: 'border-rose-600 text-rose-200', medium: 'border-amber-600 text-amber-200', low: 'border-cyan-700 text-cyan-200' };
let healthSelectedId = 0, aiRequestToken = 0;
const LEVEL_RANK = { NA: 0, NORMAL: 0, WASPADA: 1, KRITIS: 2 };
function applyAiRiskToCard(id, aiRisk) {
const card = document.querySelector(`#health_cards [data-pid="${id}"]`);
if (!card) return;
const target = (aiRisk === 'HIGH' || aiRisk === 'CRITICAL') ? 'KRITIS' : (aiRisk === 'MEDIUM' ? 'WASPADA' : null);
const cur = card.getAttribute('data-level') || 'NORMAL';
if (!target || LEVEL_RANK[target] <= (LEVEL_RANK[cur] || 0)) return;
const st = HEALTH_STYLE[target], badge = card.querySelector('.health-badge');
badge.className = `health-badge px-2 py-0.5 text-[11px] font-bold border rounded-md whitespace-nowrap ${st.badge}`;
badge.innerHTML = `${st.icon} ${st.label} • AI`;
card.querySelectorAll('.health-num').forEach(el => { el.className = `health-num ${st.num}`; });
card.setAttribute('data-level', target);
}
function selectHealthProject(id, refresh) {
healthSelectedId = id;
document.querySelectorAll('#health_cards [data-pid]').forEach(el => {
const on = parseInt(el.getAttribute('data-pid'), 10) === id;
el.classList.toggle('ring-2', on); el.classList.toggle('ring-cyan-400', on);
});
loadProjectAI(id, !!refresh);
}
async function loadProjectAI(id, refresh) {
const box = document.getElementById('health_reco');
const token = ++aiRequestToken;
const card = document.querySelector(`#health_cards [data-pid="${id}"]`);
const pname = card ? card.getAttribute('data-name') : '';
box.innerHTML = `<div class="border rounded-lg p-4 text-sm animate-pulse" style="background-color:#4a496040; border-color:#728E7E60; color:#C2B8AD;">🧠 AI sedang menganalisis <b>${esc(pname)}</b> (biaya, material, subkon, jadwal, penagihan)...</div>`;
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_project_ai&project_id=${id}&refresh=${refresh ? 1 : 0}`);
if (token !== aiRequestToken) return;
if (!json.success) { box.innerHTML = `<div class="border rounded-lg p-3 text-sm" style="background-color:#4a496040; border-color:#B1A8A860; color:#B1A8A8;">${esc(json.message || 'Gagal memuat rekomendasi AI')}</div>`; return; }
const d = json.data, rs = RISK_STYLE[d.risk] || RISK_STYLE.LOW;
const li = (arr) => arr.map(t => `<li>${esc(t)}</li>`).join('');
const findings = (d.findings || []).map(f => `
<div class="border-l-4 bg-[#4a4960] rounded px-2 py-1 mb-1" style="border-left-color:#728E7E;">
<div class="text-xs font-bold" style="color:#C2B8AD;">${esc(f.title)}</div><div class="text-[11px] opacity-80" style="color:#B1A8A8;">${esc(f.evidence)}</div></div>`).join('');
box.innerHTML = `
<div class="border rounded-lg p-4 max-h-96 overflow-y-auto no-scrollbar" style="background-color:#4a496040; border-color:#728E7E60;">
<div class="flex flex-wrap items-center gap-2 mb-2">
<span class="font-bold text-base" style="color:#728E7E;">🧠 AI Recommendation</span>
<span class="px-2 py-0.5 text-[11px] font-bold border rounded-md" style="background:#373647; color:#B1A8A8; border-color:#B1A8A880;">RISK: ${esc(d.risk)}</span>
<span class="px-2 py-0.5 text-[10px] border rounded-md" style="border-color:#949C8B80; color:#949C8B;">${d.ai_used ? 'Gemini' : 'Analisis sistem'}${d.cached ? ' • cache' : ''}</span>
<span class="text-[11px] ml-auto" style="color:#B1A8A8;">${esc(d.generated_at || '')}${d.log_id ? ' • log #' + d.log_id : ''}</span>
<button type="button" class="aiRefresh px-3 py-1 text-[11px] font-bold rounded text-white cursor-pointer border-0" style="background-color:#728E7E;">Analisis Ulang</button>
</div>
<div class="italic text-base mb-2" style="color:#C2B8AD;"><b>${esc(d.project_name)}:</b> ${esc(d.headline)}</div>
<div class="text-sm mb-2 leading-relaxed" style="color:#C2B8AD;">${esc(d.summary)}</div>
${d.actions.length ? `<div class="text-xs font-bold mt-2 mb-1" style="color:#728E7E;">Tindakan yang disarankan</div><ul class="list-disc pl-5 text-sm space-y-1" style="color:#C2B8AD;">${li(d.actions)}</ul>` : ''}
${d.questions && d.questions.length ? `<div class="text-xs font-bold mt-3 mb-1" style="color:#C2B8AD;">Perlu dikonfirmasi</div><ul class="list-disc pl-5 text-sm space-y-1" style="color:#C2B8AD;">${li(d.questions)}</ul>` : ''}
${findings ? `<div class="text-xs font-bold mt-3 mb-1" style="color:#B1A8A8;">Temuan sistem (${d.findings.length})</div>${findings}` : ''}
${d.ai_note ? `<div class="text-[11px] mt-2" style="color:#C2B8AD;">${esc(d.ai_note)}</div>` : ''}
</div>`;
box.querySelector('.aiRefresh').onclick = () => loadProjectAI(id, true);
applyAiRiskToCard(id, d.risk);
} catch (err) { if (token === aiRequestToken) box.innerHTML = '<div class="border rounded-lg p-3 text-sm" style="background-color:#4a496040; border-color:#B1A8A860; color:#B1A8A8;">Error memuat rekomendasi AI</div>'; }
}
async function loadProjectHealth() {
const box = document.getElementById('health_cards'), reco = document.getElementById('health_reco');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_project_health&project_id=${currentProjectId}`);
if (!json.success) { box.innerHTML = `<div class="text-center text-xs py-4 col-span-full" style="color:#B1A8A8;">${esc(json.message || 'Gagal memuat kesehatan proyek')}</div>`; reco.innerHTML = ''; return; }
if (!json.data.length) { box.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color:#B1A8A8;">Tidak ada proyek berstatus Berjalan.</div>'; reco.innerHTML = ''; return; }
const t = json.thresholds || { warn: 10, crit: 25 };
document.getElementById('health_legend').innerText = `Klik kartu untuk analisis AI | Status = gap biaya (WASPADA >= ${t.warn}, KRITIS >= ${t.crit}), indeks biaya CPI (<${t.cpi_yellow} / <${t.cpi_red}), jadwal, dan hasil AI terakhir`;
box.innerHTML = json.data.map((p, i) => {
const st = HEALTH_STYLE[p.level] || HEALTH_STYLE.NA;
const hasUsage = p.usage_pct !== null && p.usage_pct !== undefined;
const gap = (p.gap === null || p.gap === undefined) ? null : Math.round(p.gap);
const gapTxt = gap === null ? '-' : (gap > 0 ? '+' + gap : String(gap));
const detail = `Budget ${fmtRp(p.budget)} | Biaya ${fmtRp(p.cost_total)} (Material ${fmtRp(p.cost_material)}, Pengeluaran ${fmtRp(p.cost_expense)}, Subkon ${fmtRp(p.cost_subcon)})` +
(p.cpi !== null && p.cpi !== undefined ? ` | CPI ${p.cpi}` : '') + (p.elapsed_pct !== null ? ` | Waktu terpakai ${p.elapsed_pct}%` : '') +
(p.late_days ? ` | Lewat jadwal ${p.late_days} hari` : '') + (p.ai_risk ? ` | AI terakhir: ${p.ai_risk} (${p.ai_at})` : '') +
(p.reasons && p.reasons.length ? ` | Alasan status: ${p.reasons.join('; ')}` : '');
const w1 = Math.max(0, Math.min(100, p.progress)), w2 = hasUsage ? Math.max(0, Math.min(100, p.usage_pct)) : 0;
const levelColor = p.level === 'KRITIS' ? '#B1A8A8' : (p.level === 'WASPADA' ? '#C2B8AD' : (p.level === 'NORMAL' ? '#728E7E' : '#949C8B'));
return `
<div class="border rounded-xl p-3 cursor-pointer transition" data-pid="${p.id}" data-name="${esc(p.name)}" data-level="${p.level}" title="${esc(detail)}" style="background-color:#4a4960; border-color:#728E7E40;" onmouseover="this.style.borderColor='#728E7E';" onmouseout="this.style.borderColor='#728E7E40';">
<div class="flex justify-between items-start gap-2">
<div class="font-bold text-base leading-tight" style="color:#C2B8AD;">${HEALTH_ICONS[i % HEALTH_ICONS.length]} ${esc(p.name)}</div>
<span class="health-badge px-2 py-0.5 text-[11px] font-bold border rounded-md whitespace-nowrap" style="background:#373647; color:${levelColor}; border-color:${levelColor}80;">${st.icon} ${st.label}${p.ai_risk && p.level !== 'NORMAL' ? ' • AI' : ''}</span>
</div>
<div class="flex flex-wrap gap-x-4 text-sm mt-2" style="color:#B1A8A8;">
<span>Progres: <b style="color:#C2B8AD;">${p.progress}%</b></span>
<span>Pemakaian: <b class="health-num" style="color:${levelColor};">${hasUsage ? Number(p.usage_pct).toFixed(0) + '%' : '-'}</b></span>
<span>Gap: <b class="health-num" style="color:${levelColor};">${gapTxt}</b></span>
</div>
<div class="mt-2 space-y-1">
<div class="h-1.5 rounded" style="background-color:#373647;"><div class="h-1.5 rounded" style="width:${w1}%; background-color:#728E7E;"></div></div>
<div class="h-1.5 rounded" style="background-color:#373647;"><div class="h-1.5 rounded" style="width:${w2}%; background-color:${levelColor};"></div></div>
</div>
</div>`;
}).join('');
box.querySelectorAll('[data-pid]').forEach(el => { el.onclick = () => selectHealthProject(parseInt(el.getAttribute('data-pid'), 10), false); });
const ids = json.data.map(p => p.id);
const pick = ids.includes(healthSelectedId) ? healthSelectedId : (ids.includes(parseInt(currentProjectId, 10)) ? parseInt(currentProjectId, 10) : ids[0]);
selectHealthProject(pick, false);
} catch (err) { console.error('Error load project health:', err); box.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color:#B1A8A8;">Error memuat kesehatan proyek</div>'; }
}
async function loadExtra() {
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_extra&project_id=${currentProjectId}`);
if (!json.success) return;
const d = json.data;
document.getElementById('extra_cash').innerText = d.cash === null ? '-' : fmtRp(d.cash);
const ppnEl = document.getElementById('extra_ppn');
if (d.ppn) {
document.getElementById('extra_ppn_period').innerText = `(${d.ppn.periode})`;
ppnEl.innerText = fmtRp(Math.abs(d.ppn.net));
ppnEl.className = 'text-xl font-bold font-mono mt-2';
ppnEl.style.color = d.ppn.net > 0 ? '#C2B8AD' : '#728E7E';
document.getElementById('extra_ppn_sub').innerText = `${d.ppn.net > 0 ? 'KURANG BAYAR' : (d.ppn.net < 0 ? 'LEBIH BAYAR' : 'NIHIL')} | Keluaran ${fmtRp(d.ppn.keluaran)} - Masukan ${fmtRp(d.ppn.masukan)}`;
}
const card = document.getElementById('extra_subcon_card');
if (d.subcon) {
card.style.display = '';
document.getElementById('extra_subcon').innerText = fmtRp(d.subcon.sisa_hutang);
document.getElementById('extra_subcon_sub').innerText = `${d.subcon.spk_aktif} SPK aktif | Nilai ${fmtRp(d.subcon.nilai_spk)} | Tertagih ${fmtRp(d.subcon.tertagih)} | sisa hutang prestasi`;
} else { card.style.display = 'none'; }
} catch (err) { console.error('Error load extra:', err); }
}
async function loadTaxRates() {
const badge = document.getElementById('kmk_badge');
const validDateEl = document.getElementById('kmk_valid_date');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_tax_rates`);
if (json.success) {
badge.innerText = json.kmk_number !== '-' ? `KMK: ${json.kmk_number}` : 'Kosong';
if (json.start_date && json.end_date) validDateEl.innerText = `(${json.start_date} s/d ${json.end_date})`;
else if (json.valid_date) validDateEl.innerText = `(${json.valid_date})`;
else validDateEl.innerText = '';
globalTaxRates = json.rates || [];
filterTaxRates();
}
} catch (err) { document.getElementById('tax_rate_tbody').innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color:#B1A8A8;">Gagal memuat kurs pajak</td></tr>'; }
}
function filterTaxRates() {
const query = (document.getElementById('search_tax_currency').value || '').trim().toLowerCase();
const filtered = globalTaxRates.filter(r => (r.currency_code && r.currency_code.toLowerCase().includes(query)) || (r.currency_name && r.currency_name.toLowerCase().includes(query)));
renderTaxRates(filtered);
}
function renderTaxRates(rates) {
const tbody = document.getElementById('tax_rate_tbody');
if (!rates || rates.length === 0) { tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color:#B1A8A8;">Mata uang tidak ditemukan.</td></tr>'; return; }
tbody.innerHTML = rates.map(r => {
const dirColor = r.change_direction === 'UP' ? '#728E7E' : (r.change_direction === 'DOWN' ? '#B1A8A8' : '#949C8B');
const prefix = r.change_direction === 'UP' ? '+' : (r.change_direction === 'DOWN' ? '-' : '');
return `<tr class="hover:bg-[#4a4960]/50"><td class="p-1.5 font-bold" style="color:#C2B8AD;">${r.currency_code}</td>
<td class="p-1.5 text-right" style="color:#C2B8AD;">${formatIDR(r.rate)}</td>
<td class="p-1.5 text-center" style="color:${dirColor};">${prefix}${formatNum(r.change_amount)}</td></tr>`;
}).join('');
}
async function syncTaxRates() {
const btn = document.getElementById('btn_sync_tax');
btn.disabled = true; btn.innerText = 'Syncing...';
try {
const json = await apiFetch(`${API_ENDPOINT}?action=sync_tax_rates`, { method: 'POST' });
alert(json.message || (json.success ? 'Berhasil update kurs pajak.' : 'Gagal update kurs pajak.'));
await loadTaxRates();
} catch (err) { alert('Gagal menghubungi server sync kurs'); }
finally { btn.disabled = false; btn.innerText = 'Update Kurs'; }
}
async function loadFinancialPerformance() {
const year = document.getElementById('fin_year_select').value;
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_financial_performance&year=${year}&project_id=${currentProjectId}`);
if (!json.success) { renderFinChart(['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'], [], []); setFinMessage(json.message || 'Gagal memuat performa keuangan.', true); return; }
if (json.available_years) fillYearSelect(json.available_years, json.year);
renderFinChart(json.labels, json.datasets.pendapatan, json.datasets.pengeluaran);
const total = [...json.datasets.pendapatan, ...json.datasets.pengeluaran].reduce((a, b) => a + (Number(b) || 0), 0);
setFinMessage(total === 0 ? `Belum ada transaksi pendapatan/pengeluaran pada ${json.year}` : '', false);
} catch (err) { console.error('Error load financial performance:', err); setFinMessage('Gagal terhubung ke server.', true); }
}
function fillYearSelect(years, selected) {
const sel = document.getElementById('fin_year_select');
if (!years || !years.length) return;
sel.innerHTML = years.map(y => `<option value="${y}" ${String(y) === String(selected) ? 'selected' : ''}>${y}</option>`).join('');
}
function setFinMessage(msg, isError) {
const el = document.getElementById('fin_empty');
if (!msg) { el.classList.add('hidden'); el.innerHTML = ''; return; }
el.className = 'absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] font-sans pointer-events-none';
el.style.color = isError ? '#B1A8A8' : '#B1A8A8';
el.textContent = msg;
}
function renderFinChart(labels, incData, expData) {
const ctx = document.getElementById('finChartCanvas').getContext('2d');
if (finChart) finChart.destroy();
const compact = (v) => Number(v).toLocaleString('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
finChart = new Chart(ctx, {
type: 'bar',
data: { labels: labels, datasets: [{ label: 'Pendapatan', data: incData, backgroundColor: '#728E7E' }, { label: 'Pengeluaran', data: expData, backgroundColor: '#B1A8A8' }] },
options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#B1A8A8', font: { size: 9 } } }, tooltip: { callbacks: { label: (c) => ` ${c.dataset.label}: ${formatIDR(c.parsed.y)}` } } }, scales: { x: { ticks: { color: '#B1A8A8', font: { size: 8 } }, grid: { color: '#728E7E40' } }, y: { beginAtZero: true, ticks: { color: '#B1A8A8', font: { size: 8 }, callback: compact }, grid: { color: '#728E7E40' } } } }
});
}
async function loadMaterialMonitoring() {
const tbody = document.getElementById('material_tbody');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_material_monitoring&project_id=${currentProjectId}`);
if (json.success) {
document.getElementById('kpi_total_budget').innerText = formatIDR(json.kpi.budget_rab);
document.getElementById('kpi_material_out').innerText = formatIDR(json.kpi.material_out);
document.getElementById('kpi_sisa_budget').innerText = formatIDR(json.kpi.sisa_budget);
document.getElementById('kpi_usage_pct').innerText = `${json.kpi.persentase.toFixed(2)}%`;
const itemMap = new Map();
(json.items || []).forEach(item => {
const key = (item.item_name || '').trim().toLowerCase();
if (!itemMap.has(key)) itemMap.set(key, { item_name: item.item_name, item_code: item.item_code, unit: item.unit, budget_qty: 0, budget_value: 0, out_qty: 0, out_value: 0 });
const target = itemMap.get(key);
target.budget_qty += Number(item.budget_qty || 0); target.budget_value += Number(item.budget_value || 0);
target.out_qty += Number(item.out_qty || 0); target.out_value += Number(item.out_value || 0);
});
const aggregatedItems = Array.from(itemMap.values()).map(item => {
item.remaining_qty = item.budget_qty - item.out_qty;
item.usage_pct = item.budget_value > 0 ? ((item.out_value / item.budget_value) * 100).toFixed(2) : (item.budget_qty > 0 ? ((item.out_qty / item.budget_qty) * 100).toFixed(2) : '0.00');
const pct = Number(item.usage_pct);
item.status = pct > 100 ? 'OVERBUDGET' : (pct >= 80 ? 'WARNING' : 'ON_TRACK');
return item;
});
const statusCounts = { ON_TRACK: 0, WARNING: 0, OVERBUDGET: 0 };
aggregatedItems.forEach(i => { if (statusCounts[i.status] !== undefined) statusCounts[i.status]++; });
renderDoughnutChart(statusCounts);
renderBarMaterialChart(aggregatedItems.slice(0, 5));
document.getElementById('table_count_label').innerText = `Total Material: ${aggregatedItems.length} Item`;
if (aggregatedItems.length === 0) { tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color:#B1A8A8;">Tidak ada data penggunaan material untuk proyek ini.</td></tr>'; return; }
tbody.innerHTML = aggregatedItems.map(item => {
let statusBg = '#728E7E';
let statusText = '#C2B8AD';
if (item.status === 'WARNING') { statusBg = '#C2B8AD'; statusText = '#373647'; }
if (item.status === 'OVERBUDGET') { statusBg = '#B1A8A8'; statusText = '#373647'; }
return `<tr class="hover:bg-[#4a4960]/50 transition">
<td class="p-3 font-sans"><div class="font-bold" style="color:#C2B8AD;">${item.item_name}</div><div class="text-sm" style="color:#949C8B;">${item.item_code || '-'}</div></td>
<td class="p-3 text-center" style="color:#C2B8AD;">${item.unit || '-'}</td>
<td class="p-3 text-right" style="color:#C2B8AD;">${formatNum(item.budget_qty)}</td>
<td class="p-3 text-right font-bold" style="color:#C2B8AD;">${formatIDR(item.budget_value)}</td>
<td class="p-3 text-right" style="color:#728E7E;">${formatNum(item.out_qty)}</td>
<td class="p-3 text-right" style="color:#949C8B;">${formatIDR(item.out_value)}</td>
<td class="p-3 text-right" style="color:${item.remaining_qty < 0 ? '#B1A8A8' : '#C2B8AD'};">${formatNum(item.remaining_qty)}</td>
<td class="p-3 text-center font-bold" style="color:#C2B8AD;">${item.usage_pct}%</td>
<td class="p-3 text-center"><span class="px-2 py-0.5 text-[10px] border rounded-full" style="background:${statusBg}30; color:${statusText}; border-color:${statusBg}80;">${item.status}</span></td>
</tr>`;
}).join('');
} else {
console.error('Monitoring material gagal:', json.message);
['kpi_total_budget', 'kpi_material_out', 'kpi_sisa_budget'].forEach(id => document.getElementById(id).innerText = '-');
document.getElementById('kpi_usage_pct').innerText = '-';
tbody.innerHTML = `<tr><td colspan="9" class="p-4 text-center" style="color:#B1A8A8;">${esc(json.message || 'Gagal memuat data monitoring material')}</td></tr>`;
}
} catch (err) { console.error('Error load material monitoring:', err); tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color:#B1A8A8;">Gagal memuat data monitoring material</td></tr>'; }
}
function renderDoughnutChart(counts) {
const ctx = document.getElementById('doughnutChartCanvas').getContext('2d');
if (doughnutChart) doughnutChart.destroy();
doughnutChart = new Chart(ctx, {
type: 'doughnut',
data: { labels: ['On Track', 'Warning', 'Overbudget'], datasets: [{ data: [counts.ON_TRACK || 0, counts.WARNING || 0, counts.OVERBUDGET || 0], backgroundColor: ['#728E7E', '#C2B8AD', '#B1A8A8'], borderWidth: 0 }] },
options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: '#B1A8A8', font: { size: 10 } } } } }
});
}
function renderBarMaterialChart(topItems) {
const ctx = document.getElementById('barMaterialCanvas').getContext('2d');
if (barMaterialChart) barMaterialChart.destroy();
barMaterialChart = new Chart(ctx, {
type: 'bar',
data: { labels: topItems.map(i => i.item_name.substring(0, 15) + '...'), datasets: [{ label: 'Budget Qty', data: topItems.map(i => i.budget_qty), backgroundColor: '#728E7E' }, { label: 'Out Qty', data: topItems.map(i => i.out_qty), backgroundColor: '#949C8B' }] },
options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#B1A8A8', font: { size: 10 } } } }, scales: { x: { ticks: { color: '#B1A8A8', font: { size: 9 } }, grid: { color: '#728E7E40' } }, y: { ticks: { color: '#B1A8A8', font: { size: 9 } }, grid: { color: '#728E7E40' } } } }
});
}
</script>
</body>
</html>