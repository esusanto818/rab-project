/*
  Document      : m.coa.js
  Description   : Chart of Accounts - List Grid + Add/Edit Form (hierarkis: parent_id)
  Pattern       : mengikuti m.product.js / m.supplier.js
  Backend       : /univ/php-crud/m.coaCRD.php (task: read, read_parent, read_edit, create, update, delete)
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Chart of <em>Accounts</em>";

    var task = '';
    var dataRecord = null;

    // ============================================================
    // 1. Field definitions
    // ============================================================
    var coaField = [
        { name: 'id' },
        { name: 'account_code' },
        { name: 'account_name' },
        { name: 'account_type' },
        { name: 'parent_id' },
        { name: 'parent_name' },
        { name: 'is_active', type: 'boolean' }
    ];
    var parentField = [{ name: 'id' }, { name: 'account_code' }, { name: 'account_name' }];

    // ============================================================
    // 2. Combo stores
    // ============================================================
    var accountTypeOptions = new Ext.data.SimpleStore({
        fields: ['value', 'label'],
        data: [
            ['asset', 'Asset'],
            ['liability', 'Liability'],
            ['equity', 'Equity'],
            ['revenue', 'Revenue'],
            ['expense', 'Expense']
        ]
    });

    // Combo akun induk -- load dari server (semua akun, dipakai utk pilih parent)
    var parentDs = new Ext.data.Store({
        url: '../php-crud/m.coaCRD.php',
        baseParams: { task: 'read_parent' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, parentField)
    });
    parentDs.load();

    // ============================================================
    // 3. Main store & grid
    // ============================================================
    var mainDs = new Ext.data.Store({
        url: '../php-crud/m.coaCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, coaField)
    });
    mainDs.load();
    let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));

    var gridMain = new stdGridPaging({
        store: mainDs,
        renderTo: 'divMainGrid',
        height : mainHeight,

        columns: [
            { header: 'Kode Akun', dataIndex: 'account_code', width: 100, renderer: CellWrap },
            { header: 'Nama Akun', dataIndex: 'account_name', width: 250, renderer: CellWrap },
            { header: 'Tipe Akun', dataIndex: 'account_type', width: 110, renderer: CellWrap },
            { header: 'Akun Induk', dataIndex: 'parent_name', width: 200, renderer: CellWrap },
            {
                header: 'Status', dataIndex: 'is_active', width: 80, align: 'center',
                renderer: function (v) {
                    return v ? '<span style="font-weight:bold">Aktif</span>'
                             : '<span >Nonaktif</span>';
                }
            }
        ],
        listeners: {
            cellclick: function (grid, rowIndex) {
                dataRecord = grid.getStore().getAt(rowIndex).data;
                editDataEnable();
                mainBtnEnable('#deleteData');
            }
        },
        pageSize: 250,
        pageStore: mainDs
    });

    editDataDisable();
    mainBtnDisable('#deleteData');

    // ============================================================
    // 4. Editor Form
    // ============================================================
    var fldCode = new myTextField({
        fieldLabel: 'Kode Akun', id: 'account_code', name: 'account_code', allowBlank: false,
        autoCreate: { tag: "input", maxlength: 20, type: "text", size: "20", autocomplete: "off" }
    });
    var fldName = new myTextField({
        fieldLabel: 'Nama Akun', id: 'account_name', name: 'account_name', allowBlank: false,
        autoCreate: { tag: "input", maxlength: 150, type: "text", size: "150", autocomplete: "off" }
    });
    var fldType = new myComboField({
        fieldLabel: 'Tipe Akun', id: 'account_type', name: 'account_type', hiddenName: 'account_type',
        store: accountTypeOptions, displayField: 'label', valueField: 'value', allowBlank: false
    });
    var fldParent = new myComboField({
        fieldLabel: 'Akun Induk', id: 'parent_id', name: 'parent_id', hiddenName: 'parent_id',
        store: parentDs, displayField: 'account_name', valueField: 'id',
        emptyText: '-- Tidak ada (akun utama) --'
    });
    var fldActive = new Ext.form.Checkbox({
        fieldLabel: 'Status Aktif', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;',
        id: 'is_active', name: 'is_active_ui', cls: 'fieldCheckClass regular-checkbox',
        inputValue: 'true', checked: true
        // CATATAN: name sengaja BUKAN 'is_active' -- skin custom
        // 'regular-checkbox' merusak atribut value dari <input> aslinya
        // jadi "[object Object]" saat form di-serialize otomatis dari DOM.
        // id tetap 'is_active' (dipakai frmEditor.getForm().load() untuk
        // mengisi nilai saat Edit), tapi nilai yang BENAR-BENAR terkirim
        // ke server datang dari params eksplisit di tombol Save di bawah,
        // bukan dari serialisasi DOM field ini.
    });
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 150,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldCode },
            { items: fldName },
            { items: fldType },
            { items: fldParent },
            { columnWidth: 0.5, labelAlign: 'right', labelWidth: 150, items: fldActive },
            { items: fldId }
        ]
    });
    frmEditor.on('clientvalidation', function (f, valid) {
        if (!valid) { saveEditDisable(); } else { saveEditEnable(); }
    });

    // ============================================================
    // 5. Icon toolbar global (#addData/#editData/#deleteData/#printData)
    // ============================================================
    document.getElementById('addData').onclick = function (event) {
        task = '';
        frmEditor.getForm().reset();
        if (formAccess == 2 || formAccess == 3 || formAccess == 4) {
            task = 'create';
            parentDs.reload();
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Akun';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function (event) {
        task = '';
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (formAccess == 3 || formAccess == 4) {
            frmEditor.getForm().reset();
            task = 'update';
            frmEditor.getForm().load({
                url: '../php-crud/m.coaCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' }
            });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Akun';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function (event) {
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (formAccess == 4) {
            task = 'delete';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById('popWdwTxt').innerHTML = 'Nonaktifkan \'' + dataRecord.account_name + '\' ?';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses untuk menonaktifkan data.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function (event) {
        window.open("../pdf/m.coa.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    document.getElementById('saveEdit').onclick = function (event) {
        // FIX: field is_active pakai skin custom (cls 'regular-checkbox'),
        // yang ternyata mengubah atribut value dari <input> aslinya jadi
        // bukan string biasa (terkirim sebagai "[object Object]" saat
        // form di-serialize otomatis dari DOM). Kirim nilainya secara
        // eksplisit di sini -- ini akan override value dari DOM karena
        // ExtJS menambahkan 'params' SETELAH data form yang di-serialize.
        frmEditor.getForm().submit({
            url: '../php-crud/m.coaCRD.php',
            method: 'POST',
            params: {
                task: task,
                is_active: fldActive.getValue() ? 1 : 0
            },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function (form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = objResponse.message;
                document.getElementById('popWdw').style.display = 'block';

                mainDs.load({ params: { task: 'read' } });
                parentDs.reload();
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = objResponse.message;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('popOK').onclick = function (event) {
        if (task == 'delete') {
            var deleteDs = new Ext.data.Store({ url: '../php-crud/m.coaCRD.php' });
            deleteDs.load({
                params: { task: 'delete', id: dataRecord.id },
                callback: function (records, operation, success) {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    task = '';
                    mainDs.load();
                    parentDs.reload();
                }
            });
        } else {
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    // ============================================================
    // 6. Sembunyikan window di baris paling akhir (SETELAH semua
    //    komponen Ext selesai di-render, supaya layout terhitung benar)
    // ============================================================
    document.getElementById('editorWdw').style.display = 'none';
});