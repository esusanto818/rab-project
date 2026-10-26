/*
  Document      : po.js
  Description   : Purchase Order. Grid utama = daftar PO; klik baris -> panel detail di bawah grid
                   (item, qty diterima, nilai dalam mata uang PO / anggaran, tombol aksi status).
                   Add/Edit membuka editor (header + grid item).
                   MULTI CURRENCY: satu PO = satu mata uang (mis. IDR untuk beli lokal, USD untuk impor).
                   Anggaran proyek punya mata uang sendiri (mis. USD). Nilai PO dikonversi ke mata uang
                   anggaran (nilai x kurs PO / kurs anggaran) lalu dibatasi:
                     - per baris RAB : qty <= kuota, nilai <= sisa nilai RAB
                     - total proyek  : total PO <= total RAB (dan <= budget proyek bila diisi)
                   Menerima draft dari Purchase Planning lewat sessionStorage 'po_draft_from_planning'.
                   Alur status: Draft -> Diajukan -> Disetujui -> Selesai (Dibatalkan dari Draft/Diajukan/Disetujui
                   selama belum ada GRN). Edit & Delete hanya untuk PO berstatus Draft.
  Backend       : /rab-project/dev/php-crud/poCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Purchase <em>Order</em>";

    var CRD = main_url + '/php-crud/poCRD.php';

    var ACCESS_ADD = 2;      // level akses minimal: buat PO
    var ACCESS_EDIT = 3;     // ubah / hapus / ganti status PO
    var EPS = 0.0001;        // toleransi qty
    var VEPS = 0.01;         // toleransi nilai

    var dataRecord = null;         // baris PO terpilih di grid utama
    var editingId = 0;             // 0 = PO baru, >0 = edit PO tsb
    var hdrForm = null, budForm = null;
    var fDate, fProject, fSupplier, fCurrency, fRate, fRateInfo, fNotes, fBudRate, fBudInfo;
    var rateManual = false;        // kurs PO diubah manual / dimuat dari PO tersimpan -> jangan ditimpa otomatis
    var budRateManual = false;     // idem untuk kurs mata uang anggaran
    var rateReq = 0, budRateReq = 0;   // penanda request terbaru (abaikan respons usang)
    // Info anggaran proyek terpilih (dari list_rab_items.summary)
    var budgetInfo = { id: null, code: '', isBase: true, baseCode: 'IDR', totalLimit: 0, totalCommitted: 0, ceiling: null };

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round2(v) { return Math.round((parseFloat(v) || 0) * 100) / 100; }
    function round3(v) { return Math.round((parseFloat(v) || 0) * 1000) / 1000; }

    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diajukan': return '#e6a817 !important';
            case 'Disetujui': return '#2bc1e2 !important';
            case 'Selesai': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
    }

    var ACTION_LABEL = {
        Diajukan: 'Ajukan', Disetujui: 'Setujui', Draft: 'Kembalikan ke Draft',
        Selesai: 'Tutup PO (Selesai)', Dibatalkan: 'Batalkan PO'
    };

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { Ext.Msg.alert('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () {
                if (onFail) onFail(); else Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');

    var GridMainHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var DtlHeight = Math.round(GridMainHeight-(GridMainHeight * 0.6)-30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'poDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';

dtlDiv.style.maxHeight = DtlHeight;
dtlDiv.style.overflowY = 'auto';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetailPanel(id) {
        ajax({ task: 'load_po', id: id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail PO.') + '</div>';
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
        var h = d.header, items = d.items || [], grand = 0;
        var cur = h.currency_code || 'IDR';
        var bud = h.budget_code || cur;
        var sameCur = (!h.budget_currency_id) || (h.budget_currency_id === h.currency_id) || (bud === cur);

        var html = '<h3 class="detail-title">Detail PO: ' + enc(h.po_number) +
                   ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">Tanggal: <b>' + enc(h.po_date) + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Supplier: <b>' + enc(h.supplier_name || '-') + '</b> &nbsp;|&nbsp; Mata uang PO: <b>' + enc(cur) +
                   '</b> (kurs ' + fmtAmt(h.exch_rate) + ')' +
                   (sameCur ? '' : ' &nbsp;|&nbsp; Mata uang anggaran: <b>' + enc(bud) + '</b> (kurs ' + fmtAmt(h.budget_exch_rate) + ')') +
                   (h.notes ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.notes) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Material</th>' +
                   '<th align="right">Qty PO</th>' +
                   '<th align="right">Qty Diterima</th>' +
                   '<th align="right">Harga Satuan (' + enc(cur) + ')</th>' +
                   '<th align="right">Subtotal (' + enc(cur) + ')</th>' +
                   (sameCur ? '' : '<th align="right">Setara ' + enc(bud) + '</th>') +
                   '</tr></thead><tbody>';

        if (!items.length) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>';
        }
        Ext.each(items, function (i) {
            var sub = (parseFloat(i.qty) || 0) * (parseFloat(i.unit_price) || 0);
            grand += sub;
            var recvColor = i.qty_received >= i.qty - EPS ? '#2e9e4f' : (i.qty_received > 0 ? '#e6a817' : '#668894');
            html += '<tr class="data-row">' +
                    '<td align="left">' + enc(i.description) + ' <span style="color:#668894;">(' + enc(i.material_code || '') + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right" style="color:' + recvColor + ';">' + fmtQty(i.qty_received) + '</td>' +
                    '<td align="right">' + fmtAmt(i.unit_price) + '</td>' +
                    '<td align="right" class="subtotal-val">' + fmtAmt(sub) + '</td>' +
                    (sameCur ? '' : '<td align="right" style="color:#9fc3d1;">' + fmtAmt(i.amount_budget) + '</td>') +
                    '</tr>';
        });
        html += '<tr class="data-row"><td colspan="4" align="right"><b>Total (' + enc(cur) + ')</b></td>' +
                '<td align="right" class="subtotal-val"><b>' + fmtAmt(grand) + '</b></td>' +
                (sameCur ? '' : '<td align="right" class="subtotal-val"><b>' + fmtAmt(h.total_budget) + ' ' + enc(bud) + '</b></td>') + '</tr>';
        html += '</tbody></table>';

        // Tombol aksi status
        var actions = d.allowed_actions || [];
        if (actions.length) {
            html += '<div style="margin-top:15px; text-align:right;">';
            Ext.each(actions, function (to) {
                var bg = to === 'Dibatalkan' ? '#8e2b22' : (to === 'Draft' ? '#555555' : '#03615e');
                html += '<button type="button" class="poActionBtn" data-to="' + enc(to) + '" style="margin-left:8px; padding:8px 16px; background:' + bg +
                        '; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">' + enc(ACTION_LABEL[to] || to) + '</button>';
            });
            html += '</div>';
        }

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var btns = dtlDiv.querySelectorAll('.poActionBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () { changeStatus(h.id, h.po_number, this.getAttribute('data-to')); };
        }
    }

    function changeStatus(id, poNumber, to) {
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengubah status PO.');
            return;
        }
        Ext.Msg.confirm('Ubah Status PO',
            'Ubah status PO <b>' + enc(poNumber) + '</b> menjadi <b>' + enc(to) + '</b>?',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'change_status', id: id, to: to }, function (res) {
                    Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) {
                        mainDs.load();
                        if (dataRecord && dataRecord.id === id) dataRecord.status = to;
                        updateMainButtons();
                        loadDetailPanel(id);
                    }
                });
            });
    }

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var poField = [
        { name: 'id' }, { name: 'po_number' }, { name: 'po_date' },
        { name: 'project_id' }, { name: 'project_name' },
        { name: 'supplier_id' }, { name: 'supplier_name' },
        { name: 'currency_id' }, { name: 'currency_code' }, { name: 'exch_rate', type: 'float' },
        { name: 'status' }, { name: 'notes' },
        { name: 'item_count', type: 'int' }, { name: 'total_amount', type: 'float' },
        { name: 'total_budget', type: 'float' }, { name: 'budget_code' }
    ];

    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'budget_currency_id' }, { name: 'budget_code' }, { name: 'budget_is_base' }])
    });
    var supplierDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_suppliers' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }])
    });
    var currencyDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_currencies' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'init' }, { name: 'name' }, { name: 'is_base' }])
    });

    // Baris RAB proyek terpilih + sisa kuota qty + sisa nilai (mata uang anggaran)
    var rabDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_rab_items' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'budget_item_id' }, [
            { name: 'budget_item_id' }, { name: 'material_id' }, { name: 'material_code' }, { name: 'material_name' },
            { name: 'unit' }, { name: 'qty_plan', type: 'float' }, { name: 'tolerance_pct', type: 'float' },
            { name: 'unit_price_plan', type: 'float' }, { name: 'qty_ordered', type: 'float' },
            { name: 'qty_remaining', type: 'float' }, { name: 'qty_quota', type: 'float' },
            { name: 'value_plan', type: 'float' }, { name: 'value_limit', type: 'float' },
            { name: 'value_committed', type: 'float' }, { name: 'value_remaining', type: 'float' },
            { name: 'label', convert: function (v, r) {
                return r.material_name + ' (' + r.material_code + ') - sisa RAB ' + round3(r.qty_remaining) + ' ' + r.unit;
            } }
        ])
    });

    var itemField = [
        { name: 'budget_item_id' }, { name: 'material_id' }, { name: 'material_code' }, { name: 'material_name' },
        { name: 'unit' }, { name: 'qty', type: 'float' }, { name: 'unit_price', type: 'float' },
        { name: 'subtotal', type: 'float' }, { name: 'amount_budget', type: 'float' },
        { name: 'quota', type: 'float' }, { name: 'value_remaining', type: 'float' },
        { name: 'rab_price', type: 'float' }, { name: 'price_manual' }, { name: 'flag' }
    ];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_pos' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, poField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar PO)
    // ------------------------------------------------------------
    var GridMainHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. PO', dataIndex: 'po_number', width: 130 },
            { header: 'Tanggal', dataIndex: 'po_date', width: 90, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 220, renderer: CellWrap },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 180, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 80, align: 'center', renderer: function (v) { return v || 'IDR'; } },
            { header: 'Item', dataIndex: 'item_count', width: 50, align: 'center' },
            { header: 'Total', dataIndex: 'total_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Setara Anggaran', dataIndex: 'total_budget', width: 150, align: 'right',
                renderer: function (v, meta, rec) { return fmtAmt(v) + ' ' + enc(rec.get('budget_code') || ''); }
            },
            {
                header: 'Status', dataIndex: 'status', width: 95, align: 'center',
                renderer: function (v) {
                    return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>';
                }
            }
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

    function updateMainButtons() {
        mainBtnEnable('#addData');
        if (dataRecord && dataRecord.status === 'Draft') {
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
    // 3. Editor PO: header (form) + item grid
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 300,
        columns: [
            {
                header: 'Material (RAB proyek)', dataIndex: 'budget_item_id', width: 230,
                editor: new myComboField({
                    store: rabDs, displayField: 'label', valueField: 'budget_item_id',
                    mode: 'local', triggerAction: 'all', forceSelection: true,
                    listeners: {
                        select: function (combo, record) {
                            var row = itemGrid.getSelectionModel().getSelected();
                            if (row) {
                                row.set('budget_item_id', record.get('budget_item_id'));
                                row.set('material_id', record.get('material_id'));
                                row.set('material_code', record.get('material_code'));
                                row.set('material_name', record.get('material_name'));
                                row.set('unit', record.get('unit'));
                                row.set('rab_price', record.get('unit_price_plan'));    // harga RAB (mata uang anggaran)
                                row.set('price_manual', false);                         // harga default = RAB dikonversi
                                if (!(parseFloat(row.get('qty')) > 0)) row.set('qty', record.get('qty_remaining'));
                                onRatesChanged();
                            }
                        }
                    }
                }),
                renderer: function (v, meta, record) {
                    if (record && record.get('material_name')) {
                        return enc(record.get('material_name')) + ' <span style="color:#668894;">(' + enc(record.get('material_code')) + ')</span>';
                    }
                    return '<i>-- pilih material --</i>';
                }
            },
            { header: 'Satuan', dataIndex: 'unit', width: 50, align: 'center' },
            {
                header: 'Sisa Kuota Qty', dataIndex: 'quota', width: 90, align: 'right',
                renderer: function (v, meta, record) { return record.get('budget_item_id') ? fmtQty(v) : ''; }
            },
            {
                header: 'Sisa Nilai RAB', dataIndex: 'value_remaining', width: 110, align: 'right',
                renderer: function (v, meta, record) { return record.get('budget_item_id') ? fmtAmt(v) : ''; }
            },
            {
                header: 'Qty PO', dataIndex: 'qty', width: 80, align: 'right',
                editor: new myNumberField({ decimalPrecision: 3 }),
                renderer: function (v) { return fmtQty(v); }
            },
            {
                header: 'Harga Satuan', dataIndex: 'unit_price', width: 100, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return fmtAmt(v); }
            },
            {
                header: 'Subtotal', dataIndex: 'subtotal', width: 110, align: 'right',
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            },
            {
                header: 'Setara Anggaran', dataIndex: 'amount_budget', width: 110, align: 'right',
                renderer: function (v) { return '<span style="color:#9fc3d1;">' + fmtAmt(v) + '</span>'; }
            },
            {
                header: 'Status', dataIndex: 'flag', width: 85, align: 'center',
                renderer: function (v) {
                    if (!v) return '';
                    return '<span style="color:' + (v === 'OK' ? '#00ff4c' : '#c0392b') + ' !important;font-weight:bold;">' + enc(v) + '</span>';
                }
            },
            {
                id: 'poDeleteCol', header: '', dataIndex: 'budget_item_id', width: 32, align: 'center', fixed: true,
                renderer: function () {
                    return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>';
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'unit_price') e.record.set('price_manual', true);       // harga diubah user -> jangan ditimpa konversi
                if (e.field === 'qty' || e.field === 'unit_price' || e.field === 'budget_item_id') recalcAll();
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'poDeleteCol') {
                    grid.getStore().removeAt(rowIndex);
                    recalcAll();
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Tempat form header + tombol tambah baris + ringkasan total
    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('poHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'poHdrForm';
            hdr.style.cssText = 'margin-bottom:2px;';
            g.parentNode.insertBefore(hdr, g);

            var hdr2 = document.createElement('div');
            hdr2.id = 'poBudForm';
            hdr2.style.cssText = 'margin-bottom:8px;';
            g.parentNode.insertBefore(hdr2, g);

            var btn = document.createElement('button');
            btn.id = 'btnAddPoRow';
            btn.type = 'button';
            btn.innerHTML = '+ Tambah Material';
            btn.style.cssText = 'margin-bottom:6px; padding:5px 12px; background:#2e6da4; color:#fff; border:none; border-radius:3px; cursor:pointer;';
            btn.onclick = addBlankRow;
            g.parentNode.insertBefore(btn, g);

            var totalsDiv = document.createElement('div');
            totalsDiv.style.cssText = 'padding:10px 14px; margin-top:8px; font-weight:bold; font-size:14px; text-align:right; background:#1f2d3a; color:#ffffff !important; border-radius:4px;';
            totalsDiv.innerHTML = '<div>Total PO: <span id="lblPoTotal" style="color:#ffffff !important;">0.00</span></div>' +
                                  '<div id="lblPoBudget" style="font-size:12px; font-weight:normal; margin-top:4px;"></div>';
            g.parentNode.insertBefore(totalsDiv, g.nextSibling);
        }
    })();

    // Form header dibuat saat editor dibuka pertama kali (container hidden -> lebar kolom 0 bila dirender lebih awal)
    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); budForm.doLayout(); return; }

        fDate = new Ext.form.DateField({
            fieldLabel: 'Tanggal PO', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130,
            listeners: {
                select: function () { onDateChange(); },
                change: function () { onDateChange(); }
            }
        });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fSupplier = new myComboField({
            fieldLabel: 'Supplier', store: supplierDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, anchor: '95%'
        });
        fCurrency = new myComboField({
            fieldLabel: 'Mata Uang PO', store: currencyDs, displayField: 'init', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, width: 100,
            listeners: { select: function () { onCurrencyChange(); } }
        });
        fRate = new myNumberField({
            fieldLabel: 'Kurs PO', decimalPrecision: 4, value: 1, width: 110,
            listeners: { change: function () { rateManual = true; onRatesChanged(); } }      // diubah user -> jangan ditimpa lagi
        });
        fRateInfo = new Ext.form.DisplayField({ fieldLabel: 'Sumber Kurs', value: '-', anchor: '95%' });
        fNotes = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 100, bodyStyle: 'background:transparent;', items: items };
        };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'poHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.34, [fDate, fProject]), col(0.34, [fSupplier, fCurrency]), col(0.32, [fRate, fRateInfo, fNotes])]
        });

        // Baris anggaran: kurs mata uang anggaran (hanya bila diperlukan) + keterangan konversi
        fBudRate = new myNumberField({
            fieldLabel: 'Kurs Anggaran', decimalPrecision: 4, value: 1, width: 110,
            listeners: { change: function () { budRateManual = true; onRatesChanged(); } }
        });
        fBudInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });
        budForm = new Ext.form.FormPanel({
            renderTo: 'poBudForm',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:0 2px 4px 2px;',
            items: [col(0.34, [fBudRate]), col(0.66, [fBudInfo])]
        });
        setBudRateVisible(false);
    }

    function setBudRateVisible(v) {
        if (!fBudRate || !fBudRate.getEl()) return;
        var item = fBudRate.getEl().up('.x-form-item');
        if (item) item.setDisplayed(v);
    }

    // ---- Mata uang & kurs --------------------------------------------------
    // Mata uang dasar dari Pengaturan Sistem (is_base)
    function baseCurrencyId() {
        var id = null;
        currencyDs.each(function (r) { if (id === null && r.get('is_base') === true) id = r.get('id'); });
        return id;
    }
    function baseCurrencyCode() {
        var c = 'IDR';
        currencyDs.each(function (r) { if (r.get('is_base') === true) c = r.get('init'); });
        return c;
    }
    function currencyCode(id) {
        var r = id ? currencyDs.getById(id) : null;
        return r ? r.get('init') : baseCurrencyCode();
    }
    // Mata uang PO yang berlaku (kosong = mata uang dasar)
    function poCurId() { return Number((fCurrency && fCurrency.getValue()) || baseCurrencyId()) || null; }
    function isBaseCurrency(id) {
        var r = id ? currencyDs.getById(id) : null;
        return !!r && r.get('is_base') === true;
    }
    function poRate() { return isBaseCurrency(poCurId()) ? 1 : (parseFloat(fRate.getValue()) || 1); }
    function needBudRate() {
        return !!budgetInfo.id && poCurId() !== budgetInfo.id && !budgetInfo.isBase;
    }
    function budRate() {
        if (!budgetInfo.id) return 1;
        if (poCurId() === budgetInfo.id) return poRate();
        if (budgetInfo.isBase) return 1;
        return parseFloat(fBudRate.getValue()) || 0;
    }
    // Faktor konversi nilai PO -> mata uang anggaran (0 = belum bisa dihitung)
    function factor() {
        if (!budgetInfo.id) return 1;
        if (poCurId() === budgetInfo.id) return 1;
        var rb = budRate();
        return rb > 0 ? poRate() / rb : 0;
    }

    function applyHeaders() {
        var cm = itemGrid.getColumnModel();
        var poCode = currencyCode(poCurId()), budCode = budgetInfo.code || poCode;
        cm.setColumnHeader(3, 'Sisa Nilai RAB (' + budCode + ')');
        cm.setColumnHeader(5, 'Harga Satuan (' + poCode + ')');
        cm.setColumnHeader(6, 'Subtotal (' + poCode + ')');
        cm.setColumnHeader(7, 'Setara ' + budCode);
        if (fBudRate && fBudRate.label) fBudRate.label.update('Kurs ' + budCode + ' *');
    }

    function describeConversion() {
        if (!budgetInfo.id) { fBudInfo.setValue(''); return; }
        var poCode = currencyCode(poCurId()), budCode = budgetInfo.code;
        if (poCurId() === budgetInfo.id) {
            fBudInfo.setValue('<span style="color:#668894;">Anggaran proyek dalam <b>' + enc(budCode) + '</b> - sama dengan mata uang PO, tanpa konversi.</span>');
        } else {
            var f = factor();
            fBudInfo.setValue('<span style="color:#9fc3d1;">Anggaran proyek dalam <b>' + enc(budCode) + '</b>. Nilai PO dikonversi: 1 ' + enc(poCode) + ' = ' +
                              (f > 0 ? Ext.util.Format.number(f, '0,000.000000') : '?') + ' ' + enc(budCode) + '</span>');
        }
    }

    // Kurs PO default = KMK pada tanggal PO; belum ada -> kurs terakhir. Tetap bisa diedit.
    function refreshRate() {
        var cur = poCurId(), dt = fDate.getValue();
        if (!cur || !dt) return;
        var token = ++rateReq;
        fRateInfo.setValue('<span style="color:#668894;">memuat kurs...</span>');
        ajax({ task: 'get_exchange_rate', currency_id: cur, date: dt.format('Y-m-d') }, function (res) {
            if (token !== rateReq) return;                       // sudah ada permintaan yang lebih baru
            if (!res.success) {
                fRateInfo.setValue('<span style="color:#c0392b;">' + enc(res.message || 'Gagal memuat kurs.') + '</span>');
                return;
            }
            var d = res.data || {};
            fRate.setReadOnly(d.source === 'BASE');
            if (d.rate) fRate.setValue(d.rate);
            if (d.source === 'BASE') {
                fRateInfo.setValue('<span style="color:#668894;">Mata uang dasar (' + enc(d.base_code || '') + ', kurs 1)</span>');
            } else if (d.source === 'periode') {
                fRateInfo.setValue('<span style="color:#2e9e4f;">KMK ' + enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else if (d.source === 'terakhir') {
                fRateInfo.setValue('<span style="color:#e6a817;">Belum ada kurs untuk tanggal PO, memakai kurs terakhir: KMK ' +
                                   enc(d.kmk_no) + ' (' + enc(d.start_date) + ' - ' + enc(d.end_date) + ')</span>');
            } else {
                fRateInfo.setValue('<span style="color:#e6a817;">Data kurs mata uang ini belum tersedia, isi manual.</span>');
            }
            onRatesChanged();
        });
    }

    // Kurs mata uang anggaran (hanya bila PO berbeda mata uang dengan anggaran dan anggaran bukan mata uang dasar)
    function refreshBudRate() {
        var need = needBudRate();
        setBudRateVisible(need);
        applyHeaders();
        if (!need) { describeConversion(); onRatesChanged(); return; }
        if (budRateManual) { describeConversion(); onRatesChanged(); return; }
        var dt = fDate.getValue();
        if (!dt) return;
        var token = ++budRateReq;
        fBudInfo.setValue('<span style="color:#668894;">memuat kurs ' + enc(budgetInfo.code) + '...</span>');
        ajax({ task: 'get_exchange_rate', currency_id: budgetInfo.id, date: dt.format('Y-m-d') }, function (res) {
            if (token !== budRateReq) return;
            var d = (res && res.data) || {};
            if (res.success && d.rate) fBudRate.setValue(d.rate);
            describeConversion();
            if (res.success && d.source === 'terakhir') {
                fBudInfo.setValue(fBudInfo.getValue() + ' <span style="color:#e6a817;">(kurs terakhir KMK ' + enc(d.kmk_no) + ')</span>');
            } else if (!res.success || !d.rate) {
                fBudInfo.setValue(fBudInfo.getValue() + ' <span style="color:#e6a817;">Kurs ' + enc(budgetInfo.code) + ' belum tersedia, isi manual.</span>');
            }
            onRatesChanged();
        });
    }

    function onDateChange() {
        if (!rateManual) refreshRate();
        if (!budRateManual) refreshBudRate();
    }

    function onCurrencyChange() {
        rateManual = false;
        budRateManual = false;
        refreshRate();
        refreshBudRate();
    }

    // Kurs / mata uang / material berubah: harga default (RAB dikonversi) dan semua nilai dihitung ulang
    function onRatesChanged() {
        describeConversion();
        repriceDefaults();
        recalcAll();
    }

    // Harga satuan default = harga RAB (mata uang anggaran) dikonversi ke mata uang PO; baris yang harganya
    // sudah diubah manual tidak disentuh.
    function repriceDefaults() {
        var f = factor();
        if (!(f > 0)) return;
        itemDs.each(function (r) {
            if (r.get('budget_item_id') && r.get('price_manual') !== true) {
                r.set('unit_price', round2((parseFloat(r.get('rab_price')) || 0) / f));
            }
        });
    }

    function defaultCurrencyId() { return baseCurrencyId(); }

    function resetHdrForm() {
        fDate.setValue(new Date());
        fProject.clearValue();
        fSupplier.clearValue();
        fNotes.setValue('');
        var base = defaultCurrencyId();
        if (base !== null) fCurrency.setValue(base); else fCurrency.clearValue();
        fRate.setValue(1);
        fBudRate.setValue(1);
        rateManual = false;
        budRateManual = false;
        budgetInfo = { id: null, code: '', isBase: true, baseCode: baseCurrencyCode(), totalLimit: 0, totalCommitted: 0, ceiling: null };
        setBudRateVisible(false);
        applyHeaders();
        describeConversion();
        refreshRate();
    }

    // Muat baris RAB proyek (komitmen PO yang sedang diedit tidak dihitung) + ringkasan anggaran, lalu panggil cb
    function loadRab(projectId, cb) {
        if (!projectId) {
            rabDs.removeAll();
            budgetInfo = { id: null, code: '', isBase: true, baseCode: baseCurrencyCode(), totalLimit: 0, totalCommitted: 0, ceiling: null };
            applyHeaders();
            if (cb) cb();
            return;
        }
        rabDs.load({
            params: { project_id: projectId, po_id: editingId || 0 },
            callback: function () {
                var sm = (rabDs.reader.jsonData || {}).summary;
                if (sm) {
                    budgetInfo = {
                        id: Number(sm.budget_currency_id) || null, code: sm.budget_code, isBase: !!sm.budget_is_base, baseCode: sm.base_code || baseCurrencyCode(),
                        totalLimit: parseFloat(sm.total_limit) || 0, totalCommitted: parseFloat(sm.total_committed) || 0,
                        ceiling: sm.ceiling !== null && sm.ceiling !== undefined ? parseFloat(sm.ceiling) : null
                    };
                }
                applyHeaders();
                if (cb) cb();
            }
        });
    }

    function onProjectChange() {
        budRateManual = false;
        loadRab(fProject.getValue(), function () {
            itemDs.each(function (r) {      // material yang tidak ada di RAB proyek baru dikosongkan
                var b = r.get('budget_item_id');
                if (b && !rabDs.getById(b)) {
                    r.set('budget_item_id', null); r.set('material_id', null); r.set('material_code', '');
                    r.set('material_name', ''); r.set('unit', ''); r.set('qty', 0); r.set('unit_price', 0);
                    r.set('rab_price', 0); r.set('price_manual', false);
                } else if (b) {
                    r.set('rab_price', rabDs.getById(b).get('unit_price_plan'));
                }
            });
            refreshBudRate();
        });
    }

    // Subtotal, nilai setara anggaran, sisa kuota, flag per baris, dan ringkasan anggaran proyek
    function recalcAll() {
        var f = factor();
        var sumsQty = {}, sumsAmt = {};
        itemDs.each(function (r) {
            var b = r.get('budget_item_id');
            if (!b) return;
            var q = parseFloat(r.get('qty')) || 0;
            var amt = round2(round2(q * (parseFloat(r.get('unit_price')) || 0)) * f);
            sumsQty[b] = (sumsQty[b] || 0) + q;
            sumsAmt[b] = (sumsAmt[b] || 0) + amt;
        });

        var total = 0, totalBud = 0;
        itemDs.each(function (r) {
            var b = r.get('budget_item_id'), s = b ? rabDs.getById(b) : null;
            var q = parseFloat(r.get('qty')) || 0;
            var sub = round2(q * (parseFloat(r.get('unit_price')) || 0));
            var amt = round2(sub * f);
            r.set('subtotal', sub);
            r.set('amount_budget', amt);
            total += sub;
            totalBud += amt;
            if (s) {
                r.set('quota', s.get('qty_quota'));
                r.set('value_remaining', s.get('value_remaining'));
                var flag = 'OK';
                if (sumsQty[b] > s.get('qty_quota') + EPS) flag = 'OVER QTY';
                else if (sumsAmt[b] > s.get('value_remaining') + VEPS) flag = 'OVER NILAI';
                r.set('flag', flag);
            } else {
                r.set('flag', '');
            }
        });

        var poCode = currencyCode(poCurId());
        var el = document.getElementById('lblPoTotal');
        if (el) {
            var html = fmtAmt(total) + ' ' + enc(poCode);
            if (budgetInfo.id && poCurId() !== budgetInfo.id) {
                html += ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + fmtAmt(totalBud) + ' ' + enc(budgetInfo.code) + '</span>';
            }
            el.innerHTML = html;
        }
        updateBudgetBar(totalBud);
    }

    // Ringkasan anggaran proyek (mata uang anggaran): batas RAB, terpakai PO lain, PO ini, sisa
    function updateBudgetBar(totalBud) {
        var el = document.getElementById('lblPoBudget');
        if (!el) return;
        if (!budgetInfo.id || !fProject || !fProject.getValue()) { el.innerHTML = ''; return; }
        if (!(factor() > 0)) {
            el.innerHTML = '<span style="color:#e6a817;">Isi kurs ' + enc(budgetInfo.code) + ' agar nilai PO dapat dibandingkan dengan anggaran.</span>';
            return;
        }
        var code = enc(budgetInfo.code);
        var after = budgetInfo.totalCommitted + totalBud;
        var remain = budgetInfo.totalLimit - after;
        var overRab = remain < -VEPS;
        var overCeil = budgetInfo.ceiling !== null && after > budgetInfo.ceiling + VEPS;
        var color = (overRab || overCeil) ? '#ff6b5e' : '#7fe0a0';
        el.innerHTML = '<span style="color:' + color + ';">Anggaran RAB (' + code + '): batas ' + fmtAmt(budgetInfo.totalLimit) +
                       ' &nbsp;|&nbsp; PO lain ' + fmtAmt(budgetInfo.totalCommitted) +
                       ' &nbsp;|&nbsp; PO ini ' + fmtAmt(totalBud) +
                       ' &nbsp;|&nbsp; sisa ' + fmtAmt(remain) + (overRab ? ' (MELEBIHI RAB)' : '') +
                       (budgetInfo.ceiling !== null ? ' &nbsp;|&nbsp; budget proyek ' + fmtAmt(budgetInfo.ceiling) + (overCeil ? ' (MELEBIHI)' : '') : '') +
                       '</span>';
    }

    function newItemRecord(cfg) {
        var Rec = itemDs.recordType || Ext.data.Record.create(itemField);
        return new Rec(Ext.apply({
            budget_item_id: null, material_id: null, material_code: '', material_name: '', unit: '',
            qty: 0, unit_price: 0, subtotal: 0, amount_budget: 0, quota: 0, value_remaining: 0,
            rab_price: 0, price_manual: false, flag: ''
        }, cfg || {}));
    }

    function addBlankRow() {
        itemDs.add(newItemRecord());
        recalcAll();
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureHdrForm();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = PO baru
    function openNewPo(draft) {
        editingId = 0;
        showEditor('Purchase Order Baru');
        resetHdrForm();
        itemDs.removeAll();
        rabDs.removeAll();

        if (draft && draft.project_id) {
            fProject.setValue(draft.project_id);
            loadRab(draft.project_id, function () {
                Ext.each(draft.items || [], function (d) {
                    var s = rabDs.getById(d.budget_item_id);
                    if (!s) return;                                   // baris RAB sudah tidak ada
                    itemDs.add(newItemRecord({
                        budget_item_id: s.get('budget_item_id'), material_id: s.get('material_id'),
                        material_code: s.get('material_code'), material_name: s.get('material_name'),
                        unit: s.get('unit'), qty: parseFloat(d.qty) || 0,
                        rab_price: s.get('unit_price_plan'), price_manual: false,   // harga = harga RAB dikonversi ke mata uang PO
                        quota: s.get('qty_quota'), value_remaining: s.get('value_remaining')
                    }));
                });
                if (!itemDs.getCount()) addBlankRow();
                refreshBudRate();
            });
        } else {
            addBlankRow();
        }
    }

    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat PO.');
            return;
        }
        openNewPo(null);
    };

    // EDIT = ubah PO Draft
    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { Ext.Msg.alert('Peringatan', 'Pilih PO terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Draft') { Ext.Msg.alert('Peringatan', 'Hanya PO berstatus Draft yang dapat diubah.'); return; }
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengedit PO.');
            return;
        }
        ajax({ task: 'load_po', id: dataRecord.id }, function (res) {
            if (!res.success) { Ext.Msg.alert('Gagal', res.message || 'Gagal memuat PO.'); return; }
            var h = res.data.header;
            editingId = h.id;
            showEditor('Edit PO ' + h.po_number);

            fDate.setValue(Date.parseDate(h.po_date, 'Y-m-d') || new Date());
            fProject.setValue(h.project_id);
            if (h.supplier_id) fSupplier.setValue(h.supplier_id); else fSupplier.clearValue();
            if (h.currency_id) fCurrency.setValue(h.currency_id); else fCurrency.clearValue();
            fRate.setValue(h.exch_rate || 1);
            fRate.setReadOnly(isBaseCurrency(poCurId()));
            fBudRate.setValue(h.budget_exch_rate || 1);
            rateManual = true;                                   // kurs tersimpan di PO tidak ditimpa otomatis
            budRateManual = true;
            fRateInfo.setValue('<span style="color:#668894;">Kurs tersimpan pada PO (ganti mata uang untuk ambil ulang)</span>');
            fNotes.setValue(h.notes || '');

            itemDs.removeAll();
            loadRab(h.project_id, function () {
                Ext.each(res.data.items, function (i) {
                    var s = i.budget_item_id ? rabDs.getById(i.budget_item_id) : null;
                    itemDs.add(newItemRecord({
                        budget_item_id: i.budget_item_id, material_id: i.material_id,
                        material_code: i.material_code || '', material_name: i.description, unit: i.unit || '',
                        qty: i.qty, unit_price: i.unit_price,
                        rab_price: s ? s.get('unit_price_plan') : 0, price_manual: true,    // harga tersimpan tidak ditimpa
                        quota: s ? s.get('qty_quota') : 0, value_remaining: s ? s.get('value_remaining') : 0
                    }));
                });
                if (!itemDs.getCount()) addBlankRow();
                setBudRateVisible(needBudRate());
                applyHeaders();
                describeConversion();
                recalcAll();
            });
        });
    };

    // SAVE
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue()) { Ext.Msg.alert('Peringatan', 'Proyek wajib dipilih.'); return; }
        if (!fDate.getValue()) { Ext.Msg.alert('Peringatan', 'Tanggal PO wajib diisi.'); return; }
        if (needBudRate() && !(parseFloat(fBudRate.getValue()) > 0)) {
            Ext.Msg.alert('Peringatan', 'Kurs ' + budgetInfo.code + ' (mata uang anggaran) wajib diisi.');
            return;
        }

        var arr = [], err = '';
        itemDs.each(function (r) {
            var b = r.get('budget_item_id'), q = parseFloat(r.get('qty')) || 0, n = 'Baris ' + (itemDs.indexOf(r) + 1);
            if (!b && q <= 0) return;                               // baris kosong diabaikan
            if (!b || q <= 0) { err = err || (n + ': material dan qty (> 0) wajib diisi.'); return; }
            if (r.get('flag') === 'OVER QTY') { err = err || (n + ': qty melebihi sisa kuota RAB.'); return; }
            if (r.get('flag') === 'OVER NILAI') { err = err || (n + ': nilai melebihi sisa anggaran RAB baris ini.'); return; }
            arr.push({ budget_item_id: b, qty: q, unit_price: parseFloat(r.get('unit_price')) || 0 });
        });
        if (err) { Ext.Msg.alert('Peringatan', err); return; }
        if (!arr.length) { Ext.Msg.alert('Peringatan', 'Isi minimal satu material.'); return; }

        // Total proyek (anggaran RAB & budget proyek)
        var totalBud = 0;
        itemDs.each(function (r) { totalBud += parseFloat(r.get('amount_budget')) || 0; });
        var after = budgetInfo.totalCommitted + totalBud;
        if (after > budgetInfo.totalLimit + VEPS) {
            Ext.Msg.alert('Peringatan', 'Total PO proyek setara ' + fmtAmt(after) + ' ' + budgetInfo.code + ' melebihi total RAB (' + fmtAmt(budgetInfo.totalLimit) + ' ' + budgetInfo.code + ').');
            return;
        }
        if (budgetInfo.ceiling !== null && after > budgetInfo.ceiling + VEPS) {
            Ext.Msg.alert('Peringatan', 'Total PO proyek setara ' + fmtAmt(after) + ' ' + budgetInfo.code + ' melebihi budget proyek (' + fmtAmt(budgetInfo.ceiling) + ' ' + budgetInfo.code + ').');
            return;
        }

        ajax({
            task: 'save_po',
            id: editingId,
            project_id: fProject.getValue(),
            supplier_id: fSupplier.getValue() || 0,
            currency_id: fCurrency.getValue() || 0,
            exch_rate: poRate(),
            budget_exch_rate: needBudRate() ? (parseFloat(fBudRate.getValue()) || 0) : 0,
            po_date: fDate.getValue().format('Y-m-d'),
            notes: fNotes.getValue(),
            items: Ext.util.JSON.encode(arr)
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'PO berhasil disimpan.');
                mainDs.load();
                document.getElementById('editorWdw').style.display = 'none';
                if (res.data && res.data.id) {
                    dataRecord = { id: res.data.id, status: 'Draft' };
                    updateMainButtons();
                    loadDetailPanel(res.data.id);
                }
            } else {
                Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                loadRab(fProject.getValue(), recalcAll);     // kuota & komitmen anggaran mungkin berubah -> muat ulang
            }
        });
    };

    // DELETE = hapus PO Draft
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Draft') {
            Ext.Msg.alert('Peringatan', 'Pilih PO berstatus Draft terlebih dahulu. PO lain dapat dibatalkan dari panel detail.');
            return;
        }
        if (getFormAccess() < ACCESS_EDIT) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus PO.');
            return;
        }
        Ext.Msg.confirm('Hapus PO', 'Hapus PO <b>' + enc(dataRecord.po_number) + '</b>? Tindakan ini tidak dapat dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ task: 'delete_po', id: dataRecord.id }, function (res) {
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

    // ------------------------------------------------------------
    // 5. Draft dari Purchase Planning (sessionStorage) -> langsung buka editor PO baru
    // ------------------------------------------------------------
    function checkDraftFromPlanning() {
        var raw = null;
        try { raw = sessionStorage.getItem('po_draft_from_planning'); } catch (e) {}
        if (!raw) return;
        try { sessionStorage.removeItem('po_draft_from_planning'); } catch (e) {}     // sekali pakai
        var draft = null;
        try { draft = Ext.util.JSON.decode(raw); } catch (e) { return; }
        if (!draft || !draft.project_id || !draft.items || !draft.items.length) return;
        if (getFormAccess() < ACCESS_ADD) {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat PO.');
            return;
        }
        openNewPo(draft);
    }

    // Combo harus sudah terisi sebelum draft dipasang
    var pendingStores = 3;
    function storeReady() { if (--pendingStores === 0) checkDraftFromPlanning(); }
    projectDs.load({ callback: storeReady });
    supplierDs.load({ callback: storeReady });
    currencyDs.load({ callback: storeReady });
});