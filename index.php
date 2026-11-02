<?php
/*
Document : index.php
Description : Landing Page RAB-PROJECT - Construction ERP & AI Monitoring System
Theme : Dark Theme Modern
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
                <div class="text-xs text-slate-400 -mt-1">Construction ERP + AI</div>
            </div>
        </div>
        <div class="hidden md:flex items-center gap-8 text-sm font-medium">
            <a href="#features" class="hover:text-brand-cyan transition">Fitur</a>
            <a href="#dashboard" class="hover:text-brand-cyan transition">Dashboard</a>
            <a href="#ai" class="hover:text-brand-cyan transition">AI Features</a>
            <a href="#modules" class="hover:text-brand-cyan transition">Modul</a>
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
                ERP KONSTRUKSI + AI PERTAMA DI INDONESIA
            </div>
            <h1 class="font-display text-4xl md:text-6xl font-bold leading-tight mb-6">
                Stop <span class="gradient-text">Kebocoran</span> Material.<br>
                Mulai <span class="gradient-text-2">AI Monitoring</span> Real-time.
            </h1>
            <p class="text-lg md:text-xl text-slate-300 mb-8 leading-relaxed">
                Sistem ERP <strong class="text-white">khusus kontraktor</strong> dengan kontrol material berbasis progres,
                <strong class="text-brand-purple">AI Task Breakdown</strong>, <strong class="text-brand-orange">AI Variance Analysis</strong>,
                auto-posting jurnal, dan financial reporting terintegrasi.
            </p>
            <div class="flex flex-wrap gap-4">
                <a href="login.php" class="px-8 py-4 bg-brand-orange text-brand-dark font-bold rounded-lg hover:bg-orange-500 transition shadow-lg glow-orange flex items-center gap-2">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                    Login ke Sistem
                </a>
                <a href="#features" class="px-8 py-4 glass text-white font-semibold rounded-lg hover:border-brand-cyan transition">
                    Pelajari Fitur
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
                        <div class="text-xs text-slate-300"> Pekerjaan Tanah dan Pondasi - 2026-11-01</div>
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
        </div>

        <div class="grid lg:grid-cols-3 gap-8">
            <div class="glass rounded-xl p-6 border border-slate-700">
                <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-purple to-pink-500 flex items-center justify-center text-3xl mb-4"></div>
                <h3 class="font-heading text-xl font-bold text-white mb-2">AI Task Breakdown</h3>
                <p class="text-slate-400 text-sm mb-4">Otomatis memecah lingkup proyek menjadi sub-task dengan jadwal realistis.</p>
                <div class="text-xs text-brand-purple font-semibold">→ Hemat 80% waktu planning</div>
            </div>

            <div class="glass rounded-xl p-6 border border-slate-700">
                <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-orange to-red-500 flex items-center justify-center text-3xl mb-4">📊</div>
                <h3 class="font-heading text-xl font-bold text-white mb-2">AI Variance Analysis</h3>
                <p class="text-slate-400 text-sm mb-4">Analisis otomatis selisih biaya & jadwal dengan label risiko + rekomendasi mitigasi.</p>
                <div class="text-xs text-brand-orange font-semibold">→ Deteksi masalah sebelum terjadi</div>
            </div>

            <div class="glass rounded-xl p-6 border border-slate-700">
                <div class="w-14 h-14 rounded-lg bg-gradient-to-br from-brand-cyan to-brand-green flex items-center justify-center text-3xl mb-4">🎯</div>
                <h3 class="font-heading text-xl font-bold text-white mb-2">AI Milestone Prediction</h3>
                <p class="text-slate-400 text-sm mb-4">Prediksi milestone terdekat 14 hari ke depan berdasarkan progres & jadwal.</p>
                <div class="text-xs text-brand-cyan font-semibold">→ Tidak ada deadline terlewat</div>
            </div>
        </div>
    </div>
</section>

<!-- MODULES -->
<section id="modules" class="py-20 bg-brand-dark">
    <div class="max-w-7xl mx-auto px-6">
        <div class="text-center mb-16">
            <div class="text-brand-cyan text-sm font-bold tracking-widest mb-3">MODUL LENGKAP</div>
            <h2 class="font-display text-3xl md:text-5xl font-bold mb-4">
                12+ Modul <span class="gradient-text">Terintegrasi</span>
            </h2>
        </div>

        <div class="grid md:grid-cols-2 lg:grid-cols-4 gap-4">
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">🏗️</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Master Proyek</h3>
                <p class="text-slate-400 text-xs">Setup proyek, klien, PIC, lokasi, budget, plafon kontrak.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">📊</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">RAB Material</h3>
                <p class="text-slate-400 text-xs">Entry RAB dengan validasi plafon ketat & manajemen cadangan.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">📦</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Purchase Order</h3>
                <p class="text-slate-400 text-xs">PO dengan validasi kuota RAB + toleransi, multi-currency.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">🏭</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Warehouse (WMS)</h3>
                <p class="text-slate-400 text-xs">GRN, Issue Material, Stock Opname dengan auto-posting.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">📸</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Progress Proyek</h3>
                <p class="text-slate-400 text-xs">Laporan progres lapangan + foto, verifikasi Auditor HO.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">🧾</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Invoice Termin</h3>
                <p class="text-slate-400 text-xs">Auto-generate invoice dari task/progres, PPN, faktur pajak.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">💰</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Pembayaran</h3>
                <p class="text-slate-400 text-xs">Terima pembayaran klien, alokasi ke invoice, AI variance.</p>
            </div>
            <div class="glass rounded-xl p-5 border border-slate-700">
                <div class="text-2xl mb-2">📒</div>
                <h3 class="font-heading text-base font-bold text-white mb-1">Financial Report</h3>
                <p class="text-slate-400 text-xs">Neraca, Laba Rugi, Cash Flow real-time & balance otomatis.</p>
            </div>
        </div>
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
            <a href="login.php"
               class="px-8 py-4 bg-brand-orange text-brand-dark font-bold rounded-lg hover:bg-orange-500 transition shadow-lg glow-orange flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path></svg>
                Login ke Sistem
            </a>
            <a href="mailto:eddy.dev1302@google.com"
               class="px-8 py-4 glass text-white font-semibold rounded-lg hover:border-brand-cyan transition flex items-center gap-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                Hubungi Sales
            </a>
        </div>
        <div class="flex flex-wrap justify-center gap-6 text-sm text-slate-400">
            <div class="flex items-center gap-2">
                <span class="text-brand-green">✓</span> Setup cepat (1-2 minggu)
            </div>
            <div class="flex items-center gap-2">
                <span class="text-brand-green">✓</span> Training included
            </div>
            <div class="flex items-center gap-2">
                <span class="text-brand-green">✓</span> Support lokal Indonesia
            </div>
        </div>
    </div>
</section>

<!-- FOOTER -->
<footer class="bg-brand-darker border-t border-slate-800 py-12">
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
                    <li><a href="#features" class="hover:text-brand-cyan transition">Fitur</a></li>
                    <li><a href="#ai" class="hover:text-brand-cyan transition">AI Features</a></li>
                    <li><a href="#modules" class="hover:text-brand-cyan transition">Modul</a></li>
                </ul>
            </div>
            <div>
                <h4 class="font-heading font-bold text-white mb-4">Modul Utama</h4>
                <ul class="space-y-2 text-sm text-slate-400">
                    <li>RAB Material</li>
                    <li>Purchase Order</li>
                    <li>Warehouse (WMS)</li>
                    <li>Financial Report</li>
                </ul>
            </div>
            <div>
                <h4 class="font-heading font-bold text-white mb-4">Kontak</h4>
                <ul class="space-y-2 text-sm text-slate-400">
                    <li>eddy.dev1302@gmail.com</li>
                    <li>💬 +62 8123-1788-177</li>
                    <li>📍 Sidoarjo, Indonesia</li>
                </ul>
            </div>
        </div>
        <div class="pt-8 border-t border-slate-800 text-center text-sm text-slate-500">
            <div>&copy; <?= date('Y'); ?> RAB-PROJECT. All rights reserved.</div>
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