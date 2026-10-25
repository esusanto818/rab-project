/*
  Document      : inventory.recon.js
  Description   : Rekonsiliasi Persediaan (read-only). Membandingkan nilai BUKU (saldo akun persediaan di buku besar)
                  dengan nilai STOK (ledger stock_movements, IN - OUT bernilai FIFO) sampai tanggal tertentu.
                  Ringkasan: nilai buku, nilai stok, selisih, bagian selisih yang dijelaskan dokumen bermasalah, sisa.
                  Tab: Per Item | Per Gudang | Dokumen vs Jurnal (status OK / TIDAK ADA JURNAL / NILAI BEDA / N/A).
                  [PERBAIKAN] Tombol "Proses Perbaikan Otomatis" memposting jurnal yang kurang untuk dokumen stok yang belum berjurnal.
                  [TAB BESAR] Tab dibuat sendiri (tombol 18px) agar terbaca; teks ringkasan memakai bahasa sehari-hari.
                  Pola mengikuti trialbalance.js (filter bar + summary + grid) dan po.js.
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/inventory.reconCRD.php  (logika: php-func/function.inventory.recon.php)
=======
  Backend       : /rab-project/live/php-crud/inventory.reconCRD.php  (logika: php-func/function.inventory.recon.php)
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Rekonsiliasi <em>Persediaan</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // [FIX-EDITOR] Template menampilkan window editor/add secara default; halaman laporan ini tidak memakainya -> sembunyikan,
    //              kalau tidak kerangka kosong "SAVE / DISCARD" menutupi seluruh halaman (filter, ringkasan, tab).
    ['editorWdw', 'addWdw'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    var CRD = '../php-crud/inventory.reconCRD.php';

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function fmtAmt(v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
    function fmtQty(v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
    // Workaround: Date.format('Y-m-d') pernah mengembalikan 'Y-10-10' di sebagian browser -> format manual
    function ymd(d) {
        var m = d.getMonth() + 1, day = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (day < 10 ? '0' + day : day);
    }
    function showMsg(title, msg) { Ext.Msg.alert(title, msg); }
    function storeError(proxy, type, action, opt, res) {
        var m = 'Gagal memuat data dari server.';
        if (res && res.message) { m = res.message; } else if (res && res.status) { m += ' (HTTP ' + res.status + ')'; }
        showMsg('Gagal', m);
    }

    // ============================================================
    // 0. Elemen filter bar & summary (dibuat dinamis, sama seperti trialbalance.js)
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'irFilterBar';
    filterBarDiv.style.padding = '0 10px';
    var summaryDiv = document.createElement('div');
    summaryDiv.id = 'irSummary';
    summaryDiv.style.cssText = 'padding:10px 14px; font-size:14px; margin:0 10px 10px 10px; border-radius:4px; background:rgba(255,255,255,0.05); color:#fff;';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(summaryDiv, gridContainer);
    }

    // ============================================================
    // 1. Stores
    // ============================================================
    var accDs = new Ext.data.Store({
        url: CRD, baseParams: { task: 'list_accounts' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' },
            [{ name: 'id', type: 'int' }, { name: 'label' }, { name: 'is_default' }]),
        listeners: {
            exception: storeError,
            load: function (store) {
                var defId = null;
                store.each(function (r) {
                    var d = r.get('is_default');
                    if (defId === null && (d === true || d === 'true' || d === 1 || d === '1')) defId = r.get('id');
                });
                if (defId === null && store.getCount()) defId = store.getAt(0).get('id');
                if (defId !== null && !fldAcc.getValue()) fldAcc.setValue(defId);
                loadRecon();
            }
        }
    });

    function jsonStore(fields) {
        return new Ext.data.Store({
            reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, fields),
            listeners: { exception: storeError }
        });
    }
    var itemDs = jsonStore([{ name: 'material_code' }, { name: 'material_name' }, { name: 'unit' },
                            { name: 'qty', type: 'float' }, { name: 'avg_cost', type: 'float' }, { name: 'value', type: 'float' }]);
    var whDs = jsonStore([{ name: 'warehouse_name' }, { name: 'item_count', type: 'int' }, { name: 'value', type: 'float' }]);
    var docDs = jsonStore([{ name: 'doc_date' }, { name: 'reference_type' }, { name: 'reference_no' },
                           { name: 'value', type: 'float' }, { name: 'journal_amount' }, { name: 'status' }]);

    // ============================================================
    // 2. Filter bar
    // ============================================================
    var fldDate = new Ext.form.DateField({ fieldLabel: 'Per Tanggal', format: 'd/m/Y', value: new Date(), allowBlank: false, width: 120 });
    var fldAcc = new myComboField({
        fieldLabel: 'Akun Persediaan', width: 260, store: accDs, displayField: 'label', valueField: 'id',
        mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false
    });
    var fldOnlyIssue = new Ext.form.Checkbox({ boxLabel: 'Dokumen: hanya yang bermasalah', checked: false });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });
    var btnFix = new Ext.Button({ text: 'Proses Perbaikan Otomatis', cls: 'field-button grey-field-button', hidden: true });    // tampil hanya bila ada yang bisa diperbaiki

    new Ext.Panel({
        renderTo: 'irFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.19, layout: 'form', labelWidth: 75, items: fldDate },
            { columnWidth: 0.30, layout: 'form', labelWidth: 110, items: fldAcc },
            { columnWidth: 0.17, style: 'padding-top:6px;', items: fldOnlyIssue },
            { columnWidth: 0.11, style: 'padding-top:2px;', items: btnShow },
            { columnWidth: 0.23, style: 'padding-top:2px;', items: btnFix }
        ]
    });

    // ============================================================
    // 3. Grid dalam tab
    // ============================================================
    var statusColor = function (s) {
        return s === 'OK' ? '#2e9e4f' : (s === 'TIDAK ADA JURNAL' ? '#e8554a' : (s === 'NILAI BEDA' ? '#e6a817' : '#999999'));
    };

    var itemGrid = new Ext.grid.GridPanel({
        store: itemDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Kode', dataIndex: 'material_code', width: 100 },
            { header: 'Material', dataIndex: 'material_name', width: 260, renderer: CellWrap },
            { header: 'Satuan', dataIndex: 'unit', width: 70, align: 'center' },
            { header: 'Qty', dataIndex: 'qty', width: 110, align: 'right', renderer: fmtQty },
            { header: 'Biaya Rata-rata', dataIndex: 'avg_cost', width: 120, align: 'right', renderer: fmtAmt },
            { header: 'Nilai Stok', dataIndex: 'value', width: 140, align: 'right', renderer: fmtAmt }
        ]
    });
    var whGrid = new Ext.grid.GridPanel({
        store: whDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 260, renderer: CellWrap },
            { header: 'Jumlah Item Bersaldo', dataIndex: 'item_count', width: 140, align: 'center' },
            { header: 'Nilai Stok', dataIndex: 'value', width: 160, align: 'right', renderer: fmtAmt }
        ]
    });
    var docGrid = new Ext.grid.GridPanel({
        store: docDs, loadMask: true, viewConfig: { forceFit: true },
        columns: [
            { header: 'Tanggal', dataIndex: 'doc_date', width: 90, align: 'center' },
            { header: 'Jenis Dokumen', dataIndex: 'reference_type', width: 140 },
            { header: 'No. Dokumen', dataIndex: 'reference_no', width: 150 },
            { header: 'Nilai Stok (+masuk / -keluar)', dataIndex: 'value', width: 170, align: 'right', renderer: fmtAmt },
            {
                header: 'Nilai Jurnal', dataIndex: 'journal_amount', width: 140, align: 'right',
                renderer: function (v) { return (v === null || v === undefined || v === '') ? '-' : fmtAmt(parseFloat(v)); }
            },
            {
                header: 'Status', dataIndex: 'status', width: 140, align: 'center',
                renderer: function (v) { return '<b style="color:' + statusColor(v) + ' !important;">' + enc(v) + '</b>'; }
            }
        ]
    });

    // [TAB BESAR] Tab dibuat sendiri (bukan Ext.TabPanel): tombol 18px, kontras jelas, tidak bergantung sprite gambar tab Ext.
    var tabNames = ['Per Item', 'Per Gudang', 'Dokumen vs Jurnal'];
    var tabBar = document.createElement('div');
    tabBar.id = 'irTabBar';
    tabBar.style.cssText = 'display:flex; gap:8px; margin:0 0 8px 0;';
    var panes = [];
    var tabBtns = [];
    var activeTab = 0;
    var holder = document.createElement('div');
    holder.id = 'irPanes';
    gridContainer.appendChild(tabBar);
    gridContainer.appendChild(holder);

    // ukuran font isi tabel di area ini dinaikkan agar terbaca
    var st = document.createElement('style');
    st.innerHTML = '#irPanes .x-grid3-cell-inner { font-size:15px !important; } #irPanes .x-grid3-hd-inner { font-size:14px !important; }';
    document.head.appendChild(st);

    Ext.each(tabNames, function (name, i) {
        var btn = document.createElement('button');
        btn.type = 'button';
        btn.innerHTML = name;
        btn.onclick = function () { selectTab(i); };
        tabBar.appendChild(btn);
        tabBtns.push(btn);
        var pane = document.createElement('div');
        pane.id = 'irPane' + i;
        holder.appendChild(pane);
        panes.push(pane);
    });

    function styleTabs() {
        Ext.each(tabBtns, function (btn, i) {
            var on = (i === activeTab);
            btn.style.cssText = 'font-size:18px; font-weight:bold; font-family:inherit; padding:8px 24px; cursor:pointer; border-radius:10px; ' +
                                'color:#ffffff; border:1px solid rgb(77, 255, 0); ' +
                                'background:' + (on ? '#00ff5530' : 'rgba(255,255,255,0.06)') + ';';
        });
    }

    var gridsReady = false;
    function buildGrids() {
        itemGrid.render('irPane0'); whGrid.render('irPane1'); docGrid.render('irPane2');
        gridsReady = true;
    }

    function selectTab(i) {
        activeTab = i;
        Ext.each(panes, function (pane, k) { pane.style.display = (k === i) ? 'block' : 'none'; });
        styleTabs();
        fitTabs();
    }

    // Tinggi grid aktif = sisa tinggi layar di bawah tab (setelah filter bar & ringkasan terisi)
    function fitTabs() {
        if (!gridsReady) return;
        var top = panes[activeTab].getBoundingClientRect().top;
        var h = (window.innerHeight || document.documentElement.clientHeight) - top - 24;
        var g = [itemGrid, whGrid, docGrid][activeTab];
        g.setHeight(Math.max(h, 240));
        g.setWidth(panes[activeTab].clientWidth || 800);
        g.doLayout();
    }
    window.onresize = fitTabs;

    buildGrids();
    selectTab(0);

    function applyDocFilter() {
        if (fldOnlyIssue.getValue()) {
            docDs.filterBy(function (r) { return r.get('status') === 'TIDAK ADA JURNAL' || r.get('status') === 'NILAI BEDA'; });
        } else {
            docDs.clearFilter();
        }
    }
    fldOnlyIssue.on('check', applyDocFilter);

    // ============================================================
    // 4. Muat rekonsiliasi
    // ============================================================
    function loadRecon() {
        if (!fldDate.getValue() || !fldAcc.getValue()) return;
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'reconcile', as_of: ymd(fldDate.getValue()), account_id: fldAcc.getValue() },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { summaryDiv.innerHTML = 'Respons server tidak valid (cek error_log PHP).'; return; }
                if (!res.success) { summaryDiv.innerHTML = '<span style="color:#ffeb3b;">' + enc(res.message || 'Gagal memuat data.') + '</span>'; return; }
                var d = res.data, s = d.summary;

                itemDs.loadData({ data: d.items, totalCount: d.items.length }, false);
                whDs.loadData({ data: d.warehouses, totalCount: d.warehouses.length }, false);
                docDs.loadData({ data: d.docs, totalCount: d.docs.length }, false);
                applyDocFilter();

                var ok = (s.status === 'SESUAI');
                var cell = function (label, val, color) {
                    return '<td style="padding:4px 22px 4px 0;"><div style="color:#9fc3d1; font-size:13px;">' + label + '</div>' +
                           '<div style="font-size:20px; font-weight:bold; color:' + (color || '#ffffff') + ';">' + fmtAmt(val) + '</div></td>';
                };
                var html = '<div style="margin-bottom:6px; font-size:15px;">Per <b>' + enc(s.as_of) + '</b> &nbsp;|&nbsp; Akun: <b>' + enc(s.account_label) + '</b> &nbsp;|&nbsp; ' +
                           '<b style="color:' + (ok ? '#4ade80' : '#f87171') + ';">' + (ok ? 'SESUAI ✓' : 'ADA SELISIH ✗') + '</b></div>' +
                           '<table style="border-collapse:collapse;"><tr>' +
                           cell('Nilai Buku (angka di pembukuan)', s.book_balance) +
                           cell('Nilai Stok (barang di gudang)', s.stock_value) +
                           cell('Selisih (Buku - Stok)', s.diff, ok ? '#4ade80' : '#f87171') +
                           '</tr></table>';

                // penjelasan bahasa sehari-hari + langkah yang harus dilakukan
                var fixCount = s.fixable_count || 0, otherProblem = Math.max((s.docs_problem || 0) - fixCount, 0);
                var msg = [];
                if (ok) {
                    msg.push('<span style="color:#4ade80;">Pembukuan dan gudang sudah cocok. <b>Tidak ada yang perlu dilakukan.</b></span> (' + s.docs_total + ' dokumen stok diperiksa)');
                } else {
                    if (fixCount > 0) {
                        msg.push('<b>' + fixCount + ' dokumen</b> (nilai ' + fmtAmt(s.fixable_value) + ') sudah mengubah stok di gudang tetapi <b>belum dicatat di pembukuan</b>. ' +
                                 'Klik tombol <b>"Proses Perbaikan Otomatis"</b> di atas untuk mencatatnya. Stok tidak diubah.');
                    }
                    if (otherProblem > 0) {
                        msg.push('<b>' + otherProblem + ' dokumen</b> nilainya berbeda antara pembukuan dan gudang. Ini tidak diperbaiki otomatis; mohon dicek oleh bagian akuntansi (lihat tab "Dokumen vs Jurnal").');
                    }
                    if (Math.abs(s.unexplained) > 1) {
                        msg.push('Ada selisih <b>' + fmtAmt(s.unexplained) + '</b> yang <b>tidak bisa dijelaskan</b> oleh dokumen stok (kemungkinan catatan manual di pembukuan). ' +
                                 'Ini perlu diperiksa manual oleh bagian akuntansi, jangan disamakan dengan tombol otomatis.');
                    }
                }
                html += '<div style="margin-top:8px; font-size:14px; line-height:1.6;">' + msg.join('<br/>') + '</div>' +
                        '<div style="margin-top:8px; color:#668894; font-size:12px;">Nilai Buku = jumlah persediaan menurut pembukuan. Nilai Stok = nilai barang yang ada di gudang menurut catatan stok. Keduanya seharusnya sama.</div>';
                summaryDiv.innerHTML = html;

                if (fixCount > 0) { btnFix.show(); } else { btnFix.hide(); }
                fixInfo = { count: fixCount, value: s.fixable_value };
                fitTabs();
            },
            failure: function () { summaryDiv.innerHTML = '<span style="color:#ffeb3b;">Gagal menghubungi server.</span>'; }
        });
    }

    var fixInfo = { count: 0, value: 0 };

    // [PERBAIKAN] Satu tombol: posting jurnal yang kurang (server menghitung ulang daftarnya; tidak ada angka dari klien)
    btnFix.on('click', function () {
        if (!fixInfo.count) return;
        Ext.Msg.confirm('Proses Perbaikan Otomatis',
            'Sistem akan mencatat <b>' + fixInfo.count + ' jurnal</b> (total nilai ' + fmtAmt(fixInfo.value) + ') untuk dokumen stok yang belum dicatat di pembukuan.' +
            '<br/>Stok di gudang <b>tidak</b> diubah. Lanjutkan?',
            function (btn) {
                if (btn !== 'yes') return;
                Ext.Ajax.request({
                    url: CRD, method: 'POST',
                    params: { task: 'fix_missing_journals', as_of: ymd(fldDate.getValue()), account_id: fldAcc.getValue() },
                    success: function (response) {
                        var res;
                        try { res = Ext.util.JSON.decode(response.responseText); }
                        catch (e) { showMsg('Gagal', 'Respons server tidak valid (cek error_log PHP).'); return; }
                        showMsg(res.success ? 'Sukses' : 'Gagal', res.message || '');
                        loadRecon();
                    },
                    failure: function () { showMsg('Gagal', 'Gagal menghubungi server.'); }
                });
            });
    });

    btnShow.on('click', loadRecon);
    accDs.load();      // setelah akun terisi (default = akun kredit rule ISSUE_PROJECT), rekonsiliasi dimuat otomatis

    document.getElementById('printData').onclick = function () {
        if (!fldDate.getValue() || !fldAcc.getValue()) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih tanggal dan akun terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        window.open('../pdf/inventoryrecon.php?as_of=' + ymd(fldDate.getValue()) + '&account_id=' + fldAcc.getValue(),
                    '_blank', 'width=900,height=800,scrollbars=yes,resizable=yes');
    };
    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function () { document.getElementById('popWdw').style.display = 'none'; };
    }
});