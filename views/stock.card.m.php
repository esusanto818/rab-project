<?php
/*
Document        : stock.card.m.php
Description     : Stock Card Mobile View (PWA-ready, Vanilla JS)
File related    : - stock.card.m.js
                  - stock.cardCRD.php
*/
include "../php-func/user.valid.php";
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
$favVer = date('YmdHis');
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0b1d2b">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Kartu Stok Mobile</title>
<link rel="shortcut icon" href="<?php echo $B; ?>/../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<link rel="apple-touch-icon" href="<?php echo $B; ?>/../favicon.ico?v=<?=$favVer?>">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #020b14;
    color: #e2e8f0;
    padding: 12px;
    padding-bottom: 80px;
    min-height: 100vh;
}

/* Header */
.header {
    background: linear-gradient(135deg, #066b78 0%, #0b1d2b 100%);
    padding: 16px;
    border-radius: 12px;
    margin-bottom: 16px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.3);
}
.header h1 {
    font-size: 20px;
    color: #22d3ee;
    font-weight: 700;
    margin-bottom: 4px;
}
.header p {
    font-size: 12px;
    color: #94a3b8;
}

/* Status Bar */
.status-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    font-size: 11px;
}
.pill {
    padding: 4px 10px;
    border-radius: 12px;
    background: #1e3a52;
    color: #22d3ee;
    font-weight: 600;
    transition: all 0.3s;
}
.pill.off {
    background: #3b1212;
    color: #ff6b5e;
}

/* Message */
.msg {
    padding: 10px 14px;
    border-radius: 8px;
    margin-bottom: 12px;
    font-size: 13px;
    font-weight: 500;
    animation: slideDown 0.3s ease;
}
@keyframes slideDown {
    from { opacity: 0; transform: translateY(-10px); }
    to { opacity: 1; transform: translateY(0); }
}
.msg.err {
    background: #3b1212;
    border: 1px solid #c0392b;
    color: #ff6b5e;
}
.msg.ok {
    background: #0f2f22;
    border: 1px solid #2e9e4f;
    color: #7fe0a0;
}
.msg.hidden {
    display: none;
}

/* Filter Card */
.filter-card {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
}
.filter-row {
    margin-bottom: 12px;
}
.filter-row label {
    display: block;
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 4px;
    font-weight: 600;
}
.filter-row select,
.filter-row input {
    width: 100%;
    padding: 10px 12px;
    background: #061522;
    color: #fff;
    border: 1px solid #1e3a52;
    border-radius: 6px;
    font-size: 14px;
    -webkit-appearance: none;
    appearance: none;
    transition: border-color 0.2s;
}
.filter-row select:focus,
.filter-row input:focus {
    outline: none;
    border-color: #22d3ee;
}
.filter-row input[type="date"] {
    font-family: inherit;
}
.date-row {
    display: flex;
    gap: 8px;
}
.date-row .filter-row {
    flex: 1;
}
.btn-load {
    width: 100%;
    padding: 12px;
    background: #066b78;
    color: #fff;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 700;
    cursor: pointer;
    margin-top: 8px;
    transition: all 0.2s;
}
.btn-load:active {
    background: #0891a8;
    transform: scale(0.98);
}
.btn-load:disabled {
    background: #37566a;
    cursor: not-allowed;
    transform: none;
}

/* Summary Cards */
.summary-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
}
.sum-card {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 12px;
    text-align: center;
    transition: transform 0.2s;
}
.sum-card:active {
    transform: scale(0.97);
}
.sum-card.in {
    border-left: 4px solid #10b981;
}
.sum-card.out {
    border-left: 4px solid #f43f5e;
}
.sum-card.end {
    border-left: 4px solid #22d3ee;
    background: #0f253a;
}
.sum-lbl {
    font-size: 11px;
    color: #94a3b8;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin-bottom: 4px;
}
.sum-val {
    font-size: 16px;
    font-weight: 700;
    color: #fff;
    font-family: 'Courier New', monospace;
}

/* Section Title */
.section-title {
    font-size: 14px;
    font-weight: 700;
    color: #22d3ee;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #1e3a52;
}

/* Transaction Cards */
.tx-card {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    margin-bottom: 10px;
    overflow: hidden;
    transition: transform 0.2s, box-shadow 0.2s;
}
.tx-card:active {
    transform: scale(0.99);
    box-shadow: 0 2px 8px rgba(0,0,0,0.3);
}
.tx-card.open {
    border-left: 4px solid #e6a817;
}
.tx-card.in {
    border-left: 4px solid #10b981;
}
.tx-card.out {
    border-left: 4px solid #f43f5e;
}
.tx-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: #0a1929;
    border-bottom: 1px solid #1e3a52;
}
.tx-date {
    font-size: 13px;
    font-weight: 600;
    color: #fff;
}
.tx-type {
    font-size: 11px;
    padding: 3px 8px;
    border-radius: 10px;
    background: #1e3a52;
    color: #22d3ee;
}
.tx-body {
    padding: 10px 12px;
}
.tx-row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 4px 0;
    font-size: 12px;
    border-bottom: 1px solid rgba(30, 58, 82, 0.4);
}
.tx-row:last-child {
    border-bottom: none;
}
.tx-lbl {
    color: #94a3b8;
    flex: 0 0 110px;
}
.tx-in {
    color: #10b981;
    font-weight: 600;
}
.tx-out {
    color: #f43f5e;
    font-weight: 600;
}
.tx-bal {
    color: #22d3ee;
    font-weight: 700;
    font-size: 13px;
}

/* Empty State */
.card {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 14px;
    text-align: center;
    color: #94a3b8;
    font-size: 13px;
}
.card.err {
    border-color: #c0392b;
    color: #ff6b5e;
}

/* Bottom Navigation */
.bottom-nav {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    background: #0b1d2b;
    border-top: 1px solid #1e3a52;
    padding: 10px;
    display: flex;
    justify-content: space-around;
    z-index: 100;
    box-shadow: 0 -2px 10px rgba(0,0,0,0.3);
}
.bottom-nav a {
    color: #94a3b8;
    text-decoration: none;
    font-size: 11px;
    text-align: center;
    flex: 1;
    padding: 6px 0;
    border-radius: 6px;
    transition: all 0.2s;
}
.bottom-nav a:active {
    background: rgba(34, 211, 238, 0.1);
}
.bottom-nav a.active {
    color: #22d3ee;
    font-weight: 700;
}

/* Loading Spinner */
.spinner {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 2px solid #1e3a52;
    border-top-color: #22d3ee;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-right: 8px;
    vertical-align: middle;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Responsive */
@media (min-width: 768px) {
    body {
        max-width: 600px;
        margin: 0 auto;
    }
}
</style>
</head>
<body>
<div class="header">
    <h1>📦 Kartu Stok</h1>
    <p>Monitoring mutasi stok per gudang</p>
</div>

<div class="status-bar">
    <span class="pill" id="pillNet">online</span>
    <span style="font-size:11px;color:#64748b;">Stock Card Mobile v1.0</span>
</div>

<div id="msg" class="msg hidden"></div>

<div class="filter-card">
    <div class="filter-row">
        <label for="fWh">🏭 Gudang</label>
        <select id="fWh">
            <option value="0">-- Memuat gudang... --</option>
        </select>
    </div>
    <div class="filter-row">
        <label for="fItem">📦 Material</label>
        <select id="fItem">
            <option value="">-- Pilih gudang dulu --</option>
        </select>
    </div>
    <div class="date-row">
        <div class="filter-row">
            <label for="fFrom">📅 Dari</label>
            <input type="date" id="fFrom">
        </div>
        <div class="filter-row">
            <label for="fTo">📅 Sampai</label>
            <input type="date" id="fTo">
        </div>
    </div>
    <button class="btn-load" id="btnLoad">Tampilkan</button>
</div>

<div id="resultBox"></div>

<div class="bottom-nav">
    <a href="dashboard.php">🏠 Dashboard</a>
    <a href="stock.card.m.php" class="active">📦 Stok</a>
    <a href="project.progress.m.php">📊 Progress</a>
</div>

<script>
window.APP_BASE_URL = '<?php echo $B; ?>/';
</script>
<script src="<?php echo $B; ?>/js/stock.card.m.js"></script>
</body>
</html>