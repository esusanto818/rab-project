/*
  Document      : m.clients.js
  Description   : Master Klien (public.clients) -- CRUD sederhana tanpa
                   detail grid. Pola sama dengan m.materials.js.
                   mainDs di-assign POLOS (tanpa var/let) supaya search
                   box global di header (lihat many-template-icn.php)
                   bisa mengaksesnya.
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/clientsCRD.php
=======
  Backend       : /rab-project/live/php-crud/clientsCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Master <em>Klien</em>";

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // ------------------------------------------------------------
    // 1. Fields & Store
    // ------------------------------------------------------------
    var clientField = [
        { name: 'id' }, { name: 'name' }, { name: 'contact_person' }, { name: 'phone' },
        { name: 'email' }, { name: 'address' }, { name: 'project_count', type: 'int' },
        { name: 'created_at', type: 'date', dateFormat: 'Y-m-d' }
    ];

    // PENTING: assignment polos (bukan var/let) -- supaya search box
    // global di header (kotak Search, id 'q') bisa mengakses mainDs ini.
    // Lihat many-template-icn.php: `let mainDs = '';` dideklarasikan
    // global, dan keydown listener global manggil mainDs.load(...).
    mainDs = new Ext.data.Store({
        url: '../php-crud/clientsCRD.php',
        idProperty: 'id',
        baseParams: { task: 'list' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, clientField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: GridDtlHeight,
        columns: [
            { header: 'Nama Klien', dataIndex: 'name', width: 200, renderer: CellWrap },
            { header: 'Contact Person', dataIndex: 'contact_person', width: 160, renderer: CellWrap },
            { header: 'Telepon', dataIndex: 'phone', width: 120, renderer: CellWrap },
            { header: 'Email', dataIndex: 'email', width: 180, renderer: CellWrap },
            { header: 'Alamat', dataIndex: 'address', width: 220, renderer: CellWrap },
            {
                header: 'Jml Proyek', dataIndex: 'project_count', width: 100, align: 'center',
                renderer: function (v) {
                    return '<span style="font-weight:bold;">' + (v || 0) + '</span>';
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
                    mainBtnEnable('#editData');
                    mainBtnEnable('#deleteData');
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Editor Form Fields
    // ------------------------------------------------------------
    var fldName = new myTextField({ fieldLabel: 'Nama Klien', id: 'name', name: 'name', allowBlank: false, maxLength: 255 });
    var fldContactPerson = new myTextField({ fieldLabel: 'Contact Person', id: 'contact_person', name: 'contact_person', maxLength: 255 });
    var fldPhone = new myTextField({ fieldLabel: 'Telepon', id: 'phone', name: 'phone', maxLength: 50 });
    var fldEmail = new myTextField({ fieldLabel: 'Email', id: 'email', name: 'email', maxLength: 255, vtype: 'email' });
    var fldAddress = new myTextArea({ fieldLabel: 'Alamat', id: 'address', name: 'address' });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { items: fldName },
            { items: fldContactPerson },
            { items: fldPhone },
            { items: fldEmail },
            { columnWidth: 1, items: fldAddress },
            { items: [new myTextField({ name: 'id', id: 'id', hidden: true })] }
        ]
    });

    // ------------------------------------------------------------
    // 4. CRUD Toolbar Action Handlers
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        if (getFormAccess() >= 2) {
            task = 'create';
            dataRecord = null;
            frmEditor.getForm().reset();
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Klien Baru';
            fldName.focus('', 10);
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menambah klien.');
        }
    };

    document.getElementById('editData').onclick = function () {
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data klien terlebih dahulu dari tabel.');
            return;
        }
        if (getFormAccess() >= 3) {
            task = 'update';
            frmEditor.getForm().load({
                url: '../php-crud/clientsCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function () {},
                failure: function () {
                    Ext.Msg.alert('Gagal', 'Gagal memuat data klien.');
                }
            });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Klien';
        } else {
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk edit klien.');
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            Ext.Msg.alert('Peringatan', 'Pilih data klien yang akan dihapus.');
            return;
        }
        if (getFormAccess() >= 4) {
            var warn = dataRecord.project_count > 0
                ? ('Klien \'' + dataRecord.name + '\' terpakai di ' + dataRecord.project_count + ' proyek. Yakin ingin menghapus?')
                : ('Hapus klien \'' + dataRecord.name + '\'?');
            Ext.Msg.confirm('Konfirmasi Hapus', warn, function (btn) {
                if (btn === 'yes') {
                    Ext.Ajax.request({
                        url: '../php-crud/clientsCRD.php',
                        method: 'POST',
                        params: { task: 'delete', id: dataRecord.id },
                        success: function (response) {
                            var res = Ext.util.JSON.decode(response.responseText);
                            if (res.success) {
                                Ext.Msg.alert('Informasi', 'Klien berhasil dihapus.');
                                dataRecord = null;
                                editDataDisable();
                                mainBtnDisable('#deleteData');
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
            Ext.Msg.alert('Akses Ditolak', 'Anda tidak memiliki hak akses untuk menghapus.');
        }
    };

    document.getElementById('saveEdit').onclick = function () {
        var name = fldName.getValue();
        if (!name) {
            Ext.Msg.alert('Peringatan', 'Nama Klien wajib diisi.');
            return;
        }

        frmEditor.getForm().submit({
            url: '../php-crud/clientsCRD.php',
            method: 'POST',
            params: {
                task: task,
                id: (dataRecord && dataRecord.id) ? dataRecord.id : 0,
                name: name,
                contact_person: fldContactPerson.getValue(),
                phone: fldPhone.getValue(),
                email: fldEmail.getValue(),
                address: fldAddress.getValue()
            },
            waitTitle: 'Menyimpan',
            waitMsg: 'Menyimpan data klien...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                Ext.Msg.alert('Sukses', 'Klien ' + (res.name || name) + ' berhasil disimpan.');
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
});