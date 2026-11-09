<?php
/*
Document        : dashboard.m.php
Description     : Dashboard Mobile - versi ringkas dari dashboard desktop
                  Menampilkan KPI utama, kesehatan proyek, dan action center
File related    : - dashboardCRD.php
                  - dashboard.m.js (optional, inline JS)
*/
include "../php-func/user.valid.php";
$B = rtrim(dirname($_SERVER["SCRIPT_NAME"]), "/");
$favVer = date('YmdHis');

// Cek session
if (empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit;
}

require_once __DIR__ . '/../php-class/crudClass.php';
$db = new crud();
$user_id = $_SESSION['user_id'] ?? 0;
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
<meta name="theme-color" content="#0b1d2b">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<title>Dashboard Mobile</title>
<link rel="shortcut icon" href="<?php echo $B; ?>/../favicon.ico?v=<?=$favVer?>" type="image/x-icon">
<style>
* { box-sizing: border-box; margin: 0; padding: 0; -webkit-tap-highlight-color: transparent; }
body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #020b14;
    color: #e2e8f0;
    padding: 12px;
    padding-bottom: 80px;
}
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
}
.pill.off { background: #3b1212; color: #ff6b5e; }

/* Project Selector */
.project-selector {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 12px;
    margin-bottom: 16px;
}
.project-selector label {
    display: block;
    font-size: 12px;
    color: #94a3b8;
    margin-bottom: 6px;
    font-weight: 600;
}
.project-selector select {
    width: 100%;
    padding: 10px 12px;
    background: #061522;
    color: #fff;
    border: 1px solid #1e3a52;
    border-radius: 6px;
    font-size: 14px;
    -webkit-appearance: none;
}

/* KPI Cards */
.kpi-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-bottom: 16px;
}
.kpi-card {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 14px;
    text-align: center;
}
.kpi-card .kpi-icon { font-size: 24px; margin-bottom: 6px; }
.kpi-card .kpi-label { font-size: 11px; color: #94a3b8; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 4px; }
.kpi-card .kpi-value { font-size: 18px; font-weight: 700; color: #fff; font-family: 'Courier New', monospace; }
.kpi-card .kpi-sub { font-size: 10px; color: #64748b; margin-top: 4px; }

/* Section */
.section {
    background: #0d2137;
    border: 1px solid #1e3a52;
    border-radius: 10px;
    padding: 14px;
    margin-bottom: 16px;
}
.section-title {
    font-size: 14px;
    font-weight: 700;
    color: #22d3ee;
    margin-bottom: 10px;
    padding-bottom: 6px;
    border-bottom: 1px solid #1e3a52;
    display: flex;
    align-items: center;
    gap: 6px;
}

/* Project Health Cards */
.health-card {
    background: #0a1929;
    border: 1px solid #1e3a52;
    border-radius: 8px;
    padding: 12px;
    margin-bottom: 8px;
    cursor: pointer;
    transition: border-color 0.2s;
}
.health-card:active { border-color: #22d3ee; }
.health-card .hc-name { font-size: 14px; font-weight: 600; color: #fff; margin-bottom: 6px; }
.health-card .hc-stats { display: flex; gap: 12px; font-size: 12px; color: #94a3b8; flex-wrap: wrap; }
.health-card .hc-stats b { color: #fff; }
.health-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
    margin-left: auto;
}
.badge-normal { background: #0f2f22; color: #2e9e4f; border: 1px solid #2e9e4f; }
.badge-waspada { background: #3a2c08; color: #e6a817; border: 1px solid #e6a817; }
.badge-kritis { background: #3b1212; color: #ff6b5e; border: 1px solid #ff6b5e; }

/* Action Items */
.action-item {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 10px 12px;
    background: #0a1929;
    border-radius: 8px;
    margin-bottom: 6px;
    border-left: 4px solid;
}
.action-item.danger { border-left-color: #f43f5e; }
.action-item.warn { border-left-color: #f59e0b; }
.action-item.info { border-left-color: #22d3ee; }
.action-item .ai-label { font-size: 13px; color: #e2e8f0; flex: 1; }
.action-item .ai-label small { display: block; font-size: 10px; color: #64748b; margin-top: 2px; }
.action-item .ai-count { font-size: 18px; font-weight: 700; font-family: 'Courier New', monospace; }

/* Loading */
.loading { text-align: center; padding: 20px; color: #64748b; font-size: 13px; }
.loading .spinner {
    display: inline-block;
    width: 20px; height: 20px;
    border: 2px solid #1e3a52;
    border-top-color: #22d3ee;
    border-radius: 50%;
    animation: spin 0.8s linear infinite;
    margin-right: 8px;
    vertical-align: middle;
}
@keyframes spin { to { transform: rotate(360deg); } }

/* Empty */
.empty { text-align: center; padding: 20px; color: #64748b; font-size: 13px; }

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
.bottom-nav a:active { background: rgba(34, 211, 238, 0.1); }
.bottom-nav a.active { color: #22d3ee; font-weight: 700; }

/* Responsive */
@media (min-width: 768px) { body { max-width: 600px; margin: 0 auto; } }
</style>
</head>
<body>

<div class="header">
    <h1>🏠 Dashboard</h1>
    <p>Monitoring Proyek Real-time</p>
</div>

<div class="status-bar">
    <span class="pill" id="pillNet">online</span>
    <span style="font-size:11px;color:#64748b;">Dashboard Mobile v1.0</span>
</div>

<!-- Project Selector -->
<div class="project-selector">
    <label for="fProject">📁 Pilih Proyek</label>
    <select id="fProject">
        <option value="0">-- Semua Proyek --</option>
    </select>
</div>

<!-- KPI Cards -->
<div class="kpi-grid" id="kpiGrid">
    <div class="kpi-card">
        <div class="kpi-icon">📊</div>
        <div class="kpi-label">Total Proyek</div>
        <div class="kpi-value" id="kpiProjects">-</div>
        <div class="kpi-sub">Aktif berjalan</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">💰</div>
        <div class="kpi-label">Nilai Kontrak</div>
        <div class="kpi-value" id="kpiContract">-</div>
        <div class="kpi-sub">Total semua proyek</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">⚡</div>
        <div class="kpi-label">Butuh Tindakan</div>
        <div class="kpi-value" id="kpiActions" style="color:#f43f5e;">-</div>
        <div class="kpi-sub">Item urgent</div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon">🏦</div>
        <div class="kpi-label">Saldo Kas</div>
        <div class="kpi-value" id="kpiCash">-</div>
        <div class="kpi-sub">Kas & Bank</div>
    </div>
</div>

<!-- Kesehatan Proyek -->
<div class="section" id="healthSection">
    <div class="section-title">🩺 Kesehatan Proyek</div>
    <div id="healthList">
        <div class="loading"><span class="spinner"></span> Memuat...</div>
    </div>
</div>

<!-- Action Center -->
<div class="section" id="actionSection">
    <div class="section-title">⚡ Action Center</div>
    <div id="actionList">
        <div class="loading"><span class="spinner"></span> Memuat...</div>
    </div>
</div>

<!-- Bottom Navigation -->
<div class="bottom-nav">
    <a href="dashboard.m.php" class="active">🏠 Dashboard</a>
    <a href="stock.card.m.php">📦 Stok</a>
    <a href="project.progress.m.php">📊 Progress</a>
</div>

<script>
window.APP_BASE_URL = '<?php echo $B; ?>/';
</script>
<script>
(function () {
'use strict';

var API = window.APP_BASE_URL + 'php-crud/dashboardCRD.php';
var currentProjectId = 0;

function $(id) { return document.getElementById(id); }
function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
function fmtRp(n) { return 'Rp ' + Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 0 }); }
function fmtNum(n) { return Number(n || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function updateNet() {
    var p = $('pillNet');
    if (p) { p.textContent = navigator.onLine ? 'online' : 'offline'; p.className = 'pill' + (navigator.onLine ? '' : ' off'); }
}

function api(params) {
    var url = API + '?' + Object.keys(params).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    return fetch(url, { credentials: 'same-origin' }).then(function (r) {
        if (r.status === 401) return Promise.reject({ auth: true });
        return r.json().catch(function () { return Promise.reject({ message: 'Respons server tidak valid.' }); });
    }, function () { return Promise.reject({ offline: true }); });
}

function showMsg(t, type) {
    // Simple inline message
    console.log('[' + type + ']', t);
}

// Load projects
function loadProjects() {
    return api({ action: 'get_projects' }).then(function (res) {
        if (!res.success) return;
        var sel = $('fProject');
        var h = '<option value="0">-- Semua Proyek --</option>';
        (res.data || []).forEach(function (p) {
            h += '<option value="' + p.id + '">' + esc(p.project_name) + '</option>';
        });
        sel.innerHTML = h;
    }).catch(function (e) {
        console.error('Load projects error:', e);
    });
}

// Load KPI summary
function loadKpi() {
    // Hitung dari health data
    api({ action: 'read_project_health', project_id: currentProjectId }).then(function (res) {
        if (!res.success) return;
        var data = res.data || [];
        $('kpiProjects').textContent = data.length;
        
        var totalContract = 0;
        data.forEach(function (p) { totalContract += (p.budget || 0); });
        $('kpiContract').textContent = fmtRp(totalContract);
        
        var kritis = 0;
        data.forEach(function (p) { if (p.level === 'KRITIS') kritis++; });
        $('kpiActions').textContent = kritis;
        
        renderHealth(data);
    }).catch(function (e) {
        $('kpiProjects').textContent = '-';
        $('healthList').innerHTML = '<div class="empty">Gagal memuat data.</div>';
    });
    
    // Cash from extra
    api({ action: 'read_extra', project_id: currentProjectId }).then(function (res) {
        if (res.success && res.data && res.data.cash !== null) {
            $('kpiCash').textContent = fmtRp(res.data.cash);
        }
    }).catch(function () {
        $('kpiCash').textContent = '-';
    });
}

// Load action center
function loadActions() {
    api({ action: 'read_action_center', project_id: currentProjectId }).then(function (res) {
        if (!res.success) {
            $('actionList').innerHTML = '<div class="empty">Gagal memuat action center.</div>';
            return;
        }
        var items = (res.items || []).filter(function (i) { return i.count > 0; });
        if (items.length === 0) {
            $('actionList').innerHTML = '<div class="empty">✔ Tidak ada yang perlu ditindaklanjuti.</div>';
            return;
        }
        var order = { danger: 0, warn: 1, info: 2 };
        items.sort(function (a, b) { return (order[a.level] || 2) - (order[b.level] || 2); });
        
        var h = '';
        items.forEach(function (i) {
            h += '<div class="action-item ' + (i.level || 'info') + '">' +
                 '<div class="ai-label">' + esc(i.label) + '<small>' + esc(i.hint || '') + '</small></div>' +
                 '<div class="ai-count" style="color:' + (i.level === 'danger' ? '#f43f5e' : (i.level === 'warn' ? '#f59e0b' : '#22d3ee')) + ';">' + i.count + '</div>' +
                 '</div>';
        });
        $('actionList').innerHTML = h;
    }).catch(function () {
        $('actionList').innerHTML = '<div class="empty">Gagal memuat action center.</div>';
    });
}

// Render health cards
function renderHealth(data) {
    if (!data || data.length === 0) {
        $('healthList').innerHTML = '<div class="empty">Tidak ada proyek berjalan.</div>';
        return;
    }
    var h = '';
    data.forEach(function (p) {
        var badgeClass = p.level === 'KRITIS' ? 'badge-kritis' : (p.level === 'WASPADA' ? 'badge-waspada' : 'badge-normal');
        var gapTxt = p.gap !== null && p.gap !== undefined ? (p.gap > 0 ? '+' + Math.round(p.gap) : Math.round(p.gap)) : '-';
        h += '<div class="health-card">' +
             '<div class="hc-name">' + esc(p.name) + ' <span class="health-badge ' + badgeClass + '">' + (p.level || 'NA') + '</span></div>' +
             '<div class="hc-stats">' +
             '<span>Progres: <b>' + p.progress + '%</b></span>' +
             '<span>Pemakaian: <b>' + (p.usage_pct !== null ? Number(p.usage_pct).toFixed(0) + '%' : '-') + '</b></span>' +
             '<span>Gap: <b>' + gapTxt + '</b></span>' +
             '</div></div>';
    });
    $('healthList').innerHTML = h;
}

// Init
function init() {
    updateNet();
    window.addEventListener('online', updateNet);
    window.addEventListener('offline', updateNet);
    
    loadProjects().then(function () {
        loadKpi();
        loadActions();
    });
    
    $('fProject').addEventListener('change', function () {
        currentProjectId = parseInt(this.value, 10) || 0;
        loadKpi();
        loadActions();
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
})();
</script>
</body>
</html>