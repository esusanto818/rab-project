<?php
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");  

// views/dashboard.php - FINAL VERSION
// [REVISI 2026-11-06] Dashboard dibagi 3 area bertab: Ringkasan Proyek | Keuangan | Material & RAB.
//   Data dimuat per tab (lazy) dan hanya dimuat ulang bila filter proyek berubah. Backend dashboardCRD.php TIDAK berubah.
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (empty($_SESSION['user_id'])) { header('Location: login.php'); exit; }
require_once __DIR__ . '/../php-func/function.all.php';
if (function_exists('app_require_active_user')) {
    app_require_active_user();
}
require_once __DIR__ . '/../dev/php-class/crudClass.php';
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
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard - RAB Project Monitor</title>
<script src="https://cdn.tailwindcss.com"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Orbitron:wght@500;700&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<style>
body { font-family: 'Inter', sans-serif; background-color: #000000; color: #e2e8f0; }
.font-orbitron { font-family: 'Orbitron', sans-serif; }
.font-rajdhani { font-family: 'Rajdhani', sans-serif; }
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
/* [REVISI 2026-10-30] Mega menu: dropdown dikaitkan ke <header> (selebar layar), BUKAN ke <li> yang sempit.
   Kolom grup tersusun ke SAMPING; baru turun ke baris berikutnya bila tidak muat. Lebar maksimum = lebar layar. */
.nav-item { position: static; }
.nav-item:hover .dropdown-menu { opacity: 1; visibility: visible; transform: translateY(0); }
.dropdown-menu {
    position: absolute; top: 100%; left: 0;
    width: max-content; max-width: calc(100vw - 16px); box-sizing: border-box;
    max-height: calc(100vh - 80px); overflow-y: auto;
    opacity: 0; visibility: hidden; transform: translateY(-6px); transition: all 0.2s ease-in-out;
}
.dropdown-menu .menu-wrap { display: flex; flex-wrap: wrap; align-items: flex-start; gap: 0 2rem; }

/* [FIX] Menu Horizontal Layout */
/* Menu Horizontal */
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
    font-size: 0.8rem;
    transition: all 0.2s;
    border-radius: 0.25rem;
    font-size:16px;
    font-weight: 300;
}
.menu-column ul li a:hover {
    color: #ee2222;
    background: rgba(252, 253, 253, 0.61);
    transform: translateX(2px);
}

/* [BARU 2026-11-06 TAB] Tab dashboard. Tombol mengikuti template "Print Task"; aktif = teal #066b78 */
.tab-btn { background:#1f2d3a; color:#ffffff; font-weight:700; font-size:15px; padding:8px 20px; border:none; border-radius:4px; cursor:pointer; transition:background .15s; }
.tab-btn:hover { background:#2b3f52; }
.tab-btn.active { background:#066b78; }
.tab-btn:focus-visible { outline:2px solid #22d3ee; outline-offset:2px; }
.tab-panel { display:none; }
.tab-panel.active { display:block; }
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
    <div class="flex items-center space-x-6">
        <a href="dashboard.php" class="flex items-center space-x-3 group">
            <?php if (file_exists(__DIR__ . '/../assets/favicon.ico')): ?>
                <img src="../assets/favicon.ico" alt="Logo" class="h-10 w-auto object-contain">
            <?php else: ?>
                <span class="font-arial text-xl font-normal text-white-400 tracking-wider group-hover:text-white-900 transition">[XYZ]SYS</span>
                <span class="text-base px-2 py-0.5 rounded bg-cyan-950 text-cyan-300 border border-cyan-800/50">v1.0</span>
            <?php endif; ?>
        </a>
            <ul class="flex items-center space-x-1 sm:space-x-3 font-rajdhani text-base tracking-wider font-semibold border-l border-[#0a2d4a] pl-4">
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
            <a href="#" class="py-2 px-2.5 font-normal text-xl text-cyan-500 hover:text-yellow-300 group-hover:text-yellow-300 flex items-center gap-1 transition-all rounded hover:bg-[#071d30]">
            <?= $romanNum . '. ' . strtoupper(htmlspecialchars($main['name'])); ?>
        </a>

        <?php if ($hasChildren): ?>
            <!-- [REVISI] dropdown selebar isi (maks. lebar layar), kolom ke samping lalu membungkus ke bawah -->
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
<span class="text-slate-400">User: <strong class="text-cyan-300"><?= htmlspecialchars($_SESSION['username'] ?? 'Administrator'); ?></strong></span>
<a href="logout.php" class="px-2.5 py-1 bg-rose-950/60 hover:bg-rose-900 text-rose-300 border border-rose-800/50 rounded transition">Logout</a>
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

<!-- [BARU 2026-11-06 TAB] TAB BAR: 3 area dashboard (gaya tombol "Print Task": putih tebal, padding 8px 20px, radius 4px) -->
<div id="tab_bar" class="flex flex-wrap gap-2 bg-[#04101c] p-3 rounded-xl border border-[#0a2d4a]" role="tablist" aria-label="Area dashboard">
<button type="button" class="tab-btn active" id="tabbtn_ringkasan" data-tab="ringkasan" role="tab" aria-selected="true" aria-controls="tab_ringkasan">Ringkasan Proyek</button>
<button type="button" class="tab-btn" id="tabbtn_keuangan" data-tab="keuangan" role="tab" aria-selected="false" aria-controls="tab_keuangan" tabindex="-1">Keuangan</button>
<button type="button" class="tab-btn" id="tabbtn_material" data-tab="material" role="tab" aria-selected="false" aria-controls="tab_material" tabindex="-1">Material &amp; RAB</button>
</div>

<!-- ===== TAB 1: RINGKASAN PROYEK (kesehatan, action center, milestone, profitabilitas) ===== -->
<section id="tab_ringkasan" class="tab-panel active space-y-6" role="tabpanel" aria-labelledby="tabbtn_ringkasan">
<!-- [BARU 2026-10-30] KESEHATAN PROYEK: progres vs pemakaian biaya + rekomendasi otomatis (data: dashboardCRD.php?action=read_project_health).
     -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]" id="health_panel">
<div class="pb-3 border-b border-[#0a2d4a] mb-3 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1">
<h2 class="text-base font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">🩺 Kesehatan Proyek (Progres vs Pemakaian Biaya)</h2>
<span class="text-[11px] text-slate-500" id="health_legend">Klik kartu untuk analisis AI</span>
</div>
<div id="health_cards" class="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-3 max-h-96 overflow-y-auto no-scrollbar">
<div class="text-center text-slate-500 text-xs py-4 col-span-full">Memuat kesehatan proyek...</div>
</div>
<div id="health_reco" class="mt-3"></div>
</div>
<!-- END KESEHATAN PROYEK -->

<!-- ROW 3: ACTION CENTER & AI MILESTONES -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<!-- ACTION CENTER -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<div class="pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-rose-400 uppercase tracking-wider font-rajdhani">⚡ Action Center (Butuh Tindakan)</h2>
</div>
<ul class="space-y-2 text-sm max-h-72 overflow-y-auto no-scrollbar" id="action_list">
<li class="text-center text-slate-500 text-xs py-4">Memuat indikator...</li>
</ul></div>

<!-- AI MILESTONE -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<div class="pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">🎯 Milestone Task Proyek (14 Hari &amp; Terlambat)</h2>
</div>
<div class="space-y-2 max-h-48 overflow-y-auto no-scrollbar" id="milestone_list">
<div class="text-center text-slate-500 text-xs py-4">Memuat milestone...</div>
</div></div>
</div>


<!-- PROFITABILITAS -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<div class="pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-emerald-400 uppercase tracking-wider font-rajdhani">📊 Profitabilitas Proyek (Biaya Riil vs Tertagih)</h2>
</div>
<div class="overflow-x-auto max-h-64 no-scrollbar">
<table class="w-full text-left text-xs">
<thead class="text-slate-400 border-b border-[#0a2d4a] sticky top-0 bg-[#04101c]">
<tr><th class="pb-2">Proyek</th><th class="pb-2 text-right">Progres</th><th class="pb-2 text-right">Kontrak (DPP)</th><th class="pb-2 text-right">Tertagih (DPP)</th><th class="pb-2 text-right">Biaya Riil</th><th class="pb-2 text-right">Biaya/Kontrak</th><th class="pb-2 text-right">Margin</th></tr>
</thead>
<tbody id="profit_tbody" class="divide-y divide-[#0a2d4a]/60 font-mono">
<tr><td colspan="7" class="py-4 text-center text-slate-500">Memuat...</td></tr>
</tbody></table></div></div>
</section>

<!-- ===== TAB 2: KEUANGAN (aging, pipeline, kas/PPN/subkon, performa bulanan, kurs KMK) ===== -->
<section id="tab_keuangan" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="tabbtn_keuangan">
<!-- ROW 1: AGING & INVOICE PIPELINE -->
<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<!-- FINANCIAL AGING -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<div class="pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-slate-200 uppercase tracking-wider font-rajdhani">Financial Aging Analysis (Umur Piutang & Hutang)</h2>
</div>
<div class="overflow-x-auto no-scrollbar">
<table class="w-full text-left text-[11px]">
<thead><tr class="text-slate-400 border-b border-[#0a2d4a] font-semibold text-[10px] uppercase tracking-wider">
<th class="pb-2 text-slate-400">Kategori</th><th class="pb-2 text-right">&lt;30 HARI</th><th class="pb-2 text-right">31-60 HARI</th><th class="pb-2 text-right">61-90 HARI</th><th class="pb-2 text-right">&gt;90 HARI</th><th class="pb-2 text-right">TOTAL</th>
</tr></thead>
<tbody id="aging_tbody" class="divide-y divide-[#0a2d4a]/60 font-mono text-[11px]">
<tr><td colspan="6" class="py-4 text-center text-slate-500 font-sans">Memuat data aging...</td></tr>
</tbody></table></div></div>

<!-- INVOICE PIPELINE -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a] flex flex-col justify-between">
<div class="pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-amber-400 uppercase tracking-wider font-rajdhani">💰 Invoice Pipeline & Proyeksi Kas Masuk</h2>
</div>
<div class="grid grid-cols-2 gap-3">
<div class="bg-[#071d30] p-3 rounded border-l-4 border-rose-500"><div class="text-[10px] text-slate-400 uppercase">Jatuh Tempo (Overdue)</div><div class="text-lg font-bold text-rose-400 font-mono" id="pip_overdue_val">Rp 0</div><div class="text-[10px] text-slate-500" id="pip_overdue_cnt">0 invoice</div></div>
<div class="bg-[#071d30] p-3 rounded border-l-4 border-cyan-500"><div class="text-[10px] text-slate-400 uppercase">Belum Bayar (Aktif)</div><div class="text-lg font-bold text-cyan-300 font-mono" id="pip_unpaid_val">Rp 0</div><div class="text-[10px] text-slate-500" id="pip_unpaid_cnt">0 invoice</div></div>
<div class="bg-[#071d30] p-3 rounded border-l-4 border-amber-500"><div class="text-[10px] text-slate-400 uppercase">Terbayar Sebagian</div><div class="text-lg font-bold text-amber-400 font-mono" id="pip_partial_val">Rp 0</div><div class="text-[10px] text-slate-500" id="pip_partial_cnt">0 invoice</div></div>
<div class="bg-[#071d30] p-3 rounded border-l-4 border-emerald-500"><div class="text-[10px] text-slate-400 uppercase">Lunas (Closed)</div><div class="text-lg font-bold text-emerald-400 font-mono" id="pip_paid_cnt">0 invoice</div><div class="text-[10px] text-slate-500">Selesai</div></div>
</div></div>
</div>


<!-- ROW 2b: KAS, PPN, SUBKON -->
<div class="grid grid-cols-1 lg:grid-cols-3 gap-4" id="extra_row">
    <div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-sky-500">
        <div class="text-base text-slate-400 uppercase font-bold tracking-wider">🏦 Saldo Kas &amp; Bank</div>
        <div class="text-xl font-bold font-mono text-sky-300 mt-2" id="extra_cash">Rp 0</div>
        <div class="text-[10px] text-sky-400/80 mt-1">Dari jurnal terposting (semua proyek)</div>
    </div>
    <div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-orange-500">
        <div class="text-base text-slate-400 uppercase font-bold tracking-wider">🧾 PPN Bulan Ini <span id="extra_ppn_period" class="text-xs"></span></div>
        <div class="text-xl font-bold font-mono mt-2" id="extra_ppn">Rp 0</div>
        <div class="text-[10px] text-orange-300/80 mt-1" id="extra_ppn_sub">Keluaran - Masukan</div>
    </div>
    <div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-lime-500" id="extra_subcon_card">
        <div class="text-base text-slate-400 uppercase font-bold tracking-wider">👷 Subkontraktor</div>
        <div class="text-xl font-bold font-mono text-lime-300 mt-2" id="extra_subcon">Rp 0</div>
        <div class="text-[10px] text-lime-300/80 mt-1" id="extra_subcon_sub">Sisa hutang prestasi</div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
<!-- PERFORMA KEUANGAN -->
<div class="bg-[#04101c] rounded-xl border border-[#0a2d4a] p-4 flex flex-col justify-between">
<div>
<div class="flex justify-between items-center pb-3 border-b border-[#0a2d4a] mb-3">
<h2 class="text-base font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Performa Keuangan (Bulanan)</h2>
<select id="fin_year_select" onchange="loadFinancialPerformance()" class="bg-[#071d30] border border-[#0a2d4a] text-cyan-200 text-[10px] rounded px-2 py-0.5">
<option value="2026" selected>2026</option><option value="2025">2025</option>
</select></div>
<div class="relative w-full h-52">
<canvas id="finChartCanvas"></canvas>
<div id="fin_empty" class="hidden absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] text-slate-400 font-sans pointer-events-none"></div>
</div></div></div>

<!-- RATE KURS PAJAK -->
<div class="bg-[#04101c] rounded-xl border border-[#0a2d4a] p-4 flex flex-col justify-between">
<div>
<div class="flex flex-col sm:flex-row justify-between items-start sm:items-center pb-3 border-b border-[#0a2d4a] mb-3 gap-2">
<div>
<h2 class="text-base font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Rate Kurs Pajak (KMK)</h2>
<div class="flex items-center gap-2 mt-1">
<span id="kmk_badge" class="text-[9px] bg-cyan-950 text-cyan-300 border border-cyan-800 px-1.5 py-0.5 rounded inline-block">Memuat...</span>
<span id="kmk_valid_date" class="text-[11px] text-yellow-400"></span>
</div></div>
<div class="flex items-center space-x-2 w-full sm:w-auto">
<div class="relative flex-1 sm:w-44">
<input type="text" id="search_tax_currency" onkeyup="filterTaxRates()" placeholder="Cari mata uang..." class="w-full bg-[#071d30] border border-[#0a2d4a] text-cyan-200 text-[11px] rounded px-2.5 py-1 pl-7 focus:outline-none focus:border-cyan-500 placeholder-slate-500">
<svg class="w-3.5 h-3.5 text-slate-400 absolute left-2 top-1.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
</div>
<button id="btn_sync_tax" onclick="syncTaxRates()" class="text-[10px] bg-cyan-700 hover:bg-cyan-600 text-white px-2 py-1 rounded transition whitespace-nowrap">Update Kurs</button>
</div></div>
<div class="max-h-56 overflow-y-auto no-scrollbar">
<table class="w-full text-left text-[13px]">
<thead class="bg-[#071d30] text-cyan-300 sticky top-0">
<tr><th class="p-1.5">Mata Uang</th><th class="p-1.5 text-right">Kurs (IDR)</th><th class="p-1.5 text-center">Ubah</th></tr>
</thead>
<tbody id="tax_rate_tbody" class="divide-y divide-[#0a2d4a] text-slate-300 font-mono text-[12px]">
<tr><td colspan="3" class="p-3 text-center text-slate-500">Memuat data kurs pajak...</td></tr>
</tbody></table></div></div></div>
</div>
</section>

<!-- ===== TAB 3: MATERIAL & RAB (KPI RAB, distribusi, top material, rincian) ===== -->
<section id="tab_material" class="tab-panel space-y-6" role="tabpanel" aria-labelledby="tabbtn_material">
<!-- KPI CARDS - [FIX] Warna Border Valid Tailwind -->
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
<!-- 1. Total Budget RAB - FUCHSIA -->
<div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-fuchsia-500 flex flex-col justify-between">
<div>
<div class="text-base text-slate-400 uppercase font-bold tracking-wider flex items-center gap-1.5">
<svg class="w-4 h-4 text-fuchsia-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
</svg>
<span>Total Budget RAB</span>
</div>
<div class="text-xl font-bold font-mono text-white mt-2" id="kpi_total_budget">Rp 0</div>
</div>
<div class="text-[10px] text-cyan-400/80 mt-1">Alokasi Anggaran Disetujui</div>
</div>

<!-- 2. Material Out - EMERALD -->
<div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-emerald-500 flex flex-col justify-between">
<div>
<div class="text-base text-slate-400 uppercase font-bold tracking-wider flex items-center gap-1.5">
<svg class="w-4 h-4 text-emerald-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
</svg>
<span>Material Out (Realisasi)</span>
</div>
<div class="text-xl font-bold font-mono text-emerald-400 mt-2" id="kpi_material_out">Rp 0</div>
</div>
<div class="text-[10px] text-emerald-400/80 mt-1">Total Pemakaian Material</div>
</div>

<!-- 3. Sisa Budget - CYAN -->
<div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-cyan-500 flex flex-col justify-between">
<div>
<div class="text-base text-slate-400 uppercase font-bold tracking-wider flex items-center gap-1.5">
<svg class="w-4 h-4 text-cyan-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
</svg>
<span>Sisa Budget</span>
</div>
<div class="text-xl font-bold font-mono text-cyan-300 mt-2" id="kpi_sisa_budget">Rp 0</div>
</div>
<div class="text-[10px] text-cyan-300/80 mt-1">Sisa Anggaran Material</div>
</div>

<!-- 4. Persentase Penyerapan - AMBER -->
<div class="bg-[#071d30]/80 p-4 rounded-xl border-l-4 border-l-amber-500 flex flex-col justify-between">
<div>
<div class="text-base text-slate-400 uppercase font-bold tracking-wider flex items-center gap-1.5">
<svg class="w-4 h-4 text-amber-400 flex-shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" d="M11 3.055A9.003 9.003 0 1020.945 13H11V3.055z"></path>
<path stroke-linecap="round" stroke-linejoin="round" d="M20.488 9H15V3.512A9.025 9.025 0 0120.488 9z"></path>
</svg>
<span>Persentase Penyerapan</span>
</div>
<div class="text-xl font-bold font-mono text-amber-400 mt-2" id="kpi_usage_pct">0.00%</div>
</div>
<div class="text-[10px] text-amber-400/80 mt-1" id="kpi_status_label">Status Penyerapan</div>
</div>
</div>


<!-- ROW 5: MATERIAL CHARTS -->
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
<div class="lg:col-span-4 bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<h3 class="text-base font-bold text-cyan-400 uppercase tracking-wider mb-2 font-rajdhani text-center">Distribusi Status Penyerapan</h3>
<div class="relative w-full h-56 flex items-center justify-center"><canvas id="doughnutChartCanvas"></canvas></div>
</div>
<div class="lg:col-span-8 bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<h3 class="text-base font-bold text-cyan-400 uppercase tracking-wider mb-2 font-rajdhani">Top Material Out vs Budget Qty</h3>
<div class="relative w-full h-56"><canvas id="barMaterialCanvas"></canvas></div>
</div>
</div>


<!-- ROW 6: TABEL MATERIAL -->
<div class="bg-[#04101c] p-4 rounded-xl border border-[#0a2d4a]">
<div class="flex justify-between items-center mb-4">
<h3 class="text-base font-bold text-cyan-400 uppercase tracking-wider font-rajdhani">Rincian Penggunaan Material Out Proyek</h3>
<span class="text-base text-slate-400" id="table_count_label">Total Material: 0 Item</span>
</div>
<div class="overflow-x-auto">
<table class="w-full text-left text-sm">
<thead class="bg-[#071d30] text-cyan-300">
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
</tr></thead>
<tbody id="material_tbody" class="divide-y divide-[#0a2d4a] text-slate-300 font-mono">
<tr><td colspan="9" class="p-4 text-center text-slate-500">Memuat data material proyek...</td></tr>
</tbody></table></div></div>

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

document.addEventListener('DOMContentLoaded', () => { initDashboard(); });

// [BARU 2026-11-06 TAB] Tab engine: loader per tab, lazy-load, hash #ringkasan | #keuangan | #material
const TAB_LOADERS = {
    ringkasan: () => { loadProjectHealth(); loadActionCenter(); loadMilestones(); loadProfitability(); },
    keuangan:  () => { if (!globalTaxRates.length) loadTaxRates(); loadFinancialAging(); loadInvoicePipeline(); loadExtra(); loadFinancialPerformance(); },
    material:  () => { loadMaterialMonitoring(); }
};
// chart di tab tersembunyi bisa berukuran 0 -> resize saat tab ditampilkan kembali
const TAB_CHARTS = { keuangan: () => [finChart], material: () => [doughnutChart, barMaterialChart] };
const tabDirty = { ringkasan: true, keuangan: true, material: true };   // true = data tab perlu (re)load
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
    if (!skipHash && location.hash !== '#' + name) history.replaceState(null, '', '#' + name);   // tanpa lompat scroll
    if (tabDirty[name]) {
        tabDirty[name] = false;
        TAB_LOADERS[name]();
    } else if (TAB_CHARTS[name]) {
        TAB_CHARTS[name]().forEach(c => { if (c) c.resize(); });
    }
}

// Filter proyek berubah: tandai semua tab usang, muat ulang hanya tab yang sedang tampil
function refreshDashboard() {
    Object.keys(tabDirty).forEach(k => { tabDirty[k] = true; });
    switchTab(activeTab, true);
}

document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => switchTab(btn.getAttribute('data-tab')));
    btn.addEventListener('keydown', (e) => {          // navigasi keyboard kiri/kanan antar tab
        if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
        const names = Object.keys(TAB_LOADERS), i = names.indexOf(activeTab);
        const next = names[(i + (e.key === 'ArrowRight' ? 1 : names.length - 1)) % names.length];
        switchTab(next);
        document.getElementById('tabbtn_' + next).focus();
    });
});
window.addEventListener('hashchange', () => { const t = tabFromHash(); if (t !== activeTab) switchTab(t, true); });

async function initDashboard() {
    await loadProjects();
    initMegaMenu();
    switchTab(tabFromHash(), true);     // [REVISI TAB] sebelumnya semua loader dipanggil sekaligus; kini hanya tab aktif
}
document.querySelectorAll('.dropdown-menu a').forEach(link => {
    link.addEventListener('click', () => {
        // Membuang fokus dari elemen agar dropdown langsung tersembunyi
        document.activeElement.blur();
    });
});
async function loadProjects() {
    try {
        const res = await fetch(`${API_ENDPOINT}?action=get_projects`);
        const json = await res.json();
        const select = document.getElementById('project_select');
        if (json.success && json.data.length > 0) {
            select.innerHTML = '<option value="0">-- Semua Proyek --</option>' + json.data.map(p => `<option value="${p.id}">${p.project_name}</option>`).join('');
        } else { select.innerHTML = '<option value="0">Tidak ada proyek</option>'; }
    } catch (err) { console.error('Error load projects:', err); }
}

function onProjectChange(val) {
    currentProjectId = parseInt(val) || 0;
    refreshDashboard();     // [REVISI TAB] muat ulang tab aktif saja; tab lain ditandai usang
}

async function loadFinancialAging() {
    const tbody = document.getElementById('aging_tbody');
    const showMsg = (msg, cls) => { tbody.innerHTML = `<tr><td colspan="6" class="p-3 text-center ${cls} font-sans">${msg}</td></tr>`; };
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_financial_aging&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) { showMsg(json.message || 'Gagal memuat data aging.', 'text-rose-400'); return; }
        const ar = json.ar, ap = json.ap;
        const row = (label, labelCls, d, c) => `
            <tr class="hover:bg-[#071d30]/50 transition">
                <td class="py-2.5 font-sans font-semibold ${labelCls}">${label}<span class="block text-[9px] font-normal text-slate-500">${d.count} invoice outstanding</span></td>
                <td class="py-2.5 text-right text-slate-200">${formatNum(d.age_30)}</td>
                <td class="py-2.5 text-right text-amber-400 font-medium">${formatNum(d.age_60)}</td>
                <td class="py-2.5 text-right text-orange-400 font-medium">${formatNum(d.age_90)}</td>
                <td class="py-2.5 text-right text-rose-400 font-semibold">${formatNum(d.age_over_90)}</td>
                <td class="py-2.5 text-right ${c} font-bold">${formatNum(d.total)}</td>
            </tr>`;
        const net = (k) => (ar[k] || 0) - (ap[k] || 0);
        tbody.innerHTML = row('Piutang (AR)', 'text-cyan-400', ar, 'text-emerald-400') + row('Hutang (AP)', 'text-purple-400', ap, 'text-amber-400') + `
            <tr class="border-t border-[#0a2d4a]"><td class="py-2.5 font-sans font-semibold text-slate-300">Netto (AR - AP)</td>
            <td class="py-2.5 text-right text-slate-300">${formatNum(net('age_30'))}</td>
            <td class="py-2.5 text-right text-slate-300">${formatNum(net('age_60'))}</td>
            <td class="py-2.5 text-right text-slate-300">${formatNum(net('age_90'))}</td>
            <td class="py-2.5 text-right text-slate-300">${formatNum(net('age_over_90'))}</td>
            <td class="py-2.5 text-right text-slate-100 font-bold">${formatNum(net('total'))}</td></tr>
            <tr><td colspan="6" class="pt-2 text-right text-[9px] text-slate-500 font-sans">Umur dihitung dari tanggal invoice, per ${json.as_of}</td></tr>`;
    } catch (err) { console.error('Error load financial aging:', err); showMsg('Gagal memuat data aging.', 'text-rose-400'); }
}

async function loadInvoicePipeline() {
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_invoice_pipeline&project_id=${currentProjectId}`);
        const json = await res.json();
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

// [REVISI] mega menu: sejajarkan dropdown dengan item menunya, tetapi jangan keluar dari lebar layar
function initMegaMenu() {
    document.querySelectorAll('.nav-item').forEach(li => {
        const dd = li.querySelector('.dropdown-menu');
        if (!dd) return;
        li.addEventListener('mouseenter', () => {
            const vw = document.documentElement.clientWidth;
            dd.style.left = '0px';
            const w = dd.offsetWidth, l = li.getBoundingClientRect().left;
            dd.style.left = Math.max(8, Math.min(l, vw - w - 8)) + 'px';
        });
    });
}

const esc = (v) => String(v == null ? '' : v).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

// [REVISI] Action Center dinamis: indikator disusun dari server (sesuai alur PO -> GRN -> Invoice -> Retur -> Termin -> Subkon -> Opname)
const LEVEL_STYLE = {
    danger: 'border-rose-500 text-rose-200',
    warn:   'border-amber-500 text-amber-200',
    info:   'border-cyan-500 text-cyan-200'
};
async function loadActionCenter() {
    const ul = document.getElementById('action_list');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_action_center&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) { ul.innerHTML = `<li class="text-center text-rose-400 text-xs py-3">${esc(json.message || 'Gagal memuat Action Center')}</li>`; return; }
        const order = { danger: 0, warn: 1, info: 2 };
        const active = json.items.filter(i => i.count > 0).sort((a, b) => (order[a.level] - order[b.level]) || (b.count - a.count));
        const calm = json.items.length - active.length;
        let html = active.map(i => `
            <li class="flex justify-between items-center bg-[#071d30] p-2 rounded border-l-4 ${LEVEL_STYLE[i.level] || LEVEL_STYLE.info}" title="${esc(i.hint)}">
                <span>${esc(i.label)}<span class="block text-[10px] opacity-60">${esc(i.hint)}</span></span>
                <span class="font-bold font-mono text-lg">${i.count}</span>
            </li>`).join('');
        if (!active.length) html = '<li class="text-center text-emerald-400 text-sm py-4">✔ Tidak ada yang perlu ditindaklanjuti.</li>';
        else if (calm > 0) html += `<li class="text-center text-slate-500 text-[11px] pt-1">✔ ${calm} indikator lain aman</li>`;
        ul.innerHTML = html;
    } catch (err) { console.error('Error load action center:', err); ul.innerHTML = '<li class="text-center text-rose-400 text-xs py-3">Error memuat Action Center</li>'; }
}

async function loadMilestones() {
    const list = document.getElementById('milestone_list');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_upcoming_milestones&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) { list.innerHTML = `<div class="text-center text-rose-400 text-xs py-4">${esc(json.message || 'Error memuat milestone')}</div>`; return; }
        if (json.data.length > 0) {
            list.innerHTML = json.data.map(t => {
                const late = t.late === true;
                const info = late ? `Terlambat ${Math.abs(t.days_left)} hari` : (t.days_left === 0 ? 'Hari ini' : `${t.days_left} hari lagi`);
                return `
                <div class="flex justify-between items-center bg-[#071d30] p-2 rounded border-l-2 ${late ? 'border-rose-500' : 'border-cyan-500'}">
                    <div><div class="font-bold text-white text-xs">${esc(t.task_name)}</div><div class="text-[10px] text-slate-400">${esc(t.project_name)}</div></div>
                    <div class="text-right"><div class="${late ? 'text-rose-400' : 'text-amber-400'} font-mono text-xs font-bold">${esc(t.deadline)}</div><div class="text-[9px] ${late ? 'text-rose-300' : 'text-slate-500'}">${esc(t.status)} - ${info}</div></div>
                </div>`;
            }).join('');
        } else { list.innerHTML = '<div class="text-center text-slate-500 text-xs py-4">Tidak ada milestone terdekat.</div>'; }
    } catch (err) { list.innerHTML = '<div class="text-center text-rose-400 text-xs py-4">Error memuat milestone</div>'; }
}

// [REVISI] profitabilitas dari biaya riil: material out proyek + pengeluaran + prestasi subkon; margin = (tertagih - biaya) / tertagih
async function loadProfitability() {
    const tbody = document.getElementById('profit_tbody');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_project_profitability&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) { tbody.innerHTML = `<tr><td colspan="7" class="py-4 text-center text-rose-400 text-xs">${esc(json.message || 'Error memuat data')}</td></tr>`; return; }
        if (json.data.length > 0) {
            tbody.innerHTML = json.data.map(p => {
                const hasMargin = p.margin_pct !== null && p.margin_pct !== undefined;
                const margin = hasMargin ? parseFloat(p.margin_pct) : 0;
                const mColor = !hasMargin ? 'text-slate-500' : (margin < 10 ? 'text-rose-400' : (margin < 20 ? 'text-amber-400' : 'text-emerald-400'));
                const cPct = parseFloat(p.cost_pct) || 0;
                const cColor = cPct > 100 ? 'text-rose-400' : (cPct > 85 ? 'text-amber-400' : 'text-slate-300');
                const detail = `Material ${fmtRp(p.cost_material)} | Pengeluaran ${fmtRp(p.cost_expense)} | Subkon ${fmtRp(p.cost_subcon)}`;
                return `<tr class="hover:bg-[#071d30]/50"><td class="py-2 font-sans text-white text-xs">${esc(p.name)}</td>
                    <td class="py-2 text-right text-cyan-300">${p.progress}%</td>
                    <td class="py-2 text-right text-slate-300">${fmtRp(p.contract_dpp)}</td>
                    <td class="py-2 text-right text-slate-300">${fmtRp(p.billed_dpp)}</td>
                    <td class="py-2 text-right text-slate-300" title="${esc(detail)}">${fmtRp(p.cost_total)}</td>
                    <td class="py-2 text-right ${cColor}">${cPct.toFixed(1)}%</td>
                    <td class="py-2 text-right font-bold ${mColor}">${hasMargin ? margin.toFixed(1) + '%' : '-'}</td></tr>`;
            }).join('');
        } else { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-slate-500 text-xs">Tidak ada data profitabilitas.</td></tr>'; }
    } catch (err) { tbody.innerHTML = '<tr><td colspan="7" class="py-4 text-center text-rose-400 text-xs">Error memuat data</td></tr>'; }
}

// [BARU] Kesehatan proyek: progres vs pemakaian biaya, status NORMAL / WASPADA / KRITIS.
// Klik kartu -> uraian AI di bawahnya berganti sesuai proyek (endpoint read_project_ai: temuan sistem + narasi Gemini, dengan cache).
const HEALTH_STYLE = {
    KRITIS:  { badge: 'bg-rose-950 text-rose-300 border-rose-800',      icon: '🔴', label: 'KRITIS',  num: 'text-rose-400',    bar: 'bg-rose-500' },
    WASPADA: { badge: 'bg-amber-950 text-amber-300 border-amber-800',   icon: '🟡', label: 'WASPADA', num: 'text-yellow-300',  bar: 'bg-yellow-400' },
    NORMAL:  { badge: 'bg-emerald-950 text-emerald-300 border-emerald-800', icon: '🟢', label: 'NORMAL', num: 'text-emerald-400', bar: 'bg-emerald-500' },
    NA:      { badge: 'bg-slate-800 text-slate-300 border-slate-600',   icon: '⚪', label: 'BUDGET KOSONG', num: 'text-slate-400', bar: 'bg-slate-500' }
};
const HEALTH_ICONS = ['🏗️', '🏢', '🏘️', '🏭', '🏠'];
const RISK_STYLE = {
    HIGH:   { badge: 'bg-rose-950 text-rose-300 border-rose-800',       text: 'text-rose-300' },
    MEDIUM: { badge: 'bg-amber-950 text-amber-300 border-amber-800',    text: 'text-amber-300' },
    LOW:    { badge: 'bg-emerald-950 text-emerald-300 border-emerald-800', text: 'text-emerald-300' }
};
const SEV_STYLE = { high: 'border-rose-600 text-rose-200', medium: 'border-amber-600 text-amber-200', low: 'border-cyan-700 text-cyan-200' };
let healthSelectedId = 0, aiRequestToken = 0;

// [BARU] Setelah analisis AI (baru / ulang) selesai: status kartu langsung ikut naik bila risiko AI lebih tinggi (server menyimpan hasilnya ke ai_variance_logs,
// sehingga muat ulang dashboard menampilkan status yang sama).
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
    const token = ++aiRequestToken;                 // abaikan respons usang bila user klik kartu lain
    const card = document.querySelector(`#health_cards [data-pid="${id}"]`);
    const pname = card ? card.getAttribute('data-name') : '';
    box.innerHTML = `<div class="bg-purple-950/30 border border-purple-700/40 rounded-lg p-4 text-sm text-purple-200 animate-pulse">🧠 AI sedang menganalisa <b>${esc(pname)}</b> (biaya, material, subkon, jadwal, penagihan)...</div>`;
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_project_ai&project_id=${id}&refresh=${refresh ? 1 : 0}`);
        const json = await res.json();
        if (token !== aiRequestToken) return;
        if (!json.success) { box.innerHTML = `<div class="bg-rose-950/30 border border-rose-800 rounded-lg p-3 text-sm text-rose-300">${esc(json.message || 'Gagal memuat rekomendasi AI')}</div>`; return; }
        const d = json.data, rs = RISK_STYLE[d.risk] || RISK_STYLE.LOW;
        const li = (arr, cls) => arr.map(t => `<li class="${cls || ''}">${esc(t)}</li>`).join('');
        const findings = (d.findings || []).map(f => `
            <div class="border-l-4 ${SEV_STYLE[f.sev] || SEV_STYLE.low} bg-[#071d30] rounded px-2 py-1 mb-1">
                <div class="text-xs font-bold">${esc(f.title)}</div><div class="text-[11px] opacity-80">${esc(f.evidence)}</div></div>`).join('');
        box.innerHTML = `
        <div class="bg-purple-950/30 border border-purple-700/40 rounded-lg p-4 max-h-96 overflow-y-auto no-scrollbar">
            <div class="flex flex-wrap items-center gap-2 mb-2">
                <span class="text-purple-300 font-bold text-base">🧠 AI Recommendation</span>
                <span class="px-2 py-0.5 text-[11px] font-bold border rounded-md ${rs.badge}">RISK: ${esc(d.risk)}</span>
                <span class="px-2 py-0.5 text-[10px] border rounded-md border-slate-600 text-slate-300">${d.ai_used ? 'Gemini' : 'Analisis sistem'}${d.cached ? ' • cache' : ''}</span>
                <span class="text-[11px] text-slate-500 ml-auto">${esc(d.generated_at || '')}${d.log_id ? ' • log #' + d.log_id : ''}</span>
                <button type="button" class="aiRefresh px-3 py-1 text-[11px] font-bold rounded bg-[#066b78] text-white cursor-pointer border-0">Analisis Ulang</button>
            </div>
            <div class="${rs.text} italic text-base mb-2"><b>${esc(d.project_name)}:</b> ${esc(d.headline)}</div>
            <div class="text-sm text-slate-200 mb-2 leading-relaxed">${esc(d.summary)}</div>
            ${d.actions.length ? `<div class="text-xs font-bold text-cyan-300 mt-2 mb-1">Tindakan yang disarankan</div><ul class="list-disc pl-5 text-sm text-slate-200 space-y-1">${li(d.actions)}</ul>` : ''}
            ${d.questions && d.questions.length ? `<div class="text-xs font-bold text-amber-300 mt-3 mb-1">Perlu dikonfirmasi</div><ul class="list-disc pl-5 text-sm text-amber-100 space-y-1">${li(d.questions)}</ul>` : ''}
            ${findings ? `<div class="text-xs font-bold text-slate-400 mt-3 mb-1">Temuan sistem (${d.findings.length})</div>${findings}` : ''}
            ${d.ai_note ? `<div class="text-[11px] text-amber-400 mt-2">${esc(d.ai_note)}</div>` : ''}
        </div>`;
        box.querySelector('.aiRefresh').onclick = () => loadProjectAI(id, true);
        applyAiRiskToCard(id, d.risk);
    } catch (err) { if (token === aiRequestToken) box.innerHTML = '<div class="bg-rose-950/30 border border-rose-800 rounded-lg p-3 text-sm text-rose-300">Error memuat rekomendasi AI</div>'; }
}

async function loadProjectHealth() {
    const box = document.getElementById('health_cards'), reco = document.getElementById('health_reco');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_project_health&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) { box.innerHTML = `<div class="text-center text-rose-400 text-xs py-4 col-span-full">${esc(json.message || 'Gagal memuat kesehatan proyek')}</div>`; reco.innerHTML = ''; return; }
        if (!json.data.length) { box.innerHTML = '<div class="text-center text-slate-500 text-xs py-4 col-span-full">Tidak ada proyek berstatus Berjalan.</div>'; reco.innerHTML = ''; return; }
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
            <div class="bg-[#071d30] border border-[#0a2d4a] rounded-xl p-3 cursor-pointer hover:border-cyan-500 transition" data-pid="${p.id}" data-name="${esc(p.name)}" data-level="${p.level}" title="${esc(detail)}">
                <div class="flex justify-between items-start gap-2">
                    <div class="font-bold text-white text-base leading-tight">${HEALTH_ICONS[i % HEALTH_ICONS.length]} ${esc(p.name)}</div>
                    <span class="health-badge px-2 py-0.5 text-[11px] font-bold border rounded-md whitespace-nowrap ${st.badge}">${st.icon} ${st.label}${p.ai_risk && p.level !== 'NORMAL' ? ' • AI' : ''}</span>
                </div>
                <div class="flex flex-wrap gap-x-4 text-sm mt-2 text-slate-400">
                    <span>Progres: <b class="text-white">${p.progress}%</b></span>
                    <span>Pemakaian: <b class="health-num ${st.num}">${hasUsage ? Number(p.usage_pct).toFixed(0) + '%' : '-'}</b></span>
                    <span>Gap: <b class="health-num ${st.num}">${gapTxt}</b></span>
                </div>
                <div class="mt-2 space-y-1">
                    <div class="h-1.5 bg-slate-800 rounded"><div class="h-1.5 rounded bg-cyan-500" style="width:${w1}%"></div></div>
                    <div class="h-1.5 bg-slate-800 rounded"><div class="h-1.5 rounded ${st.bar}" style="width:${w2}%"></div></div>
                </div>
            </div>`;
        }).join('');
        box.querySelectorAll('[data-pid]').forEach(el => { el.onclick = () => selectHealthProject(parseInt(el.getAttribute('data-pid'), 10), false); });
        // pilih otomatis: proyek yang sedang dipilih / sesuai filter / yang paling kritis (kartu pertama)
        const ids = json.data.map(p => p.id);
        const pick = ids.includes(healthSelectedId) ? healthSelectedId : (ids.includes(parseInt(currentProjectId, 10)) ? parseInt(currentProjectId, 10) : ids[0]);
        selectHealthProject(pick, false);
    } catch (err) { console.error('Error load project health:', err); box.innerHTML = '<div class="text-center text-rose-400 text-xs py-4 col-span-full">Error memuat kesehatan proyek</div>'; }
}

// [REVISI] kartu tambahan: saldo kas & bank, PPN bulan berjalan, subkon
async function loadExtra() {
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_extra&project_id=${currentProjectId}`);
        const json = await res.json();
        if (!json.success) return;
        const d = json.data;
        document.getElementById('extra_cash').innerText = d.cash === null ? '-' : fmtRp(d.cash);
        const ppnEl = document.getElementById('extra_ppn');
        if (d.ppn) {
            document.getElementById('extra_ppn_period').innerText = `(${d.ppn.periode})`;
            ppnEl.innerText = fmtRp(Math.abs(d.ppn.net));
            ppnEl.className = 'text-xl font-bold font-mono mt-2 ' + (d.ppn.net > 0 ? 'text-rose-400' : 'text-emerald-400');
            document.getElementById('extra_ppn_sub').innerText = `${d.ppn.net > 0 ? 'KURANG BAYAR' : (d.ppn.net < 0 ? 'LEBIH BAYAR' : 'NIHIL')} | Keluaran ${fmtRp(d.ppn.keluaran)} - Masukan ${fmtRp(d.ppn.masukan)}`;
        }
        const card = document.getElementById('extra_subcon_card');
        if (d.subcon) {
            card.style.display = '';
            document.getElementById('extra_subcon').innerText = fmtRp(d.subcon.sisa_hutang);
            document.getElementById('extra_subcon_sub').innerText = `${d.subcon.spk_aktif} SPK aktif | Nilai ${fmtRp(d.subcon.nilai_spk)} | Tertagih ${fmtRp(d.subcon.tertagih)} | sisa hutang prestasi`;
        } else { card.style.display = 'none'; }     // modul subkon belum dimigrasi
    } catch (err) { console.error('Error load extra:', err); }
}

async function loadTaxRates() {
    const badge = document.getElementById('kmk_badge');
    const validDateEl = document.getElementById('kmk_valid_date');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_tax_rates`);
        const json = await res.json();
        if (json.success) {
            badge.innerText = json.kmk_number !== '-' ? `KMK: ${json.kmk_number}` : 'Kosong';
            if (json.start_date && json.end_date) validDateEl.innerText = `(${json.start_date} s/d ${json.end_date})`;
            else if (json.valid_date) validDateEl.innerText = `(${json.valid_date})`;
            else validDateEl.innerText = '';
            globalTaxRates = json.rates || [];
            filterTaxRates();
        }
    } catch (err) { document.getElementById('tax_rate_tbody').innerHTML = '<tr><td colspan="3" class="p-3 text-center text-rose-400">Gagal memuat kurs pajak</td></tr>'; }
}

function filterTaxRates() {
    const query = (document.getElementById('search_tax_currency').value || '').trim().toLowerCase();
    const filtered = globalTaxRates.filter(r => (r.currency_code && r.currency_code.toLowerCase().includes(query)) || (r.currency_name && r.currency_name.toLowerCase().includes(query)));
    renderTaxRates(filtered);
}

function renderTaxRates(rates) {
    const tbody = document.getElementById('tax_rate_tbody');
    if (!rates || rates.length === 0) { tbody.innerHTML = '<tr><td colspan="3" class="p-3 text-center text-slate-500">Mata uang tidak ditemukan.</td></tr>'; return; }
    tbody.innerHTML = rates.map(r => {
        const dirColor = r.change_direction === 'UP' ? 'text-emerald-400' : (r.change_direction === 'DOWN' ? 'text-rose-400' : 'text-slate-400');
        const prefix = r.change_direction === 'UP' ? '+' : (r.change_direction === 'DOWN' ? '-' : '');
        return `<tr class="hover:bg-[#071d30]/50"><td class="p-1.5 font-bold text-white">${r.currency_code}</td>
            <td class="p-1.5 text-right">${formatIDR(r.rate)}</td>
            <td class="p-1.5 text-center ${dirColor}">${prefix}${formatNum(r.change_amount)}</td></tr>`;
    }).join('');
}

async function syncTaxRates() {
    const btn = document.getElementById('btn_sync_tax');
    btn.disabled = true; btn.innerText = 'Syncing...';
    try {
        const res = await fetch(`${API_ENDPOINT}?action=sync_tax_rates`);
        const json = await res.json();
        alert(json.message || (json.success ? 'Berhasil update kurs pajak.' : 'Gagal update kurs pajak.'));
        await loadTaxRates();
    } catch (err) { alert('Gagal menghubungi server sync kurs'); }
    finally { btn.disabled = false; btn.innerText = 'Update Kurs'; }
}

async function loadFinancialPerformance() {
    const year = document.getElementById('fin_year_select').value;
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_financial_performance&year=${year}&project_id=${currentProjectId}`);
        const json = await res.json();
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
    el.className = 'absolute inset-0 flex items-center justify-center text-center px-6 text-[11px] font-sans pointer-events-none ' + (isError ? 'text-rose-400' : 'text-slate-400');
    el.textContent = msg;
}

function renderFinChart(labels, incData, expData) {
    const ctx = document.getElementById('finChartCanvas').getContext('2d');
    if (finChart) finChart.destroy();
    const compact = (v) => Number(v).toLocaleString('id-ID', { notation: 'compact', maximumFractionDigits: 1 });
    finChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: labels, datasets: [{ label: 'Pendapatan', data: incData, backgroundColor: '#10b981' }, { label: 'Pengeluaran', data: expData, backgroundColor: '#f43f5e' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8', font: { size: 9 } } }, tooltip: { callbacks: { label: (c) => ` ${c.dataset.label}: ${formatIDR(c.parsed.y)}` } } }, scales: { x: { ticks: { color: '#94a3b8', font: { size: 8 } }, grid: { color: '#0a2d4a' } }, y: { beginAtZero: true, ticks: { color: '#94a3b8', font: { size: 8 }, callback: compact }, grid: { color: '#0a2d4a' } } } }
    });
}

async function loadMaterialMonitoring() {
    const tbody = document.getElementById('material_tbody');
    try {
        const res = await fetch(`${API_ENDPOINT}?action=read_material_monitoring&project_id=${currentProjectId}`);
        const json = await res.json();
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
            if (aggregatedItems.length === 0) { tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-slate-500">Tidak ada data penggunaan material untuk proyek ini.</td></tr>'; return; }
            tbody.innerHTML = aggregatedItems.map(item => {
                let statusBg = 'bg-emerald-950 text-emerald-300 border-emerald-800';
                if (item.status === 'WARNING') statusBg = 'bg-amber-950 text-amber-300 border-amber-800';
                if (item.status === 'OVERBUDGET') statusBg = 'bg-rose-950 text-rose-300 border-rose-800';
                return `<tr class="hover:bg-[#071d30]/50 transition">
                    <td class="p-3 font-sans"><div class="font-bold text-white">${item.item_name}</div><div class="text-sm text-cyan-400/70">${item.item_code || '-'}</div></td>
                    <td class="p-3 text-center">${item.unit || '-'}</td>
                    <td class="p-3 text-right">${formatNum(item.budget_qty)}</td>
                    <td class="p-3 text-right font-bold text-white">${formatIDR(item.budget_value)}</td>
                    <td class="p-3 text-right text-emerald-400">${formatNum(item.out_qty)}</td>
                    <td class="p-3 text-right text-emerald-300">${formatIDR(item.out_value)}</td>
                    <td class="p-3 text-right ${item.remaining_qty < 0 ? 'text-rose-400' : ''}">${formatNum(item.remaining_qty)}</td>
                    <td class="p-3 text-center text-amber-400 font-bold">${item.usage_pct}%</td>
                    <td class="p-3 text-center"><span class="px-2 py-0.5 text-[10px] border rounded-full ${statusBg}">${item.status}</span></td>
                </tr>`;
            }).join('');
        } else {      // [REVISI] sebelumnya tidak ada penanganan -> KPI tetap Rp 0 tanpa pesan
            console.error('Monitoring material gagal:', json.message);
            ['kpi_total_budget', 'kpi_material_out', 'kpi_sisa_budget'].forEach(id => document.getElementById(id).innerText = '-');
            document.getElementById('kpi_usage_pct').innerText = '-';
            tbody.innerHTML = `<tr><td colspan="9" class="p-4 text-center text-rose-400">${esc(json.message || 'Gagal memuat data monitoring material')}</td></tr>`;
        }
    } catch (err) { console.error('Error load material monitoring:', err); tbody.innerHTML = '<tr><td colspan="9" class="p-4 text-center text-rose-400">Gagal memuat data monitoring material</td></tr>'; }
}

function renderDoughnutChart(counts) {
    const ctx = document.getElementById('doughnutChartCanvas').getContext('2d');
    if (doughnutChart) doughnutChart.destroy();
    doughnutChart = new Chart(ctx, {
        type: 'doughnut',
        data: { labels: ['On Track', 'Warning', 'Overbudget'], datasets: [{ data: [counts.ON_TRACK || 0, counts.WARNING || 0, counts.OVERBUDGET || 0], backgroundColor: ['#10b981', '#f59e0b', '#f43f5e'], borderWidth: 0 }] },
        options: { responsive: true, maintainAspectRatio: false, cutout: '70%', plugins: { legend: { position: 'bottom', labels: { color: '#94a3b8', font: { size: 10 } } } } }
    });
}

function renderBarMaterialChart(topItems) {
    const ctx = document.getElementById('barMaterialCanvas').getContext('2d');
    if (barMaterialChart) barMaterialChart.destroy();
    barMaterialChart = new Chart(ctx, {
        type: 'bar',
        data: { labels: topItems.map(i => i.item_name.substring(0, 15) + '...'), datasets: [{ label: 'Budget Qty', data: topItems.map(i => i.budget_qty), backgroundColor: '#0284c7' }, { label: 'Out Qty', data: topItems.map(i => i.out_qty), backgroundColor: '#10b981' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { labels: { color: '#94a3b8', font: { size: 10 } } } }, scales: { x: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } }, y: { ticks: { color: '#94a3b8', font: { size: 9 } }, grid: { color: '#0a2d4a' } } } }
    });
}
</script>
</body>
</html>