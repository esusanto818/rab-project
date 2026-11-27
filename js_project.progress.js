/*
  Document      : project.progress.js
  Description   : Entry Progress Proyek & Verifikasi Auditor Head Office (HO). ExtJS 3.4.1
                  Mengikuti struktur acuan purchase.return.js:
                  - Main Grid (#divMainGrid) + Panel Detail di bawah (#prgDtlContainer)
                  - Modal Editor (#editorWdw bawaan template)
                  - Alur Status: Draft -> Submitted -> Verified (HO) / Rejected (HO)
                  - Pre-check progress vs pemakaian anggaran
  Backend       : ../php-crud/project.progressCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();

    // Styling khusus header grid editor & modal dark theme (Disuntik persis acuan purchase.return.js)
    (function () {
        var old = document.getElementById('gridEditorHdrCss');
        if (old) old.parentNode.removeChild(old);
        var H = 'body .x-grid3-gridEditor ';
        var css = [
            H + '.x-grid3-header,' + H + '.x-grid3-header-offset,' + H + '.x-grid3-header-inner,' + H + '.x-grid3-hd-row,',
            H + '.x-grid3-hd-row td.x-grid3-hd,' + H + '.x-grid3-hd-row td.x-grid3-hd-over,' + H + '.x-grid3-hd-row td.x-grid3-hd-click,',
            H + '.x-grid3-hd-row td.sort-asc,' + H + '.x-grid3-hd-row td.sort-desc,' + H + '.x-grid3-hd-row td.x-grid3-hd-menu-open{',
            '  background:#194a82 !important;background-image:none !important;border-top:none !important;',
            '  border-bottom:2px solid #2b6cb0 !important;border-left:none !important;border-right:1px solid rgba(255,255,255,.12) !important;',
            '  vertical-align:middle !important;}',
            H + '.x-grid3-hd-row td.x-grid3-hd:last-child{border-right:none !important;}',
            H + '.x-grid3-hd-inner,' + H + 'td.x-grid3-hd-over .x-grid3-hd-inner,' + H + 'td.sort-asc .x-grid3-hd-inner,' + H + 'td.sort-desc .x-grid3-hd-inner{',
            '  background:transparent !important;background-image:none !important;border:none !important;',
            '  padding:8px 8px !important;white-space:normal !important;text-overflow:clip !important;',
            '  line-height:14px !important;height:auto !important;color:#ffffff !important;}',
            H + '.x-grid3-hd-btn,' + H + 'img.x-grid3-sort-icon{display:none !important;}',
            '.rtDarkWin .x-form-item label{color:#9fc3d1 !important;}',
            '.rtDarkWin .x-form-display-field{color:#e2e8f0 !important;}',
            '.rtDarkWin .x-form-empty-field{color:#9fb4c7 !important;}',
            '.rtDarkWin .x-btn button{color:#ffffff !important;font-weight:bold !important;opacity:1 !important;}',
            '.rtDarkWin .x-btn-tl,.rtDarkWin .x-btn-tr,.rtDarkWin .x-btn-tc,.rtDarkWin .x-btn-ml,.rtDarkWin .x-btn-mr,',
            '.rtDarkWin .x-btn-bl,.rtDarkWin .x-btn-br,.rtDarkWin .x-btn-bc{background:none !important;background-image:none !important;}',
            '.rtDarkWin .x-btn-mc{background-color:#2e7dd1 !important;background-image:none !important;border-radius:4px;}',
            '.rtDarkWin .x-btn-over .x-btn-mc{background-color:#4695e6 !important;}',
            '.rtDarkWin .x-btn-mc button{padding:2px 8px;}'
        ].join('\n');
        var st = document.createElement('style');
        st.id = 'gridEditorHdrCss';
        st.type = 'text/css';
        st.innerHTML = css;
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    document.getElementById("mainHeaderText").innerHTML = "Progress <em>Proyek & Verifikasi HO</em>";

    var CRD = '../php-crud/project.progressCRD.php';
    var ACCESS_ADD = 2;   // Hak akses entry/submit progress (Lapangan)
    var ACCESS_EDIT = 3;  // Hak akses verifikasi/reject (Auditor HO)

    var dataRecord = null;
    var editId = 0;
    var hdrForm = null;
    var fProject, fPeriodDate, fBaDoc, fNotes, fProjInfo;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtPct(v) { return Ext.util.Format.number(v || 0, '0,000.00') + '%'; }
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    function parseYmd(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(str || '');
        return m ? new Date(parseInt(m[1], 10), parseInt(m[2], 10) - 1, parseInt(m[3], 10)) : null;
    }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }

    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else {
            try { var o = Ext.decode(res.responseText); if (o && o.message) { m = o.message; } }
            catch (e) { if (res && res.status) { m += ' (HTTP ' + res.status + ')'; } }
        }
        showErr(m);
    }

    function ajax(params, onOk) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: params,
            success: function (r) {
                var o;
                try { o = Ext.decode(r.responseText); } catch (e) { showErr('Respon server tidak valid.'); return; }
                onOk(o);
            },
            failure: function (r) { showErr('Koneksi ke server gagal (HTTP ' + r.status + ').'); }
        });
    }

    function statusColor(v) {
        switch (v) {
            case 'DRAFT': return '#e6a817 !important';
            case 'SUBMITTED': return '#4fa3ff !important';
            case 'VERIFIED': return '#00ff4c !important';
            case 'REJECTED': return '#c0392b !important';
        }
        return '#ffffff !important';
    }

    function evidenceLink(v) {
        if (!v) return '-';
        if (!/^(https?:\/\/|\/)/i.test(v)) return enc(v);
        var label = /\.(jpe?g|png|gif|webp)(\?.*)?$/i.test(v) ? 'Foto Evidens' : (/\.pdf(\?.*)?$/i.test(v) ? 'PDF Berita Acara' : 'Link Dokumen');
        return '<a href="' + enc(v) + '" target="_blank" onclick="event.stopPropagation();" style="font-weight:bold">' + label + '</a>';
    }

    function doAction(params, okTitle) {
        ajax(params, function (res) {
            Ext.Msg.alert(res.success ? (okTitle || 'Sukses') : 'Gagal', res.message || '');
            if (res.success) {
                var id = dataRecord ? dataRecord.id : 0;
                dtlDiv.style.display = 'none';
                dataRecord = null;
                updateMainButtons();
                mainDs.load({
                    callback: function () {
                        var idx = mainDs.find('id', id);
                        if (idx >= 0) {
                            dataRecord = mainDs.getAt(idx).data;
                            updateMainButtons();
                            loadDetailPanel(dataRecord);
                        }
                    }
                });
            }
        });
    }

    function needAccess(level, msg) {
        if (getFormAccess() < level) { Ext.Msg.alert('Akses Ditolak', msg); return false; }
        return true;
    }

    // ------------------------------------------------------------
    // 0. Panel Detail (Master-Detail di bawah main grid)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var GridMainHeight = maxGridHeight(gridContainer);
    var DtlHeight = Math.round(GridMainHeight - (GridMainHeight * 0.6) - 30);

    var dtlDiv = document.createElement('div');
    dtlDiv.id = 'prgDtlContainer';
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.maxHeight = Math.max(DtlHeight, 220) + 'px';
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }

    function loadDetailPanel(rec) {
        ajax({ action: 'detail', id: rec.id }, function (res) {
            if (!res.success) {
                dtlDiv.innerHTML = '<div style="padding:10px">' + enc(res.message || 'Gagal memuat detail.') + '</div>';
                dtlDiv.style.display = 'block';
                return;
            }
            renderDetailPanel(rec, res.data || []);
        });
    }

    function renderDetailPanel(h, items) {
        var actions = [];
        if (h.status === 'DRAFT') {
            actions = [['submit', 'Submit ke HO', '#03615e']];
        } else if (h.status === 'SUBMITTED') {
            actions = [['verify', 'Verifikasi & Approve (HO)', '#03615e'], ['reject', 'Tolak / Reject (HO)', '#8e2b22']];
        }

        var actHtml = '';
        if (actions.length) {
            actHtml = '<div style="float:right; margin:2px 4px 0 0">';
            Ext.each(actions, function (a) {
                actHtml += '<button type="button" class="rtActionBtn" data-act="' + a[0] + '" style="margin-left:8px; padding:8px 16px; background:' + a[2] +
                           '; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">' + enc(a[1]) + '</button>';
            });
            actHtml += '</div>';
        }

        var html = actHtml + '<h3 class="detail-title">Detail Laporan Progress: Proyek ' + enc(h.project_name) +
                   ' <span >(' + enc(h.status) + ')</span></h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px">Tgl Cut-Off: <b>' + enc(h.period_date) + '</b> &nbsp;|&nbsp; Cumulative Progress: <b >' + fmtPct(h.progress_pct) +
                   '</b> &nbsp;|&nbsp; Kenaikan (Delta): <b >+' + fmtPct(h.progress_delta) +
                   '</b> &nbsp;|&nbsp; Pelapor: <b>' + enc(h.reported_by_name || '-') +
                   '</b> &nbsp;|&nbsp; Dokumen BA: ' + evidenceLink(h.ba_document_url) +
                   (h.verified_by_name ? ' &nbsp;|&nbsp; Verifikator HO: <b>' + enc(h.verified_by_name) + '</b> (' + enc(h.verified_at || '-') + ')' : '') +
                   (h.rejection_note ? ' &nbsp;|&nbsp; <span >Catatan Penolakan: ' + enc(h.rejection_note) + '</span>' : '') + '</div>' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead><tr class="header-row">' +
                   '<th align="left">Kode Pekerjaan</th><th align="left">Nama Item Pekerjaan (RAB)</th><th align="right">Qty RAB (Plan)</th>' +
                   '<th align="right">Qty Terpasang (Aktual)</th><th align="right">% Terpasang</th><th align="left">Keterangan Field</th>' +
                   '</tr></thead><tbody>';

        if (!items.length) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center">Belum ada item rincian pekerjaan.</td></tr>';
        }

        Ext.each(items, function (i) {
            html += '<tr class="data-row">' +
                    '<td align="left"><span >' + enc(i.item_code || '-') + '</span></td>' +
                    '<td align="left"><b>' + enc(i.item_name) + '</b></td>' +
                    '<td align="right">' + fmtQty(i.qty_plan) + ' ' + enc(i.unit || '') + '</td>' +
                    '<td align="right" ><b>' + fmtQty(i.qty_installed) + ' ' + enc(i.unit || '') + '</b></td>' +
                    '<td align="right" class="subtotal-val"><b>' + fmtPct(i.installed_pct) + '</b></td>' +
                    '<td align="left">' + enc(i.notes || '-') + '</td></tr>';
        });
        html += '</tbody></table>';

        dtlDiv.innerHTML = html;
        dtlDiv.style.display = 'block';

        var btns = dtlDiv.querySelectorAll('.rtActionBtn');
        for (var i = 0; i < btns.length; i++) {
            btns[i].onclick = function () {
                var act = this.getAttribute('data-act');
                runAction(h, act);
            };
        }
    }

    function runAction(rec, act) {
        if (act === 'submit') {
            if (!needAccess(ACCESS_ADD, 'Akses ditolak.')) return;
            Ext.Msg.confirm('Submit Progress', 'Kirim laporan progress ke HO untuk diverifikasi?', function (b) {
                if (b === 'yes') doAction({ action: 'submit', id: rec.id });
            });
        } else if (act === 'verify') {
            if (!needAccess(ACCESS_EDIT, 'Hanya Auditor HO / Manajemen yang berhak memverifikasi.')) return;
            Ext.Msg.confirm('Verifikasi HO', 'Setujui laporan progress ini? Progress resmi proyek akan diperbarui.', function (b) {
                if (b === 'yes') doAction({ action: 'verify', id: rec.id });
            });
        } else if (act === 'reject') {
            if (!needAccess(ACCESS_EDIT, 'Hanya Auditor HO / Manajemen yang berhak menolak.')) return;
            Ext.Msg.prompt('Tolak Laporan Progress', 'Masukkan alasan penolakan untuk dikoreksi site engineer:', function (btn, text) {
                if (btn === 'ok') {
                    if (!text || !text.trim()) { showErr('Alasan penolakan wajib diisi.'); return; }
                    doAction({ action: 'reject', id: rec.id, rejection_note: text.trim() });
                }
            }, true);
        }
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    mainDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'list', start: 0, limit: 250 },
        root: 'data', totalProperty: 'totalCount', idProperty: 'id',
        fields: ['id', 'project_id', 'project_name', 'period_date', 'progress_pct', 'progress_delta',
                 'ba_document_url', 'status', 'rejection_note', 'reported_by_name', 'verified_by_name', 'verified_at',
                 { name: 'line_count', type: 'int' }],
        listeners: { exception: storeError }
    });
    mainDs.load();

    var projectDs = new Ext.data.JsonStore({
        url: CRD, baseParams: { action: 'lookup_projects' }, root: 'data', idProperty: 'id',
        fields: ['id', 'project_name', 'budget', 'current_progress', 'spent_amount'],
        listeners: { exception: storeError }
    });

    var itemField = ['budget_item_id', 'item_code', 'item_name', 'unit',
                     { name: 'qty_plan', type: 'float' }, { name: 'qty_installed', type: 'float' },
                     { name: 'installed_pct', type: 'float' }, 'notes'];
    var itemDs = new Ext.data.Store({ reader: new Ext.data.JsonReader({ root: 'data' }, itemField) });

    // ------------------------------------------------------------
    // 2. Main Grid (Daftar Laporan Progress Proyek)
    // ------------------------------------------------------------
    var mainHeight = Math.round(GridMainHeight * 0.6);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'Proyek', dataIndex: 'project_name', width: 200, renderer: CellWrap },
            { header: 'Tgl Cut-Off', dataIndex: 'period_date', width: 90, align: 'center' },
            { header: 'Cum. Progress (%)', dataIndex: 'progress_pct', width: 110, align: 'right',
              renderer: function (v) { return '<b >' + fmtPct(v) + '</b>'; } },
            { header: 'Delta (+%)', dataIndex: 'progress_delta', width: 90, align: 'right',
              renderer: function (v) { return '<span >+' + fmtPct(v) + '</span>'; } },
            { header: 'Pelapor', dataIndex: 'reported_by_name', width: 120 },
            { header: 'Bukti BA / Photo', dataIndex: 'ba_document_url', width: 120, renderer: evidenceLink },
            { header: 'Verifikator HO', dataIndex: 'verified_by_name', width: 120, renderer: function (v) { return v || '-'; } },
            { header: 'Status', dataIndex: 'status', width: 95, align: 'center',
              renderer: function (v) { return '<span style="font-weight:bold">' + enc(v || '-') + '</span>'; } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
                loadDetailPanel(dataRecord);
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        if (dataRecord && (dataRecord.status === 'DRAFT' || dataRecord.status === 'REJECTED')) {
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
    // 3. Editor Window & Grid Rincian Progress per RAB Item
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 280,
        enableHdMenu: false,
        enableColumnMove: false,
        columns: [
            { sortable: false, menuDisabled: true, header: 'Kode', dataIndex: 'item_code', width: 70 },
            { sortable: false, menuDisabled: true, header: 'Item RAB Pekerjaan', dataIndex: 'item_name', width: 180 },
            { sortable: false, menuDisabled: true, header: 'Sat', dataIndex: 'unit', width: 45, align: 'center' },
            { sortable: false, menuDisabled: true, header: 'Qty RAB', dataIndex: 'qty_plan', width: 85, align: 'right', renderer: function (v) { return fmtQty(v); } },
            { sortable: false, menuDisabled: true, header: 'Qty Terpasang (Aktual)', dataIndex: 'qty_installed', width: 120, align: 'right',
              editor: new myNumberField({ decimalPrecision: 2 }),
              renderer: function (v) { return '<b>' + fmtQty(v) + '</b>'; } },
            { sortable: false, menuDisabled: true, header: '% Terpasang', dataIndex: 'installed_pct', width: 85, align: 'right',
              renderer: function (v) { return '<b >' + fmtPct(v) + '</b>'; } },
            { sortable: false, menuDisabled: true, header: 'Keterangan Field', dataIndex: 'notes', width: 160, editor: new myTextField(), renderer: function (v) { return enc(v); } }
        ],
        listeners: {
            validateedit: function (e) {
                if (e.field === 'qty_installed') {
                    var val = parseFloat(e.value) || 0;
                    var plan = parseFloat(e.record.get('qty_plan')) || 0;
                    if (val < 0) { showErr('Qty terpasang tidak boleh negatif.'); e.cancel = true; return; }
                    var pct = plan > 0 ? (val / plan) * 100 : 0;
                    e.record.set('installed_pct', pct);
                }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    (function injectExtraUI() {
        var g = document.getElementById('editorFormGrid');
        if (g && !document.getElementById('prgHdrForm')) {
            var hdr = document.createElement('div');
            hdr.id = 'prgHdrForm';
            hdr.style.cssText = 'margin-bottom:6px;';
            g.parentNode.insertBefore(hdr, g);
        }
    })();

    function openBaUploadWin() {
        var fUrl = new Ext.form.TextField({ fieldLabel: 'Link / URL', anchor: '98%', emptyText: 'http://... atau upload file PDF/Foto' });
        var fileInput = document.createElement('input');
        fileInput.type = 'file';
        fileInput.accept = 'image/*,.pdf';
        fileInput.style.display = 'none';
        document.body.appendChild(fileInput);

        fileInput.onchange = function () {
            var f = fileInput.files && fileInput.files[0];
            if (!f) return;
            if (f.size > 5 * 1024 * 1024) { showErr('Ukuran file maksimal 5 MB.'); return; }
            var fd = new FormData();
            fd.append('action', 'upload_ba');
            fd.append('file', f);
            var xhr = new XMLHttpRequest();
            xhr.open('POST', CRD);
            xhr.onload = function () {
                var o = Ext.decode(xhr.responseText);
                if (o.success) {
                    fBaDoc.setValue(o.url);
                    win.close();
                } else { showErr(o.message || 'Upload gagal.'); }
            };
            xhr.send(fd);
        };

        var win = new Ext.Window({
            title: 'Upload Berita Acara / Evidens', width: 450, height: 160, modal: true, layout: 'fit', cls: 'rtDarkWin',
            items: new Ext.form.FormPanel({
                border: false, labelWidth: 80, bodyStyle: 'padding:12px',
                items: [fUrl, { xtype: 'button', text: 'Pilih File dari Komputer...', handler: function () { fileInput.click(); } }]
            }),
            buttons: [
                { text: 'Gunakan Link', handler: function () { fBaDoc.setValue(fUrl.getValue()); win.close(); } },
                { text: 'Batal', handler: function () { win.close(); } }
            ]
        });
        win.show();
    }

    function ensureHdrForm() {
        if (hdrForm) { hdrForm.doLayout(); return; }

        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'project_name', valueField: 'id',
            mode: 'remote', queryParam: 'query', minChars: 0, triggerAction: 'all', forceSelection: true, allowBlank: false,
            anchor: '98%', emptyText: 'Pilih Proyek...',
            listeners: { select: function (combo, rec) { onProjectSelected(rec); } }
        });
        fPeriodDate = new Ext.form.DateField({ fieldLabel: 'Tgl Cut-Off', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
        fBaDoc = new myTextField({ fieldLabel: 'Dokumen BA / Photo', anchor: '85%', readOnly: true });
        fProjInfo = new Ext.form.DisplayField({ fieldLabel: 'Info Proyek', value: '-', anchor: '95%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, , items: items };
        };

        hdrForm = new Ext.form.FormPanel({
            renderTo: 'prgHdrForm',
            border: false,
            layout: 'column',
            bodyStyle: 'padding:4px 2px 0 2px',
            items: [
                col(0.5, [fProject, fPeriodDate]),
                col(0.5, [
                    {
                        layout: 'column', border: false, ,
                        items: [
                            { columnWidth: 0.8, layout: 'form', border: false, labelWidth: 110, , items: [fBaDoc] },
                            { columnWidth: 0.2, layout: 'form', border: false, , items: [
                                { xtype: 'button', text: 'Upload...', handler: openBaUploadWin }
                            ]}
                        ]
                    },
                    fProjInfo
                ])
            ]
        });
    }

    function onProjectSelected(rec) {
        if (!rec) { fProjInfo.setValue('-'); itemDs.removeAll(); return; }
        fProjInfo.setValue('Progress Saat Ini: <b >' + fmtPct(rec.get('current_progress')) + '</b> | Budget: <b>Rp ' + fmtQty(rec.get('budget')) + '</b>');
        loadProjectRabItems(rec.get('id'));
    }

    function loadProjectRabItems(projectId, cb) {
        ajax({ action: 'rab_items', project_id: projectId, progress_id: editId }, function (res) {
            if (!res.success) { itemDs.removeAll(); showErr(res.message || 'Gagal memuat item RAB.'); return; }
            itemDs.loadData(res);
            if (cb) cb();
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
    // 4. Action Handlers (Add, Edit, Save, Delete)
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (!needAccess(ACCESS_ADD, 'Anda tidak memiliki akses membuat laporan progress.')) return;
        editId = 0;
        showEditor('Input Laporan Progress Proyek Baru');
        fProject.enable();
        fProject.clearValue();
        fProjInfo.setValue('-');
        fPeriodDate.setValue(new Date());
        fBaDoc.setValue('');
        itemDs.removeAll();
        projectDs.load();
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) { showErr('Pilih data laporan dari tabel.'); return; }
        if (dataRecord.status !== 'DRAFT' && dataRecord.status !== 'REJECTED') {
            showErr('Hanya laporan berstatus DRAFT atau REJECTED yang dapat diedit.'); return;
        }
        if (!needAccess(ACCESS_ADD, 'Akses ditolak.')) return;

        var rec = dataRecord;
        editId = rec.id;
        showEditor('Edit Laporan Progress Proyek');
        projectDs.load({
            params: { query: rec.project_name || '' },
            callback: function () {
                fProject.setValue(rec.project_id);
                fProject.disable();
                fPeriodDate.setValue(parseYmd(rec.period_date) || new Date());
                fBaDoc.setValue(rec.ba_document_url || '');
                loadProjectRabItems(rec.project_id);
            }
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        if (!fProject.getValue() || !fPeriodDate.getValue()) {
            showErr('Proyek dan Tanggal Cut-Off wajib diisi.'); return;
        }
        var arr = [];
        itemDs.each(function (r) {
            var q = parseFloat(r.get('qty_installed')) || 0;
            if (q >= 0) {
                arr.push({
                    budget_item_id: r.get('budget_item_id'),
                    qty_installed: q,
                    installed_pct: parseFloat(r.get('installed_pct')) || 0,
                    notes: r.get('notes') || ''
                });
            }
        });

        ajax({
            action: 'save',
            id: editId,
            project_id: fProject.getValue(),
            period_date: ymd(fPeriodDate.getValue()),
            ba_document_url: fBaDoc.getValue(),
            details: Ext.util.JSON.encode(arr)
        }, function (res) {
            if (res.success) {
                Ext.Msg.alert('Sukses', res.message || 'Laporan progress tersimpan.');
                document.getElementById('editorWdw').style.display = 'none';
                dataRecord = null;
                dtlDiv.style.display = 'none';
                mainDs.load();
                updateMainButtons();
            } else {
                showErr(res.message || 'Gagal menyimpan.');
            }
        });
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord || (dataRecord.status !== 'DRAFT' && dataRecord.status !== 'REJECTED')) {
            showErr('Pilih laporan berstatus DRAFT / REJECTED.'); return;
        }
        Ext.Msg.confirm('Hapus Laporan', 'Hapus draft laporan progress ini?', function (b) {
            if (b === 'yes') doAction({ action: 'delete', id: dataRecord.id });
        });
    };

    document.getElementById('editorWdw').style.display = 'none';
    var addWdwInit = document.getElementById('addWdw');
    if (addWdwInit) addWdwInit.style.display = 'none';
});
