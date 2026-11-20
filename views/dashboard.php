<?php
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
// views/dashboard.php - FINAL VERSION WITH HAMBURGER & MOBILE DETECTION
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id']) || !is_numeric($_SESSION['user_id']) || (int)$_SESSION['user_id'] <= 0) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../php-func/function.all.php';
if (function_exists('app_require_active_user')) {
    app_require_active_user();
}
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
<!-- <script src="https://cdn.tailwindcss.com"></script> -->
<link rel="stylesheet" href="../assets/css/dashboard.tailwind.css?v=<?= @filemtime(__DIR__ . '/../assets/css/dashboard.tailwind.css') ?: date('YmdHis') ?>">
<script src="../assets/js/chart.umd.js?v=<?= @filemtime(__DIR__ . '/../assets/js/chart.umd.js') ?: date('YmdHis') ?>"></script>
<link rel="stylesheet" href="../assets/css/fonts.css?v=<?= @filemtime(__DIR__ . '/../assets/css/fonts.css') ?: date('YmdHis') ?>">
<style>
body { font-family: 'Inter', sans-serif; background-color: #000000; color: #e2e8f0; }
.font-orbitron { font-family: 'Orbitron', sans-serif; }
.font-rajdhani { font-family: 'Rajdhani', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

/* [GRID-WRAP 2026-11-17] Wrap teks rapi pada keempat tabel dashboard (profit, aging, tax rate, material). */
#profit_table th, #profit_table td,
#aging_table th, #aging_table td,
#tax_rate_table th, #tax_rate_table td,
#material_table th, #material_table td .th_fix_mobile { white-space: nowrap !important; word-break: normal !important; }
{
  white-space: normal !important;
  word-wrap: break-word;
  overflow-wrap: break-word;
  word-break: break-word;
  line-height: 1.35;
}
#profit_table th.text-right, #profit_table td.text-right,
#aging_table th.text-right, #aging_table td.text-right,
#tax_rate_table th.text-right, #tax_rate_table td.text-right,
#material_table th.text-right, #material_table td.text-right {
  white-space: nowrap !important;
}

/* Mega Menu */
.nav-item { position: relative; }
.nav-item .dropdown-menu.show { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-menu {
position: absolute; top: 100%; left: 0;
width: max-content; max-width: calc(100vw - 16px); box-sizing: border-box;
max-height: calc(100vh - 80px); overflow-y: auto;
opacity: 0; visibility: hidden; transform: translateY(-6px); transition: all 0.2s ease-in-out;
}
.dropdown-menu .menu-wrap { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0 2rem; }
.menu-column {
flex: 0 0 auto;
min-width: 180px;
max-width: 220px;
margin-bottom: 1rem;
}
.menu-column h3 {
font-size: 20px;
font-weight: 500;
color: #22d3ee;
text-transform: uppercase;
letter-spacing: 0.05em;
border-bottom: 1px solid #008afb;
padding-bottom: 0.5rem;
margin-bottom: 0.5rem;
}
.menu-column ul { list-style: none; padding: 0; margin: 0; }
.menu-column ul li { margin-bottom: 0.25rem; }
.menu-column ul li a {
display: block;
padding: 0.2rem 0.4rem;
color: #ffffff;
text-decoration: none;
font-size: 16px;
font-weight: 300;
transition: all 0.2s;
}
.menu-column ul li a:hover { color: #22d3ee; background: rgba(34, 211, 238, 0.1); border-radius: 4px; }

/* Tabs */
.tab-btn {
    padding: 0.5rem 1rem;
    font-size: 14px;
    font-weight: 600;
    color: #94a3b8;
    background: #071d30;
    border: 1px solid #0a2d4a;
    border-radius: 0.5rem;
    cursor: pointer;
    transition: all 0.2s;
}
.tab-btn:hover { background:#2b3f52; color: #fff; }
.tab-btn.active { background:#066b78; color: #fff; border-color: #22d3ee; }
.tab-btn:focus-visible { outline:2px solid #22d3ee; outline-offset:2px; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }

/* Responsive Mobile & Hardware detection */
.is-mobile-hw .desktop-menu { display: none !important; }
.is-mobile-hw #header_logout { display: none !important; }
.is-mobile-hw #hamburger_btn { display: block !important; }

@media (max-width: 768px) {
    .desktop-menu { display: none !important; }
    #header_logout { display: none !important; }
    #hamburger_btn { display: block !important; }
    section h2 { font-size: 1.25rem !important; }
    .data-table { font-size: 0.75rem !important; }
    .data-table th, .data-table td { padding: 0.4rem 0.5rem !important; }
    .stat-number { font-size: 1.25rem !important; }
    nav { padding: 0.5rem 0.75rem !important; }
    .overflow-x-auto { -webkit-overflow-scrolling: touch; }
    #project_select { width: 100% !important; }
    .tab-btn { padding: 0.4rem 0.6rem; font-size: 12px; }
    .h-52, .h-56 { height: 11rem !important; }
}

@media (max-width: 480px) {
    section h2 { font-size: 1.1rem !important; }
    .stat-number { font-size: 1.1rem !important; }
}

html, body {
    overflow-x: hidden;
    max-width: 100vw;
}
@media (max-width: 768px) { .grid { grid-template-columns: minmax(0, 1fr) !important; width: 100% !important; } table { width: 100% !important; } .overflow-x-auto { max-width: 100% !important; } }

@media (max-width: 768px) {
    .grid { grid-template-columns: minmax(0, 1fr) !important; width: 100% !important; }
    .table-container { overflow-x: auto; max-width: 100%; }
    table { min-width: 640px !important; width: 100% !important; }
    th { white-space: nowrap !important; word-break: normal !important; }
}
</style>
</head>
<?php $favVer = date('YmdHis'); ?>
<link rel="shortcut icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="apple-touch-icon" href="../favicon.ico?v=<?=$favVer?>">
<body class="min-h-screen flex flex-col bg-[#020b14]">

<!-- HEADER & NAV -->
<header class="bg-[#000000] border-b border-[#0a2d4a] sticky top-0 z-50 shadow-lg">
<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
<div class="flex items-center justify-between h-14">
<button id="theme_toggle" class="p-2 rounded-lg bg-[#0a2d4a] text-white">
    <span class="dark:hidden">🌙</span>
    <span class="hidden dark:block">☀️</span>
</button>
<script>
    const toggle = document.getElementById('theme_toggle');
    const html = document.documentElement;
    toggle.addEventListener('click', () => {
        const theme = html.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        html.setAttribute('data-theme', theme);
        localStorage.setItem('app_theme', theme);
    });
    // Load theme on init
    const saved = localStorage.getItem('app_theme') || 'dark';
    html.setAttribute('data-theme', saved);
</script>

<div class="flex items-center space-x-6">
<a href="dashboard.php" class="flex items-center space-x-3 group">
<?php if (file_exists(__DIR__ . '/../assets/favicon.ico')): ?>
<img src="../assets/favicon.ico" alt="Logo" class="h-10 w-auto object-contain">
<?php else: ?>
<span class="font-arial text-xl font-normal text-white-400 tracking-wider group-hover:text-white-900 transition">[XYZ]SYS</span>
<span class="text-base px-2 py-0.5 rounded bg-cyan-950 text-cyan-300 border border-cyan-800/50">v1.0</span>
<?php endif; ?>
</a>
<!-- Desktop Menu -->
<ul class="desktop-menu flex items-center space-x-1 sm:space-x-3 font-rajdhani text-base tracking-wider font-semibold border-l border-[#0a2d4a] pl-4">
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
<li class="nav-item group h-14 flex items-center">
<a href="#" class="py-2 px-2.5 font-normal text-xl text-white hover:text-yellow-300 group-hover:text-yellow-300 flex items-center gap-1 transition-all rounded hover:bg-[#071d30]">
<?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
</a>
<?php if ($hasChildren): ?>
<div class="dropdown-menu bg-[#0f172a] border border-[#0a2d4a] rounded-b-xl shadow-2xl p-4 text-xl font-normal text-cyan-100 z-50">
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
<div class="flex items-center space-x-3 text-base">
<span class="text-slate-400 hidden sm:inline">User: <strong class="text-cyan-300"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></strong></span>
<a id="header_logout" href="logout.php" class="px-2.5 py-1 bg-rose-950/60 hover:bg-rose-900 text-rose-300 border border-rose-800/50 rounded transition">Logout</a>
<!-- Hamburger Menu Button -->
<button id="hamburger_btn" class="hidden p-2 text-slate-300 hover:text-white focus:outline-none" onclick="toggleMobileMenu()" aria-label="Menu">
<svg class="w-7 h-7" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16m-7 6h7"></path></svg>
</button>
<!-- Dropdown Menu -->
<div id="mobile_dropdown" class="hidden absolute top-14 right-4 w-56 bg-[#0f172a] border border-[#0a2d4a] rounded-xl shadow-2xl py-2 z-50 font-rajdhani text-lg">
<a href="stock.card.m.php" class="block px-4 py-2.5 text-white hover:bg-[#071d30] border-b border-[#0a2d4a]/50">Stock Card Mobile</a>
<a href="project.progress.m.php" class="block px-4 py-2.5 text-white hover:bg-[#071d30] border-b border-[#0a2d4a]/50">[M] Project Progress</a>
<a href="logout.php" class="block px-4 py-2.5 text-rose-400 hover:bg-[#071d30]">Logout</a>
</div>
</div>
</div>
</div>
</header>

<!-- MAIN CONTAINER -->
<main class="flex-1 max-w-7xl w-full mx-auto p-4 sm:p-6 space-y-6">

<!-- HEADER & SELECTOR PROYEK -->
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] gap-4">
<div>
<h1 class="text-3xl font-medium text-white tracking-wide font-rajdhani uppercase">RAB-PROJECT MONITORING DASHBOARD</h1>
<p class="text-base text-slate-400">Monitoring Proyek, Arus Kas, Inventory & Profitabilitas Real-time</p>
</div>
<div class="flex items-center space-x-3 w-full sm:w-auto">
<label for="project_select" class="text-base text-slate-400 whitespace-nowrap">Filter Proyek:</label>
<select id="project_select" onchange="onProjectChange(this.value)" class="bg-[#071d30] border border-[#0a2d4a] text-cyan-200 text-base rounded-lg p-2 focus:ring-cyan-500 focus:border-cyan-500 w-full sm:w-64">
<option value="0">Loading proyek...</option>
</select>
</div>
</div>

<!-- TAB BAR -->
<div id="tab_bar" class="flex flex-wrap gap-2 bg-[#04101c] p-3 rounded-xl border border-[#0a2d4a]" role="tablist" aria-label="Area dashboard">
<button type="button" class="tab-btn active" id="tabbtn_ringkasan" data-tab="ringkasan" role="tab" aria-selected="true" aria-controls="tab_ringkasan">Ringkasan Proyek</button>
<button type="button" class="tab-btn" id="tabbtn_keuangan" data-tab="keuangan" role="tab" aria-selected="false" aria-controls="tab_keuangan" tabindex="-1">Keuangan</button>
<button type="button" class="tab-btn" id="tabbtn_material" data-tab="material" role="tab" aria-selected="false" aria-controls="tab_material" tabindex="-1">Material &amp; RAB</button>
</div>

<!-- TAB 1: RINGKASAN PROYEK -->
<section id="tab_ringkasan" class="tab-panel active space-y-6" role="tabpanel" aria-labelledby="tabbtn_ringkasan">

<!-- GRID STATISTIK UTAMA -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<span class="text-base text-slate-400">Total Nilai RAB</span>
<span id="stat_total_rab" class="text-3xl font-bold text-white font-rajdhani mt-2">Rp 0</span>
<span id="stat_rab_desc" class="text-[11px] text-slate-500 mt-1">Anggaran awal proyek</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<span class="text-base text-slate-400">Realisasi Pengeluaran</span>
<span id="stat_total_exp" class="text-3xl font-bold text-yellow-400 font-rajdhani mt-2">Rp 0</span>
<span id="stat_exp_desc" class="text-[11px] text-slate-500 mt-1">Total PO, GRN & Biaya</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<span class="text-base text-slate-400">Sisa Anggaran</span>
<span id="stat_remaining" class="text-3xl font-bold text-cyan-400 font-rajdhani mt-2">Rp 0</span>
<span id="stat_rem_desc" class="text-[11px] text-slate-500 mt-1">Selisih RAB & Realisasi</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<span class="text-base text-slate-400">Progres Fisik</span>
<span id="stat_physical_prog" class="text-3xl font-bold text-emerald-400 font-rajdhani mt-2">0%</span>
<span id="stat_prog_desc" class="text-[11px] text-slate-500 mt-1">Berdasarkan bobot pekerjaan</span>
</div>
</div>

<!-- HEALTH SCORE & PROGRESS BAR -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a] grid grid-cols-1 lg:grid-cols-3 gap-6 items-center">
<div class="lg:col-span-2 space-y-3">
<div class="flex justify-between items-center">
<h2 class="text-xl font-bold text-white uppercase tracking-wider font-rajdhani">Status Kesehatan & Progres Keseluruhan</h2>
<span id="health_badge" class="px-2.5 py-1 rounded text-base font-bold bg-slate-800 text-slate-300">Menghitung...</span>
</div>
<div class="w-full bg-[#071d30] rounded-full h-4 border border-[#0a2d4a] overflow-hidden">
<div id="health_progress_bar" class="bg-gradient-to-r from-cyan-500 to-emerald-400 h-4 rounded-full transition-all duration-500" style="width: 0%"></div>
</div>
<p id="health_desc" class="text-base text-slate-400">Memuat analisis kesehatan proyek...</p>
</div>
<div class="bg-[#071d30]/50 p-4 rounded-lg border border-[#0a2d4a] flex flex-col justify-center space-y-2">
<div class="flex justify-between text-base"><span class="text-slate-400">Total Transaksi PO:</span><strong id="summary_po_count" class="text-white">0</strong></div>
<div class="flex justify-between text-base"><span class="text-slate-400">GRN Diterima:</span><strong id="summary_grn_count" class="text-white">0</strong></div>
<div class="flex justify-between text-base"><span class="text-slate-400">Invoice Masuk:</span><strong id="summary_inv_count" class="text-white">0</strong></div>
</div>
</div>

<!-- ACTION CENTER & MILESTONES -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<!-- Action Center -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<div>
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Action Center (Perlu Perhatian)</h2>
<span id="action_count_badge" class="px-2 py-0.5 bg-rose-950 text-rose-300 border border-rose-800 rounded text-base font-bold">0 Isu</span>
</div>
<div id="action_center_list" class="space-y-3 max-h-72 overflow-y-auto no-scrollbar">
<div class="text-base text-slate-500 text-center py-6">Memindai isu operasional...</div>
</div>
</div>
</div>
<!-- Milestone Tracker -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<div>
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Milestone & Tenggat Waktu</h2>
<span class="text-base text-slate-400">Target Proyek</span>
</div>
<div id="milestone_list" class="space-y-3 max-h-72 overflow-y-auto no-scrollbar">
<div class="text-base text-slate-500 text-center py-6">Memuat milestone...</div>
</div>
</div>
</div>
</div>

<!-- PROFITABILITAS PERKATEGORI -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Profitabilitas & Analisis Varian per Kategori RAB</h2>
<span class="text-base text-slate-400">RAB vs Realisasi Pengeluaran</span>
</div>
<div class="overflow-x-auto">
<table id="profit_table" class="w-full text-left text-xs">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-3">Kategori RAB</th>
<th class="p-3 text-right">Nilai RAB (Rp)</th>
<th class="p-3 text-right">Realisasi (Rp)</th>
<th class="p-3 text-right">Varian (Rp)</th>
<th class="p-3 text-center">Status</th>
</tr>
</thead>
<tbody id="profit_table_body" class="divide-y divide-[#0a2d4a]/50 text-slate-300">
<tr><td colspan="5" class="p-4 text-center text-slate-500">Memuat analisis profitabilitas...</td></tr>
</tbody>
</table>
</div>
</div>

</section>

<!-- TAB 2: KEUANGAN -->
<section id="tab_keuangan" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="tabbtn_keuangan">

<!-- AGING PIUTANG & HUTANG -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Aging Piutang &amp; Hutang Usaha (Invoice Pipeline)</h2>
<span class="text-base text-slate-400">Umur Piutang Klien &amp; Hutang Supplier</span>
</div>
<div class="overflow-x-auto">
<table id="aging_table" class="w-full text-left text-[11px]">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-2.5">Mitra / Klien / Supplier</th>
<th class="p-2.5">No. Dokumen</th>
<th class="p-2.5 text-center">Tgl Dokumen</th>
<th class="p-2.5 text-center">Jatuh Tempo</th>
<th class="p-2.5 text-right">Total Tagihan</th>
<th class="p-2.5 text-right">Belum Dibayar</th>
<th class="p-2.5 text-center">Umur (Hari)</th>
<th class="p-2.5 text-center">Bucket Aging</th>
<th class="p-2.5 text-center">Status</th>
</tr>
</thead>
<tbody id="aging_table_body" class="divide-y divide-[#0a2d4a]/50 text-slate-300">
<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data aging piutang &amp; hutang...</td></tr>
</tbody>
</table>
</div>
</div>

<!-- PERFORMA KEUANGAN & KURS PAJAK -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<div>
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Performa Keuangan (Bulanan)</h2>
<select id="fin_year_select" onchange="loadFinancialPerformance()" class="bg-[#071d30] border border-[#0a2d4a] text-cyan-200 text-base rounded px-2 py-0.5">
<option value="2026" selected>2026</option><option value="2025">2025</option>
</select></div>
<div class="relative w-full h-52">
<canvas id="finChartCanvas"></canvas>
<div id="fin_empty" class="hidden absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] text-slate-400 font-sans pointer-events-none"></div>
</div></div></div>
<div class="bg-[#04101c] rounded-xl border border-[#0a2d4a] p-5 flex flex-col justify-between">
<div>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 border-b border-[#0a2d4a] mb-3 gap-2">
<div>
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Rate Kurs Pajak (KMK)</h2>
<div class="flex items-center gap-2 mt-1">
<span id="kmk_badge" class="text-xs bg-cyan-950 text-cyan-300 border border-cyan-800 px-1.5 py-0.5 rounded inline-block">Memuat...</span>
<span id="kmk_valid_date" class="text-xs text-yellow-400"></span>
</div></div>
<div class="flex items-center space-x-2 w-full sm:w-auto">
<label for="kmk_year_select" class="text-xs text-slate-400">Thn:</label>
<select id="kmk_year_select" onchange="loadTaxRates()" class="bg-[#071d30] border border-[#0a2d4a] text-cyan-200 text-base rounded px-2 py-1">
<option value="2026" selected>2026</option><option value="2025">2025</option>
</select></div></div>
<div class="max-h-56 overflow-y-auto no-scrollbar">
<table id="tax_rate_table" class="w-full text-left text-[13px]">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-2">Mata Uang</th><th class="p-2">SK Menteri</th><th class="p-2 text-center">Periode Mulai</th><th class="p-2 text-center">Periode Akhir</th><th class="p-2 text-right">Rate (IDR)</th>
</tr>
</thead>
<tbody id="tax_rate_body" class="divide-y divide-[#0a2d4a]/50 text-slate-300">
<tr><td colspan="5" class="p-3 text-center text-slate-500">Memuat kurs pajak...</td></tr>
</tbody>
</table>
</div></div>
<div class="mt-3 pt-3 border-t border-[#0a2d4a] flex justify-between items-center text-[11px] text-slate-400">
<span>Sumber: PMK / Keputusan Menteri Keuangan</span>
<span id="tax_rate_count">0 Kurs aktif</span>
</div></div>
</div>

<!-- EXTRA FINANCIAL SUMMARY CARDS -->
<div id="extra_financial_cards" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<span class="text-base text-slate-400">Total Piutang (AR)</span>
<span id="extra_ar_total" class="text-2xl font-bold text-white font-rajdhani mt-1 block">Rp 0</span>
<span id="extra_ar_sub" class="text-[11px] text-slate-500">Invoice tertagih</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<span class="text-base text-slate-400">Total Hutang (AP)</span>
<span id="extra_ap_total" class="text-2xl font-bold text-yellow-400 font-rajdhani mt-1 block">Rp 0</span>
<span id="extra_ap_sub" class="text-[11px] text-slate-500">Kewajiban supplier/vendor</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<span class="text-base text-slate-400">Cash Flow Netto</span>
<span id="extra_net_cash" class="text-2xl font-bold text-cyan-400 font-rajdhani mt-1 block">Rp 0</span>
<span id="extra_net_sub" class="text-[11px] text-slate-500">Penerimaan - Pengeluaran</span>
</div>
</div>

</section>

<!-- TAB 3: MATERIAL & RAB -->
<section id="tab_material" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="tabbtn_material">

<!-- MATERIAL CHARTS -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani mb-3">Distribusi Status Material PO</h2>
<div class="relative w-full h-52 flex items-center justify-center"><canvas id="doughnutChartCanvas"></canvas></div>
</div>
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani mb-3">Top 5 Kuantitas Material Diminta</h2>
<div class="relative w-full h-52"><canvas id="barMaterialCanvas"></canvas></div>
</div>
</div>

<!-- MATERIAL MONITORING TABLE -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Monitoring Material &amp; Inventaris Proyek</h2>
<span class="text-base text-slate-400">Daftar Item Material PO &amp; GRN</span>
</div>
<div class="overflow-x-auto">
<table id="material_table" class="w-full text-left text-sm">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-3">Kode / Item Material</th>
<th class="p-3">Spesifikasi</th>
<th class="p-3 text-center">Satuan</th>
<th class="p-3 text-right">Vol RAB</th>
<th class="p-3 text-right">Vol PO</th>
<th class="p-3 text-right">Vol GRN</th>
<th class="p-3 text-right">Harga Satuan (Rp)</th>
<th class="p-3 text-right">Total (Rp)</th>
<th class="p-3 text-center">Status</th>
</tr>
</thead>
<tbody id="material_table_body" class="divide-y divide-[#0a2d4a]/50 text-slate-300">
<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data material proyek...</td></tr>
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

function toggleMobileMenu() {
    document.getElementById("mobile_dropdown").classList.toggle("hidden");
}
document.addEventListener("click", function(event) {
    const menu = document.getElementById("mobile_dropdown");
    const btn = document.getElementById("hamburger_btn");
    if (menu && btn && !menu.contains(event.target) && !btn.contains(event.target)) {
        menu.classList.add("hidden");
    }
});
// Mobile Hardware Detection
if (navigator.maxTouchPoints > 0 || /Android|iPhone|iPad|iPod/i.test(navigator.userAgent)) {
    document.body.classList.add("is-mobile-hw");
}

document.addEventListener('DOMContentLoaded', () => { initDashboard(); });

// Tab engine
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
function switchTab(name, push = true) {
if (!TAB_LOADERS[name]) name = 'ringkasan';
activeTab = name;
if (push) history.replaceState(null, '', '#' + name);
document.querySelectorAll('.tab-btn').forEach(btn => {
const match = btn.dataset.tab === name;
btn.classList.toggle('active', match);
btn.setAttribute('aria-selected', match ? 'true' : 'false');
btn.setAttribute('tabindex', match ? '0' : '-1');
});
document.querySelectorAll('.tab-panel').forEach(panel => {
const match = panel.id === 'tab_' + name;
panel.classList.toggle('active', match);
});
if (tabDirty[name]) {
tabDirty[name] = false;
TAB_LOADERS[name]();
}
if (TAB_CHARTS[name]) {
setTimeout(() => {
TAB_CHARTS[name]().forEach(c => { if (c) c.resize(); });
}, 50);
}
}
document.getElementById('tab_bar').addEventListener('click', e => {
const btn = e.target.closest('[data-tab]');
if (btn) switchTab(btn.dataset.tab);
});
window.addEventListener('hashchange', () => {
const h = tabFromHash();
if (h !== activeTab) switchTab(h, false);
});

// Init Dashboard
async function initDashboard() {
try {
const res = await fetch(`${API_ENDPOINT}?action=get_projects`);
const json = await res.json();
const sel = document.getElementById('project_select');
sel.innerHTML = '';
if (json.success && json.data && json.data.length > 0) {
json.data.forEach((p, idx) => {
const opt = document.createElement('option');
opt.value = p.id;
opt.textContent = `${p.code} - ${p.name}`;
if (idx === 0) opt.selected = true;
sel.appendChild(opt);
});
currentProjectId = json.data[0].id;
} else {
sel.innerHTML = '<option value="0">Tidak ada proyek tersedia</option>';
}
} catch (e) {
console.error('Gagal memuat proyek:', e);
document.getElementById('project_select').innerHTML = '<option value="0">Gagal memuat proyek</option>';
}

// Initial load for active tab + mark others dirty
const initialTab = tabFromHash();
Object.keys(tabDirty).forEach(k => { tabDirty[k] = true; });
switchTab(initialTab, false);
}

function onProjectChange(val) {
currentProjectId = parseInt(val) || 0;
Object.keys(tabDirty).forEach(k => { tabDirty[k] = true; });
tabDirty[activeTab] = false;
TAB_LOADERS[activeTab]();
}

// Loader 1: Project Health & Stats
async function loadProjectHealth() {
if (!currentProjectId) return;
try {
const res = await fetch(`${API_ENDPOINT}?action=get_project_health&project_id=${currentProjectId}`);
const json = await res.json();
if (!json.success) return;
const d = json.data;
document.getElementById('stat_total_rab').textContent = fmtRp(d.total_rab);
document.getElementById('stat_total_exp').textContent = fmtRp(d.total_exp);
document.getElementById('stat_remaining').textContent = fmtRp(d.remaining);
document.getElementById('stat_physical_prog').textContent = Number(d.physical_progress || 0).toFixed(1) + '%';
document.getElementById('summary_po_count').textContent = d.po_count || 0;
document.getElementById('summary_grn_count').textContent = d.grn_count || 0;
document.getElementById('summary_inv_count').textContent = d.inv_count || 0;

const bar = document.getElementById('health_progress_bar');
const prog = Math.min(Math.max(Number(d.physical_progress || 0), 0), 100);
bar.style.width = prog + '%';

const badge = document.getElementById('health_badge');
const desc = document.getElementById('health_desc');
if (d.health_status === 'HEALTHY') {
badge.className = 'px-2.5 py-1 rounded text-base font-bold bg-emerald-950 text-emerald-300 border border-emerald-800';
badge.textContent = 'SEHAT (HEALTHY)';
desc.textContent = 'Proyek berjalan sesuai anggaran dan progres fisik memuaskan.';
} else if (d.health_status === 'WARNING') {
badge.className = 'px-2.5 py-1 rounded text-base font-bold bg-yellow-950 text-yellow-300 border border-yellow-800';
badge.textContent = 'PERINGATAN (WARNING)';
desc.textContent = 'Pengeluaran mendekati atau melebihi anggaran RAB pada beberapa kategori.';
} else {
badge.className = 'px-2.5 py-1 rounded text-base font-bold bg-rose-950 text-rose-300 border border-rose-800';
badge.textContent = 'KRITIS (CRITICAL)';
desc.textContent = 'Realisasi pengeluaran melampaui RAB atau progres mengalami hambatan signifikan.';
}
} catch (e) {
console.error('Gagal memuat kesehatan proyek:', e);
}
}

// Loader 2: Action Center
async function loadActionCenter() {
if (!currentProjectId) return;
const list = document.getElementById('action_center_list');
const badge = document.getElementById('action_count_badge');
list.innerHTML = '<div class="text-base text-slate-500 text-center py-4">Memindai isu...</div>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_action_center&project_id=${currentProjectId}`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
list.innerHTML = '<div class="text-base text-emerald-400 text-center py-6">🎉 Tidak ada isu kritis. Semua operasional lancar!</div>';
badge.textContent = '0 Isu';
badge.className = 'px-2 py-0.5 bg-emerald-950 text-emerald-300 border border-emerald-800 rounded text-base font-bold';
return;
}
badge.textContent = json.data.length + ' Isu';
badge.className = 'px-2 py-0.5 bg-rose-950 text-rose-300 border border-rose-800 rounded text-base font-bold';
list.innerHTML = '';
json.data.forEach(item => {
const div = document.createElement('div');
div.className = 'p-3 rounded-lg bg-[#071d30]/60 border border-rose-900/50 flex items-start space-x-3';
div.innerHTML = `
<div class="text-xl">⚠️</div>
<div class="flex-1">
<div class="flex justify-between items-center"><strong class="text-rose-300 text-base">${item.title}</strong><span class="text-xs text-slate-400">${item.date || ''}</span></div>
<p class="text-base text-slate-300 mt-0.5">${item.description}</p>
</div>
`;
list.appendChild(div);
});
} catch (e) {
list.innerHTML = '<div class="text-base text-rose-400 text-center py-4">Gagal memuat action center.</div>';
}
}

// Loader 3: Milestones
async function loadMilestones() {
if (!currentProjectId) return;
const list = document.getElementById('milestone_list');
list.innerHTML = '<div class="text-base text-slate-500 text-center py-4">Memuat milestone...</div>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_milestones&project_id=${currentProjectId}`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
list.innerHTML = '<div class="text-base text-slate-400 text-center py-6">Belum ada milestone tercatat untuk proyek ini.</div>';
return;
}
list.innerHTML = '';
json.data.forEach(m => {
const isDone = m.status === 'COMPLETED';
const div = document.createElement('div');
div.className = 'p-3 rounded-lg bg-[#071d30]/40 border border-[#0a2d4a] flex items-center justify-between';
div.innerHTML = `
<div class="flex items-center space-x-3">
<div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-base ${isDone ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-slate-800 text-slate-300'}">${isDone ? '✓' : '•'}</div>
<div>
<h4 class="text-base font-semibold text-white">${m.name}</h4>
<span class="text-xs text-slate-400">Target: ${m.target_date || '-'}</span>
</div>
</div>
<span class="px-2 py-1 rounded text-xs font-semibold ${isDone ? 'bg-emerald-950 text-emerald-300 border border-emerald-800' : 'bg-yellow-950 text-yellow-300 border border-yellow-800'}">${m.status || 'PENDING'}</span>
`;
list.appendChild(div);
});
} catch (e) {
list.innerHTML = '<div class="text-base text-rose-400 text-center py-4">Gagal memuat milestone.</div>';
}
}

// Loader 4: Profitability
async function loadProfitability() {
if (!currentProjectId) return;
const tbody = document.getElementById('profit_table_body');
tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-slate-500">Memuat analisis profitabilitas...</td></tr>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_profitability&project_id=${currentProjectId}`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-slate-500">Tidak ada data RAB untuk proyek ini.</td></tr>';
return;
}
tbody.innerHTML = '';
json.data.forEach(row => {
const varVal = Number(row.variance || 0);
const isSurplus = varVal >= 0;
const tr = document.createElement('tr');
tr.className = 'hover:bg-[#071d30]/40 transition';
tr.innerHTML = `
<td class="p-3 font-semibold text-white">${row.category_name}</td>
<td class="p-3 text-right text-slate-300">${fmtRp(row.rab_amount)}</td>
<td class="p-3 text-right text-yellow-300">${fmtRp(row.realized_amount)}</td>
<td class="p-3 text-right font-bold ${isSurplus ? 'text-emerald-400' : 'text-rose-400'}">${fmtRp(varVal)}</td>
<td class="p-3 text-center">
<span class="px-2 py-0.5 rounded text-xs font-semibold ${isSurplus ? 'bg-emerald-950 text-emerald-300 border border-emerald-800' : 'bg-rose-950 text-rose-300 border border-rose-800'}">${isSurplus ? 'SURPLUS' : 'DEFICIT'}</span>
</td>
`;
tbody.appendChild(tr);
});
} catch (e) {
tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-rose-400">Gagal memuat data profitabilitas.</td></tr>';
}
}

// Loader 5: Financial Aging
async function loadFinancialAging() {
const tbody = document.getElementById('aging_table_body');
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data aging piutang &amp; hutang...</td></tr>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_aging_data`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Tidak ada data aging invoice piutang/hutang.</td></tr>';
return;
}
tbody.innerHTML = '';
json.data.forEach(row => {
const isAR = row.doc_type === 'AR';
const tr = document.createElement('tr');
tr.className = 'hover:bg-[#071d30]/40 transition';
tr.innerHTML = `
<td class="p-2.5 font-semibold text-white">${row.partner_name}</td>
<td class="p-2.5 text-cyan-300 font-mono">${row.doc_no}</td>
<td class="p-2.5 text-center text-slate-400">${row.doc_date || '-'}</td>
<td class="p-2.5 text-center text-slate-400">${row.due_date || '-'}</td>
<td class="p-2.5 text-right text-slate-300">${fmtRp(row.total_amount)}</td>
<td class="p-2.5 text-right font-bold text-yellow-300">${fmtRp(row.outstanding_amount)}</td>
<td class="p-2.5 text-center font-bold text-white">${row.aging_days} hr</td>
<td class="p-2.5 text-center"><span class="px-1.5 py-0.5 rounded text-[10px] bg-slate-800 text-cyan-300 border border-slate-700">${row.aging_bucket}</span></td>
<td class="p-2.5 text-center">
<span class="px-2 py-0.5 rounded text-[10px] font-semibold ${isAR ? 'bg-cyan-950 text-cyan-300 border border-cyan-800' : 'bg-purple-950 text-purple-300 border border-purple-800'}">${row.doc_type}</span>
</td>
`;
tbody.appendChild(tr);
});
} catch (e) {
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-rose-400">Gagal memuat data aging.</td></tr>';
}
}

// Loader 6: Tax Rates
async function loadTaxRates() {
const yr = document.getElementById('kmk_year_select').value;
const tbody = document.getElementById('tax_rate_body');
const badge = document.getElementById('kmk_badge');
const validDate = document.getElementById('kmk_valid_date');
const countSpan = document.getElementById('tax_rate_count');
tbody.innerHTML = '<tr><td colspan="5" class="p-3 text-center text-slate-500">Memuat kurs pajak...</td></tr>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_tax_rates&year=${yr}`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
tbody.innerHTML = '<tr><td colspan="5" class="p-3 text-center text-slate-500">Tidak ada data kurs pajak untuk tahun ' + yr + '.</td></tr>';
badge.textContent = 'TIDAK AKTIF';
badge.className = 'text-xs bg-rose-950 text-rose-300 border border-rose-800 px-1.5 py-0.5 rounded inline-block';
validDate.textContent = '';
countSpan.textContent = '0 Kurs aktif';
globalTaxRates = [];
return;
}
globalTaxRates = json.data;
badge.textContent = 'AKTIF / VALID';
badge.className = 'text-xs bg-emerald-950 text-emerald-300 border border-emerald-800 px-1.5 py-0.5 rounded inline-block';
validDate.textContent = `Periode: ${yr}`;
countSpan.textContent = json.data.length + ' Kurs aktif';
tbody.innerHTML = '';
json.data.forEach(r => {
const tr = document.createElement('tr');
tr.className = 'hover:bg-[#071d30]/40 transition';
tr.innerHTML = `
<td class="p-2 font-bold text-white">${r.currency_code}</td>
<td class="p-2 text-cyan-300">${r.sk_minister_no || '-'}</td>
<td class="p-2 text-center text-slate-400">${r.start_date || '-'}</td>
<td class="p-2 text-center text-slate-400">${r.end_date || '-'}</td>
<td class="p-2 text-right font-bold text-emerald-400">${formatNum(r.rate_idr)}</td>
`;
tbody.appendChild(tr);
});
} catch (e) {
tbody.innerHTML = '<tr><td colspan="5" class="p-3 text-center text-rose-400">Gagal memuat kurs pajak.</td></tr>';
}
}

// Loader 7: Extra Financial Summary
async function loadExtra() {
try {
const res = await fetch(`${API_ENDPOINT}?action=get_extra_financials`);
const json = await res.json();
if (!json.success || !json.data) return;
const d = json.data;
document.getElementById('extra_ar_total').textContent = fmtRp(d.total_ar);
document.getElementById('extra_ap_total').textContent = fmtRp(d.total_ap);
document.getElementById('extra_net_cash').textContent = fmtRp(d.net_cash);
} catch (e) {
console.error('Gagal memuat extra financials:', e);
}
}

// Loader 8: Financial Performance Chart
async function loadFinancialPerformance() {
const yr = document.getElementById('fin_year_select').value;
const emptyDiv = document.getElementById('fin_empty');
try {
const res = await fetch(`${API_ENDPOINT}?action=get_financial_performance&year=${yr}`);
const json = await res.json();
if (!json.success || !json.datasets) {
renderFinChart(['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'], [], []);
emptyDiv.textContent = json.message || 'Gagal memuat performa keuangan.';
emptyDiv.classList.remove('hidden');
return;
}
if ((json.datasets.pendapatan || []).every(v => v === 0) && (json.datasets.pengeluaran || []).every(v => v === 0)) {
emptyDiv.textContent = `Tidak ada transaksi tercatat pada tahun ${yr}.`;
emptyDiv.classList.remove('hidden');
} else {
emptyDiv.classList.add('hidden');
}
renderFinChart(json.labels || ['Jan','Feb','Mar','Apr','Mei','Jun','Jul','Agu','Sep','Okt','Nov','Des'], json.datasets.pendapatan || [], json.datasets.pengeluaran || []);
} catch (e) {
console.error('Gagal memuat chart keuangan:', e);
}
}

function renderFinChart(labels, incData, expData) {
const ctx = document.getElementById('finChartCanvas').getContext('2d');
if (finChart) finChart.destroy();
finChart = new Chart(ctx, {
type: 'bar',
data: {
labels: labels,
datasets: [
{ label: 'Pendapatan (AR)', data: incData, backgroundColor: '#06b6d4', borderRadius: 4 },
{ label: 'Pengeluaran (AP/PO)', data: expData, backgroundColor: '#f59e0b', borderRadius: 4 }
]
},
options: {
responsive: true,
maintainAspectRatio: false,
plugins: { legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 10 } } } },
scales: {
x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } },
y: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } }
}
}
});
}

// Loader 9: Material Monitoring & Charts
async function loadMaterialMonitoring() {
if (!currentProjectId) return;
const tbody = document.getElementById('material_table_body');
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data material proyek...</td></tr>';
try {
const res = await fetch(`${API_ENDPOINT}?action=get_material_monitoring&project_id=${currentProjectId}`);
const json = await res.json();
if (!json.success || !json.data || json.data.length === 0) {
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Tidak ada item material tercatat untuk proyek ini.</td></tr>';
renderDoughnutChart({ PENDING: 0, APPROVED: 0, DELIVERED: 0 });
renderBarMaterialChart([]);
return;
}
tbody.innerHTML = '';
let statusCounts = { PENDING: 0, APPROVED: 0, DELIVERED: 0 };
let aggregatedItems = [];

json.data.forEach(item => {
const st = (item.status || 'PENDING').toUpperCase();
if (statusCounts[st] !== undefined) statusCounts[st]++;
else statusCounts.PENDING++;

aggregatedItems.push({ name: item.item_name || item.material_name || 'Material', qty: Number(item.vol_po || item.qty || 0) });

const tr = document.createElement('tr');
tr.className = 'hover:bg-[#071d30]/40 transition';
tr.innerHTML = `
<td class="p-3 font-semibold text-white">${item.item_code || '-'}<br><span class="text-xs text-slate-400 font-normal">${item.item_name || item.material_name || '-'}</span></td>
<td class="p-3 text-slate-300 text-xs">${item.specification || '-'}</td>
<td class="p-3 text-center text-slate-400">${item.uom || 'Pcs'}</td>
<td class="p-3 text-right text-slate-300">${formatNum(item.vol_rab)}</td>
<td class="p-3 text-right text-yellow-300">${formatNum(item.vol_po)}</td>
<td class="p-3 text-right text-emerald-400">${formatNum(item.vol_grn)}</td>
<td class="p-3 text-right text-slate-300">${fmtRp(item.unit_price)}</td>
<td class="p-3 text-right font-bold text-white">${fmtRp(item.total_amount)}</td>
<td class="p-3 text-center"><span class="px-2 py-0.5 rounded text-xs font-semibold bg-cyan-950 text-cyan-300 border border-cyan-800">${item.status || 'ACTIVE'}</span></td>
`;
tbody.appendChild(tr);
});

renderDoughnutChart(statusCounts);
renderBarMaterialChart(aggregatedItems.slice(0, 5));
} catch (e) {
tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-rose-400">Gagal memuat monitoring material.</td></tr>';
}
}

function renderDoughnutChart(counts) {
const ctx = document.getElementById('doughnutChartCanvas').getContext('2d');
if (doughnutChart) doughnutChart.destroy();
doughnutChart = new Chart(ctx, {
type: 'doughnut',
data: {
labels: ['Pending', 'Approved', 'Delivered'],
datasets: [{
data: [counts.PENDING || 0, counts.APPROVED || 0, counts.DELIVERED || 0],
backgroundColor: ['#f59e0b', '#06b6d4', '#10b981'],
borderWidth: 1,
borderColor: '#04101c'
}]
},
options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 } } } } }
});
}

function renderBarMaterialChart(topItems) {
const ctx = document.getElementById('barMaterialCanvas').getContext('2d');
if (barMaterialChart) barMaterialChart.destroy();
barMaterialChart = new Chart(ctx, {
type: 'bar',
data: {
labels: topItems.map(i => i.name),
datasets: [{
label: 'Kuantitas',
data: topItems.map(i => i.qty),
backgroundColor: '#22d3ee',
borderRadius: 4
}]
},
options: {
responsive: true,
maintainAspectRatio: false,
plugins: { legend: { display: false } },
scales: {
x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } },
y: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } }
}
}
});
}
</script>

<!-- Hamburger Menu Mobile -->
<div id="mobile_hamburger_panel" class="hidden fixed top-14 left-0 w-full bg-[#0f172a] z-[9999] p-4 flex flex-col gap-4 shadow-2xl">
    <a href="stock.card.m.php" class="block p-4 text-center bg-[#1e293b] text-white rounded-lg font-bold">STOCK CARD MOBILE</a>
    <a href="project.progress.m.php" class="block p-4 text-center bg-[#1e293b] text-white rounded-lg font-bold">[M] PROJECT PROGRESS</a>
    <a href="logout.php" class="block p-4 text-center bg-rose-900 text-white rounded-lg font-bold">LOGOUT</a>
</div>
<script>
document.getElementById("hamburger_btn").addEventListener("click", () => {
    document.getElementById("mobile_hamburger_panel").classList.toggle("hidden");
});
</script>
</body>
</html>
