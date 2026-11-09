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
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<style>
:root {
    --bg:#10202a; --card:#1b2f3c; --line:#2b4656; --txt:#eef5f8; --mut:#8fb0bf;
    --ok:#2e9e4f; --warn:#e6a817; --err:#c0392b; --pri:#03615e; --cyan:#22d3ee;
}
* { box-sizing:border-box; -webkit-tap-highlight-color:transparent; }
html, body { margin:0; background:var(--bg); color:var(--txt); font:16px/1.4 -apple-system, "Segoe UI", Roboto, sans-serif; }
body { padding:env(safe-area-inset-top) 0 calc(env(safe-area-inset-bottom) + 60px) 0; }

/* Header */
header {
    position:sticky; top:0; z-index:5;
    background:var(--pri);
    padding:12px 14px;
    display:flex; align-items:center; gap:10px;
    box-shadow:0 2px 8px rgba(0,0,0,0.3);
}
header h1 { font-size:18px; margin:0; flex:1; color:#fff; }
header .btn-back {
    background:rgba(255,255,255,0.1);
    border:1px solid rgba(255,255,255,0.2);
    color:#fff; padding:6px 12px; border-radius:6px;
    font-size:13px; cursor:pointer; text-decoration:none;
}
header .btn-back:active { background:rgba(255,255,255,0.2); }

/* Status bar */
.status-bar {
    display:flex; justify-content:space-between; align-items:center;
    margin-bottom:12px; font-size:11px;
}
.pill { font-size:12px; padding:3px 9px; border-radius:12px; background:#0008; color:var(--cyan); font-weight:600; }
.pill.off { background:var(--err); color:#fff; }
.pill.q { background:var(--warn); color:#000; }

/* Messages */
.msg { padding:10px; border-radius:8px; margin-bottom:10px; background:#0008; }
.msg.err { background:#c0392b55; color:#ff8a7d; }
.msg.ok { background:#2e9e4f55; color:#7fe0a0; }
.msg.hidden { display:none; }

/* Main container */
main { padding:12px; max-width:640px; margin:0 auto; }

/* Cards */
.card { background:var(--card); border:1px solid var(--line); border-radius:10px; padding:12px; margin-bottom:10px; }
.prj { cursor:pointer; transition:background 0.2s; }
.prj:active { background:#24404f; }
.prj .nm { font-weight:600; font-size:15px; }
.prj .sub { color:var(--mut); font-size:13px; margin-top:2px; }
.tag { display:inline-block; font-size:12px; padding:2px 8px; border-radius:10px; margin-left:6px; background:#e6a81733; color:var(--warn); }
.bar { height:8px; background:#0006; border-radius:4px; margin-top:8px; overflow:hidden; }
.bar > i { display:block; height:100%; background:var(--ok); transition:width 0.3s; }

/* Form */
label { display:block; margin:12px 0 4px; color:var(--mut); font-size:14px; font-weight:600; }
input[type=number], input[type=date], input[type=text], textarea, select {
    width:100%; min-height:44px; padding:10px; font-size:16px;
    color:var(--txt); background:#0c1820; border:1px solid var(--line); border-radius:8px;
}
input[type=number]:focus, input[type=date]:focus, input[type=text]:focus, textarea:focus, select:focus {
    outline:none; border-color:var(--cyan);
}
input[type=range] { width:100%; height:44px; }

/* Buttons */
button { min-height:46px; padding:10px 16px; font-size:16px; font-weight:600; border:0; border-radius:8px; color:#fff; background:var(--pri); cursor:pointer; }
button.sec { background:#38505e; }
button.danger { background:var(--err); }
button:disabled { opacity:.5; cursor:not-allowed; }
button:active:not(:disabled) { transform:scale(0.98); }
.row { display:flex; gap:8px; }
.row > * { flex:1; }

/* Photo */
.thumbs { display:flex; flex-wrap:wrap; gap:6px; margin-top:8px; }
.thumbs div { position:relative; }
.thumbs img { width:84px; height:84px; object-fit:cover; border-radius:6px; border:1px solid var(--line); }
.thumbs b {
    position:absolute; top:-6px; right:-6px; width:24px; height:24px; line-height:24px;
    text-align:center; border-radius:50%; background:var(--err); color:#fff; cursor:pointer;
    font-size:14px; font-weight:bold;
}

/* History */
.hist .pct { font-size:20px; font-weight:700; color:var(--cyan); margin-right:8px; }
.hist .meta { color:var(--mut); font-size:13px; }
.hist .st { font-weight:700; font-size:13px; float:right; }
.hist .thumbs img { width:60px; height:60px; }

/* Queue */
.queue-box h2 { font-size:16px; margin:16px 0 8px; color:var(--mut); }

/* Info box */
.info { padding:8px 10px; border-radius:8px; background:#0006; color:var(--mut); font-size:14px; line-height:1.5; }

/* Bottom Navigation */
.bottom-nav {
    position:fixed;
    bottom:0; left:0; right:0;
    background:#0b1d2b;
    border-top:1px solid var(--line);
    padding:10px;
    display:flex;
    justify-content:space-around;
    z-index:100;
    box-shadow:0 -2px 10px rgba(0,0,0,0.3);
}
.bottom-nav a {
    color:#94a3b8;
    text-decoration:none;
    font-size:11px;
    text-align:center;
    flex:1;
    padding:6px 0;
    border-radius:6px;
    transition:all 0.2s;
}
.bottom-nav a:active { background:rgba(34, 211, 238, 0.1); }
.bottom-nav a.active { color:var(--cyan); font-weight:700; }

.hidden { display:none !important; }

/* Loading spinner */
.spinner {
    display:inline-block; width:20px; height:20px;
    border:2px solid var(--line); border-top-color:var(--cyan);
    border-radius:50%; animation:spin 0.8s linear infinite;
    margin-right:8px; vertical-align:middle;
}
@keyframes spin { to { transform:rotate(360deg); } }

@media (min-width:768px) { body { max-width:600px; margin:0 auto; } }
</style>
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
            <div class="card" style="text-align:center;color:var(--mut);">
                <span class="spinner"></span> Memuat daftar proyek...
            </div>
        </div>
    </section>

    <!-- 2) Form Klaim + Riwayat -->
    <section id="vForm" class="hidden">
        <div id="projInfo" class="info" style="margin-bottom:10px;"></div>

        <div class="card" id="frmCard">
            <label for="fDate">📅 Tanggal Laporan</label>
            <input type="date" id="fDate">

            <label>📈 Persentase Progress</label>
            <div class="row" style="align-items:center;">
                <input type="range" id="fRange" min="0" max="100" value="0" style="flex:2;">
                <div style="flex:1;text-align:right;">
                    <span id="lblPct" style="font-size:24px;font-weight:700;color:var(--cyan);">0</span>%
                </div>
            </div>
            <input type="number" id="fPct" min="0" max="100" value="0" style="margin-top:6px;">

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

            <button id="btnSave" type="button" style="width:100%;margin-top:14px;">💾 Simpan Klaim</button>
        </div>

        <!-- Antrean Offline -->
        <div id="qBox"></div>

        <!-- Riwayat -->
        <h2 style="font-size:16px;margin:16px 0 8px;color:var(--mut);">📋 Riwayat Klaim</h2>
        <div id="hist">
            <div class="card" style="text-align:center;color:var(--mut);">
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
</body>
</html>