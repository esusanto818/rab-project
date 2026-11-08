/*
Document      : stock.card.m.js
Description   : Kartu Stok Mobile (Vanilla JS + PWA). 
                Tampilan dioptimalkan untuk layar HP (card-based layout), 
                filter gudang, item, dan periode tanggal.
Backend       : /rab-project/dev/php-crud/stock.cardCRD.php
*/
(function () {
    'use strict';

    var CRD = (window.APP_BASE_URL || '') + 'php-crud/stock.cardCRD.php';
    
    // State
    var warehouses = [];
    var items = [];
    var state = {
        warehouse_id: 0,
        item_id: 0,
        date_from: '',
        date_to: ''
    };
    var cardData = null;
    var isLoading = false;

    // Helper
    function $(id) { return document.getElementById(id); }
    function esc(v) { var d = document.createElement('div'); d.textContent = v == null ? '' : v; return d.innerHTML; }
    function fmtQty(v) { return Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 4 }); }
    function fmtAmt(v) { return Number(v || 0).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }
    function fmtDate(s) {
        if (!s) return '-';
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s));
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : s;
    }
    function todayStr() {
        var d = new Date(), m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function firstDayOfMonth() {
        var d = new Date();
        return d.getFullYear() + '-' + (d.getMonth() + 1 < 10 ? '0' : '') + (d.getMonth() + 1) + '-01';
    }

    function showMsg(t, type) {
        var m = $('msg'); 
        if (!m) return;
        m.textContent = t; 
        m.className = 'msg ' + (type || '');
        if (type === 'ok') setTimeout(function () { m.className = 'msg hidden'; }, 4000);
    }
    function hideMsg() { var m = $('msg'); if (m) m.className = 'msg hidden'; }

    // API Helper
    function api(params) {
        var url = CRD + '?' + Object.keys(params).map(function (k) { 
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); 
        }).join('&');
        return fetch(url, { credentials: 'same-origin' }).then(function (r) {
            if (r.status === 401) return Promise.reject({ auth: true });
            return r.json().catch(function () { return Promise.reject({ message: 'Respons server tidak valid.' }); });
        }, function () { return Promise.reject({ offline: true }); });
    }

    // ============================================================
    // 1. UI Rendering
    // ============================================================
    function injectStyles() {
        if ($('scm-style')) return;
        var style = document.createElement('style');
        style.id = 'scm-style';
        style.textContent = `
            body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #020b14; color: #e2e8f0; margin: 0; padding: 0; }
            .app-header { background: #066b78; padding: 16px; text-align: center; font-size: 18px; font-weight: bold; color: #fff; position: sticky; top: 0; z-index: 10; box-shadow: 0 2px 8px rgba(0,0,0,0.3); }
            .container { padding: 12px; max-width: 600px; margin: 0 auto; }
            .filter-card { background: #0d2137; border: 1px solid #1e3a52; border-radius: 8px; padding: 12px; margin-bottom: 16px; }
            .form-group { margin-bottom: 12px; }
            .form-group label { display: block; font-size: 13px; color: #94a3b8; margin-bottom: 4px; font-weight: 600; }
            .form-control { width: 100%;