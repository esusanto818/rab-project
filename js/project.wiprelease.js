/*
  Document      : project.wiprelease.js
  Description   : Pelepasan WIP -> HPP per proyek. Grid utama = daftar pelepasan; Add membuka editor
                  (proyek, tanggal, mode, progress %) dengan pratinjau hitungan dari server.
                  Mode: 'Per Progress (%)' = HPP kumulatif = WIP kumulatif x progress %;
                        'Proyek Selesai'   = 100% (seluruh WIP dilepas).
                  Simpan memposting jurnal Dr HPP / Cr WIP (rule WIP_RELEASE).
                  Delete = reverse pelepasan TERAKHIR per proyek (jurnal dihapus). Edit tidak tersedia.
                  Pola mengikuti po.js (template many-template-icn.php).
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/project.wipreleaseCRD.php
=======
  Backend       : /rab-project/live/php-crud/project.wipreleaseCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Pelepasan <em>WIP</em>";

    var CRD = '../php-crud/project.wipreleaseCRD.php';
    var ACCESS_ADD = 2;      // level akses minimal: buat pelepasan
    var ACCESS_DEL = 3;      // level akses minimal: hapus (reverse)

    var dataRecord = null;   // baris pelepasan terpilih di grid utama
    var frm = null;          // form editor (dibuat saat editor pertama kali dibuka)
    var fDate, fProject, fMode, fPct, fRemark, fInfo;
    var previewReq = 0;      // penanda request pratinjau terbaru (abaikan respons usang)
    var previewOk = false;   // pratinjau terakhir valid & nilai dilepas > 0

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }
    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtPct(v) { return Ext.util.Format.number(v || 0, '0,000.00') + ' %'; }
    // Workaround: Date.format('Y-m-d') pernah mengembalikan 'Y-10-10' di sebagian browser -> format manual
    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }

    function showMsg(title, msg) { Ext.Msg.alert(title, msg); }

    function storeError(proxy, type, action, opt, res) {      // standar: setiap store punya handler exception
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showMsg('Gagal', m);
    }

    function ajax(params, onOk, onFail) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: params,
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showMsg('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                onOk(res);
            },
            failure: function () {
                if (onFail) onFail(); else showMsg('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    // ------------------------------------------------------------
    // 1. Stores
    // ------------------------------------------------------------
    var releaseField = [
        { name: 'id', type: 'int' }, { name: 'ref_no' }, { name: 'release_date' },
        { name: 'project_id', type: 'int' }, { name: 'project_name' }, { name: 'mode' },
        { name: 'progress_pct', type: 'float' }, { name: 'cumulative_cost', type: 'float' },
        { name: 'released_before', type: 'float' }, { name: 'amount', type: 'float' },
        { name: 'remark' }, { name: 'journal_no' }, { name: 'is_latest' }
    ];

    var projectDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_project_options' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id' }, { name: 'name' }, { name: 'progress', type: 'float' }, { name: 'status' }]),
        listeners: { exception: storeError }
    });

    var modeDs = new Ext.data.ArrayStore({
        fields: ['code', 'label'],
        data: [['progress', 'Per Progress (%)'], ['complete', 'Proyek Selesai (100%)']]
    });

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list_releases' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, releaseField),
        listeners: { exception: storeError }
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid (daftar pelepasan)
    // ------------------------------------------------------------
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: maxGridHeight(document.getElementById('divMainGrid')),
        columns: [
            { header: 'No. Pelepasan', dataIndex: 'ref_no', width: 120 },
            { header: 'Tanggal', dataIndex: 'release_date', width: 90, align: 'center' },
            { header: 'Proyek', dataIndex: 'project_name', width: 230, renderer: CellWrap },
            {
                header: 'Mode', dataIndex: 'mode', width: 110, align: 'center',
                renderer: function (v) { return v === 'complete' ? 'Proyek Selesai' : 'Per Progress'; }
            },
            { header: 'Progress', dataIndex: 'progress_pct', width: 80, align: 'right', renderer: function (v) { return fmtPct(v); } },
            { header: 'WIP Kumulatif', dataIndex: 'cumulative_cost', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Dilepas Sebelumnya', dataIndex: 'released_before', width: 130, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            {
                header: 'Nilai Dilepas (HPP)', dataIndex: 'amount', width: 140, align: 'right',
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            },
            { header: 'Jurnal', dataIndex: 'journal_no', width: 110, align: 'center', renderer: function (v) { return v || '-'; } },
            { header: 'Catatan', dataIndex: 'remark', width: 180, renderer: function (v) { return CellWrap(v || ''); } }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                dataRecord = rec.data;
                updateMainButtons();
            }
        }
    });

    function updateMainButtons() {
        mainBtnEnable('#addData');
        // hapus hanya untuk pelepasan terakhir proyek (server juga memvalidasi)
        if (dataRecord && dataRecord.is_latest) { mainBtnEnable('#deleteData'); } else { mainBtnDisable('#deleteData'); }
    }
    editDataDisable();          // Edit tidak tersedia: koreksi = hapus (reverse) lalu buat ulang
    updateMainButtons();

    // ------------------------------------------------------------
    // 3. Editor: form + pratinjau
    // ------------------------------------------------------------
    function ensureForm() {
        if (frm) { frm.doLayout(); return; }

        fDate = new Ext.form.DateField({ fieldLabel: 'Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 130 });
        fProject = new myComboField({
            fieldLabel: 'Proyek', store: projectDs, displayField: 'name', valueField: 'id',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, anchor: '95%',
            listeners: {
                select: function (combo, rec) {
                    // progress default = progress proyek saat ini (masih bisa diubah)
                    if (fMode.getValue() === 'progress') fPct.setValue(rec.get('progress'));
                    doPreview();
                }
            }
        });
        fMode = new myComboField({
            fieldLabel: 'Dasar Pelepasan', store: modeDs, displayField: 'label', valueField: 'code',
            mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false, editable: false, width: 220,
            value: 'progress',
            listeners: { select: function () { onModeChange(); } }
        });
        fPct = new myNumberField({
            fieldLabel: 'Progress (%)', decimalPrecision: 2, minValue: 0, maxValue: 100, value: 0, width: 110,
            listeners: { change: function () { doPreview(); } }
        });
        fRemark = new myTextField({ fieldLabel: 'Catatan', anchor: '95%' });
        fInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

        var col = function (w, items) {
            return { columnWidth: w, layout: 'form', border: false, labelWidth: 110, bodyStyle: 'background:transparent;', items: items };
        };
        frm = new Ext.form.FormPanel({
            renderTo: 'editorFormGrid',
            border: false,
            layout: 'column',
            bodyStyle: 'background:transparent; padding:6px 4px 0 4px;',
            items: [col(0.5, [fDate, fProject, fRemark]), col(0.5, [fMode, fPct]),
                    { columnWidth: 1, layout: 'form', border: false, bodyStyle: 'background:transparent;', items: [fInfo] }]
        });
    }

    function onModeChange() {
        var complete = (fMode.getValue() === 'complete');
        fPct.setDisabled(complete);
        if (complete) { fPct.setValue(100); }
        else {
            var pid = fProject.getValue(), rec = pid ? projectDs.getById(pid) : null;
            fPct.setValue(rec ? rec.get('progress') : 0);
        }
        doPreview();
    }

    function resetForm() {
        fDate.setValue(new Date());
        fProject.clearValue();
        fMode.setValue('progress');
        fPct.setDisabled(false);
        fPct.setValue(0);
        fRemark.setValue('');
        fInfo.setValue('<span style="color:#668894;">Pilih proyek untuk melihat perhitungan pelepasan WIP.</span>');
        previewOk = false;
    }

    // Pratinjau dihitung server (rumus yang sama dengan saat simpan)
    function doPreview() {
        var pid = fProject.getValue();
        previewOk = false;
        if (!pid) { fInfo.setValue(''); return; }
        var token = ++previewReq;
        fInfo.setValue('<span style="color:#668894;">menghitung...</span>');
        ajax({
            task: 'preview', project_id: pid, mode: fMode.getValue(),
            progress_pct: fMode.getValue() === 'complete' ? 100 : (parseFloat(fPct.getValue()) || 0)
        }, function (res) {
            if (token !== previewReq) return;               // sudah ada permintaan yang lebih baru
            if (!res.success) {
                fInfo.setValue('<span style="color:#e6a817;">' + enc(res.message || 'Gagal menghitung.') + '</span>');
                return;
            }
            var d = res.data, ok = d.amount > 0.005;
            previewOk = ok;
            var html = '<div style="padding:8px 10px; background:#1f2d3a; border-radius:4px; line-height:1.7;">' +
                'Proyek: <b>' + enc(d.project_name) + '</b> (status ' + enc(d.project_status || '-') + ', progress sistem ' + fmtPct(d.project_progress) + ')<br/>' +
                'WIP kumulatif (jurnal issue): <b>' + fmtAmt(d.wip_cumulative) + '</b><br/>' +
                'Target dilepas @ ' + fmtPct(d.progress_pct) + ': <b>' + fmtAmt(d.target_released) + '</b>' +
                ' &nbsp;|&nbsp; sudah dilepas sebelumnya: <b>' + fmtAmt(d.released_before) + '</b><br/>' +
                '<span style="color:' + (ok ? '#7fe0a0' : '#ff6b5e') + ';">Nilai dilepas ke HPP sekarang: <b>' + fmtAmt(d.amount) + '</b>' +
                (ok ? '' : ' (tidak ada yang perlu dilepas)') + '</span>';
            if (d.unjournaled > 0.005) {
                html += '<br/><span style="color:#e6a817;">Perhatian: ada issue material senilai ' + fmtAmt(d.unjournaled) +
                        ' yang belum berjurnal; jalankan backfill jurnal issue agar WIP lengkap.</span>';
            }
            html += '</div>';
            fInfo.setValue(html);
        });
    }

    function showEditor(title) {
        var addWdw = document.getElementById('addWdw');      // jaga-jaga: window Add bawaan template jangan ikut terbuka
        if (addWdw) addWdw.style.display = 'none';
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + enc(title);
        ensureForm();
    }

    // ------------------------------------------------------------
    // 4. Action Handlers
    // ------------------------------------------------------------
    // ADD = pelepasan baru
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() < ACCESS_ADD) {
            showMsg('Akses Ditolak', 'Anda tidak memiliki hak akses untuk membuat pelepasan WIP.');
            return;
        }
        projectDs.load({
            callback: function () {          // combo harus terisi sebelum form dipakai
                showEditor('Pelepasan WIP Baru');
                resetForm();
            }
        });
    };

    // SAVE
    document.getElementById('saveEdit').onclick = function () {
        if (!fProject || !fProject.getValue()) { showMsg('Peringatan', 'Proyek wajib dipilih.'); return; }
        if (!fDate.getValue()) { showMsg('Peringatan', 'Tanggal wajib diisi.'); return; }
        var complete = (fMode.getValue() === 'complete');
        var pct = complete ? 100 : (parseFloat(fPct.getValue()) || 0);
        if (pct < 0 || pct > 100) { showMsg('Peringatan', 'Progress harus antara 0 - 100.'); return; }
        if (!previewOk) { showMsg('Peringatan', 'Tidak ada WIP yang perlu dilepas dengan pengaturan ini. Periksa pratinjau di form.'); return; }

        ajax({
            task: 'save_release',
            project_id: fProject.getValue(),
            release_date: ymd(fDate.getValue()),
            mode: fMode.getValue(),
            progress_pct: pct,
            remark: fRemark.getValue()
        }, function (res) {
            showMsg(res.success ? 'Sukses' : 'Gagal', res.message || '');
            if (res.success) {
                document.getElementById('editorWdw').style.display = 'none';
                dataRecord = null;
                mainDs.load();
                updateMainButtons();
            } else {
                doPreview();                 // data WIP / pelepasan mungkin berubah -> hitung ulang
            }
        });
    };

    // DELETE = reverse pelepasan terakhir proyek
    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) { showMsg('Peringatan', 'Pilih pelepasan terlebih dahulu dari tabel.'); return; }
        if (!dataRecord.is_latest) { showMsg('Peringatan', 'Hanya pelepasan terakhir per proyek yang dapat dihapus.'); return; }
        if (getFormAccess() < ACCESS_DEL) {
            showMsg('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus pelepasan WIP.');
            return;
        }
        Ext.Msg.confirm('Hapus Pelepasan WIP',
            'Hapus pelepasan <b>' + enc(dataRecord.ref_no) + '</b> (' + fmtAmt(dataRecord.amount) + ')? Jurnalnya ikut dibatalkan.',
            function (btn) {
                if (btn !== 'yes') return;
                ajax({ task: 'delete_release', id: dataRecord.id }, function (res) {
                    showMsg(res.success ? 'Sukses' : 'Gagal', res.message || '');
                    if (res.success) {
                        dataRecord = null;
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
});