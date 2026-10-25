/*
  Document      : supplier.payment.js
  Description   : Controller Supplier Payment
                  Main Grid + Detail Panel + Add / Edit / Delete (Editor Window)
  Backend       : ../php-crud/supplier.paymentCRD.php

  Hak akses (formAccess) -- sama dengan pengecekan di server:
     >= 2 : Add      >= 3 : Edit      >= 4 : Delete

  Aturan dana (sama dengan server):
     Dialokasikan ke invoice = SUM(Dibayar per invoice)
     Lebih bayar (uang muka) = Total Dibayar (kas/bank) + Saldo Deposit dipakai - Dialokasikan
     Lebih bayar  < 0  -> tidak boleh disimpan (dana kurang)
     Lebih bayar  > 0  -> dicatat sebagai Uang Muka Pembelian (deposit supplier)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Supplier <em>Payment</em>";
    }

    // Gaya toolbar & ringkasan grid alokasi (scoped ke #editorFormGrid, tidak memengaruhi halaman lain).
    // Latar biru bawaan template pada teks toolbar dinetralkan, tombol tidak lagi ikut transparan.
    Ext.util.CSS.createStyleSheet(
        '#editorFormGrid .x-toolbar { background: transparent !important; background-image: none !important; border: none !important; padding: 4px 0 !important; }' +
        '#editorFormGrid .x-toolbar .xtb-text, #editorFormGrid .x-toolbar .x-toolbar-text, #editorFormGrid #gridHeaderText { background: transparent !important; color: #1f2937 !important; padding: 0 !important; border: none !important; }' +
        '#editorFormGrid .alloc-title { font-size: 16px; font-weight: 600; letter-spacing: .2px; color: #1f2937 !important; }' +
        '#editorFormGrid .alloc-stats { display: inline-block; white-space: nowrap; }' +
        '#editorFormGrid .alloc-stat { display: inline-block; vertical-align: middle; padding: 3px 14px; line-height: 1.25; text-align: right; border-left: 1px solid rgba(31,41,55,.18); }' + 
        '.x-form-element textarea.x-form-field {width: 98% !important;box-sizing: border-box;}' +
        '#editorFormGrid .alloc-stat.first { border-left: none; }' +
        '#editorFormGrid .alloc-lbl { display: block; font-size: 14px; text-transform: uppercase; letter-spacing: .6px; color: #6b7280; }' +
        '#editorFormGrid .alloc-val { display: block; font-size: 14px; font-weight: 700; color: #111827; }' +
        '#editorFormGrid .alloc-stat.pill { border: 1px solid transparent; border-radius: 8px; margin-left: 10px; }' +
        '#editorFormGrid .alloc-stat.ok   { background: #ecfdf5; border-color: #a7f3d0; }' +
        '#editorFormGrid .alloc-stat.ok .alloc-val, #editorFormGrid .alloc-stat.ok .alloc-lbl { color: #047857; }' +
        '#editorFormGrid .alloc-stat.bad  { background: #fef2f2; border-color: #fecaca; }' +
        '#editorFormGrid .alloc-stat.bad .alloc-val, #editorFormGrid .alloc-stat.bad .alloc-lbl { color: #b91c1c; }' +
        '#editorFormGrid .alloc-stat.zero { background: rgba(31,41,55,.05); border-color: rgba(31,41,55,.12); }' +
        '#editorFormGrid a.alloc-btn { display: inline-block; padding: 6px 12px; border: 1px solid #0f766e; border-radius: 6px; background: rgba(15,118,110,.06); color: #0f766e !important; font-size: 12px; font-weight: 600; text-decoration: none; cursor: pointer; }' +
        '#editorFormGrid a.alloc-btn:hover { background: #0f766e; color: #ffffff !important; }',
        'payment-grid-editor-style'
    );

    var CRD = '../live/php-crud/supplier.paymentCRD.php';
    var task = '';
    var dataRecord = null;

    // Kondisi editor saat mode edit (untuk menghitung saldo deposit yang benar)
    var origSupplierId = 0;
    var origDeposit = 0;
    var origExcess = 0;
    var depositBalance = 0;   // saldo deposit supplier saat ini (dari server)

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    function num(v) {
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }
    function round2(v) {
        return Math.round((num(v) + 0.0000001) * 100) / 100;
    }
    function fmtAmt(v) {
        return Ext.util.Format.number(num(v), '0,000.00');
    }
    function esc(v) {
        return Ext.util.Format.htmlEncode(v === null || v === undefined ? '' : String(v));
    }

    // Fungsi penanganan exception store aman
    function handleStoreException(proxy, type, action, options, response, arg) {
        var msg = 'Terjadi kesalahan saat memuat data dari server.';
        if (response && response.responseText) {
            try {
                var res = Ext.util.JSON.decode(response.responseText);
                if (res && res.message) msg = res.message;
            } catch (e) {
                msg = 'Format respon server tidak valid.';
            }
        }
        if (window.console) console.error('Store Exception:', type, action, response);
        Ext.Msg.alert('Error Store', msg);
    }

    // ------------------------------------------------------------
    // 1. Data Stores
    // ------------------------------------------------------------
    var paymentField = [
        { name: 'id' },
        { name: 'payment_no' },
        { name: 'payment_date' },
        { name: 'supplier_id' },
        { name: 'supplier_name' },
        { name: 'account_id' },
        { name: 'account_name' },
        { name: 'payment_method' },
        { name: 'total_amount', type: 'float' },
        { name: 'allocated_amount', type: 'float' },
        { name: 'excess_amount', type: 'float' },
        { name: 'deposit_applied', type: 'float' },
        { name: 'reference_no' },
        { name: 'notes' }
    ];

    var mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, paymentField),
        listeners: { exception: handleStoreException }
    });

    var supplierDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_supplier' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'name' }])
    });

    var accountDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_account' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [{ name: 'id' }, { name: 'account_name' }])
    });

    var methodDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_payment_method' },
        reader: new Ext.data.JsonReader({ root: 'data' }, [{ name: 'value' }, { name: 'label' }])
    });

    // Invoice belum lunas (baris grid alokasi). POST supaya supplier_id & payment_id terkirim.
    var invoiceFields = [
        { name: 'invoice_id', type: 'int' },
        { name: 'invoice_no' },
        { name: 'supplier_invoice_no' },
        { name: 'invoice_date' },
        { name: 'total_amount', type: 'float' },
        { name: 'paid_amount', type: 'float' },
        { name: 'unpaid_amount', type: 'float' },
        { name: 'amount_paid', type: 'float' }
    ];
    var gridInvoiceDs = new Ext.data.Store({
        proxy: new Ext.data.HttpProxy({ url: CRD, method: 'POST' }),
        baseParams: { task: 'read_unpaid_invoices' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'invoice_id' }, invoiceFields),
        listeners: { exception: handleStoreException }
    });

    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
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
            emptyText: '<div style="padding:20px; text-align:center; color:#94a3b8;">Tidak ada data pembayaran.</div>'
        },
        columns: [
            { header: 'No. Bayar', dataIndex: 'payment_no', width: 110 },
            { header: 'Tanggal', dataIndex: 'payment_date', width: 90 },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 160 },
            { header: 'Bayar Dari Akun', dataIndex: 'account_name', width: 150 },
            { header: 'Metode', dataIndex: 'payment_method', width: 100 },
            { header: 'No. Ref/Bukti', dataIndex: 'reference_no', width: 110 },
            {
                header: 'Total Dibayar', dataIndex: 'total_amount', width: 120, align: 'right',
                renderer: function (v) { return '<b>' + fmtAmt(v) + '</b>'; }
            },
            {
                header: 'Pakai Deposit', dataIndex: 'deposit_applied', width: 110, align: 'right',
                renderer: function (v) { return fmtAmt(v); }
            },
            {
                header: 'Uang Muka (Lebih Bayar)', dataIndex: 'excess_amount', width: 150, align: 'right',
                renderer: function (v) { return fmtAmt(v); }
            }
        ]
    });

    // ------------------------------------------------------------
    // 3. Detail Panel Bawah (alokasi invoice untuk pembayaran terpilih)
    // ------------------------------------------------------------
    var payDtlDiv = document.getElementById('divDetailGrid');
    if (!payDtlDiv) {
        payDtlDiv = document.createElement('div');
        payDtlDiv.id = 'divDetailGrid';
    }
    payDtlDiv.className = 'detail-panel-container';
    payDtlDiv.style.display = 'none';

    if (elGridDiv && elGridDiv.parentNode) {
        elGridDiv.parentNode.insertBefore(payDtlDiv, elGridDiv.nextSibling);
    }

    function loadPaymentDetailPanel(paymentId, paymentNo) {
        if (!paymentId) return;

        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_payment_detail', payment_id: paymentId },
            success: function (response) {
                try {
                    var res = Ext.util.JSON.decode(response.responseText);
                    if (!res.success) {
                        payDtlDiv.innerHTML = '<div style="color:#ef4444; padding:10px; font-weight:bold;">Gagal memuat detail: ' + esc(res.message || '') + '</div>';
                        payDtlDiv.style.display = 'block';
                        return;
                    }
                    renderPaymentDetailPanel(res.data || [], paymentNo);
                } catch (e) {
                    payDtlDiv.innerHTML = '<div style="color:#ef4444; padding:10px;">Error parsing JSON backend.</div>';
                    payDtlDiv.style.display = 'block';
                }
            },
            failure: function () {
                payDtlDiv.innerHTML = '<div style="color:#ef4444; padding:10px;">Gagal terhubung ke server backend.</div>';
                payDtlDiv.style.display = 'block';
            }
        });
    }

    function renderPaymentDetailPanel(rows, paymentNo) {
        var html = '<h3 class="detail-title" style="margin:10px 0 5px 0; font-size:14px; color:#38bdf8;">Detail Alokasi Invoice: ' +
                   esc(paymentNo || '') + '</h3>' +
                   '<hr class="detail-hr" style="border:0; border-top:1px solid #334155; margin-bottom:10px;" />' +
                   '<table id="payTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#f8fafc; font-size:12px;">' +
                   '<thead>' +
                   '<tr class="header-row" style="background-color:#1e293b; color:#94a3b8;">' +
                   '<th align="left">No. Invoice</th>' +
                   '<th align="left">No. Invoice Supplier</th>' +
                   '<th align="center">Tgl Inv</th>' +
                   '<th align="right">Total Tagihan</th>' +
                   '<th align="right">Sisa Tagihan</th>' +
                   '<th align="right">Nominal Dialokasikan</th>' +
                   '</tr>' +
                   '</thead>' +
                   '<tbody>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">Belum ada alokasi invoice untuk pembayaran ini.</td></tr>';
        } else {
            var grandTotal = 0;
            Ext.each(rows, function (r) {
                var paid = num(r.amount_paid);
                grandTotal += paid;

                html += '<tr class="data-row" style="border-bottom:1px solid #334155;">' +
                        '<td align="left">' + esc(r.invoice_no || '-') + '</td>' +
                        '<td align="left">' + esc(r.supplier_invoice_no || '-') + '</td>' +
                        '<td align="center">' + esc(r.invoice_date || '-') + '</td>' +
                        '<td align="right">' + fmtAmt(r.total_amount) + '</td>' +
                        '<td align="right">' + fmtAmt(r.unpaid_amount) + '</td>' +
                        '<td align="right" style="color:#38bdf8; font-weight:bold;">' + fmtAmt(paid) + '</td>' +
                        '</tr>';
            });

            html += '<tr class="footer-row" style="background-color:#0f172a; font-weight:bold;">' +
                    '<td colspan="5" align="right" style="padding:8px;">TOTAL ALOKASI:</td>' +
                    '<td align="right" style="color:#22c55e;">' + fmtAmt(grandTotal) + '</td>' +
                    '</tr>';
        }
        html += '</tbody></table>';

        payDtlDiv.innerHTML = html;
        payDtlDiv.style.display = 'block';
    }

    function setMainButtons() {
        if (typeof mainBtnEnable !== 'function' || typeof mainBtnDisable !== 'function') return;
        if (dataRecord && getFormAccess() >= 3) mainBtnEnable('#editData'); else mainBtnDisable('#editData');
        if (dataRecord && getFormAccess() >= 4) mainBtnEnable('#deleteData'); else mainBtnDisable('#deleteData');
    }

    function clearSelection() {
        dataRecord = null;
        payDtlDiv.style.display = 'none';
        setMainButtons();
    }

    gridMain.on('rowclick', function (grid, rowIndex) {
        var rec = grid.getStore().getAt(rowIndex);
        if (rec) {
            dataRecord = rec.data;
            setMainButtons();
            loadPaymentDetailPanel(dataRecord.id, dataRecord.payment_no);
        }
    });

    setMainButtons(); // Edit & Delete nonaktif sampai ada baris dipilih

    // ------------------------------------------------------------
    // 4. Form Editor
    // ------------------------------------------------------------
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });
    var fldPayNo = new myTextField({
        fieldLabel: 'No. Pembayaran', id: 'payment_no', name: 'payment_no',
        readOnly: true, disabled: true, emptyText: 'Otomatis oleh sistem', width: 200
    });
    var fldPayDate = new Ext.form.DateField({
        fieldLabel: 'Tanggal Bayar', id: 'payment_date', name: 'payment_date',
        format: 'Y-m-d', width: 140, allowBlank: false
    });
    var fldSupplier = new myComboField({
        fieldLabel: 'Supplier', id: 'supplier_id', name: 'supplier_id', hiddenName: 'supplier_id',
        store: supplierDs, displayField: 'name', valueField: 'id',
        mode: 'remote', triggerAction: 'all', width: 200, allowBlank: false
    });
    var fldMethod = new myComboField({
        fieldLabel: 'Metode', id: 'payment_method', name: 'payment_method', hiddenName: 'payment_method',
        store: methodDs, displayField: 'label', valueField: 'value',
        mode: 'local', triggerAction: 'all', editable: false, width: 200, allowBlank: false
    });
    var fldAccount = new myComboField({
        fieldLabel: 'Bayar Dari Akun', id: 'account_id', name: 'account_id', hiddenName: 'account_id',
        store: accountDs, displayField: 'account_name', valueField: 'id',
        mode: 'local', triggerAction: 'all', width: 200, allowBlank: true
    });
    var fldTotal = new Ext.form.NumberField({
        fieldLabel: 'Total Dibayar (Kas/Bank)', id: 'total_amount', name: 'total_amount',
        width: 140, allowNegative: false, decimalPrecision: 2, value: 0
    });
    var fldDeposit = new Ext.form.NumberField({
        fieldLabel: 'Pakai Saldo Deposit', id: 'deposit_applied', name: 'deposit_applied',
        width: 140, allowNegative: false, decimalPrecision: 2, value: 0
    });
    var fldDepBalance = new Ext.form.DisplayField({
        fieldLabel: 'Saldo Deposit Tersedia', id: 'deposit_balance', value: '0.00'
    });
    var fldRef = new myTextField({ fieldLabel: 'No. Ref/Bukti', id: 'reference_no', name: 'reference_no', width: 200 });
    var fldNotes = new Ext.form.TextArea({ fieldLabel: 'Keterangan', id: 'notes', name: 'notes', width: 200, height: 45 });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 150,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: [fldId, fldPayNo, fldPayDate, fldSupplier, fldMethod, fldAccount] },
            { items: [fldTotal, fldDeposit, fldDepBalance, fldRef, fldNotes] }
        ]
    });

    // ------------------------------------------------------------
    // 5. Grid Alokasi Invoice (editor)
    // ------------------------------------------------------------
    var editorDeleter = (typeof Extensive !== 'undefined' && Extensive.grid && Extensive.grid.ItemDeleter)
        ? new Extensive.grid.ItemDeleter() : null;

    var allocColumns = [
        { header: 'No. Invoice', dataIndex: 'invoice_no', width: 120, align: 'left' },
        { header: 'No. Inv. Supplier', dataIndex: 'supplier_invoice_no', width: 120, align: 'left' },
        { header: 'Tgl Inv', dataIndex: 'invoice_date', width: 85, align: 'center' },
        { header: 'Total Tagihan', dataIndex: 'total_amount', width: 105, align: 'right', renderer: fmtAmt },
        { header: 'Sisa Hutang', dataIndex: 'unpaid_amount', width: 105, align: 'right', renderer: fmtAmt },
        {
            header: 'Dibayar', dataIndex: 'amount_paid', width: 115, align: 'right', renderer: fmtAmt,
            editor: new Ext.form.NumberField({ allowNegative: false, decimalPrecision: 2 }),
            css: (typeof cssGridEditColumn !== 'undefined') ? cssGridEditColumn : ''
        }
    ];
    if (editorDeleter) allocColumns.push(editorDeleter);
    allocColumns.push({ dataIndex: 'invoice_id', hidden: true });

    var gridAlloc = new stdGridEditor({
        id: 'gridEditorAlloc',
        renderTo: 'editorFormGrid',
        store: gridInvoiceDs,
        selModel: editorDeleter || new Ext.grid.RowSelectionModel({ singleSelect: true }),
        columns: allocColumns,
        tbar: [
            '<div id="gridHeaderText" class="alloc-title">Alokasi ke Invoice</div>',
            { xtype: 'tbfill' },
            '<div id="allocSummary" class="alloc-stats"></div>',
            { xtype: 'tbspacer', width: 14 },
            '<a id="btnAutoAlloc" class="alloc-btn" href="javascript:void(0)" title="Bagi dana ke invoice tertua lebih dulu">Alokasi otomatis (FIFO)</a>'
        ]
    });
    var lnkAutoAlloc = Ext.get('btnAutoAlloc');
    if (lnkAutoAlloc) {
        lnkAutoAlloc.on('click', function (e) {
            if (e && e.stopEvent) e.stopEvent();
            if (gridAlloc.stopEditing) gridAlloc.stopEditing();
            autoAllocateCombined();
        });
    }
    gridAlloc.addClass('x-grid3-gridEditor');

    function getAllocatedTotal() {
        var t = 0;
        gridInvoiceDs.each(function (r) { t += num(r.get('amount_paid')); });
        return round2(t);
    }

    // Deposit yang benar-benar bisa dipakai form ini.
    // Saat edit dengan supplier yang sama: saldo sekarang + deposit yang dipakai pembayaran ini
    // - uang muka yang dibuat pembayaran ini (keduanya akan dibalik dulu oleh server).
    function getAvailableDeposit() {
        var avail = depositBalance;
        if (task === 'update' && String(fldSupplier.getValue()) === String(origSupplierId)) {
            avail = depositBalance + origDeposit - origExcess;
        }
        return round2(Math.max(0, avail));
    }

    function refreshDepositDisplay() {
        fldDepBalance.setValue(fmtAmt(getAvailableDeposit()));
    }

    function statHtml(label, value, cls, hint) {
        return '<div class="alloc-stat ' + (cls || '') + '"' + (hint ? ' title="' + esc(hint) + '"' : '') + '>' +
               '<span class="alloc-lbl">' + label + '</span>' +
               '<span class="alloc-val">' + value + '</span></div>';
    }

    function refreshSummary() {
        var cash = num(fldTotal.getValue());
        var dep = num(fldDeposit.getValue());
        var alloc = getAllocatedTotal();
        var diff = round2(cash + dep - alloc);
        var el = Ext.get('allocSummary');
        if (!el) return;

        var third;
        if (diff < -0.01) {
            third = statHtml('Dana kurang', fmtAmt(-diff), 'pill bad', 'Dana belum cukup menutup alokasi invoice');
        } else if (diff > 0.01) {
            third = statHtml('Uang muka', fmtAmt(diff), 'pill ok', 'Lebih bayar: dicatat sebagai Uang Muka Pembelian (deposit supplier)');
        } else {
            third = statHtml('Selisih', 'Pas', 'pill zero', 'Dana sama dengan total alokasi');
        }

        el.update(
            statHtml('Dialokasikan', fmtAmt(alloc), 'first', 'Total nominal yang dibayarkan ke invoice') +
            statHtml('Dana (kas + deposit)', fmtAmt(cash + dep), '', 'Total dibayar (kas/bank) + saldo deposit yang dipakai') +
            third
        );
    }

    // Bagi dana ke invoice tertua dulu (urutan dari server: tanggal invoice, lalu id)
    function autoAllocateFIFO(total) {
        var remaining = round2(total);
        gridInvoiceDs.each(function (rec) {
            var unpaid = num(rec.get('unpaid_amount'));
            var alloc = round2(Math.max(0, Math.min(remaining, unpaid)));
            rec.set('amount_paid', alloc);
            remaining = round2(remaining - alloc);
        });
        refreshSummary();
    }

    function autoAllocateCombined() {
        autoAllocateFIFO(num(fldTotal.getValue()) + num(fldDeposit.getValue()));
    }

    // Batas per baris: 0 <= dibayar <= sisa hutang
    gridAlloc.on('afteredit', function (e) {
        if (e.field !== 'amount_paid') return;
        var unpaid = num(e.record.get('unpaid_amount'));
        var v = num(e.value);
        if (v < 0) {
            e.record.set('amount_paid', 0);
        } else if (v > unpaid + 0.01) {
            e.record.set('amount_paid', unpaid);
            Ext.Msg.alert('Peringatan', 'Nominal dibayar tidak boleh melebihi sisa hutang invoice (' + fmtAmt(unpaid) + ').');
        }
        refreshSummary();
    });

    gridInvoiceDs.on('load', function () {
        // mode tambah: server sudah mengembalikan amount_paid = unpaid_amount (sisa hutang)
        // mode edit  : server sudah mengembalikan amount_paid dari alokasi tersimpan
        refreshSummary();
    });
    gridInvoiceDs.on('remove', refreshSummary);

    function onFundsChanged() {
        var dep = num(fldDeposit.getValue());
        var avail = getAvailableDeposit();
        if (dep > avail + 0.01) {
            Ext.Msg.alert('Peringatan', 'Saldo deposit yang dipakai tidak boleh melebihi saldo tersedia (' + fmtAmt(avail) + ').');
            fldDeposit.setValue(avail);
        }
        if (task !== 'update') autoAllocateCombined(); else refreshSummary();
    }
    fldTotal.on('change', onFundsChanged);
    fldDeposit.on('change', onFundsChanged);

    function loadDepositBalance(supplierId, cb) {
        depositBalance = 0;
        if (!supplierId) {
            refreshDepositDisplay();
            if (cb) cb();
            return;
        }
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_supplier_deposit_balance', supplier_id: supplierId },
            success: function (response) {
                try {
                    var res = Ext.util.JSON.decode(response.responseText);
                    if (res && res.success) depositBalance = num(res.balance);
                } catch (e) { /* saldo dianggap 0 */ }
                refreshDepositDisplay();
                if (cb) cb();
            },
            failure: function () {
                refreshDepositDisplay();
                if (cb) cb();
            }
        });
    }

    function loadInvoicesFor(supplierId) {
        gridInvoiceDs.removeAll();
        refreshSummary();
        if (!supplierId) return;
        gridInvoiceDs.load({
            params: {
                supplier_id: supplierId,
                payment_id: (task === 'update' && dataRecord) ? dataRecord.id : 0
            }
        });
    }

    fldSupplier.on('select', function (combo, record) {
        var sid = record.get('id');
        if (task !== 'update') fldDeposit.setValue(0);
        loadDepositBalance(sid, function () {
            if (num(fldDeposit.getValue()) > getAvailableDeposit() + 0.01) fldDeposit.setValue(getAvailableDeposit());
            loadInvoicesFor(sid);
        });
    });

    // ------------------------------------------------------------
    // 6. Window editor (template)
    // ------------------------------------------------------------
    function setEditorGridHeight() {
        if (typeof $ !== 'function') return;
        var editorFormHeight = $('#editorForm').height() || 400;
        var headerHeight = $('#editorFormHeader').height() || 50;
        var mainFormHeight = $('#editorFormMain').height() || 150;
        var computedHeight = editorFormHeight - headerHeight - mainFormHeight - 20;
        if (computedHeight > 150) {
            gridAlloc.setHeight(computedHeight);
        }
    }

    function showWindow(titleText) {
        var wdw = document.getElementById('editorWdw');
        if (wdw) wdw.style.display = 'block';
        if (document.getElementById('editorFormHeaderText')) {
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;' + titleText;
        }
        setEditorGridHeight();
        var frmCmp = Ext.getCmp('frmEditor');
        if (frmCmp) frmCmp.doLayout();
        refreshSummary();
    }

    function hideWindow() {
        var wdw = document.getElementById('editorWdw');
        if (wdw) wdw.style.display = 'none';
    }

    // Muat semua combo (supplier, akun, metode) sebelum form dibuka / diisi
    function loadCombos(cb) {
        var pending = 3;
        var done = function () {
            pending--;
            if (pending === 0 && cb) cb();
        };
        supplierDs.load({ callback: done });
        accountDs.load({ callback: done });
        methodDs.load({ callback: done });
    }

    function defaultMethod() {
        var idx = methodDs.find('value', 'BANK_TRANSFER');
        if (idx >= 0) return 'BANK_TRANSFER';
        return methodDs.getCount() > 0 ? methodDs.getAt(0).get('value') : 'BANK_TRANSFER';
    }

    // ------------------------------------------------------------
    // 7. Aksi: Add, Edit, Delete, Save, Discard
    // ------------------------------------------------------------
    if (Ext.get('addData')) {
        Ext.get('addData').on('click', function (e) {
            if (e) e.stopEvent();
            if (getFormAccess() < 2) {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak untuk menambah data.');
                return;
            }
            task = 'create';
            origSupplierId = 0; origDeposit = 0; origExcess = 0; depositBalance = 0;

            loadCombos(function () {
                frmEditor.getForm().reset();
                fldPayDate.setValue(new Date());
                fldMethod.setValue(defaultMethod());
                fldTotal.setValue(0);
                fldDeposit.setValue(0);
                gridInvoiceDs.removeAll();
                refreshDepositDisplay();
                showWindow('Tambah Pembayaran Supplier');
            });
        });
    }

    if (Ext.get('editData')) {
        Ext.get('editData').on('click', function (e) {
            if (e) e.stopEvent();
            if (getFormAccess() < 3) {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak untuk mengubah data.');
                return;
            }
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Silakan pilih data pembayaran terlebih dahulu.');
                return;
            }
            task = 'update';

            loadCombos(function () {
                Ext.Ajax.request({
                    url: CRD,
                    method: 'POST',
                    params: { task: 'read_edit', id: dataRecord.id },
                    success: function (response) {
                        var res;
                        try {
                            res = Ext.util.JSON.decode(response.responseText);
                        } catch (ex) {
                            Ext.Msg.alert('Error', 'Format respon server tidak valid.');
                            return;
                        }
                        if (!res.success || !res.data) {
                            Ext.Msg.alert('Gagal', res.message || 'Data pembayaran tidak ditemukan.');
                            return;
                        }
                        var row = res.data;
                        origSupplierId = row.supplier_id;
                        origDeposit = num(row.deposit_applied);
                        origExcess = num(row.excess_amount);

                        frmEditor.getForm().reset();
                        frmEditor.getForm().setValues(row);
                        gridInvoiceDs.removeAll();

                        loadDepositBalance(row.supplier_id, function () {
                            gridInvoiceDs.load({ params: { supplier_id: row.supplier_id, payment_id: row.id } });
                        });
                        showWindow('Edit Pembayaran Supplier: ' + esc(row.payment_no));
                    },
                    failure: function () {
                        Ext.Msg.alert('Error', 'Gagal terhubung ke server.');
                    }
                });
            });
        });
    }

    if (Ext.get('deleteData')) {
        Ext.get('deleteData').on('click', function (e) {
            if (e) e.stopEvent();
            if (getFormAccess() < 4) {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak untuk menghapus data.');
                return;
            }
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Silakan pilih data pembayaran terlebih dahulu.');
                return;
            }

            Ext.Msg.confirm('Konfirmasi',
                'Hapus pembayaran ' + esc(dataRecord.payment_no) + '?<br/>Jurnal pembayaran ikut dibatalkan dan sisa hutang invoice dikembalikan.',
                function (btn) {
                    if (btn !== 'yes') return;
                    Ext.Ajax.request({
                        url: CRD,
                        method: 'POST',
                        params: { task: 'delete', id: dataRecord.id },
                        success: function (response) {
                            var res;
                            try {
                                res = Ext.util.JSON.decode(response.responseText);
                            } catch (ex) {
                                Ext.Msg.alert('Error', 'Format respon server tidak valid.');
                                return;
                            }
                            if (res.success) {
                                Ext.Msg.alert('Sukses', res.message || 'Data pembayaran berhasil dihapus.');
                                mainDs.reload();
                                clearSelection();
                            } else {
                                Ext.Msg.alert('Gagal', res.message || 'Gagal menghapus data.');
                            }
                        },
                        failure: function () {
                            Ext.Msg.alert('Error', 'Gagal terhubung ke server.');
                        }
                    });
                });
        });
    }

    function submitPayment(arrItems) {
        frmEditor.getForm().submit({
            url: CRD,
            params: {
                task: task,
                arrItems: Ext.encode(arrItems)
            },
            waitMsg: 'Menyimpan data...',
            success: function (form, action) {
                var msg = (action && action.result && action.result.message) ? action.result.message : 'Data pembayaran berhasil disimpan.';
                Ext.Msg.alert('Sukses', msg);
                hideWindow();
                mainDs.reload();
                clearSelection();
            },
            failure: function (form, action) {
                var msg = 'Gagal menyimpan data.';
                if (action && action.result && action.result.message) {
                    msg = action.result.message;
                } else if (action && action.failureType === Ext.form.Action.CONNECT_FAILURE) {
                    msg = 'Gagal terhubung ke server (sesi mungkin berakhir).';
                }
                Ext.Msg.alert('Gagal', msg);
            }
        });
    }

    if (Ext.get('saveEdit')) {
        Ext.get('saveEdit').on('click', function (e) {
            if (e) e.stopEvent();

            if (!frmEditor.getForm().isValid()) {
                Ext.Msg.alert('Peringatan', 'Mohon lengkapi semua field yang wajib diisi.');
                return;
            }
            if (gridAlloc.stopEditing) gridAlloc.stopEditing();

            var cash = num(fldTotal.getValue());
            var dep = num(fldDeposit.getValue());
            var alloc = getAllocatedTotal();
            var avail = getAvailableDeposit();

            if (cash <= 0 && dep <= 0) {
                Ext.Msg.alert('Peringatan', 'Total dibayar atau saldo deposit yang dipakai harus lebih dari 0.');
                return;
            }
            if (cash > 0 && !fldAccount.getValue()) {
                Ext.Msg.alert('Peringatan', 'Pilih akun Kas/Bank sumber dana.');
                return;
            }
            if (dep > avail + 0.01) {
                Ext.Msg.alert('Peringatan', 'Saldo deposit yang dipakai (' + fmtAmt(dep) + ') melebihi saldo tersedia (' + fmtAmt(avail) + ').');
                return;
            }
            if (dep > alloc + 0.01) {
                Ext.Msg.alert('Peringatan', 'Saldo deposit yang dipakai tidak boleh melebihi total alokasi ke invoice.');
                return;
            }
            if (cash + dep + 0.01 < alloc) {
                Ext.Msg.alert('Peringatan', 'Total dibayar + saldo deposit (' + fmtAmt(cash + dep) + ') belum cukup menutupi alokasi invoice (' + fmtAmt(alloc) + ').');
                return;
            }

            var arrItems = [];
            var overRow = null;
            gridInvoiceDs.each(function (rec) {
                var amt = round2(rec.get('amount_paid'));
                if (amt <= 0) return;
                if (amt > num(rec.get('unpaid_amount')) + 0.01) overRow = rec;
                arrItems.push({ invoice_id: rec.get('invoice_id'), amount_paid: amt });
            });
            if (overRow) {
                Ext.Msg.alert('Peringatan', 'Nominal dibayar untuk invoice ' + esc(overRow.get('invoice_no')) + ' melebihi sisa hutangnya.');
                return;
            }

            var excess = round2(cash + dep - alloc);
            if (excess > 0.01) {
                Ext.Msg.confirm('Konfirmasi Uang Muka',
                    'Dana melebihi alokasi invoice sebesar <b>' + fmtAmt(excess) + '</b>.<br/>Selisih dicatat sebagai Uang Muka Pembelian (deposit supplier). Lanjutkan?',
                    function (btn) { if (btn === 'yes') submitPayment(arrItems); });
            } else {
                submitPayment(arrItems);
            }
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