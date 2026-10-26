/*
  Document      : ppn.report.js
  Description   : Laporan PPN bulanan (PPN Keluaran vs PPN Masukan), detail per proyek.  [PPN - TAHAP 3]
                   Tab: Ringkasan per Proyek | Detail PPN Keluaran (termin) | Detail PPN Masukan (invoice supplier).
                   Filter: bulan, tahun, proyek; pencarian global (search box template -> mainDs.baseParams.query).
                   [UI 2026-10-26] Tab & filter memakai gaya tab Pengaturan Sistem (ikon + judul + subjudul, garis aktif cyan,
                   tema gelap); grid dibuat saat tab pertama kali dibuka (container hidden -> lebar kolom 0 bila dirender awal).
                   Masa pajak = bulan tanggal faktur pajak (bila kosong: tanggal invoice).
                   PPN Kurang(+) / Lebih(-) Bayar = PPN Keluaran - PPN Masukan.
  Backend       : /proyek/php-crud/ppn.reportCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Laporan <em>PPN</em>";

    var CRD = main_url + '/php-crud/ppn.reportCRD.php';

    var MONTHS = [[1, 'Januari'], [2, 'Februari'], [3, 'Maret'], [4, 'April'], [5, 'Mei'], [6, 'Juni'],
                  [7, 'Juli'], [8, 'Agustus'], [9, 'September'], [10, 'Oktober'], [11, 'November'], [12, 'Desember']];

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function showErr(msg) { Ext.Msg.show({ title: 'Peringatan', msg: msg, buttons: Ext.Msg.OK, icon: Ext.Msg.WARNING }); }

    // Exception store: pesan backend / HTTP ditampilkan eksplisit
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; }
        else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showErr(m);
    }
    function jsonStore(task, fields, extra) {
        var cfg = {
            url: CRD, baseParams: { task: task }, root: 'data', totalProperty: 'totalCount', fields: fields,
            listeners: { exception: storeError }
        };
        return new Ext.data.JsonStore(Ext.apply(cfg, extra || {}));
    }

    var floatF = function (n) { return { name: n, type: 'float' }; };

    // PENTING: assignment polos (bukan var/let) -- konvensi search box global. Store ringkasan = mainDs.
    mainDs = jsonStore('list_summary', ['project_id', 'project_name', floatF('keluaran_dpp'), floatF('keluaran_ppn'),
                                         floatF('masukan_dpp'), floatF('masukan_ppn'), floatF('net_ppn')]);
    var kelDs = jsonStore('list_keluaran', ['id', 'tax_date', 'inv_no', 'termin_name', 'project_name', 'counterparty', 'faktur_pajak_no',
                                             floatF('dpp_amount'), floatF('ppn_rate'), floatF('ppn_amount'), floatF('total_amount')]);
    var masDs = jsonStore('list_masukan', ['id', 'tax_date', 'inv_no', 'supplier_invoice_no', 'project_name', 'counterparty', 'npwp_no', 'faktur_pajak_no',
                                            floatF('dpp_amount'), floatF('ppn_rate'), floatF('ppn_amount'), floatF('total_amount')]);
    var projectDs = jsonStore('list_projects', ['id', 'name']);

    // ------------------------------------------------------------
    // Gaya tab & filter (tema gelap, mengikuti tab Pengaturan Sistem)
    // ------------------------------------------------------------
    (function injectCss() {
        var css = [
            '.ppn-tabs{display:flex;gap:4px;border-bottom:1px solid #123049;padding:0 4px;background:#020d1f;}',
            '.ppn-tab{display:flex;align-items:center;gap:10px;padding:10px 20px 9px 14px;cursor:pointer;border-bottom:3px solid transparent;color:#6b869a;user-select:none;}',
            '.ppn-tab:hover{background:rgba(43,193,226,.06);}',
            '.ppn-tab svg{width:24px;height:24px;stroke:#6b869a;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:none;}',
            '.ppn-tab .tt{display:block;font:bold 13px arial;color:#aebfcc;}',
            '.ppn-tab .st{display:block;font:11px arial;color:#6b869a;margin-top:1px;}',
            '.ppn-tab.active{background:linear-gradient(180deg,rgba(43,193,226,.04),rgba(43,193,226,.16));border-bottom-color:#2bc1e2;}',
            '.ppn-tab.active svg{stroke:#2bc1e2;}',
            '.ppn-tab.active .tt{color:#2bc1e2;}',
            '.ppn-tab.active .st{color:#7fa3b8;}',
            '.ppn-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 8px;color:#9fc3d1;font:13px arial;}',
            '.ppn-filter select,.ppn-filter input{background:#031126;border:1px solid #1d4a6b;color:#e6f1f8;height:26px;padding:0 8px;font:13px arial;border-radius:2px;}',
            '.ppn-filter input[type=number]{width:80px;}',
            '.ppn-filter select#ppnProject{min-width:240px;}',
            '.ppn-filter .btn{padding:5px 14px;border:none;border-radius:3px;cursor:pointer;color:#fff;font:bold 12px arial;}',
            '.ppn-filter .btn.go{background:#03615e;}',
            '.ppn-filter .btn.exp{background:#2e6da4;margin-left:auto;}',
            '.ppn-totals{padding:8px 10px;font:13px arial;color:#9fc3d1;background:#031126;border:1px solid #123049;border-radius:3px;margin:0 8px 8px 8px;}',
            '.ppn-panel{padding:0 8px 8px 8px;}'
        ].join('\n');
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(css));
        document.head.appendChild(st);
    })();

    var ICON = {
        summary: '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        keluaran: '<svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 15l6-6M10 9h5v5"/></svg>',
        masukan: '<svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M15 9l-6 6M9 10v5h5"/></svg>'
    };
    var TABS = [
        { key: 'summary',  title: 'Ringkasan',      sub: 'Per proyek, kurang / lebih bayar' },
        { key: 'keluaran', title: 'PPN Keluaran',   sub: 'Detail termin ke customer' },
        { key: 'masukan',  title: 'PPN Masukan',    sub: 'Detail invoice supplier' }
    ];

    var host = document.getElementById('divMainGrid');
    var html = '<div class="ppn-tabs" id="ppnTabs">';
    Ext.each(TABS, function (t) {
        html += '<div class="ppn-tab" id="ppnTab_' + t.key + '">' + ICON[t.key] +
                '<span><span class="tt">' + t.title + '</span><span class="st">' + t.sub + '</span></span></div>';
    });
    html += '</div>' +
            '<div class="ppn-filter" id="ppnFilter">' +
            '<label>Bulan</label><select id="ppnMonth"></select>' +
            '<label>Tahun</label><input type="number" id="ppnYear" min="2000" max="2100" />' +
            '<label>Proyek</label><select id="ppnProject"><option value="0">(Semua proyek)</option></select>' +
            '<button type="button" class="btn go" id="ppnGo">Tampilkan</button>' +
            '<button type="button" class="btn exp" id="ppnExport">Export CSV (Excel)</button>' +
            '</div>';
    Ext.each(TABS, function (t) {
        html += '<div class="ppn-panel" id="ppnPanel_' + t.key + '" style="display:none;">' +
                (t.key === 'summary' ? '<div class="ppn-totals" id="ppnTotals">Pilih periode lalu klik Tampilkan.</div>' : '') +
                '<div id="ppnGrid_' + t.key + '"></div></div>';
    });
    host.innerHTML = html;

    // isi filter
    var now = new Date();
    var selMonth = document.getElementById('ppnMonth'), selProject = document.getElementById('ppnProject'), inYear = document.getElementById('ppnYear');
    Ext.each(MONTHS, function (m) {
        var o = document.createElement('option');
        o.value = m[0]; o.text = m[1];
        if (m[0] === now.getMonth() + 1) o.selected = true;
        selMonth.appendChild(o);
    });
    inYear.value = now.getFullYear();

    function filterParams() {
        return { year: parseInt(inYear.value, 10) || now.getFullYear(), month: parseInt(selMonth.value, 10) || 1,
                 project_id: parseInt(selProject.value, 10) || 0 };
    }

    // ---- Grid ----
    var amtCol = function (header, di, w) {
        return { header: header, dataIndex: di, width: w || 120, align: 'right', renderer: function (v) { return fmtAmt(v); } };
    };
    var fakturRenderer = function (v) { return v ? enc(v) : '<span style="color:#e6a817;">belum diisi</span>'; };    // faktur wajib untuk kredit pajak masukan
    var netRenderer = function (v) {
        var n = parseFloat(v) || 0;
        return '<span style="color:' + (n > 0 ? '#ff6b5e' : (n < 0 ? '#7fe0a0' : '#9fc3d1')) + ';font-weight:bold;">' + fmtAmt(n) + '</span>';
    };
    var rateRenderer = function (v) { return v + '%'; };

    var gridHeight = 300;      // dihitung ulang setelah tab & filter terpasang
    function calcHeight() {
        var used = document.getElementById('ppnTabs').offsetHeight + document.getElementById('ppnFilter').offsetHeight + 20;
        gridHeight = Math.max(260, Math.round(maxGridHeight(host)) - used);
    }

    var builders = {
        summary: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'ppnGrid_summary', height: Math.max(200, gridHeight - 50), store: mainDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true },
                columns: [
                    { header: 'Proyek', dataIndex: 'project_name', width: 240 },
                    amtCol('DPP Keluaran', 'keluaran_dpp'), amtCol('PPN Keluaran', 'keluaran_ppn'),
                    amtCol('DPP Masukan', 'masukan_dpp'), amtCol('PPN Masukan', 'masukan_ppn'),
                    { header: 'Kurang(+) / Lebih(-) Bayar', dataIndex: 'net_ppn', width: 170, align: 'right', renderer: netRenderer }
                ]
            });
        },
        keluaran: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'ppnGrid_keluaran', height: gridHeight, store: kelDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true },
                columns: [
                    { header: 'Tgl Pajak', dataIndex: 'tax_date', width: 85, align: 'center' },
                    { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
                    { header: 'Proyek', dataIndex: 'project_name', width: 200 },
                    { header: 'Customer', dataIndex: 'counterparty', width: 160 },
                    { header: 'Termin', dataIndex: 'termin_name', width: 120 },
                    { header: 'No. Faktur Pajak', dataIndex: 'faktur_pajak_no', width: 150, renderer: fakturRenderer },
                    amtCol('DPP', 'dpp_amount'),
                    { header: 'Tarif', dataIndex: 'ppn_rate', width: 50, align: 'center', renderer: rateRenderer },
                    amtCol('PPN', 'ppn_amount'), amtCol('Total', 'total_amount')
                ]
            });
        },
        masukan: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'ppnGrid_masukan', height: gridHeight, store: masDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true },
                columns: [
                    { header: 'Tgl Pajak', dataIndex: 'tax_date', width: 85, align: 'center' },
                    { header: 'No. Invoice', dataIndex: 'inv_no', width: 110 },
                    { header: 'Inv. Supplier', dataIndex: 'supplier_invoice_no', width: 110 },
                    { header: 'Proyek', dataIndex: 'project_name', width: 190 },
                    { header: 'Supplier', dataIndex: 'counterparty', width: 160 },
                    { header: 'NPWP', dataIndex: 'npwp_no', width: 130, renderer: function (v) { return v ? enc(v) : '-'; } },
                    { header: 'No. Faktur Pajak', dataIndex: 'faktur_pajak_no', width: 150, renderer: fakturRenderer },
                    amtCol('DPP', 'dpp_amount'),
                    { header: 'Tarif', dataIndex: 'ppn_rate', width: 50, align: 'center', renderer: rateRenderer },
                    amtCol('PPN', 'ppn_amount'), amtCol('Total', 'total_amount')
                ]
            });
        }
    };
    var grids = {}, activeKey = 'summary';

    // Grid dibuat saat tab pertama kali tampil (container sudah visible -> lebar kolom benar)
    function showTab(key) {
        activeKey = key;
        Ext.each(TABS, function (t) {
            document.getElementById('ppnTab_' + t.key).className = 'ppn-tab' + (t.key === key ? ' active' : '');
            document.getElementById('ppnPanel_' + t.key).style.display = (t.key === key) ? 'block' : 'none';
        });
        if (!grids[key]) grids[key] = builders[key]();
    }
    Ext.each(TABS, function (t) {
        document.getElementById('ppnTab_' + t.key).onclick = function () { showTab(t.key); };
    });

    var inLoadAll = false;      // true saat tombol Tampilkan memuat ketiga store (cegah muat ganda dari hook pencarian)
    function loadAll() {
        var p = filterParams();
        inLoadAll = true;
        Ext.each([mainDs, kelDs, masDs], function (ds) {
            Ext.apply(ds.baseParams, p);
            ds.load();
        });
        inLoadAll = false;
    }
    document.getElementById('ppnGo').onclick = loadAll;
    document.getElementById('ppnExport').onclick = function () {
        var p = filterParams();
        var q = (mainDs.baseParams && mainDs.baseParams.query) || '';
        window.open(CRD + '?task=export_csv&type=' + encodeURIComponent(activeKey) + '&year=' + p.year + '&month=' + p.month +
                    '&project_id=' + p.project_id + '&query=' + encodeURIComponent(q));
    };

    // Total & status kurang/lebih bayar dari respons list_summary
    mainDs.on('load', function (store) {
        var el = document.getElementById('ppnTotals');
        var t = (store.reader.jsonData || {}).totals;
        if (!el) return;
        if (!t) { el.innerHTML = ''; return; }
        var net = parseFloat(t.net_ppn) || 0;
        var label = net > 0 ? 'PPN KURANG BAYAR' : (net < 0 ? 'PPN LEBIH BAYAR (dapat dikompensasi)' : 'PPN NIHIL');
        var color = net > 0 ? '#ff6b5e' : '#7fe0a0';
        el.innerHTML = 'PPN Keluaran <b style="color:#e6f1f8;">' + fmtAmt(t.keluaran_ppn) + '</b> &nbsp;-&nbsp; PPN Masukan <b style="color:#e6f1f8;">' +
                       fmtAmt(t.masukan_ppn) + '</b> &nbsp;=&nbsp; <span style="color:' + color + ';font-weight:bold;">' +
                       label + ' ' + fmtAmt(Math.abs(net)) + '</span>';
    });

    // Pencarian global template memuat ulang mainDs saja -> samakan query ke store detail
    mainDs.on('beforeload', function (store) {
        var q = store.baseParams.query || '';
        kelDs.baseParams.query = q;
        masDs.baseParams.query = q;
        if (inLoadAll) return;                       // sudah dimuat oleh loadAll()
        if (kelDs.lastOptions) kelDs.load();         // pencarian dari search box template -> muat ulang detail
        if (masDs.lastOptions) masDs.load();
    });

    // Template: laporan hanya-baca, tombol tambah/ubah/hapus dinonaktifkan
    if (typeof editDataDisable === 'function') editDataDisable();
    if (typeof mainBtnDisable === 'function') { mainBtnDisable('#addData'); mainBtnDisable('#deleteData'); }

    calcHeight();
    showTab('summary');
    projectDs.load({ callback: function () {
        projectDs.each(function (r) {
            if (r.get('id') === 0) return;               // opsi "(Semua proyek)" sudah ada di select
            var o = document.createElement('option');
            o.value = r.get('id'); o.text = r.get('name');
            selProject.appendChild(o);
        });
        loadAll();
    } });
    document.getElementById('editorWdw').style.display = 'none';

});