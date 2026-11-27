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
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Profitabilitas Proyek (Biaya Riil vs Kontrak)</h2>
<span class="text-base text-slate-400">Kontrak DPP vs Biaya Riil</span>
</div>
<div class="overflow-x-auto">
<table id="profit_table" class="w-full text-left text-xs">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-3">Proyek</th>
<th class="p-3 text-right">Kontrak DPP (Rp)</th>
<th class="p-3 text-right">Biaya Riil (Rp)</th>
<th class="p-3 text-right">Margin</th>
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
<th class="p-2.5">Jenis</th>
<th class="p-2.5 text-right">0-30 Hari</th>
<th class="p-2.5 text-right">31-60 Hari</th>
<th class="p-2.5 text-right">61-90 Hari</th>
<th class="p-2.5 text-right">&gt; 90 Hari</th>
<th class="p-2.5 text-right">Total Belum Dibayar</th>
<th class="p-2.5 text-center">Jml Dokumen</th>
</tr>
</thead>
<tbody id="aging_table_body" class="divide-y divide-[#0a2d4a]/50 text-slate-300">
<tr><td colspan="7" class="p-4 text-center text-slate-500">Memuat data aging piutang &amp; hutang...</td></tr>
</tbody>
</table>
</div>
</div>

<!-- INVOICE PIPELINE (TERMIN KLIEN) -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Invoice Pipeline (Tagihan Termin Klien)</h2>
<span class="text-base text-slate-400">Status tagihan resmi</span>
</div>
<div id="invoice_pipeline" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="text-base text-slate-500">Memuat pipeline invoice...</div>
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
<div id="extra_financial_cards" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
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
<span class="text-base text-slate-400">Saldo Kas &amp; Bank</span>
<span id="extra_net_cash" class="text-2xl font-bold text-cyan-400 font-rajdhani mt-1 block">Rp 0</span>
<span id="extra_net_sub" class="text-[11px] text-slate-500">Jurnal terposting (semua proyek)</span>
</div>
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<span class="text-base text-slate-400">PPN Bulan Berjalan (Netto)</span>
<span id="extra_ppn_total" class="text-2xl font-bold text-white font-rajdhani mt-1 block">Rp 0</span>
<span id="extra_ppn_sub" class="text-[11px] text-slate-500">Keluaran - Masukan</span>
</div>
</div>

</section>

<!-- TAB 3: MATERIAL & RAB -->
<section id="tab_material" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="tabbtn_material">

<!-- MATERIAL CHARTS -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani mb-3">Status Pemakaian Material vs RAB</h2>
<div class="relative w-full h-52 flex items-center justify-center"><canvas id="doughnutChartCanvas"></canvas></div>
</div>
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani mb-3">Top 5 Pemakaian Material (% dari RAB)</h2>
<div class="relative w-full h-52"><canvas id="barMaterialCanvas"></canvas></div>
</div>
</div>

<!-- MATERIAL MONITORING TABLE -->
<div class="bg-[#04101c] p-5 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-4">
<h2 class="text-xl font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Monitoring Material &amp; Inventaris Proyek</h2>
<span id="material_kpi" class="text-base text-slate-400">RAB vs material keluar (posted)</span>
</div>
<div class="overflow-x-auto">
<table id="material_table" class="w-full text-left text-sm">
<thead>
<tr class="bg-[#071d30] text-cyan-300 uppercase font-rajdhani border-b border-[#0a2d4a]">
<th class="p-3">Kode / Item Material</th>
<th class="p-3 text-center">Satuan</th>
<th class="p-3 text-right">Vol RAB</th>
<th class="p-3 text-right">Vol Keluar</th>
<th class="p-3 text-right">Sisa</th>
<th class="p-3 text-right">Nilai RAB (Rp)</th>
<th class="p-3 text-right">Nilai Keluar (Rp)</th>
<th class="p-3 text-right">Pemakaian</th>
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
let finYearsLoaded = false;

const formatNum = (num) => Number(num || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const fmtRp = (n) => 'Rp ' + Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
const esc = (s) => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const setText = (id, v) => { const el = document.getElementById(id); if (el) el.textContent = v; };
const fmtDate = (s) => {
    if (!s) return '-';
    const d = new Date(s);
    return isNaN(d) ? esc(s) : d.toLocaleDateString('id-ID', { day: '2-digit', month: 'short', year: 'numeric' });
};

// Semua panggilan API lewat sini: error (HTTP 500 / bukan JSON / success:false) selalu dilempar
// sehingga tampil di layar, tidak lagi diam-diam menghasilkan angka 0.
async function api(action, params = {}) {
    const qs = new URLSearchParams({ action, ...params });
    const res = await fetch(`${API_ENDPOINT}?${qs}`, { credentials: 'same-origin' });
    const txt = await res.text();
    let json;
    try { json = JSON.parse(txt); }
    catch (e) { throw new Error(`Respons bukan JSON (HTTP ${res.status}). Cek error_log PHP.`); }
    if (res.status === 401) { location.href = 'login.php'; throw new Error(json.message || 'Sesi berakhir'); }
    if (!json.success) throw new Error(json.message || 'Permintaan gagal');
    return json;
}

function toggleMobileMenu() {
    document.getElementById("mobile_dropdown").classList.toggle("hidden");
}
document.addEventListener("click", function (event) {
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
    keuangan: () => { if (!globalTaxRates.length) loadTaxRates(); loadFinancialAging(); loadInvoicePipeline(); loadExtra(); loadFinancialPerformance(); },
    material: () => { loadMaterialMonitoring(); }
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
        panel.classList.toggle('active', panel.id === 'tab_' + name);
    });
    if (tabDirty[name]) {
        tabDirty[name] = false;
        TAB_LOADERS[name]();
    }
    if (TAB_CHARTS[name]) {
        setTimeout(() => { TAB_CHARTS[name]().forEach(c => { if (c) c.resize(); }); }, 50);
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

// ---------- Init ----------
async function initDashboard() {
    // Kurs pajak: backend selalu mengirim KMK terbaru, pemilih tahun tidak dipakai.
    const ky = document.getElementById('kmk_year_select');
    if (ky && ky.parentElement) ky.parentElement.classList.add('hidden');

    try {
        const json = await api('get_projects');
        const sel = document.getElementById('project_select');
        sel.innerHTML = '';
        if (json.data && json.data.length > 0) {
            json.data.forEach((p, idx) => {
                const opt = document.createElement('option');
                opt.value = p.id;
                opt.textContent = p.project_name || p.name || ('Proyek #' + p.id);
                if (idx === 0) opt.selected = true;
                sel.appendChild(opt);
            });
            currentProjectId = parseInt(json.data[0].id) || 0;
        } else {
            sel.innerHTML = '<option value="0">Tidak ada proyek tersedia</option>';
        }
    } catch (e) {
        console.error('Gagal memuat proyek:', e);
        document.getElementById('project_select').innerHTML = '<option value="0">Gagal memuat proyek</option>';
    }
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

// ---------- Loader 1: Kesehatan proyek + kartu statistik ----------
const HEALTH_RANK = { KRITIS: 3, WASPADA: 2, NORMAL: 1, NA: 0 };
const BADGE_BASE = 'px-2.5 py-1 rounded text-base font-bold ';
async function loadProjectHealth() {
    const pid = currentProjectId;
    const badge = document.getElementById('health_badge');
    const desc = document.getElementById('health_desc');
    try {
        const [h, c] = await Promise.all([
            api('read_project_health', { project_id: pid }),
            api('read_summary_counts', { project_id: pid }).catch(() => null)
        ]);
        if (pid !== currentProjectId) return;

        const rows = h.data || [];
        const budget = rows.reduce((a, r) => a + Number(r.budget || 0), 0);
        const cost = rows.reduce((a, r) => a + Number(r.cost_total || 0), 0);
        const progress = rows.length ? rows.reduce((a, r) => a + Number(r.progress || 0), 0) / rows.length : 0;

        setText('stat_total_rab', fmtRp(budget));
        setText('stat_total_exp', fmtRp(cost));
        setText('stat_remaining', fmtRp(budget - cost));
        setText('stat_physical_prog', progress.toFixed(1) + '%');
        if (c && c.data) {
            setText('summary_po_count', c.data.po_count || 0);
            setText('summary_grn_count', c.data.grn_count || 0);
            setText('summary_inv_count', c.data.inv_count || 0);
        }
        document.getElementById('health_progress_bar').style.width = Math.min(Math.max(progress, 0), 100) + '%';

        let level = 'NA';
        rows.forEach(r => { if ((HEALTH_RANK[r.level] ?? 0) > (HEALTH_RANK[level] ?? 0) || level === 'NA') level = r.level || level; });
        const reasons = [];
        rows.forEach(r => {
            const rs = Array.isArray(r.reasons) ? r.reasons : (r.reasons ? [String(r.reasons)] : []);
            rs.forEach(x => reasons.push(typeof x === 'string' ? x : JSON.stringify(x)));
        });
        let info = '';
        if (rows.length === 1) {
            const r = rows[0];
            const parts = [];
            if (r.usage_pct != null && isFinite(Number(r.usage_pct))) parts.push(`Pemakaian anggaran ${Number(r.usage_pct).toFixed(1)}% vs progres ${Number(r.progress).toFixed(0)}%`);
            if (r.cpi != null && isFinite(Number(r.cpi))) parts.push(`CPI ${Number(r.cpi).toFixed(2)}`);
            if (Number(r.late_days) > 0) parts.push(`terlambat ${r.late_days} hari`);
            if (r.ai_risk) parts.push(`AI: ${r.ai_risk}`);
            info = parts.join(' · ');
        }
        const tail = reasons.length ? ' ' + reasons.join('; ') + '.' : '';

        if (level === 'NORMAL') {
            badge.className = BADGE_BASE + 'bg-emerald-950 text-emerald-300 border border-emerald-800';
            badge.textContent = 'SEHAT (NORMAL)';
            desc.textContent = (info || 'Proyek berjalan sesuai anggaran dan jadwal.') + tail;
        } else if (level === 'WASPADA') {
            badge.className = BADGE_BASE + 'bg-yellow-950 text-yellow-300 border border-yellow-800';
            badge.textContent = 'WASPADA';
            desc.textContent = (info || 'Ada indikator yang perlu dipantau.') + tail;
        } else if (level === 'KRITIS') {
            badge.className = BADGE_BASE + 'bg-rose-950 text-rose-300 border border-rose-800';
            badge.textContent = 'KRITIS';
            desc.textContent = (info || 'Biaya/jadwal melampaui batas kritis.') + tail;
        } else {
            badge.className = BADGE_BASE + 'bg-slate-800 text-slate-300';
            badge.textContent = 'N/A';
            desc.textContent = rows.length ? (info || 'Data belum cukup untuk menilai kesehatan proyek.') + tail
                                           : 'Proyek tidak ditemukan atau belum berstatus Berjalan.';
        }
    } catch (e) {
        console.error('Gagal memuat kesehatan proyek:', e);
        badge.className = BADGE_BASE + 'bg-rose-950 text-rose-300 border border-rose-800';
        badge.textContent = 'GAGAL';
        desc.textContent = e.message;
    }
}

// ---------- Loader 2: Action Center ----------
const LEVEL_STYLE = {
    danger: { box: 'border-rose-900/50', title: 'text-rose-300', badge: 'bg-rose-950 text-rose-300 border border-rose-800', icon: '🔴' },
    warn:   { box: 'border-[#0a2d4a]',   title: 'text-yellow-300', badge: 'bg-yellow-950 text-yellow-300 border border-yellow-800', icon: '🟡' },
    info:   { box: 'border-[#0a2d4a]',   title: 'text-cyan-300',   badge: 'bg-cyan-950 text-cyan-300 border border-cyan-800', icon: '🔵' }
};
async function loadActionCenter() {
    const pid = currentProjectId;
    const list = document.getElementById('action_center_list');
    const badge = document.getElementById('action_count_badge');
    list.innerHTML = '<div class="text-base text-slate-500 text-center py-4">Memindai isu...</div>';
    try {
        const json = await api('read_action_center', { project_id: pid });
        if (pid !== currentProjectId) return;
        const order = { danger: 0, warn: 1, info: 2 };
        const items = (json.items || []).filter(i => Number(i.count) > 0)
            .sort((a, b) => (order[a.level] ?? 3) - (order[b.level] ?? 3) || b.count - a.count);
        if (!items.length) {
            list.innerHTML = '<div class="text-base text-emerald-400 text-center py-6">🎉 Tidak ada isu kritis. Semua operasional lancar!</div>';
            badge.textContent = '0 Isu';
            badge.className = 'px-2 py-0.5 bg-emerald-950 text-emerald-300 border border-emerald-800 rounded text-base font-bold';
            return;
        }
        const hasDanger = items.some(i => i.level === 'danger');
        badge.textContent = items.length + ' Isu';
        badge.className = 'px-2 py-0.5 rounded text-base font-bold border ' +
            (hasDanger ? 'bg-rose-950 text-rose-300 border-rose-800' : 'bg-yellow-950 text-yellow-300 border-yellow-800');
        list.innerHTML = '';
        items.forEach(item => {
            const st = LEVEL_STYLE[item.level] || LEVEL_STYLE.info;
            const div = document.createElement('div');
            div.className = `p-3 rounded-lg bg-[#071d30]/60 border ${st.box} flex items-start space-x-3`;
            div.innerHTML = `
<div class="text-xl">${st.icon}</div>
<div class="flex-1">
<div class="flex justify-between items-center"><strong class="${st.title} text-base">${esc(item.label)}</strong><span class="px-2 py-0.5 rounded text-xs font-bold ${st.badge}">${Number(item.count)}</span></div>
<p class="text-base text-slate-300 mt-0.5">${esc(item.hint || '')}</p>
</div>`;
            list.appendChild(div);
        });
    } catch (e) {
        console.error('Action center:', e);
        list.innerHTML = `<div class="text-base text-rose-400 text-center py-4">Gagal memuat action center: ${esc(e.message)}</div>`;
    }
}

// ---------- Loader 3: Milestone (task proyek) ----------
async function loadMilestones() {
    const pid = currentProjectId;
    const list = document.getElementById('milestone_list');
    list.innerHTML = '<div class="text-base text-slate-500 text-center py-4">Memuat milestone...</div>';
    try {
        const json = await api('read_upcoming_milestones', { project_id: pid });
        if (pid !== currentProjectId) return;
        const rows = json.data || [];
        if (!rows.length) {
            list.innerHTML = '<div class="text-base text-slate-400 text-center py-6">Tidak ada task jatuh tempo dalam 14 hari ke depan.</div>';
            return;
        }
        list.innerHTML = '';
        rows.forEach(m => {
            const late = !!m.late;
            const days = Number(m.days_left);
            const label = late ? `Terlambat ${Math.abs(days)} hr` : (days === 0 ? 'Hari ini' : `${days} hr lagi`);
            const div = document.createElement('div');
            div.className = 'p-3 rounded-lg bg-[#071d30]/40 border border-[#0a2d4a] flex items-center justify-between';
            div.innerHTML = `
<div class="flex items-center space-x-3">
<div class="w-8 h-8 rounded-full flex items-center justify-center font-bold text-base ${late ? 'bg-rose-950 text-rose-300 border border-rose-800' : 'bg-slate-800 text-slate-300'}">${late ? '!' : '•'}</div>
<div>
<h4 class="text-base font-semibold text-white">${esc(m.task_name)}</h4>
<span class="text-xs text-slate-400">${pid ? '' : esc(m.project_name) + ' · '}Target: ${fmtDate(m.deadline)} · ${esc(m.status)}</span>
</div>
</div>
<span class="px-2 py-1 rounded text-xs font-semibold ${late ? 'bg-rose-950 text-rose-300 border border-rose-800' : 'bg-yellow-950 text-yellow-300 border border-yellow-800'}">${label}</span>`;
            list.appendChild(div);
        });
    } catch (e) {
        console.error('Milestone:', e);
        list.innerHTML = `<div class="text-base text-rose-400 text-center py-4">Gagal memuat milestone: ${esc(e.message)}</div>`;
    }
}

// ---------- Loader 4: Profitabilitas proyek ----------
async function loadProfitability() {
    const pid = currentProjectId;
    const tbody = document.getElementById('profit_table_body');
    tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-slate-500">Memuat analisis profitabilitas...</td></tr>';
    try {
        const json = await api('read_project_profitability', { project_id: pid });
        if (pid !== currentProjectId) return;
        const rows = json.data || [];
        if (!rows.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-4 text-center text-slate-500">Tidak ada proyek Berjalan/Selesai dengan nilai kontrak untuk filter ini.</td></tr>';
            return;
        }
        tbody.innerHTML = '';
        rows.forEach(r => {
            const margin = r.margin_pct;
            let cls, txt, mtxt;
            if (margin === null || margin === undefined) { cls = 'bg-slate-800 text-slate-300 border border-slate-700'; txt = 'BELUM DITAGIH'; mtxt = '-'; }
            else if (Number(margin) >= 0) { cls = 'bg-emerald-950 text-emerald-300 border border-emerald-800'; txt = 'SURPLUS'; mtxt = Number(margin).toFixed(1) + '%'; }
            else { cls = 'bg-rose-950 text-rose-300 border border-rose-800'; txt = 'DEFISIT'; mtxt = Number(margin).toFixed(1) + '%'; }
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-[#071d30]/40 transition';
            tr.innerHTML = `
<td class="p-3 font-semibold text-white">${esc(r.name)}<br><span class="text-xs text-slate-400 font-normal">${esc(r.status)} · progres ${Number(r.progress)}%</span></td>
<td class="p-3 text-right text-slate-300">${fmtRp(r.contract_dpp)}<br><span class="text-xs text-slate-400">Ditagih ${fmtRp(r.billed_dpp)}</span></td>
<td class="p-3 text-right text-yellow-300">${fmtRp(r.cost_total)}<br><span class="text-xs text-slate-400">${Number(r.cost_pct)}% dari kontrak</span></td>
<td class="p-3 text-right font-bold ${margin === null || Number(margin) >= 0 ? 'text-emerald-400' : 'text-rose-400'}">${mtxt}</td>
<td class="p-3 text-center"><span class="px-2 py-0.5 rounded text-xs font-semibold ${cls}">${txt}</span></td>`;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error('Profitabilitas:', e);
        tbody.innerHTML = `<tr><td colspan="5" class="p-4 text-center text-rose-400">Gagal memuat profitabilitas: ${esc(e.message)}</td></tr>`;
    }
}

// ---------- Loader 5: Aging piutang & hutang (bucket) ----------
async function loadFinancialAging() {
    const pid = currentProjectId;
    const tbody = document.getElementById('aging_table_body');
    tbody.innerHTML = '<tr><td colspan="7" class="p-4 text-center text-slate-500">Memuat data aging piutang &amp; hutang...</td></tr>';
    try {
        const j = await api('read_financial_aging', { project_id: pid });
        if (pid !== currentProjectId) return;
        const defs = [
            { label: 'Piutang Klien (AR)', d: j.ar, badge: 'bg-cyan-950 text-cyan-300 border border-cyan-800', tag: 'AR' },
            { label: 'Hutang Supplier & Subkon (AP)', d: j.ap, badge: 'bg-purple-950 text-purple-300 border border-purple-800', tag: 'AP' }
        ];
        tbody.innerHTML = '';
        defs.forEach(x => {
            const d = x.d || {};
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-[#071d30]/40 transition';
            tr.innerHTML = `
<td class="p-2.5 font-semibold text-white"><span class="px-2 py-0.5 rounded text-[10px] font-semibold ${x.badge}">${x.tag}</span> ${x.label}</td>
<td class="p-2.5 text-right text-slate-300">${fmtRp(d.age_30)}</td>
<td class="p-2.5 text-right text-slate-300">${fmtRp(d.age_60)}</td>
<td class="p-2.5 text-right text-yellow-300">${fmtRp(d.age_90)}</td>
<td class="p-2.5 text-right text-rose-400">${fmtRp(d.age_over_90)}</td>
<td class="p-2.5 text-right font-bold text-white">${fmtRp(d.total)}</td>
<td class="p-2.5 text-center text-slate-400">${Number(d.count || 0)}</td>`;
            tbody.appendChild(tr);
        });
        // kartu ringkasan Keuangan
        setText('extra_ar_total', fmtRp((j.ar || {}).total));
        setText('extra_ap_total', fmtRp((j.ap || {}).total));
        setText('extra_ar_sub', `${Number((j.ar || {}).count || 0)} invoice klien belum lunas`);
        setText('extra_ap_sub', `${Number((j.ap || {}).count || 0)} tagihan supplier/subkon belum lunas`);
    } catch (e) {
        console.error('Aging:', e);
        tbody.innerHTML = `<tr><td colspan="7" class="p-4 text-center text-rose-400">Gagal memuat aging: ${esc(e.message)}</td></tr>`;
    }
}

// ---------- Loader 5b: Invoice pipeline (tagihan termin klien) ----------
async function loadInvoicePipeline() {
    const pid = currentProjectId;
    const box = document.getElementById('invoice_pipeline');
    if (!box) return;
    box.innerHTML = '<div class="text-base text-slate-500">Memuat pipeline invoice...</div>';
    try {
        const j = await api('read_invoice_pipeline', { project_id: pid });
        if (pid !== currentProjectId) return;
        const c = (j.data && j.data.counts) || {}, v = (j.data && j.data.values) || {};
        const defs = [
            ['Belum Bayar', 'text-white'],
            ['Sebagian', 'text-yellow-300'],
            ['Jatuh Tempo (Overdue)', 'text-rose-400'],
            ['Lunas', 'text-emerald-400']
        ];
        box.innerHTML = defs.map(([k, color]) => `
<div class="bg-[#071d30]/50 p-4 rounded-lg border border-[#0a2d4a] flex flex-col">
<span class="text-base text-slate-400">${esc(k)}</span>
<span class="text-2xl font-bold font-rajdhani ${color} mt-1">${Number(c[k] || 0)} invoice</span>
<span class="text-[11px] text-slate-500">Sisa tagihan ${fmtRp(v[k])}</span>
</div>`).join('');
    } catch (e) {
        console.error('Invoice pipeline:', e);
        box.innerHTML = `<div class="text-base text-rose-400">Gagal memuat pipeline invoice: ${esc(e.message)}</div>`;
    }
}

// ---------- Loader 6: Kurs pajak (KMK terbaru) ----------
async function loadTaxRates() {
    const tbody = document.getElementById('tax_rate_body');
    const badge = document.getElementById('kmk_badge');
    const validDate = document.getElementById('kmk_valid_date');
    const countSpan = document.getElementById('tax_rate_count');
    tbody.innerHTML = '<tr><td colspan="5" class="p-3 text-center text-slate-500">Memuat kurs pajak...</td></tr>';
    try {
        const j = await api('read_tax_rates');
        const rates = j.rates || [];
        globalTaxRates = rates;
        if (!rates.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="p-3 text-center text-slate-500">Belum ada data kurs pajak. Sinkronkan lewat menu Master Kurs Pajak.</td></tr>';
            badge.textContent = 'TIDAK ADA DATA';
            badge.className = 'text-xs bg-rose-950 text-rose-300 border border-rose-800 px-1.5 py-0.5 rounded inline-block';
            validDate.textContent = '';
            countSpan.textContent = '0 Kurs aktif';
            return;
        }
        badge.textContent = 'KMK ' + (j.kmk_number || '-');
        badge.className = 'text-xs bg-emerald-950 text-emerald-300 border border-emerald-800 px-1.5 py-0.5 rounded inline-block';
        validDate.textContent = `Periode: ${j.start_date} – ${j.end_date}`;
        countSpan.textContent = rates.length + ' Kurs aktif';
        tbody.innerHTML = '';
        rates.forEach(r => {
            const mult = Number(r.unit_multiplier) > 1 ? ` <span class="text-xs text-slate-400">(per ${Number(r.unit_multiplier)})</span>` : '';
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-[#071d30]/40 transition';
            tr.innerHTML = `
<td class="p-2 font-bold text-white">${esc(r.currency_code)}${mult}<br><span class="text-xs text-slate-400 font-normal">${esc(r.currency_name || '')}</span></td>
<td class="p-2 text-cyan-300">${esc(j.kmk_number || '-')}</td>
<td class="p-2 text-center text-slate-400">${esc(j.start_date)}</td>
<td class="p-2 text-center text-slate-400">${esc(j.end_date)}</td>
<td class="p-2 text-right font-bold text-emerald-400">${formatNum(r.rate)}</td>`;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error('Kurs pajak:', e);
        tbody.innerHTML = `<tr><td colspan="5" class="p-3 text-center text-rose-400">Gagal memuat kurs pajak: ${esc(e.message)}</td></tr>`;
    }
}

// ---------- Loader 7: Saldo kas & PPN ----------
async function loadExtra() {
    const pid = currentProjectId;
    try {
        const j = await api('read_extra', { project_id: pid });
        if (pid !== currentProjectId) return;
        const d = j.data || {};
        setText('extra_net_cash', d.cash === null || d.cash === undefined ? '-' : fmtRp(d.cash));
        if (d.ppn) {
            setText('extra_ppn_total', fmtRp(d.ppn.net));
            setText('extra_ppn_sub', `Keluaran ${fmtRp(d.ppn.keluaran)} − Masukan ${fmtRp(d.ppn.masukan)} (${d.ppn.periode})`);
        } else {
            setText('extra_ppn_total', '-');
        }
    } catch (e) {
        console.error('Extra:', e);
        setText('extra_net_cash', '-');
        setText('extra_ppn_sub', 'Gagal memuat: ' + e.message);
    }
}

// ---------- Loader 8: Performa keuangan bulanan ----------
async function loadFinancialPerformance() {
    const pid = currentProjectId;
    const sel = document.getElementById('fin_year_select');
    const emptyDiv = document.getElementById('fin_empty');
    const monthLabels = ['Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
    try {
        const j = await api('read_financial_performance', { year: sel.value, project_id: pid });
        if (pid !== currentProjectId) return;
        if (!finYearsLoaded && Array.isArray(j.available_years) && j.available_years.length) {
            sel.innerHTML = j.available_years.map(y => `<option value="${y}">${y}</option>`).join('');
            sel.value = String(j.year);
            finYearsLoaded = true;
        }
        const inc = j.datasets.pendapatan || [], exp = j.datasets.pengeluaran || [];
        if (inc.every(v => v === 0) && exp.every(v => v === 0)) {
            emptyDiv.textContent = `Tidak ada transaksi tercatat pada tahun ${j.year}.`;
            emptyDiv.classList.remove('hidden');
        } else {
            emptyDiv.classList.add('hidden');
        }
        renderFinChart(j.labels || monthLabels, inc, exp);
    } catch (e) {
        console.error('Performa keuangan:', e);
        renderFinChart(monthLabels, [], []);
        emptyDiv.textContent = 'Gagal memuat performa keuangan: ' + e.message;
        emptyDiv.classList.remove('hidden');
    }
}

const compactNum = (v) => new Intl.NumberFormat('id-ID', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
function renderFinChart(labels, incData, expData) {
    const ctx = document.getElementById('finChartCanvas').getContext('2d');
    if (finChart) finChart.destroy();
    finChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label: 'Pendapatan', data: incData, backgroundColor: '#06b6d4', borderRadius: 4 },
                { label: 'Pengeluaran', data: expData, backgroundColor: '#f59e0b', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top', labels: { color: '#94a3b8', font: { size: 10 } } },
                tooltip: { callbacks: { label: (c) => `${c.dataset.label}: ${fmtRp(c.parsed.y)}` } }
            },
            scales: {
                x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } },
                y: { ticks: { color: '#94a3b8', font: { size: 9 }, callback: (v) => compactNum(v) }, grid: { color: '#0a2d4a' } }
            }
        }
    });
}

// ---------- Loader 9: Monitoring material (RAB vs material keluar) ----------
const MAT_STATUS = {
    ON_TRACK:   { txt: 'ON TRACK',   badge: 'bg-emerald-950 text-emerald-300 border border-emerald-800', num: 'text-emerald-400' },
    WARNING:    { txt: 'WASPADA',    badge: 'bg-yellow-950 text-yellow-300 border border-yellow-800',   num: 'text-yellow-300' },
    OVERBUDGET: { txt: 'OVERBUDGET', badge: 'bg-rose-950 text-rose-300 border border-rose-800',         num: 'text-rose-400' }
};
async function loadMaterialMonitoring() {
    const pid = currentProjectId;
    const tbody = document.getElementById('material_table_body');
    tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data material proyek...</td></tr>';
    try {
        const j = await api('read_material_monitoring', { project_id: pid });
        if (pid !== currentProjectId) return;
        const items = j.items || [];
        const k = j.kpi || {};
        setText('material_kpi', `RAB ${fmtRp(k.budget_rab)} · Keluar ${fmtRp(k.material_out)} (${Number(k.persentase || 0).toFixed(1)}%)`);
        renderDoughnutChart(j.status_counts || {});
        renderBarMaterialChart(items.slice(0, 5));
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Tidak ada item material (RAB / material keluar) untuk proyek ini.</td></tr>';
            return;
        }
        tbody.innerHTML = '';
        items.forEach(it => {
            const st = MAT_STATUS[it.status] || MAT_STATUS.ON_TRACK;
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-[#071d30]/40 transition';
            tr.innerHTML = `
<td class="p-3 font-semibold text-white">${esc(it.item_code || '-')}<br><span class="text-xs text-slate-400 font-normal">${esc(it.item_name)}</span></td>
<td class="p-3 text-center text-slate-400">${esc(it.unit || '-')}</td>
<td class="p-3 text-right text-slate-300">${it.no_rab ? '<span class="text-xs text-rose-400">Tanpa RAB</span>' : formatNum(it.budget_qty)}</td>
<td class="p-3 text-right text-yellow-300">${formatNum(it.out_qty)}</td>
<td class="p-3 text-right text-emerald-400">${formatNum(it.remaining_qty)}</td>
<td class="p-3 text-right text-slate-300">${fmtRp(it.budget_value)}</td>
<td class="p-3 text-right font-bold text-white">${fmtRp(it.out_value)}</td>
<td class="p-3 text-right font-bold ${st.num}">${Number(it.usage_pct).toFixed(1)}%</td>
<td class="p-3 text-center"><span class="px-2 py-0.5 rounded text-xs font-semibold ${st.badge}">${st.txt}</span></td>`;
            tbody.appendChild(tr);
        });
    } catch (e) {
        console.error('Material:', e);
        tbody.innerHTML = `<tr><td colspan="9" class="p-4 text-center text-rose-400">Gagal memuat monitoring material: ${esc(e.message)}</td></tr>`;
        renderDoughnutChart({});
        renderBarMaterialChart([]);
    }
}

function renderDoughnutChart(counts) {
    const ctx = document.getElementById('doughnutChartCanvas').getContext('2d');
    if (doughnutChart) doughnutChart.destroy();
    doughnutChart = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['On Track', 'Waspada', 'Overbudget'],
            datasets: [{
                data: [counts.ON_TRACK || 0, counts.WARNING || 0, counts.OVERBUDGET || 0],
                backgroundColor: ['#10b981', '#f59e0b', '#f43f5e'],
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
            labels: topItems.map(i => i.item_name),
            datasets: [{
                label: 'Pemakaian vs RAB (%)',
                data: topItems.map(i => Number(i.usage_pct)),
                backgroundColor: topItems.map(i => i.status === 'OVERBUDGET' ? '#f43f5e' : (i.status === 'WARNING' ? '#f59e0b' : '#22d3ee')),
                borderRadius: 4
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { display: false } },
            scales: {
                x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } },
                y: { ticks: { color: '#94a3b8', font: { size: 9 }, callback: (v) => v + '%' }, grid: { color: '#0a2d4a' } }
            }
        }
    });
}
</script>
</body>
</html>
