/*
  Document      : m.user.role.js
  Description   : Client-side Dynamic User Role & Access Matrix Management (ExtJS 3.4.1)
  Project       : Universal ERP / Inventory
*/

Ext.onReady(function() {
    Ext.QuickTips.init();

    document.getElementById("mainHeaderText").innerHTML = "User Role <em>& Access Matrix</em> ";

    var CRD = main_url + '/php-crud/roleCRD.php';

    var task = '';
    var activeRoleId = 0;
    var activeRoleName = '';
    var deleteRow = 0;
    var deleteRecord = null;

    var myMask = new Ext.LoadMask(Ext.getBody(), { msg: "Please wait..." });

    // ==========================================
    // 1. TOP TOOLBAR BUTTON ACTIONS
    // ==========================================
    document.getElementById('addData').onclick = function(event) {
        task = 'save_role';
        if (formAccess >= 3) {
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New User Role";
            Ext.getCmp('frmEditor').getForm().reset();
            Ext.getCmp('role_code').focus('', 10);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function(event) {
        task = 'update_role';
        if (formAccess >= 3) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Please select a role to edit first.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            Ext.getCmp('frmEditor').getForm().load({
                url: CRD,
                params: { id: dataRecord.id, task: 'read_roles' },
                success: function(form, action) {}
            });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit User Role: " + dataRecord.role_name;
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function(event) {
        if (formAccess == 4) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Please select a role to delete first.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            task = 'delete_role';
            deleteRow = 1;
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById("popWdwTxt").innerHTML = 'Delete role \'' + dataRecord.role_name + '\' (' + dataRecord.role_code + ')?';
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to delete this data';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function(event) {
        mainDs.load();
        gridMain.getView().refresh(true);
    };

    document.getElementById('saveEdit').onclick = function(event) {
        Ext.getCmp('frmEditor').getForm().submit({
            method: 'POST',
            params: {
                task: task,
                is_active: Ext.getCmp('AddActive').getValue() ? 'true' : 'false'
            },
            waitTitle: 'Connecting',
            waitMsg: 'Saving role data...',
            success: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById("popWdwTxt").innerHTML = objResponse.message || 'Saved successfully.';
                document.getElementById('popWdw').style.display = 'block';

                mainDs.load();
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById("popWdwTxt").innerHTML = objResponse.message || 'Failed to save role.';
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('popOK').onclick = function(event) {
        if (deleteRow > 0 && dataRecord && dataRecord.id) {
            myMask.show();
            Ext.Ajax.request({
                url: CRD,
                method: 'POST',
                params: { task: 'delete_role', id: dataRecord.id },
                success: function(response) {
                    myMask.hide();
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    deleteRow = 0;
                    var obj = Ext.util.JSON.decode(response.responseText);
                    document.getElementById("popWdwTxt").innerHTML = obj.message;
                    document.getElementById('popWdw').style.display = 'block';
                    mainDs.load();
                },
                failure: function(response) {
                    myMask.hide();
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    deleteRow = 0;
                    var obj = Ext.util.JSON.decode(response.responseText);
                    document.getElementById("popWdwTxt").innerHTML = obj.message || 'Failed to delete role.';
                    document.getElementById('popWdw').style.display = 'block';
                }
            });
            return;
        }

        document.getElementById('popWdw').style.display = 'none';
        document.getElementById('popCancel').style.display = 'none';
    };

    document.getElementById('logData').onclick = function(event) {
        if (!dataRecord || !dataRecord.id) {
            document.getElementById("popWdwTxt").innerHTML = 'Please select a role first.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        openRoleMatrix(dataRecord.id, dataRecord.role_name, dataRecord.role_code);
    };

    // ==========================================
    // 2. STORES & MODELS
    // ==========================================
    var roleFields = [
        { name: 'id', type: 'int' },
        { name: 'role_code', type: 'string' },
        { name: 'role_name', type: 'string' },
        { name: 'description', type: 'string' },
        { name: 'is_active', type: 'bool' },
        { name: 'user_count', type: 'int' },
        { name: 'form_count', type: 'int' },
        { name: 'created_at', type: 'string' },
        { name: 'updated_at', type: 'string' }
    ];

    mainDs = new Ext.data.Store({
        url: CRD,
        idProperty: 'id',
        baseParams: { task: 'read_roles' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: roleFields })
    });
    mainDs.load();

    // ==========================================
    // 3. MAIN GRID (Daftar Role)
    // ==========================================
    var cmMain = new Ext.grid.ColumnModel([
        new Ext.grid.RowNumberer(),
        { header: "ID", dataIndex: 'id', width: 50, sortable: true, hidden: true },
        { header: "Role Code", dataIndex: 'role_code', width: 140, sortable: true },
        { header: "Role Name", dataIndex: 'role_name', width: 180, sortable: true },
        { header: "Description", dataIndex: 'description', width: 280, sortable: true, renderer: CellWrap },
        { header: "Active", dataIndex: 'is_active', width: 70, align: 'center', renderer: function(v) { return v ? '<span style="color:green;font-weight:bold;">YES</span>' : '<span style="color:red;">NO</span>'; } },
        { header: "Users", dataIndex: 'user_count', width: 70, align: 'center' },
        { header: "Forms", dataIndex: 'form_count', width: 70, align: 'center' },
        { header: "Updated At", dataIndex: 'updated_at', width: 140, align: 'center' }
    ]);

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        cm: cmMain,
        stripeRows: true,
        loadMask: true,
        region: 'center',
        viewConfig: { forceFit: false },
        listeners: {
            rowclick: function(grid, rowIndex, e) {
                dataRecord = grid.getStore().getAt(rowIndex).data;
            },
            rowdblclick: function(grid, rowIndex, e) {
                dataRecord = grid.getStore().getAt(rowIndex).data;
                openRoleMatrix(dataRecord.id, dataRecord.role_name, dataRecord.role_code);
            }
        }
    });

    // Main Viewport Container
    var viewport = new Ext.Viewport({
        layout: 'border',
        items: [
            {
                region: 'north',
                height: 40,
                contentEl: 'header',
                border: false,
                margins: '0 0 0 0'
            },
            gridMain
        ]
    });

    // ==========================================
    // 4. FORM EDITOR WINDOW (Tambah/Edit Role)
    // ==========================================
    var frmEditor = new stdForm({
        url: CRD,
        id: 'frmEditor',
        reader: new Ext.data.JsonReader({ root: 'data', fields: roleFields }),
        renderTo: 'editorFormMain',
        items: [
            {
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-bottom: 10px;',
                items: [
                    {
                        columnWidth: 0.3,
                        layout: 'form',
                        baseCls: 'x-plain',
                        labelAlign: 'left',
                        labelWidth: 100,
                        items: [
                            {
                                xtype: 'checkbox',
                                fieldLabel: 'Active Role',
                                labelSeparator: '&nbsp;&nbsp;-',
                                name: 'is_active',
                                id: 'AddActive',
                                checked: true,
                                cls: 'fieldCheckClass regular-checkbox'
                            }
                        ]
                    },
                    {
                        columnWidth: 0.35,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Role Code',
                                id: 'role_code',
                                name: 'role_code',
                                allowBlank: false,
                                autoCreate: { tag: "input", maxlength: 32, type: "text", size: "32", autocomplete: "off" }
                            })
                        ]
                    },
                    {
                        columnWidth: 0.35,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myTextField({
                                fieldLabel: 'Role Name',
                                id: 'role_name',
                                name: 'role_name',
                                allowBlank: false,
                                autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" }
                            })
                        ]
                    }
                ]
            },
            {
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-bottom: 5px;',
                items: [
                    {
                        columnWidth: 1.0,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            {
                                xtype: 'textarea',
                                fieldLabel: 'Description',
                                id: 'description',
                                name: 'description',
                                height: 80,
                                anchor: '100%'
                            }
                        ]
                    }
                ]
            }
        ]
    });

    // ==========================================
    // 5. ROLE ACCESS MATRIX POPUP WINDOW (Master-Detail Grid Matrix)
    // ==========================================
    var matrixField = [
        { name: 'form_id', type: 'int' },
        { name: 'group_id', type: 'int' },
        { name: 'group_name', type: 'string' },
        { name: 'form_seq', type: 'string' },
        { name: 'form_name', type: 'string' },
        { name: 'php_url', type: 'string' },
        { name: 'remark', type: 'string' },
        { name: 'access_level', type: 'int' },
        { name: 'access_id', type: 'int' },
        { name: 'active', type: 'bool' }
    ];

    var matrixStore = new Ext.data.Store({
        url: CRD,
        idProperty: 'form_id',
        baseParams: { task: 'read_role_matrix' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: matrixField })
    });

    var LevelCombo = new Ext.form.ComboBox({
        id: 'LevelCombo',
        typeAhead: true,
        triggerAction: 'all',
        lazyRender: true,
        mode: 'local',
        store: new Ext.data.ArrayStore({
            id: 0,
            fields: ['myId', 'displayText'],
            data: [[0, 'None (No Access)'], [1, 'Read'], [2, 'Read/Create'], [3, 'Read/Create/Update'], [4, 'Full (Read/Create/Update/Delete)']]
        }),
        valueField: 'myId',
        displayField: 'displayText'
    });

    function renderLevelName(val) {
        switch(val) {
            case 1: return '<span style="color:blue;">Read</span>';
            case 2: return '<span style="color:darkgreen;">Read/Create</span>';
            case 3: return '<span style="color:purple;">Read/Create/Update</span>';
            case 4: return '<span style="color:darkred;font-weight:bold;">Full Access</span>';
            default: return '<span style="color:gray;">No Access</span>';
        }
    }

    var matrixCheckColumn = new Ext.grid.CheckColumn({
        header: 'Active',
        dataIndex: 'active',
        width: 60,
        fixed: true,
        align: 'center',
        listeners: {
            click: function(col, record, index) {
                record.set('active', !record.get('active'));
                if (record.get('active') && record.get('access_level') === 0) {
                    record.set('access_level', 1);
                } else if (!record.get('active')) {
                    record.set('access_level', 0);
                }
                matrixGrid.getView().refresh();
            }
        }
    });

    var matrixCm = new Ext.grid.ColumnModel([
        new Ext.grid.RowNumberer(),
        { header: "Form ID", dataIndex: 'form_id', width: 60, hidden: true },
        { header: "Menu Group", dataIndex: 'group_name', width: 180, sortable: true, renderer: CellWrap },
        { header: "Form Title", dataIndex: 'form_name', width: 180, sortable: true, renderer: CellWrap },
        { header: "URL / Path", dataIndex: 'php_url', width: 160, sortable: true },
        { header: "Remark", dataIndex: 'remark', width: 220, renderer: CellWrap },
        matrixCheckColumn,
        {
            header: "Access Level",
            dataIndex: 'access_level',
            width: 180,
            renderer: renderLevelName,
            editor: LevelCombo,
            css: cssGridEditColumn
        }
    ]);

    var matrixGrid = new Ext.grid.EditorGridModel({
        store: matrixStore,
        cm: matrixCm,
        stripeRows: true,
        loadMask: true,
        clicksToEdit: 1,
        region: 'center',
        viewConfig: { forceFit: false }
    });

    // Create Matrix Container Window HTML element dynamically if not present
    if (!document.getElementById('matrixWdw')) {
        var mw = document.createElement('div');
        mw.id = 'matrixWdw';
        mw.innerHTML = `
            <div id="matrixHeader" style="height: 45px; background: #CBD5E1; display: flex; align-items: center; justify-content: space-between; padding: 0 15px; border-bottom: 1px solid #94A3B8;">
                <div id="matrixTitle" style="font-weight: bold; font-size: 14px; color: #1E293B;">Access Matrix for Role</div>
                <div>
                    <button id="matrixSelectAll" class="field-button grey-field-button" style="margin-right: 8px; padding: 4px 12px; cursor:pointer;">Select All (Full)</button>
                    <button id="matrixClearAll" class="field-button grey-field-button" style="margin-right: 8px; padding: 4px 12px; cursor:pointer;">Clear All</button>
                    <button id="matrixSave" class="field-button grey-field-button" style="margin-right: 8px; background: #0284C7; color: #fff; padding: 4px 15px; cursor:pointer; font-weight: bold;">Save Matrix</button>
                    <button id="matrixSync" class="field-button grey-field-button" style="margin-right: 8px; background: #10B981; color: #fff; padding: 4px 15px; cursor:pointer; font-weight: bold;" title="Sync this role matrix to all assigned users">Sync to Users</button>
                    <button id="matrixClose" class="field-button grey-field-button" style="background: #64748B; color: #fff; padding: 4px 12px; cursor:pointer;">Close</button>
                </div>
            </div>
            <div id="matrixMainPanel" style="width: 1000px; height: 500px;"></div>
        `;
        document.body.appendChild(mw);
    }

    var matrixPanel = null;

    function openRoleMatrix(roleId, roleName, roleCode) {
        activeRoleId = roleId;
        activeRoleName = roleName;
        document.getElementById('matrixTitle').innerHTML = 'Access Matrix for Role: ' + roleName + ' (' + roleCode + ')';
        document.getElementById('matrixWdw').style.display = 'block';

        if (!matrixPanel) {
            matrixPanel = new Ext.Panel({
                renderTo: 'matrixMainPanel',
                layout: 'border',
                width: document.getElementById('matrixWdw').clientWidth,
                height: document.getElementById('matrixWdw').clientHeight - 45,
                items: [matrixGrid]
            });
        } else {
            matrixPanel.setSize(document.getElementById('matrixWdw').clientWidth, document.getElementById('matrixWdw').clientHeight - 45);
        }

        matrixStore.load({ params: { role_id: roleId } });
    }

    document.getElementById('matrixClose').onclick = function() {
        document.getElementById('matrixWdw').style.display = 'none';
    };

    document.getElementById('matrixSelectAll').onclick = function() {
        matrixStore.each(function(rec) {
            rec.set('active', true);
            rec.set('access_level', 4);
        });
        matrixGrid.getView().refresh();
    };

    document.getElementById('matrixClearAll').onclick = function() {
        matrixStore.each(function(rec) {
            rec.set('active', false);
            rec.set('access_level', 0);
        });
        matrixGrid.getView().refresh();
    };

    document.getElementById('matrixSave').onclick = function() {
        var arr = [];
        matrixStore.each(function(rec) {
            arr.push({
                form_id: rec.get('form_id'),
                access_level: rec.get('active') ? (rec.get('access_level') > 0 ? rec.get('access_level') : 1) : 0
            });
        });

        myMask.show();
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: {
                task: 'save_role_matrix',
                role_id: activeRoleId,
                matrix_data: Ext.util.JSON.encode(arr)
            },
            success: function(response) {
                myMask.hide();
                var obj = Ext.util.JSON.decode(response.responseText);
                document.getElementById("popWdwTxt").innerHTML = obj.message || 'Matrix saved successfully.';
                document.getElementById('popWdw').style.display = 'block';
                mainDs.load();
            },
            failure: function(response) {
                myMask.hide();
                var obj = Ext.util.JSON.decode(response.responseText);
                document.getElementById("popWdwTxt").innerHTML = obj.message || 'Failed to save matrix.';
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('matrixSync').onclick = function() {
        Ext.MessageBox.confirm(
            'Confirm Sync',
            'Are you sure you want to synchronize this role matrix to ALL users assigned to role "' + activeRoleName + '"? This will overwrite individual form permissions for those users.',
            function(btn) {
                if (btn === 'yes') {
                    myMask.show();
                    Ext.Ajax.request({
                        url: CRD,
                        method: 'POST',
                        params: {
                            task: 'sync_role_to_users',
                            role_id: activeRoleId
                        },
                        success: function(response) {
                            myMask.hide();
                            var obj = Ext.util.JSON.decode(response.responseText);
                            document.getElementById("popWdwTxt").innerHTML = obj.message || 'Synced successfully.';
                            document.getElementById('popWdw').style.display = 'block';
                            mainDs.load();
                        },
                        failure: function(response) {
                            myMask.hide();
                            var obj = Ext.util.JSON.decode(response.responseText);
                            document.getElementById("popWdwTxt").innerHTML = obj.message || 'Failed to sync.';
                            document.getElementById('popWdw').style.display = 'block';
                        }
                    });
                }
            }
        );
    };

    window.onresize = function() {
        if (document.getElementById('matrixWdw').style.display === 'block' && matrixPanel) {
            matrixPanel.setSize(document.getElementById('matrixWdw').clientWidth, document.getElementById('matrixWdw').clientHeight - 45);
        }
    };
});
