/*
  Document      : m.user.system.js
  Description   : Client-side Management User System & Hak Akses Form
  Project       : Universal ERP / Inventory / Proyek Kontraktor
*/

Ext.onReady(function() {
    Ext.QuickTips.init();

    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "User <em>List</em> ";
    }

    var task = '';
    var deleteRow = 0;
    var deleteRecord = null;
    var dataRecord = null;

    // ==========================================
    // 1. EVENT HANDLERS TOP TOOLBAR BUTTONS
    // ==========================================
    document.getElementById('addData').onclick = function(event) {
        task = '';
        if (typeof formAccess !== 'undefined' && (formAccess == 2 || formAccess == 3 || formAccess == 4)) {
            task = 'create';
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New User";
            
            var editorFormEl = document.getElementById('editorForm');
            var btnAddItemEl = document.getElementById('btnAddItem');
            if (editorFormEl && btnAddItemEl) {
                var gridEditorHeight = editorFormEl.getBoundingClientRect().bottom - btnAddItemEl.getBoundingClientRect().bottom - 5;
                if (Ext.getCmp('gridEditor')) {
                    Ext.getCmp('gridEditor').setHeight(gridEditorHeight);
                }
            }
            
            gridDetailDs.load({ params: { task: 'read_detail', user_id: 0 } });
            gridEditorDs.load({ params: { task: 'read_detail', user_id: 0 } });
            Ext.getCmp('frmEditor').getForm().reset();
            Ext.getCmp('name').focus('', 10);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function(event) {
        task = '';
        if (typeof formAccess !== 'undefined' && (formAccess == 3 || formAccess == 4)) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Pilih data user terlebih dahulu dari tabel.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }

            task = 'update';
            Ext.getCmp('frmEditor').getForm().load({
                url: '../live/php-crud/m.user.systemCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function(form, action) {
                    // Pastikan hidden field employee_id dan combo name terisi presisi
                    if (dataRecord.employee_id) {
                        Ext.getCmp('employee_id').setValue(dataRecord.employee_id);
                        Ext.getCmp('name').setValue(dataRecord.employee_id);
                    }
                }
            });
            
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit User";
            
            var editorFormEl = document.getElementById('editorForm');
            var btnAddItemEl = document.getElementById('btnAddItem');
            if (editorFormEl && btnAddItemEl) {
                var gridEditorHeight = editorFormEl.getBoundingClientRect().bottom - btnAddItemEl.getBoundingClientRect().bottom - 5;
                if (Ext.getCmp('gridEditor')) {
                    Ext.getCmp('gridEditor').setHeight(gridEditorHeight);
                }
            }
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function(event) {
        if (typeof formAccess !== 'undefined' && formAccess == 4) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Pilih data user yang akan dihapus.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }

            task = 'delete';
            deleteRow = 0; // Pastikan bukan penghapusan baris grid editor
            var userName = dataRecord.employee_name || dataRecord.login_name || '';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById("popWdwTxt").innerHTML = 'delete \'' + userName + '\' ?';
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to delete this data';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function(event) {
        window.open("../pdf/m.form.name.php", 'sub', 'toolbar=no,location=no,status=no,directories=no,menubar=no');
    };

    document.getElementById('saveEdit').onclick = function(event) {
        var arrForm = [];
        
        // Loop data store pada grid editor hak akses menu
        if (Ext.getCmp('gridEditor')) {
            var store = Ext.getCmp('gridEditor').getStore();
            store.each(function(rec) {
                arrForm.push({
                    id: rec.data.id || 0,
                    form_id: rec.data.form_id || 0,
                    access_level: rec.data.access_level || 1
                });
            });
        }

        // Tangkap employee_id dengan proteksi dari ComboBox 'name' atau Hidden Field 'employee_id'
        var selectedEmployeeId = Ext.getCmp('name').getValue() || Ext.getCmp('employee_id').getValue();

        Ext.getCmp('frmEditor').getForm().submit({
            method: 'POST',
            params: {
                task: task,
                user_id: (dataRecord && dataRecord.id) ? dataRecord.id : 0,
                employee_id: selectedEmployeeId,
                u_active: Ext.getCmp('AddActive').getValue(),
                form_access: Ext.util.JSON.encode(arrForm)
            },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                var messages = objResponse.message || 'Data berhasil disimpan.';

                document.getElementById("popWdwTxt").innerHTML = messages;
                document.getElementById('popWdw').style.display = 'block';

                mainDs.load({ params: { task: 'read' } });
                if (gridDetailDs && dataRecord && dataRecord.id) {
                    gridDetailDs.load({ params: { task: 'read_detail', id: dataRecord.id } });
                }

                Ext.getCmp('frmEditor').getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function(form, action) {
                var messages = 'Gagal menyimpan data ke database.';
                if (action && action.response && action.response.responseText) {
                    var objResponse = Ext.util.JSON.decode(action.response.responseText);
                    messages = objResponse.message || messages;
                }

                document.getElementById("popWdwTxt").innerHTML = messages;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    // ==========================================
    // 2. DIALOG POP-UP OK HANDLER
    // ==========================================
    document.getElementById('popOK').onclick = function(event) {
        // A. Hapus Baris Grid Editor Hak Akses (Lokal)
        if (deleteRow > 0) {
            if (deleteRecord && Ext.getCmp('gridEditor')) {
                Ext.getCmp('gridEditor').getStore().remove(deleteRecord);
                Ext.getCmp('gridEditor').getView().refresh();
            }
            deleteRow = 0;
            deleteRecord = null;
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
            return;
        }

        // B. Hapus Master Data User (Backend API)
        if (task == 'delete') {
            deleteDs.load({
                params: { task: "delete", id: dataRecord.id },
                callback: function(records, operation, success) {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    task = '';
                    
                    var messages = 'Data berhasil dihapus.';
                    try {
                        var textMessages = Ext.util.JSON.decode(operation.response.responseText);
                        messages = textMessages.message || messages;
                    } catch(e) {}

                    document.getElementById("popWdwTxt").innerHTML = messages;
                    document.getElementById('popWdw').style.display = 'block';

                    mainDs.load({ params: { task: 'read' } });
                    if (gridMain && gridMain.getView()) {
                        gridMain.getView().refresh(true);
                    }
                }
            });
            task = '';
            return;
        }

        document.getElementById('popWdw').style.display = 'none';
        document.getElementById('popCancel').style.display = 'none';
    };

    document.getElementById('logData').onclick = function(event) {
        if (!dataRecord) return;
        document.getElementById('logWdw').style.display = 'block';
        var userName = dataRecord.employee_name || '';
        var deptName = dataRecord.department_name || '';
        document.getElementById("logFormHeaderText").innerHTML = userName + '  [' + deptName + ']';
        
        if (typeof calculateDivHeight === 'function') {
            GridHistory.setHeight(calculateDivHeight(['logForm', 'logFormHeader']));
        }
        GridHistoryDs.load({ params: { id: dataRecord.id } });
    };

    // ==========================================
    // 3. STORES & DATA STRUCTURES
    // ==========================================
    var myMask = new Ext.LoadMask(Ext.getBody(), { msg: "Please wait..." });
    var deleteDs = new Ext.data.Store({ url: '../live/php-crud/m.user.systemCRD.php' });
    
    var employeeFields = [
        { name: 'id', type: 'numeric' },
        { name: 'login_name' },
        { name: 'employee_id' },
        { name: 'active', type: 'bool' },
        { name: 'name', type: 'string', convert: function(v, rec) { return rec.employee_name || v; } },
        { name: 'employee_name' },
        { name: 'gender', convert: function(v, rec) { return rec.gender == 'M' ? 'MALE' : 'FEMALE'; } },
        { name: 'from_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'department_name' },
        { name: 'status_name' },
        { name: 'password' },
        { name: 'confirm_password' }
    ];

    var employeeDs = new Ext.data.Store({
        url: '../live/php-crud/m.employeeCRD.php',
        baseParams: { task: 'read', limit: 999999 },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: employeeFields })
    });
    employeeDs.load();

    var formField = [
        { name: 'id' },
        { name: 'access_id' },
        { name: 'form_id' },
        { name: 'group_id' },
        { name: 'form_name' },
        { name: 'group_name' },
        { name: 'active', type: 'bool' },
        { name: 'access_level' },
        { name: 'remark' }
    ];

    mainDs = new Ext.data.Store({
        url: '../live/php-crud/m.user.systemCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read', start: 0, limit: (typeof PageSize !== 'undefined' ? PageSize : 250) },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: employeeFields })
    });
    mainDs.load();

    function form_access(val, x, store) {
        switch (parseInt(val)) {
            case 1: return 'Read';
            case 2: return 'Read/Create';
            case 3: return 'Read/Create/Update';
            case 4: return 'Read/Create/Update/Delete';
            default: return 'Read';
        }
    }

    var Addcombo = new Ext.form.ComboBox({
        id: 'Addcombo',
        typeAhead: true,
        triggerAction: 'all',
        lazyRender: true,
        mode: 'local',
        store: new Ext.data.ArrayStore({
            id: 0,
            fields: ['myId', 'displayText'],
            data: [[1, 'Read'], [2, 'Read/Create'], [3, 'Read/Create/Update'], [4, 'Read/Create/Update/Delete']]
        }),
        valueField: 'myId',
        displayField: 'displayText'
    });

    // ==========================================
    // 4. GRID EDITOR HAK AKSES FORM
    // ==========================================
    var gridEditorDs = new Ext.data.Store({
        url: '../live/php-crud/m.user.systemCRD.php',
        idProperty: 'size',
        baseParams: { task: 'read_detail' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: formField })
    });

    var addRow = Ext.data.Record.create(formField);
    var editorDeleter = new Extensive.grid.ItemDeleter();
    var editorCheckColumn = new Ext.grid.CheckColumn({ id: 'editorCheckColumn', header: 'Active', dataIndex: 'active', width: 60, fixed: true, align: 'center' });

    var gridEditor = new stdGridEditor({
        store: gridEditorDs,
        id: 'gridEditor',
        selModel: editorDeleter,
        columns: [
            { header: "Group", dataIndex: 'group_name', width: 150, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Form Name", dataIndex: 'form_name', width: 150, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Remark", dataIndex: 'remark', width: 200, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            editorCheckColumn,
            { header: "Level", dataIndex: 'access_level', width: 130, renderer: form_access, editor: Addcombo, css: typeof cssGridEditColumn !== 'undefined' ? cssGridEditColumn : '' },
            editorDeleter,
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'form_id', hidden: true },
            { dataIndex: 'access_id', hidden: true }
        ]
    });
    gridEditor.addClass('x-grid3-gridEditor');

    // ==========================================
    // 5. FORM EDITOR MAIN (LAYOUT HORIZON SEJAJAR)
    // ==========================================
    var frmEditor = new stdForm({
        url: '../live/php-crud/m.user.systemCRD.php',
        id: 'frmEditor',
        reader: new Ext.data.JsonReader({ root: 'data', fields: employeeFields }),
        renderTo: 'editorFormMain',
        items: [
            // BARIS 1: Checkbox Active (0.2) + Dropdown Name (0.8)
            {
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-bottom: 5px;',
                items: [
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        labelAlign: 'left',
                        labelWidth: 100,
                        items: [
                            {
                                xtype: 'checkbox',
                                fieldLabel: 'User is active',
                                labelSeparator: '&nbsp;&nbsp;-',
                                name: 'active',
                                id: 'AddActive',
                                cls: 'fieldCheckClass regular-checkbox'
                            }
                        ]
                    },
                    {
                        columnWidth: 0.8,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myComboField({
                                fieldLabel: 'Name',
                                id: 'name',
                                name: 'name',
                                store: employeeDs,
                                valueField: 'id',
                                displayField: 'name',
                                hiddenName: 'name',
                                allowBlank: false,
                                listeners: {
                                    select: function(combo, record, index) {
                                        Ext.getCmp('department_name').setValue(record.get('department_name'));
                                        Ext.getCmp('employee_id').setValue(record.get('id'));
                                    }
                                }
                            })
                        ]
                    }
                ]
            },

            // BARIS 2: Department (0.4) + Login Name (0.2) + Password (0.2) + Conf. Password (0.2)
            {
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-bottom: 5px;',
                items: [
                    {
                        columnWidth: 0.4,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Department',
                                id: 'department_name',
                                name: 'department_name',
                                readOnly: true
                            })
                        ]
                    },
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Login Name',
                                id: 'login_name',
                                name: 'login_name',
                                autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" }
                            })
                        ]
                    },
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Password',
                                id: 'newpwd',
                                name: 'password',
                                inputType: 'password',
                                autoCreate: { tag: "input", maxlength: 50, type: "password", size: "50", autocomplete: "off" },
                                minLength: 4,
                                maxLength: 50,
                                minLengthText: 'Password must be at least 4 characters long.',
                                maskRe: /^([a-zA-Z0-9])/
                            })
                        ]
                    },
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Conf. Password',
                                id: 'confpwd',
                                name: 'confirm_password',
                                inputType: 'password',
                                autoCreate: { tag: "input", maxlength: 50, type: "password", size: "50", autocomplete: "off" },
                                minLength: 4,
                                maxLength: 50,
                                minLengthText: 'Password must be at least 4 characters long.',
                                maskRe: /^([a-zA-Z0-9])/,
                                vtype: 'password',
                                initialPassField: 'newpwd'
                            })
                        ]
                    }
                ]
            },

            // BARIS 3: Form Access Header (0.2) + Spacer (0.7) + Button Add (0.1)
            {
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-top: 10px; margin-bottom: 5px;',
                items: [
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            { xtype: 'label', text: 'Form Access', cls: 'x-form-item my-label-GroupLbl' }
                        ]
                    },
                    {
                        columnWidth: 0.7,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [typeof ButtonSpace !== 'undefined' ? ButtonSpace : { xtype: 'spacer' }]
                    },
                    {
                        columnWidth: 0.1,
                        layout: 'form',
                        baseCls: 'x-plain',
                        style: 'text-align: right;',
                        items: [
                            {
                                xtype: 'button',
                                text: 'Add',
                                cls: 'field-button grey-field-button',
                                id: 'btnAddItem',
                                listeners: {
                                    click: function() {
                                        document.getElementById('addWdw').style.display = 'block';
                                        document.getElementById("addFormHeaderText").innerHTML = "&nbsp;&nbsp;Add Form";
                                        var ArrayItem = [];
                                        for (var v = 0; v < gridEditor.store.data.length; v++) {
                                            var listData = gridEditor.store.data.items[v];
                                            ArrayItem.push(listData.data.form_id);
                                        }
                                        
                                        var jsonParam = typeof Array2Json === 'function' ? Array2Json(ArrayItem) : Ext.util.JSON.encode(ArrayItem);
                                        gridAddEditorDs.load({ params: { task: 'addform', iteminfo: jsonParam } });
                                        
                                        var addFormEl = document.getElementById('addForm');
                                        var addFormHeaderEl = document.getElementById('addFormHeader');
                                        if (addFormEl && addFormHeaderEl) {
                                            var gridItemHeight = (addFormEl.clientHeight - addFormHeaderEl.clientHeight) - 2;
                                            gridAddEditor.setHeight(gridItemHeight);
                                        }
                                    }
                                }
                            }
                        ]
                    }
                ]
            },

            // BARIS 4: Grid Editor (Lebar Penuh)
            {
                columnWidth: 1.0,
                layout: 'form',
                baseCls: 'x-plain',
                items: [gridEditor]
            },

            // Hidden Fields
            { items: [new myTextField({ name: 'id', id: 'id', hidden: true })] },
            { items: [new myTextField({ name: 'employee_id', id: 'employee_id', hidden: true })] }
        ]
    });

    frmEditor.on('clientvalidation', function(f, valid) {
        if (!valid) { 
            if (typeof saveEditDisable === 'function') saveEditDisable(); 
        } else { 
            if (typeof saveEditEnable === 'function') saveEditEnable(); 
        }
    });

    // ==========================================
    // 6. POPUP TAMBAH FORM AKSES BARU
    // ==========================================
    document.getElementById('addSaveEdit').onclick = function(event) {
        for (var v = 0; v < gridAddEditor.store.data.length; v++) {
            var ItemData = gridAddEditor.store.data.items[v];
            if (ItemData.data.active) {
                Ext.getCmp('gridEditor').store.add(new addRow({
                    id: 0,
                    form_id: ItemData.data.form_id,
                    group_name: ItemData.data.group_name,
                    form_name: ItemData.data.form_name,
                    remark: ItemData.data.remark,
                    active: ItemData.data.active,
                    access_level: ItemData.data.access_level
                }));
            }
        }
        document.getElementById('addWdw').style.display = 'none';
    };

    var gridAddEditorDs = new Ext.data.Store({
        url: '../php-crud/m.user.systemCRD.php',
        idProperty: 'size',
        baseParams: { task: 'addform' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: formField })
    });

    var addCheckColumn = new Ext.grid.CheckColumn({
        id: 'addCheckColumn',
        header: '<img class="header-icon-grid" style="vertical-align:middle;" src="../img/icon/add_check.png"/>',
        dataIndex: 'active',
        width: 30,
        fixed: true,
        align: 'center'
    });

    var gridAddEditor = new stdGridEditor({
        store: gridAddEditorDs,
        renderTo: 'addFormGrid',
        columns: [
            { header: "Group", dataIndex: 'group_name', width: 150, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Form Name", dataIndex: 'form_name', width: 150, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Remark", dataIndex: 'remark', width: 200, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            addCheckColumn,
            { header: "Level", dataIndex: 'access_level', width: 130, editor: Addcombo, renderer: form_access },
            { dataIndex: 'form_id', hidden: true }
        ]
    });
    gridAddEditor.addClass('x-grid3-gridAddItem');

    // ==========================================
    // 7. MAIN GRID & DETAIL GRID VIEW
    // ==========================================
    var mainCheckColumn = new Ext.grid.CheckColumn({ id: 'mainCheckColumn', header: 'Active', dataIndex: 'active', width: 80, fixed: true, align: 'center', processEvent: function() { return false; } });
    var GridDtlHeight = typeof maxGridHeight === 'function' ? maxGridHeight(document.getElementById('divMainGrid')) : 400;
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.5));
    var detailHeight = (GridDtlHeight - mainHeight);

    var gridMain = new stdGridPaging({
        store: mainDs,
        height: mainHeight,
        renderTo: 'divMainGrid',
        columns: [
            {
                id: 'gridCellRibbon', dataIndex: 'active', width: 80, fixed: true, align: 'center',
                renderer: function(value, meta) {
                    if (value == true) { meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-green">Active</div>'; }
                    else { meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-grey">Idle</div>'; }
                }
            },
            { header: "#ID", dataIndex: 'login_name', width: 100, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Name ", dataIndex: 'name', width: 200, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Department", dataIndex: 'department_name', width: 200, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Hire Date", dataIndex: 'from_date', width: 150, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: "Status", dataIndex: 'status_name', width: 100, renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'employee_id', hidden: true }
        ],
        listeners: {
            cellclick: function(grid, rowIndex, columnIndex, e) {
                var record = grid.getStore().getAt(rowIndex);
                if (record) {
                    dataRecord = record.data;
                    gridDetailDs.load({ params: { task: 'read_detail', id: record.data.id } });
                    gridEditorDs.load({ params: { task: 'read_detail', id: record.data.id } });
                }
            }
        },
        pageSize: 250,
        pageStore: mainDs
    });

    var gridDetailDs = new Ext.data.Store({
        url: '../php-crud/m.user.systemCRD.php',
        baseParams: { task: 'read_detail' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: formField })
    });

    var gridDetail = new stdGrid({
        store: gridDetailDs,
        height: detailHeight,
        renderTo: 'divDetailGrid',
        columns: [
            { header: "Group", dataIndex: 'group_name', width: 200, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { header: "Form Name", dataIndex: 'form_name', width: 200, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            {
                id: 'gridCellRibbon', dataIndex: 'access_level', width: 200, fixed: true, align: 'center',
                renderer: function(value, meta) {
                    var valInt = parseInt(value);
                    if (valInt == 1) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-green-strip">Read</div>';
                    } else if (valInt == 2) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-blue-strip">Read/Create</div>';
                    } else if (valInt == 3) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-purple-strip">Read/Create/Update</div>';
                    } else if (valInt == 4) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-red-strip">Read/Create/Update/Delete</div>';
                    }
                }
            },
            { header: "Remark", dataIndex: 'remark', width: 300, align: 'left', renderer: typeof CellWrap !== 'undefined' ? CellWrap : null },
            { dataIndex: 'form_id', hidden: true },
            { dataIndex: 'id', hidden: true }
        ]
    });

    // ==========================================
    // 8. LOG HISTORY
    // ==========================================
    var GridHistoryDs = typeof historyDs !== 'undefined' ? new historyDs({ url: '../php-crud/m.user.systemCRD.php' }) : new Ext.data.Store();
    var GridHistory = typeof historyGrid !== 'undefined' ? new historyGrid({ store: GridHistoryDs, renderTo: 'logFormMain' }) : null;

    if (document.getElementById('editorWdw')) {
        document.getElementById('editorWdw').style.display = 'none';
    }
});