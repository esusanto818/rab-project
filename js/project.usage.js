/*
  Document      : project.usage.js
  Description   : Perbandingan PEMAKAIAN MATERIAL vs PROGRES proyek (read-only).
                  Grid atas = ringkasan per proyek (urut yang paling menyimpang): % progres, % pemakaian material,
                  selisih (poin), nilai RAB & terpakai, tanda NORMAL / WASPADA / KRITIS. Klik proyek -> rincian per material di bawah.
                  Selisih = % pemakaian - % progres. > 10 poin = WASPADA, > 20 poin = KRITIS (tanda awal pemborosan / kebocoran).
  Backend       : /proyek/php-crud/project.usageCRD.php   (view: v_project_material_vs_progress, v_project_material_detail_vs_progress)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Pemakaian Material <em>vs Progres</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    ['editorWdw', 'addWdw'].forEach(function (id) {      // template menampilkan window editor/add secara default; halaman ini tidak memakainya
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });
    var CRD = main_url + '/php-crud/project.usageCRD.php';


    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    function fmtPct(v) { return (v === null || v === undefined) ? '-' : Ext.util.Format.number(v, '0,000.0') + ' %'; }
    function showMsg(title, msg) { Ext.Msg.alert(title, msg); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showMsg('Gagal', m);
    }
    function flagColor(f) {
        return f === 'KRITIS' ? '#e8554a' : (f === 'WASPADA' ? '#e6a817' : (f === 'NORMAL' ? '#2e9e4f' : '#999999'));
    }
    // batang progres: lebar = persen (maks 100), warna per jenis
    function bar(pct, color) {
        var w = Math.max(0, Math.min(100, pct || 0));
        return '<div style="height:12px; overflow:hidden">' +
               '<div style="width:' + w + '%; height:12px"></div></div>';
    }

    // ============================================================
    // 0. Elemen filter, ringkasan, dan panel rincian (dibuat dinamis)
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var topDiv = document.createElement('div');
    topDiv.style.cssText = 'padding:8px 12px; margin:0 10px 8px 10px; background:rgba(255,255,255,0.05); border-radius:4px; color:#fff; font-size:14px;';
    var dtlDiv = document.createElement('div');
    dtlDiv.className = 'detail-panel-container';
    dtlDiv.style.display = 'none';
    dtlDiv.style.overflowY = 'auto';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(topDiv, gridContainer);
        gridContainer.parentNode.insertBefore(dtlDiv, gridContainer.nextSibling);
    }
    var gh = maxGridHeight(gridContainer);
    dtlDiv.style.maxHeight = Math.round(gh - gh * 0.55 - 30) + 'px';

    var onlyActive = new Ext.form.Checkbox({ boxLabel: 'Sembunyikan proyek Selesai', checked: true });
    var btnShow = new Ext.Button({ text: 'Muat Ulang', cls: 'field-button grey-field-button' });
    var fbar = document.createElement('div');
    fbar.style.padding = '0 10px';
    if (gridContainer && gridContainer.parentNode) { gridContainer.parentNode.insertBefore(fbar, topDiv); }
    new Ext.Panel({
        renderTo: fbar, layout: 'column', border: false, bodyStyle: 'padding:6px 0',
        defaults: { border: false,  },
        items: [{ columnWidth: 0.3, style: 'padding-top:6px', items: onlyActive }, { columnWidth: 0.2, items: btnShow }]
    });

    // ============================================================
    // 1. Store & grid ringkasan
    // ============================================================
    var summaryField = [{ name: 'project_id', type: 'int' }, 'project_name', 'client_name', 'project_status',
                        { name: 'progress_pct', type: 'float' }, { name: 'rab_value', type: 'float' }, { name: 'used_value', type: 'float' },
                        { name: 'usage_pct' }, { name: 'gap_pts' }, 'flag'];

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global.
    mainDs = new Ext.data.Store({
        reader: new Ext.data.JsonReader({ root: 'rows' }, summaryField),
        listeners: { exception: storeError }
    });

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs, renderTo: 'divMainGrid', height: Math.round(gh * 0.55), loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Proyek', dataIndex: 'project_name', width: 220, renderer: CellWrap },
            { header: 'Klien', dataIndex: 'client_name', width: 150, renderer: function (v) { return CellWrap(v || '-'); } },
            { header: 'Progres', dataIndex: 'progress_pct', width: 150, renderer: function (v) { return fmtPct(v) + bar(v, '#2bc1e2'); } },
            { header: 'Pemakaian Material', dataIndex: 'usage_pct', width: 160,
              renderer: function (v, m, r) { return (v === null ? '-' : fmtPct(parseFloat(v)) + bar(parseFloat(v), flagColor(r.get('flag')))); } },
            { header: 'Selisih (poin)', dataIndex: 'gap_pts', width: 100, align: 'right',
              renderer: function (v, m, r) { return v === null ? '-' : '<b >' + (parseFloat(v) > 0 ? '+' : '') + Ext.util.Format.number(parseFloat(v), '0,000.0') + '</b>'; } },
            { header: 'Nilai RAB Material', dataIndex: 'rab_value', width: 140, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Material Terpakai', dataIndex: 'used_value', width: 140, align: 'right', renderer: function (v) { return fmtAmt(v); } },
            { header: 'Status Proyek', dataIndex: 'project_status', width: 100, align: 'center' },
            { header: 'Tanda', dataIndex: 'flag', width: 90, align: 'center',
              renderer: function (v) { return '<b >' + enc(v) + '</b>'; } }
        ],
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) { loadDetail(rec.data); }
            }
        }
    });

    function loadSummary() {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: { task: 'summary', only_active: onlyActive.getValue() ? 1 : 0 },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showMsg('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                if (!res.success) { showMsg('Gagal', res.message || 'Gagal memuat data.'); return; }
                mainDs.loadData(res.data, false);
                var c = res.data.counts;
                topDiv.innerHTML = '<b >' + c['KRITIS'] + ' kritis</b> &nbsp;|&nbsp; <b >' + c['WASPADA'] + ' waspada</b> &nbsp;|&nbsp; ' +
                    '<b >' + c['NORMAL'] + ' normal</b> &nbsp;|&nbsp; <span >' + c['TANPA RAB'] + ' tanpa RAB</span>' +
                    '<div style="margin-top:4px; font-size:12px">Selisih = % pemakaian material (nilai issue / nilai RAB) dikurangi % progres. ' +
                    'Lebih dari 10 poin = waspada, lebih dari 20 poin = kritis: material keluar jauh lebih cepat dari pekerjaan yang selesai (periksa pemborosan, ' +
                    'material tidak terpasang, atau progres yang belum diperbarui).</div>';
                dtlDiv.style.display = 'none';
            },
            failure: function () { showMsg('Gagal', 'Gagal menghubungi server.'); }
        });
    }

    // ============================================================
    // 2. Rincian per material untuk proyek terpilih
    // ============================================================
    function loadDetail(p) {
        Ext.Ajax.request({
            url: CRD, method: 'POST', params: { task: 'detail', project_id: p.project_id },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { showMsg('Gagal', 'Respons server tidak valid.'); return; }
                if (!res.success) { showMsg('Gagal', res.message || 'Gagal memuat rincian.'); return; }
                var html = '<h3 class="detail-title">Rincian per material: ' + enc(p.project_name) + ' <span >(progres ' + fmtPct(p.progress_pct) + ')</span></h3>' +
                           '<hr class="detail-hr" />' +
                           '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0"><thead><tr class="header-row">' +
                           '<th align="left">Material</th><th align="right">Qty RAB</th><th align="right">Qty Terpakai</th><th align="left" width="150">% Pemakaian</th>' +
                           '<th align="right">Selisih (poin)</th><th align="right">Nilai Terpakai</th><th align="center">Tanda</th></tr></thead><tbody>';
                if (!res.data.length) { html += '<tr><td colspan="7" style="padding:15px; text-align:center">Proyek ini belum punya material di RAB.</td></tr>'; }
                Ext.each(res.data, function (m) {
                    html += '<tr class="data-row"><td align="left">' + enc(m.material_name) + ' <span >(' + enc(m.material_code) + ')</span></td>' +
                            '<td align="right">' + fmtQty(m.rab_qty) + ' ' + enc(m.unit) + '</td><td align="right">' + fmtQty(m.used_qty) + '</td>' +
                            '<td align="left">' + fmtPct(m.usage_pct) + bar(m.usage_pct, flagColor(m.flag)) + '</td>' +
                            '<td align="right"><b >' + (m.gap_pts === null ? '-' : (m.gap_pts > 0 ? '+' : '') + Ext.util.Format.number(m.gap_pts, '0,000.0')) + '</b></td>' +
                            '<td align="right">' + fmtAmt(m.used_value) + '</td>' +
                            '<td align="center"><b >' + enc(m.flag) + '</b></td></tr>';
                });
                html += '</tbody></table>';
                dtlDiv.innerHTML = html;
                dtlDiv.style.display = 'block';
            },
            failure: function () { showMsg('Gagal', 'Gagal menghubungi server.'); }
        });
    }

    btnShow.on('click', loadSummary);
    onlyActive.on('check', loadSummary);
    loadSummary();

    if (document.getElementById('printData')) {
        document.getElementById('printData').onclick = function () { window.print(); };      // cetak halaman ini apa adanya
    }
    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };
    }
});