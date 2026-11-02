/*
Document      : m.expenses.js
Description   : Client-side Controller CRUD Master Expense
                Sama pola dengan m.materials.js
Backend       : ../php-crud/m.expensesCRD.php
*/
Ext.onReady(function () {
    Ext.QuickTips.init();
    var CRD = main_url + '/php-crud/m.expensesCRD.php';

    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Master <em>Expense</em>";
    }

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ============================================================
    // 1. DATA STORE & FIELDS
    // ============================================================
    var expenseField = [
        { name: 'id' },
        { name: 'expense_code' },
        { name: 'expense_name' },
        { name: 'category' },
        { name: 'unit' },
        { name: 'default_price', type: 'float' },
        { name: 'active', type: 'boolean' }
    ];

    var mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, expenseField)
    });
    mainDs.load();

    // ============================================================
    // 2. GRID PANEL UTAMA
    // ============================================================
    var GridDtlHeight = (typeof maxGridHeight === 'function')
        ? maxGridHeight(document.getElementById('divMainGrid'))
        : 400;

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: GridDtlHeight,
        columns: [
            { header: 'Kode', dataIndex: 'expense_code', width: 120, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: 'Nama Expense', dataIndex: 'expense_name', width: 260, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: 'Kategori', dataIndex: 'category', width: 110 },
            { header: 'Satuan', dataIndex: 'unit', width: 90, align: 'center' },
            {
                header: 'Harga Satuan', dataIndex: 'default_price', width: 140, align: 'right',
                renderer: function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); }
            },
            {
                header: 'Status', dataIndex: 'active', width: 100, align: 'center',
                renderer: function (v) {
                    return (v === true || v === 'true' || v === 't' || v === 1)
                        ? '<span style="color:#2e9e4f;font-weight:bold;">Aktif</span>'
                        : '<span style="color:#c0392b;font-weight:bold;">Nonaktif</span>';
                }
            }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    if (typeof mainBtnEnable === 'function') {
                        mainBtnEnable('#editData');
                        mainBtnEnable('#deleteData');
                    }
                }
            }
        }
    });

    if (typeof editDataDisable === 'function') editDataDisable();
    if (typeof mainBtnDisable === 'function') mainBtnDisable('#deleteData');

    // ============================================================
    // 3. KOMPONEN FORM EDITOR
    // ============================================================
    var fldCode = new myTextField({ fieldLabel: 'Kode Expense', id: 'expense_code', name: 'expense_code', allowBlank: false, maxLength: 30 });
    var fldName = new myTextField({ fieldLabel: 'Nama Expense', id: 'expense_name', name: 'expense_name', allowBlank: false, maxLength: 150 });
    var fldCategory = new myComboField({
        fieldLabel: 'Kategori',
        id: 'category',
        name: 'category',
        store: new Ext.data.ArrayStore({ fields: ['v'], data: [['Upah'], ['Transport'], ['Peralatan'], ['Konsumsi'], ['Lain-lain']] }),
        displayField: 'v', valueField: 'v', mode: 'local', triggerAction: 'all', forceSelection: true, allowBlank: false
    });
    var fldUnit = new myTextField({ fieldLabel: 'Satuan', id: 'unit', name: 'unit', allowBlank: false, maxLength: 20, emptyText: 'hari, trip, unit, paket, dll' });
    var fldPrice = new myNumberField({ fieldLabel: 'Harga Satuan', id: 'default_price', name: 'default_price', decimalPrecision: 2, allowBlank: false, value: 0 });
    var fldActive = new Ext.form.Checkbox({
        fieldLabel: 'Status Aktif',
        labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;',
        id: 'is_active',
        name: 'active',
        cls: 'fieldCheckClass regular-checkbox',
        inputValue: 'true',
        uncheckedValue: 'false',
        checked: true
    });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldCode },
            { items: fldUnit },
            { items: fldName },
            { items: fldPrice },
            { items: fldCategory },
            { columnWidth: 0.5, labelAlign: 'right', labelWidth: 130, items: fldActive },
            { items: [new myTextField({ name: 'id', id: 'id', hidden: true })] }
        ]
    });

    // ============================================================
    // 4. ACTION HANDLERS
    // ============================================================

    // TAMBAH DATA
    if (document.getElementById('addData')) {
        document.getElementById('addData').onclick = function () {
            if (getFormAccess() >= 2 || getFormAccess() === 0) {
                task = 'create';
                dataRecord = null;
                frmEditor.getForm().reset();
                fldActive.setValue(true);
                document.getElementById('editorWdw').style.display = 'block';
                if (document.getElementById('editorFormHeaderText')) {
                    document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Expense Baru';
                }
                fldCode.focus('', 10);
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menambah expense.');
            }
        };
    }

    // EDIT DATA
    if (document.getElementById('editData')) {
        document.getElementById('editData').onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data expense terlebih dahulu.');
                return;
            }
            if (getFormAccess() >= 3 || getFormAccess() === 0) {
                task = 'update';
                frmEditor.getForm().load({
                    url: CRD,
                    params: { id: dataRecord.id, task: 'read_edit' },
                    success: function (form, action) {
                        var res = Ext.util.JSON.decode(action.response.responseText);
                        var val = res.data.active;
                        var isChecked = (val === true || val === 'true' || val === 't' || val === 1 || val === '1');
                        fldActive.setValue(isChecked);
                    },
                    failure: function () {
                        Ext.Msg.alert('Gagal', 'Gagal memuat data expense.');
                    }
                });
                document.getElementById('editorWdw').style.display = 'block';
                if (document.getElementById('editorFormHeaderText')) {
                    document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Expense';
                }
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk edit expense.');
            }
        };
    }

    // NONAKTIFKAN DATA
    if (document.getElementById('deleteData')) {
        document.getElementById('deleteData').onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data expense yang akan dinonaktifkan.');
                return;
            }
            if (getFormAccess() >= 4 || getFormAccess() === 0) {
                Ext.Msg.confirm('Konfirmasi', 'Nonaktifkan expense \'' + dataRecord.expense_name + '\'?', function (btn) {
                    if (btn === 'yes') {
                        Ext.Ajax.request({
                            url: CRD,
                            method: 'POST',
                            params: { task: 'delete', id: dataRecord.id },
                            success: function (response) {
                                var res = Ext.util.JSON.decode(response.responseText);
                                if (res.success) {
                                    Ext.Msg.alert('Informasi', 'Expense berhasil dinonaktifkan.');
                                    dataRecord = null;
                                    if (typeof editDataDisable === 'function') editDataDisable();
                                    if (typeof mainBtnDisable === 'function') mainBtnDisable('#deleteData');
                                    mainDs.load();
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
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menonaktifkan expense.');
            }
        };
    }

    // SIMPAN DATA (CREATE / UPDATE)
    if (document.getElementById('saveEdit')) {
        document.getElementById('saveEdit').onclick = function () {
            var code = fldCode.getValue();
            var name = fldName.getValue();
            var category = fldCategory.getValue();
            var unit = fldUnit.getValue();
            if (!code) { Ext.Msg.alert('Peringatan', 'Kode Expense wajib diisi.'); return; }
            if (!name) { Ext.Msg.alert('Peringatan', 'Nama Expense wajib diisi.'); return; }
            if (!category) { Ext.Msg.alert('Peringatan', 'Kategori wajib dipilih.'); return; }
            if (!unit) { Ext.Msg.alert('Peringatan', 'Satuan wajib diisi.'); return; }

            var isChecked = fldActive.getValue();
            var activeVal = (isChecked === true || isChecked === 'true' || isChecked === '1') ? 'true' : 'false';

            frmEditor.getForm().submit({
                url: CRD,
                method: 'POST',
                params: {
                    task: task,
                    id: (dataRecord && dataRecord.id) ? dataRecord.id : 0,
                    is_active: activeVal
                },
                waitTitle: 'Menyimpan',
                waitMsg: 'Menyimpan data expense...',
                success: function (form, action) {
                    var res = Ext.util.JSON.decode(action.response.responseText);
                    Ext.Msg.alert('Sukses', 'Expense ' + (res.expense_code || code) + ' berhasil disimpan.');
                    mainDs.load();
                    document.getElementById('editorWdw').style.display = 'none';
                },
                failure: function (form, action) {
                    var res = Ext.util.JSON.decode(action.response.responseText);
                    Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                }
            });
        };
    }

    // Sembunyikan editor window saat pertama load
    if (document.getElementById('editorWdw')) {
        document.getElementById('editorWdw').style.display = 'none';
    }
});