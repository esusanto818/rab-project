<?php
header('ngrok-skip-browser-warning: 1');
    /*
    Document        : Lapor Progress (Mobile / PWA)
    Description     : Lapangan mengajukan KLAIM progress dari HP (foto bukti, GPS, antrean offline).
                      Klaim diverifikasi office di modul desktop Progress Proyek (akses level 3).
    File related    : - project.progress.m.js
                      - project.progressCRD.php (backend modul desktop, dipakai ulang tanpa perubahan)
                      - manifest.progress.json, sw.progress.js
    */
    include "../php-func/user.valid.php";   // wajib login; set $_SESSION["FormAccess"] untuk form mobile ini
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
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    
</head>
<body>
    <header>
        <button id="btnBack" class="sec hidden" style="min-height:36px;padding:4px 12px">&larr;</button>
        <h1 id="ttl">Lapor Progress</h1>
        <span id="pillQ" class="pill q hidden"></span>
        <span id="pillNet" class="pill">online</span>
    </header>
    <main>
        <div id="msg" class="msg hidden"></div>
        <!-- 1) Daftar proyek -->
        <section id="vList"><div id="prjList"></div></section>
        <!-- 2) Form klaim + riwayat -->
        <section id="vForm" class="hidden">
            <div id="projInfo" class="info" style="margin-bottom:10px"></div>
            <div class="card" id="frmCard">
                <label>Tanggal laporan</label><input type="date" id="fDate">
                <label>Progress yang diklaim: <b id="lblPct">0</b>%</label>
                <input type="range" id="fRange" min="0" max="100" step="1" value="0">
                <input type="number" id="fPct" min="0" max="100" step="1" inputmode="numeric">
                <label>Catatan</label><textarea id="fNotes" rows="3" placeholder="Pekerjaan yang selesai..."></textarea>
                <label>Bukti (wajib: foto dan/atau no. BAP)</label>
                <input type="file" id="fPhoto" accept="image/*" capture="environment" multiple class="hidden">
                <div class="row"><button id="btnPhoto" class="sec" type="button">Ambil / Pilih Foto</button>
                                 <button id="btnGps" class="sec" type="button">Lokasi: -</button></div>
                <div id="thumbs" class="thumbs"></div>
                <label>No. BAP / keterangan bukti (opsional bila ada foto)</label>
                <input type="text" id="fEvid" maxlength="300">
                <div style="margin-top:14px"><button id="btnSave" style="width:100%">Ajukan Klaim</button></div>
            </div>
            <div id="qBox"></div>
            <h2>Riwayat klaim</h2>
            <div id="hist"></div>
        </section>
    </main>
    <script type="text/javascript">
        window.formAccess = <?php echo json_encode(intval($_SESSION["FormAccess"] ?? 0)); ?>;
        window.APP_BASE_URL = <?php echo json_encode($appBase); ?>;
    </script>
    <script type="text/javascript" src="<?= htmlspecialchars($appBase, ENT_QUOTES, "UTF-8") ?>js/project.progress.m.js"></script>
</body>
</html>