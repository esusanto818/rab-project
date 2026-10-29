/*
 * project.payment.print.js
 * Memuat data tanda terima melalui project.paymentCRD.php (task=load_payment),
 * lalu menampilkan hasil analisis AI terbaru untuk setiap proyek terkait.
 * PHP cetak hanya berisi kerangka HTML/CSS.
 */
(function () {
    'use strict';

    var paymentId = parseInt(window.PAYMENT_ID, 10) || 0;
    var endpoint = window.PAYMENT_CRD_URL || '/rab-project/dev/php-crud/project.paymentCRD.php';
    var root = document.getElementById('receipt');

    function esc(value) {
        return String(value === null || value === undefined ? '' : value)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(value) {
        var n = parseFloat(value);
        return isFinite(n) ? n : 0;
    }
    function money(value) {
        return num(value).toLocaleString('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
    function dateId(value) {
        if (!value) return '-';
        var s = String(value).substring(0, 10);
        var p = s.split('-');
        return p.length === 3 ? p[2] + '/' + p[1] + '/' + p[0] : esc(value);
    }
    function methodLabel(value) {
        var map = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
        return map[String(value || '').toUpperCase()] || value || '-';
    }
    function request(params, callback) {
        var xhr = new XMLHttpRequest();
        var body = Object.keys(params).map(function (k) {
            return encodeURIComponent(k) + '=' + encodeURIComponent(params[k] === undefined ? '' : params[k]);
        }).join('&');
        xhr.open('POST', endpoint, true);
        xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onreadystatechange = function () {
            if (xhr.readyState !== 4) return;
            var response;
            try { response = JSON.parse(xhr.responseText); }
            catch (e) { callback(new Error('Respons server bukan JSON yang valid.')); return; }
            if (xhr.status < 200 || xhr.status >= 300) {
                callback(new Error(response.message || 'Permintaan ke server gagal.'));
                return;
            }
            callback(null, response);
        };
        xhr.onerror = function () { callback(new Error('Tidak dapat terhubung ke server.')); };
        xhr.send(body);
    }
    function fail(message) {
        root.innerHTML = '<div class="err">' + esc(message) + '</div>';
    }
    // Gaya cetak mengikuti hierarki panel AI pada layar detail pembayaran.
    (function injectAiPrintStyles() {
        var style = document.createElement('style');
        style.textContent =
            '.ai-project-heading{display:flex;justify-content:space-between;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:10px;color:#075e58;font-size:14px}' +
            '.ai-risk{font-weight:bold;border:1px solid currentColor;padding:3px 8px;border-radius:3px;white-space:nowrap}' +
            '.ai-summary{font-size:12px;line-height:1.55;margin-bottom:12px}' +
            '.ai-summary p{margin:5px 0 0;white-space:pre-wrap}' +
            '.ai-mitigations{font-size:12px;line-height:1.5}' +
            '.ai-recommendations{margin:6px 0 0 18px;padding:0}' +
            '.ai-recommendations li{margin-bottom:6px}' +
            '.ai-project{margin:12px 0;padding:10px 0;border-bottom:1px solid #d8e0e3}';
        document.head.appendChild(style);
    })();

    function renderAiPlaceholder(projects) {
        if (!projects.length) {
            return '<section class="ai-section"><h3>Hasil Analisis AI — Varian Anggaran & Risiko</h3>' +
                '<p>Tidak ditemukan proyek yang terkait dengan pembayaran ini.</p></section>';
        }
        return '<section class="ai-section" id="aiSection"><h3>Hasil Analisis AI — Varian Anggaran & Risiko Proyek</h3>' +
            '<p id="aiLoading">Memuat hasil analisis AI terbaru...</p><div id="aiResults"></div>' +
            '<p class="muted">Hasil AI merupakan informasi pendukung dan perlu ditinjau bersama data proyek aktual.</p></section>';
    }
    function renderReceipt(payload) {
        var h = payload.header || {};
        var items = payload.items || [];
        var currency = h.currency_code || 'IDR';
        var foreign = currency !== 'IDR';
        var rows = '';
        var projectMap = {};
        items.forEach(function (item) {
            projectMap[String(item.project_id)] = item.project_name || ('Proyek #' + item.project_id);
            rows += '<tr><td>' + esc(item.project_name) + '</td><td>' + esc(item.termin_name) +
                (item.percentage !== null && item.percentage !== undefined ? ' (' + money(item.percentage) + '%)' : '') +
                '</td><td>' + esc(item.inv_no) + '</td><td class="r-al">' + money(item.invoice_amount) +
                '</td><td class="r-al">' + money(item.amount_paid) + '</td>' +
                (foreign ? '<td class="r-al">' + money(item.amount_cur) + '</td>' : '') + '</tr>';
        });
        if (!items.length) {
            rows = '<tr><td colspan="' + (foreign ? 6 : 5) + '" style="text-align:center">Tidak ada rincian alokasi termin.</td></tr>';
        }
        var projectIds = Object.keys(projectMap);
        var totalRows = '<tr><td colspan="' + (foreign ? 4 : 3) + '" class="r-al"><b>Total Dialokasikan (IDR)</b></td>' +
            '<td class="r-al"><b>' + money(h.allocated_amount) + '</b></td>' +
            (foreign ? '<td></td>' : '') + '</tr>';
        if (num(h.excess_amount) > 0.01) {
            totalRows += '<tr><td colspan="' + (foreign ? 4 : 3) + '" class="r-al"><b>Lebih Bayar (IDR)</b></td>' +
                '<td class="r-al">' + money(h.excess_amount) + '</td>' + (foreign ? '<td></td>' : '') + '</tr>';
        }
        totalRows += '<tr><td colspan="' + (foreign ? 4 : 3) + '" class="r-al"><b>Total Diterima (' + esc(currency) + ')</b></td>' +
            '<td class="r-al"><b>' + money(h.total_amount_cur !== undefined ? h.total_amount_cur : h.total_amount) + '</b></td>' +
            (foreign ? '<td></td>' : '') + '</tr>';
        if (foreign) {
            totalRows += '<tr><td colspan="4" class="r-al"><b>Nilai setara IDR</b></td><td class="r-al">' + money(h.total_amount) + '</td><td></td></tr>';
        }

        root.innerHTML =
            '<header class="hdr"><div class="l"><div class="co-name">RAB-Monitor</div><div class="co-addr">Sistem Monitoring Proyek</div></div>' +
            '<div class="r"><b>TANDA TERIMA PEMBAYARAN</b><div>No. ' + esc(h.payment_no) + '</div></div></header>' +
            '<h1 class="title">TANDA TERIMA</h1>' +
            '<p>Telah diterima pembayaran dari:</p>' +
            '<table class="meta"><tr><td class="k">Nama Client</td><td><b>' + esc(h.client_name) + '</b></td>' +
            '<td class="k">Tanggal Pembayaran</td><td><b>' + dateId(h.payment_date) + '</b></td></tr>' +
            '<tr><td class="k">Metode Pembayaran</td><td><b>' + esc(methodLabel(h.payment_method)) + '</b></td>' +
            '<td class="k">Mata Uang</td><td><b>' + esc(currency) + (foreign ? ' (kurs ' + money(h.exch_rate) + ')' : '') + '</b></td></tr>' +
            '<tr><td class="k">Akun Penerimaan</td><td><b>' + esc([h.account_code, h.account_name].filter(Boolean).join(' - ') || '-') + '</b></td>' +
            '<td class="k">Referensi</td><td><b>' + esc(h.reference_no || '-') + '</b></td></tr></table>' +
            '<table class="items"><thead><tr><th>Proyek</th><th>Termin</th><th>No. Invoice</th><th class="r-al">Tagihan (IDR)</th><th class="r-al">Dibayar (IDR)</th>' +
            (foreign ? '<th class="r-al">Dibayar (' + esc(currency) + ')</th>' : '') +
            '</tr></thead><tbody>' + rows + '</tbody><tfoot>' + totalRows + '</tfoot></table>' +
            (h.notes ? '<p><b>Catatan:</b> ' + esc(h.notes) + '</p>' : '') +
            renderAiPlaceholder(projectIds) +
            '<div class="sign"><div><p>Diterima oleh,</p><div class="space"></div><div class="line">' + esc(h.client_name) + '</div></div>' +
            '<div><p>Diterima dari / Petugas,</p><div class="space"></div><div class="line">' + esc(h.created_by_name || 'Petugas Penerima') + '</div></div></div>' +
            '<div class="footer">Dokumen dihasilkan oleh RAB-Monitor · ' + esc(h.payment_no) + '</div>';

        loadAiResults(projectIds, projectMap);
    }
    function loadAiResults(projectIds, projectMap) {
        var loading = document.getElementById('aiLoading');
        var results = document.getElementById('aiResults');
        var printButton = document.getElementById('btnPrintReceipt');
        if (printButton) { printButton.disabled = true; printButton.textContent = 'Memuat hasil AI...'; }
        if (!projectIds.length) {
            if (loading) loading.textContent = 'Tidak ada proyek yang terhubung dengan pembayaran ini.';
            if (printButton) { printButton.disabled = false; printButton.textContent = 'Cetak'; }
            return;
        }

        var completed = 0;
        var htmlByProject = {};
        projectIds.forEach(function (projectId) {
            // Ambil hasil analisis tersimpan yang sama dengan yang ditampilkan di panel.
            // Jangan memanggil analyze_project_variance saat mencetak karena itu membuat
            // analisis baru dan hasil cetak bisa berbeda dari hasil yang sedang dilihat.
            request({ task: 'latest_project_variance', project_id: projectId }, function (err, res) {
                completed++;
                var projectName = projectMap[projectId];
                if (err || !res || !res.success || !res.data) {
                    var msg = (res && res.message) ? res.message :
                        (err ? err.message : 'Hasil analisis AI belum tersedia.');
                    htmlByProject[projectId] = '<div class="ai-project"><b>' + esc(projectName) +
                        '</b><p>' + esc(msg) + '</p></div>';
                } else {
                    var d = res.data;
                    var risk = String(d.risk_level || 'BELUM DIKETAHUI').toUpperCase();
                    var color = (risk === 'HIGH' || risk === 'CRITICAL') ? '#e05353' :
                                (risk === 'MEDIUM' ? '#e6a817' : '#2e9e4f');
                    var recs = Array.isArray(d.recommendations) ? d.recommendations : [];
                    var recHtml = recs.length ? '<ul class="ai-recommendations">' + recs.map(function (rec) {
                        return '<li>' + esc(rec) + '</li>';
                    }).join('') + '</ul>' : '<ul class="ai-recommendations"><li>Tidak ada rekomendasi khusus.</li></ul>';

                    htmlByProject[projectId] = '<div class="ai-project">' +
                        '<div class="ai-project-heading"><b>AI Cost &amp; Schedule Variance Analysis</b>' +
                        '<span class="ai-risk" style="color:' + color + '">RISK: ' + esc(risk) + '</span></div>' +
                        '<div class="ai-summary"><b>Ringkasan Eksekutif:</b><p>' + esc(d.ai_summary || '-') + '</p></div>' +
                        '<div class="ai-mitigations"><b>Rekomendasi Mitigasi:</b>' + recHtml + '</div>' +
                        '<div class="muted">Analisis terakhir: ' + esc(dateId(d.created_at)) + '</div>' +
                        '</div>';
                }
                if (completed === projectIds.length) {
                    if (loading) loading.remove();
                    results.innerHTML = projectIds.map(function (id) { return htmlByProject[id]; }).join('');
                    if (printButton) { printButton.disabled = false; printButton.textContent = 'Cetak'; }
                }
            });
        });
    }

    // Ukur ruang cetak A4 sebelum mencetak. Jika analisis berikut tanda tangan/footer
    // melewati halaman pertama, mulai bagian analisis pada halaman kedua.
    function fitAiSectionForPrint() {
        var section = document.getElementById('aiSection');
        var sign = root.querySelector('.sign');
        var footer = root.querySelector('.footer');
        if (!section) return;
        section.classList.remove('force-page-break');

        var measure = document.createElement('div');
        measure.style.cssText = 'position:absolute;left:-10000px;top:0;width:1px;height:267mm;visibility:hidden;';
        document.body.appendChild(measure);
        var printableHeight = measure.getBoundingClientRect().height;
        document.body.removeChild(measure);

        var contentEnd = section.offsetTop + section.offsetHeight;
        if (sign) contentEnd = Math.max(contentEnd, sign.offsetTop + sign.offsetHeight);
        if (footer) contentEnd = Math.max(contentEnd, footer.offsetTop + footer.offsetHeight);
        if (contentEnd > printableHeight) section.classList.add('force-page-break');
    }

    window.addEventListener('beforeprint', fitAiSectionForPrint);

    // Assign direct handlers so toolbar controls remain functional even if other
    // page scripts register delegated click handlers.
    var printButton = document.getElementById('btnPrintReceipt');
    var closeButton = document.getElementById('btnCloseReceipt');
    if (printButton) {
        printButton.onclick = function () {
            if (printButton.disabled) return;
            fitAiSectionForPrint();
            window.focus();
            window.print();
        };
    }
    if (closeButton) {
        closeButton.onclick = function () {
            window.close();
            window.setTimeout(function () {
                // Browsers may block window.close() when the page was not opened
                // by script; provide a usable fallback instead of a dead button.
                if (!window.closed) {
                    if (window.history.length > 1) window.history.back();
                    else window.location.href = '/rab-project/';
                }
            }, 120);
        };
    }

    if (!paymentId) {
        fail('ID pembayaran tidak valid.');
        return;
    }
    request({ task: 'load_payment', id: paymentId }, function (err, res) {
        if (err) {
            fail('Gagal memuat tanda terima: ' + err.message);
            return;
        }
        if (!res || !res.success || !res.data || !res.data.header) {
            fail((res && res.message) || 'Data pembayaran tidak ditemukan.');
            return;
        }
        renderReceipt(res.data);
    });
})();
