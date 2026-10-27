/*
  Document      : project.payment.js
  Description   : Pembayaran Termin per CLIENT (multi proyek). Grid utama = daftar pembayaran dari client; klik baris ->
                   panel detail di bawah grid (alokasi per termin + tombol Cetak Tanda Terima / Hapus).
                   Add membuka editor: pilih CLIENT -> daftar termin yang masih punya sisa tagihan dari SEMUA proyek client tsb
                   -> isi "Bayar Sekarang" per termin (bisa alokasi otomatis berurutan dari tanggal termin, lintas proyek).
                   [TAGIHAN] Tombol "+ Tambah Termin" DIHAPUS: invoice termin diterbitkan dari menu Tagihan ke Klien (projectinvoice.js).
                   Pembayaran tidak bisa diedit; koreksi = hapus (reverse) lalu input ulang.
                   Kelebihan bayar (jumlah diterima > total alokasi) disimpan di excess_amount.
                   [MULTI-PROYEK] satu pembayaran boleh mengalokasikan ke termin beberapa proyek, selama client-nya sama.
                   [MULTI-CURRENCY] pembayaran punya mata uang + kurs (default IDR, kurs KMK pada tanggal bayar). Jumlah Diterima & Bayar Sekarang
                   diisi dalam mata uang pembayaran; tagihan termin tetap IDR, nilai setara IDR = nilai x kurs (dibatasi sisa tagihan).
                   [JURNAL] simpan pembayaran memposting jurnal otomatis (Kas/Bank wajib dipilih); hapus pembayaran menghapus jurnalnya.
                   [AI-VARIANCE] Integrasi Analisis Varian Anggaran dan Risiko Proyek menggunakan Google Gemini API.
                   [PPN - TAHAP 2] Termin memuat PPN Keluaran (default 11%). Nilai termin yang diinput = DPP; tagihan = DPP + PPN.
  Backend       : /rab-project/dev/php-crud/project.paymentCRD.php
  Cetak         : /rab-project/views/project.payment.print.php?id=<id>  (project.payment.print.js)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Pembayaran <em>Termin</em>";

    var CRD = '/rab-project/dev/php-crud/project.paymentCRD.php';
    var PRINT_URL = '/rab-project/views/project.payment.print.php?id=';
    var ACCESS_ADD = 2;      // level akses minimal: input pembayaran / termin
    var ACCESS_EDIT = 3;     // level akses minimal: hapus pembayaran
    var VEPS = 0.01;         // toleransi nilai
    var TERMIN_RULE_PCT = { 1: 30, 2: 20 };   
    var DP_PCT = 30;                          
    var allocNote = '';
    var PPN_DEFAULT_RATE = 11;                // [PPN] tarif default (%); samakan dengan PP_PPN_DEFAULT_RATE di backend                       

    var dataRecord = null;         
    var hdrForm = null;
    var fDate, fClient, fAccount, fMethod, fRef, fTotal, fNotes, fInfo;
    var fDeposit, fDepInfo, depBalance = 0;     // [DEPOSIT] pemakaian deposit client (IDR) & info saldo     
    var fCurrency, fRate, fRateInfo;       
    var rateManual = false;                
    var rateReq = 0;                       
    var totalManual = false;       
    var autoMode = true;           
    var projSummary = [];          

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ------------------------------------------------------------
    // [AI-VARIANCE] Fungsi Pendukung Analisis AI
    // ------------------------------------------------------------
    function triggerAiAnalysis(projectId) {
        if (!projectId) {
            Ext.Msg.alert('Peringatan', 'Tidak ada proyek yang terikat pada data ini.');
            return;
        }
        
        Ext.MessageBox.wait('AI sedang menganalisis varian anggaran & pengeluaran proyek...', 'Mohon Tunggu');

        ajax({ task: 'analyze_project_variance', project_id: projectId }, function (res) {
            Ext.MessageBox.hide();
            if (res.success) {
                renderAiResultPanel(res.data);
            } else {
                Ext.Msg.show({
                    title: 'Kendala Layanan AI',
                    msg: enc(res.message || 'Server AI sedang sibuk. Silakan klik tombol Analisis Varian AI sekali lagi.'),
                    buttons: Ext.Msg.OK,
                    icon: Ext.MessageBox.WARNING
                });
            }
        }, function () {
            Ext.MessageBox.hide();
            Ext.Msg.alert('Gagal', 'Gagal terhubung ke server.');
        });
    }

    function renderAiResultPanel(d, projectName, append) {
        var risk = String(d.risk_level || 'BELUM DIKETAHUI').toUpperCase();
        var riskColor = '#2e9e4f'; // LOW
        if (risk === 'MEDIUM') riskColor = '#e6a817';
        else if (risk === 'HIGH' || risk === 'CRITICAL') riskColor = '#e05353';

        var recHtml = '';
        if (d.recommendations && d.recommendations.length) {
            Ext.each(d.recommendations, function (r) {
                recHtml += '<li style="margin-bottom:6px;">' + enc(r) + '</li>';
            });
        } else {
            recHtml = '<li>Tidak ada rekomendasi khusus.</li>';
        }

        var html = '<div class="pp-ai-project" style="margin-top:15px; margin-bottom:20px; background:#111b24; border:1px solid #00bcd4; border-radius:6px; padding:18px; color:#ffffff !important;">' +
                   (projectName ? '<div style="font-weight:bold; color:#b7dce2; margin-bottom:8px;">Proyek: ' + enc(projectName) + '</div>' : '') +
                   '<div style="font-weight:bold; font-size:15px; color:#00bcd4; margin-bottom:10px; display:flex; justify-content:space-between; align-items:center;">' +
                   '<span>&#9881; AI Cost &amp; Schedule Variance Analysis</span>' +
                   '<span style="background:' + riskColor + '; color:#fff; padding:3px 10px; border-radius:3px; font-size:13px;">RISK: ' + enc(risk) + '</span>' +
                   '</div>' +
                   '<div style="font-size:14px; line-height:22px; margin-bottom:12px; color:#ffffff !important;">' +
                   '<b style="color:#00bcd4;">Ringkasan Eksekutif:</b> ' + enc(d.ai_summary || '-') +
                   '</div>' +
                   '<div style="font-size:14px; line-height:22px; color:#ffffff !important;">' +
                   '<b style="color:#00bcd4;">Rekomendasi Mitigasi:</b>' +
                   '<ul style="margin:6px 0 0 18px; padding:0; color:#ffffff !important;">' + recHtml + '</ul>' +
                   (d.created_at ? '<div style="font-size:11px; color:#b7c6cc; margin-top:10px;">Analisis terakhir: ' + enc(d.created_at) + '</div>' : '') +
                   '</div></div>';

        var aiContainer = document.getElementById('ppAiResultContainer');
        if (!aiContainer) {
            aiContainer = document.createElement('div');
            aiContainer.id = 'ppAiResultContainer';
            dtlDiv.appendChild(aiContainer);
        }
        if (append) aiContainer.innerHTML += html;
        else aiContainer.innerHTML = html;
        dtlDiv.style.display = 'block';
    }

    function loadSavedAiResults(items) {
        var byProject = {};
        Ext.each(items || [], function (item) {
            var projectId = item && item.project_id ? String(item.project_id) : '';
            if (projectId && !byProject[projectId]) {
                byProject[projectId] = item.project_name || ('Proyek #' + projectId);
            }
        });
        var ids = [];
        for (var id in byProject) {
            if (Object.prototype.hasOwnProperty.call(byProject, id)) ids.push(id);
        }

        var target = document.getElementById('ppAiResultContainer');
        if (!target) return;
        if (!ids.length) {
            target.innerHTML = '<div style="margin-top:12px;padding:10px;border:1px solid #45616b;color:#b7c6cc;">Tidak ada proyek pada detail pembayaran ini untuk memuat hasil analisis AI.</div>';
            return;
        }

        target.innerHTML = '<div style="padding:10px;color:#b7dce2;">Memuat hasil analisis AI tersimpan...</div>';
        var completed = 0;
        var savedResults = [];
        var failedCount = 0;

        function finishOne() {
            completed++;
            if (completed !== ids.length) return;
            var currentTarget = document.getElementById('ppAiResultContainer');
            if (!currentTarget) return;
            currentTarget.innerHTML = '';
            if (savedResults.length) {
                // Keep the original project order from the payment detail.
                savedResults.sort(function (a, b) { return a.order - b.order; });
                Ext.each(savedResults, function (saved, index) {
                    renderAiResultPanel(saved.data, saved.projectName, index > 0);
                });
            } else if (failedCount) {
                currentTarget.innerHTML = '<div style="margin-top:12px;padding:10px;color:#ffcc80;">Tidak berhasil memuat hasil analisis tersimpan. Periksa koneksi/server, lalu muat ulang detail.</div>';
            } else {
                currentTarget.innerHTML = '<div style="margin-top:12px;padding:10px;border:1px solid #45616b;color:#b7c6cc;">Belum ada hasil analisis AI tersimpan untuk proyek pada pembayaran ini. Klik "Analisis Varian AI" untuk menjalankan analisis.</div>';
            }
        }

        Ext.each(ids, function (projectId, index) {
            ajax({ task: 'latest_project_variance', project_id: projectId }, function (res) {
                if (res && res.success && res.data) {
                    savedResults.push({
                        order: index,
                        projectId: projectId,
                        projectName: byProject[projectId],
                        data: res.data
                    });
                } else if (res && res.message && String(res.message).toLowerCase().indexOf('belum ada hasil') === -1) {
                    failedCount++;
                }
                finishOne();
            }, function () {
                failedCount++;
                finishOne();
            });
        });
    }

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }

    var METHOD_LABEL = { BANK_TRANSFER: 'Transfer Bank', CASH: 'Tunai', GIRO: 'Giro', CEK: 'Cek' };
    function methodLabel(v) { return METHOD_LABEL[v] || v || '-'; }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                onOk(res);
            },
            failure: function () {
                if (onFail) onFail(); else Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    function onStoreException() {
        Ext.Msg.alert('Gagal', 'Gagal memuat data dari server.');
    }

    function numVal(f) {
        if (!f) return 0;
        var v = parseFloat(f.getValue());
        if (isNaN(v)) v = parseFloat(String(f.getRawValue() || '').replace(/,/g, ''));
        return isNaN(v) ? 0 : v;
    }

    function fmtRate(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function currencyCode(id) { var r = id ? currencyDs.getById(id) : null; return r ? r.get('init') : 'IDR'; }
    function baseCurrencyId() {
        var id = null;
        currencyDs.each(function (r) { if (id === null && r.get('is_base') === true) id = r.get('id'); });
        return id;
    }
    function curId() { return Number((fCurrency && fCurrency.getValue()) || baseCurrencyId()) || null; }
    function isBaseCur(id) { var r = id ? currencyDs.getById(id) : null; return !r || r.get('is_base') === true; }
    function curCode() { return currencyCode(curId()); }
    function curRate() { return isBaseCur(curId()) ? 1 : (parseFloat(fRate.getValue()) || 0); }
    function rateTol() { return 0.005 * curRate() + VEPS; }
    function toIdr(pay, remaining) {
        var idr = round2(pay * curRate());
        if (Math.abs(idr - remaining) <= rateTol()) idr = round2(remaining);
        return idr;
    }
    function maxPay(remaining) { var r = curRate(); return r > 0 ? round2(remaining / r) : 0; }

    // ------------------------------------------------------------
    // 0. Panel Detail Dinamis Berbasis Persentase Tinggi Layanan
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var screenH = window.innerHeight || document.documentElement.clientHeight || 700;
    
    // Alokasi porsi grid utama & panel detail mengikuti persentase tinggi layar
    var mainHeight = Math.round(screenH * 0.38);
    var dtlHeight  = Math.round(screenH * 0.45);

    (function injectHeaderCss() {
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(
            '.ppMainGrid .x-grid3-hd-inner, #editorFormGrid .x-grid3-hd-inner {' +
            ' white-space:normal !important; overflow:hidden !important; text-overflow:clip !important;' +
            ' line-height:14px !important; height:36px !important; padding:0 6px !important; box-sizing:border-box;' +
            ' display:flex !important; flex-direction:column; justify-content:center; }' +
            '.ppMainGrid .x-grid3-hd, #editorFormGrid .x-grid3-hd { padding:0 !important; vertical-align:top; }'));
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'ppDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    
    // Pengaturan tinggi dan scroller otomatis berbasis persentase screen
    dtlDiv.style.height = dtlHeight + 'px';
    dtlDiv.style.maxHeight = dtlHeight + 'px';
    dtlDiv.style.overflowY = 'auto';     
    dtlDiv.style.overflowX = 'hidden';   
    dtlDiv.style.paddingRight = '8px';
    dtlDiv.style.paddingBottom = '20px';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    // Penyesuaian dinamis saat jendela peramban diubah ukurannya (resize)
    Ext.EventManager.onWindowResize(function () {
        var newH = window.innerHeight || document.documentElement.clientHeight || 700;
        var newDtlH = Math.round(newH * 0.45);
        if (dtlDiv) {
            dtlDiv.style.height = newDtlH + 'px';
            dtlDiv.style.maxHeight = newDtlH + 'px';
        }
    });

    function loadDetailPanel(id) {
        ajax({ task: 'load_payment', id: id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(res.data);
        }, function () {
            dtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server.</div>';
            dtlDiv.style.display = 'block';
        });
    }

    function renderDetailPanel(d) {
        var h = d.header, items = d.items || [];
        var cur = h.currency_code || 'IDR', foreign = (cur !== 'IDR'), allocCur = 0;
        
        var html = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">' +
                   '<h3 class="detail-title" style="margin:0;">Detail Pembayaran: ' + enc(h.payment_no) + '</h3>' +
                   '<div>' +
                   '<button type="button" id="ppBtnAi" style="margin-left:8px; padding:6px 14px; background:#00796b; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold; font-size:12px;">Analisis Varian AI</button>' +
                   '<button type="button" id="ppBtnPrint" style="margin-left:8px; padding:6px 14px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold; font-size:12px;">Cetak Tanda Terima</button>' +
                   '</div>' +
                   '</div>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.payment_date) + '</b> &nbsp;|&nbsp; Client: <b>' + enc(h.client_name) +
                   '</b> &nbsp;|&nbsp; Metode: <b>' + enc(methodLabel(h.payment_method)) + '</b>' +
                   ' &nbsp;|&nbsp; Mata uang: <b>' + enc(cur) + '</b>' + (foreign ? ' (kurs ' + fmtRate(h.exch_rate) + ')' : '') +
                   (h.account_name ? ' &nbsp;|&nbsp; Akun: <b>' + enc(h.account_code + ' - ' + h.account_name) + '</b>' : '') +
                   (h.reference_no ? ' &nbsp;|&nbsp; Ref: ' + enc(h.reference_no) : '') +
                   (h.notes ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.notes) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Proyek</th>' +
                   '<th align="left">Termin</th>' +
                   '<th align="left">No. Invoice</th>' +
                   '<th align="right">Tagihan</th>' +
                   '<th align="right">Dibayar (IDR)</th>' +
                   '<th align="right">Sisa Setelah Bayar</th>' +
                   (foreign ? '<th align="right">Dibayar (' + enc(cur) + ')</th>' : '') +
                   '</tr></thead><tbody>';

        if (!items.length) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#557982;">Tidak ada alokasi termin.</td></tr>';
        }
        Ext.each(items, function (i) {
            var sisaColor = i.sisa_after <= VEPS ? '#2e9e4f' : '#e6a817';
            allocCur += parseFloat(i.amount_cur) || 0;
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.project_name) + '</td>' +
                    '<td align="left">' + enc(i.termin_name) + (i.percentage !== null ? ' <span style="color:#668894;">(' + fmtAmt(i.percentage) + '%)</span>' : '') + '</td>' +
                    '<td align="left">' + enc(i.inv_no) + '</td>' +
                    '<td align="right">' + fmtAmt(i.invoice_amount) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(i.amount_paid) + '</td>' +
                    '<td align="right" style="color:' + sisaColor + ';">' + fmtAmt(i.sisa_after) + '</td>' +
                    (foreign ? '<td align="right">' + fmtAmt(i.amount_cur) + '</td>' : '') +
                    '</tr>';
        });
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Dialokasikan</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.allocated_amount) + '</b></td><td></td>' + (foreign ? '<td align="right"><b>' + fmtAmt(allocCur) + '</b></td>' : '') + '</tr>';
        if (h.excess_amount > VEPS) {
            html += '<tr class="data-row"><td colspan="4" align="right"><b>Lebih Bayar</b></td>' +
                    '<td align="right" style="color:#e6a817;"><b>' + fmtAmt(h.excess_amount) + '</b></td><td></td>' + (foreign ? '<td align="right" style="color:#e6a817;"><b>' + fmtAmt(round2(h.total_amount_cur - allocCur)) + '</b></td>' : '') + '</tr>';
        }
        if (h.deposit_applied > VEPS) {                                                       // [DEPOSIT] bagian deposit dari total dana
            html += '<tr class="data-row"><td colspan="4" align="right">Termasuk deposit dipakai</td>' +
                    '<td align="right" style="color:#2bc1e2;">' + fmtAmt(h.deposit_applied) + '</td><td></td>' + (foreign ? '<td></td>' : '') + '</tr>' +
                    '<tr class="data-row"><td colspan="4" align="right">Kas / bank diterima</td>' +
                    '<td align="right">' + fmtAmt(round2(h.total_amount - h.deposit_applied)) + '</td><td></td>' + (foreign ? '<td></td>' : '') + '</tr>';
        }
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total Diterima</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.total_amount) + '</b></td><td></td>' + (foreign ? '<td align="right"><b>' + fmtAmt(h.total_amount_cur) + '</b></td>' : '') + '</tr>';
        html += '</tbody></table>';

        html += '<div id="ppAiResultContainer"></div>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        document.getElementById('ppBtnPrint').onclick = function () { printReceipt(h.id); };
        document.getElementById('ppBtnAi').onclick = function () {
            var firstProjId = (items.length > 0) ? items[0].project_id : null;
            var aiContainer = document.getElementById('ppAiResultContainer');
            if (aiContainer) aiContainer.innerHTML = '';
            triggerAiAnalysis(firstProjId);
        };

        // Saat detail dibuka, tampilkan hasil AI tersimpan untuk semua proyek yang terkait.
        loadSavedAiResults(items);
    }

    function printReceipt(id) {
        id = parseInt(id, 10);
        if (!id || id < 1) {
            Ext.Msg.alert('Pilih Pembayaran', 'Pilih satu pembayaran terlebih dahulu.');
            return;
        }
        // Buka langsung dari interaksi klik supaya tidak mudah diblokir popup blocker.
        var w = window.open(PRINT_URL + encodeURIComponent(id), '_blank',
                            'width=1000,height=800,scrollbars=yes,resizable=yes');
        if (!w) {
            Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Printing kembali.');
        }
    }

    // Kaitkan tombol Printing/Cetak di toolbar halaman utama.
    // Printing toolbar utama: cetak daftar seluruh header pembayaran, bukan tanda terima satu baris.
    function bindMainPrintButton() {
        var el = document.getElementById('printData') ||
                 document.getElementById('printing') ||
                 document.getElementById('printBtn') ||
                 document.getElementById('btnPrinting');
        if (!el || el.getAttribute('data-pp-list-print-bound') === '1') return;
        el.setAttribute('data-pp-list-print-bound', '1');
        el.addEventListener('click', function (ev) {
            if (ev && ev.preventDefault) ev.preventDefault();
            if (ev && ev.stopImmediatePropagation) ev.stopImmediatePropagation();
            else if (ev && ev.stopPropagation) ev.stopPropagation();
            var w = window.open('/rab-project/views/project.payment.list.print.php', '_blank',
                                'width=1100,height=800,scrollbars=yes,resizable=yes');
            if (!w) {
                Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Printing kembali.');
            }
        }, true);
    }

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var payField = [
        { name: 'id' }, { name: 'payment_no' }, { name: 'payment_date' },
        { name: 'client_id' }, { name: 'client_name' }, { name: 'project_names' },
        { name: 'account_id' }, { name: 'account_name' }, { name: 'payment_method' },
        { name: 'total_amount', type: 'float' }, { name: 'allocated_amount', type: 'float' },
        { name: 'excess_amount', type: 'float' }, { name: 'deposit_applied', type: 'float' }, { name: 'reference_no' }, { name: 'notes' },   // [DEPOSIT]
        { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }, { name: 'total_amount_cur', type: 'float' }
    ];

    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'client_name' }, { name: 'contract_value', type: 'float' }]),
        listeners: { exception: onStoreException }
    });

    var clientDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_client_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }]),
        listeners: { exception: onStoreException }
    });

    var currencyDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_currencies' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'init' }, { name: 'name' }, { name: 'is_base' }]),
        listeners: { exception: onStoreException }
    });

    var accountDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_account_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'label' }]),
        listeners: { exception: onStoreException }
    });

    var methodDs = new Ext.data.ArrayStore({
        fields: ['id', 'name'],
        data: [['BANK_TRANSFER', 'Transfer Bank'], ['CASH', 'Tunai'], ['GIRO', 'Giro'], ['CEK', 'Cek']]
    });

    var outField = [
        { name: 'invoice_id' }, { name: 'project_id' }, { name: 'project_name' },
        { name: 'inv_no' }, { name: 'termin_name' }, { name: 'invoice_date' },
        { name: 'termin_no', type: 'int' },
        { name: 'percentage', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'dpp_amount', type: 'float' }, { name: 'ppn_amount', type: 'float' }, { name: 'ppn_rate', type: 'float' },   // [PPN]
        { name: 'faktur_pajak_no' }, { name: 'faktur_pajak_date' },                                                          // [PPN] faktur keluaran
        { name: 'paid_amount', type: 'float' }, { name: 'remaining', type: 'float' },
        { name: 'pay', type: 'float', defaultValue: 0 },
        { name: 'pay_idr', type: 'float', defaultValue: 0 }
    ];

    var outDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_outstanding' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'invoice_id' }, outField),
        listeners: { exception: onStoreException }
    });

    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_payments' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, payField),
        listeners: { exception: onStoreException }
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Pembayaran)
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        cls: 'ppMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. Pembayaran', dataIndex: 'payment_no', width: 130 },
            { header: 'Tanggal', dataIndex: 'payment_date', width: 90, align: 'center' },
            { header: 'Client', dataIndex: 'client_name', width: 180, renderer: CellWrap },
            { header: 'Proyek', dataIndex: 'project_names', width: 220, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Metode', dataIndex: 'payment_method', width: 100, align: 'center', renderer: function (v) { return methodLabel(v); } },
            { header: 'Akun', dataIndex: 'account_name', width: 140, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 70, align: 'center', renderer: function (v) { return v || 'IDR'; } },
            { header: 'Jumlah Diterima', dataIndex: 'total_amount_cur', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Total Diterima (IDR)', dataIndex: 'total_amount', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Teralokasi (IDR)', dataIndex: 'allocated_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Lebih Bayar (IDR)', dataIndex: 'excess_amount', width: 100, align: 'right',
                renderer: function (v) { return v > VEPS ? '<span style="color:#e6a817;font-weight:bold;">' + fmtAmt(v) + '</span>' : '-'; }
            },
            { header: 'Deposit Dipakai (IDR)', dataIndex: 'deposit_applied', width: 110, align: 'right',          // [DEPOSIT]
              renderer: function (v) { return v > VEPS ? '<span style="color:#2bc1e2;font-weight:bold;">' + fmtAmt(v) + '</span>' : '-'; } },
            { header: 'Referensi', dataIndex: 'reference_no', width: 120, renderer: function (v) { return CellWrap(v || ''); } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord.id);
            }
        }
    });

    setTimeout(function () { try { gridMain.getView().layout(); } catch (e) {} }, 100);

    function updateMainButtons() {
        mainBtnEnable('#addData');
        mainBtnDisable('#editData');
        if (dataRecord) mainBtnEnable('#deleteData'); else mainBtnDisable('#deleteData');
    }
    editDataDisable();
    updateMainButtons();
    bindMainPrintButton();
    // Beberapa layout merender toolbar sedikit terlambat.
    setTimeout(bindMainPrintButton, 250);
    setTimeout(bindMainPrintButton, 1000);

    // ------------------------------------------------------------
    // 3. Editor Pembayaran
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: outDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 280,
        columns: [
            { header: 'Proyek', dataIndex: 'project_name', width: 170, renderer: CellWrap },
            { header: 'Termin', dataIndex: 'termin_name', width: 150, renderer: CellWrap },
            { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
            { header: 'Tgl Termin', dataIndex: 'invoice_date', width: 85, align: 'center' },
            {
                header: '%', dataIndex: 'percentage', width: 50, align: 'right',
                renderer: function (v) { return v ? fmtAmt(v) : ''; }
            },
            { header: 'Tagihan incl. PPN (IDR)', dataIndex: 'amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Terbayar (IDR)', dataIndex: 'paid_amount', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Sisa Tagihan (IDR)', dataIndex: 'remaining', width: 110, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Bayar Sekarang', dataIndex: 'pay', width: 120, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            },
            {
                header: 'Setara IDR', dataIndex: 'pay_idr', width: 110, align: 'right',
                renderer: function (v) { return '<span style="color:#9fc3d1;">' + fmtAmt(v) + '</span>'; }
            },
            {
                id: 'ppDeleteCol', dataIndex: 'invoice_id', width: 32, align: 'center', fixed: true,
                header: '<span title="Hapus termin" style="display:inline-block;line-height:0;">' +
                        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">' +
                        '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
                        '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg></span>',
                renderer: function () {
                    return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus termin ini">&#10005;</span>';
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'pay') {
                    autoMode = false; allocNote = '';
                    var v = round2(e.value), max = maxPay(round2(e.record.get('remaining')));
                    if (v < 0) v = 0;
                    if (v > max) v = max;
                    e.record.set('pay', v);
                    recalcAll();
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) !== 'ppDeleteCol') return;
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) deleteTermin(rec);
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('ppHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'ppHdrForm';
            hdr.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(hdr, g);

            var mkBtn = function (id, label, bg, fn) {
                var b = document.createElement('button');
                b.id = id; b.type = 'button'; b.innerHTML = label;
                b.style.cssText = 'margin:0 6px 6px 0; padding:5px 12px; background:' + bg + '; color:#fff; border:none; border-radius:3px; cursor:pointer;';
                b.onclick = fn;
                g.parentNode.insertBefore(b, g);
            };
            // [TAGIHAN] invoice termin sekarang diterbitkan dari menu Tagihan ke Klien (seq 6026); modul ini hanya menerima pembayaran.
            // mkBtn('btnPpAddTermin', '+ Tambah Termin', '#2e6da4', openTerminWindow);
            mkBtn('btnPpAutoAlloc', 'Alokasi Otomatis', '#03615e', autoAllocate);
            mkBtn('btnPpPayAll', 'Lunasi Semua Sisa', '#555555', payAllRemaining);
            mkBtn('btnPpFaktur', 'Faktur Pajak Termin', '#8e6b22', editFakturPajak);        // [PPN] isi/ubah faktur pajak keluaran termin terpilih

            var totalsDiv = document.createElement('div');
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; font-family:arial; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = '<div>Total Dialokasikan: <span id="lblPpAlloc" style="color:#ffffff !important;">0.00</span></div>' +
                                  '<div id="lblPpSplit" style="font-size:14px; font-family:arial; line-height:22px; font-weight:normal; margin-top:4px; color:#c8dde6;"></div>' +
                                  '<div id="lblPpExcess" style="font-size:14px; font-family:arial; line-height:22px; font-weight:normal; margin-top:4px;"></div>';
            g.parentNode.insertBefore(totalsDiv, g.nextSibling);
        }
    })();

    function fitGrid() {
        var ef = document.getElementById('editorForm'), ld = document.getElementById('lblPpAlloc');
        if (!ef || !ld || !itemGrid.rendered || ef.offsetHeight === 0) return;
        var tot = ld.parentNode.parentNode;
        var avail = ef.getBoundingClientRect().bottom - itemGrid.getEl().dom.getBoundingClientRect().top - tot.offsetHeight - 24;
        itemGrid.setHeight(Math.max(120, Math.floor(avail)));
    }
    Ext.EventManager.onWindowResize(fitGrid);

    // [DEPOSIT] helper -------------------------------------------------------
    function depIdr() {          // deposit yang dipakai (IDR), dibatasi saldo deposit client
        if (!fDeposit) return 0;
        var v = round2(parseFloat(fDeposit.getValue()) || 0);
        return Math.max(Math.min(v, depBalance), 0);
    }
    function depCur() {          // setara mata uang pembayaran
        var r = curRate();
        return r > 0 ? depIdr() / r : 0;
    }
    function outstandingIdr() {
        var t = 0;
        outDs.each(function (r) { t += round2(r.get('remaining')); });
        return round2(t);
    }
    function loadDeposit(cid) {
        depBalance = 0;
        if (fDeposit) fDeposit.setValue(0);
        if (!cid) { if (fDepInfo) fDepInfo.setValue(''); return; }
        ajax({ task: 'get_client_deposit', client_id: cid }, function (res) {
            if (fClient.getValue() !== cid) return;                 // client sudah diganti
            depBalance = round2(parseFloat(res.balance) || 0);
            if (depBalance > VEPS) {
                fDepInfo.setValue('<span style="color:#e6a817;">Deposit client tersedia: <b>' + fmtAmt(depBalance) + ' IDR</b></span> ' +
                                  '&nbsp;<a href="#" style="color:#2bc1e2;" onclick="window.ppUseDeposit(); return false;">pakai maksimal</a>' +
                                  ' &nbsp;|&nbsp; <a href="#" style="color:#2bc1e2;" onclick="window.ppDepositHistory(); return false;">riwayat</a>');
            } else {
                fDepInfo.setValue('<span style="color:#668894;">Deposit client: 0 IDR</span> ' +
                                  '&nbsp;<a href="#" style="color:#2bc1e2;" onclick="window.ppDepositHistory(); return false;">riwayat</a>');
            }
            recalcAll();
        });
    }
    window.ppUseDeposit = function () {                              // pakai deposit sebesar min(saldo, total sisa tagihan)
        if (!(depBalance > 0)) return;
        fDeposit.setValue(Math.min(depBalance, outstandingIdr()));
        fDeposit.fireEvent('change', fDeposit);
    };
    window.ppDepositHistory = function () {                          // jendela riwayat deposit (left over) client
        var cid = fClient && fClient.getValue();
        if (!cid) { Ext.Msg.alert('Peringatan', 'Pilih client terlebih dahulu.'); return; }
        var ds = new Ext.data.JsonStore({
            url: CRD, baseParams: { task: 'get_client_deposit', client_id: cid }, root: 'data',
            fields: ['payment_date', 'payment_no', 'reference_no', { name: 'deposit_in', type: 'float' }, { name: 'deposit_out', type: 'float' }, { name: 'balance_after', type: 'float' }],
            listeners: { exception: function () { Ext.Msg.alert('Gagal', 'Gagal memuat riwayat deposit.'); } }
        });
        new Ext.Window({
            title: 'Riwayat Deposit - ' + enc(fClient.getRawValue()), width: 760, height: 380, modal: true, layout: 'fit',
            items: new Ext.grid.GridPanel({
                store: ds, stripeRows: true, viewConfig: { forceFit: true, deferEmptyText: false, emptyText: '<div style="padding:12px;">Belum ada deposit.</div>' },
                columns: [
                    { header: 'Tanggal', dataIndex: 'payment_date', width: 90, align: 'center' },
                    { header: 'No. Pembayaran', dataIndex: 'payment_no', width: 130 },
                    { header: 'Referensi', dataIndex: 'reference_no', width: 120, renderer: function (v) { return enc(v || ''); } },
                    { header: 'Lebih Bayar (+)', dataIndex: 'deposit_in', width: 120, align: 'right', renderer: function (v) { return v > 0 ? fmtAmt(v) : '-'; } },
                    { header: 'Dipakai (-)', dataIndex: 'deposit_out', width: 120, align: 'right', renderer: function (v) { return v > 0 ? fmtAmt(v) : '-'; } },
                    { header: 'Saldo', dataIndex: 'balance_after', width: 120, align: 'right', renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } }
                ]
            })
        }).show();
        ds.load();
    };

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal Bayar', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130,
            listeners: { select: function () { onDateChange(); }, change: function () { onDateChange(); } }
        });
        fClient = new myComboField({
            fieldLabel: 'Client', store: clientDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onClientChange(); } }
        });
        fCurrency = new myComboField({
            fieldLabel: 'Mata Uang', store: currencyDs, displayField: 'init', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, width: 100,
            listeners: { select: function () { onCurrencyChange(); } }
        });
        fRate = new myNumberField({
            fieldLabel: 'Kurs', decimalPrecision: 4, value: 1, width: 110, readOnly: true,
            listeners: { change: function () { rateManual = true; recalcAll(); } }
        });
        fRateInfo = new Ext.form.DisplayField({ fieldLabel: 'Sumber Kurs', value: '-', anchor: '98%' });
        fAccount = new myComboField({
            fieldLabel: 'Akun Kas/Bank', store: accountDs, displayField: 'label', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%'
        });
        fMethod = new myComboField({
            fieldLabel: 'Metode', store: methodDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, value: 'BANK_TRANSFER', width: 140
        });
        fRef = new myTextField({ fieldLabel: 'No. Referensi', anchor: '95%' });
        fTotal = new myNumberField({
            fieldLabel: 'Jumlah Diterima', decimalPrecision: 2, value: 0, width: 140,
            listeners: {
                change: function () {
                    totalManual = numVal(fTotal) > 0;
                    if (autoMode) {
                        if (totalManual && curRate() > 0) { autoAllocate(true); return; }
                        if (!totalManual) outDs.each(function (r) { r.set('pay', 0); r.set('pay_idr', 0); });
                    }
                    recalcAll();
                }
            }
        });
        fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fDeposit = new myNumberField({                      // [DEPOSIT] pakai deposit client untuk membayar tagihan
            fieldLabel: 'Pakai Deposit (IDR)', decimalPrecision: 2, value: 0, width: 140, allowNegative: false,
            listeners: {
                change: function () {
                    var v = depIdr();
                    if (round2(parseFloat(fDeposit.getValue()) || 0) !== v) fDeposit.setValue(v);      // batasi ke saldo
                    if (autoMode && v > 0 && curRate() > 0) { autoAllocate(true); return; }
                    recalcAll();
                }
            }
        });
        fDepInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });
        fInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 105, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'ppHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.34, [fDate, fClient, fCurrency]), col(0.34, [fAccount, fMethod, fRef]), col(0.32, [fTotal, fDeposit, fRate, fNotes])]
        });
        new Ext.form.FormPanel({
            renderTo: 'ppHdrForm',
            border: false,
            bodyStyle: 'background:transparent; padding:0 2px 4px 2px;',
            items: [fRateInfo, fDepInfo, fInfo]
        });
    }

    function resetHdrForm() {
        fDate.setValue(new Date());
        fClient.clearValue();
        var baseCur = baseCurrencyId();
        if (baseCur !== null) fCurrency.setValue(baseCur); else fCurrency.clearValue();
        fRate.setValue(1);
        fRate.setReadOnly(true);
        rateManual = false;
        fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (IDR, kurs 1)</span>');
        applyCurrencyLabels();
        fAccount.clearValue();
        fMethod.setValue('BANK_TRANSFER');
        fRef.setValue('');
        fNotes.setValue('');
        fTotal.setValue(0);
        fDeposit.setValue(0);                    // [DEPOSIT]
        depBalance = 0;
        fDepInfo.setValue('');
        totalManual = false;
        autoMode = true;
        allocNote = '';
        projSummary = [];
        describeContract();
    }

    function describeContract() {
        if (!fClient || !fClient.getValue()) { fInfo.setValue(''); return; }
        var cv = 0, tt = 0, tp = 0, bt = 0, lines = '';
        Ext.each(projSummary, function (p) {
            var bel = Math.max(p.contract_value - p.total_dpp, 0);      // [PPN] kontrak dibanding DPP termin, bukan tagihan incl. PPN
            cv += p.contract_value; tt += p.total_termin; tp += p.total_paid; bt += bel;
            lines += '<div style="font-size:14px; font-family: arial;line-height:22px; color:#c8dde6;">&bull; <b>' + enc(p.project_name) + '</b>' +
                     ' &nbsp;&ndash;&nbsp; kontrak <b>' + fmtAmt(p.contract_value) + '</b>' +
                     ' &nbsp;|&nbsp; termin (incl. PPN) <b>' + fmtAmt(p.total_termin) + '</b>' +
                     ' &nbsp;|&nbsp; dibayar <b>' + fmtAmt(p.total_paid) + '</b>' +
                     ' &nbsp;|&nbsp; belum ditagihkan <b>' + fmtAmt(bel) + '</b></div>';
        });
        var multi = projSummary.length > 1;
        fInfo.setValue('<div style="padding:6px 2px 2px 2px;">' +
                       '<div style="font-size:14px; line-height:24px; color:#ffffff;">' + (multi ? ('Total ' + projSummary.length + ' proyek &nbsp;&ndash;&nbsp; ') : '') +
                       'Nilai kontrak: <b>' + fmtAmt(cv) + '</b> &nbsp;|&nbsp; Total termin: <b>' + fmtAmt(tt) +
                       '</b> &nbsp;|&nbsp; Sudah dibayar: <b>' + fmtAmt(tp) + '</b> &nbsp;|&nbsp; Belum ditagihkan: <b>' + fmtAmt(bt) + '</b></div>' +
                       (multi ? ('<div style="margin-top:4px;">' + lines + '</div>') : '') + '</div>');
        setTimeout(fitGrid, 0);
    }

    function loadOutstanding(clientId, cb) {
        var keep = {};
        outDs.each(function (r) { keep[r.get('invoice_id')] = r.get('pay'); });
        if (!clientId) {
            outDs.removeAll();
            projSummary = [];
            describeContract();
            recalcAll();
            if (cb) cb();
            return;
        }
        outDs.load({
            params: { client_id: clientId },
            callback: function () {
                var sm = (outDs.reader.jsonData || {}).summary;
                projSummary = [];
                if (sm && sm.projects) {
                    Ext.each(sm.projects, function (p) {
                        projSummary.push({
                            project_id: parseInt(p.project_id, 10), project_name: p.project_name,
                            contract_value: parseFloat(p.contract_value) || 0,
                            total_termin: parseFloat(p.total_termin) || 0,
                            total_dpp: parseFloat(p.total_dpp) || 0,           // [PPN] dasar banding kontrak
                            total_paid: parseFloat(p.total_paid) || 0
                        });
                    });
                }
                outDs.each(function (r) {
                    var p = keep[r.get('invoice_id')];
                    if (p !== undefined) r.set('pay', Math.min(round2(p), round2(r.get('remaining'))));
                });
                describeContract();
                if (autoMode && numVal(fTotal) > 0 && curRate() > 0) { totalManual = true; autoAllocate(true); }
                else recalcAll();
                if (cb) cb();
            }
        });
    }

    function onClientChange() {
        outDs.removeAll();
        totalManual = false;
        autoMode = true;
        allocNote = '';
        fTotal.setValue(0);
        loadOutstanding(fClient.getValue());
        loadDeposit(fClient.getValue());          // [DEPOSIT] saldo deposit client
    }

    function applyCurrencyLabels() {
        var code = curCode();
        if (fTotal && fTotal.label) fTotal.label.update('Jumlah Diterima (' + code + ')');
        itemGrid.getColumnModel().setColumnHeader(8, 'Bayar Sekarang (' + code + ')');
    }

    function refreshRate() {
        var cid = curId(), dt = fDate.getValue();
        if (!cid || !dt) return;
        if (isBaseCur(cid)) {
            fRate.setValue(1);
            fRate.setReadOnly(true);
            fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (IDR, kurs 1)</span>');
            recalcAll();
            return;
        }
        fRate.setReadOnly(false);
        var token = ++rateReq;
        fRateInfo.setValue('<span style="color:#668894;">memuat kurs...</span>');
        ajax({ task: 'get_exchange_rate', currency_id: cid, date: dt.dateFormat('Y-m-d') }, function (res) {
            if (token !== rateReq) return;
            if (!res.success) {
                fRateInfo.setValue('<span style="color:#c0392b;">' + enc(res.message || 'Gagal memuat kurs.') + '</span>');
                return;
            }
            var d = res.data || {};
            if (d.rate) fRate.setValue(d.rate);
            if (d.source === 'periode') {
                fRateInfo.setValue('<span style="color:#2e9e4f;">KMK ' + enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else if (d.source === 'terakhir') {
                fRateInfo.setValue('<span style="color:#e6a817;">Belum ada kurs untuk tanggal bayar, memakai kurs terakhir: KMK ' +
                                   enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else {
                fRateInfo.setValue('<span style="color:#e6a817;">Data kurs belum tersedia, isi manual.</span>');
            }
            recalcAll();
        });
    }

    function onDateChange() { if (!rateManual) refreshRate(); }

    function onCurrencyChange() {
        rateManual = false;
        outDs.each(function (r) { r.set('pay', 0); r.set('pay_idr', 0); });
        totalManual = false;
        autoMode = true;
        allocNote = '';
        fTotal.setValue(0);
        applyCurrencyLabels();
        refreshRate();
    }

    function recalcAll() {
        var code = curCode(), foreign = !isBaseCur(curId()), rate = curRate();
        var alloc = 0, allocIdr = 0;
        outDs.each(function (r) {
            var rem = round2(r.get('remaining')), pay = round2(r.get('pay'));
            var mx = maxPay(rem);
            if (pay > mx) { pay = mx; r.set('pay', pay); }
            var idr = pay > 0 ? toIdr(pay, rem) : 0;
            if (round2(r.get('pay_idr')) !== idr) r.set('pay_idr', idr);
            alloc += pay;
            allocIdr += idr;
        });
        alloc = round2(alloc);
        allocIdr = round2(allocIdr);
        if (fTotal && !totalManual) fTotal.setValue(round2(Math.max(alloc - depCur(), 0)));       // [DEPOSIT] kas yang perlu diterima = alokasi - deposit
        var total = fTotal ? (parseFloat(fTotal.getValue()) || 0) : alloc;
        var excess = round2(total + depCur() - alloc);                                              // [DEPOSIT] dana = kas + deposit

        var el = document.getElementById('lblPpAlloc');
        if (el) {
            el.innerHTML = fmtAmt(alloc) + ' ' + enc(code) +
                           (foreign ? ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + fmtAmt(allocIdr) + ' IDR</span>' : '');
        }
        var ex = document.getElementById('lblPpExcess');
        if (ex) {
            if (foreign && !(rate > 0)) ex.innerHTML = '<span style="color:#e6a817;">Isi kurs ' + enc(code) + ' agar nilai setara IDR dapat dihitung.</span>';
            else if (excess < -VEPS) ex.innerHTML = '<span style="color:#ff6b5e;">Jumlah diterima kurang ' + fmtAmt(-excess) + ' ' + enc(code) + ' dari total alokasi.</span>';
            else if (excess > VEPS) ex.innerHTML = '<span style="color:#e6a817;">Lebih bayar ' + fmtAmt(excess) + ' ' + enc(code) + ' (dicatat sebagai excess).</span>';
            else ex.innerHTML = '';
        }
        var sp = document.getElementById('lblPpSplit');
        if (sp) {
            var sums = {}, names = [];
            outDs.each(function (r) {
                var pv = parseFloat(r.get('pay')) || 0;
                if (pv <= 0) return;
                var k = r.get('project_name');
                if (sums[k] === undefined) { sums[k] = 0; names.push(k); }
                sums[k] += pv;
            });
            if (names.length > 1 && alloc > 0) {
                var parts = [];
                Ext.each(names, function (k) {
                    parts.push(enc(k) + ' <b>' + Ext.util.Format.number(sums[k] / alloc * 100, '0.0') + '%</b> (' + fmtAmt(sums[k]) + ')');
                });
                sp.innerHTML = 'Porsi per proyek: ' + parts.join(' &nbsp;|&nbsp; ');
            } else {
                sp.innerHTML = '';
            }
            if (allocNote) sp.innerHTML += (sp.innerHTML ? '<br/>' : '') + '<span style="color:#e6c36a;">' + allocNote + '</span>';
        }
        fitGrid();
    }

    function autoAllocate(silent) {
        silent = (silent === true);
        itemGrid.stopEditing();
        var total = numVal(fTotal), rate = curRate(), depI = depIdr();      // [DEPOSIT]
        if (total <= 0 && depI <= 0) { if (!silent) Ext.Msg.alert('Peringatan', 'Isi Jumlah Diterima terlebih dahulu.'); return; }
        if (!(rate > 0)) { if (!silent) Ext.Msg.alert('Peringatan', 'Isi kurs ' + curCode() + ' terlebih dahulu.'); return; }
        if (!outDs.getCount()) { if (!silent) Ext.Msg.alert('Peringatan', 'Belum ada termin yang bisa dialokasikan.'); return; }

        var totalIdr = round2(round2(total * rate) + depI);                 // [DEPOSIT] dana alokasi = kas + deposit
        var smap = {};
        Ext.each(projSummary, function (p) { smap[p.project_id] = p; });

        var recs = outDs.getRange(), groups = {}, order = [], i, sumRemain = 0, sumContract = 0;
        for (i = 0; i < recs.length; i++) {
            var pid = recs[i].get('project_id'), rem = round2(recs[i].get('remaining'));
            if (!groups[pid]) { groups[pid] = { recs: [], cap: 0, given: 0, w: 0, prop: 0, rule: 0, want: 0, name: recs[i].get('project_name') }; order.push(pid); }
            groups[pid].recs.push(recs[i]);
            groups[pid].cap = round2(groups[pid].cap + rem);
            sumRemain = round2(sumRemain + rem);
        }
        Ext.each(order, function (id) {
            var sm = smap[id];
            groups[id].contract = sm ? sm.contract_value : 0;
            groups[id].everPaid = !!sm && sm.total_paid > VEPS;
            sumContract += groups[id].contract;
        });

        var notes = [];
        if (totalIdr + VEPS >= sumRemain) {
            Ext.each(order, function (id) { groups[id].given = groups[id].cap; });
            notes.push('Jumlah diterima &ge; total sisa tagihan (' + fmtAmt(sumRemain) + ' IDR): semua termin dilunasi.');
        } else {
            var sumWant = 0;
            Ext.each(order, function (id) {
                var g = groups[id], first = g.recs[0];
                g.w = sumContract > 0 ? g.contract : 1;
                g.prop = round2(totalIdr * g.w / (sumContract > 0 ? sumContract : order.length));
                var n = parseInt(first.get('termin_no'), 10) || 1;
                var pct = g.everPaid ? (TERMIN_RULE_PCT[n] || 0) : DP_PCT;
                g.ruleLabel = g.everPaid ? ('termin ke-' + n + ' ' + pct + '%') : ('DP ' + pct + '%');
                g.rule = Math.max(round2(g.contract * pct / 100) - round2(first.get('paid_amount')), 0);
                g.want = Math.min(Math.max(g.prop, g.rule), g.cap);
                sumWant = round2(sumWant + g.want);
            });
            var left = totalIdr;
            if (sumWant > totalIdr) {
                var f = totalIdr / sumWant, used = 0;
                Ext.each(order, function (id) { var g = groups[id]; g.given = Math.min(round2(g.want * f), g.cap); used = round2(used + g.given); });
                left = round2(totalIdr - used);
            } else {
                Ext.each(order, function (id) { groups[id].given = groups[id].want; });
                left = round2(totalIdr - sumWant);
            }
            var active = order.slice();
            for (var guard = 0; guard < 50 && left >= 0.01 && active.length; guard++) {
                var wsum = 0, used2 = 0, next = [];
                Ext.each(active, function (id) { wsum += groups[id].w; });
                if (!(wsum > 0)) break;
                Ext.each(active, function (id) {
                    var g = groups[id], room = round2(g.cap - g.given);
                    var take = Math.min(round2(left * g.w / wsum), room, round2(left - used2));
                    if (take > 0) { g.given = round2(g.given + take); used2 = round2(used2 + take); }
                    if (round2(g.cap - g.given) >= 0.01) next.push(id);
                });
                if (used2 < 0.01) break;
                left = round2(left - used2);
                active = next;
            }
            Ext.each(order, function (id) {
                var g = groups[id];
                notes.push('&bull; ' + enc(g.name) + ': ' + enc(g.ruleLabel) + ' = ' + fmtAmt(g.rule) + ' | proporsional = ' + fmtAmt(g.prop));
            });
        }

        Ext.each(order, function (id) {
            var rest = groups[id].given;
            Ext.each(groups[id].recs, function (r) {
                var rm = round2(r.get('remaining'));
                var idr = Math.min(rest, rm);
                var pay = (idr >= rm - VEPS) ? maxPay(rm) : round2(idr / rate);
                if (!(pay > 0)) pay = 0;
                r.set('pay', round2(pay));
                rest = round2(rest - idr);
            });
        });
        allocNote = notes.join('<br/>');
        autoMode = true;
        totalManual = true;
        recalcAll();
        itemGrid.getView().refresh();
    }

    function payAllRemaining() {
        itemGrid.stopEditing();
        if (!(curRate() > 0)) { Ext.Msg.alert('Peringatan', 'Isi kurs terlebih dahulu.'); return; }
        outDs.each(function (r) { r.set('pay', maxPay(round2(r.get('remaining')))); });
        autoMode = false;
        allocNote = '';
        totalManual = false;
        recalcAll();
    }

    // [PPN] Isi / ubah No. & Tgl Faktur Pajak keluaran pada termin yang dipilih (baris di grid tagihan)
    function editFakturPajak() {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Tidak memiliki hak akses.'); return; }
        itemGrid.stopEditing();
        var rec = itemGrid.getSelectionModel().getSelected();
        if (!rec) { Ext.Msg.alert('Peringatan', 'Pilih termin pada tabel terlebih dahulu.'); return; }
        if (!(parseFloat(rec.get('ppn_amount')) > 0)) {
            Ext.Msg.alert('Peringatan', 'Termin ' + enc(rec.get('inv_no')) + ' tidak memuat PPN, tidak ada faktur pajak.');
            return;
        }
        var cid = fClient.getValue();
        var fNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', maxLength: 30, value: rec.get('faktur_pajak_no') || '' });
        var fDt = new Ext.form.DateField({
            fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true,
            value: rec.get('faktur_pajak_date') ? Date.parseDate(rec.get('faktur_pajak_date'), 'Y-m-d') : ''
        });
        var win = new Ext.Window({
            title: 'Faktur Pajak - ' + rec.get('inv_no'), width: 420, height: 230, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 120, bodyStyle: 'padding:12px;',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: 'PPN Keluaran <b>' + fmtAmt(rec.get('ppn_amount')) + '</b> (DPP ' + fmtAmt(rec.get('dpp_amount')) +
                                 '). Tanggal faktur menentukan masa pajak di Laporan PPN. Kosongkan nomor untuk menghapus.' }, fNo, fDt]
            }),
            buttons: [
                { text: 'Simpan', handler: function () {
                    var no = fNo.getValue(), dt = fDt.getValue();               // baca nilai sebelum win.close() (field ikut hancur)
                    ajax({
                        task: 'update_faktur_pajak', id: rec.get('invoice_id'), client_id: cid,
                        faktur_pajak_no: no, faktur_pajak_date: dt ? dt.dateFormat('Y-m-d') : ''
                    }, function (res) {
                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                        if (res.success) { win.close(); loadOutstanding(cid); }
                    });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    function deleteTermin(rec) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Tidak memiliki hak akses.'); return; }
        var cid = fClient.getValue();
        Ext.Msg.confirm('Hapus Termin',
            'Hapus termin <b>' + enc(rec.get('inv_no')) + '</b> (' + enc(rec.get('termin_name')) + ')?',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_termin', id: rec.get('invoice_id'), client_id: cid }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) loadOutstanding(cid);
                });
            });
    }

    function openTerminWindow() {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Tidak memiliki hak akses.'); return; }
        var cid = fClient.getValue();
        if (!cid) { Ext.Msg.alert('Peringatan', 'Pilih client terlebih dahulu.'); return; }

        var prjRows = [];
        projectDs.each(function (r) {
            if (Number(r.get('client_id')) === Number(cid)) prjRows.push([r.get('id'), r.get('name'), parseFloat(r.get('contract_value')) || 0]);
        });
        if (!prjRows.length) { Ext.Msg.alert('Peringatan', 'Client belum memiliki proyek.'); return; }
        var contract = prjRows[0][2];

        var tProject = new myComboField({
            fieldLabel: 'Proyek', allowBlank: false, anchor: '95%',
            store: new Ext.data.ArrayStore({ fields: ['id', 'name', 'contract_value'], data: prjRows }),
            displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all', forceSelection: true,
            value: prjRows[0][0],
            listeners: {
                select: function (combo, rec) {
                    contract = parseFloat(rec.get('contract_value')) || 0;
                    refreshContractInfo();
                    recalcFromPct();
                }
            }
        });
        var tName = new myTextField({ fieldLabel: 'Nama Termin', allowBlank: false, anchor: '95%', emptyText: 'mis. Termin 1 - DP' });
        var tDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        var tAmt = new myNumberField({
            fieldLabel: 'Nilai Termin (DPP)', decimalPrecision: 2, value: 0, width: 150,
            listeners: { change: function () { refreshContractInfo(); } }
        });
        // [PPN] PPN Keluaran: centang = kenakan PPN, tarif default 11%
        var tPpnOn = new Ext.form.Checkbox({
            fieldLabel: 'Kenakan PPN', boxLabel: 'PPN Keluaran', checked: true,
            listeners: { check: function () { tPpnRate.setDisabled(!tPpnOn.getValue()); refreshContractInfo(); } }
        });
        var tPpnRate = new myNumberField({
            fieldLabel: 'Tarif PPN (%)', decimalPrecision: 2, value: PPN_DEFAULT_RATE, width: 100,
            listeners: { change: function () { refreshContractInfo(); } }
        });
        var tFpNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', emptyText: 'opsional' });
        var tFpDate = new Ext.form.DateField({ fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true });
        function curPpnRate() { return tPpnOn.getValue() ? (parseFloat(tPpnRate.getValue()) || 0) : 0; }
        var tPct = new myNumberField({
            fieldLabel: 'Persentase (%)', decimalPrecision: 2, width: 100,
            listeners: { change: function () { recalcFromPct(); } }
        });
        var tInfo = new Ext.form.DisplayField({ hideLabel: true, value: '' });

        function recalcFromPct() {
            var p = parseFloat(tPct.getValue()) || 0;
            if (contract > 0 && p > 0) tAmt.setValue(round2(contract * p / 100));
            refreshContractInfo();                                   // [PPN] ringkasan ikut diperbarui
        }
        function refreshContractInfo() {
            // [PPN] ringkasan DPP / PPN / total tagihan (rumus sama dengan backend: PPN = DPP x tarif, dibulatkan 2 desimal)
            var dpp = round2(parseFloat(tAmt.getValue()) || 0), rt = curPpnRate(), ppn = round2(dpp * rt / 100);
            tInfo.setValue('<span style="color:#9fc3d1;">Nilai kontrak (DPP): <b>' + fmtAmt(contract) + '</b>.</span><br/>' +
                           '<span style="color:#9fc3d1;">DPP <b>' + fmtAmt(dpp) + '</b> &nbsp;|&nbsp; PPN ' + rt + '% <b>' + fmtAmt(ppn) +
                           '</b> &nbsp;|&nbsp; Total tagihan <b>' + fmtAmt(round2(dpp + ppn)) + '</b></span>');
        }
        refreshContractInfo();

        var lblStyle = 'color:#9fc3d1;';
        var btnCss = 'margin-left:8px; padding:7px 18px; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;';
        var win = new Ext.Window({
            title: 'Tambah Termin - ' + enc(fClient.getRawValue()),
            width: 460, height: 500, modal: true, closeAction: 'close', resizable: false,
            layout: 'fit',
            bodyStyle: 'background:#0f1d29;',
            items: [new Ext.form.FormPanel({
                border: false, labelWidth: 120, labelStyle: lblStyle, autoScroll: true,
                bodyStyle: 'background:#0f1d29; padding:14px;',
                defaults: { labelStyle: lblStyle },
                items: [
                    tProject, tName, tDate, tPct, tAmt, tPpnOn, tPpnRate, tFpNo, tFpDate, tInfo,
                    {
                        xtype: 'panel', border: false, bodyStyle: 'background:transparent; text-align:right; padding-top:10px;',
                        html: '<button type="button" id="ppTerminSave" style="' + btnCss + 'background:#03615e;">Simpan</button>' +
                              '<button type="button" id="ppTerminCancel" style="' + btnCss + 'background:#555555;">Batal</button>',
                        listeners: {
                            afterrender: function () {
                                document.getElementById('ppTerminCancel').onclick = function () { win.close(); };
                                document.getElementById('ppTerminSave').onclick = function () {
                                    if (!tProject.getValue()) { Ext.Msg.alert('Peringatan', 'Proyek wajib dipilih.'); return; }
                                    if (!tName.getValue()) { Ext.Msg.alert('Peringatan', 'Nama termin wajib diisi.'); return; }
                                    if (!tDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal wajib diisi.'); return; }
                                    if (!((parseFloat(tAmt.getValue()) || 0) > 0)) { Ext.Msg.alert('Peringatan', 'Nilai termin harus > 0.'); return; }
                                    ajax({
                                        task: 'save_termin',
                                        client_id: cid,
                                        project_id: tProject.getValue(),
                                        termin_name: tName.getValue(),
                                        invoice_date: tDate.getValue().dateFormat('Y-m-d'),
                                        percentage: (parseFloat(tPct.getValue()) || 0) > 0 ? tPct.getValue() : '',
                                        amount: tAmt.getValue(),
                                        ppn_rate: curPpnRate(),                                                                   // [PPN]
                                        faktur_pajak_no: tFpNo.getValue(),                                                         // [PPN]
                                        faktur_pajak_date: tFpDate.getValue() ? tFpDate.getValue().dateFormat('Y-m-d') : ''        // [PPN]
                                    }, function (res) {
                                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                                        if (res.success) { win.close(); loadOutstanding(cid); }
                                    });
                                };
                            }
                        }
                    }
                ]
            })]
        });
        win.show();
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        var ef = document.getElementById('editorForm');
        if (ef) ef.style.overflowY = 'auto';
        ensureHdrForm();
        setTimeout(function () { fitGrid(); try { itemGrid.getView().layout(); } catch (e) {} }, 50);
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            Ext.Msg.alert('Akses Ditolak', 'Tidak memiliki hak akses.');
            return;
        }
        showEditor('Pembayaran Termin Baru');
        resetHdrForm();
        outDs.removeAll();
        recalcAll();
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fClient || !fClient.getValue()) { Ext.Msg.alert('Peringatan', 'Client wajib dipilih.'); return; }
        if (!fDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal bayar wajib diisi.'); return; }
        if (!fAccount.getValue() && !(depIdr() > 0 && numVal(fTotal) <= 0)) { Ext.Msg.alert('Peringatan', 'Akun Kas/Bank wajib dipilih.'); return; }   // [DEPOSIT] lunas penuh dari deposit tanpa kas
        var cid = fClient.getValue();
        if (!isBaseCur(curId()) && !(curRate() > 0)) { Ext.Msg.alert('Peringatan', 'Kurs wajib diisi.'); return; }

        var arr = [], alloc = 0, err = '';
        outDs.each(function (r) {
            var pay = round2(r.get('pay'));
            if (pay <= 0) return;
            if (pay > maxPay(round2(r.get('remaining'))) + VEPS) {
                err = err || ('Termin ' + r.get('inv_no') + ': bayar melebihi sisa tagihan.');
                return;
            }
            arr.push({ invoice_id: r.get('invoice_id'), amount_paid: pay });
            alloc += pay;
        });
        if (err) { Ext.Msg.alert('Peringatan', err); return; }
        alloc = round2(alloc);
        if (!arr.length) { Ext.Msg.alert('Peringatan', 'Isi "Bayar Sekarang" minimal pada satu termin.'); return; }

        var total = round2(fTotal.getValue());
        if (total <= 0 && depIdr() <= 0) total = alloc;                                          // [DEPOSIT]
        if (total + depCur() + VEPS < alloc) { Ext.Msg.alert('Peringatan', 'Kas diterima + deposit lebih kecil dari total alokasi.'); return; }
        var excess = round2(total + depCur() - alloc);

        var doSave = function () {
            ajax({
                task: 'save_payment',
                client_id: cid,
                currency_id: curId() || 0,
                exch_rate: curRate(),
                payment_date: fDate.getValue().dateFormat('Y-m-d'),
                account_id: fAccount.getValue() || 0,
                payment_method: fMethod.getValue() || 'BANK_TRANSFER',
                total_amount: total,
                deposit_apply: depIdr(),                                                           // [DEPOSIT]
                reference_no: fRef.getValue(),
                notes: fNotes.getValue(),
                items: Ext.util.JSON.encode(arr)
            }, function (res) {
                if (res.success) {
                    document.getElementById('editorWdw').style.display = 'none';
                    mainDs.load();
                    var newId = res.data && res.data.id;
                    if (newId) {
                        dataRecord = { id: newId };
                        updateMainButtons();
                        loadDetailPanel(newId);
                        Ext.Msg.confirm('Sukses', (res.message || 'Berhasil disimpan.') + '<br/><br/>Cetak tanda terima sekarang?', function (btn) {
                            if (btn === 'yes') printReceipt(newId);
                        });
                    } else {
                        Ext.Msg.alert('Sukses', res.message || 'Berhasil disimpan.');
                    }
                } else {
                    Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                    loadOutstanding(cid);
                }
            });
        };

        if (excess > VEPS) {
            Ext.Msg.confirm('Konfirmasi Lebih Bayar',
                'Jumlah diterima melebihi alokasi sebesar <b>' + fmtAmt(excess) + ' ' + curCode() + '</b>. Lanjutkan simpan?',
                function (btn) { if (btn === 'yes') doSave(); });
        } else {
            doSave();
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih pembayaran terlebih dahulu.'); return; }
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Tidak memiliki hak akses.');
            return;
        }
        Ext.Msg.confirm('Hapus Pembayaran',
            'Hapus pembayaran <b>' + enc(dataRecord.payment_no || '') + '</b>? Saldo termin akan dikembalikan.',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_payment', id: dataRecord.id }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) {
                        dataRecord = null;
                        dtlDiv.style.display = 'none';
                        mainDs.load();
                        updateMainButtons();
                    }
                });
            });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) {
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';

    projectDs.load();
    clientDs.load();
    currencyDs.load();
    accountDs.load();
});