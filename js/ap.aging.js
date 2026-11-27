/*
  Document      : ap.aging.js
  Description   : Laporan Kartu Hutang & Aging Hutang Supplier (AP). Read-only.
                   Tab: Kartu Hutang (periode bulan) | Aging Detail (per faktur, periode bebas).
                   Filter: bulan/tahun (kartu), rentang tanggal + supplier (detail), pencarian global.
                   Grid memakai scoped text-wrap (GRID-WRAP 2026-11-17) agar nama supplier/proyek
                   & keterangan tidak terpotong "...".
  Backend       : /php-crud/ap.agingCRD.php  (task: list_suppliers | list_cards | list_details | export_csv)
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Kartu <em>Hutang</em> & Aging";

    // [GRID-WRAP 2026-11-17] Override SCOPED untuk grid Kartu Hutang/Aging (#divMainGrid) — tidak menyentuh CSS global.
    // ExtJS 3.4.1 (css/ext-all.css) memasang
    //   .x-grid3-cell-inner,.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    // sehingga header & sel panjang (Supplier, Proyek, Termin) terpotong "...".
    // Paksa wrap + clip + tinggi baris dinamis; angka tetap rata kanan (align ColumnModel dihormati).
    (function injectGridWrapCss() {
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(
            '#divMainGrid .x-grid3-hd-inner {' +
            ' white-space:normal !important; overflow:visible !important; text-overflow:clip !important;' +
            ' word-wrap:break-word !important; overflow-wrap:break-word !important; word-break:break-word !important;' +
            ' line-height:1.3 !important; height:auto !important; min-height:34px !important;' +
            ' padding:4px 6px !important; box-sizing:border-box; vertical-align:middle !important;' +
            ' display:flex !important; flex-direction:column; justify-content:center; }' +
            '#divMainGrid .x-grid3-cell-inner {' +
            ' white-space:normal !important; overflow:visible !important; text-overflow:clip !important;' +
            ' word-wrap:break-word !important; overflow-wrap:break-word !important; word-break:break-word !important;' +
            ' line-height:1.35 !important; height:auto !important; padding:3px 6px !important; box-sizing:border-box; }' +
            // Baris & header tinggi dinamis (tidak dikunci): teks membungkus ke 2 baris bila perlu.
            '#divMainGrid .x-grid3-hd { padding:0 !important; vertical-align:middle !important; height:auto !important; }' +
            '#divMainGrid .x-grid3-row td { height:auto !important; vertical-align:middle !important; }' +
            '#divMainGrid .x-grid3-header-offset { height:auto !important; }'));
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    var CRD = main_url + '/php-crud/ap.agingCRD.php';
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

    // assignment polos (bukan var/let) -- konvensi search box global. Store kartu = mainDs.
    mainDs = jsonStore('list_cards', ['supplier_id', 'supplier_name', 'period_start', 'period_end', floatF('saldo_awal'),
                                       floatF('faktur'), floatF('pembayaran'), floatF('alokasi'), floatF('sisa'),
                                       'invoice_count', 'first_invoice_date', 'last_invoice_date',
                                       floatF('bucket_0_30'), floatF('bucket_31_60'), floatF('bucket_61_90'),
                                       floatF('bucket_90_plus'), floatF('outstanding_all')]);
    var detDs = jsonStore('list_details', ['supplier_id', 'supplier_name', 'invoice_id', 'inv_no',
                                           'supplier_invoice_no', 'status', 'invoice_date', 'aging_date',
                                           floatF('invoice_amount'), floatF('paid_amount'), floatF('outstanding'), 'age_days',
                                           floatF('bucket_0_30'), floatF('bucket_31_60'), floatF('bucket_61_90'), floatF('bucket_90_plus')]);
    var supplierDs = jsonStore('list_suppliers', ['id', 'name']);

    // ------------------------------------------------------------
    // Gaya tab & filter (tema gelap, mengikuti tab Laporan PPN)
    // ------------------------------------------------------------
    (function injectCss() {
        var css = [
            '.ap-tabs{display:flex;gap:4px;border-bottom:1px solid #123049;padding:0 4px;background:#020d1f;}',
            '.ap-tab{display:flex;align-items:center;gap:10px;padding:10px 20px 9px 14px;cursor:pointer;border-bottom:3px solid transparent;color:#6b869a;user-select:none;}',
            '.ap-tab:hover{background:rgba(43,193,226,.06);}',
            '.ap-tab svg{width:24px;height:24px;stroke:#6b869a;fill:none;stroke-width:1.6;stroke-linecap:round;stroke-linejoin:round;flex:none;}',
            '.ap-tab .tt{display:block;font:bold 13px arial;color:#aebfcc;}',
            '.ap-tab .st{display:block;font:11px arial;color:#6b869a;margin-top:1px;}',
            '.ap-tab.active{background:linear-gradient(180deg,rgba(43,193,226,.04),rgba(43,193,226,.16));border-bottom-color:#2bc1e2;}',
            '.ap-tab.active svg{stroke:#2bc1e2;}',
            '.ap-tab.active .tt{color:#2bc1e2;}',
            '.ap-tab.active .st{color:#7fa3b8;}',
            '.ap-filter{display:flex;align-items:center;flex-wrap:wrap;gap:8px;padding:10px 8px;color:#9fc3d1;font:13px arial;}',
            '.ap-filter select,.ap-filter input{background:#031126;border:1px solid #1d4a6b;color:#e6f1f8;height:26px;padding:0 8px;font:13px arial;border-radius:2px;}',
            '.ap-filter input[type=number]{width:80px;}',
            '.ap-filter select#apSupplier{min-width:240px;}',
            '.ap-filter .btn{padding:5px 14px;border:none;border-radius:3px;cursor:pointer;color:#fff;font:bold 12px arial;}',
            '.ap-filter .btn.go{background:#03615e;}',
            '.ap-filter .btn.exp{background:#2e6da4;margin-left:auto;}',
            '.ap-totals{padding:8px 10px;font:13px arial;color:#9fc3d1;background:#031126;border:1px solid #123049;border-radius:3px;margin:0 8px 8px 8px;}',
            '.ap-panel{padding:0 8px 8px 8px;}',
            // Baris saldo nol ditandai kusam; sisa menunggak (>90) disorot merah pada kartu.
            '.x-grid3-row-ap-zero .x-grid3-cell-inner{color:#5d7488;}',
            '#divMainGrid .x-grid3-row-ap-old .x-grid3-cell-inner{color:#ff8d7e;}'
        ].join('\n');
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(css));
        document.head.appendChild(st);
    })();

    var ICON = {
        cards:   '<svg viewBox="0 0 24 24"><path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/></svg>',
        details: '<svg viewBox="0 0 24 24"><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9z"/><path d="M14 3v6h6M9 15l6-6M10 9h5v5"/></svg>'
    };
    var TABS = [
        { key: 'cards',   title: 'Kartu Hutang', sub: 'Per supplier, periode berjalan' },
        { key: 'details', title: 'Aging Detail',  sub: 'Per invoice supplier, rentang bebas' }
    ];

    var host = document.getElementById('divMainGrid');
    var html = '<div class="ap-tabs" id="apTabs">';
    Ext.each(TABS, function (t) {
        html += '<div class="ap-tab" id="apTab_' + t.key + '">' + ICON[t.key] +
                '<span><span class="tt">' + t.title + '</span><span class="st">' + t.sub + '</span></span></div>';
    });
    html += '</div>' +
            '<div class="ap-filter" id="apFilter">' +
            '<label>Bulan</label><select id="apMonth"></select>' +
            '<label>Tahun</label><input type="number" id="apYear" min="2000" max="2100" />' +
            '<label>Dari</label><input type="text" id="apFrom" size="10" />' +
            '<label>s/d</label><input type="text" id="apTo" size="10" />' +
            '<label>Supplier</label><select id="apSupplier"><option value="0">(Semua supplier)</option></select>' +
            '<button type="button" class="btn go" id="apGo">Tampilkan</button>' +
            '<button type="button" class="btn exp" id="apExport">Export CSV (Excel)</button>' +
            '</div>';
    Ext.each(TABS, function (t) {
        html += '<div class="ap-panel" id="apPanel_' + t.key + '" style="display:none">' +
                (t.key === 'cards' ? '<div class="ap-totals" id="apTotals">Pilih periode lalu klik Tampilkan.</div>' : '') +
                '<div id="apGrid_' + t.key + '"></div></div>';
    });
    host.innerHTML = html;

    // isi filter
    var now = new Date();
    var selMonth = document.getElementById('apMonth'), selClient = document.getElementById('apSupplier'), inYear = document.getElementById('apYear');
    Ext.each(MONTHS, function (m) {
        var o = document.createElement('option');
        o.value = m[0]; o.text = m[1];
        if (m[0] === now.getMonth() + 1) o.selected = true;
        selMonth.appendChild(o);
    });
    inYear.value = now.getFullYear();

    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' : '') + m + '-' + (day < 10 ? '0' : '') + day;
    }
    function filterParams() {
        return { year: parseInt(inYear.value, 10) || now.getFullYear(), month: parseInt(selMonth.value, 10) || 1,
                 date_from: document.getElementById('apFrom').value, date_to: document.getElementById('apTo').value,
                 supplier_id: parseInt(selClient.value, 10) || 0 };
    }

    // ---- Grid ----
    var amtCol = function (header, di, w) {
        return { header: header, dataIndex: di, width: w || 120, align: 'right', renderer: function (v) { return fmtAmt(v); } };
    };
    var nameRenderer = function (v) { return enc(v); };
    var zeroRow = function (rec) { return (parseFloat(rec.get('sisa')) === 0 && parseFloat(rec.get('outstanding_all')) === 0) ? 'x-grid3-row-ap-zero' : ''; };
    var oldRow = function (rec) { return rec.get('bucket_90_plus') > 0 ? 'x-grid3-row-ap-old' : ''; };

    var EMPTY_TXT = '<div style="padding:16px">Tidak ada transaksi hutang pada periode ini (hanya invoice supplier aktif yang tampil).</div>';
    var gridHeight = 300;      // dihitung ulang setelah tab & filter terpasang
    function calcHeight() {
        var used = document.getElementById('apTabs').offsetHeight + document.getElementById('apFilter').offsetHeight + 20;
        gridHeight = Math.max(260, Math.round(maxGridHeight(host)) - used);
    }

    var builders = {
        cards: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'apGrid_cards', height: Math.max(200, gridHeight - 50), store: mainDs, loadMask: true, stripeRows: true,
                viewConfig: {
                    forceFit: false, scrollOffset: 0, deferEmptyText: false, emptyText: EMPTY_TXT,
                    getRowClass: zeroRow
                },
                columns: [
                    { header: 'Supplier', dataIndex: 'supplier_name', width: 220, renderer: nameRenderer },
                    { header: 'Awal Periode', dataIndex: 'period_start', width: 90, align: 'center' },
                    { header: 'Akhir Periode', dataIndex: 'period_end', width: 90, align: 'center' },
                    amtCol('Saldo Awal', 'saldo_awal'),
                    amtCol('Faktur', 'faktur'),
                    amtCol('Pembayaran', 'pembayaran'),
                    amtCol('Alokasi', 'alokasi'),
                    { header: 'Sisa', dataIndex: 'sisa', width: 130, align: 'right',
                      renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } },
                    { header: 'Jml Faktur', dataIndex: 'invoice_count', width: 70, align: 'center' },
                    amtCol('0-30', 'bucket_0_30', 100),
                    amtCol('31-60', 'bucket_31_60', 100),
                    amtCol('61-90', 'bucket_61_90', 100),
                    amtCol('>90', 'bucket_90_plus', 100),
                    amtCol('Outstanding Semua Periode', 'outstanding_all', 150)
                ]
            });
        },
        details: function () {
            return new Ext.grid.GridPanel({
                renderTo: 'apGrid_details', height: gridHeight, store: detDs, loadMask: true, stripeRows: true,
                viewConfig: {
                    forceFit: false, scrollOffset: 0, deferEmptyText: false, emptyText: EMPTY_TXT,
                    getRowClass: oldRow
                },
                columns: [
                    { header: 'Supplier', dataIndex: 'supplier_name', width: 200, renderer: nameRenderer },
                    { header: 'No. Invoice', dataIndex: 'inv_no', width: 130, renderer: nameRenderer },
                    { header: 'Inv. Supplier', dataIndex: 'supplier_invoice_no', width: 140, renderer: nameRenderer },
                    { header: 'Status', dataIndex: 'status', width: 90, align: 'center' },
                    { header: 'Tgl Invoice', dataIndex: 'invoice_date', width: 90, align: 'center' },
                    { header: 'Tgl Aging', dataIndex: 'aging_date', width: 90, align: 'center' },
                    amtCol('Nilai', 'invoice_amount'),
                    amtCol('Dibayar', 'paid_amount'),
                    { header: 'Outstanding', dataIndex: 'outstanding', width: 130, align: 'right',
                      renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; } },
                    { header: 'Umur (hari)', dataIndex: 'age_days', width: 75, align: 'center' },
                    amtCol('0-30', 'bucket_0_30', 100),
                    amtCol('31-60', 'bucket_31_60', 100),
                    amtCol('61-90', 'bucket_61_90', 100),
                    amtCol('>90', 'bucket_90_plus', 100)
                ]
            });
        }
    };
    var grids = {}, activeKey = 'cards';

    // Grid dibuat saat tab pertama kali tampil (container sudah visible -> lebar kolom benar)
    function showTab(key) {
        activeKey = key;
        Ext.each(TABS, function (t) {
            document.getElementById('apTab_' + t.key).className = 'ap-tab' + (t.key === key ? ' active' : '');
            document.getElementById('apPanel_' + t.key).style.display = (t.key === key) ? 'block' : 'none';
        });
        if (!grids[key]) grids[key] = builders[key]();
    }
    Ext.each(TABS, function (t) {
        document.getElementById('apTab_' + t.key).onclick = function () { showTab(t.key); };
    });

    var inLoadAll = false;      // true saat tombol Tampilkan memuat kedua store (cegah muat ganda dari hook pencarian)
    function loadAll() {
        var p = filterParams();
        inLoadAll = true;
        Ext.apply(mainDs.baseParams, { year: p.year, month: p.month, query: p.query || '' });
        mainDs.load();
        Ext.apply(detDs.baseParams, { date_from: p.date_from, date_to: p.date_to, supplier_id: p.supplier_id, query: p.query || '' });
        detDs.load();
        inLoadAll = false;
    }
    document.getElementById('apGo').onclick = loadAll;
    document.getElementById('apExport').onclick = function () {
        var p = filterParams();
        var type = (activeKey === 'details') ? 'details' : 'cards';
        var q = (mainDs.baseParams && mainDs.baseParams.query) || '';
        var url = CRD + '?task=export_csv&type=' + type + '&year=' + p.year + '&month=' + p.month +
                  '&supplier_id=' + p.supplier_id + '&date_from=' + encodeURIComponent(p.date_from || '') +
                  '&date_to=' + encodeURIComponent(p.date_to || '') + '&query=' + encodeURIComponent(q);
        window.open(url);
    };

    // Ringkasan periode dari respons list_cards (total saldo awal/faktur/bayar/alokasi/sisa & bucket)
    mainDs.on('load', function (store) {
        var el = document.getElementById('apTotals');
        var t = (store.reader.jsonData || {}).totals;
        if (!el) return;
        if (!t) { el.innerHTML = ''; return; }
        el.innerHTML = 'Saldo awal: <b >' + fmtAmt(t.saldo_awal) + '</b>' +
                       ' &nbsp;|&nbsp; Faktur: <b >' + fmtAmt(t.faktur) + '</b>' +
                       ' &nbsp;|&nbsp; Pembayaran: <b >' + fmtAmt(t.pembayaran) + '</b>' +
                       ' &nbsp;|&nbsp; Alokasi: <b >' + fmtAmt(t.alokasi) + '</b>' +
                       ' &nbsp;|&nbsp; <b>Sisa: ' + fmtAmt(t.sisa) + '</b>' +
                       ' &nbsp;|&nbsp; >90 hari: <span style="font-weight:bold">' + fmtAmt(t.bucket_90_plus) + '</span>';
    });

    // Pencarian global template memuat ulang mainDs saja -> samakan query ke store detail
    mainDs.on('beforeload', function (store) {
        var q = store.baseParams.query || '';
        detDs.baseParams.query = q;
        if (inLoadAll) return;                       // sudah dimuat oleh loadAll()
        if (detDs.lastOptions) detDs.load();         // pencarian dari search box template -> muat ulang detail
    });

    // Template: laporan hanya-baca, tombol tambah/ubah/hapus dinonaktifkan
    if (typeof editDataDisable === 'function') { editDataDisable(); }
    if (typeof mainBtnDisable === 'function') { mainBtnDisable('#addData'); mainBtnDisable('#deleteData'); }

    calcHeight();
    showTab('cards');
    supplierDs.load({ callback: function () {
        supplierDs.each(function (r) {
            if (r.get('id') === 0) return;               // opsi "(Semua supplier)" sudah ada di select
            var o = document.createElement('option');
            o.value = r.get('id'); o.text = r.get('name');
            selClient.appendChild(o);
        });
        loadAll();
    } });
    document.getElementById('editorWdw').style.display = 'none';
});
