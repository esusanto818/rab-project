/*
  Document      : stock.opname.js
  Description   : Stock Opname. Grid utama = daftar opname; klik baris -> panel detail di bawah grid (lembar hitung, selisih,
                   jejak audit, pergerakan stok & jurnal hasil posting, tombol aksi status).
                   Add/Edit membuka editor (header + grid hitung). Edit hanya untuk status Draft; dokumen tidak pernah dihapus.
                   Alur status: Draft -> Diajukan -> Disetujui (posting stok + jurnal). Dibatalkan dari Draft / Diajukan / Disetujui
                   (alasan wajib; Disetujui = reverse). Stok sistem = snapshot saat disimpan / di-refresh.
  Backend       : ../php-crud/stock.opnameCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Stock <em>Opname</em>";

    var CRD = '../php-crud/stock.opnameCRD.php';
    var ACCESS_ADD = 2;          // level akses minimal: buat / ubah lembar hitung
    var ACCESS_EDIT = 3;         // level akses minimal: ajukan / setujui / batalkan
    var EPS = 0.00005;
    var REASONS = ['Salah Hitung / Salah Catat', 'Rusak', 'Hilang / Susut', 'Kelebihan Penerimaan', 'Salah Pengeluaran', 'Lainnya'];

    var dataRecord = null;       // baris opname terpilih di grid utama
    var editingId = 0;           // 0 = baru, >0 = edit Draft
    var hdrForm = null, fWh, fDate, fRemark;

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function round4(v) { return Math.round((parseFloat(v) || 0) * 10000) / 10000; }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function ymd(d) { return d ? d.dateFormat('Y-m-d') : ''; }
    function uiBtn(cls, label, bg, attrs) {      // tombol HTML gaya standar UI
        return '<button type="button" class="' + cls + '" ' + (attrs || '') + ' style="margin-left:8px;padding:8px 20px;background:' + bg +
               ';color:#fff;border:none;border-radius:4px;cursor:pointer;font-weight:bold;">' + label + '</button>';
    }
    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diajukan': return '#e6a817 !important';
            case 'Disetujui': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
    }
    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }

    // ------------------------------------------------------------
    // 0. Panel detail (di bawah grid utama)
    // ------------------------------------------------------------
    var GridMainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'soDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);
    dtlDiv.style.overflowY = 'auto';
    var gridContainer = document.getElementById('divMainGrid');
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling); }

    function loadDetailPanel(id) {
        ajax({ action: 'detail', id: id }, function (res) {
            if (!res.success) { dtlDiv.innerHTML = '<div style="color:yellow;padding:10px;">' + enc(res.message || 'Gagal memuat detail.') + '</div>'; dtlDiv.style.display = 'block'; return; }
            renderDetail(res);
        });
    }

    function renderDetail(res) {
        var h = res.header, lines = res.data || [], mov = res.movements || [], jr = res.journals || [];
        var counted = 0, withDiff = 0;
        Ext.each(lines, function (l) { if (l.counted_qty !== null && l.counted_qty !== undefined) { counted++; if (Math.abs(parseFloat(l.diff_qty)) > EPS) withDiff++; } });

        var html = '<h3 class="detail-title">Detail Opname: ' + enc(h.ref_no) + ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" /><div style="padding:4px 2px 10px 2px;">Gudang: <b>' + enc(h.warehouse_name) + '</b> &nbsp;|&nbsp; Tanggal: <b>' + enc(h.opname_date) +
                   '</b> &nbsp;|&nbsp; Item: <b>' + lines.length + '</b> &nbsp;|&nbsp; Dihitung: <b>' + counted + '</b> &nbsp;|&nbsp; Ada selisih: <b>' + withDiff + '</b>' +
                   (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + enc(h.remark) : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Stok Sistem</th><th align="right">Qty Hitung</th><th align="right">Selisih</th>' +
                   '<th align="right">Biaya Satuan</th><th align="right">Nilai Selisih</th><th align="left">Alasan</th><th align="left">Catatan</th></tr></thead><tbody>';
        if (!lines.length) { html += '<tr><td colspan="8" style="padding:15px;text-align:center;color:#557982;">Belum ada baris.</td></tr>'; }
        Ext.each(lines, function (l) {
            var has = (l.counted_qty !== null && l.counted_qty !== undefined);
            var diff = parseFloat(l.diff_qty) || 0;
            var col = !has ? '#668894' : (diff > EPS ? '#2e9e4f' : (diff < -EPS ? '#e05353' : '#9fc3d1'));
            html += '<tr class="data-row"><td align="left">' + enc(l.material_name) + ' <span style="color:#668894;">(' + enc(l.material_code) + ')</span></td>' +
                    '<td align="right">' + fmtQty(l.system_qty) + ' ' + enc(l.unit || '') + '</td>' +
                    '<td align="right">' + (has ? fmtQty(l.counted_qty) : '<i style="color:#668894;">tidak dihitung</i>') + '</td>' +
                    '<td align="right" style="color:' + col + ';font-weight:bold;">' + (has ? fmtQty(diff) : '-') + '</td>' +
                    '<td align="right">' + fmtAmt(l.unit_cost) + '</td>' +
                    '<td align="right" style="color:' + col + ';">' + (parseFloat(l.diff_value) ? fmtAmt(l.diff_value) : '-') + '</td>' +
                    '<td align="left">' + enc(l.reason || '') + '</td><td align="left">' + enc(l.note || '') + '</td></tr>';
        });
        html += '</tbody></table>';

        // Jejak audit
        var trail = [];
        trail.push('Dibuat: <b>' + enc(h.created_by_name || '-') + '</b> (' + enc(h.created_at) + ')');
        if (h.submitted_at) trail.push('Diajukan: <b>' + enc(h.submitted_by_name || '-') + '</b> (' + enc(h.submitted_at) + ')');
        if (h.approved_at) trail.push('Disetujui: <b>' + enc(h.approved_by_name || '-') + '</b> (' + enc(h.approved_at) + ')');
        if (h.cancelled_at) trail.push('Dibatalkan: <b>' + enc(h.cancelled_by_name || '-') + '</b> (' + enc(h.cancelled_at) + ') - ' + enc(h.cancel_reason || ''));
        html += '<div style="margin-top:12px;color:#9fc3d1;">' + trail.join(' &nbsp;|&nbsp; ') + '</div>';
        if (h.status === 'Disetujui') {
            html += '<div style="margin-top:6px;color:#9fc3d1;">Nilai lebih diposting: <b>' + fmtAmt(h.total_gain) + '</b> &nbsp;|&nbsp; Nilai kurang diposting: <b>' + fmtAmt(h.total_loss) + '</b>';
            if (jr.length) { html += ' &nbsp;|&nbsp; Jurnal: ' + Ext.pluck(jr, 'ref_no').join(', '); }
            html += ' &nbsp;|&nbsp; Pergerakan stok: ' + mov.length + ' baris (lihat Kartu Stok, ref ' + enc(h.ref_no) + ')</div>';
        }

        // Tombol aksi menurut status
        var btns = '';
        if (h.status === 'Draft') btns += uiBtn('soAct', 'Ajukan', '#066b78', 'data-act="submit"') + uiBtn('soAct', 'Refresh Stok Sistem', '#555555', 'data-act="refresh"');
        if (h.status === 'Diajukan') btns += uiBtn('soAct', 'Setujui &amp; Posting', '#066b78', 'data-act="approve"') + uiBtn('soAct', 'Refresh / Kembali ke Draft', '#555555', 'data-act="refresh"');
        if (h.status !== 'Dibatalkan') btns += uiBtn('soAct', h.status === 'Disetujui' ? 'Batalkan (Reverse)' : 'Batalkan', '#800020', 'data-act="cancel"');
        if (btns) html += '<div style="margin-top:15px;text-align:right;">' + btns + '</div>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';
        var els = dtlDiv.querySelectorAll('.soAct');
        for (var i = 0; i < els.length; i++) { els[i].onclick = function () { doAction(h, this.getAttribute('data-act')); }; }
    }

    function doAction(h, act) {
        if (getFormAccess() < ACCESS_EDIT) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk aksi ini.'); return; }
        var done = function (res) {
            Ext.Msg.alert(res.success ? 'Sukses' : 'Gagal', res.message || '');
            if (res.success) { mainDs.load(); loadDetailPanel(h.id); if (dataRecord && dataRecord.id == h.id) { dataRecord.status = res.status || dataRecord.status; } updateMainButtons(); }
        };
        var texts = { submit: 'Ajukan opname <b>' + enc(h.ref_no) + '</b> untuk persetujuan?',
                      refresh: 'Ambil ulang stok sistem untuk semua baris? Status kembali ke Draft.',
                      approve: 'Setujui &amp; posting opname <b>' + enc(h.ref_no) + '</b>? Stok dan jurnal akan disesuaikan.' };
        if (act === 'cancel') {
            Ext.Msg.prompt('Batalkan Opname', 'Alasan pembatalan (wajib, min. 5 karakter):', function (btn, txt) {
                if (btn !== 'ok') return;
                ajax({ action: 'cancel', id: h.id, reason: txt }, done);
            }, this, true);
            return;
        }
        Ext.Msg.confirm('Konfirmasi', texts[act], function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: act, id: h.id }, done);
        });
    }

    // ------------------------------------------------------------
    // 1. Stores & grid utama
    // ------------------------------------------------------------
    mainDs = new Ext.data.Store({       // assignment polos (konvensi search box template)
        url: CRD, baseParams: { action: 'list' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, [
            { name: 'id' }, { name: 'ref_no' }, { name: 'warehouse_id' }, { name: 'warehouse_name' }, { name: 'opname_date' },
            { name: 'status' }, { name: 'remark' }, { name: 'created_by_name' },
            { name: 'line_count', type: 'int' }, { name: 'counted_count', type: 'int' }, { name: 'diff_count', type: 'int' },
            { name: 'total_gain', type: 'float' }, { name: 'total_loss', type: 'float' }
        ]),
        listeners: { exception: storeError }
    });
    mainDs.load();

    var whDs = new Ext.data.Store({
        url: CRD, baseParams: { action: 'lookup_warehouses' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'code' }, { name: 'name' }]),
        listeners: { exception: storeError }
    });
    var matDs = new Ext.data.Store({
        url: CRD, baseParams: { action: 'lookup_materials' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'material_id' }, [
            { name: 'material_id' }, { name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
            { name: 'system_qty', type: 'float' }, { name: 'unit_cost', type: 'float' }]),
        listeners: { exception: storeError }
    });

    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: mainHeight,
        columns: [
            { header: 'No. Opname', dataIndex: 'ref_no', width: 120 },
            { header: 'Tanggal', dataIndex: 'opname_date', width: 90, align: 'center' },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 190, renderer: CellWrap },
            { header: 'Item', dataIndex: 'line_count', width: 55, align: 'center' },
            { header: 'Dihitung', dataIndex: 'counted_count', width: 70, align: 'center' },
            { header: 'Ada Selisih', dataIndex: 'diff_count', width: 80, align: 'center' },
            { header: 'Nilai Lebih', dataIndex: 'total_gain', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Nilai Kurang', dataIndex: 'total_loss', width: 110, align: 'right', renderer: fmtAmt },
            { header: 'Dibuat Oleh', dataIndex: 'created_by_name', width: 110 },
            { header: 'Status', dataIndex: 'status', width: 90, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v || '-') + '</span>'; } }
        ],
        viewConfig: { forceFit: true }, loadMask: true,
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
        mainBtnDisable('#deleteData');          // dokumen opname tidak dihapus, hanya Dibatalkan (jejak audit)
        if (dataRecord && dataRecord.status === 'Draft') { mainBtnEnable('#editData'); } else { mainBtnDisable('#editData'); }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 2. Editor: header + grid hitung
    // ------------------------------------------------------------
    var itemField = [
        { name: 'material_id' }, { name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
        { name: 'system_qty', type: 'float' }, { name: 'counted_qty' }, { name: 'diff_qty', type: 'float' },
        { name: 'unit_cost', type: 'float' }, { name: 'reason' }, { name: 'note' }
    ];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    var editorDeleter = new Extensive.grid.ItemDeleter({ header: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>' });
    itemDs.on('remove', function () { updateSummary(); });

    function hasCount(r) { var v = r.get('counted_qty'); return !(v === null || v === undefined || v === ''); }
    function recalcRow(r) {
        r.set('diff_qty', hasCount(r) ? round4((parseFloat(r.get('counted_qty')) || 0) - (parseFloat(r.get('system_qty')) || 0)) : 0);
    }
    function updateSummary() {
        var n = 0, c = 0, d = 0, gain = 0, loss = 0;
        itemDs.each(function (r) {
            n++;
            if (!hasCount(r)) return;
            c++;
            var diff = parseFloat(r.get('diff_qty')) || 0;
            if (Math.abs(diff) > EPS) { d++; var v = diff * (parseFloat(r.get('unit_cost')) || 0); if (diff > 0) gain += v; else loss += -v; }
        });
        var el = document.getElementById('soSummary');
        if (el) el.innerHTML = 'Item: <b>' + n + '</b> &nbsp;|&nbsp; Dihitung: <b>' + c + '</b> &nbsp;|&nbsp; Ada selisih: <b>' + d +
            '</b> &nbsp;|&nbsp; Perkiraan nilai lebih: <b style="color:#2e9e4f;">' + fmtAmt(gain) + '</b> &nbsp;|&nbsp; Perkiraan nilai kurang (FIFO bisa berbeda): <b style="color:#e05353;">' + fmtAmt(loss) + '</b>';
    }

    var itemGrid = new stdGridEditor({
        selModel: editorDeleter, store: itemDs, renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 }, height: 300,
        columns: [
            { header: 'Kode', dataIndex: 'material_code', width: 80 },
            { header: 'Material', dataIndex: 'material_name', width: 200 },
            { header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
            { header: 'Stok Sistem', dataIndex: 'system_qty', width: 90, align: 'right', renderer: fmtQty },
            { header: 'Qty Hitung', dataIndex: 'counted_qty', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4, allowNegative: false }),
              renderer: function (v) { return (v === null || v === undefined || v === '') ? '<i style="color:#668894;">isi</i>' : fmtQty(v); } },
            { header: 'Selisih', dataIndex: 'diff_qty', width: 90, align: 'right',
              renderer: function (v, m, r) {
                  if (!hasCount(r)) return '';
                  var c = v > EPS ? '#2e9e4f' : (v < -EPS ? '#e05353' : '#9fc3d1');
                  return '<span style="color:' + c + ';font-weight:bold;">' + fmtQty(v) + '</span>';
              } },
            { header: 'Biaya Satuan', dataIndex: 'unit_cost', width: 100, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4, allowNegative: false }), renderer: fmtAmt },
            { header: 'Alasan Selisih', dataIndex: 'reason', width: 150,
              editor: new Ext.form.ComboBox({ store: REASONS, triggerAction: 'all', editable: false, forceSelection: true }) },
            { header: 'Catatan', dataIndex: 'note', width: 150, editor: new myTextField() },
            editorDeleter
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'counted_qty') {
                    if (e.value === '' || e.value === null) { e.record.set('counted_qty', null); }
                    recalcRow(e.record);
                }
                updateSummary();
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {      // form header + pemilih material + ringkasan
        var g = document.getElementById('editorFormGrid');
        if (!g || document.getElementById('soHdrForm')) return;
        var hdr = document.createElement('div'); hdr.id = 'soHdrForm'; hdr.style.cssText = 'margin-bottom:2px;';
        g.parentNode.insertBefore(hdr, g);
        var bar = document.createElement('div'); bar.id = 'soAddBar'; bar.style.cssText = 'margin:6px 0;';
        g.parentNode.insertBefore(bar, g);
        var sum = document.createElement('div'); sum.id = 'soSummary';
        sum.style.cssText = 'padding:10px 14px;margin-top:8px;font-size:13px;text-align:right;background:#1f2d3a;color:#ffffff !important;border-radius:4px;';
        g.parentNode.insertBefore(sum, g.nextSibling);
    })();

    var fMat = null;
    function ensureForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }
        fWh = new myComboField({
            fieldLabel: 'Gudang', store: whDs, displayField: 'name', valueField: 'id', mode: 'local', triggerAction: 'all',
            forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { itemDs.removeAll(); updateSummary(); } }
        });
        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Opname', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, bodyStyle: 'background:transparent;', items: items }; };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'soHdrForm', border: false, layout: 'column', bodyStyle: 'background:transparent;padding:4px 2px 0 2px;',
            items: [col(0.34, [fWh]), col(0.22, [fDate]), col(0.44, [fRemark])]
        });

        // baris tambah material + ambil semua stok gudang
        fMat = new Ext.form.ComboBox({
            store: matDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote', queryParam: 'query', minChars: 0,
            triggerAction: 'all', forceSelection: true, width: 380, listWidth: 520, emptyText: 'Tambah material (termasuk yang stok sistemnya 0)...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} <b>(stok {system_qty} {unit})</b></div></tpl>',
            renderTo: 'soAddBar',
            listeners: { beforequery: function () {
                if (!fWh.getValue()) { showErr('Pilih gudang terlebih dahulu.'); return false; }
                matDs.baseParams.warehouse_id = fWh.getValue();
            } }
        });
        var b1 = document.createElement('span');
        b1.innerHTML = uiBtn('soBtnAdd', 'Tambah', '#066b78') + uiBtn('soBtnSnap', 'Ambil Semua Stok Gudang', '#555555');
        document.getElementById('soAddBar').appendChild(b1);
        document.getElementById('soAddBar').getElementsByClassName('soBtnAdd')[0].onclick = addMaterial;
        document.getElementById('soAddBar').getElementsByClassName('soBtnSnap')[0].onclick = loadSnapshot;
    }

    function newRec(cfg) {
        var Rec = itemDs.recordType || Ext.data.Record.create(itemField);
        return new Rec(Ext.apply({ material_id: null, material_code: '', material_name: '', unit: '', system_qty: 0, counted_qty: null, diff_qty: 0, unit_cost: 0, reason: '', note: '' }, cfg || {}));
    }
    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        if (itemDs.find('material_id', rec.get('material_id')) >= 0) { showErr('Material sudah ada di daftar.'); return; }
        itemDs.add(newRec({ material_id: rec.get('material_id'), material_code: rec.get('material_code'), material_name: rec.get('material_name'),
                            unit: rec.get('unit'), system_qty: rec.get('system_qty'), unit_cost: rec.get('unit_cost') }));
        fMat.clearValue();
        updateSummary();
    }
    function loadSnapshot() {
        if (!fWh.getValue()) { showErr('Pilih gudang terlebih dahulu.'); return; }
        ajax({ action: 'snapshot', warehouse_id: fWh.getValue() }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal mengambil stok.'); return; }
            var added = 0;
            Ext.each(res.data, function (d) {
                if (itemDs.find('material_id', d.material_id) >= 0) return;
                itemDs.add(newRec({ material_id: d.material_id, material_code: d.material_code, material_name: d.material_name, unit: d.unit,
                                    system_qty: parseFloat(d.system_qty), unit_cost: parseFloat(d.unit_cost) }));
                added++;
            });
            updateSummary();
            Ext.Msg.alert('Stok Gudang', added + ' material ditambahkan dari stok sistem.');
        });
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw'); if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureForm();
    }

    // ------------------------------------------------------------
    // 3. Handler tombol template
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat opname.'); return; }
        editingId = 0;
        showEditor('Stock Opname Baru');
        whDs.load({ callback: function () {
            fWh.clearValue(); fWh.setDisabled(false); fDate.setValue(new Date()); fRemark.setValue('');
            itemDs.removeAll(); updateSummary();
        } });
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih opname terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Draft') { showErr('Hanya opname berstatus Draft yang dapat diubah.'); return; }
        if (getFormAccess() < ACCESS_ADD) { Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengubah opname.'); return; }
        ajax({ action: 'detail', id: dataRecord.id }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat opname.'); return; }
            var h = res.header;
            editingId = parseInt(h.id, 10);
            showEditor('Edit Opname ' + h.ref_no);
            whDs.load({ callback: function () {
                fWh.setValue(parseInt(h.warehouse_id, 10)); fWh.setDisabled(true);       // gudang tidak dapat diganti
                fDate.setValue(Date.parseDate(h.opname_date, 'Y-m-d') || new Date());
                fRemark.setValue(h.remark || '');
                itemDs.removeAll();
                Ext.each(res.data, function (d) {
                    itemDs.add(newRec({
                        material_id: d.material_id, material_code: d.material_code, material_name: d.material_name, unit: d.unit,
                        system_qty: parseFloat(d.system_qty), counted_qty: (d.counted_qty === null ? null : parseFloat(d.counted_qty)),
                        diff_qty: parseFloat(d.diff_qty), unit_cost: parseFloat(d.unit_cost), reason: d.reason || '', note: d.note || ''
                    }));
                });
                updateSummary();
            } });
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fWh.getValue()) { showErr('Gudang wajib dipilih.'); return; }
        if (!fDate.getValue()) { showErr('Tanggal opname wajib diisi.'); return; }
        if (!itemDs.getCount()) { showErr('Tambahkan minimal satu material.'); return; }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var c = hasCount(r) ? parseFloat(r.get('counted_qty')) : null;
            if (c !== null && c < 0) { err = err || ('Qty hitung ' + r.get('material_name') + ' tidak boleh negatif.'); return; }
            arr.push({ material_id: r.get('material_id'), counted_qty: c, unit_cost: parseFloat(r.get('unit_cost')) || 0,
                       reason: r.get('reason') || '', note: r.get('note') || '' });
        });
        if (err) { showErr(err); return; }
        ajax({
            action: 'save', id: editingId, warehouse_id: fWh.getValue(), opname_date: ymd(fDate.getValue()), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)          // detail bertingkat -> JSON via POST
        }, function (res) {
            if (res.success) {
                document.getElementById('editorWdw').style.display = 'none';
                Ext.Msg.alert('Sukses', res.message || 'Opname tersimpan.');
                mainDs.load();
                var newId = res.id || (res.data && res.data.id);        // WhOk: id di top-level atau di dalam data
                if (newId) { dataRecord = { id: newId, status: 'Draft' }; updateMainButtons(); loadDetailPanel(newId); }
            } else {
                showErr(res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) { document.getElementById('popCancel').style.display = 'none'; }
    };
    document.getElementById('editorWdw').style.display = 'none';
});