/*
  Document      : grn00_5.js
  Description   : Purchase GRN (Goods Receipt Note) - ExtJS 3.x Frontend Controller
  Backend       : /inv/dev/php-crud/grnCRD.php
  Multi currency: GRN membekukan mata uang & kurs PO. Harga satuan GRN dalam mata uang PO (bisa diedit);
                  nilai dalam mata uang dasar (= harga x kurs PO) dihitung server dan dipakai WH Rcv.
                  GRN hanya bisa dibuat dari PO berstatus Disetujui.
  [NEW 2026-10-12] No. DO Supplier (grn_hdr.supplier_do_no): diinput di sini, lalu diturunkan read-only ke W/H RCV dan Retur Pembelian
                  (trace GRN -> W/H RCV -> Retur). Field dikirim lewat form submit (name 'supplier_do_no'); edit memuat via task read_edit.
  [NEW 2026-10-12] Plat No. Kendaraan & Nama Driver (grn_hdr.vehicle_plate_no / driver_name, opsional) di form entry, grid, dan panel detail.
                  Tombol 'Cetak Tanda Terima' di panel detail (task backend: read_grn_print) -> dokumen TANPA harga untuk driver/supplier.
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Goods Receipt Note";

    var task = '';
    var dataRecord = null;
    var grnCur = { code: 'IDR', base: 'IDR', rate: 1 };      // mata uang GRN yang sedang dilihat/diedit

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ------------------------------------------------------------
    // 0. Container Detail Item GRN
    // ------------------------------------------------------------
    var gridContainer = document.getElementById('divMainGrid');

    var grnDtlDiv = document.createElement('div');
    grnDtlDiv.id = 'grnDtlContainer';
    grnDtlDiv.className = 'detail-panel-container';
    grnDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(grnDtlDiv, gridContainer.nextSibling);
    }

    (function injectExtraUI() {
        var gridEditorContainer = document.getElementById('editorFormGrid');
        if (gridEditorContainer && !document.getElementById('lblGrnTotal')) {
            var totalsDiv = document.createElement('div');
            totalsDiv.className = 'invoice-totals';
            totalsDiv.style.cssText = 'padding:8px 0; font-weight:bold; font-size:14px; color:#fff; text-align:right;';
            totalsDiv.innerHTML = 'Total Nilai GRN: <span id="lblGrnTotal">0.00</span>';
            gridEditorContainer.parentNode.insertBefore(totalsDiv, gridEditorContainer.nextSibling);
        }
    })();

    // ------------------------------------------------------------
    // 1. Fields & Stores
    // ------------------------------------------------------------
    var grnField = [
        { name: 'id' }, { name: 'ref_no' }, { name: 'grn_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'po_id' }, { name: 'po_ref_no' }, { name: 'supplier_id' }, { name: 'supplier_name' },
        { name: 'status' }, { name: 'remark' },
        { name: 'total_qty', type: 'float' }, { name: 'total_amount', type: 'float' },
        { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }, { name: 'total_base', type: 'float' },
        { name: 'supplier_do_no' }, { name: 'vehicle_plate_no' }, { name: 'driver_name' }      // [NEW] No. DO Supplier, Plat No., Driver
    ];

    var itemField = [
        { name: 'po_dtl_id' }, { name: 'item_id' }, { name: 'item_name' },
        { name: 'qty_ordered', type: 'float' }, { name: 'unit_price', type: 'float' },
        { name: 'qty_received', type: 'float' },
        { name: 'sisa_qty_po', type: 'float' },
        { name: 'qty_max_allowed', type: 'float' },
        { name: 'qty_receivable_max', type: 'float' },
        { name: 'qty_received_this_grn', type: 'float' },
        { name: 'unit_cost_this_grn', type: 'float' },
        { name: 'qty_input', type: 'float' },
        { name: 'unit_cost', type: 'float' }
    ];

    var poField = [{ name: 'id' }, { name: 'ref_no' }, { name: 'supplier_id' }, { name: 'supplier_name' },
                   { name: 'currency_code' }, { name: 'exch_rate', type: 'float' }];
    var poDs = new Ext.data.Store({
        url: '../dev/php-crud/grnCRD.php',
        baseParams: { task: 'list_po_open' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, poField)
    });
    poDs.load();

    var itemGridDs = new Ext.data.Store({
        url: '../dev/php-crud/grnCRD.php',
        baseParams: { task: 'read_po_items' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, itemField),
        listeners: {
            load: function (store) {
                var cu = store.reader.jsonData && store.reader.jsonData.currency;
                if (cu) {
                    grnCur = { code: cu.code || 'IDR', base: cu.base_code || 'IDR', rate: parseFloat(cu.exch_rate) || 1 };
                    applyCurrencyHeaders();
                }
                store.each(function (r) {
                    var existingQty = parseFloat(r.get('qty_received_this_grn')) || 0;
                    if (existingQty > 0 && task === 'update') {
                        r.set('qty_input', existingQty);
                    } else {
                        var defaultQty = parseFloat(r.get('sisa_qty_po')) || 0;
                        r.set('qty_input', defaultQty);
                    }

                    var existingCost = r.get('unit_cost_this_grn');
                    var poUnitPrice = parseFloat(r.get('unit_price')) || 0;
                    r.set('unit_cost', (existingCost !== null && existingCost !== undefined && existingCost !== '') ? existingCost : poUnitPrice);
                });
                updateGrnTotalLabel();
            }
        }
    });

    var mainDs = new Ext.data.Store({
        url: '../dev/php-crud/grnCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, grnField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.45));

    var gridMain = new stdGrid({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'No. GRN', dataIndex: 'ref_no', width: 110, renderer: CellWrap },
            { header: 'Tanggal', dataIndex: 'grn_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'No. PO', dataIndex: 'po_ref_no', width: 110, renderer: CellWrap },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 170, renderer: CellWrap },
            { header: 'No. DO Supplier', dataIndex: 'supplier_do_no', width: 130, renderer: function (v) { return CellWrap(v || '-'); } },      // [NEW]
            { header: 'Plat No.', dataIndex: 'vehicle_plate_no', width: 90, align: 'center', renderer: function (v) { return CellWrap(v || '-'); } },      // [NEW]
            { header: 'Driver', dataIndex: 'driver_name', width: 110, renderer: function (v) { return CellWrap(v || '-'); } },      // [NEW]
            {
                header: 'Total Qty', dataIndex: 'total_qty', width: 100, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            { header: 'Mata Uang', dataIndex: 'currency_code', width: 80, align: 'center', renderer: function (v) { return v || '-'; } },
            {
                header: 'Total Nilai (Mata Uang PO)', dataIndex: 'total_amount', width: 150, align: 'right',
                renderer: function (v) { return '<b>' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>'; }
            },
            {
                header: 'Total Nilai (Dasar)', dataIndex: 'total_base', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Status', dataIndex: 'status', width: 110, align: 'center',
                renderer: function (v) {
                    if (v === 'draft') return '<span style="color:#e08e0b;font-weight:bold;">Draft</span>';
                    if (v === 'cancelled') return '<span style="color:#c0392b;font-weight:bold;">Cancelled</span>';
                    return '<span style="color:#2e9e4f;font-weight:bold;">Confirmed</span>';
                }
            },
            { header: 'Keterangan', dataIndex: 'remark', width: 160, renderer: CellWrap }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    mainBtnEnable('#deleteData');
                    editDataEnable();
                    loadGrnDetail(dataRecord.id, dataRecord.ref_no);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Detail View Renderer
    // ------------------------------------------------------------
    function loadGrnDetail(grnId, grnNo) {
        Ext.Ajax.request({
            url: '../dev/php-crud/grnCRD.php',
            method: 'POST',
            params: { task: 'read_grn_items', grn_id: grnId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    grnDtlDiv.innerHTML = '<div style="color:yellow;">Gagal memuat detail GRN.</div>';
                    grnDtlDiv.style.display = 'block';
                    return;
                }
                renderGrnDetailView(res.data || [], grnNo, res.currency);
            },
            failure: function () {
                grnDtlDiv.innerHTML = '<div style="color:red;">Gagal terhubung ke server.</div>';
                grnDtlDiv.style.display = 'block';
            }
        });
    }

    function renderGrnDetailView(rows, grnNo, cur) {
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); };
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        var code = (cur && cur.code) || 'IDR', baseCode = (cur && cur.base_code) || 'IDR';
        var diff = code !== baseCode;
        var html = '<h3 class="detail-title">Detail Item GRN: ' + Ext.util.Format.htmlEncode(grnNo || '') + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<div style="padding:4px 2px 10px 2px;">No. DO Supplier: <b>' + Ext.util.Format.htmlEncode((dataRecord && dataRecord.supplier_do_no) || '-') + '</b>' +
                   ' &nbsp;|&nbsp; Plat No.: <b>' + Ext.util.Format.htmlEncode((dataRecord && dataRecord.vehicle_plate_no) || '-') + '</b>' +
                   ' &nbsp;|&nbsp; Driver: <b>' + Ext.util.Format.htmlEncode((dataRecord && dataRecord.driver_name) || '-') + '</b>' +
                   '<button type="button" id="btnPrintGrn" style="float:right; padding:5px 14px; background:#2e6da4; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold;">Cetak Tanda Terima</button>' +
                   '</div>' +      // [NEW] info DO / kendaraan / driver + tombol cetak
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left">Nama Item</th>' +
                    '<th style="text-align:right;">Qty Diterima</th>' +
                    '<th style="text-align:right;">Harga Satuan (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                    '<th style="text-align:right;">Subtotal (' + Ext.util.Format.htmlEncode(code) + ')</th>' +
                    (diff ? '<th style="text-align:right;">Subtotal Dasar (' + Ext.util.Format.htmlEncode(baseCode) + ')</th>' : '') +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        var grandTotal = 0, grandBase = 0;

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="5" class="empty-msg">Tidak ada item detail.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var qty = parseFloat(r.qty) || 0;
                var cost = parseFloat(r.unit_price) || 0;
                var subtotal = qty * cost;
                var subBase = qty * (parseFloat(r.unit_cost_base) || 0);
                grandTotal += subtotal;
                grandBase += subBase;

                html += '<tr class="data-row">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.item_name || '-') + '</td>' +
                        '<td align="right" style="color:#4ade80; font-weight:bold;">' + fmtQty(qty) + '</td>' +
                        '<td align="right">' + fmtAmt(cost) + '</td>' +
                        '<td align="right" class="subtotal-val">' + fmtAmt(subtotal) + '</td>' +
                        (diff ? '<td align="right" style="color:#9fc3d1;">' + fmtAmt(subBase) + '</td>' : '') +
                        '</tr>';
            });
        }

        html += '</tbody>' +
                '<tfoot>' +
                '<tr class="total-row">' +
                '<td colspan="3" align="right" class="total-label">Total Nilai GRN:</td>' +
                '<td align="right" class="total-val">' + fmtAmt(grandTotal) + ' ' + Ext.util.Format.htmlEncode(code) + '</td>' +
                (diff ? '<td align="right" class="total-val">' + fmtAmt(grandBase) + ' ' + Ext.util.Format.htmlEncode(baseCode) + '</td>' : '') +
                '</tr>' +
                '</tfoot>' +
                '</table>';

        grnDtlDiv.innerHTML = html;
        grnDtlDiv.style.display = 'block';
        var pb = document.getElementById('btnPrintGrn'), printId = dataRecord ? dataRecord.id : 0;      // [NEW] tombol Cetak Tanda Terima
        if (pb && printId) { pb.onclick = function () { printGrn(printId); }; }
    }

    // ------------------------------------------------------------
    // 3b. [NEW] Cetak Tanda Terima GRN (tanpa harga). Jendela dibuka saat klik (lolos popup blocker),
    //     data dari task 'read_grn_print', lalu HTML ditulis ke jendela dan window.print() dipanggil.
    // ------------------------------------------------------------
    var COMPANY_NAME = '';      // isi sama seperti di warehouse.issuing.js / purchase.return.js; kosong = baris tidak dicetak
    var COMPANY_ADDR = '';

    function grnEnc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }
    function grnDmy(str) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(str || '');
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : (str || '-');
    }
    // [FIX-DATE] Date.format('d/m/Y H:i') bermasalah di browser ini (token Y tidak terformat) -> susun manual
    function grnNow() {
        var d = new Date(), p2 = function (n) { return n < 10 ? '0' + n : n; };
        return p2(d.getDate()) + '/' + p2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + p2(d.getHours()) + ':' + p2(d.getMinutes());
    }
    function grnQty(v) { return Ext.util.Format.number(parseFloat(v) || 0, '0,000.0000').replace(/\.?0+$/, ''); }

    function printGrn(grnId) {
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) { Ext.Msg.alert('Popup Diblokir', 'Izinkan popup untuk situs ini, lalu klik Cetak Tanda Terima lagi.'); return; }
        w.document.write('<html><body style="font-family:Arial,sans-serif;padding:20px;">Memuat Tanda Terima GRN...</body></html>');
        Ext.Ajax.request({
            url: '../dev/php-crud/grnCRD.php',
            method: 'POST',
            params: { task: 'read_grn_print', grn_id: grnId },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { w.close(); Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                if (!res.success) { w.close(); Ext.Msg.alert('Gagal', res.message || 'Gagal memuat data cetak.'); return; }
                w.document.open();
                w.document.write(buildGrnPrintHtml(res.data.header || {}, res.data.items || []));
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 400);
            },
            failure: function () { w.close(); Ext.Msg.alert('Gagal', 'Gagal menghubungi server.'); }
        });
    }

    function buildGrnPrintHtml(h, items) {
        var BLANK = '..............................';
        var rows = '', no = 0;
        Ext.each(items, function (i) {
            no++;
            var po = parseFloat(i.qty_po) || 0, cum = parseFloat(i.qty_cum) || 0;
            rows += '<tr><td class="c">' + no + '</td><td>' + grnEnc(i.material_code || '') + '</td><td>' + grnEnc(i.item_name) + '</td>' +
                    '<td class="r">' + grnQty(po) + '</td><td class="r"><b>' + grnQty(i.qty) + '</b></td><td class="r">' + grnQty(cum) + '</td>' +
                    '<td class="r">' + grnQty(Math.max(po - cum, 0)) + '</td><td class="c">' + grnEnc(i.unit || '') + '</td><td>' + grnEnc(i.remark || '') + '</td></tr>';
        });
        var css = '@page{size:A4;margin:12mm;}body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#000;margin:0;}' +
                  'h1{font-size:18px;margin:0 0 2px 0;text-align:center;letter-spacing:1px;}.sub{text-align:center;margin-bottom:10px;font-size:11px;}' +
                  'table{border-collapse:collapse;width:100%;}.meta td{padding:2px 4px;vertical-align:top;}' +
                  '.items th,.items td{border:1px solid #000;padding:5px 6px;}.items th{background:#eee;}' +
                  '.c{text-align:center;}.r{text-align:right;}.sign td{width:33%;text-align:center;padding-top:6px;}' +
                  '.sign .box{height:70px;}hr{border:0;border-top:2px solid #000;margin:8px 0;}';
        return '<html><head><meta charset="utf-8"><title>Tanda Terima GRN ' + grnEnc(h.ref_no) + '</title><style>' + css + '</style></head><body>' +
               (COMPANY_NAME ? '<div style="font-size:15px;font-weight:bold;text-align:center;">' + grnEnc(COMPANY_NAME) + '</div>' : '') +
               (COMPANY_ADDR ? '<div class="sub">' + grnEnc(COMPANY_ADDR) + '</div>' : '') +
               '<hr/><h1>TANDA TERIMA BARANG (GRN)</h1><div class="sub">Bukti penerimaan barang dari supplier</div>' +
               '<table class="meta"><tr>' +
               '<td width="16%">No. GRN</td><td width="34%">: <b>' + grnEnc(h.ref_no) + '</b></td>' +
               '<td width="16%">Tanggal Terima</td><td width="34%">: ' + grnDmy(h.grn_date) + '</td></tr><tr>' +
               '<td>Supplier</td><td>: <b>' + grnEnc(h.supplier_name || '-') + '</b></td>' +
               '<td>No. DO Supplier</td><td>: <b>' + grnEnc(h.supplier_do_no || '-') + '</b></td></tr><tr>' +
               '<td>No. PO</td><td>: ' + grnEnc(h.po_ref_no || '-') + (h.po_date ? ' (' + grnDmy(h.po_date) + ')' : '') + '</td>' +
               '<td>Proyek</td><td>: ' + grnEnc(h.project_name || '-') + '</td></tr><tr>' +
               '<td>Plat No. Kendaraan</td><td>: ' + (h.vehicle_plate_no ? grnEnc(h.vehicle_plate_no) : BLANK) + '</td>' +
               '<td>Driver</td><td>: ' + (h.driver_name ? grnEnc(h.driver_name) : BLANK) + '</td></tr></table>' +
               '<br/><table class="items"><thead><tr><th width="4%">No</th><th width="11%">Kode</th><th>Material</th><th width="9%">Qty PO</th>' +
               '<th width="10%">Diterima</th><th width="10%">Akumulasi</th><th width="8%">Sisa</th><th width="6%">Sat</th><th width="14%">Keterangan</th></tr></thead><tbody>' + rows +
               '</tbody></table><div style="margin-top:6px;">Total item: <b>' + no + '</b>' +
               (h.remark ? ' &nbsp;|&nbsp; Catatan: ' + grnEnc(h.remark) : '') + '</div><br/>' +
               '<table class="sign"><tr><td>Diterima oleh<br/>(Gudang)</td><td>Pengirim / Driver</td><td>Dibuat oleh<br/>(Admin)</td></tr>' +
               '<tr><td class="box"></td><td class="box"></td><td class="box"></td></tr>' +
               '<tr><td>(................................)</td><td>(' + (h.driver_name ? grnEnc(h.driver_name) : '................................') + ')</td>' +
               '<td>(' + (h.created_by_name ? grnEnc(h.created_by_name) : '................................') + ')</td></tr></table>' +
               '<div style="margin-top:14px;font-size:10px;color:#444;">Dicetak: ' + grnNow() + '</div></body></html>';
    }

    // ------------------------------------------------------------
    // 4. Item Grid Editor
    // ------------------------------------------------------------
    function toleranceRenderer(v, meta, record) {
        var qtyOrdered = parseFloat(record.get('qty_ordered')) || 0;
        var qtyMax = parseFloat(record.get('qty_max_allowed')) || (qtyOrdered * 1.10);
        var qtyInput = parseFloat(v) || 0;

        var pctOfOrdered = qtyOrdered > 0 ? (qtyInput / qtyOrdered) * 100 : 0;
        var color = '#2e9e4f';
        if (qtyInput > qtyOrdered && qtyInput <= qtyMax) {
            color = '#e08e0b';
        } else if (qtyInput > qtyMax) {
            color = '#c0392b';
        }
        return '<b style="color:' + color + ';">' + Ext.util.Format.number(qtyInput, '0,000.0000') +
               '</b> <span style="color:#888;font-size:11px;">(' + pctOfOrdered.toFixed(0) + '% dari PO)</span>';
    }

    var itemGrid = new stdGridEditor({
        store: itemGridDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 240,
        columns: [
            { header: 'Item', dataIndex: 'item_name', width: 180, renderer: CellWrap },
            {
                header: 'Qty PO', dataIndex: 'qty_ordered', width: 90, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: 'Sisa Qty PO', dataIndex: 'sisa_qty_po', width: 90, align: 'right',
                renderer: function (v) { return '<b style="color:#38bdf8;">' + Ext.util.Format.number(v || 0, '0,000.0000') + '</b>'; }
            },
            {
                header: 'Batas +10%', dataIndex: 'qty_max_allowed', width: 90, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.0000'); }
            },
            {
                header: 'Qty Diterima', dataIndex: 'qty_input', width: 150, align: 'right',
                editor: new myNumberField({ decimalPrecision: 4 }),
                renderer: toleranceRenderer
            },
            {
                header: 'Harga Satuan', dataIndex: 'unit_cost', width: 110, align: 'right',
                editor: new myNumberField({ decimalPrecision: 2 }),
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Subtotal', dataIndex: 'qty_input', width: 130, align: 'right',
                renderer: function (v, meta, record) {
                    var subtotal = (parseFloat(record.get('qty_input')) || 0) * (parseFloat(record.get('unit_cost')) || 0);
                    return '<b>' + Ext.util.Format.number(subtotal, '0,000.00') + '</b>';
                }
            },
            {
                header: 'Subtotal Dasar', dataIndex: 'unit_cost', width: 130, align: 'right', hidden: true,
                renderer: function (v, meta, record) {
                    var base = (parseFloat(record.get('qty_input')) || 0) * (parseFloat(record.get('unit_cost')) || 0) * grnCur.rate;
                    return '<span style="color:#9fc3d1;">' + Ext.util.Format.number(base, '0,000.00') + '</span>';
                }
            }
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'qty_input') {
                    var qtyMax = parseFloat(e.record.get('qty_max_allowed')) || 0;
                    var qtyInput = parseFloat(e.value) || 0;
                    if (qtyInput > qtyMax) {
                        e.record.set('qty_input', qtyMax);
                        Ext.Msg.alert('Peringatan', 'Qty diterima melebihi batas toleransi 10% dari PO. Otomatis dibatasi ke ' + Ext.util.Format.number(qtyMax, '0,000.0000') + '.');
                    }
                    if (qtyInput < 0) {
                        e.record.set('qty_input', 0);
                    }
                }
                updateGrnTotalLabel();
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    // Judul kolom mengikuti mata uang PO; kolom "Subtotal Dasar" hanya tampil bila mata uang PO bukan mata uang dasar
    function applyCurrencyHeaders() {
        var cm = itemGrid.getColumnModel();
        var diff = grnCur.code !== grnCur.base;
        cm.setColumnHeader(5, 'Harga Satuan (' + grnCur.code + ')');
        cm.setColumnHeader(6, 'Subtotal (' + grnCur.code + ')');
        cm.setColumnHeader(7, 'Subtotal Dasar (' + grnCur.base + ')');
        cm.setHidden(7, !diff);
        setCurInfo();
        itemGrid.getView().refresh();
    }

    function setCurInfo() {
        if (!fldCurInfo) return;
        fldCurInfo.setValue('<b>' + Ext.util.Format.htmlEncode(grnCur.code) + '</b>' +
            (grnCur.code !== grnCur.base
                ? ' &nbsp;(kurs PO: 1 ' + Ext.util.Format.htmlEncode(grnCur.code) + ' = ' + Ext.util.Format.number(grnCur.rate, '0,000.0000') + ' ' + Ext.util.Format.htmlEncode(grnCur.base) + ')'
                : ' &nbsp;(mata uang dasar)'));
    }

    function updateGrnTotalLabel() {
        var total = 0;
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            var cost = parseFloat(r.get('unit_cost')) || 0;
            total += qty * cost;
        });
        var el = document.getElementById('lblGrnTotal');
        if (el) {
            var html = Ext.util.Format.number(total, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(grnCur.code);
            if (grnCur.code !== grnCur.base) {
                html += ' <span style="color:#9fc3d1; font-weight:normal;">&asymp; ' + Ext.util.Format.number(total * grnCur.rate, '0,000.00') + ' ' + Ext.util.Format.htmlEncode(grnCur.base) + '</span>';
            }
            el.innerHTML = html;
        }
    }

    // ------------------------------------------------------------
    // 5. Editor Form
    // ------------------------------------------------------------
    // [CHANGED 2026-10-30] semua field diberi lebar eksplisit (anchor / width) dan dikelompokkan per kolom seperti form PO / Purchase Invoice;
    // sebelumnya tiap field berdiri sendiri tanpa lebar -> input dan pembungkusnya tampil sebagai dua kotak (form berantakan).
    var fldRefNo = new myTextField({ fieldLabel: 'No. GRN', id: 'ref_no', name: 'ref_no', readOnly: true, emptyText: 'Auto-generate', anchor: '95%' });
    var fldPoHdr = new myComboField({
        fieldLabel: 'Purchase Order', id: 'po_id', name: 'po_id', hiddenName: 'po_id',
        store: poDs, displayField: 'ref_no', valueField: 'id', allowBlank: false, anchor: '95%',      // [CHANGED]
        listeners: {
            select: function (combo, record) {
                Ext.getCmp('supplier_id_hidden') && Ext.getCmp('supplier_id_hidden').setValue(record.get('supplier_id'));
                grnCur = { code: record.get('currency_code') || grnCur.base, base: grnCur.base, rate: parseFloat(record.get('exch_rate')) || 1 };
                setCurInfo();
                itemGridDs.load({
                    params: {
                        po_id: record.get('id'),
                        grn_id: (task === 'update' && dataRecord) ? dataRecord.id : 0
                    }
                });
            }
        }
    });
    var fldCurInfo = new Ext.form.DisplayField({ fieldLabel: 'Mata Uang PO', value: '-' });
    var fldGrnDate = new myDateField({ fieldLabel: 'Tgl GRN', id: 'grn_date', name: 'grn_date', allowBlank: false, value: new Date(), width: 130 });   // [CHANGED]
    var fldSupplierIdHidden = new myTextField({ id: 'supplier_id_hidden', name: 'supplier_id', hidden: true });
    var fldRemark = new myTextArea({ fieldLabel: 'Keterangan', id: 'remark', name: 'remark', anchor: '95%', height: 60 });   // [CHANGED]
    // [NEW] No. DO Supplier (nomor surat jalan dari supplier) -> acuan trace ke W/H RCV dan Retur Pembelian
    var fldSupplierDo = new myTextField({ fieldLabel: 'No. DO Supplier', id: 'supplier_do_no', name: 'supplier_do_no', maxLength: 100, anchor: '95%' });   // [CHANGED]
    // [NEW] Plat No. Kendaraan & Nama Driver (opsional; backend merapikan plat jadi huruf besar)
    var fldPlate = new myTextField({ fieldLabel: 'Plat No. Kendaraan', id: 'vehicle_plate_no', name: 'vehicle_plate_no', maxLength: 20, anchor: '95%',     // [CHANGED]
        listeners: { blur: function (f) { f.setValue(String(f.getValue() || '').toUpperCase()); } } });
    var fldDriver = new myTextField({ fieldLabel: 'Nama Driver', id: 'driver_name', name: 'driver_name', maxLength: 100, anchor: '95%' });   // [CHANGED]

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 120,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            // [CHANGED] tiga kolom, field dikelompokkan dalam array (pola sama dengan form PO / Purchase Invoice)
            { columnWidth: .34, items: [fldRefNo, fldGrnDate, fldPoHdr] },
            { columnWidth: .33, items: [fldSupplierDo, fldPlate, fldDriver] },      // [NEW] DO, plat, driver
            { columnWidth: .33, items: [fldCurInfo, fldRemark, fldSupplierIdHidden] }
        ]
    });

    document.getElementById('addData').onclick = function () {
        if (getFormAccess() >= 2) {
            task = 'create';
            frmEditor.getForm().reset();
            itemGridDs.removeAll();
            grnCur = { code: 'IDR', base: grnCur.base, rate: 1 };
            grnCur.code = grnCur.base;
            applyCurrencyHeaders();
            poDs.load(); // Reload PO outstanding
            updateGrnTotalLabel();
            fldPoHdr.setDisabled(false);
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;GRN Baru';
        }
    };

    document.getElementById('editData').onclick = function () {
        task = '';
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data GRN yang ingin diedit.');
            return;
        }

        if (getFormAccess() >= 3) {
            frmEditor.getForm().reset();
            task = 'update';

            if (!poDs.getById(dataRecord.po_id)) {
                var PoRecord = Ext.data.Record.create(poField);
                poDs.add(new PoRecord({
                    id: dataRecord.po_id,
                    ref_no: dataRecord.po_ref_no + ' (sudah selesai diterima)',
                    supplier_id: dataRecord.supplier_id,
                    supplier_name: dataRecord.supplier_name,
                    currency_code: dataRecord.currency_code,
                    exch_rate: dataRecord.exch_rate
                }, dataRecord.po_id));
            }

            frmEditor.getForm().load({
                url: '../dev/php-crud/grnCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function (form, action) {
                    fldPoHdr.setDisabled(true);
                    itemGridDs.load({
                        params: {
                            po_id: dataRecord.po_id,
                            grn_id: dataRecord.id
                        },
                        callback: function () {
                            updateGrnTotalLabel();
                        }
                    });
                }
            });

            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit GRN';
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk mengedit.');
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data GRN yang ingin dihapus.');
            return;
        }
        if (getFormAccess() >= 4) {
            Ext.Msg.confirm('Konfirmasi Hapus', 'Hapus GRN \'' + dataRecord.ref_no + '\'? Jurnal & status outstanding PO akan dibatalkan otomatis.', function (btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: '../dev/php-crud/grnCRD.php',
                        method: 'POST',
                        params: { task: 'delete', id: dataRecord.id },
                        success: function (response) {
                            var res = Ext.util.JSON.decode(response.responseText);
                            Ext.Msg.alert('Informasi', res.message);
                            grnDtlDiv.style.display = 'none';
                            mainDs.load();
                        },
                        failure: function () {
                            Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
                        }
                    });
                }
            });
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses menghapus data.');
        }
    };

    document.getElementById('saveEdit').onclick = function () {
        var poHdrId = fldPoHdr.getValue();
        if (!poHdrId) {
            Ext.Msg.alert('Peringatan', 'Pilih Purchase Order terlebih dahulu.');
            return;
        }

        var arrItems = [];
        itemGridDs.each(function (r) {
            var qty = parseFloat(r.get('qty_input')) || 0;
            if (qty > 0) {
                arrItems.push({
                    po_dtl_id: r.get('po_dtl_id'),
                    item_id: r.get('item_id'),
                    item_name: r.get('item_name'),
                    qty_received: qty,
                    unit_cost: parseFloat(r.get('unit_cost')) || 0,
                    remark: ''
                });
            }
        });

        if (arrItems.length === 0) {
            Ext.Msg.alert('Peringatan', 'Minimal 1 item dengan qty diterima > 0.');
            return;
        }

        var currentId = (task === 'update' && dataRecord) ? dataRecord.id : null;
        var supplierId = fldSupplierIdHidden.getValue();
        if (!supplierId && task === 'update' && dataRecord) {
            supplierId = dataRecord.supplier_id;
        }

        frmEditor.getForm().submit({
            url: '../dev/php-crud/grnCRD.php',
            method: 'POST',
            params: {
                task: task,
                id: currentId,
                supplier_id: supplierId,
                arrItems: JSON.stringify(arrItems)
            },
            waitTitle: 'Menyimpan',
            waitMsg: 'Memproses GRN & auto-jurnal...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Sukses', res.message);
                grnDtlDiv.style.display = 'none';
                mainDs.load();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
            }
        });
    };

    document.getElementById('editorWdw').style.display = 'none';
    document.getElementById('grnDtlContainer').style.display = 'none';
});