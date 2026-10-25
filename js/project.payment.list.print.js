(function () {
    'use strict';
<<<<<<< Updated upstream
    var endpoint = window.PAYMENT_LIST_CRD_URL || '/rab-project/dev/php-crud/project.paymentCRD.php';
=======
    var endpoint = window.PAYMENT_LIST_CRD_URL || '/rab-project/live/php-crud/project.paymentCRD.php';
>>>>>>> Stashed changes
    var status = document.getElementById('listStatus');
    var table = document.getElementById('paymentTable');
    var rowsEl = document.getElementById('paymentRows');

    function esc(v) {
        return String(v === null || v === undefined ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(v) { var n = parseFloat(v); return isFinite(n) ? n : 0; }
    function money(v) {
        return num(v).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function dateId(v) {
        if (!v) return '-';
        var s = String(v).substring(0, 10), p = s.split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : esc(v);
    }
    function methodLabel(v) {
        var labels = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
        return labels[String(v || '').toUpperCase()] || v || '-';
    }
    function loadPage(start, all, total) {
        var xhr = new XMLHttpRequest();
        var body = 'task=list_payments&start=' + encodeURIComponent(start) + '&limit=250';
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var res;
            try { res = JSON.parse(xhr.responseText); }
            catch (e) { fail('Respons server tidak valid.'); return; }
            if (xhr.status < 200 || xhr.status >= 300 || !res.success || !Array.isArray(res.data)) {
                fail((res && res.message) || 'Gagal memuat daftar pembayaran.');
                return;
            }
            Array.prototype.push.apply(all, res.data);
            total = parseInt(res.totalCount, 10) || all.length;
            if (all.length < total && res.data.length) {
                loadPage(all.length, all, total);
                return;
            }
            render(all);
        };
        xhr.onerror = function () { fail('Tidak dapat terhubung ke server.'); };
        xhr.send(body);
    }
    function fail(message) {
        status.textContent = message;
        status.style.color = '#b42318';
    }
    function render(data) {
        if (!data.length) {
            status.textContent = 'Belum ada data pembayaran.';
            return;
        }
        var totalIdr = 0;
        rowsEl.innerHTML = data.map(function (r) {
            totalIdr += num(r.total_amount);
            return '<tr><td>' + esc(r.payment_no || '-') + '</td><td>' + dateId(r.payment_date) +
                '</td><td>' + esc(r.client_name || '-') + '</td><td>' + esc(r.project_names || '-') +
                '</td><td>' + esc(methodLabel(r.payment_method)) + '</td><td>' + esc(r.currency_code || 'IDR') +
                '</td><td class="num">' + money(r.total_amount_cur !== undefined ? r.total_amount_cur : r.total_amount) +
                '</td><td class="num">' + money(r.allocated_amount) + '</td><td class="num">' + money(r.excess_amount) +
                '</td><td>' + esc(r.reference_no || '-') + '</td></tr>';
        }).join('');
        document.getElementById('grandTotal').textContent = money(totalIdr);
        status.hidden = true;
        table.hidden = false;
    }
    document.getElementById('btnPrintList').addEventListener('click', function () { window.print(); });
    document.getElementById('btnCloseList').addEventListener('click', function () { window.close(); });
    loadPage(0, [], 0);
})();