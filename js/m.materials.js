/*
  Document      : m.materials.js
  Description   : Client-side Controller CRUD Master Material (m_materials)
                  menggunakan ExtJS 3.x dan layout stdForm.
  Backend       : ../php-crud/m.materialsCRD.php
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    var CRD = main_url + '/php-crud/m.materialsCRD.php';

    // Header halaman
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Master <em>Material</em>";
    }

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ============================================================
    // 1. DATA STORE & FIELDS
    // ============================================================
    var materialField = [
        { name: 'id' }, 
        { name: 'material_code' }, 
        { name: 'material_name' }, 
        { name: 'unit' },
        { name: 'estimated_price', type: 'float' }, 
        { name: 'active', type: 'boolean' },
        { name: 'created_at', type: 'date', dateFormat: 'Y-m-d' }
    ];

    var mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'list' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, materialField)
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
            { header: 'Kode Material', dataIndex: 'material_code', width: 120, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: 'Nama Material', dataIndex: 'material_name', width: 260, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: 'Satuan', dataIndex: 'unit', width: 90, align: 'center', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            {
                header: 'Estimasi Harga', dataIndex: 'estimated_price', width: 140, align: 'right',
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
    // 3. KOMPONEN FORM EDITOR (Menggunakan stdForm & myTextField)
    // ============================================================
    var fldMaterialCode = new myTextField({ fieldLabel: 'Kode Material', id: 'material_code', name: 'material_code', allowBlank: false, maxLength: 30 });
    var fldMaterialName = new myTextField({ fieldLabel: 'Nama Material', id: 'material_name', name: 'material_name', allowBlank: false, maxLength: 150 });
    var fldUnit = new myTextField({ fieldLabel: 'Satuan', id: 'unit', name: 'unit', allowBlank: false, maxLength: 20, emptyText: 'sak, m3, kg, btg, pcs, dll' });
    var fldEstimatedPrice = new myNumberField({ fieldLabel: 'Estimasi Harga', id: 'estimated_price', name: 'estimated_price', decimalPrecision: 2, allowBlank: false, value: 0 });
    
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
            { items: fldMaterialCode },
            { items: fldUnit },
            { items: fldMaterialName },
            { items: fldEstimatedPrice },
            { columnWidth: 0.5, labelAlign: 'right', labelWidth: 130, items: fldActive },
            { items: [new myTextField({ name: 'id', id: 'id', hidden: true })] }
        ]
    });

    // ============================================================
    // 4. ACTION HANDLERS (Tambah, Edit, Hapus, Simpan)
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
                    document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Material Baru';
                }
                fldMaterialCode.focus('', 10);
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menambah material.');
            }
        };
    }

    // EDIT DATA
    if (document.getElementById('editData')) {
        document.getElementById('editData').onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data material terlebih dahulu dari tabel.');
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
                        
                        // Parameter konversi boolean presisi
                        var isChecked = (val === true || val === 'true' || val === 't' || val === 1 || val === '1');
                        fldActive.setValue(isChecked);
                    },
                    failure: function () {
                        Ext.Msg.alert('Gagal', 'Gagal memuat data material.');
                    }
                });
                document.getElementById('editorWdw').style.display = 'block';
                if (document.getElementById('editorFormHeaderText')) {
                    document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Material';
                }
            } else {
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk edit material.');
            }
        };
    }

    // NONAKTIFKAN / HAPUS DATA
    if (document.getElementById('deleteData')) {
        document.getElementById('deleteData').onclick = function () {
            if (!dataRecord) {
                Ext.Msg.alert('Peringatan', 'Pilih data material yang akan dinonaktifkan.');
                return;
            }
            if (getFormAccess() >= 4 || getFormAccess() === 0) {
                Ext.Msg.confirm('Konfirmasi', 'Nonaktifkan material \'' + dataRecord.material_name + '\'?', function (btn) {
                    if (btn === 'yes') {
                        Ext.Ajax.request({
                            url: CRD,
                            method: 'POST',
                            params: { task: 'delete', id: dataRecord.id },
                            success: function (response) {
                                var res = Ext.util.JSON.decode(response.responseText);
                                if (res.success) {
                                    Ext.Msg.alert('Informasi', 'Material berhasil dinonaktifkan.');
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
                Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menonaktifkan material.');
            }
        };
    }

    // SIMPAN DATA (CREATE / UPDATE)
        document.getElementById('saveEdit').onclick = function () {
            var code = fldMaterialCode.getValue();
            var name = fldMaterialName.getValue();
            var unit = fldUnit.getValue();

            if (!code) { Ext.Msg.alert('Peringatan', 'Kode Material wajib diisi.'); return; }
            if (!name) { Ext.Msg.alert('Peringatan', 'Nama Material wajib diisi.'); return; }
            if (!unit) { Ext.Msg.alert('Peringatan', 'Satuan wajib diisi.'); return; }

            // Evaluasi eksplisit kondisi Checkbox agar dikirim akurat
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
                waitMsg: 'Menyimpan data material...',
                success: function (form, action) {
                    var res = Ext.util.JSON.decode(action.response.responseText);
                    Ext.Msg.alert('Sukses', 'Material ' + (res.material_code || code) + ' berhasil disimpan.');
                    mainDs.load();
                    document.getElementById('editorWdw').style.display = 'none';
                },
                failure: function (form, action) {
                    var res = Ext.util.JSON.decode(action.response.responseText);
                    Ext.Msg.alert('Gagal', res.message || 'Terjadi kesalahan sistem.');
                }
            });
        };

    if (document.getElementById('editorWdw')) {
        document.getElementById('editorWdw').style.display = 'none';
    }
});