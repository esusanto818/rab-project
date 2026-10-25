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
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#03615e">
    <title>Lapor Progress</title>
    <link rel="manifest" href="/rab-project/manifest.progress.json">
    <link rel="apple-touch-icon" href="/rab-project/assets/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <style>
        :root { --bg:#10202a; --card:#1b2f3c; --line:#2b4656; --txt:#eef5f8; --mut:#8fb0bf; --ok:#2e9e4f; --warn:#e6a817; --err:#c0392b; --pri:#03615e; }
        * { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
        html, body { margin:0; background:var(--bg); color:var(--txt); font:16px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif; }
        body { padding:env(safe-area-inset-top) 0 env(safe-area-inset-bottom) 0; }
        header { position:sticky; top:0; z-index:5; background:var(--pri); padding:12px 14px; display:flex; align-items:center; gap:10px; }
        header h1 { font-size:18px; margin:0; flex:1; }
        .pill { font-size:12px; padding:3px 9px; border-radius:12px; background:#0008; }
        .pill.off { background:var(--err); } .pill.q { background:var(--warn); color:#000; }
        main { padding:12px; max-width:640px; margin:0 auto; }
        .card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px; margin-bottom:10px; }
        .prj { cursor:pointer; } .prj:active { background:#24404f; }
        .prj .nm { font-weight:600; } .prj .sub { color:var(--mut); font-size:13px; }
        .tag { display:inline-block; font-size:12px; padding:2px 8px; border-radius:10px; margin-left:6px; background:#e6a81733; color:var(--warn); }
        .bar { height:8px; background:#0006; border-radius:4px; margin-top:8px; overflow:hidden; }
        .bar > i { display:block; height:100%; background:var(--ok); }
        label { display:block; margin:12px 0 4px; color:var(--mut); font-size:14px; }
        input[type=number], input[type=date], input[type=text], textarea { width:100%; min-height:44px; padding:10px; font-size:16px; /* 16px = iOS tidak auto-zoom */
            color:var(--txt); background:#0c1820; border:1px solid var(--line); border-radius:8px; }
        input[type=range] { width:100%; height:44px; }
        button { min-height:46px; padding:10px 16px; font-size:16px; font-weight:600; border:0; border-radius:8px; color:#fff; background:var(--pri); }
        button.sec { background:#38505e; } button.danger { background:var(--err); } button:disabled { opacity:.5; }
        .row { display:flex; gap:8px; } .row > * { flex:1; }
        .thumbs { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
        .thumbs div { position:relative; } .thumbs img { width:84px; height:84px; object-fit:cover; border-radius:6px; border:1px solid var(--line); }
        .thumbs b { position:absolute; top:-6px; right:-6px; width:24px; height:24px; line-height:24px; text-align:center; border-radius:12px; background:var(--err); }
        .hist .pct { font-size:20px; font-weight:700; } .hist .meta { color:var(--mut); font-size:13px; }
        .st { font-weight:700; font-size:13px; float:right; }
        .msg { padding:10px; border-radius:8px; margin-bottom:10px; background:#0008; } .msg.err { background:#c0392b55; } .msg.ok { background:#2e9e4f55; }
        .info { padding:8px 10px; border-radius:8px; background:#0006; color:var(--mut); font-size:14px; }
        .hidden { display:none; } h2 { font-size:16px; margin:16px 0 8px; color:var(--mut); }
    </style>
</head>
<body>
    <header>
        <button id="btnBack" class="sec hidden" style="min-height:36px;padding:4px 12px;">&larr;</button>
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
            <div id="projInfo" class="info" style="margin-bottom:10px;"></div>
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
    </script>
<<<<<<< Updated upstream
    <script type="text/javascript" src="/rab-project/dev/js/project.progress.m.js"></script>
=======
    <script type="text/javascript" src="/rab-project/live/js/project.progress.m.js"></script>
>>>>>>> Stashed changes
</body>
</html>