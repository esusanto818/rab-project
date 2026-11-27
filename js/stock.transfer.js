/*
  Document    : stock.transfer.js
  Description : Stock Transfer antar gudang. ExtJS 3.4.1
  Backend     : php-crud/stock.transferCRD.php
  CATATAN     : container render diasumsikan div #main-content (fallback ke body).
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    var URL = 'php-crud/stock.transferCRD.php';
    var RENDER_TO = Ext.get('main-content') ? 'main-content' : Ext.getBody();
    var dataRecord = null;

    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function ajaxPost(params, okCb) {
        Ext.Ajax.request({
            url: URL, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                if (!o.success) { showErr(o.message || 'Proses gagal.'); return; }
                okCb(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }
    function statusRenderer(v) {
        return '<span style="font-weight:bold">' + enc(v) + '</span>';
    }

    // ---------------- grid utama ----------------
    mainDs = new Ext.data.JsonStore({      // assignment polos (konvensi template)
        url: URL, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'ref_no', 'transfer_date', 'from_warehouse_id', 'from_warehouse_name',
                 'to_warehouse_id', 'to_warehouse_name', 'status', 'remark',
                 { name: 'total_qty', type: 'float' }, { name: 'total_amount', type: 'float' }],
        listeners: { exception: storeError }
    });
    var searchField = new Ext.form.TextField({
        emptyText: 'Cari No / Gudang...', width: 200, enableKeyEvents: true,
        listeners: { specialkey: function (f, e) { if (e.getKey() === e.ENTER) { doSearch(); } } }
    });
    function doSearch() { mainDs.baseParams.query = searchField.getValue(); mainDs.load({ params: { start: 0, limit: 25 } }); }

    new Ext.grid.GridPanel({
        store: mainDs, renderTo: RENDER_TO, title: 'Stock Transfer',
        height: Math.max(420, Ext.getBody().getViewSize().height - 140), loadMask: true, stripeRows: true,
        sm: new Ext.grid.RowSelectionModel({ singleSelect: true }), viewConfig: { forceFit: true },
        columns: [
            { header: 'No. Transfer', dataIndex: 'ref_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'transfer_date', width: 85, align: 'center' },
            { header: 'Gudang Asal', dataIndex: 'from_warehouse_name', width: 160 },
            { header: 'Gudang Tujuan', dataIndex: 'to_warehouse_name', width: 160 },
            { header: 'Total Qty', dataIndex: 'total_qty', width: 100, align: 'right', renderer: fmtQty },
            { header: 'Nilai', dataIndex: 'total_amount', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center', renderer: statusRenderer }
        ],
        tbar: [
            { text: 'Tambah Transfer', handler: function () { openEditor(); } },
            { text: 'Detail', handler: function () {
                if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
                openDetail(dataRecord);
            } },
            { text: 'Reverse', handler: function () {
                if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
                if (dataRecord.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
                Ext.Msg.confirm('Reverse Transfer', 'Reverse <b>' + enc(dataRecord.ref_no) + '</b>? Stok dikembalikan ke gudang asal.',
                    function (b) {
                        if (b !== 'yes') { return; }
                        ajaxPost({ action: 'reverse', id: dataRecord.id }, function (o) {
                            Ext.Msg.alert('Sukses', o.message); dataRecord = null; mainDs.reload();
                        });
                    });
            } },
            '->', searchField, { text: 'Cari', handler: doSearch }
        ],
        bbar: new Ext.PagingToolbar({ store: mainDs, pageSize: 25, displayInfo: true }),
        listeners: { rowclick: function (g, i) { var r = g.getStore().getAt(i); dataRecord = r ? r.data : null; } }
    });
    mainDs.load({ params: { start: 0, limit: 25 } });

    // ---------------- detail ----------------
    function openDetail(rec) {
        var ds = new Ext.data.JsonStore({
            url: URL, baseParams: { action: 'detail', id: rec.id }, root: 'data',
            fields: ['material_code', 'material_name', 'unit', { name: 'qty', type: 'float' },
                     { name: 'unit_cost', type: 'float' }, { name: 'amount', type: 'float' }],
            listeners: { exception: storeError }
        });
        new Ext.Window({
            title: 'Detail Transfer ' + rec.ref_no, width: 760, height: 360, modal: true, layout: 'fit',
            items: new Ext.grid.GridPanel({
                store: ds, stripeRows: true, viewConfig: { forceFit: true },
                columns: [
                    { header: 'Kode', dataIndex: 'material_code', width: 90 },
                    { header: 'Material', dataIndex: 'material_name', width: 220 },
                    { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                    { header: 'Qty', dataIndex: 'qty', width: 100, align: 'right', renderer: fmtQty },
                    { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 100, align: 'right', renderer: fmtAmt },
                    { header: 'Nilai', dataIndex: 'amount', width: 110, align: 'right', renderer: fmtAmt }
                ]
            })
        }).show();
        ds.load();
    }

    // ---------------- editor ----------------
    var whDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_warehouses' }, root: 'data', fields: ['id', 'code', 'name', 'project_name'],
        listeners: { exception: storeError }
    });
    var itemDs = new Ext.data.JsonStore({
        url: URL, baseParams: { action: 'lookup_items' }, root: 'data',
        fields: ['item_id', 'material_code', 'material_name', 'unit', { name: 'onhand', type: 'float' }],
        listeners: { exception: storeError }
    });
    gridAddEditorDs = new Ext.data.JsonStore({      // store detail editor
        root: 'data', fields: ['item_id', 'material_code', 'material_name', 'unit',
            { name: 'qty', type: 'float' }, { name: 'onhand', type: 'float' }]
    });

    function openEditor() {
        gridAddEditorDs.removeAll();
        var wtpl = '<tpl for="."><div class="x-combo-list-item">{name} <span >[{project_name}]</span></div></tpl>';
        var fFrom = new Ext.form.ComboBox({ fieldLabel: 'Gudang Asal', store: whDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', tpl: wtpl });
        var fTo = new Ext.form.ComboBox({ fieldLabel: 'Gudang Tujuan', store: whDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%', tpl: wtpl });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fRemark = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });

        fFrom.on('select', function () { gridAddEditorDs.removeAll(); fItem.clearValue(); });

        var fItem = new Ext.form.ComboBox({
            store: itemDs, displayField: 'material_name', valueField: 'item_id', mode: 'remote', queryParam: 'query',
            minChars: 0, triggerAction: 'all', forceSelection: true, width: 380, listWidth: 480,
            emptyText: 'Cari item yang berstok di gudang asal...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} <b>(stok {onhand} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fFrom.getValue()) { showErr('Pilih gudang asal terlebih dahulu.'); return false; }
                    itemDs.baseParams.warehouse_id = fFrom.getValue();
                }
            }
        });
        function addItem() {
            var rec = fItem.findRecord('item_id', fItem.getValue());
            if (!rec) { showErr('Pilih item terlebih dahulu.'); return; }
            if (gridAddEditorDs.find('item_id', rec.get('item_id')) >= 0) { showErr('Item sudah ada di daftar.'); return; }
            var R = gridAddEditorDs.recordType;
            gridAddEditorDs.add(new R({
                item_id: rec.get('item_id'), material_code: rec.get('material_code'), material_name: rec.get('material_name'),
                unit: rec.get('unit'), qty: rec.get('onhand'), onhand: rec.get('onhand')
            }));
            fItem.clearValue();
        }

        var detailGrid = new Ext.grid.EditorGridPanel({
            region: 'center', store: gridAddEditorDs, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
            tbar: [fItem, { text: 'Tambah', handler: addItem }],
            columns: [
                { header: 'Kode', dataIndex: 'material_code', width: 90 },
                { header: 'Material', dataIndex: 'material_name', width: 230 },
                { header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
                { header: 'Stok Asal', dataIndex: 'onhand', width: 100, align: 'right', renderer: fmtQty },
                { header: 'Qty Transfer', dataIndex: 'qty', width: 100, align: 'right', renderer: fmtQty,
                  editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 4 }) },
                { header: '', dataIndex: 'item_id', width: 30, align: 'center', fixed: true,
                  renderer: function () { return '<span style="cursor:pointer;font-weight:bold">&#10005;</span>'; } }
            ],
            listeners: {
                validateedit: function (e) {
                    if (e.field === 'qty' && parseFloat(e.value) > e.record.get('onhand') + 0.00005) {
                        showErr('Qty melebihi stok gudang asal (' + fmtQty(e.record.get('onhand')) + ').');
                        e.cancel = true;
                    }
                },
                cellclick: function (g, row, col) { if (col === g.getColumnModel().getColumnCount() - 1) { g.getStore().removeAt(row); } }
            }
        });

        var form = new Ext.form.FormPanel({
            region: 'north', height: 110, border: false, layout: 'column', bodyStyle: 'padding:8px 6px 0 6px',
            items: [
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 110, items: [fFrom, fTo] },
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 90, items: [fDate, fRemark] }
            ]
        });

        var win = new Ext.Window({
            title: 'Stock Transfer Baru', width: 900, height: 560, modal: true, layout: 'border',
            items: [form, detailGrid],
            buttons: [
                { text: 'Simpan & Posting', handler: function () {
                    if (!fFrom.getValue() || !fTo.getValue() || !fDate.getValue()) { showErr('Gudang asal, tujuan, dan tanggal wajib diisi.'); return; }
                    if (fFrom.getValue() === fTo.getValue()) { showErr('Gudang asal dan tujuan tidak boleh sama.'); return; }
                    var arr = [], err = '';
                    gridAddEditorDs.each(function (r) {
                        var q = parseFloat(r.get('qty')) || 0;
                        if (q <= 0) { err = 'Qty transfer ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
                        arr.push({ item_id: r.get('item_id'), qty: q });
                    });
                    if (err) { showErr(err); return; }
                    if (!arr.length) { showErr('Tambahkan minimal satu item.'); return; }
                    ajaxPost({
                        action: 'save', from_warehouse_id: fFrom.getValue(), to_warehouse_id: fTo.getValue(),
                        transfer_date: fDate.getValue().format('Y-m-d'), remark: fRemark.getValue(),
                        details: Ext.util.JSON.encode(arr)
                    }, function (o) { win.close(); Ext.Msg.alert('Sukses', o.message); mainDs.reload(); });
                } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
        whDs.load();
    }
});