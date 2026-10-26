/*
  Document      : m.user.system.js
  Description   : Client-side Management User System & Hak Akses Form
  Project       : Universal ERP / Inventory
*/

Ext.onReady(function() {
    Ext.QuickTips.init();

    document.getElementById("mainHeaderText").innerHTML = "User <em>List</em> ";

    var CRD = main_url + '/php-crud/m.user.systemCRD.php';

    // ==========================================
    // 1. EVENT HANDLERS TOP TOOLBAR BUTTONS
    // ==========================================
    document.getElementById('addData').onclick = function(event) {
        task = '';
        if (formAccess == 2 || formAccess == 3 || formAccess == 4) {
            task = 'create';
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New User";
            
            var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom - 5;
            gridEditor.setHeight(gridEditorHeight);
            
            gridDetailDs.load({ params: { user_id: 0 } });
            gridEditorDs.load({ params: { user_id: 0 } });
            Ext.getCmp('frmEditor').getForm().reset();
            Ext.getCmp('name').focus('', 10);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function(event) {
        task = '';
        if (formAccess == 3 || formAccess == 4) {
            task = 'update';
            Ext.getCmp('frmEditor').getForm().load({
                url: CRD,
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function(form, action) {}
            });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit User";
            
            var gridEditorHeight = editorForm.getBoundingClientRect().bottom - btnAddItem.getBoundingClientRect().bottom - 5;
            gridEditor.setHeight(gridEditorHeight);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
        task = 'update';
    };

    document.getElementById('deleteData').onclick = function(event) {
        if (formAccess == 4) {
            task = 'delete';
            deleteRow = 0; // Pastikan bukan penghapusan baris grid editor
            var userName = dataRecord.employee_name;
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById("popWdwTxt").innerHTML = 'delete \'' + userName + '\' ?';
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to delete this data';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function(event) {
        window.open("../pdf/m.form.name.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    document.getElementById('saveEdit').onclick = function(event) {
        var arrForm = new Ext.data.ArrayStore({ fields: formField });
        arrForm.loadData(EmptyArr);
        for (i = 0; i < gridEditorDs.data.length; i++) {
            var dataFormGrid = gridEditorDs.data.items[i];
            arrForm.add(new addRow({
                id: dataFormGrid.data.id,
                form_id: dataFormGrid.data.form_id,
                access_level: dataFormGrid.data.access_level
            }));
        }

        Ext.getCmp('frmEditor').getForm().submit({
            method: 'POST',
            params: {
                task: task,
                u_active: Ext.getCmp('AddActive').getValue(),
                form_access: DsArray2Json(arrForm)
            },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                var messages = objResponse.message;

                document.getElementById("popWdwTxt").innerHTML = messages;
                document.getElementById('popWdw').style.display = 'block';

                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                var messages = objResponse.message;

                document.getElementById("popWdwTxt").innerHTML = messages;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    // ==========================================
    // 2. DIALOG POP-UP OK HANDLER (DISESUAIKAN)
    // ==========================================
    document.getElementById('popOK').onclick = function(event) {
        // A. Hapus Baris Grid Editor (Lokal)
        if (deleteRow > 0) {
            if (deleteRecord && Ext.getCmp('gridEditor')) {
                Ext.getCmp('gridEditor').getStore().remove(deleteRecord);
                Ext.getCmp('gridEditor').getView().refresh();
            }
            deleteRow = 0;
            deleteRecord = null;
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
            return; // Hentikan eksekusi agar tidak memicu hapus master user
        }

        // B. Hapus Master Data User (Backend API)
        if (task == 'delete') {
            deleteDs.load({
                params: { task: "delete", id: dataRecord.id },
                callback: function(records, operation, success) {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    task = '';
                    var textMessages = Ext.util.JSON.decode(operation.callback.caller.caller.arguments[2].responseText);
                    var messages = textMessages.message;

                    document.getElementById("popWdwTxt").innerHTML = messages;
                    document.getElementById('popWdw').style.display = 'block';

                    mainDs.load();
                    gridMain.getView().refresh(true);
                }
            });
            task = '';
            return;
        }

        document.getElementById('popWdw').style.display = 'none';
        document.getElementById('popCancel').style.display = 'none';
    };

    document.getElementById('logData').onclick = function(event) {
        document.getElementById('logWdw').style.display = 'block';
        var userName = dataRecord.employee_name;
        document.getElementById("logFormHeaderText").innerHTML = userName + '  [' + dataRecord.department_name + ']';
        GridHistory.setHeight(calculateDivHeight(['logForm', 'logFormHeader']));
        GridHistoryDs.load({ params: { id: dataRecord.id } });
    };

    // ==========================================
    // 3. STORES & DATA STRUCTURES
    // ==========================================
    var myMask = new Ext.LoadMask(Ext.getBody(), { msg: "Please wait..." });
    var deleteDs = new Ext.data.Store({ url: CRD });
    
    var employeeFields = [
        { name: 'id', type: 'numeric' },
        { name: 'login_name' },
        { name: 'employee_id' },
        { name: 'active', type: 'bool' },
        { name: 'name', type: 'string', convert: function(v, rec) { return rec.employee_name; } },
        { name: 'employee_name' },
        { name: 'gender', convert: function(v, rec) { return rec.gender == 'M' ? 'MALE' : 'FEMALE'; } },
        { name: 'from_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'department_name' },
        { name: 'status_name' },
        { name: 'password' },
        { name: 'confirm_password' }
    ];

    var employeeDs = new Ext.data.Store({
        url: '../dev/php-crud/m.employeeCRD.php',
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
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: employeeFields })
    });
    mainDs.load();

    function form_access(val, x, store) {
        switch (val) {
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
        url: CRD,
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
            { header: "Group", dataIndex: 'group_name', width: 150, align: 'left', renderer: CellWrap },
            { header: "Form Name", dataIndex: 'form_name', width: 150, align: 'left', renderer: CellWrap },
            { header: "Remark", dataIndex: 'remark', width: 200, align: 'left', renderer: CellWrap },
            editorCheckColumn,
            { header: "Level", dataIndex: 'access_level', width: 130, renderer: form_access, editor: Addcombo, css: cssGridEditColumn },
            editorDeleter,
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'form_id', hidden: true },
            { dataIndex: 'access_id', hidden: true }
        ]
    });
    gridEditor.addClass('x-grid3-gridEditor');

    // ==========================================
    // 5. FORM EDITOR MAIN (DIRAPIKAN LAYOUT-NYA)
    // ==========================================
// Editor Form (Perbaikan Struktur Layout Horizontal Sejajar)
  var frmEditor = new stdForm({
    url: CRD,
    id: 'frmEditor',
    reader: new Ext.data.JsonReader({ root: 'data', fields: employeeFields }),
    renderTo: 'editorFormMain',
    items: [
      // =========================================================
      // BARIS 1: Checkbox Active (0.2) + Dropdown Name (0.8)
      // =========================================================
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
                  }
                }
              })
            ]
          }
        ]
      },

      // =========================================================
      // BARIS 2: Department (0.4) + Login Name (0.2) + Password (0.2) + Conf. Password (0.2)
      // =========================================================
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

      // =========================================================
      // BARIS 3: Form Access Header (0.2) + Spacer (0.7) + Button Add (0.1)
      // =========================================================
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
            items: [ButtonSpace]
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
                    gridEditorCheck = 0;
                    document.getElementById('addWdw').style.display = 'block';
                    document.getElementById("addFormHeaderText").innerHTML = "&nbsp;&nbsp;Add Form";
                    ArrayItem = [];
                    for (v = 0; v < gridEditor.store.data.length; v++) {
                      listData = gridEditor.store.data.items[v];
                      ArrayItem.push(listData.data.form_id);
                    }
                    gridAddEditorDs.load({ params: { iteminfo: Array2Json(ArrayItem) } });
                    var gridItemHeight = (document.getElementById('addForm').clientHeight - document.getElementById('addFormHeader').clientHeight) - 2;
                    gridAddEditor.setHeight(gridItemHeight);
                  }
                }
              }
            ]
          }
        ]
      },

      // =========================================================
      // BARIS 4: Grid Editor (1.0 Lebar Penuh)
      // =========================================================
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
        if (!valid) { saveEditDisable(); } else { saveEditEnable(); }
    });

    // ==========================================
    // 6. POPUP TAMBAH FORM AKSES BARU
    // ==========================================
    document.getElementById('addSaveEdit').onclick = function(event) {
        for (v = 0; v < gridAddEditor.store.data.length; v++) {
            ItemData = gridAddEditor.store.data.items[v];
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
        url: CRD,
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
            { header: "Group", dataIndex: 'group_name', width: 150, align: 'left', renderer: CellWrap },
            { header: "Form Name", dataIndex: 'form_name', width: 150, align: 'left', renderer: CellWrap },
            { header: "Remark", dataIndex: 'remark', width: 200, align: 'left', renderer: CellWrap },
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
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
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
            { header: "#ID", dataIndex: 'login_name', width: 100, renderer: CellWrap },
            { header: "Name ", dataIndex: 'name', width: 200, renderer: CellWrap },
            { header: "Department", dataIndex: 'department_name', width: 200, renderer: CellWrap },
            { header: "Hire Date", dataIndex: 'from_date', width: 150, renderer: Ext.util.Format.dateRenderer('d M Y') },
            { header: "Status", dataIndex: 'status_name', width: 100, renderer: CellWrap },
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'employee_id', hidden: true }
        ],
        listeners: {
            cellclick: function(grid, rowIndex, columnIndex, e) {
                var record = grid.getStore().getAt(rowIndex).data;
                gridDetailDs.load({ params: { id: record.id } });
                gridEditorDs.load({ params: { id: record.id } });
            }
        },
        pageSize: 250,
        pageStore: mainDs
    });

    var gridDetailDs = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_detail' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: formField })
    });

    var gridDetail = new stdGrid({
        store: gridDetailDs,
        height: detailHeight,
        renderTo: 'divDetailGrid',
        columns: [
            { header: "Group", dataIndex: 'group_name', width: 200, align: 'left', renderer: CellWrap },
            { header: "Form Name", dataIndex: 'form_name', width: 200, align: 'left', renderer: CellWrap },
            {
                id: 'gridCellRibbon', dataIndex: 'access_level', width: 200, fixed: true, align: 'center',
                renderer: function(value, meta) {
                    if (parseInt(value) == 1) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-green-strip">Read</div>';
                    } else if (parseInt(value) == 2) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-blue-strip">Read/Create</div>';
                    } else if (parseInt(value) == 3) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-purple-strip">Read/Create/Update</div>';
                    } else if (parseInt(value) == 4) {
                        meta.attr = 'style= "padding:4px 0px !important;"'; return '<div class="grid-ribbon ribbon-red-strip">Read/Create/Update/Delete</div>';
                    }
                }
            },
            { header: "Remark", dataIndex: 'remark', width: 300, align: 'left', renderer: CellWrap },
            { dataIndex: 'form_id', hidden: true },
            { dataIndex: 'id', hidden: true }
        ]
    });

    // ==========================================
    // 8. LOG HISTORY
    // ==========================================
    var GridHistoryDs = new historyDs({ url: CRD });
    var GridHistory = new historyGrid({ store: GridHistoryDs, renderTo: 'logFormMain' });

    document.getElementById('editorWdw').style.display = 'none';
});