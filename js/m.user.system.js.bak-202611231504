/*
Document      : m.user.system.js
Description   : Client-side Management User System & Hak Akses Form
Project       : Universal ERP / Inventory
[REVISI 2026-11-23 FINAL]
  1) Layout toolbar "Form Access" simetris: Add & Apply Role sejajar di kanan (hbox)
  2) Label "Form Access" TANPA cls my-label-GroupLbl (gradient meluber) -> pakai cls custom
  3) Icon SVG trash di header kolom delete (editorDeleter) - sama dengan po.js
  4) CSS wrap header grid yang MENGENA: target #gridEditor + .x-grid3-gridEditor
     (bukan #editorFormGrid yang tidak ada di halaman ini)
*/
Ext.onReady(function() {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "User <em>List</em> ";
    var CRD = main_url + '/php-crud/m.user.systemCRD.php';

    // ==========================================
    // [FIX #4] CSS WRAP HEADER GRID - TARGET YANG BENAR
    // Grid editor di halaman ini punya id='gridEditor' dan class 'x-grid3-gridEditor'
    // (BUKAN #editorFormGrid yang tidak ada di DOM)
    // ==========================================
    (function injectGridWrapCss() {
        var st = document.createElement('style');
        st.type = 'text/css';
        st.appendChild(document.createTextNode(
            // Target: gridEditor (id) + class x-grid3-gridEditor + addFormGrid (popup)
            '#gridEditor .x-grid3-hd-inner, #addFormGrid .x-grid3-hd-inner,' +
            '.x-grid3-gridEditor .x-grid3-hd-inner, .x-grid3-gridAddItem .x-grid3-hd-inner {' +
            ' white-space:normal !important; overflow:visible !important; text-overflow:clip !important;' +
            ' word-wrap:break-word !important; overflow-wrap:break-word !important; word-break:break-word !important;' +
            ' line-height:1.25 !important; height:auto !important; min-height:34px !important;' +
            ' padding:4px 4px !important; box-sizing:border-box; vertical-align:middle !important;' +
            ' display:flex !important; flex-direction:column; justify-content:center; }' +
            '#gridEditor .x-grid3-cell-inner, #addFormGrid .x-grid3-cell-inner,' +
            '.x-grid3-gridEditor .x-grid3-cell-inner, .x-grid3-gridAddItem .x-grid3-cell-inner {' +
            ' white-space:normal !important; overflow:visible !important; text-overflow:clip !important;' +
            ' word-wrap:break-word !important; line-height:1.35 !important; height:auto !important;' +
            ' padding:3px 6px !important; box-sizing:border-box; }' +
            '#gridEditor .x-grid3-hd, #addFormGrid .x-grid3-hd,' +
            '.x-grid3-gridEditor .x-grid3-hd, .x-grid3-gridAddItem .x-grid3-hd { padding:0 !important; vertical-align:middle !important; height:auto !important; }' +
            '#gridEditor .x-grid3-row td, #addFormGrid .x-grid3-row td { height:auto !important; vertical-align:middle !important; }' +
            '#gridEditor .x-grid3-header-offset, #addFormGrid .x-grid3-header-offset { height:auto !important; }' +
            // Label "Form Access" - hapus background gradient dari my-label-GroupLbl
            '#editorForm .x-form-item.fsa-label, .fsa-label .x-form-label { background:transparent !important; border:none !important; box-shadow:none !important; }'
        ));
        document.getElementsByTagName('head')[0].appendChild(st);
    })();

    // ==========================================
    // SVG ICONS (SHOW / HIDE PASSWORD)
    // ==========================================
    var ICON_EYE_OPEN = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#22d3ee" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>';
    var ICON_EYE_CLOSED = '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#ef4444" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line></svg>';
    function togglePasswordVisibility(fieldId, btnElement) {
        var field = Ext.getCmp(fieldId);
        if (!field || !field.el || !field.el.dom) return;
        var inputEl = field.el.dom;
        if (inputEl.tagName !== 'INPUT') { inputEl = inputEl.querySelector('input') || inputEl; }
        var isCurrentlyHidden = (inputEl.type === 'password');
        if (isCurrentlyHidden) {
            inputEl.type = 'text';
            btnElement.innerHTML = ICON_EYE_OPEN;
            btnElement.title = 'Sembunyikan password';
        } else {
            inputEl.type = 'password';
            btnElement.innerHTML = ICON_EYE_CLOSED;
            btnElement.title = 'Tampilkan password';
        }
    }
    function addToggleToField(fieldId) {
        setTimeout(function() {
            var field = Ext.getCmp(fieldId);
            if (!field || !field.el || !field.el.dom) return;
            var inputEl = field.el.dom;
            if (inputEl.tagName !== 'INPUT') { inputEl = inputEl.querySelector('input') || inputEl; }
            var wrapper = inputEl;
            while (wrapper && wrapper !== document.body && !wrapper.classList.contains('x-form-field-wrap')) { wrapper = wrapper.parentNode; }
            if (!wrapper || wrapper === document.body) { wrapper = inputEl.parentNode; }
            wrapper.style.position = 'relative';
            if (document.getElementById('toggle-' + fieldId)) return;
            var toggleBtn = document.createElement('div');
            toggleBtn.id = 'toggle-' + fieldId;
            toggleBtn.style.cssText = 'position:absolute; right:8px; top:50%; transform:translateY(-50%); cursor:pointer; padding:4px; z-index:99; display:flex; align-items:center; justify-content:center; background:transparent; border:none;';
            toggleBtn.innerHTML = ICON_EYE_CLOSED;
            toggleBtn.title = 'Tampilkan password';
            toggleBtn.onclick = function(e) { e.preventDefault(); e.stopPropagation(); togglePasswordVisibility(fieldId, this); };
            wrapper.appendChild(toggleBtn);
            inputEl.style.paddingRight = '32px';
        }, 400);
    }

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
            deleteRow = 0;
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
                apply_role: (roleAppliedId > 0 && !roleGridDirty
                             && roleAppliedId === parseInt(Ext.getCmp('role_id_combo').getValue(), 10)) ? 1 : 0,
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
    document.getElementById('popOK').onclick = function(event) {
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
        { name: 'role_id', type: 'int' },
        { name: 'role_name', type: 'string' },
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
    var ROLE_CRD = main_url + '/php-crud/m.user.roleCRD.php';
    var roleDs = new Ext.data.Store({
        url: ROLE_CRD,
        baseParams: { task: 'read_roles' },
        reader: new Ext.data.JsonReader({ root: 'data', fields: [{name:'id', type:'int'}, {name:'role_name'}] })
    });
    roleDs.load();
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

    // ---- Form Access otomatis dari matriks role --------------------------------
    var roleAppliedId = 0;
    var roleGridDirty = false;
    var applyingRole  = false;
    function markRoleGridDirty() { if (!applyingRole) { roleGridDirty = true; } }
    gridEditorDs.on('beforeload', function() { roleAppliedId = 0; roleGridDirty = false; });
    gridEditorDs.on('update', markRoleGridDirty);
    gridEditorDs.on('add', markRoleGridDirty);
    gridEditorDs.on('remove', markRoleGridDirty);

    function fetchRoleMatrix(rid, onDone) {
        Ext.Ajax.request({
            url: ROLE_CRD,
            method: 'POST',
            params: { task: 'read_role_matrix', role_id: rid },
            success: function(response) {
                var obj = null;
                try { obj = Ext.util.JSON.decode(response.responseText); } catch (e) {}
                if (!obj || obj.success === false || !obj.data) {
                    Ext.Msg.alert('User Role', (obj && obj.message) || 'Gagal memuat form dari role.');
                    return;
                }
                var rows = [];
                for (var i = 0; i < obj.data.length; i++) {
                    if (parseInt(obj.data[i].access_level, 10) > 0) { rows.push(obj.data[i]); }
                }
                onDone(rows);
            },
            failure: function(response) {
                var msg = 'Gagal memuat form dari role.';
                try { msg = Ext.util.JSON.decode(response.responseText).message || msg; } catch (e) {}
                Ext.Msg.alert('User Role', msg);
            }
        });
    }
    function applyRoleReplaceAll(rid, rows) {
        var existing = {};
        gridEditorDs.each(function(r) {
            var rowId = parseInt(r.get('id'), 10);
            if (rowId > 0) { existing[r.get('form_id')] = rowId; }
        });
        for (var i = 0; i < rows.length; i++) {
            rows[i].id = existing[rows[i].form_id] || '';
        }
        applyingRole = true;
        gridEditorDs.loadData({ data: rows, totalRecord: rows.length });
        applyingRole = false;
        roleAppliedId = rid;
        roleGridDirty = false;
    }
    function applyRoleAccessOnly(rid, rows) {
        var levelByForm = {};
        for (var i = 0; i < rows.length; i++) {
            levelByForm[rows[i].form_id] = parseInt(rows[i].access_level, 10);
        }
        var changed = 0, notInRole = 0;
        gridEditorDs.each(function(r) {
            var lvl = levelByForm[r.get('form_id')];
            if (lvl > 0) {
                if (parseInt(r.get('access_level'), 10) !== lvl) {
                    r.set('access_level', lvl);
                    changed++;
                }
            } else {
                notInRole++;
            }
        });
        roleAppliedId = 0;
        roleGridDirty = true;
        gridEditor.getView().refresh();
        Ext.Msg.alert('User Role',
            changed + ' form diperbarui levelnya.' +
            (notInRole > 0 ? '<br>' + notInRole + ' form di daftar tidak ada di role ini dan tidak diubah.' : ''));
    }
    function applyRoleToGrid(rid, askMode) {
        rid = parseInt(rid, 10);
        if (!rid) { return; }
        fetchRoleMatrix(rid, function(rows) {
            var current = gridEditorDs.getCount();
            if (askMode && current > 0) {
                Ext.Msg.show({
                    title: 'Apply Role',
                    msg: 'Form Access user ini sudah berisi ' + current + ' form.<br><br>' +
                         '<b>Update All</b>: ganti daftar form dan level sesuai role (' + rows.length + ' form).<br>' +
                         '<b>Access Only</b>: pertahankan daftar form, hanya ubah level form yang ada di role.',
                    width: 440,
                    icon: Ext.Msg.QUESTION,
                    buttons: { yes: 'Update All', no: 'Access Only', cancel: 'Cancel' },
                    fn: function(btn) {
                        if (btn === 'yes') { applyRoleReplaceAll(rid, rows); }
                        else if (btn === 'no') { applyRoleAccessOnly(rid, rows); }
                    }
                });
            } else {
                applyRoleReplaceAll(rid, rows);
            }
        });
    }

    // ==========================================
    // [FIX #3] EDITOR DELETER DENGAN SVG ICON TRASH
    // ==========================================
    var editorDeleter = new Extensive.grid.ItemDeleter({
        header: '<svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" ' +
                'fill="none" stroke="#e05353" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" ' +
                'style="vertical-align:middle;">' +
                '<polyline points="3 6 5 6 21 6"/>' +
                '<path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
                '<path d="M10 11v6M14 11v6"/>' +
                '<path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/>' +
                '</svg>'
    });

    var editorCheckColumn = new Ext.grid.CheckColumn({
        id: 'editorCheckColumn',
        header: 'Active',
        dataIndex: 'active',
        width: 72,  // [FIX] 60 -> 72 agar "Active" tidak jadi "ACT..."
        fixed: true,
        align: 'center'
    });

 

    var gridEditor = new stdGridEditor({
        store: gridEditorDs,
        id: 'gridEditor',
        selModel: editorDeleter,
        columns: [
            { header: "Group", dataIndex: 'group_name', width: 180, align: 'left', renderer: CellWrap },
            { header: "Form Name", dataIndex: 'form_name', width: 200, align: 'left', renderer: CellWrap },
            { header: "Remark", dataIndex: 'remark', width: 250, align: 'left', renderer: CellWrap },
            editorCheckColumn,
            { header: "Level", dataIndex: 'access_level', width: 220, renderer: form_access, editor: Addcombo, css: cssGridEditColumn },
            editorDeleter,
            { dataIndex: 'id', hidden: true },
            { dataIndex: 'form_id', hidden: true },
            { dataIndex: 'access_id', hidden: true }
        ]
    });


    gridEditor.addClass('x-grid3-gridEditor');

    // ==========================================
    // 5. FORM EDITOR MAIN
    // ==========================================
    var frmEditor = new stdForm({
        url: CRD,
        id: 'frmEditor',
        reader: new Ext.data.JsonReader({ root: 'data', fields: employeeFields }),
        renderTo: 'editorFormMain',
        items: [
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
                        columnWidth: 0.4,
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
                    },
                    {
                        columnWidth: 0.4,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            new myComboField({
                                fieldLabel: 'User Role',
                                id: 'role_id_combo',
                                name: 'role_id',
                                hiddenName: 'role_id',
                                store: roleDs,
                                valueField: 'id',
                                displayField: 'role_name',
                                allowBlank: true,
                                emptyText: '-- No Role assigned --',
                                listeners: {
                                    select: function(combo) { applyRoleToGrid(combo.getValue(), true); }
                                }
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
                            {
                                xtype: 'textfield',
                                fieldLabel: 'Password',
                                id: 'newpwd',
                                name: 'password',
                                inputType: 'password',
                                autoCreate: { tag: "input", maxlength: 50, type: "password", size: "50", autocomplete: "off" },
                                minLength: 4,
                                maxLength: 50,
                                minLengthText: 'Password must be at least 4 characters long.',
                                maskRe: /^([a-zA-Z0-9])/,
                                listeners: {
                                    'render': function(field) { addToggleToField('newpwd'); }
                                }
                            }
                        ]
                    },
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [
                            {
                                xtype: 'textfield',
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
                                initialPassField: 'newpwd',
                                listeners: {
                                    'render': function(field) { addToggleToField('confpwd'); }
                                }
                            }
                        ]
                    }
                ]
            },

            // ==========================================
            // [FIX #1 & #2] TOOLBAR FORM ACCESS - SIMETRIS + TANPA GRADIENT MELUBER
            // Label kiri (flex:1), 2 tombol sejajar di kanan (hbox pack:end)
            // Label TIDAK pakai cls my-label-GroupLbl (sumber gradient)
            // ==========================================
            {
                columnWidth: 1.0,
                layout: 'form',
                baseCls: 'x-plain',
                style: 'margin-top:10px; margin-bottom:8px;',
                items: [{
                    xtype: 'container',
                    layout: { type: 'hbox', defaultAlign: 'middle' },
                    style: 'padding:0 4px;',
                    items: [
                        {
                            xtype: 'label',
                            text: 'Form Access',
                            cls: 'fsa-label',  // [FIX #2] custom cls, BUKAN my-label-GroupLbl
                            flex: 1,
                            style: 'background:transparent !important; border:none !important; box-shadow:none !important;' +
                                   ' padding:4px 0 !important; margin:0 !important; width:auto !important;' +
                                   ' font-weight:800 !important; font-size:13px !important; color:#22d3ee !important;' +
                                   ' letter-spacing:.5px; text-transform:uppercase;'
                        },
                        {
                            xtype: 'button',
                            text: '➕ Add Form',
                            id: 'btnAddItem',
                            cls: 'field-button grey-field-button',
                            style: 'background:#475569 !important; color:#fff !important; border:1px solid #64748b !important;' +
                                   ' border-radius:6px !important; padding:6px 14px !important; font-size:12px !important;' +
                                   ' font-weight:600 !important; height:32px !important;',
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
                        },
                        {
                            xtype: 'button',
                            text: '🎭 Apply Role',
                            id: 'btnApplyRole',
                            cls: 'field-button grey-field-button',
                            style: 'background:#10B981 !important; color:#fff !important; border:1px solid #059669 !important;' +
                                   ' border-radius:6px !important; padding:6px 14px !important; font-size:12px !important;' +
                                   ' font-weight:600 !important; height:32px !important; margin-left:8px !important;',
                            listeners: {
                                click: function() {
                                    var rid = Ext.getCmp('role_id_combo').getValue();
                                    if (!rid) { Ext.Msg.alert('Warning', 'Please select a role first.'); return; }
                                    applyRoleToGrid(rid, true);
                                }
                            }
                        }
                    ]
                }]
            },

            {
                columnWidth: 1.0,
                layout: 'form',
                baseCls: 'x-plain',
                items: [gridEditor]
            },
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
            { header: "Role", dataIndex: 'role_name', width: 150, renderer: CellWrap },
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