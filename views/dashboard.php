<?php
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
// views/dashboard.php
// [REVISI 2026-11-24b] FIX: button theme (label tidak terbalik) + LIGHT theme aktif (warm, tanpa putih silau). Dark theme TIDAK diubah.
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<!-- [FIX] Terapkan theme tersimpan SEBELUM render (cegah flash putih) -->
<script>document.documentElement.setAttribute('data-theme', localStorage.getItem('app_theme') || 'dark');</script>
<link rel="stylesheet" type="text/css" href="../css/app-theme.css" />
</head>
<?php $favVer = date('YmdHis'); ?>
<link rel="shortcut icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="icon" href="../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="apple-touch-icon" href="../favicon.ico?v=<?=$favVer?>">
<body class="dashboard-scope dashboard-page min-h-screen flex flex-col">
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
<!-- [FIX] Button theme: label = theme AKTIF (tidak terbalik), ikon dinamis -->
<button id="theme_toggle_btn" type="button" onclick="toggleTheme()" class="theme-toggle-btn" title="Ganti tema">
<span id="theme_icon">☀️</span>
<span id="theme_lbl">(Light/Dark)</span>
</button>
<span class="hidden sm:inline" style="color: var(--text-sub);">User: <strong style="color: var(--accent);"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></strong></span>
<a id="header_logout" href="logout.php" class="btn-logout px-2.5 py-1 rounded transition">Logout</a>
<div class="relative">
<button id="hamburger_btn" class="items-center justify-center w-10 h-10 rounded-lg border transition" data-role="hamburger-trigger" aria-label="Menu" aria-haspopup="true" aria-expanded="false" onclick="toggleMobileMenu(event)">
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

/* ===== [FIX] THEME ENGINE (label tidak terbalik) ===== */
function cssVar(name) {
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim();
}
/*
function updateThemeUI(theme) {
  const icon = document.getElementById('theme_icon');
  const lbl = document.getElementById('theme_lbl');
  if (!icon || !lbl) return;
  // Ikon menunjukkan tema AKTIF, label tetap "Ganti Tema (Light / Dark)"
  if (theme === 'light') { icon.textContent = '🌙'; }
  else { icon.textContent = '☀️'; }
  // Label tidak berubah, tetap "Ganti Tema (Light / Dark)"
}
*/
function toggleTheme() {
  const cur = document.documentElement.getAttribute('data-theme') || 'dark';
  const next = cur === 'dark' ? 'light' : 'dark';
  document.documentElement.setAttribute('data-theme', next);
  localStorage.setItem('app_theme', next);
  updateThemeUI(next);
  rethemeCharts(); // chart ikut berubah warna
}

function updateThemeUI(theme) {
  const icon = document.getElementById('theme_icon');
  const lbl = document.getElementById('theme_lbl');
  if (!icon || !lbl) return;
  // Ikon menunjukkan tema AKTIF, label tetap "Ganti Tema (Light / Dark)"
  if (theme === 'light') { icon.textContent = '🌙'; }
  else { icon.textContent = '☀️'; }
  // Label tidak berubah, tetap "Ganti Tema (Light / Dark)"
}
function rethemeCharts() {
  const tick = cssVar('--text-sub'), grid = cssVar('--border-soft');
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
// Sinkronkan label button dengan theme yang sudah di-set di <head>
updateThemeUI(document.documentElement.getAttribute('data-theme') || 'dark');

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
<tr class="hover:bg-[#4a4960]/50 transition">
<td class="py-2.5 font-sans font-semibold" style="color:${labelColor};">${label}<span class="block text-[9px] font-normal" style="color: var(--text-sub);">${d.count} invoice outstanding</span></td>
<td class="py-2.5 text-right" style="color: var(--text-main);">${formatNum(d.age_30)}</td>
<td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(d.age_60)}</td>
<td class="py-2.5 text-right font-medium" style="color: var(--text-main);">${formatNum(d.age_90)}</td>
<td class="py-2.5 text-right font-semibold" style="color: var(--text-sub);">${formatNum(d.age_over_90)}</td>
<td class="py-2.5 text-right font-bold" style="color:${totalColor};">${formatNum(d.total)}</td>
</tr>`;
const net = (k) => (ar[k] || 0) - (ap[k] || 0);
tbody.innerHTML = row('Piutang (AR)', '#728E7E', ar, '#728E7E') + row('Hutang (AP)', '#949C8B', ap, '#C2B8AD') + `
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
const levelBorder = { danger: '#B1A8A8', warn: '#C2B8AD', info: '#728E7E' };
const levelText = { danger: '#B1A8A8', warn: '#C2B8AD', info: '#728E7E' };
let html = active.map(i => `
<li class="flex justify-between items-center p-2 rounded" style="background-color: var(--bg-inner); border-left: 4px solid ${levelBorder[i.level] || '#728E7E'}; color: ${levelText[i.level] || '#728E7E'};" title="${esc(i.hint)}">
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
const border = late ? '#B1A8A8' : '#728E7E';
const textColor = late ? '#B1A8A8' : '#C2B8AD';
const subColor = late ? '#B1A8A8' : '#B1A8A8';
return `
<div class="flex justify-between items-center p-2 rounded" style="background-color: var(--bg-inner); border-left: 2px solid ${border};">
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
return `<tr class="hover:bg-[#4a4960]/50"><td class="py-2 font-sans text-xs" style="color: var(--text-main);">${esc(p.name)}</td>
<td class="py-2 text-right" style="color: var(--accent);">${p.progress}%</td>
<td class="py-2 text-right" style="color: var(--text-main);">${fmtRp(p.contract_dpp)}</td>
<td class="py-2 text-right" style="color: var(--text-main);">${fmtRp(p.billed_dpp)}</td>
<td class="py-2 text-right" style="color: var(--text-main);" title="${esc(detail)}">${fmtRp(p.cost_total)}</td>
<td class="py-2 text-right" style="color:${cColor};">${cPct.toFixed(1)}%</td>
<td class="py-2 text-right font-bold" style="color:${mColor};">${hasMargin ? margin.toFixed(1) + '%' : '-'}</td></tr>`;
}).join('');
} else { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-xs" style="color: var(--text-sub);">Tidak ada data profitabilitas.</td></tr>'; }
} catch (err) { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-xs" style="color: var(--text-sub);">Error memuat data</td></tr>'; }
}
const HEALTH_STYLE = {
KRITIS:  { badge: 'badge-kritis', icon: '🔴', label: 'KRITIS', num: '#B1A8A8', bar: '#B1A8A8' },
WASPADA: { badge: 'badge-waspada', icon: '🟡', label: 'WASPADA', num: '#C2B8AD', bar: '#C2B8AD' },
NORMAL:  { badge: 'badge-normal', icon: '🟢', label: 'NORMAL', num: '#728E7E', bar: '#728E7E' },
NA:      { badge: 'badge-normal', icon: '⚪', label: 'BUDGET KOSONG', num: '#949C8B', bar: '#949C8B' }
};
const HEALTH_ICONS = ['🏗️', '🏢', '🏘️', '🏭', '🏠'];
const RISK_STYLE = {
HIGH:   { badge: 'badge-kritis', text: '#B1A8A8' },
MEDIUM: { badge: 'badge-waspada', text: '#C2B8AD' },
LOW:    { badge: 'badge-normal', text: '#728E7E' }
};
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
card.querySelectorAll('.health-num').forEach(el => { el.style.color = st.num; });
card.setAttribute('data-level', target);
}
function selectHealthProject(id, refresh) {
healthSelectedId = id;
document.querySelectorAll('#health_cards [data-pid]').forEach(el => {
const on = parseInt(el.getAttribute('data-pid'), 10) === id;
el.style.borderColor = on ? 'var(--accent)' : 'var(--border-soft)';
el.style.borderWidth = on ? '2px' : '1px';
});
loadProjectAI(id, !!refresh);
}
async function loadProjectAI(id, refresh) {
const box = document.getElementById('health_reco');
const token = ++aiRequestToken;
const card = document.querySelector(`#health_cards [data-pid="${id}"]`);
const pname = card ? card.getAttribute('data-name') : '';
box.innerHTML = `<div class="border rounded-lg p-4 text-sm animate-pulse" style="background-color: var(--bg-inner); border-color: var(--border-strong); color: var(--text-main);">🧠 AI sedang menganalisis <b>${esc(pname)}</b> (biaya, material, subkon, jadwal, penagihan)...</div>`;
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_project_ai&project_id=${id}&refresh=${refresh ? 1 : 0}`);
if (token !== aiRequestToken) return;
if (!json.success) { box.innerHTML = `<div class="border rounded-lg p-3 text-sm" style="background-color: var(--bg-inner); border-color: var(--border-strong); color: var(--text-sub);">${esc(json.message || 'Gagal memuat rekomendasi AI')}</div>`; return; }
const d = json.data, rs = RISK_STYLE[d.risk] || RISK_STYLE.LOW;
const li = (arr) => arr.map(t => `<li>${esc(t)}</li>`).join('');
const findings = (d.findings || []).map(f => `
<div class="border-l-4 rounded px-2 py-1 mb-1" style="background-color: var(--bg-inner); border-left-color: var(--accent);">
<div class="text-xs font-bold" style="color: var(--text-main);">${esc(f.title)}</div><div class="text-[11px] opacity-80" style="color: var(--text-sub);">${esc(f.evidence)}</div></div>`).join('');
box.innerHTML = `
<div class="border rounded-lg p-4 max-h-96 overflow-y-auto no-scrollbar" style="background-color: var(--bg-inner); border-color: var(--border-strong);">
<div class="flex flex-wrap items-center gap-2 mb-2">
<span class="font-bold text-base" style="color: var(--accent);">🧠 AI Recommendation</span>
<span class="px-2 py-0.5 text-[11px] font-bold border rounded-md ${rs.badge}">RISK: ${esc(d.risk)}</span>
<span class="px-2 py-0.5 text-[10px] border rounded-md" style="border-color: var(--accent-2); color: var(--accent-2);">${d.ai_used ? 'Gemini' : 'Analisis sistem'}${d.cached ? ' • cache' : ''}</span>
<span class="text-[11px] ml-auto" style="color: var(--text-sub);">${esc(d.generated_at || '')}${d.log_id ? ' • log #' + d.log_id : ''}</span>
<button type="button" class="aiRefresh px-3 py-1 text-[11px] font-bold rounded text-white cursor-pointer border-0 btn-accent">Analisis Ulang</button>
</div>
<div class="italic text-base mb-2" style="color: var(--text-main);"><b>${esc(d.project_name)}:</b> ${esc(d.headline)}</div>
<div class="text-sm mb-2 leading-relaxed" style="color: var(--text-main);">${esc(d.summary)}</div>
${d.actions.length ? `<div class="text-xs font-bold mt-2 mb-1" style="color: var(--accent);">Tindakan yang disarankan</div><ul class="list-disc pl-5 text-sm space-y-1" style="color: var(--text-main);">${li(d.actions)}</ul>` : ''}
${d.questions && d.questions.length ? `<div class="text-xs font-bold mt-3 mb-1" style="color: var(--text-main);">Perlu dikonfirmasi</div><ul class="list-disc pl-5 text-sm space-y-1" style="color: var(--text-main);">${li(d.questions)}</ul>` : ''}
${findings ? `<div class="text-xs font-bold mt-3 mb-1" style="color: var(--text-sub);">Temuan sistem (${d.findings.length})</div>${findings}` : ''}
${d.ai_note ? `<div class="text-[11px] mt-2" style="color: var(--text-main);">${esc(d.ai_note)}</div>` : ''}
</div>`;
box.querySelector('.aiRefresh').onclick = () => loadProjectAI(id, true);
applyAiRiskToCard(id, d.risk);
} catch (err) { if (token === aiRequestToken) box.innerHTML = '<div class="border rounded-lg p-3 text-sm" style="background-color: var(--bg-inner); border-color: var(--border-strong); color: var(--text-sub);">Error memuat rekomendasi AI</div>'; }
}
async function loadProjectHealth() {
const box = document.getElementById('health_cards'), reco = document.getElementById('health_reco');
try {
const json = await apiFetch(`${API_ENDPOINT}?action=read_project_health&project_id=${currentProjectId}`);
if (!json.success) { box.innerHTML = `<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">${esc(json.message || 'Gagal memuat kesehatan proyek')}</div>`; reco.innerHTML = ''; return; }
if (!json.data.length) { box.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Tidak ada proyek berstatus Berjalan.</div>'; reco.innerHTML = ''; return; }
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
return `
<div class="border rounded-xl p-3 cursor-pointer transition" data-pid="${p.id}" data-name="${esc(p.name)}" data-level="${p.level}" title="${esc(detail)}" style="background-color: var(--bg-inner); border-color: var(--border-soft);" onmouseover="this.style.borderColor='var(--accent)';" onmouseout="this.style.borderColor='var(--border-soft)';">
<div class="flex justify-between items-start gap-2">
<div class="font-bold text-base leading-tight" style="color: var(--text-main);">${HEALTH_ICONS[i % HEALTH_ICONS.length]} ${esc(p.name)}</div>
<span class="health-badge px-2 py-0.5 text-[11px] font-bold border rounded-md whitespace-nowrap ${st.badge}">${st.icon} ${st.label}${p.ai_risk && p.level !== 'NORMAL' ? ' • AI' : ''}</span>
</div>
<div class="flex flex-wrap gap-x-4 text-sm mt-2" style="color: var(--text-sub);">
<span>Progres: <b style="color: var(--text-main);">${p.progress}%</b></span>
<span>Pemakaian: <b class="health-num" style="color:${st.num};">${hasUsage ? Number(p.usage_pct).toFixed(0) + '%' : '-'}</b></span>
<span>Gap: <b class="health-num" style="color:${st.num};">${gapTxt}</b></span>
</div>
<div class="mt-2 space-y-1">
<div class="h-1.5 rounded" style="background-color: var(--bg-deep);"><div class="h-1.5 rounded" style="width:${w1}%; background-color: var(--accent);"></div></div>
<div class="h-1.5 rounded" style="background-color: var(--bg-deep);"><div class="h-1.5 rounded" style="width:${w2}%; background-color:${st.bar};"></div></div>
</div>
</div>`;
}).join('');
box.querySelectorAll('[data-pid]').forEach(el => { el.onclick = () => selectHealthProject(parseInt(el.getAttribute('data-pid'), 10), false); });
const ids = json.data.map(p => p.id);
const pick = ids.includes(healthSelectedId) ? healthSelectedId : (ids.includes(parseInt(currentProjectId, 10)) ? parseInt(currentProjectId, 10) : ids[0]);
selectHealthProject(pick, false);
} catch (err) { console.error('Error load project health:', err); box.innerHTML = '<div class="text-center text-xs py-4 col-span-full" style="color: var(--text-sub);">Error memuat kesehatan proyek</div>'; }
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
ppnEl.style.color = d.ppn.net > 0 ? 'var(--text-main)' : 'var(--accent)';
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
} catch (err) { document.getElementById('tax_rate_tbody').innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Gagal memuat kurs pajak</td></tr>'; }
}
function filterTaxRates() {
const query = (document.getElementById('search_tax_currency').value || '').trim().toLowerCase();
const filtered = globalTaxRates.filter(r => (r.currency_code && r.currency_code.toLowerCase().includes(query)) || (r.currency_name && r.currency_name.toLowerCase().includes(query)));
renderTaxRates(filtered);
}
function renderTaxRates(rates) {
const tbody = document.getElementById('tax_rate_tbody');
if (!rates || rates.length === 0) { tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center" style="color: var(--text-sub);">Mata uang tidak ditemukan.</td></tr>'; return; }
tbody.innerHTML = rates.map(r => {
const dirColor = r.change_direction === 'UP' ? 'var(--accent)' : (r.change_direction === 'DOWN' ? 'var(--text-sub)' : 'var(--accent-2)');
const prefix = r.change_direction === 'UP' ? '+' : (r.change_direction === 'DOWN' ? '-' : '');
return `<tr class="hover:bg-[#4a4960]/50"><td class="p-1.5 font-bold" style="color: var(--text-main);">${r.currency_code}</td>
<td class="p-1.5 text-right" style="color: var(--text-main);">${formatIDR(r.rate)}</td>
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
el.style.color = 'var(--text-sub)';
el.textContent = msg;
}
function renderFinChart(labels, incData, expData) {
const ctx = document.getElementById('finChartCanvas').getContext('2d');
if (finChart) finChart.destroy();
const compact = (v) => Number(v).toLocaleString('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
const tick = cssVar('--text-sub'), grid = cssVar('--border-soft');
finChart = new Chart(ctx, {
type: 'bar',
data: { labels: labels, datasets: [{ label: 'Pendapatan', data: incData, backgroundColor: cssVar('--accent') }, { label: 'Pengeluaran', data: expData, backgroundColor: cssVar('--text-sub') }] },
options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: tick, font: { size: 9 } } }, tooltip: { callbacks: { label: (c) => ` ${c.dataset.label}: ${formatIDR(c.parsed.y)}` } } }, scales: { x: { ticks: { color: tick, font: { size: 8 } }, grid: { color: grid } }, y: { beginAtZero: true, ticks: { color: tick, font: { size: 8 }, callback: compact }, grid: { color: grid } } } }
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
if (aggregatedItems.length === 0) { tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">Tidak ada data penggunaan material untuk proyek ini.</td></tr>'; return; }
tbody.innerHTML = aggregatedItems.map(item => {
let statusBg = 'rgba(114,142,126,0.15)';
let statusText = '#728E7E';
let statusBorder = 'rgba(114,142,126,0.5)';
if (item.status === 'WARNING') { statusBg = 'rgba(194,184,173,0.15)'; statusText = '#C2B8AD'; statusBorder = 'rgba(194,184,173,0.5)'; }
if (item.status === 'OVERBUDGET') { statusBg = 'rgba(177,168,168,0.15)'; statusText = '#B1A8A8'; statusBorder = 'rgba(177,168,168,0.5)'; }
return `<tr class="hover:bg-[#4a4960]/50 transition">
<td class="p-3 font-sans"><div class="font-bold" style="color: var(--text-main);">${item.item_name}</div><div class="text-sm" style="color: var(--accent-2);">${item.item_code || '-'}</div></td>
<td class="p-3 text-center" style="color: var(--text-main);">${item.unit || '-'}</td>
<td class="p-3 text-right" style="color: var(--text-main);">${formatNum(item.budget_qty)}</td>
<td class="p-3 text-right font-bold" style="color: var(--text-main);">${formatIDR(item.budget_value)}</td>
<td class="p-3 text-right" style="color: var(--accent);">${formatNum(item.out_qty)}</td>
<td class="p-3 text-right" style="color: var(--accent-2);">${formatIDR(item.out_value)}</td>
<td class="p-3 text-right" style="color:${item.remaining_qty < 0 ? 'var(--text-sub)' : 'var(--text-main)'};">${formatNum(item.remaining_qty)}</td>
<td class="p-3 text-center font-bold" style="color: var(--text-main);">${item.usage_pct}%</td>
<td class="p-3 text-center"><span class="px-2 py-0.5 text-[10px] border rounded-full" style="background:${statusBg}; color:${statusText}; border-color:${statusBorder};">${item.status}</span></td>
</tr>`;
}).join('');
} else {
console.error('Monitoring material gagal:', json.message);
['kpi_total_budget', 'kpi_material_out', 'kpi_sisa_budget'].forEach(id => document.getElementById(id).innerText = '-');
document.getElementById('kpi_usage_pct').innerText = '-';
tbody.innerHTML = `<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">${esc(json.message || 'Gagal memuat data monitoring material')}</td></tr>`;
}
} catch (err) { console.error('Error load material monitoring:', err); tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center" style="color: var(--text-sub);">Gagal memuat data monitoring material</td></tr>'; }
}
function renderDoughnutChart(counts) {
const ctx = document.getElementById('doughnutChartCanvas').getContext('2d');
if (doughnutChart) doughnutChart.destroy();
doughnutChart = new Chart(ctx, {
type: 'doughnut',
data: { labels: ['On Track', 'Warning', 'Overbudget'], datasets: [{ data: [counts.ON_TRACK || 0, counts.WARNING || 0, counts.OVERBUDGET || 0], backgroundColor: [cssVar('--accent'), cssVar('--text-main'), cssVar('--text-sub')], borderWidth: 0 }] },
options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: cssVar('--text-sub'), font: { size: 10 } } } } }
});
}
function renderBarMaterialChart(topItems) {
const ctx = document.getElementById('barMaterialCanvas').getContext('2d');
if (barMaterialChart) barMaterialChart.destroy();
const tick = cssVar('--text-sub'), grid = cssVar('--border-soft');
barMaterialChart = new Chart(ctx, {
type: 'bar',
data: { labels: topItems.map(i => i.item_name.substring(0, 15) + '...'), datasets: [{ label: 'Budget Qty', data: topItems.map(i => i.budget_qty), backgroundColor: cssVar('--accent') }, { label: 'Out Qty', data: topItems.map(i => i.out_qty), backgroundColor: cssVar('--accent-2') }] },
options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: tick, font: { size: 10 } } } }, scales: { x: { ticks: { color: tick, font: { size: 9 } }, grid: { color: grid } }, y: { ticks: { color: tick, font: { size: 9 } }, grid: { color: grid } } } }
});
}
</script>
</body>
</html>