/*
  Document      : spk.js
  Description   : SPK (Surat Perintah Kerja). Grid utama = daftar SPK; klik baris -> panel detail (jatah, terpakai, sisa) dan
                  tombol aksi status. Add/Edit membuka editor (header + grid material) untuk SPK berstatus Draft.
                  Aturan: SPK baru dapat DITERBITKAN setelah proyek menerima pembayaran termin; qty tiap material dibatasi kuota
                  = qty RAB x (% kontrak dibayar x 2), maks 100% (dihitung server). Warehouse Issuing proyek wajib memilih SPK.
                  Alur status: Draft -> Diterbitkan -> Selesai ; Dibatalkan dari Draft / Diterbitkan yang belum dipakai issue.
                  Pola mengikuti po.js / warehouse.issuing.js (template many-template-icn.php).
  Backend       : /proyek/php-crud/spkCRD.php  (kuota: php-func/function.spk.php)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "<em>SPK</em> - Surat Perintah Kerja";

    var CRD =  main_url + '/php-crud/spkCRD.php';
    var ACCESS_EDIT = 2;      // buat / ubah Draft
    var ACCESS_ACTION = 3;    // terbitkan / tutup / batalkan / hapus
    var EPS = 0.00005;

    var dataRecord = null;    // baris SPK terpilih
    var editingId = 0;        // 0 = SPK baru
    var hdrForm = null, matBar = null;
    var fDate, fProject, fForeman, fDesc, fRemark, fMat, quotaDiv;

    function getFormAccess() { return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0); }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtPct(v) { return Ext.util.Format.number(v || 0, '0,000.00') + ' %'; }
    function ymd(d) {         // Date.format('Y-m-d') pernah bermasalah di sebagian browser -> format manual
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }
    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function statusColor(v) {
        switch (v) {
            case 'Draft': return '#999999 !important';
            case 'Diterbitkan': return '#2bc1e2 !important';
            case 'Selesai': return '#00ff4c !important';
            case 'Dibatalkan': return '#c0392b !important';
        }
        return '#ffffff !important';
    }

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

    // ------------------------------------------------------------
    // 0. Panel detail di bawah grid utama
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'spkDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30) + 'px';
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling); }

    function loadDetailPanel(id) {
        ajax({ action: 'detail', id: id }, function (res) {
            if (!res.success) { dtlDiv.innerHTML = '<div style="color:yellow; padding:10px;">' + enc(res.message || 'Gagal memuat detail SPK.') + '</div>'; dtlDiv.style.display = 'block'; return; }
            renderDetailPanel(res);
        });
    }

    function actionBtn(id, label, bg) {
        return '<button type="button" id="' + id + '" style="margin-left:8px; padding:8px 16px; background:' + bg + '; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">' + label + '</button>';
    }

    function renderDetailPanel(d) {
        var h = d.header, pay = d.pay, items = d.items || [];
        var btns = actionBtn('spkPrintBtn', 'Cetak SPK', '#2e6da4');
        if (h.status === 'Draft') { btns += actionBtn('spkIssueBtn', 'Terbitkan SPK', '#03615e'); }
        if (h.status === 'Diterbitkan') { btns += actionBtn('spkCloseBtn', 'Tutup SPK (Selesai)', '#555555'); }
        if ((h.status === 'Draft' || h.status === 'Diterbitkan') && !(parseInt(d.issue_count, 10) > 0)) { btns += actionBtn('spkCancelBtn', 'Batalkan SPK', '#8e2b22'); }

        var html = '<div style="float:right; margin:2px 4px 0 0;">' + btns + '</div>' +
                   '<h3 class="detail-title">Detail SPK: ' + enc(h.spk_no) + ' <span style="color:' + statusColor(h.status) + ';">(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 6px 2px;">Tanggal: <b>' + enc(h.spk_date) + '</b> &nbsp;|&nbsp; Proyek: <b>' + enc(h.project_name) +
                   '</b> &nbsp;|&nbsp; Klien: <b>' + enc(h.client_name || '-') + '</b> &nbsp;|&nbsp; Mandor: <b>' + enc(h.foreman || '-') + '</b>' +
                   (h.description ? ' &nbsp;|&nbsp; Uraian: ' + enc(h.description) : '') + '</div>' +
                   '<div style="padding:4px 10px; margin-bottom:8px; background:rgba(15,113,194,0.18); border-radius:3px;">Pembayaran proyek: <b>' + fmtAmt(pay.paid_amount) +
                   '</b> dari kontrak <b>' + fmtAmt(pay.contract_value) + '</b> (<b>' + fmtPct(pay.paid_pct) + '</b>) &rarr; batas material saat ini <b>' + fmtPct(pay.allowed_pct) +
                   '</b> dari qty RAB (dibayar x 2).</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                   '<th align="left">Material</th><th align="right">Qty SPK</th><th align="right">Terpakai (issue)</th><th align="right">Sisa Jatah</th><th align="left">Catatan</th></tr></thead><tbody>';
        if (!items.length) { html += '<tr><td colspan="5" style="padding:15px; text-align:center; color:#557982;">Belum ada item.</td></tr>'; }
        Ext.each(items, function (i) {
            var color = i.remaining <= EPS ? '#2e9e4f' : (i.used > 0 ? '#e6a817' : '#668894');
            html += '<tr class="data-row"><td align="left">' + enc(i.material_name) + ' <span style="color:#668894;">(' + enc(i.material_code) + ')</span></td>' +
                    '<td align="right">' + fmtQty(i.qty) + ' ' + enc(i.unit) + '</td>' +
                    '<td align="right" style="color:' + color + ';">' + fmtQty(i.used) + '</td>' +
                    '<td align="right"><b>' + fmtQty(i.remaining) + '</b></td><td align="left">' + enc(i.notes || '') + '</td></tr>';
        });
        html += '</tbody></table>';
        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var b;
        if ((b = document.getElementById('spkPrintBtn'))) b.onclick = function () { window.open('../pdf/spk.php?id=' + h.id, '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes'); };
        if ((b = document.getElementById('spkIssueBtn'))) b.onclick = function () { doAction('issue', h, 'Terbitkan SPK <b>' + enc(h.spk_no) + '</b>? Sistem memeriksa pembayaran termin dan kuota material.'); };
        if ((b = document.getElementById('spkCloseBtn'))) b.onclick = function () { doAction('close', h, 'Tutup SPK <b>' + enc(h.spk_no) + '</b>? Sisa jatah yang belum terpakai kembali menjadi kuota proyek.'); };
        if ((b = document.getElementById('spkCancelBtn'))) b.onclick = function () { doAction('cancel', h, 'Batalkan SPK <b>' + enc(h.spk_no) + '</b>?'); };
    }

    function doAction(action, h, text) {
        if (!needAccess(ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk aksi ini.')) return;
        Ext.Msg.confirm('Konfirmasi', text, function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: action, id: h.id }, function (res) {
                if (!res.success) { showErr(res.message || 'Proses gagal.'); return; }
                Ext.Msg.alert('Sukses', res.message || '');
                mainDs.reload({ callback: function () {
                    var idx = mainDs.find('id', h.id);
                    if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; }
                    updateMainButtons();
                    loadDetailPanel(h.id);
                } });
            });
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list' }, root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'spk_no', 'spk_date', 'project_id', 'project_name', 'foreman', 'description', 'status', 'remark',
                 { name: 'item_count', type: 'int' }, { name: 'used_pct', type: 'float' }],
        listeners: { exception: storeError }
    });
    mainDs.load();

    var projectDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', fields: ['id', 'name'],
        listeners: { exception: storeError }
    });
    var materialDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_materials' }, root: 'data',
        fields: ['material_id', 'material_code', 'material_name', 'unit',
                 { name: 'rab_qty', type: 'float' }, { name: 'cap_qty', type: 'float' }, { name: 'available', type: 'float' }],
        listeners: { exception: storeError }
    });
    var itemField = ['material_id', 'material_code', 'material_name', 'unit',
                     { name: 'rab_qty', type: 'float' }, { name: 'cap_qty', type: 'float' }, { name: 'available', type: 'float' },
                     { name: 'qty', type: 'float' }, 'notes'];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: Math.round(GridMainHeight * 0.6),
        columns: [
            { header: 'No. SPK', dataIndex: 'spk_no', width: 130 },
            { header: 'Tanggal', dataIndex: 'spk_date', width: 90, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 230, renderer: CellWrap },
            { header: 'Mandor', dataIndex: 'foreman', width: 130, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Uraian', dataIndex: 'description', width: 220, renderer: function (v) { return CellWrap(v || ''); } },
            { header: 'Item', dataIndex: 'item_count', width: 50, align: 'center' },
            { header: 'Terpakai', dataIndex: 'used_pct', width: 80, align: 'right', renderer: function (v) { return fmtPct(v); } },
            { header: 'Status', dataIndex: 'status', width: 100, align: 'center',
              renderer: function (v) { return '<span style="color:' + statusColor(v) + ';font-weight:bold;">' + enc(v) + '</span>'; } }
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
        if (dataRecord && dataRecord.status === 'Draft') { mainBtnEnable('#editData'); mainBtnEnable('#deleteData'); }
        else { mainBtnDisable('#editData'); mainBtnDisable('#deleteData'); }
    }
    editDataDisable();
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: header + grid material
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs, renderTo: 'editorFormGrid', viewConfig: { forceFit: true, scrollOffset: 0 }, height: 280,
        enableHdMenu: false, enableColumnMove: false,
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'material_code', width: 90 },
            { sortable: false, menuDisabled: true, header: 'Material', dataIndex: 'material_name', width: 220 },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 50, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Qty RAB', dataIndex: 'rab_qty', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Kuota Saat Ini', dataIndex: 'cap_qty', width: 100, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Tersedia', dataIndex: 'available', width: 90, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty SPK', dataIndex: 'qty', width: 90, align: 'right',
              editor: new myNumberField({ decimalPrecision: 4 }), renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { sortable: false, menuDisabled: true, header: 'Catatan', dataIndex: 'notes', width: 140, editor: new myTextField({}) },
            { id: 'spkDeleteCol', sortable: false, menuDisabled: true, header: '', dataIndex: 'material_id', width: 30, align: 'center', fixed: true,
              renderer: function () { return '<span style="color:#e05353;cursor:pointer;font-weight:bold;font-size:14px;" title="Hapus baris">&#10005;</span>'; } }
        ],
        listeners: {
            validateedit: function (e) {
                if (e.field === 'qty' && parseFloat(e.value) > parseFloat(e.record.get('available')) + EPS) {
                    showErr('Qty melebihi kuota tersedia (' + fmtQty(e.record.get('available')) + ').');
                    e.cancel = true;
                }
            },
            cellclick: function (grid, rowIndex, columnIndex) {
                if (grid.getColumnModel().getColumnId(columnIndex) === 'spkDeleteCol') { grid.getStore().removeAt(rowIndex); }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('spkHdrForm')) {
            var hdr = document.createElement('div'); hdr.id = 'spkHdrForm'; hdr.style.cssText = 'margin-bottom:2px;';
            g.parentNode.insertBefore(hdr, g);
            var q = document.createElement('div'); q.id = 'spkQuotaInfo'; q.style.cssText = 'margin:2px 4px 6px 4px;';
            g.parentNode.insertBefore(q, g);
            var bar = document.createElement('div'); bar.id = 'spkMatBar'; bar.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(bar, g);
        }
        quotaDiv = document.getElementById('spkQuotaInfo');
    })();

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); matBar.doLayout(); return; }

        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal SPK', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: { select: function () { onProjectChange(); } }
        });
        fForeman = new myTextField({ fieldLabel: 'Mandor / Pelaksana', anchor: '95%' });
        fDesc = new myTextField({ fieldLabel: 'Uraian Pekerjaan', anchor: '95%' });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });

        var col = function (w, items) { return { columnWidth: w, layout: 'form', border: false, labelWidth: 120, bodyStyle: 'background:transparent;', items: items }; };
        hdrForm = new Ext.form.FormPanel({
            renderTo: 'spkHdrForm', border: false, layout: 'column', bodyStyle: 'background:transparent; padding:4px 2px 0 2px;',
            items: [col(0.5, [fDate, fProject]), col(0.5, [fForeman, fDesc, fRemark])]
        });

        fMat = new myComboField({
            store: materialDs, displayField: 'material_name', valueField: 'material_id', mode: 'remote',
            queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, anchor: '98%', listWidth: 560,
            emptyText: 'Cari material dari RAB proyek...',
            tpl: '<tpl for="."><div class="x-combo-list-item">{material_code} - {material_name} ' +
                 '<b>(RAB {rab_qty}, kuota {cap_qty}, tersedia {available} {unit})</b></div></tpl>',
            listeners: {
                beforequery: function () {
                    if (!fProject.getValue()) { showErr('Pilih proyek terlebih dahulu.'); return false; }
                    materialDs.baseParams.project_id = fProject.getValue();
                    materialDs.baseParams.exclude_id = editingId;
                }
            }
        });
        matBar = new Ext.Panel({
            renderTo: 'spkMatBar', border: false, layout: 'column', bodyStyle: 'background:transparent; padding:2px;',
            items: [
                { columnWidth: 0.45, layout: 'form', border: false, hideLabels: true, bodyStyle: 'background:transparent;', items: [fMat] },
                { width: 150, border: false, bodyStyle: 'background:transparent; padding-left:6px;', items: [new Ext.Button({ text: '+ Tambah Material', handler: addMaterial })] },
                { width: 210, border: false, bodyStyle: 'background:transparent; padding-left:6px;', items: [new Ext.Button({ text: '+ Semua Material (Qty Maks)', handler: addAllMaterials })] },     // [DEFAULT-MAKS][NEW]
                { columnWidth: 0.4, border: false, bodyStyle: 'background:transparent; padding:6px 0 0 8px; color:#9fc3d1; font-size:11px;',
                  html: 'Qty SPK otomatis = kuota tersedia (maksimum), bisa dikurangi. Kuota = qty RAB x (% kontrak dibayar x 2).' }
            ]
        });
    }

    // info pembayaran + batas persen untuk proyek terpilih
    function loadQuotaInfo() {
        if (!fProject || !fProject.getValue()) { quotaDiv.innerHTML = ''; return; }
        ajax({ action: 'quota', project_id: fProject.getValue() }, function (res) {
            if (!res.success) { quotaDiv.innerHTML = '<span style="color:#e6a817;">' + enc(res.message || '') + '</span>'; return; }
            var p = res.data;
            var ok = p.paid_amount > 0;
            quotaDiv.innerHTML = '<div style="padding:6px 10px; background:#1f2d3a; border-radius:4px; color:' + (ok ? '#9fc3d1' : '#e6a817') + ';">' +
                'Pembayaran diterima: <b>' + fmtAmt(p.paid_amount) + '</b> dari kontrak <b>' + fmtAmt(p.contract_value) + '</b> (<b>' + fmtPct(p.paid_pct) +
                '</b>) &rarr; batas material saat ini <b>' + fmtPct(p.allowed_pct) + '</b> dari qty RAB.' +
                (ok ? '' : ' <b>Belum ada pembayaran termin: SPK dapat disimpan sebagai Draft tetapi belum dapat diterbitkan.</b>') + '</div>';
        });
    }

    function onProjectChange() {
        itemDs.removeAll();
        fMat.clearValue();
        loadQuotaInfo();
    }

    function addMaterial() {
        var rec = fMat.findRecord('material_id', fMat.getValue());
        if (!rec) { showErr('Pilih material terlebih dahulu.'); return; }
        if (itemDs.findBy(function (r) { return String(r.get('material_id')) === String(rec.get('material_id')); }) >= 0) { showErr('Material sudah ada di daftar.'); return; }
        if (rec.get('available') <= 0) { showErr('Tidak ada kuota tersedia untuk material ini (kuota habis / belum ada pembayaran / sudah dipakai SPK lain).'); return; }
        itemDs.add(new itemDs.recordType({
            material_id: rec.get('material_id'), material_code: rec.get('material_code'), material_name: rec.get('material_name'), unit: rec.get('unit'),
            rab_qty: rec.get('rab_qty'), cap_qty: rec.get('cap_qty'), available: rec.get('available'), qty: rec.get('available'), notes: ''
        }));
        fMat.clearValue();
    }

    // [DEFAULT-MAKS][NEW] Tambahkan SEMUA material RAB proyek yang masih punya kuota, Qty SPK = kuota tersedia (maksimum).
    // Material yang sudah ada di daftar dilewati. Qty tiap baris tetap bisa dikurangi (tidak boleh melebihi tersedia).
    function addAllMaterials() {
        if (!fProject.getValue()) { showErr('Pilih proyek terlebih dahulu.'); return; }
        ajax({ action: 'lookup_materials', project_id: fProject.getValue(), exclude_id: editingId }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat material.'); return; }
            var added = 0, skippedNoQuota = 0;
            Ext.each(res.data || [], function (m) {
                var avail = parseFloat(m.available) || 0;
                if (itemDs.findBy(function (r) { return String(r.get('material_id')) === String(m.material_id); }) >= 0) { return; }
                if (avail <= 0) { skippedNoQuota++; return; }
                itemDs.add(new itemDs.recordType({
                    material_id: m.material_id, material_code: m.material_code, material_name: m.material_name, unit: m.unit,
                    rab_qty: parseFloat(m.rab_qty) || 0, cap_qty: parseFloat(m.cap_qty) || 0, available: avail, qty: avail, notes: ''
                }));
                added++;
            });
            if (!added) { showErr('Tidak ada material yang bisa ditambahkan' + (skippedNoQuota ? ' (' + skippedNoQuota + ' material tanpa kuota tersedia).' : '.')); return; }
            Ext.Msg.alert('Material ditambahkan', added + ' material ditambahkan dengan qty maksimum.' +
                          (skippedNoQuota ? ' ' + skippedNoQuota + ' material dilewati karena tidak ada kuota tersedia.' : ''));
        });
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureHdrForm();
        itemGrid.doLayout();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk membuat SPK.')) return;
        editingId = 0;
        projectDs.load({ callback: function () {
            showEditor('SPK Baru');
            fDate.setValue(new Date()); fProject.clearValue(); fForeman.setValue(''); fDesc.setValue(''); fRemark.setValue('');
            itemDs.removeAll(); fMat.clearValue(); quotaDiv.innerHTML = '';
        } });
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih SPK terlebih dahulu dari tabel.'); return; }
        if (dataRecord.status !== 'Draft') { showErr('Hanya SPK berstatus Draft yang dapat diubah.'); return; }
        if (!needAccess(ACCESS_EDIT, 'Anda tidak memiliki hak akses untuk mengubah SPK.')) return;
        ajax({ action: 'detail', id: dataRecord.id }, function (res) {
            if (!res.success) { showErr(res.message || 'Gagal memuat SPK.'); return; }
            var h = res.header;
            editingId = parseInt(h.id, 10);
            projectDs.load({ callback: function () {
                showEditor('Edit SPK ' + h.spk_no);
                fDate.setValue(Date.parseDate(h.spk_date, 'Y-m-d') || new Date());
                fProject.setValue(parseInt(h.project_id, 10));
                fForeman.setValue(h.foreman || ''); fDesc.setValue(h.description || ''); fRemark.setValue(h.remark || '');
                fMat.clearValue(); itemDs.removeAll();
                loadQuotaInfo();
                // kuota tiap material (tanpa menghitung SPK ini sendiri) untuk validasi qty
                ajax({ action: 'lookup_materials', project_id: h.project_id, exclude_id: h.id }, function (q) {
                    var map = {};
                    Ext.each((q.data || []), function (m) { map[String(m.material_id)] = m; });
                    Ext.each(res.items, function (i) {
                        var m = map[String(i.material_id)] || {};
                        itemDs.add(new itemDs.recordType({
                            material_id: i.material_id, material_code: i.material_code, material_name: i.material_name, unit: i.unit,
                            rab_qty: parseFloat(m.rab_qty) || 0, cap_qty: parseFloat(m.cap_qty) || 0, available: parseFloat(m.available) || 0,
                            qty: parseFloat(i.qty), notes: i.notes || ''
                        }));
                    });
                });
            } });
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue() || !fDate.getValue()) { showErr('Proyek dan tanggal wajib diisi.'); return; }
        var arr = [], err = '';
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty')) || 0;
            if (q <= 0) { err = 'Qty SPK ' + r.get('material_name') + ' harus lebih besar dari 0.'; return false; }
            arr.push({ material_id: r.get('material_id'), qty: q, notes: r.get('notes') || '' });
        });
        if (err) { showErr(err); return; }
        if (!arr.length) { showErr('Tambahkan minimal satu material.'); return; }
        ajax({
            action: 'save', id: editingId, project_id: fProject.getValue(), spk_date: ymd(fDate.getValue()),
            foreman: fForeman.getValue(), description: fDesc.getValue(), remark: fRemark.getValue(),
            details: Ext.util.JSON.encode(arr)
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'SPK tersimpan.');
                document.getElementById('editorWdw').style.display = 'none';
                mainDs.reload({ callback: function () {
                    var idx = res.id ? mainDs.find('id', res.id) : -1;
                    if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; gridMain.getSelectionModel().selectRow(idx, false); updateMainButtons(); loadDetailPanel(dataRecord.id); }
                } });
            } else { showErr(res.message || 'Terjadi kesalahan sistem.'); }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || dataRecord.status !== 'Draft') { showErr('Pilih SPK berstatus Draft. SPK lain dibatalkan dari panel detail.'); return; }
        if (!needAccess(ACCESS_ACTION, 'Anda tidak memiliki hak akses untuk menghapus SPK.')) return;
        Ext.Msg.confirm('Hapus SPK', 'Hapus SPK <b>' + enc(dataRecord.spk_no) + '</b>? Tindakan ini tidak dapat dibatalkan.', function (btn) {
            if (btn !== 'yes') return;
            ajax({ action: 'delete', id: dataRecord.id }, function (res) {
                if (!res.success) { showErr(res.message || 'Gagal menghapus.'); return; }
                Ext.Msg.alert('Sukses', res.message || '');
                dataRecord = null; dtlDiv.style.display = 'none'; mainDs.reload(); updateMainButtons();
            });
        });
    };

    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';
        if (document.getElementById('popCancel')) { document.getElementById('popCancel').style.display = 'none'; }
    };
    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';
});