/*
Document      : stock.card.m.js
Description   : Stock Card Mobile (read-only monitoring stok per gudang/item/periode)
                Vanilla JS + PWA (tanpa ExtJS)
Backend       : ../php-crud/stock.cardCRD.php
Service Worker: sw.stockcard.js  (taruh di root aplikasi, sejajar views/ dan php-crud/)
View          : ../views/stock.card.m.php

[REVISI 2026-11-10]
1. Perbaikan "list gudang kosong di HP":
   - Base URL tidak lagi percaya mentah pada window.APP_BASE_URL. Kalau origin-nya beda dengan
     halaman yang sedang dibuka (mis. 'localhost' / IP lain), dipakai URL relatif terhadap halaman.
   - fetch pakai cache:'no-store' + parameter anti-cache.
   - Pesan error tidak lagi selalu "Offline"; ada panel "Diagnostik koneksi" yang bisa dibuka di HP.
2. Mode offline untuk PIC proyek:
   - Tombol "Sinkron" mengunduh snapshot gudang (material + saldo awal + mutasi N hari) ke IndexedDB.
   - Tanpa sinyal / timeout, kartu stok dihitung lokal dari snapshot.
   - Service Worker menyimpan halaman + JS/CSS supaya aplikasi bisa dibuka tanpa sinyal (butuh HTTPS).
   - Data server selalu diutamakan saat online; data offline hanya fallback dan diberi label.
*/
(function () {
'use strict';

var CRD_FILE = 'php-crud/stock.cardCRD.php';
var SW_FILE = 'sw.stockcard.js';
var SHELL_VER = '1';

var LS_LAST_RESULT = 'scm_last_result_cache';
var DB_NAME = 'stockCardMobile';
var DB_VERSION = 1;
var STORE_NAME = 'cache';

var warehouses = [];
var items = [];
var db = null;
var syncing = false;
var lastDiag = { url: '-', status: '-', body: '' };

// ============================================================
// 0. Helpers
// ============================================================
function noop() {}
function $(id) { return document.getElementById(id); }
function setVal(id, val) { var el = $(id); if (el) el.value = val; }
function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
function z2(n) { return n < 10 ? '0' + n : '' + n; }
function ymd(d) { return d.getFullYear() + '-' + z2(d.getMonth() + 1) + '-' + z2(d.getDate()); }
function todayStr() { return ymd(new Date()); }
function monthStartStr() { var d = new Date(); return d.getFullYear() + '-' + z2(d.getMonth() + 1) + '-01'; }
function daysAgoStr(n) { var d = new Date(); d.setDate(d.getDate() - n); return ymd(d); }
function r4(x) { return Math.round(x * 10000) / 10000; }

function fmtQty(v) {
    if (v === null || v === undefined || v === '') return '-';
    return Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 });
}
function fmtShort(v) { return Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 }); }
function fmtAmt(v) {
    if (v === null || v === undefined || v === '') return '-';
    return 'Rp ' + Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function fmtDate(s) {
    if (!s) return '-';
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s));
    return m ? (m[3] + '/' + m[2] + '/' + m[1]) : s;
}
function fmtStamp(s) {
    var d = new Date(s);
    if (isNaN(d.getTime())) return '-';
    return z2(d.getDate()) + '/' + z2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + z2(d.getHours()) + ':' + z2(d.getMinutes());
}
function ageHours(s) { var d = new Date(s); return isNaN(d.getTime()) ? 9999 : (Date.now() - d.getTime()) / 36e5; }
function ageText(s) {
    var h = ageHours(s);
    if (h < 1) return 'baru saja';
    if (h < 48) return Math.floor(h) + ' jam lalu';
    return Math.floor(h / 24) + ' hari lalu';
}

// ============================================================
// 1. Base URL  [FIX: jangan percaya APP_BASE_URL yang beda origin]
// ============================================================
function resolveBase() {
    var b = window.APP_BASE_URL || '';
    if (b) {
        try {
            var u = new URL(b, location.href);
            if (u.origin !== location.origin) {
                // mis. APP_BASE_URL = http://localhost/app/ tapi halaman dibuka via 127.0.0.1 atau IP LAN:
                // pakai PATH-nya saja, host mengikuti halaman yang sedang dibuka
                console.warn('[StockCard.m] APP_BASE_URL (' + b + ') beda origin dengan halaman (' + location.origin + '); pakai path-nya saja.');
            }
            return location.origin + u.pathname.replace(/\/?$/, '/');
        } catch (e) {}
    }
    // halaman ada di <root>/views/stock.card.m.php  ->  root = ../
    return new URL('../', location.href).href;
}
var APP_BASE = resolveBase();
// Override eksplisit bila perlu: di view tulis  window.STOCKCARD_CRD_URL = '/rab-project/xxx/php-crud/stock.cardCRD.php';
var CRD = window.STOCKCARD_CRD_URL
    ? new URL(window.STOCKCARD_CRD_URL, location.href).href
    : APP_BASE + CRD_FILE;
console.info('[StockCard.m] Backend:', CRD);

// ============================================================
// 2. IndexedDB
// ============================================================
function openDb() {
    if (db) return Promise.resolve(db);
    return new Promise(function (res, rej) {
        if (!window.indexedDB) { rej(new Error('IndexedDB tidak tersedia')); return; }
        var rq = indexedDB.open(DB_NAME, DB_VERSION);
        rq.onupgradeneeded = function () {
            if (!rq.result.objectStoreNames.contains(STORE_NAME)) rq.result.createObjectStore(STORE_NAME, { keyPath: 'key' });
        };
        rq.onsuccess = function () { db = rq.result; res(db); };
        rq.onerror = function () { rej(rq.error); };
    });
}
function cacheGet(key) {
    return openDb().then(function (d) {
        return new Promise(function (res) {
            var rq = d.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME).get(key);
            rq.onsuccess = function () { res(rq.result ? rq.result.value : null); };
            rq.onerror = function () { res(null); };
        });
    }).catch(function () { return null; });
}
// hapus semua key berawalan prefixes, lalu tulis records - satu transaksi (atomik)
function cacheReplace(prefixes, records) {
    return openDb().then(function (d) {
        return new Promise(function (res, rej) {
            var tx = d.transaction(STORE_NAME, 'readwrite'), os = tx.objectStore(STORE_NAME);
            prefixes.forEach(function (p) { os.delete(IDBKeyRange.bound(p, p + '\uffff')); });
            records.forEach(function (r) { os.put({ key: r.key, value: r.value, ts: Date.now() }); });
            tx.oncomplete = function () { res(); };
            tx.onerror = tx.onabort = function () { rej(tx.error || new Error('Gagal menyimpan ke penyimpanan HP')); };
        });
    });
}
function cachePut(key, value) { return cacheReplace([], [{ key: key, value: value }]); }
function cacheList(prefix) {
    return openDb().then(function (d) {
        return new Promise(function (res) {
            var out = [], rq = d.transaction(STORE_NAME, 'readonly').objectStore(STORE_NAME)
                .openCursor(IDBKeyRange.bound(prefix, prefix + '\uffff'));
            rq.onsuccess = function () {
                var c = rq.result;
                if (c) { out.push(c.value.value); c.continue(); } else res(out);
            };
            rq.onerror = function () { res(out); };
        });
    }).catch(function () { return []; });
}

// ============================================================
// 3. API
// ============================================================
function isNetFail(e) { return !!(e && (e.offline || (e.http && e.http >= 500))); }

function apiOnce(params, opt) {
    opt = opt || {};
    var p = {}, k;
    for (k in params) { if (params.hasOwnProperty(k)) p[k] = params[k]; }
    p._ = Date.now(); // anti-cache
    var url = CRD + '?' + Object.keys(p).map(function (key) {
        return encodeURIComponent(key) + '=' + encodeURIComponent(p[key]);
    }).join('&');
    lastDiag = { url: url, status: '(menunggu respons)', body: '' };

    if (navigator.onLine === false) {
        lastDiag.status = 'perangkat offline';
        renderDiag();
        return Promise.reject({ offline: true, message: 'Tidak ada koneksi internet.' });
    }

    var ctl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
    var timedOut = false;
    var timer = setTimeout(function () { timedOut = true; if (ctl) ctl.abort(); }, opt.timeout || 20000);

    return fetch(url, { credentials: 'same-origin', cache: 'no-store', signal: ctl ? ctl.signal : undefined })
        .then(function (r) {
            lastDiag.status = r.status + ' ' + r.statusText;
            return r.text().then(function (txt) {
                clearTimeout(timer);
                lastDiag.body = txt.substring(0, 300);
                if (r.status === 401 || r.status === 403) {
                    return Promise.reject({ auth: true, message: 'Sesi berakhir. Buka ulang halaman & login.' });
                }
                var json = null;
                try { json = JSON.parse(txt); } catch (e) {}
                if (json && typeof json === 'object') { crdConfirmed = true; return json; }
                if (!r.ok) return Promise.reject({ http: r.status, message: 'Server error (' + r.status + ').' });
                return Promise.reject({ notJson: true,
                    message: 'Respons server bukan JSON (sesi login habis atau URL backend salah). Lihat "Diagnostik koneksi".' });
            });
        })
        .catch(function (e) {
            clearTimeout(timer);
            if (e && (e.auth || e.http || e.notJson)) return Promise.reject(e);
            lastDiag.status = timedOut ? 'timeout' : 'gagal terhubung (' + ((e && e.message) || e) + ')';
            return Promise.reject({ offline: true, timeout: timedOut,
                message: timedOut ? 'Server tidak merespons (sinyal lemah).' :
                    'Tidak bisa terhubung ke server. Lihat "Diagnostik koneksi".' });
        })
        .then(function (r) { renderDiag(); return r; }, function (e) { renderDiag(); return Promise.reject(e); });
}

// Auto-temukan folder php-crud bila 404: coba naik satu folder demi satu dari lokasi halaman
// (mis. /rab-project/dev/views/ -> /rab-project/dev/ -> /rab-project/ -> /). Tidak aktif bila
// window.STOCKCARD_CRD_URL diisi eksplisit atau backend sudah pernah menjawab JSON.
var crdConfirmed = !!window.STOCKCARD_CRD_URL;
var crdTried = {};
crdTried[CRD] = true;
function nextCrdCandidate() {
    var parts = location.pathname.replace(/[^\/]*$/, '').split('/').filter(Boolean);
    while (true) {
        var cand = location.origin + '/' + parts.join('/') + (parts.length ? '/' : '') + CRD_FILE;
        if (!crdTried[cand]) { crdTried[cand] = true; return cand; }
        if (!parts.length) return null;
        parts.pop();
    }
}
function api(params, opt) {
    return apiOnce(params, opt).catch(function (e) {
        if (!crdConfirmed && e && (e.http === 404 || e.notJson)) {
            var next = nextCrdCandidate();
            if (next) { console.info('[StockCard.m] Coba backend:', next); CRD = next; return api(params, opt); }
        }
        return Promise.reject(e);
    });
}

function showMsg(t, type) {
    var m = $('msg');
    if (!m) { console.log('[' + type + ']', t); return; }
    m.textContent = t;
    m.className = 'msg ' + (type || '');
    if (type === 'ok') setTimeout(function () { m.className = 'msg hidden'; }, 5000);
}
function hideMsg() { var m = $('msg'); if (m) m.className = 'msg hidden'; }

function updateNet() {
    var p = $('pillNet');
    if (p) { p.textContent = navigator.onLine ? 'online' : 'offline'; p.className = 'pill' + (navigator.onLine ? '' : ' off'); }
}

function renderDiag() {
    var el = $('diagText');
    if (!el) return;
    el.textContent =
        'Halaman      : ' + location.href + '\n' +
        'Backend      : ' + CRD + '\n' +
        'APP_BASE_URL : ' + (window.APP_BASE_URL || '(kosong)') + '\n' +
        'HTTPS/aman   : ' + (window.isSecureContext ? 'ya' : 'TIDAK (offline app tidak bisa aktif)') + '\n' +
        'navigator.onLine : ' + navigator.onLine + '\n' +
        'Request akhir: ' + lastDiag.url + '\n' +
        'Status       : ' + lastDiag.status + '\n' +
        'Respons      : ' + (lastDiag.body || '-');
}

// ============================================================
// 4. Gudang & Material
// ============================================================
function offlineWarehouses() {
    return cacheList('snapmeta_').then(function (metas) {
        return metas.map(function (m) { return { id: m.wid, name: m.warehouse_name }; })
            .sort(function (a, b) { return a.name < b.name ? -1 : 1; });
    });
}

function loadWarehouses() {
    return api({ action: 'lookup_warehouses' }, { timeout: 15000 }).then(function (res) {
        if (!res || !res.success) {
            showMsg((res && res.message) || 'Gagal memuat gudang.', 'err');
            warehouses = [];
            renderWarehouses();
            return;
        }
        // "Semua Gudang" (id 0) tidak dipakai di mobile: gudang wajib dipilih
        warehouses = (res.data || []).filter(function (w) { return parseInt(w.id, 10) > 0; });
        renderWarehouses();
    }, function (e) {
        if (isNetFail(e)) {
            return offlineWarehouses().then(function (list) {
                warehouses = list;
                renderWarehouses();
                if (list.length) showMsg('Tidak terhubung ke server. Menampilkan gudang yang sudah disinkronkan untuk offline.', 'ok');
                else showMsg(e.message + ' Belum ada data offline - sinkronkan dulu saat ada sinyal.', 'err');
            });
        }
        warehouses = [];
        renderWarehouses();
        showMsg(e.message || 'Gagal memuat gudang.', 'err');
    });
}

function filterLocal(list, q) {
    q = (q || '').toLowerCase();
    if (!q) return list;
    return list.filter(function (it) {
        return String(it.material_name || '').toLowerCase().indexOf(q) >= 0 ||
               String(it.material_code || '').toLowerCase().indexOf(q) >= 0;
    });
}

function itemsFromSnapshot(wid, q, netErr) {
    return cacheGet('snapmeta_' + wid).then(function (meta) {
        if (!meta) {
            if (!items.length) showMsg((netErr && netErr.message || 'Tidak ada koneksi.') + ' Gudang ini belum disinkronkan untuk offline.', 'err');
            return;
        }
        items = filterLocal(meta.items || [], q);
        renderItems();
        showMsg('Offline: daftar material dari data tersimpan (' + fmtStamp(meta.generated_at) + ').', 'ok');
    });
}

function loadItems(wid, q) {
    q = q || '';
    if (!wid || wid <= 0) { items = []; renderItems(); return Promise.resolve(); }
    var key = 'items_wh_' + wid;
    var pre = q === '' ? cacheGet(key) : Promise.resolve(null);
    return pre.then(function (cached) {
        if (cached && cached.length) { items = cached; renderItems(); }
        return api({ action: 'lookup_items', warehouse_id: wid, query: q }, { timeout: 15000 });
    }).then(function (res) {
        if (!res || !res.success) { showMsg((res && res.message) || 'Gagal memuat material.', 'err'); return; }
        items = res.data || [];
        if (q === '') cachePut(key, items).catch(noop);
        renderItems();
    }, function (e) {
        if (isNetFail(e)) return itemsFromSnapshot(wid, q, e);
        showMsg(e.message || 'Gagal memuat material.', 'err');
    });
}

// ============================================================
// 5. Render
// ============================================================
function renderWarehouses() {
    var sel = $('fWh');
    if (!sel) return;
    var cur = sel.value;
    var h = '<option value="0">' + (warehouses.length ? '-- Pilih Gudang --' : '-- Tidak ada gudang tersedia --') + '</option>';
    warehouses.forEach(function (w) { h += '<option value="' + esc(w.id) + '">' + esc(w.name) + '</option>'; });
    sel.innerHTML = h;
    if (cur && warehouses.some(function (w) { return String(w.id) === cur; })) sel.value = cur;
}

function renderItems() {
    var sel = $('fItem');
    if (!sel) return;
    var cur = sel.value;
    var h = '<option value="">-- Pilih Material' + (items.length ? ' (' + items.length + ')' : '') + ' --</option>';
    items.forEach(function (it) {
        var stock = Number(it.qty_on_hand) > 0 ? ' [Stok: ' + fmtShort(it.qty_on_hand) + ']' : '';
        h += '<option value="' + esc(it.item_id) + '">' +
             esc((it.material_code ? it.material_code + ' - ' : '') + it.material_name) + ' (' + esc(it.unit) + ')' + stock + '</option>';
    });
    sel.innerHTML = h;
    if (cur && items.some(function (it) { return String(it.item_id) === cur; })) sel.value = cur;
}

function renderResult(data, note) {
    var box = $('resultBox');
    if (!box) return;
    if (!data || !data.success) {
        box.innerHTML = '<div class="card err">' + esc((data && data.message) || 'Tidak ada data.') + '</div>';
        return;
    }
    var rows = data.data || [];
    var summary = data.summary || {};
    var html = '';

    if (note) {
        html += '<div class="card" style="border-left:4px solid #d9822b;font-size:13px;">📴 ' + esc(note) + '</div>';
    }

    html += '<div class="summary-grid">';
    html += '<div class="sum-card"><div class="sum-lbl">Saldo Awal</div><div class="sum-val">' + fmtQty(summary.opening) + '</div></div>';
    html += '<div class="sum-card in"><div class="sum-lbl">Total Masuk</div><div class="sum-val">' + fmtQty(summary.total_in) + '</div></div>';
    html += '<div class="sum-card out"><div class="sum-lbl">Total Keluar</div><div class="sum-val">' + fmtQty(summary.total_out) + '</div></div>';
    html += '<div class="sum-card end"><div class="sum-lbl">Saldo Akhir</div><div class="sum-val">' + fmtQty(summary.ending) + '</div></div>';
    html += '</div>';

    var movCount = rows.filter(function (r) { return r.row_type !== 'OPEN'; }).length;
    if (!movCount) html += '<div class="card">Tidak ada mutasi pada periode ini.</div>';
    html += '<div class="section-title">Rincian Mutasi (' + movCount + ' transaksi)</div>';

    rows.forEach(function (r) {
        var isOpen = r.row_type === 'OPEN';
        var isIn = !isOpen && r.qty_in !== null && r.qty_in !== undefined;
        html += '<div class="tx-card ' + (isOpen ? 'open' : (isIn ? 'in' : 'out')) + '">' +
            '<div class="tx-head"><span class="tx-date">' + fmtDate(r.movement_date) + '</span>' +
            '<span class="tx-type">' + (isOpen ? '📋 Saldo Awal' : (isIn ? '📥 Masuk' : '📤 Keluar')) + '</span></div>' +
            '<div class="tx-body">' +
            '<div class="tx-row"><span class="tx-lbl">Jenis:</span><span>' + (r.reference_type ? esc(r.reference_type) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">No. Dokumen:</span><span>' + (r.reference_no ? esc(r.reference_no) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Gudang:</span><span>' + (r.warehouse_name ? esc(r.warehouse_name) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Keterangan:</span><span>' + (r.remark ? esc(r.remark) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Masuk:</span><span class="tx-in">' + (r.qty_in !== null && r.qty_in !== undefined ? fmtQty(r.qty_in) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Keluar:</span><span class="tx-out">' + (r.qty_out !== null && r.qty_out !== undefined ? fmtQty(r.qty_out) : '-') + '</span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Saldo:</span><span class="tx-bal"><b>' + fmtQty(r.balance) + '</b></span></div>' +
            '<div class="tx-row"><span class="tx-lbl">Biaya Satuan:</span><span>' + (r.unit_cost !== null && r.unit_cost !== undefined ? fmtAmt(r.unit_cost) : '-') + '</span></div>' +
            '</div></div>';
    });

    box.innerHTML = html;
}

function saveLast(data) {
    try { localStorage.setItem(LS_LAST_RESULT, JSON.stringify({ ts: new Date().toISOString(), data: data })); } catch (e) {}
}

// ============================================================
// 6. Kartu Stok (online -> server, gagal/timeout -> hitung dari snapshot)
// ============================================================
function buildCard(it, mvs, from, to, whName) {
    var opening = Number(it.opening) || 0, i, m, d, q, isIn;
    for (i = 0; i < mvs.length; i++) {
        m = mvs[i]; d = String(m.movement_date).substr(0, 10);
        if (d >= from) break;
        q = Number(m.qty) || 0;
        opening = r4(opening + (m.movement_type === 'IN' ? q : -q));
    }
    var bal = opening, totIn = 0, totOut = 0;
    var data = [{ row_type: 'OPEN', movement_date: from, reference_type: '', reference_no: '', warehouse_name: '',
                  remark: 'Saldo Awal', qty_in: null, qty_out: null, unit_cost: null, balance: opening }];
    for (; i < mvs.length; i++) {
        m = mvs[i]; d = String(m.movement_date).substr(0, 10);
        if (d > to) break;
        q = Number(m.qty) || 0;
        isIn = m.movement_type === 'IN';
        bal = r4(bal + (isIn ? q : -q));
        if (isIn) totIn += q; else totOut += q;
        data.push({ row_type: 'MOV', movement_date: m.movement_date, reference_type: m.reference_type,
                    reference_no: m.reference_no, warehouse_name: whName, remark: m.remark,
                    qty_in: isIn ? q : null, qty_out: isIn ? null : q, unit_cost: Number(m.unit_cost) || 0, balance: bal });
    }
    return { success: true, totalCount: data.length, data: data,
             summary: { opening: opening, total_in: r4(totIn), total_out: r4(totOut), ending: bal } };
}

function offlineCard(wid, itemId, from, to, netErr) {
    return cacheGet('snapmeta_' + wid).then(function (meta) {
        var why = (netErr && netErr.message) || 'Tidak ada koneksi.';
        if (!meta) {
            showMsg(why + ' Data offline gudang ini belum disinkronkan. Tekan "Sinkron" saat ada sinyal.', 'err');
            return;
        }
        if (from < meta.date_from) {
            showMsg('Data offline gudang ini hanya mulai ' + fmtDate(meta.date_from) +
                    '. Ubah tanggal "Dari" atau sinkron ulang dengan rentang lebih panjang.', 'err');
            return;
        }
        var it = null;
        (meta.items || []).forEach(function (x) { if (parseInt(x.item_id, 10) === itemId) it = x; });
        if (!it) { showMsg('Material ini tidak ada di data offline gudang tersebut.', 'err'); return; }
        return cacheGet('snapitem_' + wid + '_' + itemId).then(function (mvs) {
            var res = buildCard(it, mvs || [], from, to, meta.warehouse_name);
            renderResult(res, 'DATA OFFLINE - disinkronkan ' + fmtStamp(meta.generated_at) + ' (' + ageText(meta.generated_at) +
                '). Transaksi setelah waktu itu belum termasuk.' + (ageHours(meta.generated_at) > 24 ? ' Data sudah lebih dari 1 hari.' : ''));
        });
    });
}

function loadCard() {
    var wid = parseInt($('fWh').value, 10) || 0;
    var itemId = parseInt($('fItem').value, 10) || 0;
    var dateFrom = $('fFrom').value;
    var dateTo = $('fTo').value;

    if (!wid) { showMsg('Pilih gudang terlebih dahulu.', 'err'); return; }
    if (!itemId) { showMsg('Pilih material terlebih dahulu.', 'err'); return; }
    if (!dateFrom || !dateTo) { showMsg('Periode tanggal wajib diisi.', 'err'); return; }
    if (dateFrom > dateTo) { showMsg('Tanggal awal tidak boleh melebihi tanggal akhir.', 'err'); return; }

    var btn = $('btnLoad');
    btn.disabled = true; btn.textContent = 'Memuat...';
    hideMsg();

    api({ action: 'card', item_id: itemId, warehouse_id: wid, date_from: dateFrom, date_to: dateTo }, { timeout: 20000 })
        .then(function (res) {
            renderResult(res, null);
            if (res && res.success) saveLast(res);
        }, function (e) {
            if (isNetFail(e)) return offlineCard(wid, itemId, dateFrom, dateTo, e);
            showMsg((e && e.message) || 'Gagal memuat data.', 'err');
        })
        .then(function () { btn.disabled = false; btn.textContent = 'Tampilkan'; });
}

// ============================================================
// 7. Sinkron offline
// ============================================================
function syncOne(w, days) {
    return api({ action: 'snapshot', warehouse_id: w.id, date_from: daysAgoStr(days) }, { timeout: 120000 }).then(function (res) {
        if (!res || !res.success) return Promise.reject({ message: (res && res.message) || 'Gagal sinkron.' });
        var byItem = {};
        (res.movements || []).forEach(function (m) {
            (byItem[m.item_id] || (byItem[m.item_id] = [])).push({
                id: m.id, movement_date: m.movement_date, movement_type: m.movement_type, qty: m.qty, unit_cost: m.unit_cost,
                reference_type: m.reference_type, reference_no: m.reference_no, remark: m.remark });
        });
        var recs = [{ key: 'snapmeta_' + w.id, value: {
            wid: parseInt(w.id, 10), warehouse_name: res.warehouse_name, date_from: res.date_from,
            generated_at: res.generated_at, items: res.items || [], rows: (res.movements || []).length } }];
        (res.items || []).forEach(function (it) {
            recs.push({ key: 'snapitem_' + w.id + '_' + it.item_id, value: byItem[it.item_id] || [] });
        });
        return cacheReplace(['snapitem_' + w.id + '_'], recs);
    });
}

function setProgress(t) { var el = $('syncProgress'); if (el) el.textContent = t || ''; }
function setSyncButtons(disabled) {
    ['btnSyncOne', 'btnSyncAll', 'btnClearOffline'].forEach(function (id) {
        var b = $(id); if (b) { b.disabled = disabled; b.style.opacity = disabled ? '.5' : '1'; }
    });
}

function runSync(list) {
    if (syncing) return;
    if (!list.length) { showMsg('Tidak ada gudang untuk disinkronkan.', 'err'); return; }
    if (!navigator.onLine) { showMsg('Sinkron butuh koneksi internet.', 'err'); return; }
    syncing = true; setSyncButtons(true);
    try { if (navigator.storage && navigator.storage.persist) navigator.storage.persist(); } catch (e) {}
    var days = parseInt($('fSyncDays').value, 10) || 90, ok = 0, fail = [];
    var chain = Promise.resolve();
    list.forEach(function (w, i) {
        chain = chain.then(function () {
            setProgress('Sinkron ' + (i + 1) + '/' + list.length + ': ' + w.name + ' ...');
            return syncOne(w, days).then(function () { ok++; }, function (e) {
                var why = (e && e.name === 'QuotaExceededError') ? 'penyimpanan HP penuh' : ((e && e.message) || 'gagal');
                fail.push(w.name + ' (' + why + ')');
            });
        });
    });
    chain.then(function () {
        syncing = false; setSyncButtons(false);
        setProgress('');
        refreshSyncList();
        if (fail.length) showMsg('Sinkron ' + ok + ' gudang berhasil. Gagal: ' + fail.join('; '), 'err');
        else showMsg('Sinkron selesai: ' + ok + ' gudang siap dipakai offline.', 'ok');
        // sesudah sinkron, segarkan daftar material gudang yang sedang dipilih
        var wid = parseInt(($('fWh') || {}).value, 10) || 0;
        if (wid) loadItems(wid, '');
    });
}

function refreshSyncList() {
    var el = $('syncList');
    if (!el) return;
    cacheList('snapmeta_').then(function (metas) {
        if (!metas.length) { el.innerHTML = '<i>Belum ada data offline. Sinkronkan saat ada sinyal.</i>'; return; }
        metas.sort(function (a, b) { return a.warehouse_name < b.warehouse_name ? -1 : 1; });
        el.innerHTML = metas.map(function (m) {
            var stale = ageHours(m.generated_at) > 24;
            return '• <b>' + esc(m.warehouse_name) + '</b>: ' + (m.items || []).length + ' material, ' + m.rows + ' mutasi sejak ' +
                   fmtDate(m.date_from) + '<br/>&nbsp;&nbsp;disinkronkan ' + fmtStamp(m.generated_at) + ' (' + ageText(m.generated_at) + ')' +
                   (stale ? ' <span style="color:#d9822b;">⚠ usang</span>' : '');
        }).join('<br/>');
    });
}

function clearOffline() {
    if (!confirm('Hapus semua data offline di HP ini? Anda harus sinkron lagi untuk memakai mode offline.')) return;
    cacheReplace(['snapmeta_', 'snapitem_', 'items_wh_', 'wh_list'], []).then(function () {
        try { localStorage.removeItem(LS_LAST_RESULT); } catch (e) {}
        var box = $('resultBox'); if (box) box.innerHTML = '';
        refreshSyncList();
        showMsg('Data offline dihapus.', 'ok');
    }, function () { showMsg('Gagal menghapus data offline.', 'err'); });
}

// ============================================================
// 8. UI tambahan (dibuat lewat JS supaya views/stock.card.m.php tidak perlu diubah)
// ============================================================
var BTN = 'padding:9px 12px;border:0;border-radius:4px;background:#2e6da4;color:#fff;font-weight:bold;';

function buildSearchBox() {
    var sel = $('fItem');
    if (!sel || !sel.parentNode) return;
    var inp = document.createElement('input');
    inp.type = 'search'; inp.id = 'fItemQ'; inp.placeholder = 'Cari kode / nama material...';
    inp.autocomplete = 'off';
    inp.style.cssText = 'width:100%;box-sizing:border-box;padding:9px;margin-bottom:6px;';
    sel.parentNode.insertBefore(inp, sel);
    var t = null;
    inp.addEventListener('input', function () {
        clearTimeout(t);
        t = setTimeout(function () {
            var wid = parseInt($('fWh').value, 10) || 0;
            if (wid) loadItems(wid, inp.value.trim());
        }, 350);
    });
}

function buildOfflinePanel() {
    var box = $('resultBox');
    if (!box || !box.parentNode) return;
    var p = document.createElement('div');
    p.id = 'offlinePanel'; p.className = 'card';
    p.innerHTML =
        '<div style="font-weight:bold;margin-bottom:6px;">📴 Mode Offline</div>' +
        '<div id="swStatus" style="font-size:12px;margin-bottom:8px;">Memeriksa...</div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center;">' +
        '<select id="fSyncDays" style="padding:9px;"><option value="30">30 hari</option><option value="90" selected>90 hari</option><option value="180">180 hari</option></select>' +
        '<button id="btnSyncOne" type="button" style="' + BTN + '">Sinkron gudang ini</button>' +
        '<button id="btnSyncAll" type="button" style="' + BTN + '">Sinkron semua</button>' +
        '</div>' +
        '<div id="syncProgress" style="font-size:12px;margin:6px 0;"></div>' +
        '<div id="syncList" style="font-size:12px;margin:6px 0;"></div>' +
        '<button id="btnClearOffline" type="button" style="' + BTN + 'background:#a33;">Hapus data offline</button>' +
        '<details style="margin-top:8px;font-size:11px;"><summary>Diagnostik koneksi</summary>' +
        '<pre id="diagText" style="white-space:pre-wrap;word-break:break-all;"></pre></details>';
    box.parentNode.insertBefore(p, box);

    $('btnSyncOne').addEventListener('click', function () {
        var wid = parseInt($('fWh').value, 10) || 0;
        var w = null;
        warehouses.forEach(function (x) { if (parseInt(x.id, 10) === wid) w = x; });
        if (!w) { showMsg('Pilih gudang dulu, atau pakai "Sinkron semua".', 'err'); return; }
        runSync([w]);
    });
    $('btnSyncAll').addEventListener('click', function () { runSync(warehouses.slice()); });
    $('btnClearOffline').addEventListener('click', clearOffline);
    refreshSyncList();
    renderDiag();
}

// ============================================================
// 9. Service Worker (aplikasi bisa dibuka tanpa sinyal)
// ============================================================
function setupServiceWorker() {
    var el = $('swStatus');
    function st(t, good) { if (el) { el.textContent = t; el.style.color = good ? '#2e9e4f' : '#d9822b'; } }

    if (!window.isSecureContext) {
        st('Halaman ini belum bisa dibuka tanpa sinyal karena diakses lewat ' + location.protocol + '//' + location.host +
           ' (butuh HTTPS). Data offline tetap bisa disinkronkan & dipakai selama halaman masih terbuka.', false);
        return;
    }
    if (!('serviceWorker' in navigator)) { st('Browser ini tidak mendukung Service Worker.', false); return; }

    navigator.serviceWorker.register(APP_BASE + SW_FILE, { scope: APP_BASE }).then(function () {
        return navigator.serviceWorker.ready;
    }).then(function (reg) {
        var done = false;
        try { done = localStorage.getItem('sc_shell_ok') === SHELL_VER; } catch (e) {}
        if (done || !navigator.onLine) { st('Aplikasi siap dibuka tanpa sinyal.', true); return; }

        var urls = [location.href];
        try {
            performance.getEntriesByType('resource').forEach(function (r) {
                try {
                    var u = new URL(r.name);
                    if (u.origin === location.origin && u.pathname.indexOf('/php-crud/') < 0 && urls.indexOf(r.name) < 0) urls.push(r.name);
                } catch (e) {}
            });
        } catch (e) {}
        st('Menyimpan halaman untuk mode offline...', false);
        var ch = new MessageChannel();
        ch.port1.onmessage = function (ev) {
            if (ev.data && ev.data.ok) {
                try { localStorage.setItem('sc_shell_ok', SHELL_VER); } catch (e) {}
                st('Aplikasi siap dibuka tanpa sinyal (' + ev.data.cached + ' file tersimpan).', true);
            } else st('Gagal menyimpan halaman untuk offline. Muat ulang saat online.', false);
        };
        reg.active.postMessage({ type: 'precache', urls: urls }, [ch.port2]);
    }).catch(function (e) {
        st('Service Worker gagal aktif: ' + ((e && e.message) || e) +
           ' - pastikan ' + APP_BASE + SW_FILE + ' bisa dibuka di browser dan bertipe JavaScript.', false);
    });
}

// ============================================================
// 10. Init
// ============================================================
function init() {
    buildSearchBox();
    buildOfflinePanel();
    updateNet();
    window.addEventListener('online', function () { updateNet(); loadWarehouses(); });
    window.addEventListener('offline', updateNet);

    setVal('fFrom', monthStartStr());
    setVal('fTo', todayStr());

    var fWh = $('fWh');
    if (fWh) {
        fWh.addEventListener('change', function () {
            var wid = parseInt(this.value, 10) || 0;
            setVal('fItemQ', '');
            setVal('fItem', '');
            items = [];
            renderItems();
            if (wid > 0) loadItems(wid, '');
        });
        loadWarehouses();
    } else {
        showMsg('Error: dropdown gudang (#fWh) tidak ditemukan. Refresh halaman.', 'err');
    }

    var btnLoad = $('btnLoad');
    if (btnLoad) btnLoad.addEventListener('click', loadCard);

    // hasil terakhir (dari penyimpanan perangkat)
    try {
        var c = JSON.parse(localStorage.getItem(LS_LAST_RESULT) || 'null');
        if (c && c.success !== undefined) c = { ts: null, data: c }; // format lama
        if (c && c.data) renderResult(c.data, 'Hasil terakhir' + (c.ts ? ' (' + fmtStamp(c.ts) + ')' : '') + ' dari penyimpanan perangkat. Tekan "Tampilkan" untuk data terbaru.');
    } catch (e) {}

    setupServiceWorker();
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
else init();
})();