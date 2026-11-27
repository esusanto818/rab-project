<?php
header('ngrok-skip-browser-warning: 1');
/*
Document        : project.progress.m.php
Description     : Lapor Progress (Mobile / PWA)
                  Lapangan mengajukan KLAIM progress dari HP (foto bukti, GPS, antrean offline).
                  Klaim diverifikasi office di modul desktop Progress Proyek (akses level 3).
File related    : - project.progress.m.js
                  - project.progressCRD.php (backend modul desktop, dipakai ulang)
                  - manifest.progress.json, sw.progress.js (optional PWA)
*/
include "../php-func/user.valid.php";   // wajib login; set $_SESSION["FormAccess"]
$appBase = rtrim(BASE_URL, '/') . '/';
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#03615e">
<title>Lapor Progress</title>
<link rel="manifest" href="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>manifest.progress.json">
<link rel="apple-touch-icon" href="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>assets/icon-192.png">
<link rel="stylesheet" href="../css/app-theme.css">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">

</head>
<body>

<header>
    <button id="btnBack" class="btn-back hidden">&larr;</button>
    <h1 id="ttl">📊 Lapor Progress</h1>
    <span id="pillQ" class="pill q hidden"></span>
    <span id="pillNet" class="pill">online</span>
</header>

<main>
    <div id="msg" class="msg hidden"></div>

    <!-- 1) Daftar Proyek -->
    <section id="vList">
        <div id="prjList">
            <div class="card" style="text-align:center">
                <span class="spinner"></span> Memuat daftar proyek...
            </div>
        </div>
    </section>

    <!-- 2) Form Klaim + Riwayat -->
    <section id="vForm" class="hidden">
        <div id="projInfo" class="info" style="margin-bottom:10px"></div>

        <div class="card" id="frmCard">
            <label for="fDate">📅 Tanggal Laporan</label>
            <input type="date" id="fDate">

            <label>📈 Persentase Progress</label>
            <div class="row" style="align-items:center">
                <input type="range" id="fRange" min="0" max="100" value="0" style="flex:2">
                <div style="flex:1;text-align:right">
                    <span id="lblPct" style="font-size:24px;font-weight:700">0</span>%
                </div>
            </div>
            <input type="number" id="fPct" min="0" max="100" value="0" style="margin-top:6px">

            <label for="fNotes">📝 Catatan</label>
            <textarea id="fNotes" rows="3" placeholder="Deskripsi pekerjaan yang sudah selesai..."></textarea>

            <label>📸 Foto Bukti (maks 5)</label>
            <div class="row">
                <button id="btnPhoto" class="sec" type="button">+ Tambah Foto</button>
                <button id="btnGps" class="sec" type="button">📍 Lokasi: -</button>
            </div>
            <input type="file" id="fPhoto" accept="image/*" capture="environment" multiple class="hidden">
            <div id="thumbs" class="thumbs"></div>

            <label for="fEvid">🔗 Bukti Lain (No. BA / Link)</label>
            <input type="text" id="fEvid" maxlength="300" placeholder="Contoh: BA-2026-001 atau https://...">

            <button id="btnSave" type="button" style="width:100%;margin-top:14px">💾 Simpan Klaim</button>
        </div>

        <!-- Antrean Offline -->
        <div id="qBox"></div>

        <!-- Riwayat -->
        <h2 style="font-size:16px;margin:16px 0 8px">📋 Riwayat Klaim</h2>
        <div id="hist">
            <div class="card" style="text-align:center">
                <span class="spinner"></span> Memuat riwayat...
            </div>
        </div>
    </section>
</main>

<!-- Bottom Navigation -->
<div class="bottom-nav">
    <a href="dashboard.php">🏠 Dashboard</a>
    <a href="stock.card.m.php">📦 Stok</a>
    <a href="project.progress.m.php" class="active">📊 Progress</a>
</div>

<script>
window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
window.APP_BASE_URL = <?php echo json_encode($appBase); ?>;
</script>
<script type="text/javascript" src="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>js/project.progress.m.js"></script>
<script>
const savedTheme = localStorage.getItem('app_theme') || 'dark';
document.documentElement.setAttribute('data-theme', savedTheme);
</script>
</body>
</html>