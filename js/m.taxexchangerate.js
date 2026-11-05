/**
 * Document      : Master Kurs Pajak (m.taxexchangerate.js)
 * Description   : ExtJS 3.4 Controller for Kurs Pajak Kemenkeu
 *                 Detail (rincian mata uang) ditampilkan sebagai panel HTML
 *                 table di bawah grid utama. Main Grid dikonfigurasi auto-fit screen.
 */

Ext.onReady(function() {
    Ext.QuickTips.init();

    // Sembunyikan tombol aksi default yang tidak diperlukan
    ['addData', 'editData'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Kurs Pajak (KMK)";
    }

    var task = '';
    var selectedHdrRecord = null;
    var pageSizeVal = typeof PageSize !== 'undefined' ? PageSize : 250;

    function renderMoneyVal(v) {
        return (typeof renderMoney === 'function') ? renderMoney(v) : Ext.util.Format.number(v || 0, '0,000.00');
    }

    // ------------------------------------------------------------
    // 0. Inject CSS untuk Detail Panel (Scrollable) & Grid Full-Width
    // ------------------------------------------------------------
    Ext.util.CSS.createStyleSheet(
        '#divMainGrid { width: 100% !important; } ' +
        '#kursDtlContainer {' +
        '    width: 100% !important;' +
        '    box-sizing: border-box;' +
        '    margin-top: 10px;' +
        '    padding: 12px;' +
        '    background: #1e293b;' +
        '    border: 1px solid #334155;' +
        '    border-radius: 6px;' +
        '    max-height: 380px !important;' +
        '    overflow-y: auto !important;' +
        '}' +
        '.detail-title { font-size: 14px; font-weight: bold; color: #f8fafc; margin-bottom: 8px; }' +
        '.detail-hr { border: 0; border-top: 1px solid #475569; margin-bottom: 12px; }' +
        '.detail-table { width: 100%; border-collapse: collapse; font-size: 12px; color: #e2e8f0; }' +
        '.detail-table th { background: #0f172a; color: #94a3b8; padding: 8px 10px; border-bottom: 2px solid #334155; text-transform: uppercase; font-size: 11px; }' +
        '.detail-table td { padding: 8px 10px; border-bottom: 1px solid #334155; }' +
        '.detail-table tr.data-row:hover { background: #334155; }',
        'tax-rate-detail-style'
    );

    // ------------------------------------------------------------
    // 0. Panel Detail Rincian Kurs (tampil di bawah grid utama)
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');
    var kursDtlDiv = document.createElement('div');
    kursDtlDiv.id = 'kursDtlContainer';
    kursDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(kursDtlDiv, gridContainer.nextSibling);
    }

    function loadKursDetailPanel(hdrId, kmkNo) {
        kursDtlDiv.innerHTML = '<div style="padding:15px;color:#94a3b8;text-align:center;">Memuat rincian kurs...</div>';
        kursDtlDiv.style.display = 'block';

        Ext.Ajax.request({
            url: '../dev/php-crud/m.taxexchangerateCRD.php',
            method: 'POST',
            params: { task: 'read_detail', hdr_id: hdrId },
            success: function(response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    kursDtlDiv.innerHTML = '<div style="color:#ef4444; padding:15px; text-align:center;">Gagal memuat rincian kurs.</div>';
                    kursDtlDiv.style.display = 'block';
                    return;
                }
                renderKursDetailPanel(res.data || [], kmkNo);
            },
            failure: function() {
                kursDtlDiv.innerHTML = '<div style="color:#ef4444; padding:15px; text-align:center;">Gagal terhubung ke server.</div>';
                kursDtlDiv.style.display = 'block';
            }
        });
    }

    function renderKursDetailPanel(rows, kmkNo) {
        var fmtUnit = function(v) { return Ext.util.Format.number(v || 0, '0,000'); };

        var html = '<div class="detail-title">Rincian Nilai Kurs Pajak Mingguan (Terhadap Rupiah / IDR) — KMK No: ' +
                   Ext.util.Format.htmlEncode(kmkNo || '') + '</div>' +
                   '<hr class="detail-hr" />' +
                   '<table id="kursDtlTable" class="detail-table" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr>' +
                   '<th align="center">ISO Code</th>' +
                   '<th align="left">Nama Mata Uang</th>' +
                   '<th align="center">Unit</th>' +
                   '<th align="right">Kurs KMK (IDR)</th>' +
                   '<th align="right">Kurs Murni / Unit (IDR)</th>' +
                   '<th align="center">Perubahan</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="6" style="padding:20px; text-align:center; color:#94a3b8;">Belum ada rincian kurs untuk periode ini.</td></tr>';
        } else {
            Ext.each(rows, function(r) {
                var chgHtml;
                if (r.change_direction === 'UP') {
                    chgHtml = '<span style="color:#22c55e;font-weight:bold;">&#9650; +' + renderMoneyVal(r.change_amount) + '</span>';
                } else if (r.change_direction === 'DOWN') {
                    chgHtml = '<span style="color:#ef4444;font-weight:bold;">&#9660; -' + renderMoneyVal(r.change_amount) + '</span>';
                } else {
                    chgHtml = '<span style="color:#94a3b8;">&#9644; Tetap</span>';
                }

                html += '<tr class="data-row">' +
                        '<td align="center"><b>' + Ext.util.Format.htmlEncode(r.currency_code || '') + '</b></td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.currency_name || '-') + '</td>' +
                        '<td align="center">' + fmtUnit(r.unit_multiplier) + '</td>' +
                        '<td align="right"><b>' + renderMoneyVal(r.rate_kmk) + '</b></td>' +
                        '<td align="right">' + renderMoneyVal(r.rate_effective) + '</td>' +
                        '<td align="center">' + chgHtml + '</td>' +
                        '</tr>';
            });
        }
        html += '</tbody></table>';

        kursDtlDiv.innerHTML = html;
        kursDtlDiv.style.display = 'block';
    }

    // ------------------------------------------------------------
    // STORES
    // ------------------------------------------------------------
    var hdrFields = [
        { name: 'id' },
        { name: 'kmk_no' },
        { name: 'start_date' },
        { name: 'end_date' },
        { name: 'file_url' },
        { name: 'remark' },
        { name: 'created_at' }
    ];

    var mainDs = new Ext.data.Store({
        url: '../dev/php-crud/m.taxexchangerateCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read', start: 0, limit: pageSizeVal },
        reader: new Ext.data.JsonReader({
            root: 'data',
            totalProperty: 'totalRecord',
            fields: hdrFields
        })
    });

    // ------------------------------------------------------------
    // BUTTON HANDLERS
    // ------------------------------------------------------------
    if (document.getElementById('deleteData')) {
        document.getElementById('deleteData').onclick = function() {
            if (typeof formAccess !== 'undefined' && formAccess < 4) {
                if (document.getElementById("popWdwTxt")) {
                    document.getElementById("popWdwTxt").innerHTML = 'Tidak ada hak akses untuk menghapus data ini.';
                    document.getElementById('popWdw').style.display = 'block';
                }
                return;
            }

            if (!selectedHdrRecord || !selectedHdrRecord.id) {
                if (document.getElementById("popWdwTxt")) {
                    document.getElementById("popWdwTxt").innerHTML = 'Pilih data periode Kurs Pajak terlebih dahulu.';
                    document.getElementById('popWdw').style.display = 'block';
                }
                return;
            }

            task = 'delete';
            if (document.getElementById('popWdw')) document.getElementById('popWdw').style.display = 'block';
            if (document.getElementById('popCancel')) document.getElementById('popCancel').style.display = 'block';
            if (document.getElementById("popWdwTxt")) {
                document.getElementById("popWdwTxt").innerHTML = 'Hapus data Kurs Pajak KMK No: \'' + selectedHdrRecord.get('kmk_no') + '\' ?';
            }
        };
    }

    if (document.getElementById('printData')) {
        document.getElementById('printData').onclick = function() {
            var hdrId = selectedHdrRecord ? selectedHdrRecord.get('id') : 0;
            var targetUrl = "../dev/php-crud/m.taxexchangerate.pdf.php?id=" + hdrId;
            window.open(targetUrl, 'print_kurs_pajak', 'toolbar=no,location=no,status=no,directories=no,menubar=no,width=900,height=700,scrollbars=yes');
        };
    }

    if (document.getElementById('popOK')) {
        document.getElementById('popOK').onclick = function() {
            if (task === 'delete' && selectedHdrRecord) {
                Ext.Ajax.request({
                    url: '../dev/php-crud/m.taxexchangerateCRD.php',
                    params: { task: 'delete', id: selectedHdrRecord.get('id') },
                    success: function(response) {
                        var res = Ext.util.JSON.decode(response.responseText);
                        if (document.getElementById('popWdw')) document.getElementById('popWdw').style.display = 'none';
                        if (document.getElementById('popCancel')) document.getElementById('popCancel').style.display = 'none';
                        task = '';

                        if (document.getElementById("popWdwTxt")) {
                            document.getElementById("popWdwTxt").innerHTML = res.message;
                            document.getElementById('popWdw').style.display = 'block';
                        }
                        selectedHdrRecord = null;
                        kursDtlDiv.style.display = 'none';
                        mainDs.load();
                    }
                });
                return;
            }
            if (document.getElementById('popWdw')) document.getElementById('popWdw').style.display = 'none';
            if (document.getElementById('popCancel')) document.getElementById('popCancel').style.display = 'none';
            task = '';
        };
    }

    // ------------------------------------------------------------
    // GRID UTAMA (FIT LEBAR SCREEN)
    // ------------------------------------------------------------
    // [FIX] Sebelumnya GridPanel tidak diberi "width" eksplisit dan hanya
    // mengandalkan viewConfig.forceFit. forceFit menghitung lebar kolom
    // dari ukuran grid SAAT PERTAMA KALI dirender; kalau di momen itu
    // container (#divMainGrid) belum benar-benar selebar layar (mis. CSS
    // "width:100%" belum sempat diterapkan browser), grid mengunci lebar
    // lama dan menyisakan area kosong di kanan, seperti pada screenshot.
    // Perbaikan: beri width eksplisit dari lebar container, dan setelah
    // render paksa ulang layout + refresh view sekali lagi (bukan cuma
    // mengandalkan forceFit di render pertama).
    var elGridDiv = document.getElementById('divMainGrid');

    function getGridWidth() {
        var w = elGridDiv ? elGridDiv.clientWidth : 0;
        return (w && w > 300) ? w : (document.documentElement.clientWidth || window.innerWidth || 1024);
    }

    var totalHeight = typeof maxGridHeight === 'function' ? maxGridHeight(document.getElementById('divMainGrid')) : 500;
    var mainHeight = Math.round(totalHeight * 0.45);

    var gridMain = new Ext.grid.GridPanel({
        renderTo  : 'divMainGrid',
        store     : mainDs,
        width     : getGridWidth(),   // [FIX] lebar eksplisit, bukan mengandalkan auto-measure saat render
        height    : mainHeight,
        stripeRows: true,
        loadMask  : { msg: 'Memuat data...' },
        sm        : new Ext.grid.RowSelectionModel({ singleSelect: true }),
        autoExpandColumn: 'colRemark', // [FIX] kolom Remark menyerap sisa lebar bila forceFit belum sempat jalan
        viewConfig: {
            forceFit : true,      // Memaksa kolom melebarkan diri secara proporsional sesuai lebar container/layar
            scrollOffset: 0
        },
        columns : [
            { header: "No. KMK", dataIndex: 'kmk_no', width: 220, renderer: typeof CellWrap !== 'undefined' ? CellWrap : Ext.identityFn },
            {
                header: "Tgl Mulai", dataIndex: 'start_date', width: 110, align: 'center',
                renderer: function(v) { return v ? Ext.util.Format.date(v, 'd-m-Y') : ''; }
            },
            {
                header: "Tgl Selesai", dataIndex: 'end_date', width: 110, align: 'center',
                renderer: function(v) { return v ? Ext.util.Format.date(v, 'd-m-Y') : ''; }
            },
            {
                header: "Salinan KMK (PDF)", dataIndex: 'file_url', width: 250,
                renderer: function(v) {
                    return v ? '<a href="' + v + '" target="_blank" style="color:#38bdf8;text-decoration:underline;">📥 Download File KMK</a>' : '-';
                }
            },
            { id: 'colRemark', header: "Remark", dataIndex: 'remark', width: 200 },
            { dataIndex: 'id', hidden: true }
        ],
        listeners: {
            rowclick: function(grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    selectedHdrRecord = rec;
                    window.dataRecord = { id: rec.get('id'), name: rec.get('kmk_no') };
                    loadKursDetailPanel(rec.get('id'), rec.get('kmk_no'));
                }
            }
        }
    });

    // [FIX] Sinkron ulang lebar setelah grid benar-benar dirender ke DOM,
    // saat container sudah pasti punya lebar akhir yang sebenarnya.
    gridMain.on('afterrender', function() {
        var w = getGridWidth();
        gridMain.setWidth(w);
        gridMain.doLayout();
        if (gridMain.getView()) gridMain.getView().refresh(true);
    });

    // [FIX] Auto resize saat ukuran browser diubah: sebelumnya hanya
    // view.refresh() tanpa setWidth(), sehingga grid tetap terkunci pada
    // lebar lama walau window melebar/menyempit.
    Ext.EventManager.onWindowResize(function() {
        if (!gridMain) return;
        gridMain.setWidth(getGridWidth());
        gridMain.doLayout();
        if (gridMain.getView()) gridMain.getView().refresh();
    });

    // Listener Keyboard / RowSelect
    gridMain.getSelectionModel().on('rowselect', function(sm, rowIndex, record) {
        selectedHdrRecord = record;
        window.dataRecord = { id: record.get('id'), name: record.get('kmk_no') };
        loadKursDetailPanel(record.get('id'), record.get('kmk_no'));
    });

    // Initial Load
    mainDs.load({
        callback: function() {
            if (mainDs.getCount() > 0) {
                gridMain.getSelectionModel().selectFirstRow();
            }
            // [FIX] Setelah data (dan scrollbar bila ada) muncul, ukuran
            // konten bisa mengubah lebar efektif container -> sinkron sekali lagi.
            var w = getGridWidth();
            gridMain.setWidth(w);
            gridMain.doLayout();
            if (gridMain.getView()) gridMain.getView().refresh(true);
        }
    });

    if (document.getElementById('editorWdw')) document.getElementById('editorWdw').style.display = 'none';
    if (document.getElementById('addWdw')) document.getElementById('addWdw').style.display = 'none';
});