/*
  Document      : material.leftover.js
  Description   : Material Left Over = RETUR SISA MATERIAL PROYEK ke gudang. Material yang sudah di-issue ke proyek (masuk WIP)
                   tetapi tidak terpakai dikembalikan: stok gudang bertambah (biaya = rata-rata issue bersih proyek, membentuk layer FIFO)
                   dan WIP proyek berkurang (Dr Persediaan Bahan Baku / Cr WIP).
                   Tab 1 "Dokumen Retur Sisa": daftar dokumen (klik baris -> detail; Add = retur sisa baru; Delete = reverse).
                   Tab 2 "Posisi per Proyek": per proyek & material: RAB, di-issue, retur sisa, terpakai bersih (qty, nilai, % RAB).
                   Qty retur dibatasi qty issue bersih proyek; reverse hanya bila stok hasil retur belum terpakai.
  Backend       : /proyek/php-crud/material.leftoverCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Material <em>Left Over</em>";

    var CRD = main_url + '/php-crud/material.leftoverCRD.php';
    var ACCESS_ADD = 2;                 // buat retur sisa
    var ACCESS_EDIT = 3;                // reverse
    var EPS = 0.00005;
    var BTN_CLS = 'field-button grey-field-button';     // gaya tombol template (tombol Ext bawaan tidak terbaca di tema gelap)
    var dataRecord = null;              // dokumen terpilih (tab 1)
    var activeKey = 'docs';

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }      // Date.format('Y-m-d') pernah salah di browser (lihat catatan), pakai dateFormat

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(o);
            },
            failure: function () { if (onFail) onFail(); else showErr('Gagal menghubungi server.'); }
        });
    }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function jsonStore(task, fields) {
        return new Ext.data.JsonStore({ url: CRD, baseParams: { task: task }, root: 'data', totalProperty: 'totalCount', fields: fields,
                                         listeners: { exception: storeError } });
    }
    var fl = function (n) { return { name: n, type: 'float' }; };

    // ------------------------------------------------------------
    // 0. Gaya & kerangka (tab seperti Pengaturan Sistem, tema gelap)
    // ------------------------------------------------------------
    (function injectCss() {
        var css = [
            '.lo-tabs{display:flex;gap:4px;border-bottom:1px solid #123049;padding:0 4px;background:#020d1f;}',
            '.lo-tab{display:flex;align-items:center;gap:10px;padding:10px 20px 9px 14px;cursor:pointer;border-bottom:3px solid transparent;color:#6b869a;user-select:none;}',
            '.lo-tab:hover{background:rgba(43,193,226,.06);}',
            '.lo-tab svg{width:24px;height:24px;stroke:#6b869a;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:none;}',
            '.lo-tab .tt{display:block;font:bold 13px arial;color:#aebfcc;} .lo-tab .st{display:block;font:11px arial;color:#6b869a;margin-top:1px;}',
            '.lo-tab.active{background:linear-gradient(180deg,rgba(43,193,226,.04),rgba(43,193,226,.16));border-bottom-color:#2bc1e2;}',
            '.lo-tab.active svg{stroke:#2bc1e2;} .lo-tab.active .tt{color:#2bc1e2;} .lo-tab.active .st{color:#7fa3b8;}',
            '.lo-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 8px;color:#9fc3d1;font:13px arial;}',
            '.lo-filter select{background:#031126;border:1px solid #1d4a6b;color:#e6f1f8;height:26px;padding:0 8px;font:13px arial;border-radius:2px;min-width:240px;}',
            '.lo-filter .btn{padding:5px 14px;border:none;border-radius:3px;cursor:pointer;color:#fff;font:bold 12px arial;background:#03615e;}',
            '.lo-panel{padding:0 8px 8px 8px;}'
        ].join('\n');
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(css));
        document.head.appendChild(st);
    })();

    var ICON = {
        docs: '<svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M8 13h8M8 17h5"/></svg>',
        pos:  '<svg viewBox="0 0 24 24"><path d="M3 7l9-4 9 4-9 4-9-4zM3 12l9 4 9-4M3 17l9 4 9-4"/></svg>'
    };
    var TABS = [
        { key: 'docs', title: 'Dokumen Retur Sisa', sub: 'Sisa material kembali ke gudang' },
        { key: 'pos',  title: 'Posisi per Proyek',  sub: 'Issue, retur sisa, terpakai vs RAB' }
    ];
    var host = document.getElementById('divMainGrid');
    var html = '<div class="lo-tabs" id="loTabs">';
    Ext.each(TABS, function (t) {
        html += '<div class="lo-tab" id="loTab_' + t.key + '">' + ICON[t.key] + '<span><span class="tt">' + t.title + '</span><span class="st">' + t.sub + '</span></span></div>';
    });
    html += '</div><div class="lo-filter" id="loFilter"><label>Proyek</label><select id="loProject"><option value="0">(Semua proyek)</option></select>' +
            '<button type="button" class="btn" id="loGo">Tampilkan</button></div>';
    Ext.each(TABS, function (t) {
        html += '<div class="lo-panel" id="loPanel_' + t.key + '" style="display:none;"><div id="loGrid_' + t.key + '"></div>' +
                (t.key === 'docs' ? '<div id="loDtl" class="detail-panel-container" style="display:none;overflow-y:auto;margin-top:8px;"></div>' : '') + '</div>';
    });
    host.innerHTML = html;

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global. mainDs = store dokumen (tab 1).
    mainDs = jsonStore('list', ['id', 'ref_no', 'doc_date', 'status', 'remark', 'project_id', 'project_name', 'warehouse_id', 'warehouse_name',
                                { name: 'line_count', type: 'int' }, fl('total_amount')]);
    var posDs = jsonStore('position', ['project_id', 'project_name', 'material_id', 'material_code', 'material_name', 'unit',
                                       fl('rab_qty'), fl('issued_qty'), fl('issued_amt'), fl('lo_qty'), fl('lo_amt'), fl('net_qty'), fl('net_amt'), 'used_pct']);
    var projectDs = jsonStore('lookup_projects', ['id', 'name']);
    var warehouseDs = jsonStore('lookup_warehouses', ['id', 'code', 'name']);
    var materialDs = jsonStore('lookup_materials', ['material_id', 'material_code', 'material_name', 'unit', fl('net_qty'), fl('avg_cost')]);

    var selProject = document.getElementById('loProject');
    function filterProject() { return parseInt(selProject.value, 10) || 0; }
    function loadAll() {
        var p = filterProject();
        mainDs.baseParams.project_id = p;
        posDs.baseParams.project_id = p;
        mainDs.load();
        posDs.load();
    }
    document.getElementById('loGo').onclick = loadAll;
    // pencarian global template memuat ulang mainDs saja -> samakan query ke posisi
    mainDs.on('beforeload', function (s) { posDs.baseParams.query = s.baseParams.query || ''; });

    // ------------------------------------------------------------
    // 2. Grid
    // ------------------------------------------------------------
    var h = Math.max(Math.round(maxGridHeight(host)) - 120, 260);
    var statusRenderer = function (v) {
        return '<span style="color:' + (v === 'posted' ? '#2e9e4f' : '#c0392b') + ' !important;font-weight:bold;">' + enc(v) + '</span>';
    };
    var builders = {
        docs: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'loGrid_docs', height: Math.round(h * 0.55), store: mainDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true, deferEmptyText: false, emptyText: '<div style="padding:16px;color:#9fc3d1;">Belum ada retur sisa material. Klik Add untuk mengembalikan sisa material proyek ke gudang.</div>' },
                columns: [
                    { header: 'No. Dokumen', dataIndex: 'ref_no', width: 120 },
                    { header: 'Tanggal', dataIndex: 'doc_date', width: 85, align: 'center' },
                    { header: 'Proyek', dataIndex: 'project_name', width: 220, renderer: CellWrap },
                    { header: 'Gudang Tujuan', dataIndex: 'warehouse_name', width: 150, renderer: CellWrap },
                    { header: 'Item', dataIndex: 'line_count', width: 50, align: 'center' },
                    { header: 'Nilai (Rp)', dataIndex: 'total_amount', width: 120, align: 'right', renderer: function (v) { return fmtAmt(v); } },
                    { header: 'Catatan', dataIndex: 'remark', width: 180, renderer: function (v) { return CellWrap(v || ''); } },
                    { header: 'Status', dataIndex: 'status', width: 80, align: 'center', renderer: statusRenderer }
                ],
                listeners: {
                    rowclick: function (g, i) {
                        var r = g.getStore().getAt(i);
                        dataRecord = r ? r.data : null;
                        updateButtons();
                        if (dataRecord) loadDetail(dataRecord);
                    }
                }
            });
        },
        pos: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'loGrid_pos', height: h, store: posDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true, deferEmptyText: false, emptyText: '<div style="padding:16px;color:#9fc3d1;">Belum ada material yang di-issue ke proyek.</div>' },
                columns: [
                    { header: 'Proyek', dataIndex: 'project_name', width: 190, renderer: CellWrap },
                    { header: 'Kode', dataIndex: 'material_code', width: 80 },
                    { header: 'Material', dataIndex: 'material_name', width: 190, renderer: CellWrap },
                    { header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
                    { header: 'RAB', dataIndex: 'rab_qty', width: 85, align: 'right', renderer: fmtQty },
                    { header: 'Di-issue', dataIndex: 'issued_qty', width: 90, align: 'right', renderer: fmtQty },
                    { header: 'Retur Sisa', dataIndex: 'lo_qty', width: 90, align: 'right', renderer: function (v) { return v > EPS ? '<span style="color:#2bc1e2;">' + fmtQty(v) + '</span>' : '-'; } },
                    { header: 'Terpakai Bersih', dataIndex: 'net_qty', width: 100, align: 'right', renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
                    { header: '% RAB', dataIndex: 'used_pct', width: 65, align: 'right',
                      renderer: function (v) { return v === null || v === undefined ? '-' : '<span style="color:' + (v > 105 ? '#ff6b5e' : '#e6f1f8') + ';">' + v + '%</span>'; } },
                    { header: 'Nilai Di-issue', dataIndex: 'issued_amt', width: 110, align: 'right', renderer: fmtAmt },
                    { header: 'Nilai Retur Sisa', dataIndex: 'lo_amt', width: 110, align: 'right', renderer: function (v) { return v > 0 ? fmtAmt(v) : '-'; } },
                    { header: 'Nilai Bersih (WIP)', dataIndex: 'net_amt', width: 120, align: 'right', renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } }
                ]
            });
        }
    };
    var grids = {};
    function showTab(key) {
        activeKey = key;
        Ext.each(TABS, function (t) {
            document.getElementById('loTab_' + t.key).className = 'lo-tab' + (t.key === key ? ' active' : '');
            document.getElementById('loPanel_' + t.key).style.display = (t.key === key) ? 'block' : 'none';
        });
        if (!grids[key]) grids[key] = builders[key]();          // dibuat saat tab pertama dibuka (container visible -> lebar kolom benar)
        updateButtons();
    }
    Ext.each(TABS, function (t) { document.getElementById('loTab_' + t.key).onclick = function () { showTab(t.key); }; });

    function updateButtons() {
        mainBtnEnable('#addData');
        if (activeKey === 'docs' && dataRecord && dataRecord.status === 'posted') mainBtnEnable('#deleteData'); else mainBtnDisable('#deleteData');
        mainBtnDisable('#editData');
    }
    editDataDisable();

    // Detail dokumen
    function loadDetail(rec) {
        var el = document.getElementById('loDtl');
        ajax({ task: 'detail', id: rec.id }, function (res) {
            if (!res.success) { el.innerHTML = '<div style="color:yellow;padding:10px;">' + enc(res.message) + '</div>'; el.style.display = 'block'; return; }
            var tot = 0;
            var t = '<h3 class="detail-title">Dokumen: ' + enc(rec.ref_no) + ' <span style="font-weight:normal;">(' + enc(rec.project_name) + ' &rarr; ' + enc(rec.warehouse_name) + ')</span></h3><hr class="detail-hr" />' +
                    '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row"><th align="left">Material</th>' +
                    '<th align="right">Qty</th><th align="right">Biaya Satuan</th><th align="right">Nilai</th><th align="left">Catatan</th></tr></thead><tbody>';
            Ext.each(res.data, function (r) {
                tot += r.amount;
                t += '<tr class="data-row"><td>' + enc(r.material_name) + ' <span style="color:#668894;">(' + enc(r.material_code) + ')</span></td><td align="right">' + fmtQty(r.qty) + ' ' + enc(r.unit) +
                     '</td><td align="right">' + fmtAmt(r.unit_cost) + '</td><td align="right">' + fmtAmt(r.amount) + '</td><td>' + enc(r.notes || '') + '</td></tr>';
            });
            t += '<tr class="data-row"><td colspan="3" align="right"><b>Total</b></td><td align="right" class="subtotal-val"><b>' + fmtAmt(tot) + '</b></td><td></td></tr></tbody></table>';
            el.innerHTML = t;
            el.style.display = 'block';
        });
    }

    // ------------------------------------------------------------
    // 3. Jendela retur sisa baru
    // ------------------------------------------------------------
    var editStore = new Ext.data.JsonStore({ root: 'data', fields: ['material_id', 'material_code', 'material_name', 'unit', fl('net_qty'), fl('avg_cost'), fl('qty'), 'notes'] });

    function openEditor() {
        editStore.removeAll();
        var fProject = new Ext.form.ComboBox({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { editStore.removeAll(); fMat.clearValue(); } }
        });
        var fWh = new Ext.form.ComboBox({
            fieldLabel: 'Gudang Tujuan', store: warehouseDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%'
        });
        var fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        var fRemark = new Ext.form.TextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var fMat = new Ext.form.ComboBox({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote', queryParam: 'query', minChars: 0,
            triggerAction: 'all', forceSelection: true, width: 400, listWidth: 540, emptyText: 'Cari material yang pernah di-issue ke proyek...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} <b>(issue bersih {net_qty} {unit}, biaya rata-rata {avg_cost})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fProject.getValue()) { showErr('Pilih proyek terlebih dahulu.'); return false; }
                    materialDs.baseParams.project_id = fProject.getValue();
                }
            }
        });
        function addMaterial() {
            var rec = fMat.findRecord('material_id', fMat.getValue());
            if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
            if (editStore.find('material_id', rec.get('material_id')) >= 0) { showErr('Material sudah ada di daftar.'); return; }
            var R = editStore.recordType;
            editStore.add(new R({
                material_id: rec.get('material_id'), material_code: rec.get('material_code'), material_name: rec.get('material_name'), unit: rec.get('unit'),
                net_qty: rec.get('net_qty'), avg_cost: rec.get('avg_cost'), qty: rec.get('net_qty'), notes: ''
            }));
            fMat.clearValue();
        }
        var detailGrid = new Ext.grid.EditorGridPanel({
            region: 'center', store: editStore, clicksToEdit: 1, stripeRows: true, viewConfig: { forceFit: true },
            tbar: [fMat, { text: 'Tambah', cls: BTN_CLS, handler: addMaterial }],
            bbar: ['Qty retur dibatasi qty issue bersih proyek. Nilai = biaya rata-rata issue bersih; WIP proyek berkurang sebesar nilai ini.'],
            columns: [
                { header: 'Kode', dataIndex: 'material_code', width: 80 },
                { header: 'Material', dataIndex: 'material_name', width: 200 },
                { header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
                { header: 'Issue Bersih', dataIndex: 'net_qty', width: 100, align: 'right', renderer: fmtQty },
                { header: 'Biaya Rata-rata', dataIndex: 'avg_cost', width: 105, align: 'right', renderer: fmtAmt },
                { header: 'Qty Retur Sisa', dataIndex: 'qty', width: 100, align: 'right', renderer: fmtQty, editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 4 }) },
                { header: 'Nilai', dataIndex: 'qty', width: 110, align: 'right', renderer: function (v, m, r) { return fmtAmt(Math.round(v * r.get('avg_cost') * 100) / 100); } },
                { header: 'Catatan', dataIndex: 'notes', width: 150, editor: new Ext.form.TextField() },
                { header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
                  renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;">&#10005;</span>'; } }
            ],
            listeners: {
                validateedit: function (e) {
                    if (e.field === 'qty' && parseFloat(e.value) > e.record.get('net_qty') + EPS) {
                        showErr('Qty melebihi issue bersih proyek (' + fmtQty(e.record.get('net_qty')) + ').');
                        e.cancel = true;
                    }
                },
                cellclick: function (g, row, col) { if (col === g.getColumnModel().getColumnCount() - 1) g.getStore().removeAt(row); }
            }
        });
        var form = new Ext.form.FormPanel({
            region: 'north', height: 110, border: false, layout: 'column', bodyStyle: 'padding:8px 6px 0 6px;',
            items: [
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 110, items: [fProject, fWh, fDate] },
                { columnWidth: 0.5, layout: 'form', border: false, labelWidth: 110, items: [fRemark] }
            ]
        });
        var win = new Ext.Window({
            title: 'Retur Sisa Material (Left Over) Baru', width: 960, height: 560, modal: true, layout: 'border', items: [form, detailGrid],
            buttons: [
                { text: 'Simpan & Posting', cls: BTN_CLS, handler: function () {
                    if (!fProject.getValue() || !fWh.getValue() || !fDate.getValue()) { showErr('Proyek, gudang tujuan, dan tanggal wajib diisi.'); return; }
                    var arr = [], err = '';
                    editStore.each(function (r) {
                        var q = parseFloat(r.get('qty')) || 0;
                        if (q <= 0) { err = 'Qty retur ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
                        arr.push({ material_id: r.get('material_id'), qty: q, notes: r.get('notes') || '' });
                    });
                    if (err) { showErr(err); return; }
                    if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }
                    ajax({ task: 'save', project_id: fProject.getValue(), warehouse_id: fWh.getValue(), doc_date: ymd(fDate.getValue()),
                           remark: fRemark.getValue(), details: Ext.util.JSON.encode(arr) }, function (res) {
                        if (res.success) { win.close(); Ext.Msg.alert('Sukses', res.message); loadAll(); }
                        else showErr(res.message || 'Proses gagal.');
                    });
                } },
                { text: 'Batal', cls: BTN_CLS, handler: function () { win.close(); } }
            ]
        });
        win.show();
        projectDs.load();
        warehouseDs.load();
    }

    // ------------------------------------------------------------
    // 4. Tombol template
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat retur sisa material.'); return; }
        openEditor();
    };
    document.getElementById('deleteData').onclick = function () {      // Delete = Reverse dokumen
        if (!dataRecord) { showErr('Pilih dokumen terlebih dahulu.'); return; }
        if (dataRecord.status !== 'posted') { showErr('Hanya dokumen posted yang dapat di-reverse.'); return; }
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk reverse dokumen.'); return; }
        Ext.Msg.confirm('Reverse Retur Sisa', 'Reverse dokumen <b>' + enc(dataRecord.ref_no) + '</b>? Stok hasil retur ditarik kembali dan jurnal dibatalkan.', function (b) {
            if (b !== 'yes') return;
            ajax({ task: 'reverse', id: dataRecord.id }, function (res) {
                Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
                if (res.success) { dataRecord = null; document.getElementById('loDtl').style.display = 'none'; loadAll(); updateButtons(); }
            });
        });
    };
    if (document.getElementById('popOK')) document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };

    // ------------------------------------------------------------
    // 5. Data awal
    // ------------------------------------------------------------
    showTab('docs');
    projectDs.load({ callback: function () {
        projectDs.each(function (r) {
            var o = document.createElement('option');
            o.value = r.get('id'); o.text = r.get('name');
            selProject.appendChild(o);
        });
    } });
    document.getElementById('editorWdw').style.display = 'none';
    loadAll();
});
