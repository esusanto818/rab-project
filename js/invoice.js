/*
  Document      : m.invoice.js
  Description   : Sales Invoice - Header Grid + Editor Form dengan item
                   grid inline-editable. Status draft -> final (final
                   dipicu otomatis oleh tombol Print).
  Backend       : /inv/php-crud/invoiceCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Sales <em>Invoice</em>";

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ============================================================
    // 0. Buat elemen tambahan (Add Line & Total) secara dinamis
    // ============================================================
    (function injectExtraUI() {
        var gridContainer = document.getElementById('editorFormGrid');
        if (gridContainer && !document.getElementById('btnAddItem')) {
            var soItemPanel = document.createElement('div');
            soItemPanel.id = 'soItemPickerPanel';
            soItemPanel.style.cssText = 'padding:6px; margin-bottom:6px; background:rgba(255,255,255,0.05); border-radius:4px; display:none;';
            gridContainer.parentNode.insertBefore(soItemPanel, gridContainer);

            var toolbar = document.createElement('div');
            toolbar.style.padding = '5px 0';
            //toolbar.innerHTML = '<button id="btnAddItem" type="button" class="btn-dark">+ Tambah Item Manual</button>';
            gridContainer.parentNode.insertBefore(toolbar, gridContainer);

            var totalsDiv = document.createElement('div');
            totalsDiv.className = 'invoice-totals';
            totalsDiv.style.cssText = 'padding:8px 0; font-weight:bold; font-size:14px; color:#fff;';
            totalsDiv.innerHTML = 'Total: <span id="lblInvoiceTotal">0.00</span>';
            gridContainer.parentNode.insertBefore(totalsDiv, gridContainer.nextSibling);
        }
    })();

    function renderSoItemPicker() {
        var panel = document.getElementById('soItemPickerPanel');
        if (!panel) return;

        if (soItemDs.getCount() === 0) {
            panel.style.display = 'none';
            return;
        }

        // Otomatis masukkan SEMUA item SO yang belum di-invoice ke grid invoice
        soItemDs.each(function (r) {
            var soItemId = r.get('so_item_id');
            var soqty = r.get('qty');
            var sounitprice = r.get('unit_price');
            var sosubtotal = r.get('subtotal');

            var exists = itemGridDs.findExact('so_item_id', soItemId) >= 0;
            if (!exists) {
                itemGridDs.add(new addItemRow({
                    so_item_id: soItemId,
                    description: r.get('description') || r.get('product_name') || '(tanpa deskripsi)',
                    qty: soqty, unit_price: sounitprice, subtotal: sosubtotal
                }));
            }
        });
        updateInvoiceTotal();

        panel.style.display = 'block';
        panel.innerHTML = '<div style="font-size:12px">✓ ' + soItemDs.getCount() + ' item dari Sales Order ini sudah otomatis ditambahkan ke daftar item invoice di bawah.</div>';
    }

    // ============================================================
    // 1. Field definitions & stores
    // ============================================================
    var invoiceField = [
        { name: 'id' }, { name: 'invoice_no' }, { name: 'so_id' }, { name: 'so_ref_no' },
        { name: 'customer_id' }, { name: 'customer_name' },
        { name: 'invoice_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'due_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'total_amount', type: 'float' }, { name: 'paid_amount', type: 'float' }, { name: 'status' }
    ];

    var itemField = [
        { name: 'line_id' }, 
        { name: 'so_item_id' }, 
        { name: 'description' }, 
        { name: 'qty', type: 'float' },
        { name: 'unit_price', type: 'float' }, 
        { name: 'subtotal', type: 'float' }
    ];
    var addItemRow = Ext.data.Record.create(itemField);

    var soItemField = [
        { name: 'so_item_id' }, 
        { name: 'description' },
        { name: 'qty' }, 
        { name: 'unit_price' }, 
        { name: 'subtotal' },  
        { name: 'product_name' }
    ];
    var soItemDs = new Ext.data.Store({
        url: '../php-crud/invoiceCRD.php',
        baseParams: { task: 'read_so_items' },
        reader: new Ext.data.JsonReader({ root: 'data' }, soItemField)
    });
    soItemDs.on('load', renderSoItemPicker);

    var soField = [{ name: 'id' }, { name: 'ref_no' }, { name: 'tx_date' }, { name: 'legacy_customer_name' }];
    var soDs = new Ext.data.Store({
        url: '../php-crud/invoiceCRD.php',
        baseParams: { task: 'read_so' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, soField)
    });
    soDs.load();

    var customerField = [{ name: 'id' }, { name: 'name' }];
    var customerDs = new Ext.data.Store({
        url: '../php-crud/invoiceCRD.php',
        baseParams: { task: 'read_customer' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, customerField)
    });
    customerDs.load();

    var itemGridDs = new Ext.data.Store({
        url: '../php-crud/invoiceCRD.php',
        baseParams: { task: 'read_items' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, itemField)
    });

    // ============================================================
    // 2. Main grid
    // ============================================================
    var mainDs = new Ext.data.Store({
        url: '../php-crud/invoiceCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, invoiceField)
    });
    mainDs.load();

    function statusRenderer(v) {
        return v === 'final'
            ? '<span style="font-weight:bold">Final</span>'
            : '<span >Draft</span>';
    }
    let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));

    var gridMain = new stdGridPaging({
        store: mainDs,
        renderTo: 'divMainGrid',
        height : mainHeight,
        columns: [
            { header: 'No. Invoice', dataIndex: 'invoice_no', width: 110, renderer: CellWrap },
            { header: 'No. SO', dataIndex: 'so_ref_no', width: 100, renderer: CellWrap },
            { header: 'Customer', dataIndex: 'customer_name', width: 160, renderer: CellWrap },
            { header: 'Tanggal', dataIndex: 'invoice_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: 'Jatuh Tempo', dataIndex: 'due_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
            {
                header: 'Total', dataIndex: 'total_amount', width: 120, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Terbayar', dataIndex: 'paid_amount', width: 110, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            { header: 'Status', dataIndex: 'status', width: 80, align: 'center', renderer: statusRenderer }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                dataRecord = grid.getStore().getAt(rowIndex).data;
                mainBtnEnable('#deleteData');
                editDataEnable();
            }
        },
        pageSize: 250,
        pageStore: mainDs
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ============================================================
    // 3. Item grid (inline editable)
    // ============================================================
    var itemDeleter = new Extensive.grid.ItemDeleter();

    var itemGrid = new stdGridEditor({
        store: itemGridDs,
        renderTo: 'editorFormGrid',
        viewConfig: { forceFit: true, scrollOffset: 0 },
        height: 200,
        selModel: itemDeleter,
        columns: [
            { header: 'Deskripsi', dataIndex: 'description', width: 220, editor: new myTextField({}), renderer: CellWrap },
            { header: 'Qty', dataIndex: 'qty', width: 70, align: 'right', editor: new myNumberField({ decimalPrecision: 2 }), renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Harga Satuan', dataIndex: 'unit_price', width: 120, align: 'right', editor: new myNumberField({ decimalPrecision: 2 }), renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); } },
            { header: 'Subtotal', dataIndex: 'subtotal', width: 130, align: 'right', renderer: function (v) { return '<b>' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>'; } },
            itemDeleter
        ],
        listeners: {
            afteredit: function (e) {
                if (e.field === 'qty' || e.field === 'unit_price') {
                    var qty = parseFloat(e.record.get('qty')) || 0;
                    var price = parseFloat(e.record.get('unit_price')) || 0;
                    e.record.set('subtotal', qty * price);
                }
                updateInvoiceTotal();
            }
        }
    });
    itemGrid.addClass('x-grid3-gridEditor');

    document.getElementById('btnAddItem') && (document.getElementById('btnAddItem').onclick = function () {
        itemGridDs.add(new addItemRow({ description: '', qty: 1, unit_price: 0, subtotal: 0 }));
    });

    function updateInvoiceTotal() {
        var total = 0;
        itemGridDs.each(function (r) { total += parseFloat(r.get('subtotal')) || 0; });
        var el = document.getElementById('lblInvoiceTotal');
        if (el) el.innerHTML = Ext.util.Format.number(total, '0,000.00');
    }
    itemGridDs.on('update', updateInvoiceTotal);
    itemGridDs.on('add', updateInvoiceTotal);
    itemGridDs.on('remove', updateInvoiceTotal);
    itemGridDs.on('load', updateInvoiceTotal);

    // ============================================================
    // 4. Editor Form
    // ============================================================
    var fldInvoiceNo = new myTextField({ fieldLabel: 'No. Invoice', id: 'invoice_no', name: 'invoice_no', readOnly: true, emptyText: 'Auto-generate' });
    var fldSo = new myComboField({
        fieldLabel: 'Sales Order', id: 'so_id', name: 'so_id', hiddenName: 'so_id',
        store: soDs, displayField: 'ref_no', valueField: 'id', allowBlank: false,
        listeners: {
            select: function (combo, record) {
                soItemDs.load({ params: { so_id: record.get('id') } });

                // Coba cocokkan Customer berdasarkan NAMA (bukan ID -- SO lama
                // pakai tabel m_customer yang terpisah dari tabel customers
                // baru, ID-nya tidak sebanding).
                var legacyName = (record.get('legacy_customer_name') || '').trim().toLowerCase();
                fldCustomer.clearValue();
                if (legacyName) {
                    var matchIdx = customerDs.findBy(function (rec) {
                        return (rec.get('name') || '').trim().toLowerCase() === legacyName;
                    });
                    if (matchIdx >= 0) {
                        fldCustomer.setValue(customerDs.getAt(matchIdx).get('id'));
                    } else {
                        document.getElementById('popWdwTxt').innerHTML = 'Customer SO ("' + record.get('legacy_customer_name') + '") tidak ditemukan otomatis di data Customer baru. Silakan pilih Customer secara manual.';
                        document.getElementById('popWdw').style.display = 'block';
                    }
                }
            }
        }
    });
    var fldCustomer = new myComboField({
        fieldLabel: 'Customer', id: 'customer_id', name: 'customer_id', hiddenName: 'customer_id',
        store: customerDs, displayField: 'name', valueField: 'id', allowBlank: false
    });
    var fldInvDate = new myDateField({ fieldLabel: 'Tanggal Invoice', id: 'invoice_date', name: 'invoice_date', allowBlank: false, value: new Date() });
    var fldDueDate = new myDateField({ fieldLabel: 'Jatuh Tempo', id: 'due_date', name: 'due_date' });
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 110,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldInvoiceNo },
            { items: fldSo },
            { items: fldCustomer },
            { items: fldInvDate },
            { items: fldDueDate },
            { items: fldId }
        ]
    });
    frmEditor.on('clientvalidation', function (f, valid) { if (!valid) { saveEditDisable(); } else { saveEditEnable(); } });

    // ============================================================
    // 5. Toolbar
    // ============================================================
    document.getElementById('addData').onclick = function () {
        task = '';
        frmEditor.getForm().reset();
        if (getFormAccess() == 2 || getFormAccess() == 3 || getFormAccess() == 4) {
            task = 'create';
            itemGridDs.removeAll();
            soItemDs.removeAll();
            var panel = document.getElementById('soItemPickerPanel');
            if (panel) panel.style.display = 'none';
            updateInvoiceTotal();
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Invoice';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function () {
        task = '';
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (dataRecord.status !== 'draft') {
            document.getElementById('popWdwTxt').innerHTML = 'Hanya invoice berstatus Draft yang boleh diedit.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 3 || getFormAccess() == 4) {
            frmEditor.getForm().reset();
            task = 'update';
            frmEditor.getForm().load({ url: '../php-crud/invoiceCRD.php', params: { id: dataRecord.id, task: 'read_edit' } });
            itemGridDs.load({ params: { id: dataRecord.id } });
            if (dataRecord.so_id) soItemDs.load({ params: { so_id: dataRecord.so_id } });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Invoice';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (dataRecord.status !== 'draft') {
            document.getElementById('popWdwTxt').innerHTML = 'Hanya invoice berstatus Draft yang boleh dihapus.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 4) {
            task = 'delete';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById('popWdwTxt').innerHTML = 'Hapus invoice \'' + dataRecord.invoice_no + '\' ?';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses untuk menghapus data.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    // Print -- finalisasi otomatis dulu (draft -> final + jurnal), baru buka PDF
    document.getElementById('printData').onclick = function () {
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih invoice terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        Ext.Ajax.request({
            url: '../php-crud/invoiceCRD.php',
            method: 'POST',
            params: { task: 'finalize', id: dataRecord.id },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    document.getElementById('popWdwTxt').innerHTML = res.message;
                    document.getElementById('popWdw').style.display = 'block';
                    return;
                }
                if (!res.already_final) {
                    document.getElementById('popWdwTxt').innerHTML = res.message;
                    document.getElementById('popWdw').style.display = 'block';
                    mainDs.load({ params: { task: 'read' } });
                }
                window.open('../pdf/invoice.php?id=' + dataRecord.id, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
            },
            failure: function () {
                document.getElementById('popWdwTxt').innerHTML = 'Gagal menghubungi server untuk finalisasi invoice.';
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('saveEdit').onclick = function () {
        var arrItems = [];
        itemGridDs.each(function (r) {
            arrItems.push({
                so_item_id: r.get('so_item_id'),
                description: r.get('description'), qty: r.get('qty'),
                unit_price: r.get('unit_price'), subtotal: r.get('subtotal')
            });
        });

        frmEditor.getForm().submit({
            url: '../php-crud/invoiceCRD.php',
            method: 'POST',
            params: { task: task, arrItems: JSON.stringify(arrItems) },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                itemGridDs.removeAll();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message || 'Gagal menyimpan invoice.';
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        if (task == 'delete') {
            var deleteDs = new Ext.data.Store({ url: '../php-crud/invoiceCRD.php' });
            deleteDs.load({
                params: { task: 'delete', id: dataRecord.id },
                callback: function (records, operation) {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    var res = Ext.util.JSON.decode(operation.response ? operation.response.responseText : '{}');
                    document.getElementById('popWdwTxt').innerHTML = res.message || 'Selesai';
                    document.getElementById('popWdw').style.display = 'block';
                    task = '';
                    mainDs.load();
                }
            });
        } else {
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
});