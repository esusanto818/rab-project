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
Ext.onReady/* [CSS-UNIFIED] Styling dipindahkan ke css/app-theme.css */

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
        html += '<div class="ppn-panel" id="ppnPanel_' + t.key + '" style="display:none">' +
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
    var fakturRenderer = function (v) { return v ? enc(v) : '<span >belum diisi</span>'; };    // faktur wajib untuk kredit pajak masukan
    var netRenderer = function (v) {
        var n = parseFloat(v) || 0;
        return '<span style="font-weight:bold">' + fmtAmt(n) + '</span>';
    };
    var rateRenderer = function (v) { return v + '%'; };

    var EMPTY_TXT = '<div style="padding:16px">Tidak ada transaksi ber-PPN pada periode ini (hanya termin / invoice dengan PPN &gt; 0 yang tampil; masa pajak mengikuti tanggal faktur, bila kosong tanggal invoice).</div>';
    var gridHeight = 300;      // dihitung ulang setelah tab & filter terpasang
    function calcHeight() {
        var used = document.getElementById('ppnTabs').offsetHeight + document.getElementById('ppnFilter').offsetHeight + 20;
        gridHeight = Math.max(260, Math.round(maxGridHeight(host)) - used);
    }

    var builders = {
        summary: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'ppnGrid_summary', height: Math.max(200, gridHeight - 50), store: mainDs, loadMask: true, stripeRows: true,
                viewConfig: { forceFit: true, deferEmptyText: false, emptyText: EMPTY_TXT },   // pesan jelas saat tidak ada transaksi ber-PPN
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
                viewConfig: { forceFit: true, deferEmptyText: false, emptyText: EMPTY_TXT },   // pesan jelas saat tidak ada transaksi ber-PPN
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
                viewConfig: { forceFit: true, deferEmptyText: false, emptyText: EMPTY_TXT },   // pesan jelas saat tidak ada transaksi ber-PPN
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

    // [PRINT] tombol Printing template -> halaman cetak pdf/ppnreport.php (periode & proyek sesuai filter)
    if (document.getElementById('printData')) {
        document.getElementById('printData').onclick = function () {
            var p = filterParams();
            window.open('../dev/pdf/ppn.report.php?year=' + p.year + '&month=' + p.month + '&project_id=' + p.project_id,
                        '_blank', 'width=1100,height=800,scrollbars=yes,resizable=yes');
        };
    }

    // Total & status kurang/lebih bayar dari respons list_summary
    mainDs.on('load', function (store) {
        var el = document.getElementById('ppnTotals');
        var t = (store.reader.jsonData || {}).totals;
        if (!el) return;
        if (!t) { el.innerHTML = ''; return; }
        var net = parseFloat(t.net_ppn) || 0;
        var label = net > 0 ? 'PPN KURANG BAYAR' : (net < 0 ? 'PPN LEBIH BAYAR (dapat dikompensasi)' : 'PPN NIHIL');
        var color = net > 0 ? '#ff6b5e' : '#7fe0a0';
        el.innerHTML = 'PPN Keluaran <b >' + fmtAmt(t.keluaran_ppn) + '</b> &nbsp;-&nbsp; PPN Masukan <b >' +
                       fmtAmt(t.masukan_ppn) + '</b> &nbsp;=&nbsp; <span style="font-weight:bold">' +
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