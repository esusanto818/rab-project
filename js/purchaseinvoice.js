/* Document      : purchaseinvoice.js 
   Description   : Complete Purchase Invoice JS - Fixed Main Grid Loading & Auto Default Matched
   [BARU 2026-10-08] Tombol "Cetak Tanda Terima" di panel detail (task backend: read_invoice_print) */

Ext.onReady(function () {
    Ext.QuickTips.init();

    // [BARU] Hilangkan background warna default toolbar khusus untuk grid
    // editor detail invoice ini saja (scoped ke #editorFormGrid supaya
    // tidak mempengaruhi toolbar grid lain di halaman lain).
    Ext.util.CSS.createStyleSheet(
        '#editorFormGrid .x-toolbar, #editorFormGrid .x-toolbar td, #editorFormGrid .x-toolbar tr { ' +
        'background: transparent !important; background-image: none !important; border: none !important; }',
        'invoice-grid-tbar-transparent'
    );

    var task = '';
    var dataRecord = null;

    // ------------------------------------------------------------
    // 1. Data Store Main Grid
    // ------------------------------------------------------------
    var invoiceFields = [
        { name: 'id' },
        { name: 'inv_no' },
        { name: 'supplier_invoice_no' },
        { name: 'invoice_date' },
        { name: 'supplier_id' },
        { name: 'supplier_name' },
        { name: 'status' },
        { name: 'remark' },
        { name: 'total_amount', type: 'float' },
        { name: 'paid_amount', type: 'float' },
        { name: 'dpp_amount', type: 'float' }, { name: 'ppn_rate', type: 'float' }, { name: 'ppn_amount', type: 'float' },   // [PPN]
        { name: 'faktur_pajak_no' }, { name: 'faktur_pajak_date' }                                                       // [PPN]
    ];

    var mainDs = new Ext.data.Store({
        url: '../dev/php-crud/purchaseinvoiceCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, invoiceFields),
        listeners: {
            // [PERBAIKAN] Tambahkan listener exception/load untuk debugging jika grid kosong
            exception: function(proxy, type, action, options, response, arg) {
                try {
                    var res = Ext.util.JSON.decode(response.responseText);
                    Ext.Msg.alert('Error Data', res.message || 'Gagal memuat data main grid.');
                } catch(e) {
                    console.error('Response error:', response.responseText);
                }
            }
        }
    });

    // Store Supplier untuk ComboBox Form Editor
    var supplierDs = new Ext.data.Store({
        url: '../dev/php-crud/purchaseinvoiceCRD.php',
        baseParams: { task: 'read_supplier' },
        reader: new Ext.data.JsonReader({ root: 'data' }, [
            { name: 'id' },
            { name: 'name' },
            { name: 'is_pkp' }          // [PPN] status PKP supplier -> default tarif PPN
        ])
    });

    // ------------------------------------------------------------
    // 2. Main Grid Panel (Tanpa bbar / Paging Toolbar)
    // ------------------------------------------------------------
    var elGridDiv = document.getElementById('divMainGrid');
    var calcHeight = 380;
    if (elGridDiv && elGridDiv.offsetHeight > 100) {
        calcHeight = elGridDiv.offsetHeight;
    }

    var gridMain = new Ext.grid.GridPanel({
        renderTo: 'divMainGrid',
        store: mainDs,
        height: calcHeight,
        stripeRows: true,
        loadMask: true,
        viewConfig: {
            forceFit: true,
            emptyText: '<div style="padding:20px; text-align:center; color:#94a3b8;">Tidak ada data untuk ditampilkan.</div>' // [PERBAIKAN] Pesan jika data kosong
        },
        columns: [
            { header: 'No. Invoice Internal', dataIndex: 'inv_no', sortable: true },
            { header: 'No. Supp. Invoice', dataIndex: 'supplier_invoice_no', sortable: true },
            { header: 'Tgl Invoice', dataIndex: 'invoice_date', sortable: true },
            { header: 'Supplier', dataIndex: 'supplier_name', sortable: true },
            // [PPN] DPP, PPN Masukan, no. faktur pajak
            { header: 'DPP (Rp)', dataIndex: 'dpp_amount', align: 'right', renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'PPN (Rp)', dataIndex: 'ppn_amount', align: 'right', renderer: function (v) { return v > 0 ? Ext.util.Format.number(v, '0,000.00') : '-'; } },
            { header: 'No. Faktur Pajak', dataIndex: 'faktur_pajak_no', sortable: true },
            { 
                header: 'Total + PPN (Rp)', 
                dataIndex: 'total_amount', 
                align: 'right',
                renderer: function (v) {
                    return Ext.util.Format.number(v || 0, '0,000.00');
                }
            },
            { header: 'Status', dataIndex: 'status', align: 'center' },
            { header: 'Keterangan', dataIndex: 'remark' }
        ]
    });

    mainDs.load();

    // ------------------------------------------------------------
    // 3. Container Panel Detail Bawah (Main View)
    // ------------------------------------------------------------
    var payDtlDiv = document.createElement('div');
    payDtlDiv.id = 'payDtlContainer';
    payDtlDiv.className = 'detail-panel-container';
    payDtlDiv.style.display = 'none';

    if (elGridDiv && elGridDiv.parentNode) {
        elGridDiv.parentNode.insertBefore(payDtlDiv, elGridDiv.nextSibling);
    }

    function loadInvoiceDetailPanel(invoiceId, invoiceNo, supplierId) {
        if (!invoiceId) return;

        Ext.Ajax.request({
            url: '../dev/php-crud/purchaseinvoiceCRD.php',
            method: 'POST',
            params: { 
                task: 'read_invoice_detail', 
                invoice_id: invoiceId,
                supplier_id: supplierId || 0
            },
            success: function (response) {
                try {
                    var res = Ext.util.JSON.decode(response.responseText);
                    if (!res.success) {
                        payDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal memuat detail invoice.</div>';
                        payDtlDiv.style.display = 'block';
                        return;
                    }
                    renderInvoiceDetailPanel(res.data || [], invoiceNo, invoiceId);   // [CHG] + invoiceId untuk tombol cetak
                } catch (e) {
                    payDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Error parsing data dari server.</div>';
                    payDtlDiv.style.display = 'block';
                }
            },
            failure: function () {
                payDtlDiv.innerHTML = '<div style="color:red; padding:10px;">Gagal terhubung ke server backend.</div>';
                payDtlDiv.style.display = 'block';
            }
        });
    }

    // ------------------------------------------------------------
    // 3b. [BARU] Cetak Tanda Terima Invoice
    //     Jendela cetak dibuka SINKRON saat klik (hindari popup blocker), data diambil via
    //     task 'read_invoice_print', lalu HTML ditulis ke jendela dan window.print() dipanggil.
    // ------------------------------------------------------------
    var PRINT_COMPANY = {           // [BARU] kop dokumen -- sesuaikan (samakan dengan grn.js)
        name: 'NAMA PERUSAHAAN',
        address: 'Alamat perusahaan'
    };

    function enc(v) { return Ext.util.Format.htmlEncode(v == null ? '' : v); }

    // [FIX 2026-10-09] Tanggal diformat manual (dd/mm/yyyy). Sebelumnya memakai Date.format('d/m/Y') milik Ext/app,
    // yang di jendela cetak menghasilkan "6/10/Y" (tahun tidak terformat, hari tanpa nol di depan).
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function fmtPrintDate(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));      // 'YYYY-MM-DD' (atau timestamp PostgreSQL)
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : '-';
    }
    function fmtPrintNow() {
        var d = new Date();
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear() + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes());
    }

    function printInvoice(invoiceId) {
        if (!invoiceId) {
            Ext.Msg.alert('Peringatan', 'Pilih data invoice yang ingin dicetak.');
            return;
        }
        var w = window.open('', '_blank', 'width=900,height=700');
        if (!w) {
            Ext.Msg.alert('Popup Diblokir', 'Izinkan popup untuk situs ini, lalu klik Cetak Tanda Terima lagi.');
            return;
        }
        w.document.write('<html><body style="font-family:Arial,sans-serif;padding:20px;">Memuat Tanda Terima Invoice...</body></html>');

        Ext.Ajax.request({
            url: '../dev/php-crud/purchaseinvoiceCRD.php',
            method: 'POST',
            params: { task: 'read_invoice_print', invoice_id: invoiceId },
            success: function (response) {
                var res;
                try { res = Ext.util.JSON.decode(response.responseText); }
                catch (e) { w.close(); Ext.Msg.alert('Gagal', 'Respons server tidak valid.'); return; }
                if (!res.success) { w.close(); Ext.Msg.alert('Gagal', res.message || 'Gagal memuat data cetak.'); return; }

                w.document.open();
                w.document.write(buildInvoicePrintHtml(res.data.header, res.data.items || []));
                w.document.close();
                w.focus();
                setTimeout(function () { w.print(); }, 400);
            },
            failure: function () {
                w.close();
                Ext.Msg.alert('Gagal', 'Gagal menghubungi server.');
            }
        });
    }

    function buildInvoicePrintHtml(h, items) {
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        // Kode mata uang hanya ditampilkan di total bila seragam di semua baris
        var codes = {}, codeCount = 0, onlyCode = '';
        Ext.each(items, function (it) {
            var c = it.currency_code || '';
            if (c && !codes[c]) { codes[c] = true; codeCount++; onlyCode = c; }
        });

        var sum = 0, rowsHtml = '';
        if (!items.length) {
            rowsHtml = '<tr><td colspan="6" style="text-align:center;padding:14px;">Tidak ada GRN terhubung.</td></tr>';
        }
        Ext.each(items, function (it, i) {
            var amt = parseFloat(it.matched_amount) || 0;
            sum += amt;
            rowsHtml += '<tr>' +
                '<td class="c">' + (i + 1) + '</td>' +
                '<td>' + enc(it.grn_ref_no || '-') + '</td>' +
                '<td class="c">' + fmtPrintDate(it.grn_date) + '</td>' +
                '<td>' + enc(it.po_ref_no || '-') + '</td>' +
                '<td>' + enc(it.item_name || '-') + '</td>' +
                '<td class="r">' + (codeCount > 1 && it.currency_code ? enc(it.currency_code) + ' ' : '') + fmtAmt(amt) + '</td>' +
                '</tr>';
        });

        var st = String(h.status || '').toLowerCase();
        var cancelled = (st === 'cancelled' || st === 'void' || st === 'dibatalkan');
        var totalLabel = 'TOTAL' + (codeCount === 1 ? ' (' + enc(onlyCode) + ')' : '');
        // [PPN] footer cetak: DPP / PPN / total bila invoice memuat PPN
        var ppnAmtPrn = parseFloat(h.ppn_amount) || 0, tfootHtml;
        if (ppnAmtPrn > 0) {
            var ccPrn = codeCount === 1 ? ' (' + enc(onlyCode) + ')' : '';
            tfootHtml = '<tr><td colspan="5" class="r">DPP' + ccPrn + '</td><td class="r">' + fmtAmt(parseFloat(h.dpp_amount) || sum) + '</td></tr>' +
                        '<tr><td colspan="5" class="r">PPN ' + (parseFloat(h.ppn_rate) || 0) + '%' + ccPrn + '</td><td class="r">' + fmtAmt(ppnAmtPrn) + '</td></tr>' +
                        '<tr><td colspan="5" class="r">' + totalLabel + ' (DPP + PPN)</td><td class="r">' + fmtAmt(parseFloat(h.total_amount) || (sum + ppnAmtPrn)) + '</td></tr>';
        } else {
            tfootHtml = '<tr><td colspan="5" class="r">' + totalLabel + '</td><td class="r">' + fmtAmt(sum) + '</td></tr>';
        }
        var printedAt = fmtPrintNow();       // [FIX 2026-10-09]

        return '<!DOCTYPE html><html><head><meta charset="utf-8"/><title>Tanda Terima Invoice ' + enc(h.inv_no) + '</title>' +
            '<style>' +
            '@page { size: A4; margin: 12mm; }' +
            'body { font-family: Arial, Helvetica, sans-serif; font-size: 12px; color: #000; margin: 0; }' +
            '.kop { border-bottom: 2px solid #000; padding-bottom: 6px; margin-bottom: 10px; }' +
            '.kop .nm { font-size: 17px; font-weight: bold; }' +
            'h2 { text-align: center; margin: 8px 0 12px 0; font-size: 16px; letter-spacing: 1px; text-decoration: underline; }' +
            'table.info { width: 100%; border-collapse: collapse; margin-bottom: 10px; }' +
            'table.info td { padding: 2px 4px; vertical-align: top; }' +
            'table.info td.lbl { width: 130px; } table.info td.sep { width: 10px; }' +
            // [FIX 2026-10-09] Garis tabel: tiap sel bergaris kanan+bawah, tabel bergaris atas+kiri (border-collapse: separate),
            // table-layout fixed -> kolom paling kanan (Sisa PO / Nominal) tidak lagi terpotong / tanpa garis.
            // [FIX 2026-10-09 b] width: calc(100% - 2px) + border-box: garis kanan tabel sebelumnya tepat di tepi area cetak -> terpotong di PDF.
            'table.items { box-sizing: border-box; width: calc(100% - 2px); table-layout: fixed; border-collapse: separate; border-spacing: 0; border-top: 1px solid #000; border-left: 1px solid #000; }' +
            'table.items th, table.items td { border-right: 1px solid #000; border-bottom: 1px solid #000; padding: 4px 6px; word-wrap: break-word; overflow-wrap: break-word; }' +
            'table.items th { background: #eee; text-align: center; }' +
            'table.items tfoot td { font-weight: bold; background: #f5f5f5; }' +
            '.c { text-align: center; } .r { text-align: right; }' +
            '.note { margin-top: 8px; }' +
            'table.sign { width: 100%; margin-top: 28px; border-collapse: collapse; page-break-inside: avoid; }' +
            'table.sign td { width: 50%; text-align: center; vertical-align: top; }' +
            '.sign .box { height: 70px; }' +
            '.sign .line { display: inline-block; min-width: 170px; border-top: 1px solid #000; padding-top: 3px; }' +
            '.foot { margin-top: 18px; font-size: 10px; color: #444; }' +
            '.wm { text-align:center; color:#c0392b; font-size:20px; font-weight:bold; border:2px solid #c0392b; padding:4px; margin-bottom:8px; }' +
            '</style></head><body>' +
            '<div class="kop"><div class="nm">' + enc(PRINT_COMPANY.name) + '</div><div>' + enc(PRINT_COMPANY.address) + '</div></div>' +
            '<h2>TANDA TERIMA INVOICE</h2>' +
            (cancelled ? '<div class="wm">DIBATALKAN</div>' : '') +
            '<table class="info"><tr>' +
            '<td class="lbl">No. Tanda Terima</td><td class="sep">:</td><td><b>' + enc(h.inv_no) + '</b></td>' +
            '<td class="lbl">No. Invoice Supplier</td><td class="sep">:</td><td><b>' + enc(h.supplier_invoice_no || '-') + '</b></td>' +
            '</tr><tr>' +
            '<td class="lbl">Tanggal Diterima</td><td class="sep">:</td><td>' + fmtPrintDate(h.created_at) + '</td>' +
            '<td class="lbl">Tanggal Invoice</td><td class="sep">:</td><td>' + fmtPrintDate(h.invoice_date) + '</td>' +
            '</tr><tr>' +
            '<td class="lbl">Supplier</td><td class="sep">:</td><td colspan="4"><b>' + enc(h.supplier_name || '-') + '</b></td>' +
            (h.faktur_pajak_no ? '</tr><tr><td class="lbl">No. Faktur Pajak</td><td class="sep">:</td><td colspan="4"><b>' + enc(h.faktur_pajak_no) + '</b>' +
                (h.faktur_pajak_date ? ' (' + fmtPrintDate(h.faktur_pajak_date) + ')' : '') + '</td>' : '') +      // [PPN]
            '</tr></table>' +
            '<table class="items"><thead><tr>' +
            '<th style="width:30px;">No</th><th style="width:110px;">No. GRN</th><th style="width:75px;">Tgl GRN</th>' +
            '<th style="width:110px;">No. PO</th><th>Item</th><th style="width:120px;">Nominal Invoice</th>' +
            '</tr></thead><tbody>' + rowsHtml + '</tbody>' +
            '<tfoot>' + tfootHtml + '</tfoot></table>' +
            (codeCount > 1 ? '<div class="note"><i>Catatan: GRN terhubung memakai mata uang berbeda; total hanya penjumlahan nominal.</i></div>' : '') +
            (h.remark ? '<div class="note"><b>Keterangan:</b> ' + enc(h.remark) + '</div>' : '') +
            '<table class="sign"><tr>' +
            '<td>Yang Menyerahkan (Supplier / Kurir)<div class="box"></div><span class="line">( .............................. )</span></td>' +
            '<td>Yang Menerima (Finance / AP)<div class="box"></div><span class="line">( ' + enc(h.created_by_name || '..............................') + ' )</span></td>' +
            '</tr></table>' +
            '<div class="foot">Dicetak: ' + printedAt + '</div>' +
            '</body></html>';
    }

    function renderInvoiceDetailPanel(rows, invoiceNo, invoiceId) {
        var fmtAmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var fmtQty = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        var html = '<h3 class="detail-title" style="margin:10px 0 5px 0; font-size:14px; color:#38bdf8;">Detail Match GRN / Item Invoice: ' +
                   Ext.util.Format.htmlEncode(invoiceNo || '') +
                   // [BARU] tombol cetak tanda terima
                   '<button type="button" id="btnPrintInvoice" style="float:right; padding:5px 14px; background:#2e6da4; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold; font-size:12px;">&#128424; Cetak Tanda Terima</button>' +
                   '</h3>' +
                   '<hr class="detail-hr" style="border:0; border-top:1px solid #334155; margin-bottom:10px;" />' +
                   '<table id="payTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#f8fafc; font-size:12px;">' +
                   '<thead>' +
                   '<tr class="header-row" style="background-color:#1e293b; color:#94a3b8;">' +
                   '<th align="left">No. Ref GRN</th>' +
                   '<th align="left">Item / Ref</th>' +
                   '<th align="right">Qty</th>' +
                   '<th align="right">Harga Satuan</th>' +
                   '<th align="right">Total Subtotal</th>' +
                   '<th align="right">Nominal Matched</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">Belum ada rincian GRN/Item terhubung untuk invoice ini.</td></tr>';
        } else {
            var grandTotal = 0;
            Ext.each(rows, function (r) {
                var matched = parseFloat(r.matched_amount_this_invoice || r.matched_amount || r.subtotal || r.amount || 0);
                var grnTotal = parseFloat(r.grn_total || r.subtotal || 0);
                grandTotal += matched;

                html += '<tr class="data-row" style="border-bottom:1px solid #334155;">' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.grn_ref_no || r.ref_no || '-') + '</td>' +
                        '<td align="left">' + Ext.util.Format.htmlEncode(r.item_name || r.remark || '-') + '</td>' +
                        '<td align="right">' + fmtQty(r.qty_received) + '</td>' +
                        '<td align="right">' + fmtAmt(r.unit_cost) + '</td>' +
                        '<td align="right">' + fmtAmt(grnTotal) + '</td>' +
                        '<td align="right" style="color:#38bdf8; font-weight:bold;">' + fmtAmt(matched) + '</td>' +
                        '</tr>';
            });

            // [PPN] bila invoice memuat PPN: tampilkan DPP, PPN, dan total hutang (DPP + PPN)
            var hd = (dataRecord && String(dataRecord.id) === String(invoiceId)) ? dataRecord : null;
            var hdPpn = hd ? (parseFloat(hd.ppn_amount) || 0) : 0;
            if (hdPpn > 0) {
                html += '<tr class="footer-row" style="background-color:#0f172a;"><td colspan="5" align="right" style="padding:6px;">DPP:</td><td align="right">' + fmtAmt(grandTotal) + '</td></tr>' +
                        '<tr class="footer-row" style="background-color:#0f172a;"><td colspan="5" align="right" style="padding:6px;">PPN Masukan ' + (parseFloat(hd.ppn_rate) || 0) + '%' +
                        (hd.faktur_pajak_no ? ' (Faktur ' + Ext.util.Format.htmlEncode(hd.faktur_pajak_no) + ')' : '') + ':</td><td align="right">' + fmtAmt(hdPpn) + '</td></tr>' +
                        '<tr class="footer-row" style="background-color:#0f172a; font-weight:bold;"><td colspan="5" align="right" style="padding:8px;">TOTAL INVOICE (DPP + PPN):</td>' +
                        '<td align="right" style="color:#22c55e;">' + fmtAmt(parseFloat(hd.total_amount) || (grandTotal + hdPpn)) + '</td></tr>';
            } else {
            html += '<tr class="footer-row" style="background-color:#0f172a; font-weight:bold;">' +
                    '<td colspan="5" align="right" style="padding:8px;">TOTAL INVOICE:</td>' +
                    '<td align="right" style="color:#22c55e;">' + fmtAmt(grandTotal) + '</td>' +
                    '</tr>';
            }
        }
        html += '</tbody></table>';

        payDtlDiv.innerHTML = html;
        payDtlDiv.style.display = 'block';

        // [BARU] handler tombol cetak
        var btnPrint = document.getElementById('btnPrintInvoice');
        if (btnPrint) btnPrint.onclick = function () { printInvoice(invoiceId); };
    }

    gridMain.on('rowclick', function (grid, rowIndex) {
        var rec = grid.getStore().getAt(rowIndex);
        if (rec) {
            dataRecord = rec.data;
            if (typeof mainBtnEnable === 'function') mainBtnEnable('#editData');
            if (typeof mainBtnEnable === 'function') mainBtnEnable('#deleteData');
            
            loadInvoiceDetailPanel(dataRecord.id, dataRecord.inv_no, dataRecord.supplier_id);
        }
    });

    // ------------------------------------------------------------
    // 4. Form Editor & Grid Editor Detail Setup
    // ------------------------------------------------------------
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });
    var fldInvNo = new myTextField({ fieldLabel: 'No. Ref Dokumen', id: 'inv_no', name: 'inv_no', allowBlank: true, readOnly: true, disabled: true, emptyText: 'Otomatis oleh sistem', width: 200 });
    var fldSuppInvNo = new myTextField({ fieldLabel: 'No. Supp. Invoice', id: 'supplier_invoice_no', name: 'supplier_invoice_no', allowBlank: false, width: 200 });
    var fldInvDate = new Ext.form.DateField({ fieldLabel: 'Tanggal Invoice', id: 'invoice_date', name: 'invoice_date', format: 'Y-m-d', width: 140, allowBlank: false });
    var fldSupplier = new myComboField({ fieldLabel: 'Supplier', id: 'supplier_id', name: 'supplier_id', hiddenName: 'supplier_id', store: supplierDs, displayField: 'name', valueField: 'id', mode: 'remote', triggerAction: 'all', width: 200, allowBlank: false });
    var fldRemark = new Ext.form.TextArea({ fieldLabel: 'Keterangan', id: 'remark', name: 'remark', anchor: '98%', height: 45 });

    // [PPN] PPN Masukan: tarif (0 = tanpa PPN), nilai PPN sesuai faktur (kosong = otomatis), no./tgl faktur pajak supplier
    var PPN_DEFAULT_RATE = 11;           // tarif default (%); samakan dengan kebijakan pajak berlaku
    var ppnAmtManual = false;            // true = nilai PPN diketik user / dimuat dari invoice tersimpan -> jangan ditimpa hitungan otomatis
    // [CHANGED] pilihan 'Kena PPN' Ya / Tidak (default Ya): Ya -> tarif default 11%; Tidak -> tarif 0 (tanpa PPN).
    // Memakai combo (bukan checkbox) karena checkbox bawaan tak terlihat di tema gelap dan merusak tinggi baris form.
    // Tanpa name / hiddenName agar tidak ikut terkirim; yang dikirim tetap ppn_rate.
    var ppnTaxSyncing = false;
    var fldPpnTax = new myComboField({
        fieldLabel: 'Kena PPN', store: new Ext.data.ArrayStore({ fields: ['v', 't'], data: [['1', 'Ya'], ['0', 'Tidak']] }),
        displayField: 't', valueField: 'v', mode: 'local', triggerAction: 'all', forceSelection: true, editable: false,
        value: '1', width: 90,
        listeners: {
            select: function () {
                if (ppnTaxSyncing) return;
                ppnAmtManual = false;
                fldPpnRate.setValue(fldPpnTax.getValue() === '1' ? PPN_DEFAULT_RATE : 0);
                recalcInvoicePpn();
            }
        }
    });
    function syncPpnTax() {                                  // [CHANGED] pilihan Ya/Tidak mengikuti tarif
        ppnTaxSyncing = true;
        fldPpnTax.setValue((parseFloat(fldPpnRate.getValue()) || 0) > 0 ? '1' : '0');
        ppnTaxSyncing = false;
    }
    var fldPpnRate = new Ext.form.NumberField({
        fieldLabel: 'Tarif PPN (%)', id: 'ppn_rate', name: 'ppn_rate', decimalPrecision: 2, value: PPN_DEFAULT_RATE, width: 90,   // [CHANGED] default 11
        listeners: { change: function () { ppnAmtManual = false; syncPpnTax(); recalcInvoicePpn(); } }
    });
    var fldPpnAmt = new Ext.form.NumberField({
        fieldLabel: 'PPN (sesuai faktur)', id: 'ppn_amount', name: 'ppn_amount', decimalPrecision: 2, width: 140, allowNegative: false,
        listeners: { change: function (f, v) { ppnAmtManual = (v !== '' && v !== null && v !== undefined); recalcInvoicePpn(); } }
    });
    var fldFpNo = new myTextField({ fieldLabel: 'No. Faktur Pajak', id: 'faktur_pajak_no', name: 'faktur_pajak_no', allowBlank: true, width: 200 });
    var fldFpDate = new Ext.form.DateField({ fieldLabel: 'Tgl Faktur Pajak', id: 'faktur_pajak_date', name: 'faktur_pajak_date', format: 'Y-m-d', width: 140, allowBlank: true });
    var fldPpnInfo = new Ext.form.DisplayField({ hideLabel: true, value: '', anchor: '98%' });

    // Hitung ulang DPP / PPN / total dari grid matched (dipanggil saat grid, tarif, atau PPN berubah)
    function recalcInvoicePpn() {
        if (typeof gridEditorInvoiceDs === 'undefined') return;
        var fmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };
        var dpp = 0;
        gridEditorInvoiceDs.each(function (r) { dpp += parseFloat(r.get('matched_amount')) || 0; });
        dpp = Math.round(dpp * 100) / 100;
        var rate = parseFloat(fldPpnRate.getValue()) || 0;
        var calc = rate > 0 ? Math.round(dpp * rate) / 100 : 0;
        var ppn = calc, warn = '';
        if (rate > 0 && ppnAmtManual) {
            ppn = parseFloat(fldPpnAmt.getValue()) || 0;
            if (Math.abs(ppn - calc) > 100) warn = ' <span style="color:#e6a817;">(beda dari hitungan ' + fmt(calc) + ' - server menolak selisih > Rp 100)</span>';
        } else if (rate > 0) {
            fldPpnAmt.setValue(calc);
        } else {
            fldPpnAmt.setValue('');
        }
        fldPpnInfo.setValue('<span style="color:#9fc3d1;">DPP <b>' + fmt(dpp) + '</b> &nbsp;|&nbsp; PPN <b>' + fmt(ppn) + '</b> &nbsp;|&nbsp; Total hutang <b>' + fmt(dpp + ppn) + '</b>' + warn + '</span>');
    }

    // [BARU] Begitu supplier dipilih, otomatis tarik semua GRN supplier itu
    // yang belum pernah di-invoice-kan, langsung jadi baris di grid detail.
    // Default nominal matched = qty_received x unit_cost, sama seperti Add Item manual.
    function autoLoadAvailableGrn(supplierId) {
        if (!supplierId) return;
        Ext.Ajax.request({
            url: '../dev/php-crud/purchaseinvoiceCRD.php',
            method: 'POST',
            params: { task: 'read_available_grn', supplier_id: supplierId },
            success: function (response) {
                var res;
                try {
                    res = Ext.util.JSON.decode(response.responseText);
                } catch (e) {
                    return;
                }
                if (!res.success) return;

                Ext.each(res.data || [], function (g) {
                    var already = false;
                    gridEditorInvoiceDs.each(function (r) {
                        if (r.get('grn_hdr_id') === g.grn_hdr_id) already = true;
                    });
                    if (already) return;

                    var qty = parseFloat(g.qty_received || 0);
                    var price = parseFloat(g.unit_cost || 0);
                    var remaining = parseFloat(g.remaining || 0);

                    var NewItem = gridEditorInvoiceDs.recordType;
                    var newRecord = new NewItem({
                        id: 0,
                        invoice_hdr_id: 0,
                        grn_hdr_id: g.grn_hdr_id,
                        grn_ref_no: g.grn_ref_no,
                        item_name: g.item_name,
                        qty_received: qty,
                        unit_cost: price,
                        grn_total: g.grn_total,
                        matched_amount: remaining
                    });
                    gridEditorInvoiceDs.add(newRecord);
                });
            },
            failure: function () {
                Ext.Msg.alert('Error', 'Gagal memuat daftar GRN otomatis untuk supplier ini.');
            }
        });
    }

    fldSupplier.on('select', function (combo, record) {
        autoLoadAvailableGrn(record.get('id'));
        // [PPN][CHANGED] ikut checkbox 'Kena PPN' (default tercentang -> 11%), tidak lagi dibedakan PKP / non-PKP
        ppnAmtManual = false;
        fldPpnRate.setValue(fldPpnTax.getValue() === '1' ? PPN_DEFAULT_RATE : 0);
        recalcInvoicePpn();
    });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 120,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: [fldId, fldInvNo, fldSuppInvNo, fldInvDate, fldFpNo, fldFpDate] },          // [PPN] + faktur pajak
            { items: [fldSupplier, fldRemark, fldPpnTax, fldPpnRate, fldPpnAmt, fldPpnInfo] }   // [CHANGED] + checkbox Kena PPN               // [PPN] + tarif, PPN, ringkasan
        ]
    });

    var invoiceItemFields = [
        { name: 'id', type: 'int' },
        { name: 'invoice_hdr_id', type: 'int' },
        { name: 'grn_hdr_id', type: 'int' },
        { name: 'grn_ref_no', type: 'string' },
        { name: 'item_name', type: 'string' },
        { name: 'qty_received', type: 'float' },
        { name: 'unit_cost', type: 'float' },
        { name: 'grn_total', type: 'float' },
        { name: 'matched_amount', type: 'float' }
    ];

    var gridEditorInvoiceDs = new Ext.data.Store({
        url: '../dev/php-crud/purchaseinvoiceCRD.php',
        baseParams: { task: 'read_invoice_detail' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: invoiceItemFields })
    });

    // [PPN] hitung ulang ringkasan PPN setiap isi grid detail berubah
    gridEditorInvoiceDs.on('load', recalcInvoicePpn);
    gridEditorInvoiceDs.on('add', recalcInvoicePpn);
    gridEditorInvoiceDs.on('remove', recalcInvoicePpn);
    gridEditorInvoiceDs.on('update', recalcInvoicePpn);

    var editorDeleter = new Extensive.grid.ItemDeleter({ header: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:middle;"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>' });   // [CHANGED] ikon trash di header kolom hapus (seperti Pembayaran Termin)

    // Store daftar GRN yang bisa dipilih (untuk window picker "Add Item")
    var availableGrnDs = new Ext.data.Store({
        url: '../dev/php-crud/purchaseinvoiceCRD.php',
        baseParams: { task: 'read_available_grn' },
        reader: new Ext.data.JsonReader({ root: 'data' }, [
            { name: 'grn_hdr_id', type: 'int' },
            { name: 'grn_ref_no', type: 'string' },
            { name: 'grn_date', type: 'date', dateFormat: 'Y-m-d' },
            { name: 'item_name', type: 'string' },
            { name: 'qty_received', type: 'float' },
            { name: 'unit_cost', type: 'float' },
            { name: 'grn_total', type: 'float' },
            { name: 'already_matched', type: 'float' },
            { name: 'remaining', type: 'float' }
        ])
    });

    var gridGrnPicker = new Ext.grid.GridPanel({
        store: availableGrnDs,
        height: 320,
        width: 640,
        stripeRows: true,
        loadMask: true,
        viewConfig: {
            forceFit: true,
            emptyText: '<div style="padding:20px; text-align:center; color:#94a3b8;">Tidak ada GRN yang tersedia untuk supplier ini.</div>'
        },
        columns: [
            { header: 'No. Ref GRN', dataIndex: 'grn_ref_no', width: 100 },
            { header: 'Tgl GRN', dataIndex: 'grn_date', width: 85, renderer: Ext.util.Format.dateRenderer('Y-m-d') },
            { header: 'Item', dataIndex: 'item_name', width: 150 },
            { header: 'Total GRN', dataIndex: 'grn_total', width: 90, align: 'right', renderer: format_number },
            { header: 'Sudah Di-invoice', dataIndex: 'already_matched', width: 100, align: 'right', renderer: format_number },
            { header: 'Sisa Belum Invoice', dataIndex: 'remaining', width: 110, align: 'right', renderer: format_number, css: 'font-weight:bold;' }
        ]
    });

    var wdwGrnPicker = new Ext.Window({
        title: 'Pilih GRN untuk Di-match',
        width: 660,
        height: 400,
        modal: true,
        closeAction: 'hide',
        layout: 'fit',
        items: [gridGrnPicker],
        buttons: [{
            text: 'Pilih',
            handler: function () {
                var rec = gridGrnPicker.getSelectionModel().getSelected();
                if (!rec) {
                    Ext.Msg.alert('Peringatan', 'Silakan pilih salah satu GRN.');
                    return;
                }

                // Cegah GRN yang sama ditambahkan dua kali ke invoice yang sama
                var already = false;
                gridEditorInvoiceDs.each(function (r) {
                    if (r.get('grn_hdr_id') === rec.get('grn_hdr_id')) already = true;
                });
                if (already) {
                    Ext.Msg.alert('Peringatan', 'GRN ini sudah ditambahkan ke invoice.');
                    return;
                }

                var qty = parseFloat(rec.get('qty_received') || 0);
                var price = parseFloat(rec.get('unit_cost') || 0);
                var remaining = parseFloat(rec.get('remaining') || 0);

                var NewItem = gridEditorInvoiceDs.recordType;
                var newRecord = new NewItem({
                    id: 0,
                    invoice_hdr_id: 0,
                    grn_hdr_id: rec.get('grn_hdr_id'),
                    grn_ref_no: rec.get('grn_ref_no'),
                    item_name: rec.get('item_name'),
                    qty_received: qty,
                    unit_cost: price,
                    grn_total: rec.get('grn_total'),
                    // [FIX] Nominal matched default = sisa yang belum di-invoice
                    // (untuk GRN baru = qty x harga = sama dengan grn_total)
                    matched_amount: remaining
                });
                gridEditorInvoiceDs.add(newRecord);

                wdwGrnPicker.hide();
            }
        }, {
            text: 'Batal',
            handler: function () { wdwGrnPicker.hide(); }
        }]
    });

    var gridEditorInvoice = new stdGridEditor({
        id: 'gridEditorInvoice',
        renderTo: 'editorFormGrid',
        store: gridEditorInvoiceDs,
        selModel: editorDeleter,
        columns: [
            { header: 'No. Ref GRN', dataIndex: 'grn_ref_no', width: 130, align: 'left' },
            { header: 'Item / Material', dataIndex: 'item_name', width: 200, align: 'left' },
            { header: 'Qty', dataIndex: 'qty_received', width: 80, align: 'right', renderer: format_number },
            { header: 'Harga Satuan', dataIndex: 'unit_cost', width: 100, align: 'right', renderer: format_number },
            { 
                header: 'Nominal Matched', 
                dataIndex: 'matched_amount', 
                width: 120, 
                align: 'right', 
                renderer: format_number,
                editor: new Ext.form.NumberField(),
                css: cssGridEditColumn 
            },
            editorDeleter,
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'invoice_hdr_id', hidden: true },
            { dataIndex: 'grn_hdr_id', hidden: true }
        ],
        tbar: [
            '<div id="gridHeaderText">Rincian Item / GRN</div>',
            { xtype: 'tbfill' },
            { 
                text: '&nbsp;&nbsp;&nbsp;Add Item&nbsp;&nbsp;&nbsp;', 
                ctCls: 'field-button green-field-button', 
                id: 'btnAddInvoiceItem', 
                handler: function() {
                    var supplierId = fldSupplier.getValue();
                    if (!supplierId) {
                        Ext.Msg.alert('Peringatan', 'Pilih supplier terlebih dahulu sebelum menambah item.');
                        return;
                    }
                    availableGrnDs.load({ params: { supplier_id: supplierId } });
                    wdwGrnPicker.show();
                } 
            }
        ],
        listeners: {
            // [PERBAIKAN] Otomatis set default nominal matched = qty_received * unit_cost jika kosong/0
            render: function(grid) {
                grid.getStore().on('load', function(store) {
                    store.each(function(record) {
                        var currentMatched = record.get('matched_amount');
                        if (!currentMatched || currentMatched === 0) {
                            var qty = parseFloat(record.get('qty_received') || 0);
                            var price = parseFloat(record.get('unit_cost') || 0);
                            record.set('matched_amount', qty * price);
                        }
                    });
                });
            }
        }
    });
    gridEditorInvoice.addClass('x-grid3-gridEditor');

    function setInvoiceGridEditorHeight() {
        var editorFormHeight = $('#editorForm').height() || 400;
        var headerHeight = $('#editorFormHeader').height() || 50;
        var mainFormHeight = $('#editorFormMain').height() || 150;
        var computedHeight = editorFormHeight - headerHeight - mainFormHeight - 20;
        
        if (computedHeight > 150) {
            gridEditorInvoice.setHeight(computedHeight);
        }
    }

    function showWindow(titleText) {
        var wdw = document.getElementById('editorWdw');
        if (wdw) wdw.style.display = 'block';
        if (document.getElementById('editorFormHeaderText')) {
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + titleText;
        }
        setInvoiceGridEditorHeight();
        // [FIX] Typo lama: id komponennya 'frmEditor', bukan 'frmGridEditor'
        // (yang tidak pernah ada) -> Ext.getCmp() balik undefined -> crash di .doLayout()
        var frmCmp = Ext.getCmp('frmEditor');
        if (frmCmp) frmCmp.doLayout();
    }

    function hideWindow() {
        var wdw = document.getElementById('editorWdw');
        if (wdw) wdw.style.display = 'none';
    }

    // ------------------------------------------------------------
    // 5. Action Handlers (Add, Edit, Delete, Save, Discard)
    // ------------------------------------------------------------
    if (Ext.get('addData')) {
        Ext.get('addData').on('click', function (e) {
            if (e) e.stopEvent();
            task = 'create';
            dataRecord = null;
            frmEditor.getForm().reset();
            supplierDs.load();
            gridEditorInvoiceDs.load({ params: { invoice_id: 0 } });
            ppnAmtManual = false;                                   // [PPN]
            fldPpnRate.setValue(PPN_DEFAULT_RATE);                  // [CHANGED] invoice baru: default kena PPN (pastikan tarif kembali ke default setelah reset())
            syncPpnTax();
            recalcInvoicePpn();
            showWindow('Tambah Purchase Invoice');
        });
    }

    if (Ext.get('editData')) {
        Ext.get('editData').on('click', function (e) {
            if (e) e.stopEvent();
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Silakan pilih data invoice terlebih dahulu.');
                return;
            }
            task = 'update';
            supplierDs.load({
                callback: function() {
                    frmEditor.getForm().reset();
                    frmEditor.getForm().setValues(dataRecord);
                    gridEditorInvoiceDs.load({ params: { invoice_id: dataRecord.id } });
                    ppnAmtManual = (parseFloat(dataRecord.ppn_amount) || 0) > 0;     // [PPN] PPN tersimpan (mis. sesuai faktur) tidak ditimpa
                    syncPpnTax();                                                    // [CHANGED] pilihan Kena PPN mengikuti tarif tersimpan (0 = Tidak)
                    showWindow('Edit Purchase Invoice');
                }
            });
        });
    }

    if (Ext.get('deleteData')) {
        Ext.get('deleteData').on('click', function (e) {
            if (e) e.stopEvent();
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Silakan pilih data invoice terlebih dahulu.');
                return;
            }

            Ext.Msg.confirm('Konfirmasi', 'Apakah Anda yakin ingin menghapus invoice ' + dataRecord.inv_no + '?', function (btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: '../dev/php-crud/purchaseinvoiceCRD.php',
                        method: 'POST',
                        params: { task: 'delete', id: dataRecord.id },
                        success: function (response) {
                            var res = Ext.util.JSON.decode(response.responseText);
                            if (res.success) {
                                Ext.Msg.alert('Sukses', 'Data invoice berhasil dihapus.');
                                mainDs.reload();
                                payDtlDiv.style.display = 'none';
                                dataRecord = null;
                                if (typeof mainBtnDisable === 'function') {
                                    mainBtnDisable('#editData');
                                    mainBtnDisable('#deleteData');
                                }
                            } else {
                                Ext.Msg.alert('Gagal', res.message || 'Gagal menghapus data.');
                            }
                        },
                        failure: function () {
                            Ext.Msg.alert('Error', 'Gagal terhubung ke server.');
                        }
                    });
                }
            });
        });
    }

    if (Ext.get('saveEdit')) {
        Ext.get('saveEdit').on('click', function (e) {
            if (e) e.stopEvent();
            if (!frmEditor.getForm().isValid()) {
                Ext.Msg.alert('Peringatan', 'Mohon lengkapi semua field yang wajib diisi.');
                return;
            }

            // [PERBAIKAN] Menghentikan mode edit aktif pada grid agar nilai sel terakhir ikut tersimpan di store
            if (gridEditorInvoice.stopEditing) {
                gridEditorInvoice.stopEditing();
            }

            // [PERBAIKAN] Menampung seluruh data row grid editor ke dalam array objek JavaScript
            var detailRecords = [];
            gridEditorInvoiceDs.each(function(record) {
                detailRecords.push(record.data);
            });

            // [PERBAIKAN] Kirim data form beserta detail grid yang sudah di-encode ke format JSON
            frmEditor.getForm().submit({
                url: '../dev/php-crud/purchaseinvoiceCRD.php',
                params: { 
                    task: task,
                    invoice_details: Ext.encode(detailRecords) 
                },
                waitMsg: 'Menyimpan data...',
                success: function (form, action) {
                    Ext.Msg.alert('Sukses', 'Data invoice berhasil disimpan.');
                    hideWindow();
                    mainDs.reload();
                    payDtlDiv.style.display = 'none';
                },
                failure: function (form, action) {
                    var msg = action.result ? action.result.message : 'Gagal menyimpan data.';
                    Ext.Msg.alert('Gagal', msg);
                }
            });
        });
    }

    var btnDiscard = Ext.get('discardEdit') || Ext.select('#editorWdw .x-tool-close');
    if (btnDiscard) {
        btnDiscard.on('click', function (e) {
            if (e) e.stopEvent();
            hideWindow();
        });
    }

    var wdwInit = document.getElementById('editorWdw');
    if (wdwInit) wdwInit.style.display = 'none';
});