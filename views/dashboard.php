<?php
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
// views/dashboard.php
// [REVISI 2026-11-24d] LIGHT EMERALD THEME - hijau lembut, nyaman untuk mata
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
<script>
tailwind.config = { corePlugins: { preflight: false } };
</script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link rel="stylesheet" href="../css/theme-sync.css?v=<?=$favVer?>">
<script src="../js/theme-sync.js?v=<?=$favVer?>""></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<script>document.documentElement.setAttribute('data-theme', localStorage.getItem('app_theme') || 'dark');</script>
<style>
/* ===== DARK THEME (Default - TIDAK DIUBAH) ===== */
:root {
  --bg-deep: #373647;
  --bg-card: #3f3e52;
  --bg-inner: #4a4960;
  --bg-deeper: #2e2d3d;
  --accent: #728E7E;
  --accent-2: #949C8B;
  --text-main: #C2B8AD;
  --text-sub: #B1A8A8;
  --border-soft: rgba(114, 142, 126, 0.25);
  --border-strong: rgba(114, 142, 126, 0.5);
  --hover-bg: #5a5970;
  --hover-text: #ffffff;
  --grid-color: rgba(114, 142, 126, 0.15);
  --card-shadow: rgba(0, 0, 0, 0.3);
}

/* ===== LIGHT EMERALD THEME - Hijau Lembut, Eye-Friendly ===== */
[data-theme="light"] {
  --bg-deep: #e8f5e9;
  --bg-card: #f1f8f4;
  --bg-inner: #dcedc8;
  --bg-deeper: #a5d6a7;
  --accent: #2e7d32;
  --accent-2: #4caf50;
  --text-main: #1b5e20;
  --text-sub: #388e3c;
  --border-soft: rgba(46, 125, 50, 0.28);
  --border-strong: rgba(46, 125, 50, 0.55);
  --hover-bg: #c8e6c9;
  --hover-text: #1b5e20;
  --grid-color: rgba(46, 125, 50, 0.12);
  --card-shadow: rgba(0, 0, 0, 0.08);
}

html, body {
  background-color: var(--bg-app);
  color: var(--text-primary);
  font-family: 'Inter', sans-serif;
  overflow-x: hidden;
  max-width: 100vw;
  transition: background-color 0.3s ease, color 0.3s ease;
}
body.dashboard-scope { background-color: var(--bg-deep); }

.font-orbitron { font-family: 'Orbitron', sans-serif; }
.font-rajdhani { font-family: 'Rajdhani', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* ===== HEADER ===== */
.dash-header {
  background-color: var(--bg-deeper);
  border-bottom: 1px solid var(--border-strong);
  transition: all 0.3s ease;
}

/* ===== NAV ===== */
.nav-item { position: relative; }
.nav-link {
  color: var(--text-main);
  text-decoration: none;
  transition: all 0.2s;
}
.nav-link:hover {
  color: var(--hover-text);
  background-color: var(--hover-bg);
}

/* ===== DROPDOWN MEGA MENU ===== */
.dropdown-menu {
  position: absolute; top: 100%; left: 0; z-index: 60;
  width: max-content; max-width: calc(100vw - 16px); box-sizing: border-box;
  max-height: calc(100vh - 90px); overflow-y: auto;
  opacity: 0; visibility: hidden; transform: translateY(-6px);
  pointer-events: none;
  background-color: var(--bg-card);
  border: 1px solid var(--border-strong);
  transition: opacity .18s ease, transform .18s ease, visibility .18s;
}
.dropdown-menu.open { opacity: 1; visibility: visible; transform: translateY(0); pointer-events: auto; }
.dropdown-menu .menu-wrap { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0 2rem; }
.menu-column { flex: 0 0 auto; min-width: 180px; max-width: 220px; margin-bottom: 1rem; }
.menu-column h3 {
  font-size: 20px; font-weight: 500;
  color: var(--accent);
  text-transform: uppercase; letter-spacing: 0.05em;
  border-bottom: 1px solid var(--accent);
  padding-bottom: 0.5rem; margin-bottom: 0.5rem;
}
.menu-column ul { list-style: none; padding: 0; margin: 0; }
.menu-column ul li { margin-bottom: 0.25rem; }
.menu-column ul li a {
  display: block; padding: 0.2rem 0.4rem;
  color: var(--text-main);
  text-decoration: none; font-size: 16px; font-weight: 300;
  transition: all 0.2s; border-radius: 0.25rem;
}
.menu-column ul li a:hover {
  color: var(--hover-text);
  background: var(--hover-bg);
  transform: translateX(2px);
}

/* ===== MOBILE ===== */
#hamburger_btn { display: none; }
#mobile_dropdown {
  min-width: 220px;
  background-color: var(--bg-card);
  border: 1px solid var(--border-strong);
}
#mobile_dropdown a {
  color: var(--text-main);
  border-bottom: 1px solid var(--border-soft);
}
#mobile_dropdown a:hover {
  background-color: var(--hover-bg);
  color: var(--hover-text);
}

/* ===== CARDS / PANELS ===== */
.dash-card {
  background-color: var(--bg-card);
  border: 1px solid var(--border-soft);
  border-radius: 0.75rem;
  box-shadow: 0 2px 8px var(--card-shadow);
  transition: all 0.3s ease;
}

/* ===== TABS ===== */
.tab-btn {
  background-color: var(--bg-inner);
  color: var(--text-main);
  font-weight: 700; font-size: 15px;
  padding: 8px 20px; border: none; border-radius: 4px;
  cursor: pointer; transition: all 0.15s;
}
.tab-btn:hover {
  background-color: var(--hover-bg);
  color: var(--hover-text);
}
.tab-btn.active {
  background-color: var(--accent);
  color: var(--hover-text);
}
.tab-btn:focus-visible { outline: 2px solid var(--accent-2); outline-offset: 2px; }
.tab-panel { display: none; }
.tab-panel.active { display: block; }

/* ===== SELECT / INPUT ===== */
.dash-select, .dash-input {
  background-color: var(--bg-inner);
  border: 1px solid var(--border-strong);
  color: var(--text-main);
  transition: all 0.2s;
}
.dash-select:focus, .dash-input:focus {
  border-color: var(--accent);
  outline: none;
}

/* ===== BUTTONS ===== */
.btn-accent {
  background-color: var(--accent);
  color: var(--hover-text);
}
.btn-accent:hover { background-color: #1b5e20; }
.btn-ghost {
  background-color: var(--bg-inner);
  color: var(--text-main);
  border: 1px solid var(--accent-2);
  transition: all 0.2s;
}
.btn-ghost:hover {
  background-color: var(--hover-bg);
  color: var(--hover-text);
}

/* ===== KPI CARDS ===== */
.kpi-card {
  background-color: var(--bg-inner);
  border-radius: 0.75rem;
  padding: 1rem;
  box-shadow: 0 2px 6px var(--card-shadow);
  transition: all 0.3s ease;
}

/* ===== TABLE ===== */
.dash-table thead {
  background-color: var(--bg-inner);
  color: var(--text-main);
}
.dash-table tbody tr {
  border-bottom: 1px solid var(--grid-color);
}
.dash-table tbody tr:hover {
  background-color: var(--hover-bg);
}

/* ===== BADGES ===== */
.badge-kritis {
  background-color: rgba(229, 115, 115, 0.15);
  color: #c62828;
  border: 1px solid rgba(229, 115, 115, 0.5);
}
.badge-waspada {
  background-color: rgba(255, 183, 77, 0.15);
  color: #ef6c00;
  border: 1px solid rgba(255, 183, 77, 0.5);
}
.badge-normal {
  background-color: rgba(76, 175, 80, 0.15);
  color: var(--accent);
  border: 1px solid rgba(76, 175, 80, 0.5);
}

/* ===== RESPONSIVE ===== */
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

/* ===== SCROLLBAR ===== */
::-webkit-scrollbar { width: 8px; height: 8px; }
::-webkit-scrollbar-track { background: var(--bg-deep); }
::-webkit-scrollbar-thumb { background: var(--bg-inner); border-radius: 4px; }
::-webkit-scrollbar-thumb:hover { background: var(--accent-2); }
</style>
</head>
<?php $favVer = date('YmdHis'); ?>
<link rel="shortcut icon" href="../favicon.ico?v=<?=$favVer?>">
<link rel="icon" href="../favicon.ico?v=<?=$favVer?>">
<link rel="apple-touch-icon" href="../favicon.ico?v=<?=$favVer?>">
<body class="dashboard-scope min-h-screen flex flex-col">
<!-- HEADER -->
<header class="dash-header sticky top-0 z-50 shadow-lg">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-14">
<div class="flex items-center space-x-6">
<a href="dashboard.php" class="flex items-center space-x-3 group">
<?php if (file_exists(__DIR__ . '/../assets/favicon.ico')): ?>
<img src="../assets/favicon.ico" alt="Logo" class="h-10 w-auto object-contain">
<?php else: ?>
<span class="text-xl font-normal tracking-wider" style="color: var(--text-main);">[XYZ]SYS</span>
<span class="text-base px-2 py-0.5 rounded border" style="background: var(--bg-deep); color: var(--accent); border-color: var(--border-strong);">v1.0</span>
<?php endif; ?>
</a>
<!-- Desktop Menu -->
<ul class="desktop-menu flex items-center space-x-1 sm:space-x-3 font-rajdhani text-base tracking-wider font-semibold border-l pl-4" style="border-color: var(--border-soft);">
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
<a href="#" class="nav-link py-2 px-2.5 font-normal text-xl flex items-center gap-1 rounded">
<?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
</a>
<?php if ($hasChildren): ?>
<div class="dropdown-menu rounded-b-xl shadow-2xl p-4 text-xl font-normal">
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
<!-- KANAN -->
<div class="flex items-center space-x-3 text-base">
<button id="theme_toggle_btn" type="button" onclick="toggleTheme()" class="btn-ghost px-2.5 py-1 rounded text-sm font-semibold transition">
<span id="theme_icon">🌙</span> <span id="theme_lbl">Dark</span>
</button>
<span class="hidden sm:inline" style="color: var(--text-sub);">User: <strong style="color: var(--accent);"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></strong></span>
<a id="header_logout" href="logout.php" class="btn-ghost px-2.5 py-1 rounded transition">Logout</a>
<div class="relative">
<button id="hamburger_btn" class="items-center justify-center w-10 h-10 rounded-lg border transition" style="background: var(--bg-inner); border-color: var(--border-strong); color: var(--text-main);" aria-label="Menu" aria-haspopup="true" aria-expanded="false" onclick="toggleMobileMenu(event)">
<svg class="w-6 h-6" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h16"/></svg>
</button>
<div id="mobile_dropdown" class="hidden absolute right-0 top-full mt-2 w-60 rounded-xl shadow-2xl py-2 z-50 font-rajdhani text-lg">
<a href="project.progress.m.php" class="block px-4 py-2.5">📊 [M] Project Progress</a>
<a href="stock.card.m.php" class="block px-4 py-2.5">📦 Stock Card Mobile</a>
<a href="logout.php" class="block px-4 py-2.5" style="color: var(--text-sub);">🚪 Logout</a>
</div>
</div>
</div>
</div>
</div>
</header>
<!-- MAIN -->
<main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 space-y-6">
<!-- TITLE BAR -->
<div class="dash-card p-4 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
<div>
<h1 class="text-3xl font-medium tracking-wide font-rajdhani uppercase" style="color: var(--text-main);">RAB-PROJECT MONITORING DASHBOARD</h1>
<p class="text-base" style="color: var(--text-sub);">Monitoring Proyek, Arus Kas, Inventory & Profitabilitas Real-time</p>
</div>
<div class="flex items-center space-x-3 w-full sm:w-auto">
<label for="project_select" class="text-base whitespace-nowrap" style="color: var(--text-sub);">Filter Proyek:</label>
<select id="project_select" onchange="onProjectChange(this.value)" class="dash-select text-base rounded-lg p-2 w-full sm:w-64">
<option value="0">Loading proyek...</option>
</select>
</div>
</div>
<!-- TAB BAR -->
<div class="dash-card flex flex-wrap gap-2 p-3" role="tablist">
<button type="button" class="tab-btn active" id="tabbtn_ringkasan" data-tab="ringkasan">Ringkasan Proyek</button>
<button type="button" class="tab-btn" id="tabbtn_keuangan" data-tab="keuangan">Keuangan</button>
<button type="button" class="tab-btn" id="tabbtn_material" data-tab="material">Material &amp; RAB</button>
</div>
<!-- TAB 1: RINGKASAN -->
<section id="tab_ringkasan" class="tab-panel active space-y-6">
<div class="dash-card p-4" id="health_panel">
<div class="pb-3 border-b mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">🩺 Kesehatan Proyek (Progres vs Pemakaian Biaya)</h2>
<span class="text-[11px]" id="health_legend" style="color: var(--text-sub);">Klik kartu untuk analisis AI</span>
</div>
<div id="health_cards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 max-h-96 overflow-y-auto no-scrollbar">
<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Memuat kesehatan proyek...</div>
</div>
<div id="health_reco" class="mt-3"></div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="dash-card p-4">
<div class="pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--text-sub);">⚡ Action Center (Butuh Tindakan)</h2>
</div>
<ul class="space-y-2 text-sm max-h-72 overflow-y-auto no-scrollbar" id="action_list">
<li class="text-center text-xs py-4" style="color: var(--text-sub);">Memuat indikator...</li>
</ul>
</div>
<div class="dash-card p-4">
<div class="pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">🎯 Milestone Task Proyek (14 Hari &amp; Terlambat)</h2>
</div>
<div class="space-y-2 max-h-48 overflow-y-auto no-scrollbar" id="milestone_list">
<div class="text-center text-xs py-4" style="color: var(--text-sub);">Memuat milestone...</div>
</div>
</div>
</div>
<div class="dash-card p-4">
<div class="pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">📊 Profitabilitas Proyek (Biaya Riil vs Tertagih)</h2>
</div>
<div class="overflow-x-auto max-h-64 no-scrollbar">
<table class="w-full text-left text-xs dash-table">
<thead class="border-b sticky top-0" style="border-color: var(--border-soft);">
<tr><th class="pb-2">Proyek</th><th class="pb-2 text-right">Progres</th><th class="pb-2 text-right">Kontrak (DPP)</th><th class="pb-2 text-right">Tertagih (DPP)</th><th class="pb-2 text-right">Biaya Riil</th><th class="pb-2 text-right">Biaya/Kontrak</th><th class="pb-2 text-right">Margin</th></tr>
</thead>
<tbody id="profit_tbody" class="divide-y font-mono" style="color: var(--text-main);">
<tr><td colspan="7" class="py-4 text-center text-xs" style="color: var(--text-sub);">Memuat...</td></tr>
</tbody>
</table>
</div>
</div>
</section>
<!-- TAB 2: KEUANGAN -->
<section id="tab_keuangan" class="tab-panel space-y-6">
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="dash-card p-4">
<div class="pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--text-main);">Financial Aging Analysis (Umur Piutang & Hutang)</h2>
</div>
<div class="overflow-x-auto no-scrollbar">
<table class="w-full text-left text-[11px] dash-table">
<thead><tr class="border-b font-semibold text-[10px] uppercase tracking-wider" style="border-color: var(--border-soft); color: var(--text-sub);">
<th class="pb-2">Kategori</th><th class="pb-2 text-right">&lt;30 HARI</th><th class="pb-2 text-right">31-60 HARI</th><th class="pb-2 text-right">61-90 HARI</th><th class="pb-2 text-right">&gt;90 HARI</th><th class="pb-2 text-right">TOTAL</th>
</tr></thead>
<tbody id="aging_tbody" class="divide-y font-mono text-[11px]" style="color: var(--text-main);">
<tr><td colspan="6" class="py-4 text-center font-sans" style="color: var(--text-sub);">Memuat data aging...</td></tr>
</tbody>
</table>
</div>
</div>
<div class="dash-card p-4">
<div class="pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--text-main);">💰 Invoice Pipeline & Proyeksi Kas Masuk</h2>
</div>
<div class="grid grid-cols-2 gap-3">
<div class="kpi-card" style="border-left: 4px solid var(--text-sub);"><div class="text-[10px] uppercase" style="color: var(--text-sub);">Jatuh Tempo (Overdue)</div><div class="text-lg font-bold font-mono mt-1" id="pip_overdue_val" style="color: var(--text-sub);">Rp 0</div><div class="text-[10px]" id="pip_overdue_cnt" style="color: var(--text-sub);">0 invoice</div></div>
<div class="kpi-card" style="border-left: 4px solid var(--accent);"><div class="text-[10px] uppercase" style="color: var(--text-sub);">Belum Bayar (Aktif)</div><div class="text-lg font-bold font-mono mt-1" id="pip_unpaid_val" style="color: var(--accent);">Rp 0</div><div class="text-[10px]" id="pip_unpaid_cnt" style="color: var(--text-sub);">0 invoice</div></div>
<div class="kpi-card" style="border-left: 4px solid var(--text-main);"><div class="text-[10px] uppercase" style="color: var(--text-sub);">Terbayar Sebagian</div><div class="text-lg font-bold font-mono mt-1" id="pip_partial_val" style="color: var(--text-main);">Rp 0</div><div class="text-[10px]" id="pip_partial_cnt" style="color: var(--text-sub);">0 invoice</div></div>
<div class="kpi-card" style="border-left: 4px solid var(--accent-2);"><div class="text-[10px] uppercase" style="color: var(--text-sub);">Lunas (Closed)</div><div class="text-lg font-bold font-mono mt-1" id="pip_paid_cnt" style="color: var(--accent-2);">0 invoice</div><div class="text-[10px]" style="color: var(--text-sub);">Selesai</div></div>
</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4" id="extra_row">
<div class="kpi-card" style="border-left: 4px solid var(--accent);">
<div class="text-base uppercase font-bold tracking-wider" style="color: var(--text-sub);">🏦 Saldo Kas &amp; Bank</div>
<div class="text-xl font-bold font-mono mt-2" id="extra_cash" style="color: var(--accent);">Rp 0</div>
<div class="text-[10px] mt-1" style="color: var(--accent-2);">Dari jurnal terposting (semua proyek)</div>
</div>
<div class="kpi-card" style="border-left: 4px solid var(--text-main);">
<div class="text-base uppercase font-bold tracking-wider" style="color: var(--text-sub);">🧾 PPN Bulan Ini <span id="extra_ppn_period" class="text-xs"></span></div>
<div class="text-xl font-bold font-mono mt-2" id="extra_ppn" style="color: var(--text-main);">Rp 0</div>
<div class="text-[10px] mt-1" id="extra_ppn_sub" style="color: var(--text-main);">Keluaran - Masukan</div>
</div>
<div class="kpi-card" style="border-left: 4px solid var(--accent-2);" id="extra_subcon_card">
<div class="text-base uppercase font-bold tracking-wider" style="color: var(--text-sub);">👷 Subkontraktor</div>
<div class="text-xl font-bold font-mono mt-2" id="extra_subcon" style="color: var(--accent-2);">Rp 0</div>
<div class="text-[10px] mt-1" id="extra_subcon_sub" style="color: var(--accent-2);">Sisa hutang prestasi</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<div class="dash-card p-4">
<div class="flex justify-between items-center pb-3 border-b mb-3" style="border-color: var(--border-soft);">
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">Performa Keuangan (Bulanan)</h2>
<select id="fin_year_select" onchange="loadFinancialPerformance()" class="dash-select text-[10px] rounded px-2 py-0.5">
<option value="2026" selected>2026</option><option value="2025">2025</option>
</select>
</div>
<div class="relative w-full h-52">
<canvas id="finChartCanvas"></canvas>
<div id="fin_empty" class="hidden absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] font-sans pointer-events-none" style="color: var(--text-sub);"></div>
</div>
</div>
<div class="dash-card p-4">
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 border-b mb-3 gap-2" style="border-color: var(--border-soft);">
<div>
<h2 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">Rate Kurs Pajak (KMK)</h2>
<div class="flex items-center gap-2 mt-1">
<span id="kmk_badge" class="text-[9px] border px-1.5 py-0.5 rounded inline-block" style="background: var(--bg-deep); color: var(--accent); border-color: var(--border-strong);">Memuat...</span>
<span id="kmk_valid_date" class="text-[11px]" style="color: var(--text-main);"></span>
</div>
</div>
<div class="flex items-center space-x-2 w-full sm:w-auto">
<div class="relative flex-1 sm:w-44">
<input type="text" id="search_tax_currency" onkeyup="filterTaxRates()" placeholder="Cari mata uang..." class="dash-input w-full text-[11px] rounded px-2.5 py-1 pl-7 placeholder-slate-500">
<svg class="w-3.5 h-3.5 absolute left-2 top-1.5" style="color: var(--text-sub);" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
</div>
<button id="btn_sync_tax" onclick="syncTaxRates()" class="btn-accent text-[10px] px-2 py-1 rounded transition whitespace-nowrap">Update Kurs</button>
</div>
</div>
<div class="max-h-56 overflow-y-auto no-scrollbar">
<table class="w-full text-left text-[13px] dash-table">
<thead class="sticky top-0">
<tr><th class="p-1.5">Mata Uang</th><th class="p-1.5 text-right">Kurs (IDR)</th><th class="p-1.5 text-center">Ubah</th></tr>
</thead>
<tbody id="tax_rate_tbody" class="divide-y text-[12px]" style="color: var(--text-main);">
<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Memuat data kurs pajak...</td></tr>
</tbody>
</table>
</div>
</div>
</div>
</section>
<!-- TAB 3: MATERIAL -->
<section id="tab_material" class="tab-panel space-y-6">
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="kpi-card" style="border-left: 4px solid var(--accent);">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color: var(--text-sub);">
<svg class="w-4 h-4 flex-shrink-0" style="color: var(--accent);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
<span>Total Budget RAB</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_total_budget" style="color: var(--text-main);">Rp 0</div>
<div class="text-[10px] mt-1" style="color: var(--accent-2);">Alokasi Anggaran Disetujui</div>
</div>
<div class="kpi-card" style="border-left: 4px solid var(--accent-2);">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color: var(--text-sub);">
<svg class="w-4 h-4 flex-shrink-0" style="color: var(--accent-2);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path></svg>
<span>Material Out (Realisasi)</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_material_out" style="color: var(--accent-2);">Rp 0</div>
<div class="text-[10px] mt-1" style="color: var(--accent-2);">Total Pemakaian Material</div>
</div>
<div class="kpi-card" style="border-left: 4px solid var(--text-main);">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color: var(--text-sub);">
<svg class="w-4 h-4 flex-shrink-0" style="color: var(--text-main);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
<span>Sisa Budget</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_sisa_budget" style="color: var(--text-main);">Rp 0</div>
<div class="text-[10px] mt-1" style="color: var(--text-main);">Sisa Anggaran Material</div>
</div>
<div class="kpi-card" style="border-left: 4px solid var(--text-sub);">
<div class="text-base uppercase font-bold tracking-wider flex items-center gap-1.5" style="color: var(--text-sub);">
<svg class="w-4 h-4 flex-shrink-0" style="color: var(--text-sub);" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z"></path><path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path></svg>
<span>Persentase Penyerapan</span>
</div>
<div class="text-xl font-bold font-mono mt-2" id="kpi_usage_pct" style="color: var(--text-sub);">0.00%</div>
<div class="text-[10px] mt-1" id="kpi_status_label" style="color: var(--text-sub);">Status Penyerapan</div>
</div>
</div>
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<div class="dash-card p-4 lg:col-span-4">
<h3 class="text-base font-bold uppercase tracking-wider mb-2 font-rajdhani text-center" style="color: var(--accent);">Distribusi Status Penyerapan</h3>
<div class="relative w-full h-56 flex items-center justify-center"><canvas id="doughnutChartCanvas"></canvas></div>
</div>
<div class="dash-card p-4 lg:col-span-8">
<h3 class="text-base font-bold uppercase tracking-wider mb-2 font-rajdhani" style="color: var(--accent);">Top Material Out vs Budget Qty</h3>
<div class="relative w-full h-56"><canvas id="barMaterialCanvas"></canvas></div>
</div>
</div>
<div class="dash-card p-4">
<div class="flex justify-between items-center mb-4">
<h3 class="text-base font-bold uppercase tracking-wider font-rajdhani" style="color: var(--accent);">Rincian Penggunaan Material Out Proyek</h3>
<span class="text-base" id="table_count_label" style="color: var(--text-sub);">Total Material: 0 Item</span>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm dash-table">
<thead>
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
<tbody id="material_tbody" class="divide-y font-mono" style="color: var(--text-main);">
<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">Memuat data material proyek...</td></tr>
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

/* ===== THEME ENGINE ===== */
function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}

function updateThemeUI(theme) {
  window.RabThemeSync.updateUI(theme);
}

function toggleTheme() {
  window.RabThemeSync.cycleTheme();
}
function rethemeCharts() {
  const tick = cssVar('--text-sub'), grid = cssVar('--grid-color');
  const acc = cssVar('--accent'), acc2 = cssVar('--accent-2'), sub = cssVar('--text-sub'), main = cssVar('--text-main');
  if (finChart) {
    finChart.data.datasets[0].backgroundColor = acc;
    finChart.data.datasets[1].backgroundColor = sub;
    finChart.options.plugins.legend.labels.color = tick;
    finChart.options.scales.x.ticks.color = tick; finChart.options.scales.x.grid.color = grid;
    finChart.options.scales.y.ticks.color = tick; finChart.options.scales.y.grid.color = grid;
    finChart.update();
  }
  if (doughnutChart) {
    doughnutChart.data.datasets[0].backgroundColor = [acc, main, sub];
    doughnutChart.options.plugins.legend.labels.color = tick;
    doughnutChart.update();
  }
  if (barMaterialChart) {
    barMaterialChart.data.datasets[0].backgroundColor = acc;
    barMaterialChart.data.datasets[1].backgroundColor = acc2;
    barMaterialChart.options.plugins.legend.labels.color = tick;
    barMaterialChart.options.scales.x.ticks.color = tick; barMaterialChart.options.scales.x.grid.color = grid;
    barMaterialChart.options.scales.y.ticks.color = tick; barMaterialChart.options.scales.y.grid.color = grid;
    barMaterialChart.update();
  }
}
updateThemeUI(document.documentElement.getAttribute('data-theme') || 'dark');

const formatIDR = (num) => 'Rp ' + Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const formatNum = (num) => Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const esc = (v) => String(v == null ? '' : v).replace(/[&<>\"]/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '\"': '&quot;' }[c]));

async function apiFetch(url, opts = {}) {
  const res = await fetch(url, opts);
  if (res.status === 401) { alert('Sesi berakhir. Silakan login kembali.'); location.href = 'login.php'; throw new Error('401'); }
  if (!res.ok) throw new Error("HTTP " + res.status + " " + res.statusText);
  let json;
  try { json = await res.json(); } catch (e) { throw new Error("Respons bukan JSON dari server."); }
  if (json === null || typeof json !== 'object') throw new Error("Format respons server tidak valid.");
  return json;
}

/* MOBILE */
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
  const showMsg = (msg) => { tbody.innerHTML = `<tr><td colspan="6" class="p-3 text-center font-sans" style="color: var(--text-sub);">${msg}</td></tr>`; };
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_financial_aging&project_id=${currentProjectId}`);
    if (!json.success) { showMsg(json.message || 'Gagal memuat data aging.'); return; }
    const ar = json.ar, ap = json.ap;
    const row = (label, labelColor, d, totalColor) => `
      <tr class="hover:bg-[var(--hover-bg)] transition">
        <td class="py-2.5 font-sans font-semibold" style="color:${labelColor};">${label}<span class="block text-[9px] font-normal" style="color: var(--text-sub);">${d.count} invoice outstanding</span></td>
        <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(d.age_30)}</td>
        <td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(d.age_60)}</td>
        <td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(d.age_90)}</td>
        <td class="py-2.5 text-right font-semibold" style="color: var(--text-sub);">${formatNum(d.age_over_90)}</td>
        <td class="py-2.5 text-right font-bold" style="color:${totalColor};">${formatNum(d.total)}</td>
      </tr>`;
    const net = (k) => (ar[k] || 0) - (ap[k] || 0);
    tbody.innerHTML = row('Piutang (AR)', cssVar('--accent'), ar, cssVar('--accent')) + row('Hutang (AP)', cssVar('--accent-2'), ap, cssVar('--text-main')) + `
      <tr class="border-t" style="border-color: var(--border-soft);"><td class="py-2.5 font-sans font-semibold" style="color: var(--text-main);">Netto (AR - AP)</td>
        <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(net('age_30'))}</td>
        <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(net('age_60'))}</td>
        <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(net('age_90'))}</td>
        <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(net('age_over_90'))}</td>
        <td class="py-2.5 text-right font-bold" style="color: var(--text-main);">${formatNum(net('total'))}</td></tr>
      <tr><td colspan="6" class="pt-2 text-right text-[9px] font-sans" style="color: var(--text-sub);">Umur dihitung dari tanggal invoice, per ${json.as_of}</td></tr>`;
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

async function loadActionCenter() {
  const ul = document.getElementById('action_list');
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_action_center&project_id=${currentProjectId}`);
    if (!json.success) { ul.innerHTML = `<li class="text-center text-xs py-3" style="color: var(--text-sub);">${esc(json.message || 'Gagal memuat Action Center')}</li>`; return; }
    const order = { danger: 0, warn: 1, info: 2 };
    const active = json.items.filter(i => i.count > 0).sort((a, b) => (order[a.level] - order[b.level]) || (b.count - a.count));
    const calm = json.items.length - active.length;
    const levelBorder = { danger: cssVar('--text-sub'), warn: cssVar('--text-main'), info: cssVar('--accent') };
    const levelText = { danger: cssVar('--text-sub'), warn: cssVar('--text-main'), info: cssVar('--accent') };
    let html = active.map(i => `
      <li class="flex justify-between items-center p-2 rounded" style="background-color: var(--bg-inner); border-left: 4px solid ${levelBorder[i.level] || cssVar('--accent')}; color: ${levelText[i.level] || cssVar('--accent')}; border-bottom: 1px solid var(--grid-color);" title="${esc(i.hint)}">
        <span>${esc(i.label)}<span class="block text-[10px] opacity-60">${esc(i.hint)}</span></span>
        <span class="font-bold font-mono text-lg">${i.count}</span>
      </li>`).join('');
    if (!active.length) html = '<li class="text-center text-sm py-4" style="color: var(--accent);">✔ Tidak ada yang perlu ditindaklanjuti.</li>';
    else if (calm > 0) html += `<li class="text-center text-[11px] pt-1" style="color: var(--text-sub);">✔ ${calm} indikator lain aman</li>`;
    ul.innerHTML = html;
  } catch (err) { console.error('Error load action center:', err); ul.innerHTML = '<li class="text-center text-xs py-3" style="color: var(--text-sub);">Error memuat Action Center</li>'; }
}

async function loadMilestones() {
  const list = document.getElementById('milestone_list');
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_upcoming_milestones&project_id=${currentProjectId}`);
    if (!json.success) { list.innerHTML = `<div class="text-center text-xs py-4" style="color: var(--text-sub);">${esc(json.message || 'Error memuat milestone')}</div>`; return; }
    if (json.data.length > 0) {
      list.innerHTML = json.data.map(t => {
        const late = t.late === true;
        const info = late ? `Terlambat ${Math.abs(t.days_left)} hari` : (t.days_left === 0 ? 'Hari ini' : `${t.days_left} hari lagi`);
        const border = late ? cssVar('--text-sub') : cssVar('--accent');
        const textColor = late ? cssVar('--text-sub') : cssVar('--text-main');
        const subColor = late ? cssVar('--text-sub') : cssVar('--text-sub');
        return `
          <div class="flex justify-between items-center p-2 rounded" style="background-color: var(--bg-inner); border-left: 2px solid ${border}; border-bottom: 1px solid var(--grid-color);">
            <div><div class="font-bold text-xs" style="color: var(--text-main);">${esc(t.task_name)}</div><div class="text-[10px]" style="color: var(--text-sub);">${esc(t.project_name)}</div></div>
            <div class="text-right"><div class="font-mono text-xs font-bold" style="color:${textColor};">${esc(t.deadline)}</div><div class="text-[9px]" style="color:${subColor};">${esc(t.status)} - ${info}</div></div>
          </div>`;
      }).join('');
    } else { list.innerHTML = '<div class="text-center text-xs py-4" style="color: var(--text-sub);">Tidak ada milestone terdekat.</div>'; }
  } catch (err) { list.innerHTML = '<div class="text-center text-xs py-4" style="color: var(--text-sub);">Error memuat milestone</div>'; }
}

async function loadProfitability() {
  const tbody = document.getElementById('profit_tbody');
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_project_profitability&project_id=${currentProjectId}`);
    if (!json.success) { tbody.innerHTML = `<tr><td colspan="7" class="py-4 text-center text-xs" style="color: var(--text-sub);">${esc(json.message || 'Error memuat data')}</td></tr>`; return; }
    if (json.data.length > 0) {
      tbody.innerHTML = json.data.map(p => {
        const marginPct = (p.margin / p.contract_value) * 100;
        const marginColor = marginPct >= 10 ? cssVar('--accent') : (marginPct >= 5 ? cssVar('--text-main') : cssVar('--text-sub'));
        return `
          <tr class="hover:bg-[var(--hover-bg)] transition">
            <td class="py-2.5 font-sans font-semibold" style="color: var(--text-main);">${esc(p.project_name)}</td>
            <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(p.progress_pct)}%</td>
            <td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(p.contract_value)}</td>
            <td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(p.invoiced_value)}</td>
            <td class="py-2.5 text-right font-semibold" style="color: var(--text-main);">${formatNum(p.actual_cost)}</td>
            <td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(p.cost_per_contract)}%</td>
            <td class="py-2.5 text-right font-bold" style="color:${marginColor};">${formatNum(marginPct)}%</td>
          </tr>`;
      }).join('');
    }
  } catch (err) { console.error('Error load profitability:', err); tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-xs" style="color: var(--text-sub);">Error memuat data profitabilitas</td></tr>'; }
}

async function loadProjectHealth() {
  const cards = document.getElementById('health_cards');
  const reco = document.getElementById('health_reco');
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_project_health&project_id=${currentProjectId}`);
    if (!json.success || !json.data || json.data.length === 0) {
      cards.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Tidak ada data kesehatan proyek.</div>';
      reco.innerHTML = '';
      return;
    }
    if (json.data.length > 0) {
      cards.innerHTML = json.data.map(p => {
        const healthColor = p.health_score >= 70 ? cssVar('--accent') : (p.health_score >= 40 ? cssVar('--text-main') : cssVar('--text-sub'));
        const healthText = p.health_score >= 70 ? 'Sehat' : (p.health_score >= 40 ? 'Waspada' : 'Kritis');
        return `
          <div class="kpi-card" style="border-left: 4px solid ${healthColor}; cursor: pointer;" onclick="showHealthDetail(${p.project_id})">
            <div class="text-base uppercase font-bold tracking-wider" style="color: var(--text-sub);">${esc(p.project_name)}</div>
            <div class="text-xl font-bold font-mono mt-2" style="color:${healthColor};">${p.health_score}%</div>
            <div class="text-[10px] mt-1" style="color: var(--text-sub);">${healthText} - ${esc(p.health_status)}</div>
          </div>`;
      }).join('');
      reco.innerHTML = json.recommendations ? `<div class="dash-card p-4 mt-4">
        <h3 class="text-base font-bold uppercase tracking-wider font-rajdhani mb-2" style="color: var(--accent);">Rekomendasi AI</h3>
        <div class="text-sm" style="color: var(--text-main);">${esc(json.recommendations)}</div>
      </div>` : '';
    } else {
      cards.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Tidak ada data kesehatan proyek.</div>';
      reco.innerHTML = '';
    }
  } catch (err) { console.error('Error load project health:', err); cards.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Error memuat data kesehatan proyek</div>'; reco.innerHTML = ''; }
}

async function loadTaxRates() {
  const tbody = document.getElementById('tax_rate_tbody');
  const badge = document.getElementById('kmk_badge');
  const dateSpan = document.getElementById('kmk_valid_date');
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_tax_rates`);
    if (!json.success) {
      tbody.innerHTML = `<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">${esc(json.message || 'Gagal memuat data kurs pajak')}</td></tr>`;
      badge.textContent = 'Error';
      badge.style.backgroundColor = cssVar('--error-color');
      return;
    }
    if (json.data.length > 0) {
      globalTaxRates = json.data;
      renderTaxRates();
      badge.textContent = 'Valid';
      badge.style.backgroundColor = cssVar('--success-color');
      dateSpan.textContent = `Per ${json.valid_date}`;
    } else {
      tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Tidak ada data kurs pajak</td></tr>';
      badge.textContent = 'Kosong';
      badge.style.backgroundColor = cssVar('--warning-color');
    }
  } catch (err) { console.error('Error load tax rates:', err); tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Error memuat data kurs pajak</td></tr>'; badge.textContent = 'Error'; badge.style.backgroundColor = cssVar('--error-color'); }
}

function renderTaxRates() {
  const tbody = document.getElementById('tax_rate_tbody');
  const search = document.getElementById('search_tax_currency').value.toLowerCase();
  const filtered = globalTaxRates.filter(r => r.currency.toLowerCase().includes(search));
  if (filtered.length > 0) {
    tbody.innerHTML = filtered.map(r => {
      const isEditable = r.is_editable === true;
      return `
        <tr class="hover:bg-[var(--hover-bg)] transition">
          <td class="p-2.5 font-sans font-semibold" style="color: var(--text-main);">${esc(r.currency)}</td>
          <td class="p-2.5 text-right font-mono" style="color: var(--text-main);">${formatNum(r.rate)}</td>
          <td class="p-2.5 text-center">${isEditable ? `<button onclick="editTaxRate('${esc(r.currency)}', ${r.rate})" class="text-[10px] px-1.5 py-0.5 rounded" style="background: var(--bg-inner); color: var(--text-main); border: 1px solid var(--border-strong);">Ubah</button>` : '<span class="text-[10px]" style="color: var(--text-sub);">Tidak bisa diubah</span>'}</td>
        </tr>`;
    }).join('');
  } else {
    tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Tidak ada data yang cocok</td></tr>';
  }
}

function filterTaxRates() {
  renderTaxRates();
}

async function syncTaxRates() {
  const btn = document.getElementById('btn_sync_tax');
  const badge = document.getElementById('kmk_badge');
  const dateSpan = document.getElementById('kmk_valid_date');
  try {
    btn.disabled = true;
    btn.textContent = 'Memperbarui...';
    const json = await apiFetch(`${API_ENDPOINT}?action=sync_tax_rates`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ user_id: <?=$user_id?> })
    });
    if (json.success) {
      await loadTaxRates();
    } else {
      alert(json.message || 'Gagal memperbarui kurs pajak');
      badge.textContent = 'Error';
      badge.style.backgroundColor = cssVar('--error-color');
    }
  } catch (err) {
    console.error('Error sync tax rates:', err);
    alert('Error memperbarui kurs pajak');
    badge.textContent = 'Error';
    badge.style.backgroundColor = cssVar('--error-color');
  } finally {
    btn.disabled = false;
    btn.textContent = 'Update Kurs';
  }
}

function editTaxRate(currency, currentRate) {
  const newRate = prompt(`Masukkan kurs baru untuk ${currency}:`, currentRate);
  if (newRate === null) return;
  const rate = parseFloat(newRate);
  if (isNaN(rate) || rate <= 0) {
    alert('Kurs harus berupa angka positif');
    return;
  }
  updateTaxRate(currency, rate);
}

async function updateTaxRate(currency, rate) {
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=update_tax_rate`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ currency, rate, user_id: <?=$user_id?> })
    });
    if (json.success) {
      alert('Kurs pajak berhasil diperbarui');
      await loadTaxRates();
    } else {
      alert(json.message || 'Gagal memperbarui kurs pajak');
    }
  } catch (err) {
    console.error('Error update tax rate:', err);
    alert('Error memperbarui kurs pajak');
  }
}

async function loadFinancialPerformance() {
  const canvas = document.getElementById('finChartCanvas');
  const emptyDiv = document.getElementById('fin_empty');
  const year = document.getElementById('fin_year_select').value;
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_financial_performance&year=${year}&project_id=${currentProjectId}`);
    if (!json.success) {
      emptyDiv.textContent = json.message || 'Gagal memuat data performa keuangan';
      emptyDiv.classList.remove('hidden');
      return;
    }
    if (json.data.length > 0) {
      emptyDiv.classList.add('hidden');
      const months = json.data.map(d => d.month);
      const income = json.data.map(d => d.income);
      const expense = json.data.map(d => d.expense);
      const ctx = canvas.getContext('2d');
      if (finChart) finChart.destroy();
      finChart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: months,
          datasets: [
            { label: 'Pendapatan', data: income, backgroundColor: cssVar('--accent'), borderWidth: 1 },
            { label: 'Pengeluaran', data: expense, backgroundColor: cssVar('--text-sub'), borderWidth: 1 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { color: cssVar('--text-sub') } },
            tooltip: { callbacks: { label: ctx => formatIDR(ctx.raw) } }
          },
          scales: {
            x: { ticks: { color: cssVar('--text-sub') }, grid: { color: cssVar('--grid-color') } },
            y: { ticks: { color: cssVar('--text-sub'), callback: v => fmtRp(v) }, grid: { color: cssVar('--grid-color') } }
          }
        }
      });
    } else {
      emptyDiv.textContent = 'Tidak ada data untuk tahun ini';
      emptyDiv.classList.remove('hidden');
    }
  } catch (err) {
    console.error('Error load financial performance:', err);
    emptyDiv.textContent = 'Error memuat data performa keuangan';
    emptyDiv.classList.remove('hidden');
  }
}

async function loadExtra() {
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_extra_data&project_id=${currentProjectId}`);
    if (json.success) {
      document.getElementById('extra_cash').innerText = fmtRp(json.cash_balance);
      document.getElementById('extra_ppn').innerText = fmtRp(json.ppn_balance);
      document.getElementById('extra_ppn_period').innerText = json.ppn_period;
      document.getElementById('extra_ppn_sub').innerText = json.ppn_direction;
      document.getElementById('extra_subcon').innerText = fmtRp(json.subcon_balance);
      document.getElementById('extra_subcon_sub').innerText = json.subcon_status;
      if (json.subcon_balance === 0) {
        document.getElementById('extra_subcon_card').style.display = 'none';
      }
    }
  } catch (err) { console.error('Error load extra data:', err); }
}

async function loadMaterialMonitoring() {
  try {
    const json = await apiFetch(`${API_ENDPOINT}?action=read_material_monitoring&project_id=${currentProjectId}`);
    if (!json.success) {
      document.getElementById('material_tbody').innerHTML = `<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">${esc(json.message || 'Gagal memuat data material')}</td></tr>`;
      return;
    }
    if (json.data.length > 0) {
      // Update KPI cards
      const totalBudget = json.summary.total_budget;
      const materialOut = json.summary.material_out;
      const sisaBudget = json.summary.sisa_budget;
      const usagePct = json.summary.usage_pct;

      document.getElementById('kpi_total_budget').innerText = fmtRp(totalBudget);
      document.getElementById('kpi_material_out').innerText = fmtRp(materialOut);
      document.getElementById('kpi_sisa_budget').innerText = fmtRp(sisaBudget);
      document.getElementById('kpi_usage_pct').innerText = `${usagePct.toFixed(2)}%`;

      document.getElementById('kpi_status_label').innerText = usagePct >= 90 ? 'Kritis' : (usagePct >= 70 ? 'Waspada' : 'Normal');
      document.getElementById('kpi_status_label').style.color = usagePct >= 90 ? cssVar('--text-sub') : (usagePct >= 70 ? cssVar('--text-main') : cssVar('--accent'));

      document.getElementById('table_count_label').innerText = `Total Material: ${json.data.length} Item`;

      // Render material table
      const tbody = document.getElementById('material_tbody');
      tbody.innerHTML = json.data.map(m => {
        const statusColor = m.status === 'Kritis' ? cssVar('--text-sub') : (m.status === 'Waspada' ? cssVar('--text-main') : cssVar('--accent'));
        return `
          <tr class="hover:bg-[var(--hover-bg)] transition">
            <td class="p-3 font-sans font-semibold" style="color: var(--text-main);">${esc(m.material_name)}<span class="block text-[10px] font-normal" style="color: var(--text-sub);">${esc(m.material_code)}</span></td>
            <td class="p-3 text-center" style="color: var(--text-main);">${esc(m.unit)}</td>
            <td class="p-3 text-right" style="color: var(--text-main);">${formatNum(m.budget_qty)}</td>
            <td class="p-3 text-right font-medium" style="color: var(--text-main);">${fmtRp(m.total_budget)}</td>
            <td class="p-3 text-right" style="color: var(--text-main);">${formatNum(m.material_out_qty)}</td>
            <td class="p-3 text-right font-semibold" style="color: var(--text-main);">${fmtRp(m.total_realisasi)}</td>
            <td class="p-3 text-right" style="color: var(--text-main);">${formatNum(m.sisa_qty)}</td>
            <td class="p-3 text-center font-bold" style="color:${statusColor};">${m.usage_pct.toFixed(2)}%</td>
            <td class="p-3 text-center"><span class="badge-${m.status.toLowerCase()}" style="padding: 2px 6px; border-radius: 4px;">${m.status}</span></td>
          </tr>`;
      }).join('');

      // Render doughnut chart
      const doughnutCtx = document.getElementById('doughnutChartCanvas').getContext('2d');
      if (doughnutChart) doughnutChart.destroy();
      doughnutChart = new Chart(doughnutCtx, {
        type: 'doughnut',
        data: {
          labels: ['Normal', 'Waspada', 'Kritis'],
          datasets: [{
            data: [json.summary.normal_count, json.summary.waspada_count, json.summary.kritis_count],
            backgroundColor: [cssVar('--accent'), cssVar('--text-main'), cssVar('--text-sub')],
            borderWidth: 1
          }]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'bottom', labels: { color: cssVar('--text-sub') } },
            tooltip: { callbacks: { label: ctx => `${ctx.label}: ${ctx.raw} item` } }
          }
        }
      });

      // Render bar chart
      const barCtx = document.getElementById('barMaterialCanvas').getContext('2d');
      if (barMaterialChart) barMaterialChart.destroy();
      const topMaterials = json.data.sort((a, b) => b.usage_pct - a.usage_pct).slice(0, 10);
      barMaterialChart = new Chart(barCtx, {
        type: 'bar',
        data: {
          labels: topMaterials.map(m => m.material_name),
          datasets: [
            { label: 'Budget Qty', data: topMaterials.map(m => m.budget_qty), backgroundColor: cssVar('--accent'), borderWidth: 1 },
            { label: 'Material Out', data: topMaterials.map(m => m.material_out_qty), backgroundColor: cssVar('--accent-2'), borderWidth: 1 }
          ]
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { color: cssVar('--text-sub') } },
            tooltip: { callbacks: { label: ctx => `${ctx.dataset.label}: ${ctx.raw}` } }
          },
          scales: {
            x: { ticks: { color: cssVar('--text-sub') }, grid: { color: cssVar('--grid-color') } },
            y: { ticks: { color: cssVar('--text-sub') }, grid: { color: cssVar('--grid-color') } }
          }
        }
      });
    } else {
      document.getElementById('material_tbody').innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">Tidak ada data material</td></tr>';
    }
  } catch (err) {
    console.error('Error load material monitoring:', err);
    document.getElementById('material_tbody').innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">Error memuat data material</td></tr>';
  }
}

</script>
</body>
</html>