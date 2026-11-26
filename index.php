<?php
/*
Document : index.php
Description : Landing Page RAB-PROJECT - Construction ERP & AI Monitoring System
Theme : Dark Theme Modern - LENGKAP
*/
if (!isset($_SESSION)) { session_start(); }
date_default_timezone_set('Asia/Jakarta');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<?php $favVer = date('YmdHis'); ?>
<link rel="shortcut icon" href="favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="icon" href="favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="apple-touch-icon" href="favicon.ico?v=<?=$favVer?>">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="RAB-PROJECT - Sistem ERP Konstruksi Terintegrasi dengan AI untuk monitoring proyek, material, dan keuangan real-time">
<title>RAB-PROJECT | Construction ERP & AI Monitoring System</title>
<script src="https://cdn.tailwindcss.com"></script>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Orbitron:wght@500;700;900&family=Rajdhani:wght@500;600;700&display=swap" rel="stylesheet">
<script>
tailwind.config = {
theme: {
extend: {
fontFamily: {
'display': ['Orbitron', 'sans-serif'],
'heading': ['Rajdhani', 'sans-serif'],
'body': ['Inter', 'sans-serif'],
},
colors: {
'brand': {
'dark': '#071d30',
'deep': '#0a2d4a',
'darker': '#051525',
'cyan': '#22d3ee',
'orange': '#f39c12',
'green': '#10b981',
'purple': '#a855f7',
}
},
animation: {
'fade-up': 'fadeUp 0.8s ease-out forwards',
'float': 'float 6s ease-in-out infinite',
},
keyframes: {
fadeUp: {
'0%': { opacity: '0', transform: 'translateY(30px)' },
'100%': { opacity: '1', transform: 'translateY(0)' },
},
float: {
'0%, 100%': { transform: 'translateY(0px)' },
'50%': { transform: 'translateY(-15px)' },
}
}
}
}
}
</script>
<style>
html { scroll-behavior: smooth; }
body { font-family: 'Inter', sans-serif; }
.gradient-text {
background: linear-gradient(135deg, #22d3ee 0%, #f39c12 100%);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
}
.gradient-text-2 {
background: linear-gradient(135deg, #a855f7 0%, #ec4899 100%);
-webkit-background-clip: text;
-webkit-text-fill-color: transparent;
background-clip: text;
}
.glass {
background: rgba(10, 45, 74, 0.4);
backdrop-filter: blur(12px);
-webkit-backdrop-filter: blur(12px);
border: 1px solid rgba(34, 211, 238, 0.15);
}
.glow-cyan { box-shadow: 0 0 40px rgba(34, 211, 238, 0.3); }
.glow-orange { box-shadow: 0 0 40px rgba(243, 156, 18, 0.3); }
.hero-bg {
background:
radial-gradient(ellipse at top left, rgba(34, 211, 238, 0.15) 0%, transparent 50%),
radial-gradient(ellipse at bottom right, rgba(243, 156, 18, 0.1) 0%, transparent 50%),
linear-gradient(180deg, #071d30 0%, #0a2d4a 100%);
}
.grid-pattern {
background-image:
linear-gradient(rgba(34, 211, 238, 0.05) 1px, transparent 1px),
linear-gradient(90deg, rgba(34, 211, 238, 0.05) 1px, transparent 1px);
background-size: 50px 50px;
}
.feature-card:hover {
transform: translateY(-8px);
border-color: rgba(34, 211, 238, 0.5);
box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
}
.feature-card { transition: all 0.3s ease; }
.stat-number { font-family: 'Orbitron', sans-serif; }
.scroll-indicator { animation: bounce 2s infinite; }
@keyframes bounce {
0%, 20%, 50%, 80%, 100% { transform: translateY(0); }
40% { transform: translateY(-10px); }
60% { transform: translateY(-5px); }
}
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #051525; }
::-webkit-scrollbar-thumb { background: #0891b2; border-radius: 3px; }

/* [BARU] Table styling untuk dashboard preview */
.data-table {
width: 100%;
border-collapse: collapse;
font-size: 0.85rem;
}
.data-table th, .data-table td {
padding: 0.5rem 0.75rem;
text-align: left;
border-bottom: 1px solid rgba(34, 211, 238, 0.1);
}
.data-table th {
color: #22d3ee;
font-weight: 600;
font-size: 0.75rem;
text-transform: uppercase;
letter-spacing: 0.05em;
}
.data-table tr:hover {
background: rgba(34, 211, 238, 0.05);
}
</style>
</head>
<body class="bg-brand-darker text-slate-100 overflow-x-hidden">

<!-- NAVBAR -->
<nav class="fixed top-0 left-0 right-0 z-50 glass">
<div class="max-w-7xl mx-auto px-6 py-4 flex items-center justify-between">
<div class="flex items-center gap-3">
<div class="w-10 h-10 rounded-lg bg-gradient-to-br from-brand-cyan to-brand-orange flex items-center justify-center font-display font-bold text-brand-dark">R</div>
<div>
<div class="font-display font-bold text-lg tracking-wider">RAB-PROJECT</div>
<div class="text-xs text-slate-400 -mt-1">Smart ERP for Contractors: Zero-Manual Accounting, AI-Driven Insights</div>
</div>
</div>
<div class="hidden md:flex items-center gap-8 text-sm font-medium">
<a href="#dashboard" class="hover:text-brand-cyan transition">Dashboard</a>
<a href="#features" class="hover:text-brand-cyan transition">Fitur</a>
<a href="#ai" class="hover:text-brand-cyan transition">AI Features</a>
<a href="#modules" class="hover:text-brand-cyan transition">Modul</a>
<a href="#comparison" class="hover:text-brand-cyan transition">Perbandingan</a>
<a href="#contact" class="hover:text-brand-cyan transition">Kontak</a>
</div>
<a href="login.php" class="px-5 py-2 bg-brand-orange text-brand-dark font-bold rounded-lg hover:bg-orange-500 transition shadow-lg">
Login
</a>
</div>
</nav>

<!-- HERO SECTION -->
<section class="hero-bg grid-pattern min-h-screen flex items-center pt-24 pb-16 relative">
<div class="max-w-7xl mx-auto px-6 grid lg:grid-cols-2 gap-12 items-center">
<div class="animate-fade-up">
<div class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-brand-cyan/10 border border-brand-cyan/30 text-brand-cyan text-xs font-semibold mb-6">
<span class="w-2 h-2 bg-brand-cyan rounded-full animate-pulse"></span>
ERP Konstruksi Modern: Auto-Posting Jurnal Akuntansi & Analisa Proyek Berbasis AI</div>
<h1 class="font-display text-4xl md:text-6xl font-bold leading-tight mb-6">
Stop <span class="gradient-text">Kebocoran</span> Material.<br>
Mulai <span class="gradient-text-2">AI Monitoring</span> Real-time.
</h1>
<p class="text-lg md:text-xl text-slate-300 mb-8 leading-relaxed">
Sistem ERP <strong class="text-white">khusus kontraktor</strong> dengan kontrol material berbasis progres,
<strong class="text-brand-purple">AI Task Breakdown</strong>, <strong class="text-brand-orange">AI Variance Analysis</strong>,
auto-posting jurnal, dan financial reporting terintegrasi.
</p>
<div class="flex flex-wrap gap-">
<!-- <a href="dev/login.php" class="px-8 py-4 bg-brand-orange text-brand-dark font-bold rounded-lg hover:bg-orange-500 transition shadow-lg glow-orange flex items-center gap-2">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
Login ke Sistem
</a> -->
<a href="#dashboard" class="px-8 py-4 glass text-white font-semibold rounded-lg hover:border-brand-cyan transition">
Lihat Dashboard
</a>
</div>
<div class="flex items-center gap-8 mt-10 pt-8 border-t border-slate-700/50">
<div>
<div class="stat-number text-3xl font-bold text-brand-cyan">100%</div>
<div class="text-xs text-slate-400">Auto-Posting Jurnal</div>
</div>
<div>
<div class="stat-number text-3xl font-bold text-brand-orange">8+</div>
<div class="text-xs text-slate-400">Kontrol Bisnis Ketat</div>
</div>
<div>
<div class="stat-number text-3xl font-bold text-brand-purple">AI</div>
<div class="text-xs text-slate-400">Task & Variance</div>
</div>
</div>
</div>
<!-- Mockup Dashboard -->
<div class="relative animate-float">
<div class="absolute inset-0 bg-gradient-to-br from-brand-cyan/20 to-brand-purple/20 blur-3xl"></div>
<div class="relative glass rounded-2xl p-6 glow-cyan">
<div class="flex items-center gap-2 mb-4">
<div class="w-3 h-3 rounded-full bg-red-500"></div>
<div class="w-3 h-3 rounded-full bg-yellow-500"></div>
<div class="w-3 h-3 rounded-full bg-green-500"></div>
<div class="ml-4 text-xs text-slate-400 font-mono">RAB-PROJECT Dashboard</div>
</div>
<div class="grid grid-cols-2 gap-3">
<div class="bg-brand-dark/60 rounded-lg p-3 border border-slate-700">
<div class="text-xs text-slate-400 mb-1">Total Budget RAB</div>
<div class="text-lg font-bold text-brand-cyan">Rp 1.009.400.000</div>
</div>
<div class="bg-brand-dark/60 rounded-lg p-3 border border-slate-700">
<div class="text-xs text-slate-400 mb-1">Material Out</div>
<div class="text-lg font-bold text-brand-green">Rp 242.850.000</div>
</div>
<div class="bg-brand-dark/60 rounded-lg p-3 border border-slate-700">
<div class="text-xs text-slate-400 mb-1">Sisa Budget</div>
<div class="text-lg font-bold text-brand-orange">Rp 766.550.000</div>
</div>
<div class="bg-brand-dark/60 rounded-lg p-3 border border-slate-700">
<div class="text-xs text-slate-400 mb-1">Penyerapan</div>
<div class="text-lg font-bold text-brand-purple">24.06%</div>
</div>
</div>
<div class="mt-4 bg-brand-dark/60 rounded-lg p-3 border border-brand-cyan/30">
<div class="text-xs text-brand-cyan font-semibold mb-2">🎯 AI Milestone Terdekat</div>
<div class="space-y-2">
<div class="text-xs text-slate-300">📅 Pembersihan Akhir & Serah Terima - 2026-10-31</div>
<div class="text-xs text-slate-300">⛏️ Pekerjaan Tanah dan Pondasi - 2026-11-01</div>
</div>
</div>
</div>
</div>
</div>
<div class="absolute bottom-8 left-1/2 -translate-x-1/2 scroll-indicator">
<svg class="w-6 h-6 text-brand-cyan" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 14l-7 7m0 0l-7-7m7 7V3"/>
</svg>
</div>
</section>

<!-- [BARU] DASHBOARD MONITORING SECTION -->
<section id="dashboard" class="py-20 bg-brand-darker">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-cyan text-sm font-bold tracking-widest mb-3">DASHBOARD MONITORING</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
Satu Layar untuk <span class="gradient-text">Semua Keputusan</span>
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">Dashboard eksekutif real-time yang menggabungkan financial aging, profitabilitas, monitoring material, dan AI milestone dalam satu tampilan.</p>
</div>

<div class="grid lg:grid-cols-2 gap-6 mb-6">
<!-- Financial Aging -->
<div class="glass rounded-xl p-6 border border-slate-700">
<h3 class="font-heading text-xl font-bold text-white mb-4">FINANCIAL AGING ANALYSIS</h3>
<table class="data-table">
<thead>
<tr>
<th>Kategori</th>
<th class="text-right">&lt;30 Hari</th>
<th class="text-right">31-60</th>
<th class="text-right">61-90</th>
<th class="text-right">&gt;90</th>
<th class="text-right">Total</th>
</tr>
</thead>
<tbody>
<tr>
<td class="text-brand-cyan font-semibold">Piutang (AR)<div class="text-xs text-slate-500">3 invoice outstanding</div></td>
<td class="text-right text-slate-300">265.000.000</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-rose-400">0</td>
<td class="text-right text-brand-cyan font-bold">265.000.000</td>
</tr>
<tr>
<td class="text-brand-purple font-semibold">Hutang (AP)<div class="text-xs text-slate-500">1 invoice outstanding</div></td>
<td class="text-right text-slate-300">274.336.500</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-rose-400">0</td>
<td class="text-right text-brand-orange font-bold">274.336.500</td>
</tr>
<tr class="border-t border-brand-cyan/20">
<td class="text-slate-200 font-semibold">Netto (AR-AP)</td>
<td class="text-right text-slate-300">-9.336.500</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-slate-500">0</td>
<td class="text-right text-slate-100 font-bold">-9.336.500</td>
</tr>
</tbody>
</table>
<div class="text-xs text-slate-500 mt-3 text-right">Umur dihitung dari tanggal invoice, per <?= date('Y-m-d') ?></div>
</div>

<!-- Profitabilitas -->
<div class="glass rounded-xl p-6 border border-slate-700">
<h3 class="font-heading text-xl font-bold text-white mb-4">PROFITABILITAS PROYEK (MARGIN)</h3>
<table class="data-table">
<thead>
<tr>
<th>Proyek</th>
<th class="text-right">Kontrak</th>
<th class="text-right">Spent</th>
<th class="text-right">Margin %</th>
</tr>
</thead>
<tbody>
<tr>
<td class="text-slate-200">Renovasi Gedung Kantor Pusat</td>
<td class="text-right text-slate-300">Rp 1.250.000.000</td>
<td class="text-right text-slate-400">Rp 1.150.000.000</td>
<td class="text-right text-rose-400 font-bold">8.0%</td>
</tr>
<tr>
<td class="text-slate-200">Pembangunan Ruko Grand Blok C</td>
<td class="text-right text-slate-300">Rp 900.000.000</td>
<td class="text-right text-slate-400">Rp 320.000.000</td>
<td class="text-right text-brand-orange font-bold">64.4%</td>
</tr>
<tr>
<td class="text-slate-200">Pembangunan Gudang Logistik Sidoarjo</td>
<td class="text-right text-slate-300">Rp 900.000.000</td>
<td class="text-right text-slate-400">Rp 0</td>
<td class="text-right text-brand-green font-bold">100.0%</td>
</tr>
<tr>
<td class="text-slate-200">Gedung Wisma A - Surabaya</td>
<td class="text-right text-slate-300">Rp 150.000.000</td>
<td class="text-right text-slate-400">Rp 0</td>
<td class="text-right text-brand-green font-bold">100.0%</td>
</tr>
</tbody>
</table>
</div>
</div>

<!-- Material Monitoring -->
<div class="glass rounded-xl p-6 border border-slate-700">
<h3 class="font-heading text-xl font-bold text-white mb-4">RINCIAN PENGGUNAAN MATERIAL OUT PROYEK</h3>
<div class="overflow-x-auto">
<table class="data-table">
<thead>
<tr>
<th>Nama / Kode Material</th>
<th>Satuan</th>
<th class="text-right">Budget Qty</th>
<th class="text-right">Total Budget</th>
<th class="text-right">Material Out</th>
<th class="text-right">Total Realisasi</th>
<th class="text-right">Sisa Qty</th>
<th class="text-right">% Terpakai</th>
<th class="text-center">Status</th>
</tr>
</thead>
<tbody>
<tr>
<td class="text-slate-200">Cat Tembok Exterior 20Kg<div class="text-xs text-brand-cyan">MAT-CAT-05</div></td>
<td class="text-slate-400">Pail</td>
<td class="text-right text-slate-300">170,00</td>
<td class="text-right text-slate-200">Rp 144.500.000</td>
<td class="text-right text-brand-green">120,00</td>
<td class="text-right text-brand-green">Rp 102.000.000</td>
<td class="text-right text-slate-300">50,00</td>
<td class="text-right text-brand-orange font-bold">70.59%</td>
<td class="text-center"><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green border border-brand-green/30">ON_TRACK</span></td>
</tr>
<tr>
<td class="text-slate-200">Keramik 60x60 Polished<div class="text-xs text-brand-cyan">MAT-KRM-07</div></td>
<td class="text-slate-400">Dus</td>
<td class="text-right text-slate-300">450,00</td>
<td class="text-right text-slate-200">Rp 83.250.000</td>
<td class="text-right text-brand-green">440,00</td>
<td class="text-right text-brand-green">Rp 81.400.000</td>
<td class="text-right text-slate-300">10,00</td>
<td class="text-right text-brand-orange font-bold">97.78%</td>
<td class="text-center"><span class="px-2 py-1 text-xs rounded-full bg-brand-orange/20 text-brand-orange border border-brand-orange/30">WARNING</span></td>
</tr>
<tr>
<td class="text-slate-200">Semen Semen Gresik 50kg<div class="text-xs text-brand-cyan">MAT-SMC-01</div></td>
<td class="text-slate-400">Sak</td>
<td class="text-right text-slate-300">3.800,00</td>
<td class="text-right text-slate-200">Rp 258.400.000</td>
<td class="text-right text-brand-green">775,00</td>
<td class="text-right text-brand-green">Rp 52.700.000</td>
<td class="text-right text-slate-300">3.025,00</td>
<td class="text-right text-brand-orange font-bold">20.39%</td>
<td class="text-center"><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green border border-brand-green/30">ON_TRACK</span></td>
</tr>
</tbody>
</table>
</div>
</div>
</div>
</section>

<!-- FEATURES SECTION -->
<section id="features" class="py-20 bg-brand-dark">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-cyan text-sm font-bold tracking-widest mb-3">FITUR UNGGULAN</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
8 Senjata <span class="gradient-text">Pertahanan</span> Proyek Anda
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">Bukan sekadar pencatat data — RAB-PROJECT adalah sistem pertahanan finansial & operasional.</p>
</div>
<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6">
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">🛡️</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Progress-Gated Issue</h3>
<p class="text-slate-400 text-sm">Gudang diblokir otomatis mengeluarkan material jika melebihi batas progres fisik & pembayaran klien.</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">🔒</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Plafon Ketat</h3>
<p class="text-slate-400 text-sm">Total RAB dilarang keras melebihi Nilai Kontrak. Penambahan wajib isi alasan addendum.</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">🧠</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">AI Variance Analysis</h3>
<p class="text-slate-400 text-sm">Deteksi otomatis deviasi % Pemakaian vs % Progres dengan flag NORMAL / WASPADA / KRITIS.</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">💱</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Multi-Currency</h3>
<p class="text-slate-400 text-sm">RAB dalam USD, PO dalam IDR? Sistem konversi otomatis dengan kurs anggaran terkunci.</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">📉</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Purchase Planning</h3>
<p class="text-slate-400 text-sm">Hitung otomatis sisa perlu beli = RAB − PO. Centang item, klik "Buat PO".</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">📒</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Auto-Posting Jurnal</h3>
<p class="text-slate-400 text-sm">Setiap transaksi otomatis menjurnal dengan atomic transaction. Stok & keuangan 100% sinkron.</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">🔄</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Material Left Over</h3>
<p class="text-slate-400 text-sm">Retur sisa material otomatis mengurangi WIP & menambah stok gudang (FIFO).</p>
</div>
<div class="feature-card glass rounded-xl p-6 border border-slate-700">
<div class="text-3xl mb-3">🧾</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Auto-Invoice Termin</h3>
<p class="text-slate-400 text-sm">Tagihan DP/Termin di-generate otomatis berdasarkan task yang diverifikasi Auditor.</p>
</div>
</div>
</div>
</section>

<!-- AI FEATURES -->
<section id="ai" class="py-20 bg-brand-darker">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-purple text-sm font-bold tracking-widest mb-3">ARTIFICIAL INTELLIGENCE</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
3 Fitur <span class="gradient-text-2">AI</span> yang Mengubah Cara Kerja
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">Bukan sekadar dashboard — RAB-PROJECT memiliki AI yang bekerja untuk Anda 24/7.</p>
</div>
<div class="grid lg:grid-cols-3 gap-8">
<div class="glass rounded-xl p-6 border border-slate-700">
<div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-purple to-pink-500 flex items-center justify-center text-3xl mb-4">🤖</div>
<h3 class="font-heading text-xl font-bold text-white mb-2">AI Task Breakdown</h3>
<p class="text-slate-400 text-sm mb-4">Otomatis memecah lingkup proyek menjadi sub-task dengan jadwal realistis berdasarkan definisi & rentang tanggal.</p>
<div class="bg-brand-dark/60 rounded-lg p-3 mb-3">
<div class="text-xs text-brand-purple font-semibold mb-2">📋 Contoh Output AI:</div>
<div class="space-y-1 text-xs text-slate-300">
<div>🏗️ Pekerjaan Persiapan <span class="text-slate-500">→ 2026-10-21</span></div>
<div>⛏️ Pekerjaan Tanah & Pondasi <span class="text-slate-500">→ 2026-10-25</span></div>
<div>🏢 Struktur Kolom & Balok <span class="text-slate-500">→ 2026-11-02</span></div>
</div>
</div>
<div class="text-xs text-brand-purple font-semibold">→ Hemat 80% waktu planning</div>
</div>
<div class="glass rounded-xl p-6 border border-slate-700">
<div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-orange to-red-500 flex items-center justify-center text-3xl mb-4">📊</div>
<h3 class="font-heading text-xl font-bold text-white mb-2">AI Variance Analysis</h3>
<p class="text-slate-400 text-sm mb-4">Analisis otomatis selisih biaya & jadwal dengan label risiko (LOW/MEDIUM/HIGH/CRITICAL) + rekomendasi mitigasi.</p>
<div class="bg-brand-dark/60 rounded-lg p-3 mb-3">
<div class="text-xs text-brand-orange font-semibold mb-2">🔍 AI Cost & Schedule Variance</div>
<div class="text-xs text-brand-green font-bold mb-1">RISK: LOW</div>
<div class="text-xs text-slate-300 mb-2">Proyek menunjukkan efisiensi biaya sangat baik. Realisasi aktual Rp 37.500.000 dari anggaran Rp 150.000.000, menghasilkan sisa anggaran (favorable variance) sebesar Rp 112.500.000.</div>
<div class="text-xs text-slate-400 italic">Rekomendasi: Lakukan audit kualitas (QC) secara ketat dan tinjau ulang sisa ruang lingkup pekerjaan.</div>
</div>
<div class="text-xs text-brand-orange font-semibold">→ Deteksi masalah sebelum terjadi</div>
</div>
<div class="glass rounded-xl p-6 border border-slate-700">
<div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-cyan to-brand-green flex items-center justify-center text-3xl mb-4">🎯</div>
<h3 class="font-heading text-xl font-bold text-white mb-2">AI Milestone Prediction</h3>
<p class="text-slate-400 text-sm mb-4">Prediksi milestone terdekat 14 hari ke depan berdasarkan progres, jadwal, dan historis proyek sejenis.</p>
<div class="bg-brand-dark/60 rounded-lg p-3 mb-3">
<div class="text-xs text-brand-cyan font-semibold mb-2">📅 Milestone Terdekat:</div>
<div class="space-y-2">
<div class="text-xs">
<div class="text-white font-semibold">Pembersihan Akhir & Serah Terima</div>
<div class="text-slate-400">Gedung Wisma A - Surabaya</div>
<div class="text-brand-orange font-mono">2026-10-31</div>
</div>
<div class="text-xs">
<div class="text-white font-semibold">Pekerjaan Tanah dan Pondasi</div>
<div class="text-slate-400">Pembangunan Gudang MERR</div>
<div class="text-brand-orange font-mono">2026-11-01</div>
</div>
</div>
</div>
<div class="text-xs text-brand-cyan font-semibold">→ Tidak ada deadline terlewat</div>
</div>
</div>
</div>
</section>

<!-- [BARU] FINANCIAL REPORT SECTION -->
<section id="financial" class="py-20 bg-brand-dark">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-green text-sm font-bold tracking-widest mb-3">MODUL KEUANGAN LENGKAP</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
Dari Jurnal Otomatis hingga <span class="gradient-text">Laporan Keuangan</span>
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">Auto-posting jurnal untuk setiap transaksi, fiscal config yang dapat dikustomisasi, dan laporan keuangan real-time.</p>
</div>

<!-- Fiscal Config -->
<div class="glass rounded-xl p-6 border border-slate-700 mb-6">
<h3 class="font-heading text-xl font-bold text-white mb-4">FISCAL CONFIG — AUTO POSTING RULES</h3>
<div class="overflow-x-auto">
<table class="data-table">
<thead>
<tr>
<th>Rule Code</th>
<th>Nama Rule</th>
<th>Debit</th>
<th>Kredit</th>
<th>Status</th>
</tr>
</thead>
<tbody>
<tr>
<td class="font-mono text-brand-cyan">ISSUE_PROJECT</td>
<td class="text-slate-200">Issue material ke proyek</td>
<td class="text-slate-300">1700 - WIP Proyek</td>
<td class="text-slate-300">1500 - Persediaan</td>
<td><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green">Aktif</span></td>
</tr>
<tr>
<td class="font-mono text-brand-cyan">WIP_RELEASE</td>
<td class="text-slate-200">Pelepasan WIP ke HPP</td>
<td class="text-slate-300">5100 - Beban Pokok</td>
<td class="text-slate-300">1700 - WIP Proyek</td>
<td><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green">Aktif</span></td>
</tr>
<tr>
<td class="font-mono text-brand-cyan">PROJECT_INVOICE</td>
<td class="text-slate-200">Tagihan Termin Proyek</td>
<td class="text-slate-300">1200 - Piutang Usaha</td>
<td class="text-slate-300">4300 - Pendapatan</td>
<td><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green">Aktif</span></td>
</tr>
<tr>
<td class="font-mono text-brand-cyan">PRJ_PAY_AR</td>
<td class="text-slate-200">Pembayaran Termin</td>
<td class="text-slate-300">1111 - Bank BCA</td>
<td class="text-slate-300">1200 - Piutang Usaha</td>
<td><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green">Aktif</span></td>
</tr>
<tr>
<td class="font-mono text-brand-cyan">GRN_RCV</td>
<td class="text-slate-200">Penerimaan Barang (GRN)</td>
<td class="text-slate-300">1500 - Persediaan</td>
<td class="text-slate-300">2600 - GRN Clearing</td>
<td><span class="px-2 py-1 text-xs rounded-full bg-brand-green/20 text-brand-green">Aktif</span></td>
</tr>
</tbody>
</table>
</div>
<div class="mt-4 text-sm text-brand-green font-semibold">✓ 25+ rule auto-posting siap pakai untuk konstruksi</div>
</div>

<!-- Financial Report -->
<div class="grid lg:grid-cols-2 gap-6">
<div class="glass rounded-xl p-6 border border-slate-700">
<h3 class="font-heading text-xl font-bold text-white mb-4">📊 NERACA — PERIODE <?= date('Y-m') ?></h3>
<div class="space-y-3">
<div>
<div class="text-xs text-brand-cyan font-bold uppercase mb-2">ASET</div>
<div class="space-y-1 text-sm">
<div class="flex justify-between text-slate-300"><span>1112 - Bank Mandiri</span><span class="font-mono">Rp 750.000.000</span></div>
<div class="flex justify-between text-slate-300"><span>1200 - Piutang Usaha</span><span class="font-mono">Rp 15.000.000</span></div>
<div class="flex justify-between text-slate-300"><span>1500 - Persediaan</span><span class="font-mono">Rp 24.000.000</span></div>
<div class="flex justify-between text-slate-300"><span>1700 - WIP Proyek</span><span class="font-mono">Rp 16.828.000</span></div>
<div class="flex justify-between text-white font-bold pt-2 border-t border-slate-700"><span>Total Aset</span><span class="font-mono text-brand-cyan">Rp 805.828.000</span></div>
</div>
</div>
<div>
<div class="text-xs text-brand-orange font-bold uppercase mb-2 mt-4">LIABILITAS</div>
<div class="space-y-1 text-sm">
<div class="flex justify-between text-slate-300"><span>2600 - GRN Clearing</span><span class="font-mono">Rp 234.350.000</span></div>
<div class="flex justify-between text-white font-bold pt-2 border-t border-slate-700"><span>Total Liabilitas</span><span class="font-mono text-brand-orange">Rp 234.350.000</span></div>
</div>
</div>
<div>
<div class="text-xs text-brand-purple font-bold uppercase mb-2 mt-4">EKUITAS</div>
<div class="space-y-1 text-sm">
<div class="flex justify-between text-slate-300"><span>Laba Kumulatif</span><span class="font-mono">Rp 571.478.000</span></div>
<div class="flex justify-between text-white font-bold pt-2 border-t border-slate-700"><span>Total Ekuitas</span><span class="font-mono text-brand-purple">Rp 571.478.000</span></div>
</div>
</div>
<div class="mt-4 p-3 bg-brand-green/10 border border-brand-green/30 rounded-lg">
<div class="flex justify-between text-white font-bold">
<span>Total Liabilitas + Ekuitas</span>
<span class="font-mono text-brand-green">Rp 805.828.000</span>
</div>
<div class="text-xs text-brand-green mt-1">✓ BALANCE</div>
</div>
</div>
</div>

<div class="glass rounded-xl p-6 border border-slate-700">
<h3 class="font-heading text-xl font-bold text-white mb-4">📈 LAPORAN LABA RUGI</h3>
<div class="space-y-3">
<div>
<div class="text-xs text-brand-cyan font-bold uppercase mb-2">PENDAPATAN</div>
<div class="space-y-1 text-sm">
<div class="flex justify-between text-slate-300"><span>4300 - Pendapatan Proyek</span><span class="font-mono">Rp 765.000.000</span></div>
<div class="flex justify-between text-white font-bold pt-2 border-t border-slate-700"><span>Total Pendapatan</span><span class="font-mono text-brand-cyan">Rp 765.000.000</span></div>
</div>
</div>
<div>
<div class="text-xs text-brand-orange font-bold uppercase mb-2 mt-4">BEBAN</div>
<div class="space-y-1 text-sm">
<div class="flex justify-between text-slate-300"><span>5100 - Beban Pokok</span><span class="font-mono">Rp 193.522.000</span></div>
<div class="flex justify-between text-white font-bold pt-2 border-t border-slate-700"><span>Total Beban</span><span class="font-mono text-brand-orange">Rp 193.522.000</span></div>
</div>
</div>
<div class="mt-4 p-4 bg-gradient-to-r from-brand-green/20 to-brand-cyan/20 border border-brand-green/30 rounded-lg">
<div class="flex justify-between text-white font-bold text-lg">
<span>LABA BERSIH</span>
<span class="font-mono text-brand-green">Rp 571.478.000</span>
</div>
<div class="flex justify-between text-sm mt-2">
<span class="text-slate-400">💰 Margin Bersih</span>
<span class="text-brand-green font-bold">74.7% — Sangat sehat untuk industri konstruksi</span>
</div>
</div>
</div>
</div>
</div>
</div>
</section>

<!-- [BARU] MODULES SECTION -->
<section id="modules" class="py-20 bg-brand-darker">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-cyan text-sm font-bold tracking-widest mb-3">MODUL LENGKAP</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
12+ Modul <span class="gradient-text">Terintegrasi</span>
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">Satu sistem untuk seluruh siklus hidup proyek konstruksi.</p>
</div>
<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">🏗️</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Master Proyek</h3>
<p class="text-slate-400 text-xs">Setup proyek, klien, PIC, lokasi, budget, plafon kontrak, cadangan, PPN, retensi, DP minimum.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📊</div>
<h3 class="font-heading text-base font-bold text-white mb-1">RAB Material</h3>
<p class="text-slate-400 text-xs">Entry RAB dengan validasi plafon ketat, manajemen cadangan, wajib alasan addendum.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">🤖</div>
<h3 class="font-heading text-base font-bold text-white mb-1">AI Task Breakdown</h3>
<p class="text-slate-400 text-xs">Otomatis pecah lingkup proyek jadi sub-task dengan jadwal realistis.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📦</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Purchase Order</h3>
<p class="text-slate-400 text-xs">PO dengan validasi kuota RAB + toleransi, multi-currency, PPN otomatis.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📉</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Purchase Planning</h3>
<p class="text-slate-400 text-xs">Hitung otomatis sisa perlu beli = RAB − PO. Centang & buat PO dalam 1 klik.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">🏭</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Warehouse (WMS)</h3>
<p class="text-slate-400 text-xs">GRN, Issue Material, Stock Opname, Transfer, Retur Supplier — semua dengan auto-posting.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📝</div>
<h3 class="font-heading text-base font-bold text-white mb-1">SPK (Surat Perintah Kerja)</h3>
<p class="text-slate-400 text-xs">Alokasi kuota material per mandor/subkon dengan progress-gated issue.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📸</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Progress Proyek</h3>
<p class="text-slate-400 text-xs">Laporan progres lapangan + foto, verifikasi Auditor HO, update % progres real-time.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">🧾</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Invoice Termin</h3>
<p class="text-slate-400 text-xs">Auto-generate invoice dari task/progres, PPN, faktur pajak, monitoring jatuh tempo.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">💰</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Pembayaran</h3>
<p class="text-slate-400 text-xs">Terima pembayaran klien, alokasi ke invoice, deposit otomatis, AI variance analysis.</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">🔄</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Material Left Over</h3>
<p class="text-slate-400 text-xs">Retur sisa material dari lapangan, kurangi WIP, tambah stok gudang (FIFO).</p>
</div>
<div class="glass rounded-xl p-5 border border-slate-700 hover:border-brand-cyan/50 transition">
<div class="text-2xl mb-2">📒</div>
<h3 class="font-heading text-base font-bold text-white mb-1">Financial Report</h3>
<p class="text-slate-400 text-xs">Neraca, Laba Rugi, Cash Flow, PPN Report — semua real-time & balance otomatis.</p>
</div>
</div>
</div>
</section>

<!-- [BARU] COMPARISON SECTION -->
<section id="comparison" class="py-20 bg-brand-dark">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-orange text-sm font-bold tracking-widest mb-3">PERBANDINGAN</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
Mengapa Bukan <span class="gradient-text">ERP Lain?</span>
</h2>
<p class="text-slate-400 max-w-2xl mx-auto">ERP generik butuh kustomisasi mahal. App PM tidak punya kontrol material. RAB-PROJECT dirancang khusus untuk kontraktor.</p>
</div>
<div class="overflow-x-auto">
<table class="data-table glass rounded-xl border border-slate-700">
<thead>
<tr>
<th class="text-left">Fitur</th>
<th class="text-center text-brand-cyan">RAB-PROJECT</th>
<th class="text-center text-slate-400">ERP Generik (Odoo/SAP)</th>
<th class="text-center text-slate-400">App PM (Trello/Asana)</th>
</tr>
</thead>
<tbody>
<tr>
<td class="text-slate-200 font-semibold">Kontrol Issue vs Progres</td>
<td class="text-center text-brand-green font-bold">✓ Progress-Gated</td>
<td class="text-center text-brand-orange">⚠ Kustomisasi</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
</tr>
<tr>
<td class="text-slate-200 font-semibold">Plafon Kontrak Ketat</td>
<td class="text-center text-brand-green font-bold">✓ Hard Lock</td>
<td class="text-center text-brand-orange">⚠ Setup manual</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
</tr>
<tr>
<td class="text-slate-200 font-semibold">AI Task Breakdown</td>
<td class="text-center text-brand-green font-bold">✓ Otomatis</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
</tr>
<tr>
<td class="text-slate-200 font-semibold">AI Variance Analysis</td>
<td class="text-center text-brand-green font-bold">✓ + Rekomendasi</td>
<td class="text-center text-brand-orange">⚠ Angka mentah</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
</tr>
<tr>
<td class="text-slate-200 font-semibold">Akuntansi Konstruksi</td>
<td class="text-center text-brand-green font-bold">✓ Auto-Posting</td>
<td class="text-center text-brand-orange">⚠ Mapping COA</td>
<td class="text-center text-rose-400">✗ Tidak ada</td>
</tr>
<tr>
<td class="text-slate-200 font-semibold">Fokus Industri</td>
<td class="text-center text-brand-green font-bold">🎯 Konstruksi ID</td>
<td class="text-center text-slate-400">🌐 Umum</td>
<td class="text-center text-slate-400">🌐 Umum</td>
</tr>
</tbody>
</table>
</div>
</div>
</section>

<!-- [BARU] KEMAMPUAN SISTEM -->
<section class="py-20 bg-brand-darker">
<div class="max-w-7xl mx-auto px-6">
<div class="text-center mb-16">
<div class="text-brand-green text-sm font-bold tracking-widest mb-3">KEMAMPUAN SISTEM</div>
<h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
Dibangun untuk <span class="gradient-text">Efisiensi Nyata</span>
</h2>
</div>
<div class="grid md:grid-cols-2 lg:grid-cols-4 gap-6 mb-12">
<div class="glass rounded-xl p-6 border border-slate-700 text-center">
<div class="text-4xl mb-3">🛡️</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Pencegahan Kebocoran</h3>
<p class="text-slate-400 text-sm">Dengan progress-gated issue</p>
</div>
<div class="glass rounded-xl p-6 border border-slate-700 text-center">
<div class="text-4xl mb-3">⚡</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">Laporan Real-time</h3>
<p class="text-slate-400 text-sm">Hemat waktu vs bulanan</p>
</div>
<div class="glass rounded-xl p-6 border border-slate-700 text-center">
<div class="text-4xl mb-3">📒</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">100% Auto-Posting</h3>
<p class="text-slate-400 text-sm">Jurnal akurat & atomic</p>
</div>
<div class="glass rounded-xl p-6 border border-slate-700 text-center">
<div class="text-4xl mb-3">🤖</div>
<h3 class="font-heading text-lg font-bold text-white mb-2">80% Lebih Cepat</h3>
<p class="text-slate-400 text-sm">AI task breakdown</p>
</div>
</div>

<!-- Target Market -->
<div class="glass rounded-xl p-8 border border-slate-700">
<h3 class="font-heading text-2xl font-bold text-white mb-6 text-center">💼 Cocok Untuk:</h3>
<div class="grid md:grid-cols-2 gap-4">
<div class="flex items-start gap-3">
<div class="text-brand-green text-xl">✓</div>
<div class="text-slate-300">Kontraktor bangunan & sipil (pemula hingga menengah)</div>
</div>
<div class="flex items-start gap-3">
<div class="text-brand-green text-xl">✓</div>
<div class="text-slate-300">Kontraktor MEP & interior yang butuh kontrol material ketat</div>
</div>
<div class="flex items-start gap-3">
<div class="text-brand-green text-xl">✓</div>
<div class="text-slate-300">Developer perumahan & apartemen skala kecil-menengah</div>
</div>
<div class="flex items-start gap-3">
<div class="text-brand-green text-xl">✓</div>
<div class="text-slate-300">Manajemen proyek multi-site yang butuh visibilitas real-time</div>
</div>
</div>
</div>
</div>
</section>

<!-- [BARU] KOMITMEN SECTION -->
<section class="py-20 bg-brand-dark relative overflow-hidden">
<div class="absolute inset-0 grid-pattern opacity-30"></div>
<div class="relative max-w-4xl mx-auto px-6 text-center">
<div class="text-brand-cyan text-sm font-bold tracking-widest mb-6">🎯 KOMITMEN & FOKUS KAMI</div>
<blockquote class="glass rounded-2xl p-8 md:p-12 border border-brand-cyan/30">
<p class="text-lg md:text-xl text-slate-200 italic leading-relaxed mb-6">
"RAB-PROJECT dibangun dari pengalaman langsung menghadapi tantangan manajemen proyek di lapangan. 
Kami tidak menjual janji kosong. Sistem ini dirancang dengan logika bisnis yang ketat 
untuk memastikan setiap rupiah budget terpantau, setiap material teraccountability, dan setiap progres terverifikasi. 
Fokus kami adalah memberikan Anda alat pertahanan finansial yang andal sejak hari pertama penggunaan."
</p>
<div class="text-brand-cyan font-semibold">— Tim Pengembang RAB-PROJECT</div>
</blockquote>
</div>
</section>

<!-- CTA SECTION -->
<section class="py-20 bg-brand-darker relative overflow-hidden">
<div class="absolute inset-0 grid-pattern opacity-30"></div>
<div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-[600px] h-[600px] bg-brand-cyan/10 rounded-full blur-3xl"></div>
<div class="relative max-w-4xl mx-auto px-6 text-center">
<h2 class="font-display text-3xl md:text-5xl font-bold mb-6">
Siap <span class="gradient-text">Hentikan Kebocoran</span> & <span class="gradient-text-2">Mulai AI Monitoring</span>?
</h2>
<p class="text-lg text-slate-300 mb-8 max-w-2xl mx-auto">
Akses sistem sekarang untuk melihat langsung bagaimana RAB-PROJECT bisa menyelamatkan margin proyek Anda.
</p>
<div class="flex flex-wrap justify-center gap-4 mb-8">

<!-- <a href="dev/login.php" class="px-8 py-4 bg-brand-orange text-brand-dark font-bold rounded-lg hover:bg-orange-500 transition shadow-lg glow-orange flex items-center gap-2">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
Login ke Sistem
</a> -->

<a href="mailto:eddy.dev1302@gmail.com" class="px-8 py-4 glass text-white font-semibold rounded-lg hover:border-brand-cyan transition flex items-center gap-2">
<svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
Hubungi Tim Sales
</a>
</div>
<div class="flex flex-wrap justify-center gap-6 text-sm text-slate-400">
<div class="flex items-center gap-2"><span class="text-brand-green">✓</span> Setup cepat (1-2 minggu)</div>
<div class="flex items-center gap-2"><span class="text-brand-green">✓</span> Training included</div>
<div class="flex items-center gap-2"><span class="text-brand-green">✓</span> Support lokal Indonesia</div>
<div class="flex items-center gap-2"><span class="text-brand-green">✓</span> Data migration gratis</div>
</div>
</div>
</section>

<!-- FOOTER -->
<footer id="contact" class="bg-brand-darker border-t border-slate-800 py-12">
<div class="max-w-7xl mx-auto px-6">
<div class="grid md:grid-cols-4 gap-8 mb-8">
<div>
<div class="flex items-center gap-3 mb-4">
<div class="w-10 h-10 rounded-lg bg-gradient-to-br from-brand-cyan to-brand-orange flex items-center justify-center font-display font-bold text-brand-dark">R</div>
<div>
<div class="font-display font-bold tracking-wider">RAB-PROJECT</div>
<div class="text-xs text-slate-400">Construction ERP + AI</div>
</div>
</div>
<p class="text-sm text-slate-400">Sistem manajemen proyek konstruksi terintegrasi dengan AI khusus untuk kontraktor Indonesia.</p>
</div>
<div>
<h4 class="font-heading font-bold text-white mb-4">Produk</h4>
<ul class="space-y-2 text-sm text-slate-400">
<li><a href="#dashboard" class="hover:text-brand-cyan transition">Dashboard</a></li>
<li><a href="#ai" class="hover:text-brand-cyan transition">AI Features</a></li>
<li><a href="#modules" class="hover:text-brand-cyan transition">Modul</a></li>
<li><a href="#financial" class="hover:text-brand-cyan transition">Keuangan</a></li>
<li><a href="#comparison" class="hover:text-brand-cyan transition">Perbandingan</a></li>
</ul>
</div>
<div>
<h4 class="font-heading font-bold text-white mb-4">Modul Utama</h4>
<ul class="space-y-2 text-sm text-slate-400">
<li>RAB Material</li>
<li>Purchase Order</li>
<li>Warehouse (WMS)</li>
<li>Progress & Invoice</li>
<li>AI Variance Analysis</li>
<li>Financial Report</li>
</ul>
</div>
<div>
<h4 class="font-heading font-bold text-white mb-4">Kontak</h4>
<ul class="space-y-2 text-sm text-slate-400">
<li>📧 eddy.dev1302@gmail.com</li>
<li>💬 +62 812-3178-8177</li>
<li>📍 Sidoarjo, Indonesia</li>
</ul>
</div>
</div>
<div class="pt-8 border-t border-slate-800 text-center text-sm text-slate-500">
<div>&copy; <?= date('Y'); ?> RAB-PROJECT. All rights reserved.</div>
<div class="mt-2 space-x-4">
<a href="#" class="hover:text-brand-cyan transition">Privacy</a>
<a href="#" class="hover:text-brand-cyan transition">Terms</a>
<a href="#" class="hover:text-brand-cyan transition">Support</a>
</div>
</div>
</div>
</footer>

<script>
// Smooth scroll
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
anchor.addEventListener('click', function (e) {
const target = document.querySelector(this.getAttribute('href'));
if (target) {
e.preventDefault();
target.scrollIntoView({ behavior: 'smooth', block: 'start' });
}
});
});
// Fade-in animation
const observer = new IntersectionObserver((entries) => {
entries.forEach(entry => {
if (entry.isIntersecting) {
entry.target.style.opacity = '1';
entry.target.style.transform = 'translateY(0)';
}
});
}, { threshold: 0.1 });
document.querySelectorAll('.feature-card, .glass').forEach(el => {
el.style.opacity = '0';
el.style.transform = 'translateY(20px)';
el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
observer.observe(el);
});
</script>
</body>
</html>