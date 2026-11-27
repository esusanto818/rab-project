/*
  Document      : whrcv.js
  Description   : WH Rcv (Penerimaan Fisik Gudang) - Frontend Controller ExtJS 3.x
  Backend       : /php-crud/whrcvCRD.php
  Split bin     : satu baris GRN boleh dipecah ke beberapa Bin Location (mis. item X: 60 di A1-01 dan 40 di A1-02).
                  Klik "Split" di baris item -> isi qty yang dipindah -> baris baru muncul untuk memilih bin lain.
                  Total qty semua bin per item <= sisa GRN; bin tidak boleh sama dua kali; tiap baris hasil split wajib punya bin.
                  Tiap baris disimpan sebagai baris wh_receiving_dtl sendiri (satu layer FIFO per bin).
  Multi currency: nilai penerimaan dicatat dalam MATA UANG DASAR (diambil server dari GRN = harga GRN x kurs PO);
                  harga mata uang asal ikut ditampilkan sebagai jejak. Dasar FIFO, stok, dan jurnal = mata uang dasar.
*/

Ext.onReady(function () {
    // [FIX-DATE] Date.format('Y-m-d') Ext menghasilkan 'Y-10-13' di browser ini -> susun YYYY-MM-DD manual
    function ymd(d) {
        var m = d.getMonth() + 1, dd = d.getDate();
        return d.getFullYear() + '-' + (m < 10 ? '0' + m : m) + '-' + (dd < 10 ? '0' + dd : dd);
    }
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Penerimaan <em>Fisik Gudang</em>";

    // [GRID-WRAP 2026-11-17] Override SCOPED untuk grid halaman W/H Rcv:
    // #divMainGrid (daftar WH Rcv) & #editorFormGrid (item) — tidak menyentuh CSS global.
    // ExtJS 3.4.1 (css/ext-all.css) memasang
    //   .x-grid3-cell-inner,.x-grid3-hd-inner{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    // sehingga header & sel panjang (Supplier, Gudang, Item, No. DO) terpotong "...".
    // Paksa wrap + clip + tinggi baris dinamis; angka tetap rata kanan (align ColumnModel dihormati).
    /* [CSS-UNIFIED] Style dipindahkan ke css/app-theme.css */

    var task = '';
    var dataRecord = null;
    var whCur = { code: 'IDR', base: 'IDR', rate: 1 };      // mata uang GRN yang sedang diterima

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ------------------------------------------------------------
    // 0. Detail View Container Setup
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');

    var whrcvDtlDiv = document.createElement('div');
    whrcvDtlDiv.id = 'whrcvDtlContainer';
    whrcvDtlDiv.className = 'detail-panel-container';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(whrcvDtlDiv, gridContainer.nextSibling);
    }

    (function injectExtraUI() {
        var gridEditorContainer = document.getElementById('editorFormGrid');
        if (gridEditorContainer && !document.getElementById('lblWhrcvTotal')) {
            var totalsDiv = document.createElement('div');
            totalsDiv.className = 'invoice-totals';
            totalsDiv.style.cssText = 'padding:8px 0; font-weight:bold; font-size:14px; color:#fff; text-align:right;';
            totalsDiv.innerHTML = 'Total Nilai Diterima (dasar): <span id="lblWhrcvTotal">0.00</span>';
            gridEditorContainer.parentNode.insertBefore(totalsDiv, gridEditorContainer.nextSibling);
        }
    })();

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var whrcvField = [
        { name: 'id' }, { name: 'ref_no' }, { name: 'received_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'grn_hdr_id' }, { name: 'grn_ref_no' }, { name: 'supplier_do_no' }, { name: 'warehouse_id' },      // [NEW] supplier_do_no (dari GRN)
         { name: 'warehouse_name' },
        { name: 'supplier_name' }, { name: 'status' }, { name: 'remark' },
        { name: 'total_amount', type: 'float' }, { name: 'total_txn', type: 'float' }, { name: 'currency_code' }
    ];

    var itemField = [
        { name: 'grn_dtl_id' }, { name: 'item_id' }, { name: 'item_name' }, { name: 'grade_id' },
        { name: 'qty_grn', type: 'float' }, { name: 'unit_cost', type: 'float' }, { name: 'unit_cost_base', type: 'float' },
        { name: 'qty_wh_received', type: 'float' },
        { name: 'qty_remaining', type: 'float' },
        { name: 'qty_input', type: 'float' },
        { name: 'bin_location_id' }, { name: 'lot_no' },
        { name: 'is_split', type: 'boolean', defaultValue: false }      // true = baris hasil pecahan bin
    ];

    var grnField = [
        { name: 'id' }, { name: 'ref_no' }, { name: 'grn_date' }, { name: 'status' },
        { name: 'warehouse_id' }, { name: 'warehouse_name' }, { name: 'supplier_name' },
        { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }
    ];

    var grnDs = new Ext.data.Store({
        url: '../php-crud/whrcvCRD.php',
        baseParams: { task: 'list_grn_receivable' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, grnField)
    });
    grnDs.load();

    var warehouseField = [{ name: 'id' }, { name: 'init' }, { name: 'name' }];
    var warehouseDs = new Ext.data.Store({
        url: '../php-crud/whrcvCRD.php',
        baseParams: { task: 'list_warehouses' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, warehouseField)
    });
    warehouseDs.load();

    var binLocationField = [{ name: 'id' }, { name: 'code' }, { name: 'name' }, { name: 'zone' }, { name: 'rack' }, { name: 'level' }];
    var binLocationDs = new Ext.data.Store({
        url: '../php-crud/whrcvCRD.php',
        baseParams: { task: 'list_bin_locations' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, binLocationField)
    });

    var itemGridDs = new Ext.data.Store({
        url: '../php-crud/whrcvCRD.php',
        baseParams: { task: 'load_grn_dtl' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, itemField),
        listeners: {
            load: function (store) {
                var cu = store.reader.jsonData && store.reader.jsonData.currency;
                if (cu) {
                    whCur = { code: cu.code || 'IDR', base: cu.base_code || 'IDR', rate: parseFloat(cu.exch_rate) || 1 };
                    applyCurrencyHeaders();
                }
                store.each(function (r) {
                    var sisa = parseFloat(r.get('qty_remaining')) || 0;
                    r.set('qty_input', sisa);
                });
                updateWhrcvTotalLabel();
            }
        }
    });

    var mainDs = new Ext.data.Store({
        url: '../php-crud/whrcvCRD.php',
        idProperty: 'id',
        baseParams: { task: 'list' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, whrcvField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid Panel
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.45));

    var gridMain = new stdGrid({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        // [GRID-WRAP 2026-11-17] forceFit:true -> false agar Supplier/Gudang/Item wrap, bukan terpotong "...".
        viewConfig: { forceFit: false, scrollOffset: 0 },
        columns: [
            { header: 'No. WH Rcv', dataIndex: 'ref_no', width: 120, renderer: CellWrap },
            { header: 'No. GRN', dataIndex: 'grn_ref_no', width: 120, renderer: CellWrap },
            { header: 'No. DO Supplier', dataIndex: 'supplier_do_no', width: 130, renderer: function (v) { return CellWrap(v || '-'); } },      // [NEW] diturunkan dari GRN
            { header: 'Supplier', dataIndex: 'supplier_name', width: 170, renderer: CellWrap },
            { header: 'Gudang', dataIndex: 'warehouse_name', width: 120, renderer: CellWrap },
            { header: 'Tgl Terima', dataIndex: 'received_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 80, align: 'center', renderer: function (v) { return v || '-'; } },
            {
                header: 'Nilai Mata Uang Asal', dataIndex: 'total_txn', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Total Nilai (Dasar)', dataIndex: 'total_amount', width: 140, align: 'right',
                renderer: function (v) { return '<b>' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>'; }
            },
            {
                header: 'Status', dataIndex: 'status', width: 90, align: 'center',
                renderer: function (v) {
                    if (v === 'reversed') return '<span style="font-weight:bold">Reversed</span>';
                    return '<span style="font-weight:bold">Posted</span>';
                }
            }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    // [FIX] Edit tidak lagi dipakai (dokumen posted tidak punya form
                    // edit -- cuma bisa direverse), jadi tombol Edit dibiarkan mati.
                    // Delete di-enable di sini, dipakai untuk aksi Reverse (lihat
                    // handler #deleteData di bawah), dan dimatikan lagi kalau
                    // dokumennya sudah berstatus 'reversed'.
                    if (dataRecord.status === 'reversed' || getFormAccess() < 4) {
                        mainBtnDisable('#deleteData');
                    } else {
                        mainBtnEnable('#deleteData');
                    }
                    loadWhrcvDetail(dataRecord.id, dataRecord.ref_no);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Detail View Renderer (Disamakan dengan PO / GRN)
    // ------------------------------------------------------------
    function loadWhrcvDetail(id, refNo) {
        Ext.Ajax.request({
            url: '../php-crud/whrcvCRD.php',
            method: 'POST',
            params: { task: 'read_edit', id: id },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    whrcvDtlDiv.innerHTML = '<div >Gagal memuat detail.</div>';
                    whrcvDtlDiv.style.display = 'block';
                    return;
                }
                renderWhrcvDetailView(res.data.items || [], refNo, res.data.currency_code);
            },
            failure: function () {
                whrcvDtlDiv.innerHTML = '<div >Gagal terhubung ke server.</div>';
                whrcvDtlDiv.style.display = 'block';
            }
        });
    }

    function renderWhrcvDetailView(rows, refNo, curCode) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        var code = curCode || '';
        var html = '<h3 class="detail-title">Detail Item WH Rcv: ' + Ext.util.Format.htmlEncode(refNo || '') + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left">Nama Item</th>' +
                   '<th style="text-align:right">Qty Diterima</th>' +
                   '<th align="left">Lot No</th>' +
                   '<th align="left">Bin</th>' +
                   '<th align="right">Harga Asal' + (code ? ' (' + Ext.util.Format.htmlEncode(code) + ')' : '') + '</th>' +
                   '<th align="right">Harga Dasar</th>' +
                   '<th align="right">Subtotal Dasar</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        var grandTotal = 0;

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="7" class="empty-msg">Tidak ada item detail.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var qty = parseFloat(r.qty_received) || 0;
                var cost = parseFloat(r.unit_cost) || 0;                       // mata uang dasar
                var costTxn = (r.unit_cost_txn !== null && r.unit_cost_txn !== undefined) ? parseFloat(r.unit_cost_txn) : cost;
                var subtotal = qty * cost;
                grandTotal += subtotal;

                html += '<tr class="data-row">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.item_name || '-') + '</td>' +
                        '<td align="right" style="font-weight:bold">' + fmtQty(qty) + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.lot_no || '-') + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.bin_code || '-') + '</td>' +
                        '<td align="right" >' + fmtAmt(costTxn) + '</td>' +
                        '<td align="right">' + fmtAmt(cost) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(subtotal) + '</td>' +
                        '</tr>';
            });
        }

        html += '</tbody>' +
                '<tfoot>' +
                '<tr class="total-row">' +
                '<td colspan="6" align="right" class="total-label">Total Nilai Diterima (dasar):</td>' +
                '<td align="right" class="total-val">' + fmtAmt(grandTotal) + '</td>' +
                '</tr>' +
                '</tfoot>' +
                '</table>';

        whrcvDtlDiv.innerHTML = html;
        whrcvDtlDiv.style.display = 'block';
    }

    // ------------------------------------------------------------
    // 4. Item Grid Editor (Form Grid)
    // ------------------------------------------------------------
    var itemGrid = new stdGridEditor({
        store: itemGridDs,
        renderTo: 'editorFormGrid',
        // [GRID-WRAP 2026-11-17] forceFit:true -> false agar Item/nama panjang wrap, bukan terpotong "...".
        viewConfig: { forceFit: false, scrollOffset: 0 },
        height: 220,
        columns: [
            {
                header: 'Item', dataIndex: 'item_name', width: 180,
                renderer: function (v, meta, record) {
                    if (record && record.get('is_split')) {
                        return '<span style="padding-left:14px">&#8627; ' + Ext.util.Format.htmlEncode(v || '') + '</span>';
                    }
                    return CellWrap.apply(this, arguments);
                }
            },
            {
                header: 'Qty GRN', dataIndex: 'qty_grn', width: 90, align: 'right',
                renderer: function (v, meta, record) {
                    if (record && record.get('is_split')) return '';
                    return Ext.util.Format.number(v || 0, '0,000.0000');
                }
            },
            {
                header: 'Sisa Belum Diterima', dataIndex: 'qty_remaining', width: 110, align: 'right',
                renderer: function (v, meta, record) {
                    if (record && record.get('is_split')) return '';
                    return '<b >' + Ext.util.Format.number(v || 0, '0,000.0000') + '</b>';
                }
            },
            {
                header: 'Qty Diterima Skrg', dataIndex: 'qty_input', width: 120, align: 'right',
                editor: new myNumberField({ decimalPrecision: 4 }),
                renderer: function (v) { return '<b >' + Ext.util.Format.number(v || 0, '0,000.0000') + '</b>'; }
            },
            {
                header: 'Lot No', dataIndex: 'lot_no', width: 100,
                editor: new myTextField({})
            },
            {
                header: 'Bin Location', dataIndex: 'bin_location_id', width: 130,
                editor: new myComboField({
                    store: binLocationDs, displayField: 'code', valueField: 'id',
                    mode: 'local', triggerAction: 'all', forceSelection: true
                }),
                renderer: function (v) {
                    if (!v) return '-';
                    var rec = binLocationDs.getById(v);
                    return rec ? rec.get('code') : v;
                }
            },
            {
                header: 'Harga Asal', dataIndex: 'unit_cost', width: 100, align: 'right',
                renderer: function (v) { return '<span >' + Ext.util.Format.number(v || 0, '0,000.00') + '</span>'; }
            },
            {
                header: 'Harga Dasar', dataIndex: 'unit_cost_base', width: 100, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Subtotal Dasar', dataIndex: 'qty_input', width: 120, align: 'right',
                renderer: function (v, meta, record) {
                    var subtotal = (parseFloat(record.get('qty_input')) || 0) * (parseFloat(record.get('unit_cost_base')) || 0);
                    return '<b>' + Ext.util.Format.number(subtotal, '0,000.00') + '</b>';
                }
            },
            {
                id: 'whSplitCol', header: 'Split Bin', dataIndex: 'is_split', width: 90, align: 'center', fixed: true,
                renderer: function (v, meta, record) {
                    var html = '<span class="whSplitBtn" title="Pecah qty item ini ke bin lain" style="cursor:pointer; font-weight:bold">&#10697; Split</span>';
                    if (record && record.get('is_split')) {
                        html += ' <span class="whSplitDel" title="Hapus baris ini (qty kembali ke baris induk)" style="cursor:pointer; font-weight:bold; margin-left:6px">&#10005;</span>';
                    }
                    return html;
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                var rec = e.record;
                if (e.field === 'qty_input') {
                    var q = parseFloat(e.value) || 0;
                    if (q < 0) { rec.set('qty_input', 0); q = 0; }
                    var maxQty = splitGroupMax(rec);            // sisa GRN dikurangi qty baris lain di item yang sama
                    if (q > maxQty + 0.00005) {
                        rec.set('qty_input', Math.max(maxQty, 0));
                        Ext.Msg.alert('Peringatan', 'Qty melebihi sisa GRN' + (splitGroupRows(rec).length > 1 ? ' (total semua bin untuk item ini)' : '') +
                                      '. Otomatis dibatasi ke ' + Ext.util.Format.number(Math.max(maxQty, 0), '0,000.0000') + '.');
                    }
                } else if (e.field === 'bin_location_id' && rec.get('bin_location_id')) {
                    var dup = false;
                    Ext.each(splitGroupRows(rec), function (r) {
                        if (r !== rec && String(r.get('bin_location_id')) === String(rec.get('bin_location_id'))) dup = true;
                    });
                    if (dup) {
                        rec.set('bin_location_id', e.originalValue || null);
                        Ext.Msg.alert('Peringatan', 'Bin tersebut sudah dipakai baris lain untuk item yang sama. Gabungkan qty-nya ke satu baris.');
                    }
                }
                updateWhrcvTotalLabel();
            },
            cellclick: function (grid, rowIndex, colIndex, e) {
                var rec = grid.getStore().getAt(rowIndex);
                if (!rec) return;
                if (e.getTarget('.whSplitDel')) { removeSplitRow(rec); return; }
                if (e.getTarget('.whSplitBtn')) { splitRow(rec); }
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // ------------------------------------------------------------
    // Split bin: satu baris GRN -> beberapa baris dengan bin berbeda
    // ------------------------------------------------------------
    function round4(v) { return Math.round((parseFloat(v) || 0) * 10000) / 10000; }

    // Semua baris (induk + hasil split) milik baris GRN yang sama, urut seperti di grid
    function splitGroupRows(rec) {
        var rows = [], id = String(rec.get('grn_dtl_id'));
        itemGridDs.each(function (r) { if (String(r.get('grn_dtl_id')) === id) rows.push(r); });
        return rows;
    }

    // Qty maksimum untuk baris ini = sisa GRN - qty baris lain pada item yang sama
    function splitGroupMax(rec) {
        var others = 0;
        Ext.each(splitGroupRows(rec), function (r) { if (r !== rec) others += parseFloat(r.get('qty_input')) || 0; });
        return round4((parseFloat(rec.get('qty_remaining')) || 0) - others);
    }

    function splitRow(rec) {
        var cur = parseFloat(rec.get('qty_input')) || 0;
        if (cur <= 0.0001) {
            Ext.Msg.alert('Peringatan', 'Isi Qty Diterima baris ini dulu sebelum dipecah.');
            return;
        }
        Ext.Msg.prompt('Split Bin Location',
            'Item: <b>' + Ext.util.Format.htmlEncode(rec.get('item_name') || '') + '</b><br />' +
            'Qty yang dipindah ke baris baru (bin lain), kurang dari ' + Ext.util.Format.number(cur, '0,000.0000') + ':',
            function (btn, text) {
                if (btn !== 'ok') return;
                var t = String(text || '').trim();
                if (t.indexOf(',') >= 0 && t.indexOf('.') < 0) t = t.replace(',', '.');
                var q = round4(t);
                if (!(q > 0) || q >= cur - 0.00005) {
                    Ext.Msg.alert('Peringatan', 'Qty split harus lebih dari 0 dan kurang dari ' + Ext.util.Format.number(cur, '0,000.0000') + '.');
                    return;
                }
                var child = new itemGridDs.recordType({
                    grn_dtl_id: rec.get('grn_dtl_id'), item_id: rec.get('item_id'), item_name: rec.get('item_name'),
                    grade_id: rec.get('grade_id'), qty_grn: rec.get('qty_grn'), unit_cost: rec.get('unit_cost'),
                    unit_cost_base: rec.get('unit_cost_base'), qty_wh_received: rec.get('qty_wh_received'),
                    qty_remaining: rec.get('qty_remaining'), qty_input: q,
                    lot_no: rec.get('lot_no') || '', bin_location_id: null, is_split: true
                });
                rec.set('qty_input', round4(cur - q));
                var rows = splitGroupRows(rec);
                var at = itemGridDs.indexOf(rows[rows.length - 1]) + 1;         // tepat setelah baris terakhir item ini
                itemGridDs.insert(at, child);
                updateWhrcvTotalLabel();
                try { itemGrid.startEditing(at, itemGrid.getColumnModel().findColumnIndex('bin_location_id')); } catch (e) {}
            }, this, false, String(round4(cur / 2)));
    }

    // Hapus baris hasil split: qty dikembalikan ke baris induk (baris pertama item tsb)
    function removeSplitRow(rec) {
        if (!rec.get('is_split')) return;
        var rows = splitGroupRows(rec), parent = rows[0];
        if (!parent || parent === rec) return;
        parent.set('qty_input', round4((parseFloat(parent.get('qty_input')) || 0) + (parseFloat(rec.get('qty_input')) || 0)));
        itemGridDs.remove(rec);
        updateWhrcvTotalLabel();
    }

    // Kolom "Harga Asal" disembunyikan bila mata uang GRN = mata uang dasar; judul kolom memuat kode mata uang
    function applyCurrencyHeaders() {
        var cm = itemGrid.getColumnModel();
        var diff = whCur.code !== whCur.base;
        cm.setColumnHeader(6, 'Harga Asal (' + whCur.code + ')');
        cm.setColumnHeader(7, 'Harga Dasar (' + whCur.base + ')');
        cm.setColumnHeader(8, 'Subtotal Dasar (' + whCur.base + ')');
        cm.setHidden(6, !diff);
        itemGrid.getView().refresh();
    }

    function updateWhrcvTotalLabel() {
        var total = 0, totalTxn = 0;
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            total += qty * (parseFloat(r.get('unit_cost_base')) || 0);          // mata uang dasar
            totalTxn += qty * (parseFloat(r.get('unit_cost')) || 0);            // mata uang asal
        });
        var el = document.getElementById('lblWhrcvTotal');
        if (el) {
            var html = Ext.util.Format.number(total, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(whCur.base);
            if (whCur.code !== whCur.base) {
                html += ' <span style="font-weight:normal">(asal ' + Ext.util.Format.number(totalTxn, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(whCur.code) + ')</span>';
            }
            el.innerHTML = html;
        }
    }

    // ------------------------------------------------------------
    // 5. Form Header & Input Controls
    // ------------------------------------------------------------
    var fldRefNo = new myTextField({ fieldLabel: 'No. WH Rcv', id: 'ref_no', name: 'ref_no', readOnly: true, emptyText: 'Auto-generate' });
    var fldGrnHdr = new myComboField({
        fieldLabel: 'GRN yang Diterima', id: 'grn_hdr_id', name: 'grn_hdr_id', hiddenName: 'grn_hdr_id',
        store: grnDs, displayField: 'ref_no', valueField: 'id', allowBlank: false,
        listeners: {
            select: function (combo, record) {
                if (record.get('warehouse_id')) {
                    fldWarehouse.setValue(record.get('warehouse_id'));
                    binLocationDs.load({ params: { warehouse_id: record.get('warehouse_id') } });
                }
                itemGridDs.load({ params: { grn_hdr_id: record.get('id') } });
            }
        }
    });
    var fldWarehouse = new myComboField({
        fieldLabel: 'Gudang Penerima', id: 'warehouse_id', name: 'warehouse_id', hiddenName: 'warehouse_id',
        store: warehouseDs, displayField: 'name', valueField: 'id', allowBlank: false,
        listeners: {
            select: function (combo, record) {
                binLocationDs.load({ params: { warehouse_id: record.get('id') } });
            }
        }
    });
    var fldReceivedDate = new myDateField({ fieldLabel: 'Tgl Terima', id: 'received_date', name: 'received_date', allowBlank: false, value: new Date() });
    var fldRemark = new myTextArea({ fieldLabel: 'Keterangan', id: 'remark', name: 'remark' });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldRefNo },
            { items: fldReceivedDate },
            { items: fldGrnHdr },
            { items: fldWarehouse },
            { columnWidth: 1, items: fldRemark }
        ]
    });

    // ------------------------------------------------------------
    // 6. Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() >= 2) {
            task = 'create';
            frmEditor.getForm().reset();
            itemGridDs.removeAll();
            grnDs.load();
            updateWhrcvTotalLabel();
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Penerimaan Fisik Gudang Baru';
        }
    };

    document.getElementById('editData').onclick = function () {
        // [FIX] Edit dimatikan permanen -- dokumen WH Rcv yang sudah posted
        // tidak punya form edit, cuma bisa dibatalkan lewat tombol Delete
        // (yang menjalankan Reverse, lihat handler di bawah).
        Ext.Msg.alert('Info', 'Dokumen yang sudah posted tidak bisa diedit. Gunakan tombol Delete untuk membatalkan (reverse) transaksi ini.');
    };

    document.getElementById('deleteData').onclick = function () {
        // [FIX] Aksi "Delete" untuk modul ini sebenarnya adalah Reverse
        // (pembatalan dengan jejak audit tetap ada) -- dokumen WH Rcv yang
        // sudah posted TIDAK di-hard-delete, karena sudah memengaruhi stok
        // dan jurnal. Sebelumnya aksi ini keliru dipasang di tombol Edit.
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data WH Rcv yang akan di-reverse.');
            return;
        }
        if (dataRecord.status === 'reversed') {
            Ext.Msg.alert('Peringatan', 'WH Rcv ini sudah di-reverse sebelumnya.');
            return;
        }
        if (getFormAccess() >= 4) {
            Ext.Msg.confirm('Konfirmasi Reverse', 'Reverse WH Rcv \'' + dataRecord.ref_no + '\'? Stok & jurnal akan dibatalkan.', function (btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: '../php-crud/whrcvCRD.php',
                        method: 'POST',
                        params: { task: 'reverse', id: dataRecord.id },
                        success: function (response) {
                            var res = Ext.util.JSON.decode(response.responseText);
                            if (res.success) {
                                Ext.Msg.alert('Informasi', res.message || 'WH Rcv berhasil di-reverse.');
                                whrcvDtlDiv.style.display = 'none';
                                mainDs.load();
                                grnDs.load();
                                dataRecord = null;
                                mainBtnDisable('#deleteData');
                            } else {
                                Ext.Msg.alert('Gagal', res.message);
                            }
                        },
                        failure: function () {
                            Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
                        }
                    });
                }
            });
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk reverse.');
        }
    };

    document.getElementById('saveEdit').onclick = function () {
        var grnHdrId = fldGrnHdr.getValue();
        if (!grnHdrId) {
            Ext.Msg.alert('Peringatan', 'Pilih GRN yang mau diterima terlebih dahulu.');
            return;
        }
        if (!fldWarehouse.getValue()) {
            Ext.Msg.alert('Peringatan', 'Pilih gudang terlebih dahulu.');
            return;
        }

        // Validasi split bin per item: total <= sisa GRN, bin wajib & tidak boleh sama bila item dipecah
        var groups = {}, splitErr = '';
        itemGridDs.each(function (r) {
            if ((parseFloat(r.get('qty_input')) || 0) <= 0) return;
            var k = String(r.get('grn_dtl_id'));
            (groups[k] = groups[k] || []).push(r);
        });
        Ext.iterate(groups, function (k, rows) {
            var sum = 0, name = rows[0].get('item_name'), rem = parseFloat(rows[0].get('qty_remaining')) || 0;
            Ext.each(rows, function (r) { sum += parseFloat(r.get('qty_input')) || 0; });
            if (sum > rem + 0.0001) splitErr = splitErr || ('Total qty ' + name + ' (' + round4(sum) + ') melebihi sisa GRN (' + round4(rem) + ').');
            if (rows.length > 1) {
                var seen = {};
                Ext.each(rows, function (r) {
                    var b = r.get('bin_location_id');
                    if (!b) splitErr = splitErr || ('Item ' + name + ' dipecah ke beberapa baris: Bin Location wajib diisi di setiap baris.');
                    else if (seen[b]) splitErr = splitErr || ('Item ' + name + ': bin yang sama dipakai dua kali. Gabungkan qty-nya.');
                    else seen[b] = true;
                });
            }
        });
        if (splitErr) {
            Ext.Msg.alert('Peringatan', splitErr);
            return;
        }

        var arrItems = [];
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            if (qty > 0) {
                arrItems.push({
                    grn_dtl_id: r.get('grn_dtl_id'),
                    item_id: r.get('item_id'),
                    item_name: r.get('item_name'),
                    grade_id: r.get('grade_id'),
                    qty_received: qty,
                    unit_cost: parseFloat(r.get('unit_cost')) || 0,
                    lot_no: r.get('lot_no') || null,
                    bin_location_id: r.get('bin_location_id') || null
                });
            }
        });

        if (arrItems.length === 0) {
            Ext.Msg.alert('Peringatan', 'Minimal 1 item dengan qty diterima > 0.');
            return;
        }

        frmEditor.getForm().submit({
            url: '../php-crud/whrcvCRD.php',
            method: 'POST',
            params: {
                task: 'create',
                grn_hdr_id: grnHdrId,
                warehouse_id: fldWarehouse.getValue(),
                received_date: ymd(fldReceivedDate.getValue()),      // [FIX-DATE]
                items: JSON.stringify(arrItems)
            },
            waitTitle: 'Menyimpan',
            waitMsg: 'Memproses penerimaan & posting stok/jurnal...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Sukses', res.message || ('WH Rcv ' + res.ref_no + ' berhasil disimpan.'));
                whrcvDtlDiv.style.display = 'none';
                mainDs.load();
                grnDs.load();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('editorWdw').style.display = 'none';
    document.getElementById('whrcvDtlContainer').style.display = 'none';

});