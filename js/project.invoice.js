/*
  Document      : projectinvoice.js
  Description   : Tagihan ke Klien (invoice termin proyek).  Alur: TERBITKAN invoice -> client membayar (modul Project Payment)
                   -> kelebihan bayar jadi deposit client. Grid utama = daftar invoice (status Belum Bayar / Sebagian / Lunas, jatuh tempo);
                   klik baris -> panel detail (pembayaran yang masuk, faktur pajak, cetak). Add/Edit membuka jendela invoice.
                   Nilai yang diinput = DPP; PPN Keluaran default 11% (0 = tanpa PPN); total tagihan = DPP + PPN.
                   Ubah / hapus hanya untuk invoice yang belum ada pembayaran. Ringkasan di atas grid = dasar proyeksi arus kas penerimaan.
  Backend       : /proyek/php-crud/projectinvoiceCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Tagihan ke <em>Klien</em>";

    var CRD = main_url + '/php-crud/project.invoiceCRD.php';

    var ACCESS_ADD = 2;                  // level akses minimal: terbitkan invoice
    var ACCESS_EDIT = 3;                 // ubah / hapus / faktur pajak
    var PPN_DEFAULT_RATE = 11;           // tarif PPN default (%); samakan dengan kebijakan pajak berlaku
    var TOP_DEFAULT_DAYS = 30;           // termin pembayaran default (hari) untuk jatuh tempo; bisa diubah per invoice
    var VEPS = 0.01;

    var dataRecord = null;               // baris invoice terpilih

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showErr('Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () { if (onFail) onFail(); else showErr('Gagal menghubungi server.'); }
        });
    }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }

    function statusColor(v) {
        return { 'Lunas': '#00ff4c', 'Sebagian': '#e6a817', 'Belum Bayar': '#2bc1e2' }[v] || '#ffffff';
    }

    // ------------------------------------------------------------
    // 0. Gaya & kerangka halaman (ringkasan, filter, panel detail) - tema gelap
    // ------------------------------------------------------------
    (function injectCss() {
        var css = [
            '.ti-cards{display:flex;gap:10px;flex-wrap:wrap;padding:8px 10px 4px 10px;}',
            '.ti-card{flex:1 1 150px;background:#031126;border:1px solid #123049;border-radius:4px;padding:8px 12px;min-width:150px;}',
            '.ti-card .lb{font:11px arial;color:#6b869a;text-transform:uppercase;letter-spacing:.4px;}',
            '.ti-card .vl{font:bold 16px arial;color:#e6f1f8;margin-top:3px;}',
            '.ti-card.warn .vl{color:#ff6b5e;} .ti-card.soon .vl{color:#e6a817;} .ti-card.ok .vl{color:#7fe0a0;}',
            '.ti-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:6px 10px 8px 10px;color:#9fc3d1;font:13px arial;}',
            '.ti-filter select{background:#031126;border:1px solid #1d4a6b;color:#e6f1f8;height:26px;padding:0 8px;font:13px arial;border-radius:2px;min-width:170px;}',
            '.ti-filter .btn{padding:5px 14px;border:none;border-radius:3px;cursor:pointer;color:#fff;font:bold 12px arial;background:#03615e;}'
        ].join('\n');
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(css));
        document.head.appendChild(st);
    })();

    var gridContainer = document.getElementById('divMainGrid');
    var topDiv = document.createElement('div');
    topDiv.innerHTML =
        '<div class="ti-cards" id="tiCards"></div>' +
        '<div class="ti-filter">' +
        '<label>Client</label><select id="tiClient"><option value="0">(Semua client)</option></select>' +
        '<label>Status</label><select id="tiStatus">' +
        '<option value="">(Semua)</option><option value="open">Belum lunas</option><option value="unpaid">Belum bayar</option>' +
        '<option value="partial">Sebagian</option><option value="paid">Lunas</option><option value="overdue">Jatuh tempo</option></select>' +
        '<button type="button" class="btn" id="tiGo">Tampilkan</button></div>';
    gridContainer.parentNode.insertBefore(topDiv, gridContainer);

    var GridMainHeight = maxGridHeight(gridContainer);
    var mainHeight = Math.max(Math.round(GridMainHeight * 0.55) - 60, 200);
    var DtlHeight = Math.max(Math.round(GridMainHeight - mainHeight - 130), 120);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'tiDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = DtlHeight + 'px';
    dtlDiv.style.overflowY = 'auto';
    gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);

    function renderCards(s) {
        s = s || {};
        var card = function (cls, lb, v) { return '<div class="ti-card ' + cls + '"><div class="lb">' + lb + '</div><div class="vl">' + fmtAmt(v) + '</div></div>'; };
        document.getElementById('tiCards').innerHTML =
            card('', 'Total Ditagihkan', s.invoiced) + card('ok', 'Sudah Dibayar', s.paid) + card('', 'Sisa Tagihan', s.outstanding) +
            card('warn', 'Lewat Jatuh Tempo', s.overdue) + card('soon', 'Jatuh Tempo 30 Hari', s.due_30) + card('', 'Tanpa Jatuh Tempo', s.no_due);
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    var invField = [
        'id', 'inv_no', 'invoice_date', 'due_date', 'termin_name', 'project_id', 'project_name', 'client_id', 'client_name',
        'faktur_pajak_no', 'faktur_pajak_date', 'remark', 'status',
        { name: 'termin_no', type: 'int' }, { name: 'percentage', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'paid_amount', type: 'float' }, { name: 'remaining', type: 'float' }, { name: 'dpp_amount', type: 'float' },
        { name: 'ppn_rate', type: 'float' }, { name: 'ppn_amount', type: 'float' }, 'is_overdue', { name: 'days_overdue', type: 'int' }
    ];
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_invoices' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, invField),
        listeners: {
            exception: storeError,
            load: function (store) { renderCards((store.reader.jsonData || {}).summary); }
        }
    });
    var clientDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_clients' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }]),
        listeners: { exception: storeError }
    });
    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_projects' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'client_id' }, { name: 'contract_value', type: 'float' }, { name: 'billed_dpp', type: 'float' }]),
        listeners: { exception: storeError }
    });

    function loadMain() {
        mainDs.baseParams.client_id = parseInt(document.getElementById('tiClient').value, 10) || 0;
        mainDs.baseParams.status = document.getElementById('tiStatus').value;
        mainDs.load();
    }
    document.getElementById('tiGo').onclick = loadMain;

    // ------------------------------------------------------------
    // 2. Grid utama
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: mainHeight, loadMask: true, stripeRows: true,
        viewConfig: { forceFit: true, deferEmptyText: false, emptyText: '<div style="padding:16px;color:#9fc3d1;">Belum ada invoice. Klik Add untuk menerbitkan invoice ke client.</div>' },
        columns: [
            { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'invoice_date', width: 82, align: 'center' },
            {
                header: 'Jatuh Tempo', dataIndex: 'due_date', width: 105, align: 'center',
                renderer: function (v, meta, r) {
                    if (!v) return '<span style="color:#668894;">-</span>';
                    return r.get('is_overdue') ? '<span style="color:#ff6b5e;font-weight:bold;">' + enc(v) + ' (+' + r.get('days_overdue') + ' hr)</span>' : enc(v);
                }
            },
            { header: 'Client', dataIndex: 'client_name', width: 150, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Proyek', dataIndex: 'project_name', width: 180, renderer: CellWrap },
            { header: 'Termin', dataIndex: 'termin_name', width: 110, renderer: CellWrap },
            { header: 'DPP', dataIndex: 'dpp_amount', width: 105, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'PPN', dataIndex: 'ppn_amount', width: 90, align: 'right', renderer: function (v) { return v > 0 ? fmtAmt(v) : '-'; } },
            { header: 'Total Tagihan', dataIndex: 'amount', width: 115, align: 'right', renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } },
            { header: 'Terbayar', dataIndex: 'paid_amount', width: 105, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Sisa', dataIndex: 'remaining', width: 105, align: 'right', renderer: function (v) { return v > VEPS ? '<b>' + fmtAmt(v) + '</b>' : '-'; } },
            {
                header: 'Status', dataIndex: 'status', width: 90, align: 'center',
                renderer: function (v) { return '<span style="color:' + statusColor(v) + ' !important;font-weight:bold;">' + enc(v) + '</span>'; }
            }
        ],
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

    function updateMainButtons() {
        mainBtnEnable('#addData');
        if (dataRecord && !(dataRecord.paid_amount > VEPS)) {       // hanya invoice yang belum dibayar boleh diubah / dihapus
            mainBtnEnable('#editData');
            mainBtnEnable('#deleteData');
        } else {
            mainBtnDisable('#editData');
            mainBtnDisable('#deleteData');
        }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Panel detail (pembayaran yang masuk + aksi)
    // ------------------------------------------------------------
    function loadDetailPanel(id) {
        ajax({ task: 'load_invoice', id: id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail invoice.') + '</div>';
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
        var h = d.header, pays = d.payments || [];
        var st = h.remaining <= VEPS ? 'Lunas' : (h.paid_amount > VEPS ? 'Sebagian' : 'Belum Bayar');
        var html = '<h3 class="detail-title">Invoice: ' + enc(h.inv_no) + ' <span style="color:' + statusColor(st) + ';">(' + st + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Client: <b>' + enc(h.client_name || '-') + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Termin: <b>' + enc(h.termin_name) + '</b>' + (h.percentage > 0 ? ' (' + h.percentage + '% kontrak)' : '') +
                   ' &nbsp;|&nbsp; Tanggal: <b>' + enc(h.invoice_date) + '</b> &nbsp;|&nbsp; Jatuh tempo: <b>' + enc(h.due_date || '-') + '</b>' +
                   ' &nbsp;|&nbsp; Faktur pajak: <b>' + (h.ppn_amount > 0 ? (h.faktur_pajak_no ? enc(h.faktur_pajak_no) : '<span style="color:#e6a817;">belum diisi</span>') : 'tanpa PPN') + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Keterangan</th><th align="right">Jumlah (IDR)</th></tr></thead><tbody>' +
                   '<tr class="data-row"><td>DPP</td><td align="right">' + fmtAmt(h.dpp_amount) + '</td></tr>';
        if (h.ppn_amount > 0) html += '<tr class="data-row"><td>PPN Keluaran ' + h.ppn_rate + '%</td><td align="right">' + fmtAmt(h.ppn_amount) + '</td></tr>';
        html += '<tr class="data-row"><td><b>Total Tagihan</b></td><td align="right" class="subtotal-val"><b>' + fmtAmt(h.amount) + '</b></td></tr>';
        Ext.each(pays, function (p) {
            html += '<tr class="data-row"><td style="color:#2e9e4f;">Pembayaran ' + enc(p.payment_no) + ' (' + enc(p.payment_date) + ')</td><td align="right" style="color:#2e9e4f;">- ' + fmtAmt(p.amount_paid) + '</td></tr>';
        });
        html += '<tr class="data-row"><td><b>Sisa Tagihan</b></td><td align="right" class="subtotal-val"><b>' + fmtAmt(h.remaining) + '</b></td></tr></tbody></table>';

        html += '<div style="margin-top:15px; text-align:right;">';
        if (h.ppn_amount > 0) html += '<button type="button" class="tiActBtn" data-act="faktur" style="margin-left:8px; padding:8px 16px; background:#8e6b22; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Faktur Pajak</button>';
        html += '<button type="button" class="tiActBtn" data-act="print" style="margin-left:8px; padding:8px 16px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Cetak Invoice</button></div>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var btns = dtlDiv.querySelectorAll('.tiActBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                if (this.getAttribute('data-act') === 'print') printInvoice(h.id); else editFaktur(h);
            };
        }
    }

    function printInvoice(id) {
        window.open('../dev/pdf/project.invoice.php?id=' + id, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    }

    // Isi / ubah faktur pajak keluaran
    function editFaktur(h) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses.'); return; }
        var fNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', maxLength: 30, value: h.faktur_pajak_no || '' });
        var fDt = new Ext.form.DateField({
            fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true,
            value: h.faktur_pajak_date ? Date.parseDate(h.faktur_pajak_date, 'Y-m-d') : ''
        });
        var win = new Ext.Window({
            title: 'Faktur Pajak - ' + h.inv_no, width: 420, height: 230, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 120, bodyStyle: 'padding:12px;',
                items: [{ xtype: 'displayfield', hideLabel: true,
                          value: 'PPN Keluaran <b>' + fmtAmt(h.ppn_amount) + '</b> (DPP ' + fmtAmt(h.dpp_amount) +
                                 '). Tanggal faktur menentukan masa pajak di Laporan PPN. Kosongkan nomor untuk menghapus.' }, fNo, fDt]
            }),
            buttons: [
                { text: 'Simpan', handler: function () {
                    var no = fNo.getValue(), dt = fDt.getValue();        // baca nilai sebelum win.close()
                    ajax({ task: 'update_faktur_pajak', id: h.id, faktur_pajak_no: no, faktur_pajak_date: ymd(dt) }, function (res) {
                        Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                        if (res.success) { win.close(); mainDs.load(); loadDetailPanel(h.id); }
                    });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    // ------------------------------------------------------------
    // 4. Jendela terbitkan / ubah invoice
    // ------------------------------------------------------------
    function openEditor(rec) {                        // rec null = invoice baru
        var editing = !!rec;
        var dueManual = editing && !!rec.due_date;     // jatuh tempo diubah manual / dimuat dari invoice -> jangan ditimpa TOP

        var fClient = new myComboField({
            fieldLabel: 'Client', store: clientDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%', disabled: editing,
            listeners: { select: function () {                          // proyek hanya milik client terpilih (filterBy: cocok persis, bukan awalan)
                var cid = String(fClient.getValue());
                projectDs.clearFilter();
                projectDs.filterBy(function (r) { return String(r.get('client_id')) === cid; });
                fProject.clearValue(); refresh();
            } }
        });
        var fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%', disabled: editing,
            listeners: { select: function () { recalcFromPct(); refresh(); } }
        });
        var fName = new myTextField({ fieldLabel: 'Nama Termin', anchor: '95%', allowBlank: false, value: editing ? rec.termin_name : '' });
        var fDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal Invoice', format: 'd/m/Y', width: 130, allowBlank: false,
            value: editing ? Date.parseDate(rec.invoice_date, 'Y-m-d') : new Date(),
            listeners: { select: function () { syncDue(); }, change: function () { syncDue(); } }
        });
        var fTop = new myNumberField({
            fieldLabel: 'TOP (hari)', decimalPrecision: 0, allowNegative: false, width: 70,
            value: (editing && rec.due_date) ? Math.round((Date.parseDate(rec.due_date, 'Y-m-d') - Date.parseDate(rec.invoice_date, 'Y-m-d')) / 86400000) : TOP_DEFAULT_DAYS,
            listeners: { change: function () { dueManual = false; syncDue(); } }
        });
        var fDue = new Ext.form.DateField({
            fieldLabel: 'Jatuh Tempo', format: 'd/m/Y', width: 130, allowBlank: true,
            value: editing && rec.due_date ? Date.parseDate(rec.due_date, 'Y-m-d') : '',
            listeners: { select: function () { dueManual = true; }, change: function () { dueManual = !!fDue.getValue(); } }
        });
        var fPct = new myNumberField({
            fieldLabel: '% dari Kontrak', decimalPrecision: 2, allowNegative: false, width: 90,
            value: editing && rec.percentage > 0 ? rec.percentage : '',
            listeners: { change: function () { recalcFromPct(); refresh(); } }
        });
        var fAmt = new myNumberField({
            fieldLabel: 'Nilai Invoice (DPP)', decimalPrecision: 2, allowNegative: false, width: 160, value: editing ? rec.dpp_amount : 0,
            listeners: { change: function () { refresh(); } }
        });
        var fPpnOn = new Ext.form.Checkbox({
            fieldLabel: 'Kenakan PPN', boxLabel: 'PPN Keluaran', checked: editing ? rec.ppn_amount > 0 : true,
            listeners: { check: function () { fPpnRate.setDisabled(!fPpnOn.getValue()); refresh(); } }
        });
        var fPpnRate = new myNumberField({
            fieldLabel: 'Tarif PPN (%)', decimalPrecision: 2, width: 90, allowNegative: false,
            value: editing && rec.ppn_rate > 0 ? rec.ppn_rate : PPN_DEFAULT_RATE, disabled: editing ? !(rec.ppn_amount > 0) : false,
            listeners: { change: function () { refresh(); } }
        });
        var fFpNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', anchor: '95%', emptyText: 'opsional, bisa diisi setelah terbit', value: editing ? (rec.faktur_pajak_no || '') : '' });
        var fFpDate = new Ext.form.DateField({
            fieldLabel: 'Tgl Faktur Pajak', format: 'd/m/Y', width: 130, allowBlank: true,
            value: editing && rec.faktur_pajak_date ? Date.parseDate(rec.faktur_pajak_date, 'Y-m-d') : ''
        });
        var fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%', value: editing ? (rec.remark || '') : '' });
        var fInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        function curProject() { return fProject.getValue() ? projectDs.getById(fProject.getValue()) : null; }
        function curRate() { return fPpnOn.getValue() ? (parseFloat(fPpnRate.getValue()) || 0) : 0; }
        function syncDue() {
            if (dueManual) return;
            var d = fDate.getValue(), n = parseInt(fTop.getValue(), 10);
            if (d && n >= 0) fDue.setValue(d.add(Date.DAY, n)); else fDue.setValue('');
        }
        function recalcFromPct() {                    // nilai kontrak x persen -> DPP
            var p = curProject(), pct = parseFloat(fPct.getValue()) || 0;
            if (p && p.get('contract_value') > 0 && pct > 0) fAmt.setValue(round2(p.get('contract_value') * pct / 100));
        }
        function refresh() {
            var p = curProject(), dpp = round2(parseFloat(fAmt.getValue()) || 0), r = curRate(), ppn = round2(dpp * r / 100);
            var msg = '';
            if (p) {
                var cv = p.get('contract_value'), billed = p.get('billed_dpp') - (editing ? (rec.dpp_amount || 0) : 0);
                msg += 'Nilai kontrak <b>' + fmtAmt(cv) + '</b> | sudah ditagih (DPP) <b>' + fmtAmt(billed) + '</b> | sisa kontrak <b>' + fmtAmt(Math.max(cv - billed - dpp, 0)) + '</b>';
                if (cv > 0 && billed + dpp > cv + VEPS) msg += ' <span style="color:#ff6b5e;">(melebihi nilai kontrak)</span>';
                msg += '<br/>';
            }
            msg += 'DPP <b>' + fmtAmt(dpp) + '</b> &nbsp;|&nbsp; PPN ' + r + '% <b>' + fmtAmt(ppn) + '</b> &nbsp;|&nbsp; Total tagihan <b>' + fmtAmt(round2(dpp + ppn)) + '</b>';
            fInfo.setValue('<span style="color:#9fc3d1;">' + msg + '</span>');
        }

        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 115, bodyStyle: 'padding:0 6px;', items: items }; };
        var win = new Ext.Window({
            title: editing ? 'Ubah Invoice ' + rec.inv_no : 'Terbitkan Invoice ke Client', width: 880, height: 440, modal: true, layout: 'fit',
            items: new Ext.form.FormPanel({
                border: false, layout: 'column', bodyStyle: 'padding:10px 4px;',
                items: [
                    col(0.5, [fClient, fProject, fName, fDate, fTop, fDue, fRemark]),
                    col(0.5, [fPct, fAmt, fPpnOn, fPpnRate, fFpNo, fFpDate, fInfo])
                ]
            }),
            buttons: [
                { text: editing ? 'Simpan Perubahan' : 'Terbitkan Invoice', handler: function () {
                    if (!fProject.getValue()) { showErr('Proyek wajib dipilih.'); return; }
                    if (!fName.getValue()) { showErr('Nama termin wajib diisi.'); return; }
                    if (!fDate.getValue()) { showErr('Tanggal invoice wajib diisi.'); return; }
                    if (!(parseFloat(fAmt.getValue()) > 0)) { showErr('Nilai invoice (DPP) harus lebih dari 0.'); return; }
                    if (fDue.getValue() && fDue.getValue() < fDate.getValue()) { showErr('Jatuh tempo tidak boleh lebih awal dari tanggal invoice.'); return; }
                    if (fPpnOn.getValue() && !(curRate() > 0 && curRate() <= 100)) { showErr('Tarif PPN harus lebih dari 0 dan maksimal 100%.'); return; }
                    var p = {
                        task: 'save_invoice', id: editing ? rec.id : 0, project_id: fProject.getValue(), client_id: fClient.getValue() || (editing ? rec.client_id : 0),
                        termin_name: fName.getValue(), invoice_date: ymd(fDate.getValue()), due_date: ymd(fDue.getValue()),
                        percentage: fPct.getValue(), amount: fAmt.getValue(), ppn_rate: curRate(),
                        faktur_pajak_no: fFpNo.getValue(), faktur_pajak_date: ymd(fFpDate.getValue()), remark: fRemark.getValue()
                    };
                    ajax(p, function (res) {
                        if (res.success) {
                            win.close();
                            Ext.Msg.alert('Sukses', res.message || 'Invoice tersimpan.');
                            projectDs.load();            // total DPP yang sudah ditagih berubah
                            mainDs.load();
                            if (res.data && res.data.id) { dataRecord = { id: res.data.id, paid_amount: 0 }; updateMainButtons(); loadDetailPanel(res.data.id); }
                        } else {
                            showErr(res.message || 'Terjadi kesalahan sistem.');
                        }
                    });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();

        // pasang nilai awal setelah master (client & proyek) termuat
        function applyInitial() {
            projectDs.clearFilter();
            if (editing) {
                fClient.setValue(rec.client_id);
                fProject.setValue(rec.project_id);
            }
            if (!editing) syncDue();
            refresh();
        }
        clientDs.load({ callback: function () { projectDs.load({ callback: applyInitial }); } });
    }

    // ------------------------------------------------------------
    // 5. Handler tombol template
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menerbitkan invoice.'); return; }
        openEditor(null);
    };
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (dataRecord.paid_amount > VEPS) { Ext.Msg.alert('Peringatan', 'Invoice yang sudah ada pembayaran tidak dapat diubah.'); return; }
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengubah invoice.'); return; }
        openEditor(dataRecord);
    };
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        if (dataRecord.paid_amount > VEPS) { Ext.Msg.alert('Peringatan', 'Invoice yang sudah ada pembayaran tidak dapat dihapus.'); return; }
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus invoice.'); return; }
        Ext.Msg.confirm('Hapus Invoice', 'Hapus invoice <b>' + enc(dataRecord.inv_no) + '</b>? Jurnal penagihannya ikut dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'delete_invoice', id: dataRecord.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) {
                    dataRecord = null;
                    dtlDiv.style.display = 'none';
                    projectDs.load();
                    mainDs.load();
                    updateMainButtons();
                }
            });
        });
    };
    document.getElementById('printData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih invoice terlebih dahulu dari tabel.'); return; }
        printInvoice(dataRecord.id);
    };
    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };
    }

    // ------------------------------------------------------------
    // 6. Data awal
    // ------------------------------------------------------------
    clientDs.load({ callback: function () {
        var sel = document.getElementById('tiClient');
        clientDs.each(function (r) {
            var o = document.createElement('option');
            o.value = r.get('id'); o.text = r.get('name');
            sel.appendChild(o);
        });
    } });
    document.getElementById('editorWdw').style.display = 'none';

    loadMain();
});