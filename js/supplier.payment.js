/* ================================================================================
 * Document      : supplier.payment.js (COMPLETE FINAL VERSION)
 * Description   : Controller Supplier Payment
 *                 - Main Grid + Detail Panel (dengan tombol Cetak Bukti Bayar)
 *                 - Editor Window: Add / Edit / Delete dengan validasi lengkap
 *                 - Deposit supplier tracking & Auto allocation (FIFO)
 *                 - Real-time summary & stats
 * Backend       : ../php-crud/supplier.paymentCRD.php
 * Cetak Single  : ../views/supplier.payment.print.php?id=<id>
 * Cetak List    : ../views/supplier.payment.list.print.php
 * ================================================================================ */

Ext.onReady(function () {
    Ext.QuickTips.init();

    // ============================================================
    // HEADER & INITIALIZATION
    // ============================================================
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Supplier <em>Payment</em>";
    }

    // ============================================================
    // CONSTANTS
    // ============================================================
    var CRD = '../php-crud/supplier.paymentCRD.php';
    var PRINT_URL = '../views/supplier.payment.print.php?id=';
    var LIST_PRINT_URL = '../views/supplier.payment.list.print.php';

    // ============================================================
    // VARIABLES
    // ============================================================
    var task = '';
    var dataRecord = null;
    var origSupplierId = 0;
    var origDeposit = 0;
    var origExcess = 0;
    var depositBalance = 0;

    // ============================================================
    // UTILITY FUNCTIONS
    // ============================================================
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

    // ============================================================
    // 1. DATA STORES
    // ============================================================
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
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'name' }
        ])
    });

    var accountDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_account' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, [
            { name: 'id' }, { name: 'account_name' }
        ])
    });

    var methodDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_payment_method' },
        reader: new Ext.data.JsonReader({ root: 'data' }, [
            { name: 'value' }, { name: 'label' }
        ])
    });

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

    // ============================================================
    // 2. MAIN GRID
    // ============================================================
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
            {
                header: 'No. Bayar',
                dataIndex: 'payment_no',
                width: 110,
                renderer: function (v) {
                    return '<b style="color:#22d3ee;">' + esc(v) + '</b>';
                }
            },
            {
                header: 'Tanggal',
                dataIndex: 'payment_date',
                width: 90,
                renderer: function (v) {
                    return v ? Ext.util.Format.date(v, 'd/m/Y') : '-';
                }
            },
            { header: 'Supplier', dataIndex: 'supplier_name', width: 160 },
            { header: 'Bayar Dari Akun', dataIndex: 'account_name', width: 150 },
            {
                header: 'Metode',
                dataIndex: 'payment_method',
                width: 100,
                renderer: function (v) {
                    return '<span style="background:#1e293b; padding:2px 8px; border-radius:4px; font-size:11px;">' + esc(v || '-') + '</span>';
                }
            },
            { header: 'No. Ref/Bukti', dataIndex: 'reference_no', width: 110 },
            {
                header: 'Total Dibayar',
                dataIndex: 'total_amount',
                width: 120,
                align: 'right',
                renderer: function (v) {
                    return '<b>' + fmtAmt(v) + '</b>';
                }
            },
            {
                header: 'Pakai Deposit',
                dataIndex: 'deposit_applied',
                width: 110,
                align: 'right',
                renderer: function (v) {
                    return num(v) > 0 ? '<span style="color:#f59e0b;">' + fmtAmt(v) + '</span>' : '-';
                }
            },
            {
                header: 'Uang Muka (Lebih Bayar)',
                dataIndex: 'excess_amount',
                width: 150,
                align: 'right',
                renderer: function (v) {
                    return num(v) > 0 ? '<span style="color:#10b981;">' + fmtAmt(v) + '</span>' : '-';
                }
            }
        ]
    });

    // ============================================================
    // 3. DETAIL PANEL (Alokasi Invoice + Tombol Cetak)
    // ============================================================
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

    function renderPaymentDetailPanel(rows, paymentNo, paymentId) {
        var html = '<div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:4px;">';
        html += '<h3 class="detail-title" style="margin:0; font-size:14px; color:#38bdf8;">Detail Alokasi Invoice: ' + esc(paymentNo || '') + '</h3>';
        html += '<div>';
        // Berikan atribut data-payment-id agar mudah ditangkap oleh event handler global
        html += '<button type="button" class="spBtnPrint" data-payment-id="' + paymentId + '" style="margin-left:8px; padding:6px 14px; background:#03615e; color:#fff; border:none; border-radius:3px; cursor:pointer; font-weight:bold; font-size:12px;">Cetak Bukti Bayar</button>';
        html += '</div>';
        html += '</div>';
        html += '<hr class="detail-hr" style="border:0; border-top:1px solid #334155; margin-bottom:10px;" />';
        html += '<table id="payTable" class="detail-table" width="100%" cellSpacing="0" cellPadding="6" style="border-collapse:collapse; color:#f8fafc; font-size:12px;">';
        html += '<thead>';
        html += '<tr class="header-row" style="background-color:#1e293b; color:#94a3b8;">';
        html += '<th align="left">No. Invoice</th>';
        html += '<th align="left">No. Invoice Supplier</th>';
        html += '<th align="center">Tgl Inv</th>';
        html += '<th align="right">Total Tagihan</th>';
        html += '<th align="right">Sisa Tagihan</th>';
        html += '<th align="right">Nominal Dialokasikan</th>';
        html += '</tr>';
        html += '</thead>';
        html += '<tbody>';
        
        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#94a3b8;">Belum ada alokasi invoice untuk pembayaran ini.</td></tr>';
        } else {
            var grandTotal = 0;
            Ext.each(rows, function (r) {
                var paid = num(r.amount_paid);
                grandTotal += paid;
                html += '<tr class="data-row" style="border-bottom:1px solid #334155;">';
                html += '<td align="left">' + esc(r.invoice_no || '-') + '</td>';
                html += '<td align="left">' + esc(r.supplier_invoice_no || '-') + '</td>';
                html += '<td align="center">' + esc(r.invoice_date || '-') + '</td>';
                html += '<td align="right">' + fmtAmt(r.total_amount) + '</td>';
                html += '<td align="right">' + fmtAmt(r.unpaid_amount) + '</td>';
                html += '<td align="right" style="color:#38bdf8; font-weight:bold;">' + fmtAmt(paid) + '</td>';
                html += '</tr>';
            });
            html += '<tr class="footer-row" style="background-color:#0f172a; font-weight:bold;">';
            html += '<td colspan="5" align="right" style="padding:8px;">TOTAL ALOKASI:</td>';
            html += '<td align="right" style="color:#22c55e;">' + fmtAmt(grandTotal) + '</td>';
            html += '</tr>';
        }
        html += '</tbody></table>';
        
        payDtlDiv.innerHTML = html;
        payDtlDiv.style.display = 'block';
    }

    // Event Delegation global pada kontainer detail untuk tombol cetak
    if (payDtlDiv) {
        payDtlDiv.onclick = function (e) {
            var target = e.target;
            if (target && target.classList && target.classList.contains('spBtnPrint')) {
                var pid = target.getAttribute('data-payment-id');
                if (pid) {
                    printReceipt(pid);
                } else {
                    Ext.Msg.alert('Peringatan', 'ID Pembayaran tidak ditemukan.');
                }
            }
        };
    }
   

    // ============================================================
    // PRINTING FUNCTIONS
    // ============================================================
    function printReceipt(id) {
        id = parseInt(id, 10);
        if (!id || id < 1) {
            Ext.Msg.alert('Pilih Pembayaran', 'Pilih satu pembayaran terlebih dahulu.');
            return;
        }
        var w = window.open(PRINT_URL + encodeURIComponent(id), '_blank',
                            'width=1000,height=800,scrollbars=yes,resizable=yes');
        if (!w) {
            Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Cetak kembali.');
        }
    }

    function bindMainPrintButton() {
        var el = document.getElementById('printData') ||
                 document.getElementById('printing') ||
                 document.getElementById('printBtn') ||
                 document.getElementById('btnPrinting');
        if (!el || el.getAttribute('data-sp-list-print-bound') === '1') return;
        el.setAttribute('data-sp-list-print-bound', '1');
        el.addEventListener('click', function (ev) {
            if (ev && ev.preventDefault) ev.preventDefault();
            if (ev && ev.stopImmediatePropagation) ev.stopImmediatePropagation();
            else if (ev && ev.stopPropagation) ev.stopPropagation();
            var w = window.open(LIST_PRINT_URL, '_blank',
                                'width=1100,height=800,scrollbars=yes,resizable=yes');
            if (!w) {
                Ext.Msg.alert('Popup diblokir', 'Izinkan pop-up untuk situs ini, lalu klik Printing kembali.');
            }
        }, true);
    }

    // ============================================================
    // 4. MAIN GRID ACTIONS & DETAIL LOADER
    // ============================================================
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

    function loadPaymentDetailPanel(paymentId, paymentNo) {
        payDtlDiv.style.display = 'block';
        payDtlDiv.innerHTML = '<div style="padding:15px; text-align:center; color:#38bdf8;">Memuat detail alokasi...</div>';

        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_detail', payment_id: paymentId },
            success: function (response) {
                var res;
                try {
                    res = Ext.util.JSON.decode(response.responseText);
                } catch (e) {
                    payDtlDiv.innerHTML = '<div style="padding:15px; color:#ef4444;">Gagal memparsing respon detail.</div>';
                    return;
                }
                
                if (res && res.success) {
                    renderPaymentDetailPanel(res.data, paymentNo, paymentId);
                } else {
                    payDtlDiv.innerHTML = '<div style="padding:15px; color:#f59e0b;">' + (res.message || 'Tidak ada detail ditemukan.') + '</div>';
                }
            },
            failure: function () {
                payDtlDiv.innerHTML = '<div style="padding:15px; color:#ef4444;">Gagal terhubung ke server untuk memuat detail.</div>';
            }
        });
    }

    gridMain.on('rowclick', function (grid, rowIndex) {
        var rec = grid.getStore().getAt(rowIndex);
        if (rec) {
            dataRecord = rec.data;
            setMainButtons();
            loadPaymentDetailPanel(dataRecord.id, dataRecord.payment_no);
        }
    });

    setMainButtons();

    // ============================================================
    // 5. FORM EDITOR (DEFERRED RENDERING)
    // ============================================================
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var fldPayNo = new myTextField({
        fieldLabel: 'No. Pembayaran',
        id: 'payment_no',
        name: 'payment_no',
        readOnly: true,
        disabled: true,
        emptyText: 'Otomatis oleh sistem',
        width: 200
    });

    var fldPayDate = new Ext.form.DateField({
        fieldLabel: 'Tanggal Bayar',
        id: 'payment_date',
        name: 'payment_date',
        format: 'Y-m-d',
        width: 140,
        allowBlank: false
    });

    var fldSupplier = new myComboField({
        fieldLabel: 'Supplier',
        id: 'supplier_id',
        name: 'supplier_id',
        hiddenName: 'supplier_id',
        store: supplierDs,
        displayField: 'name',
        valueField: 'id',
        mode: 'remote',
        triggerAction: 'all',
        width: 200,
        allowBlank: false
    });

    var fldMethod = new myComboField({
        fieldLabel: 'Metode',
        id: 'payment_method',
        name: 'payment_method',
        hiddenName: 'payment_method',
        store: methodDs,
        displayField: 'label',
        valueField: 'value',
        mode: 'local',
        triggerAction: 'all',
        editable: false,
        width: 200,
        allowBlank: false
    });

    var fldAccount = new myComboField({
        fieldLabel: 'Bayar Dari Akun',
        id: 'account_id',
        name: 'account_id',
        hiddenName: 'account_id',
        store: accountDs,
        displayField: 'account_name',
        valueField: 'id',
        mode: 'local',
        triggerAction: 'all',
        width: 200,
        allowBlank: true
    });

    var fldTotal = new Ext.form.NumberField({
        fieldLabel: 'Total Dibayar (Kas/Bank)',
        id: 'total_amount',
        name: 'total_amount',
        width: 140,
        allowNegative: false,
        decimalPrecision: 2,
        value: 0
    });

    var fldDeposit = new Ext.form.NumberField({
        fieldLabel: 'Pakai Saldo Deposit',
        id: 'deposit_applied',
        name: 'deposit_applied',
        width: 140,
        allowNegative: false,
        decimalPrecision: 2,
        value: 0
    });

    var fldDepBalance = new Ext.form.DisplayField({
        fieldLabel: 'Saldo Deposit Tersedia',
        id: 'deposit_balance',
        value: '0.00'
    });

    var fldRef = new myTextField({
        fieldLabel: 'No. Ref/Bukti',
        id: 'reference_no',
        name: 'reference_no',
        width: 200
    });

    var fldNotes = new Ext.form.TextArea({
        fieldLabel: 'Keterangan',
        id: 'notes',
        name: 'notes',
        width: 200,
        height: 45
    });

    var colLeft = new Ext.form.FormPanel({
        columnWidth: 0.50,
        labelWidth: 150,
        border: false,
        bodyStyle: 'background:transparent; padding: 4px 8px 4px 0;',
        items: [fldId, fldPayNo, fldPayDate, fldSupplier, fldMethod, fldAccount]
    });

    var colRight = new Ext.form.FormPanel({
        columnWidth: 0.50,
        labelWidth: 150,
        border: false,
        bodyStyle: 'background:transparent; padding: 4px 0 4px 8px;',
        items: [fldTotal, fldDeposit, fldDepBalance, fldRef, fldNotes]
    });

    var frmEditor = new Ext.form.FormPanel({
        id: 'frmEditor',
        border: false,
        layout: 'column',
        bodyStyle: 'background:transparent;',
        items: [colLeft, colRight]
    });

    var frmEditorRendered = false;
    function ensureFrmEditorRendered() {
        if (frmEditorRendered) return;
        var container = document.getElementById('editorFormMain');
        if (!container) return;
        frmEditor.render('editorFormMain');
        frmEditorRendered = true;
    }

    // ============================================================
    // 6. GRID ALOKASI INVOICE (Editor)
    // ============================================================
    var editorDeleter = (typeof Extensive !== 'undefined' && Extensive.grid && Extensive.grid.ItemDeleter)
        ? new Extensive.grid.ItemDeleter() : null;

    var allocColumns = [
        {
            header: 'No. Invoice',
            dataIndex: 'invoice_no',
            width: 120,
            align: 'left',
            renderer: function (v) {
                return '<b style="color:#22d3ee;">' + esc(v) + '</b>';
            }
        },
        { header: 'No. Inv. Supplier', dataIndex: 'supplier_invoice_no', width: 120, align: 'left' },
        {
            header: 'Tgl Inv',
            dataIndex: 'invoice_date',
            width: 85,
            align: 'center',
            renderer: function (v) {
                return v ? Ext.util.Format.date(v, 'd/m/Y') : '-';
            }
        },
        {
            header: 'Total Tagihan',
            dataIndex: 'total_amount',
            width: 105,
            align: 'right',
            renderer: fmtAmt
        },
        {
            header: 'Sisa Hutang',
            dataIndex: 'unpaid_amount',
            width: 105,
            align: 'right',
            renderer: function (v) {
                return '<span style="color:#f59e0b; font-weight:bold;">' + fmtAmt(v) + '</span>';
            }
        },
        {
            header: 'Dibayar',
            dataIndex: 'amount_paid',
            width: 115,
            align: 'right',
            renderer: function (v) {
                return '<span style="color:#10b981; font-weight:bold;">' + fmtAmt(v) + '</span>';
            },
            editor: new Ext.form.NumberField({
                allowNegative: false,
                decimalPrecision: 2
            })
        }
    ];

    if (editorDeleter) {
        allocColumns.push(editorDeleter);
    }

    allocColumns.push({
        id: 'spDeleteCol',
        header: '<span style="color:#e05353 !important; font-size:16px; font-weight:bold;" title="Hapus Baris">🗑️</span>',
        dataIndex: 'invoice_id',
        width: 42,
        align: 'center',
        fixed: true,
        renderer: function () {
            return '<span class="spDeleteBtn" style="color:#ffffff !important; cursor:pointer; font-size:16px;" title="Hapus baris">🗑️</span>';
        }
    });

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

    gridAlloc.on('cellclick', function (grid, rowIndex, columnIndex) {
        if (grid.getColumnModel().getColumnId(columnIndex) === 'spDeleteCol') {
            grid.getStore().removeAt(rowIndex);
            refreshSummary();
        }
    });

    // ============================================================
    // 7. ALLOCATION & SUMMARY LOGIC
    // ============================================================
    function getAllocatedTotal() {
        var t = 0;
        gridInvoiceDs.each(function (r) {
            t += num(r.get('amount_paid'));
        });
        return round2(t);
    }

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

    // ============================================================
    // 8. DEPOSIT BALANCE LOADER
    // ============================================================
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
                } catch (e) { }
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
            if (num(fldDeposit.getValue()) > getAvailableDeposit() + 0.01) {
                fldDeposit.setValue(getAvailableDeposit());
            }
            loadInvoicesFor(sid);
        });
    });

    // ============================================================
    // 9. WINDOW EDITOR HELPERS
    // ============================================================
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

        ensureFrmEditorRendered();
        setEditorGridHeight();
        var frmCmp = Ext.getCmp('frmEditor');
        if (frmCmp) frmCmp.doLayout();
        refreshSummary();
    }

    function hideWindow() {
        var wdw = document.getElementById('editorWdw');
        if (wdw) wdw.style.display = 'none';
    }

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

    // ============================================================
    // 10. ACTIONS: ADD, EDIT, DELETE, SAVE, DISCARD
    // ============================================================

    // --- ADD ---
    if (Ext.get('addData')) {
        Ext.get('addData').on('click', function (e) {
            if (e) e.stopEvent();
            if (getFormAccess() < 2) {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak untuk menambah data.');
                return;
            }

            task = 'create';
            origSupplierId = 0;
            origDeposit = 0;
            origExcess = 0;
            depositBalance = 0;

            loadCombos(function () {
                ensureFrmEditorRendered();
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

    // --- EDIT ---
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

                        ensureFrmEditorRendered();
                        frmEditor.getForm().reset();
                        frmEditor.getForm().setValues(row);

                        gridInvoiceDs.removeAll();
                        loadDepositBalance(row.supplier_id, function () {
                            gridInvoiceDs.load({
                                params: {
                                    supplier_id: row.supplier_id,
                                    payment_id: row.id
                                }
                            });
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

    // --- DELETE ---
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
                }
            );
        });
    }

    // --- SUBMIT ---
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

    // --- SAVE BUTTON ---
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
                    function (btn) {
                        if (btn === 'yes') submitPayment(arrItems);
                    }
                );
            } else {
                submitPayment(arrItems);
            }
        });
    }

    // --- DISCARD BUTTON ---
    var btnDiscard = Ext.get('discardEdit') || Ext.select('#editorWdw .x-tool-close');
    if (btnDiscard) {
        btnDiscard.on('click', function (e) {
            if (e) e.stopEvent();
            hideWindow();
        });
    }

    // ============================================================
    // INIT: Hide editor window + bind print buttons
    // ============================================================
    var wdwInit = document.getElementById('editorWdw');
    if (wdwInit) wdwInit.style.display = 'none';

    bindMainPrintButton();
    setTimeout(bindMainPrintButton, 250);
    setTimeout(bindMainPrintButton, 1000);
});