/*
Document      : stock.card.m.js
Description   : Stock Card Mobile (Read-only monitoring stok per gudang/item/periode)
                - Vanilla JS + PWA (tanpa ExtJS)
                - [FIX 2026-11-08] Force refresh warehouses, debug logging, URL validation
Backend       : ../php-crud/stock.cardCRD.php
View          : ../views/stock.card.m.php
*/
(function () {
'use strict';

// [FIX] Validasi URL backend
var APP_BASE = window.APP_BASE_URL || '';
if (!APP_BASE) {
    console.error('[StockCard.m] window.APP_BASE_URL tidak terdefinisi! Cek views/stock.card.m.php');
    APP_BASE = '../';
}
var CRD = APP_BASE + 'php-crud/stock.cardCRD.php';
console.log('[StockCard.m] Backend URL:', CRD);

var LS_LAST_RESULT = 'scm_last_result_cache';
var DB_NAME = 'stockCardMobile';
var DB_VERSION = 1;
var STORE_NAME = 'cache';

var warehouses = [];
var items = [];
var db = null;

// Null-safe DOM helpers
function $(id) { 
    var el = document.getElementById(id); 
    if (!el) console.warn('[StockCard.m] Elemen #' + id + ' tidak ditemukan di DOM.');
    return el; 
}
function setVal(id, val) { var el = $(id); if (el) el.value = val; }

function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
function todayStr() { var d = new Date(), m = d.getMonth() + 1, dd = d.getDate(); return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd); }
function monthStartStr() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1) + '-01'; }

function fmtQty(v) {
    if (v === null || v === undefined || v === '') return '-';
    return Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}
function fmtAmt(v) {
    if (v === null || v === undefined || v === '') return '-';
    return 'Rp ' + Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtDate(s) {
    if (!s) return '-';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s));
    return m ? (m[3] + '/' + m[2] + '/' + m[1]) : s;
}

// ============================================================
// 0. IndexedDB Cache
// ============================================================
function openDb() {
    if (db) return Promise.resolve(db);
    return new Promise(function (res, rej) {
        var rq = indexedDB.open(DB_NAME, DB_VERSION);
        rq.onupgradeneeded = function () { rq.result.createObjectStore(STORE_NAME, { keyPath: 'key' }); };
        rq.onsuccess = function () { db = rq.result; res(db); };
        rq.onerror = function () { rej(rq.error); };
    });
}
function cacheGet(key) {
    return openDb().then(function (d) {
        return new Promise(function (res) {
            var tx = d.transaction(STORE_NAME, 'readonly');
            var rq = tx.objectStore(STORE_NAME).get(key);
            rq.onsuccess = function () { res(rq.result ? rq.result.value : null); };
            rq.onerror = function () { res(null); };
        });
    });
}
function cachePut(key, value) {
    return openDb().then(function (d) {
        return new Promise(function (res) {
            var tx = d.transaction(STORE_NAME, 'readwrite');
            tx.objectStore(STORE_NAME).put({ key: key, value: value, ts: Date.now() });
            tx.oncomplete = function () { res(); };
        });
    });
}

// ============================================================
// 1. API - [FIX] Logging lengkap untuk debugging
// ============================================================
function api(params) {
    var url = CRD + '?' + Object.keys(params).map(function (k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
    }).join('&');
    
    console.log('[StockCard.m] Request:', url);
    
    return fetch(url, { credentials: 'same-origin' })
        .then(function (r) {
            console.log('[StockCard.m] Response status:', r.status, r.statusText);
            if (r.status === 401) return Promise.reject({ auth: true });
            if (r.status === 404) return Promise.reject({ message: 'Endpoint tidak ditemukan: ' + url });
            if (r.status === 500) return Promise.reject({ message: 'Server error (500). Cek error_log PHP.' });
            return r.text().then(function(txt) {
                console.log('[StockCard.m] Response body:', txt.substring(0, 500));
                try {
                    return JSON.parse(txt);
                } catch (e) {
                    return Promise.reject({ message: 'Respons bukan JSON: ' + txt.substring(0, 200) });
                }
            });
        }, function (err) { 
            console.error('[StockCard.m] Fetch error:', err);
            return Promise.reject({ offline: true, error: err }); 
        });
}

function showMsg(t, type) {
    var m = $('msg');
    if (!m) { console.log('[' + type + ']', t); return; }
    m.textContent = t;
    m.className = 'msg ' + (type || '');
    if (type === 'ok') setTimeout(function () { m.className = 'msg hidden'; }, 4000);
}
function hideMsg() { var m = $('msg'); if (m) m.className = 'msg hidden'; }

function updateNet() {
    var p = $('pillNet');
    if (p) { p.textContent = navigator.onLine ? 'online' : 'offline'; p.className = 'pill' + (navigator.onLine ? '' : ' off'); }
}

// ============================================================
// 2. Load Warehouses - [FIX] Force refresh, skip cache
// ============================================================
function loadWarehouses() {
    // [FIX] HAPUS cache localStorage untuk warehouses agar selalu fetch dari server
    try { localStorage.removeItem('scm_warehouses_cache'); } catch (e) {}
    
    console.log('[StockCard.m] Memuat daftar gudang dari server...');
    
    return api({ action: 'lookup_warehouses' }).then(function (res) {
        console.log('[StockCard.m] Warehouses response:', res);
        if (!res || !res.success) { 
            showMsg((res && res.message) || 'Gagal memuat gudang.', 'err'); 
            warehouses = [];
            renderWarehouses();
            return; 
        }
        warehouses = res.data || [];
        console.log('[StockCard.m] Ditemukan', warehouses.length, 'gudang');
        renderWarehouses();
    }, function (e) {
        console.error('[StockCard.m] Load warehouses error:', e);
        if (e.auth) showMsg('Sesi berakhir. Buka ulang halaman & login.', 'err');
        else if (e.offline) showMsg('Offline. Tidak bisa memuat gudang.', 'err');
        else showMsg(e.message || 'Gagal memuat gudang.', 'err');
        warehouses = [];
        renderWarehouses();
    });
}

function loadItems(warehouseId) {
    if (!warehouseId || warehouseId <= 0) {
        items = [];
        renderItems();
        return Promise.resolve();
    }
    var cacheKey = 'items_wh_' + warehouseId;
    return cacheGet(cacheKey).then(function (cached) {
        if (cached && cached.length) {
            items = cached;
            renderItems();
        }
        return api({ action: 'lookup_items', warehouse_id: warehouseId, query: '' });
    }).then(function (res) {
        console.log('[StockCard.m] Items response:', res);
        if (!res || !res.success) { showMsg((res && res.message) || 'Gagal memuat material.', 'err'); return; }
        items = res.data || [];
        var cacheKey = 'items_wh_' + warehouseId;
        cachePut(cacheKey, items);
        renderItems();
    }, function (e) {
        console.error('[StockCard.m] Load items error:', e);
        if (e.auth) showMsg('Sesi berakhir.', 'err');
        else showMsg(e.message || 'Gagal memuat material.', 'err');
    });
}

// ============================================================
// 3. Render UI
// ============================================================
function renderWarehouses() {
    var sel = $('fWh');
    if (!sel) { console.error('[StockCard.m] Dropdown #fWh tidak ada!'); return; }
    
    console.log('[StockCard.m] Rendering', warehouses.length, 'warehouses');
    
    var h = '<option value="0">-- Pilih Gudang --</option>';
    if (warehouses.length === 0) {
        h = '<option value="0">-- Tidak ada gudang dengan stok --</option>';
    }
    warehouses.forEach(function (w) {
        h += '<option value="' + w.id + '">' + esc(w.name) + '</option>';
    });
    sel.innerHTML = h;
}

function renderItems() {
    var sel = $('fItem');
    if (!sel) return;
    var h = '<option value="">-- Pilih Material --</option>';
    items.forEach(function (it) {
        var stockInfo = it.qty_on_hand > 0 ? ' [Stok: ' + fmtQty(it.qty_on_hand) + ']' : '';
        h += '<option value="' + it.item_id + '">' + esc(it.material_name) + ' (' + esc(it.unit) + ')' + stockInfo + '</option>';
    });
    sel.innerHTML = h;
}

function renderResult(data) {
    var box = $('resultBox');
    if (!box) return;
    if (!data || !data.success) {
        box.innerHTML = '<div class="card err">' + esc((data && data.message) || 'Tidak ada data.') + '</div>';
        return;
    }
    var rows = data.data || [];
    var summary = data.summary || {};
    var html = '';
    
    html += '<div class="summary-grid">';
    html += '<div class="sum-card"><div class="sum-lbl">Saldo Awal</div><div class="sum-val">' + fmtQty(summary.opening) + '</div></div>';
    html += '<div class="sum-card in"><div class="sum-lbl">Total Masuk</div><div class="sum-val">' + fmtQty(summary.total_in) + '</div></div>';
    html += '<div class="sum-card out"><div class="sum-lbl">Total Keluar</div><div class="sum-val">' + fmtQty(summary.total_out) + '</div></div>';
    html += '<div class="sum-card end"><div class="sum-lbl">Saldo Akhir</div><div class="sum-val">' + fmtQty(summary.ending) + '</div></div>';
    html += '</div>';
    
    if (!rows.length) {
        html += '<div class="card">Tidak ada mutasi pada periode ini.</div>';
    } else {
        html += '<div class="section-title">Rincian Mutasi (' + rows.length + ' transaksi)</div>';
        rows.forEach(function (r) {
            var isIn = r.row_type === 'OPEN';
            var qtyIn = r.qty_in !== null ? fmtQty(r.qty_in) : '-';
            var qtyOut = r.qty_out !== null ? fmtQty(r.qty_out) : '-';
            var refType = r.reference_type ? esc(r.reference_type) : '-';
            var refNo = r.reference_no ? esc(r.reference_no) : '-';
            var wh = r.warehouse_name ? esc(r.warehouse_name) : '-';
            var remark = r.remark ? esc(r.remark) : '-';
            var cost = r.unit_cost !== null ? fmtAmt(r.unit_cost) : '-';
            
            html += '<div class="tx-card ' + (isIn ? 'open' : (r.qty_in ? 'in' : 'out')) + '">' +
                '<div class="tx-head">' +
                '<span class="tx-date">' + fmtDate(r.movement_date) + '</span>' +
                '<span class="tx-type">' + (isIn ? '📋 Saldo Awal' : (r.qty_in ? '📥 Masuk' : '📤 Keluar')) + '</span>' +
                '</div>' +
                '<div class="tx-body">' +
                '<div class="tx-row"><span class="tx-lbl">Jenis:</span><span>' + refType + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">No. Dokumen:</span><span>' + refNo + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Gudang:</span><span>' + wh + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Keterangan:</span><span>' + remark + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Masuk:</span><span class="tx-in">' + qtyIn + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Keluar:</span><span class="tx-out">' + qtyOut + '</span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Saldo:</span><span class="tx-bal"><b>' + fmtQty(r.balance) + '</b></span></div>' +
                '<div class="tx-row"><span class="tx-lbl">Biaya Satuan:</span><span>' + cost + '</span></div>' +
                '</div></div>';
        });
    }
    
    box.innerHTML = html;
    try { localStorage.setItem(LS_LAST_RESULT, JSON.stringify(data)); } catch (e) {}
}

// ============================================================
// 4. Load Stock Card
// ============================================================
function loadCard() {
    var wid = parseInt($('fWh').value, 10) || 0;
    var itemId = parseInt($('fItem').value, 10) || 0;
    var dateFrom = $('fFrom').value;
    var dateTo = $('fTo').value;
    
    if (!wid) { showMsg('Pilih gudang terlebih dahulu.', 'err'); return; }
    if (!itemId) { showMsg('Pilih material terlebih dahulu.', 'err'); return; }
    if (!dateFrom || !dateTo) { showMsg('Periode tanggal wajib diisi.', 'err'); return; }
    if (dateFrom > dateTo) { showMsg('Tanggal awal tidak boleh melebihi tanggal akhir.', 'err'); return; }
    
    $('btnLoad').disabled = true;
    $('btnLoad').textContent = 'Memuat...';
    hideMsg();
    
    api({
        action: 'card',
        item_id: itemId,
        warehouse_id: wid,
        date_from: dateFrom,
        date_to: dateTo
    }).then(function (res) {
        renderResult(res);
    }, function (e) {
        if (e.offline) {
            var cached = null;
            try { cached = JSON.parse(localStorage.getItem(LS_LAST_RESULT) || 'null'); } catch (err) {}
            if (cached) {
                showMsg('Offline - menampilkan data cache terakhir.', 'ok');
                renderResult(cached);
            } else {
                showMsg('Tidak ada koneksi & tidak ada data cache.', 'err');
            }
        } else if (e.auth) {
            showMsg('Sesi berakhir. Buka ulang halaman & login.', 'err');
        } else {
            showMsg((e && e.message) || 'Gagal memuat data.', 'err');
        }
    }).then(function () {
        $('btnLoad').disabled = false;
        $('btnLoad').textContent = 'Tampilkan';
    });
}

// ============================================================
// 5. Init - [FIX] Clear cache saat init
// ============================================================
function init() {
    console.log('[StockCard.m] === INIT STARTED ===');
    console.log('[StockCard.m] APP_BASE_URL:', window.APP_BASE_URL);
    console.log('[StockCard.m] CRD URL:', CRD);
    
    // [FIX] Clear semua cache lama saat init
    try { 
        localStorage.removeItem('scm_warehouses_cache'); 
        localStorage.removeItem('scm_items_cache');
    } catch (e) {}
    
    updateNet();
    window.addEventListener('online', function () { updateNet(); });
    window.addEventListener('offline', updateNet);
    
    // Set default tanggal
    setVal('fFrom', monthStartStr());
    setVal('fTo', todayStr());
    
    // Load gudang - [FIX] selalu dari server
    var fWh = $('fWh');
    if (fWh) {
        loadWarehouses().then(function () {
            fWh.addEventListener('change', function () {
                var wid = parseInt(this.value, 10) || 0;
                var fItem = $('fItem');
                if (fItem) fItem.value = '';
                items = [];
                renderItems();
                if (wid > 0) loadItems(wid);
            });
        });
    } else {
        console.error('[StockCard.m] Dropdown gudang (#fWh) TIDAK DITEMUKAN di DOM!');
        showMsg('Error: Dropdown gudang tidak ditemukan. Refresh halaman.', 'err');
    }
    
    var btnLoad = $('btnLoad');
    if (btnLoad) {
        btnLoad.addEventListener('click', loadCard);
    } else {
        console.error('[StockCard.m] Tombol Tampilkan (#btnLoad) TIDAK DITEMUKAN!');
    }
    
    // Coba restore hasil terakhir dari cache
    var cached = null;
    try { cached = JSON.parse(localStorage.getItem(LS_LAST_RESULT) || 'null'); } catch (e) {}
    if (cached) renderResult(cached);
    
    // Service Worker - [FIX] Nonaktifkan sementara untuk hindari error MIME
    /*
    if ('serviceWorker' in navigator && window.APP_BASE_URL) {
        navigator.serviceWorker.register(window.APP_BASE_URL + 'sw.stockcard.js', {
            scope: window.APP_BASE_URL
        }).catch(function (error) {
            console.error('[StockCard.m] SW registration gagal:', error);
        });
    }
    */
    
    console.log('[StockCard.m] === INIT COMPLETED ===');
}

// Jalankan saat DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
} else {
    init();
}
})();