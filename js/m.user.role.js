/*
  Document      : m.user.role.js
  Description   : Client-side Dynamic User Role & Access Matrix Management (ExtJS 3.4.1)
  Project       : Universal ERP / Inventory
*/
Ext.onReady(function() {
    Ext.QuickTips.init();

    document.getElementById("mainHeaderText").innerHTML = "User Role <em>& Access Matrix</em> ";

    var CRD = main_url + '/php-crud/m.user.roleCRD.php';

    var task = '';
    var activeRoleId = 0;
    var activeRoleName = '';
    var pendingNewRoleEdit = true;
    var pendingNewRoleId = 0;     // role yang baru dibuat -> otomatis dipilih & matriksnya dibuka dalam mode edit
    var matrixEditMode = false;   // matriks hanya bisa diubah setelah tombol Edit ditekan
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
            showRoleFormAccess(true);
            Ext.getCmp('role_code').focus('', 10);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function(event) {
        if (formAccess >= 3) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Please select a role to edit first.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            if (activeRoleId !== dataRecord.id) {
                loadRoleMatrix(dataRecord.id, dataRecord.role_name, dataRecord.role_code);
            }
            setMatrixEditMode(true);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    // Edit data induk role (kode, nama, deskripsi, status) - dibuka dari tombol "Edit Info" di panel matriks.
    function openRoleInfoEditor() {
        task = 'update_role';
        Ext.getCmp('frmEditor').getForm().setValues({
            role_code: dataRecord.role_code,
            role_name: dataRecord.role_name,
            description: dataRecord.description
        });
        Ext.getCmp('AddActive').setValue(!!dataRecord.is_active);
        showRoleFormAccess(false);          // Edit Info hanya data induk; matriks diedit di panel bawah
        document.getElementById('editorWdw').style.display = 'block';
        document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit User Role: " + dataRecord.role_name;
    }

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
                id: (task === 'update_role' && dataRecord) ? dataRecord.id : 0,
                is_active: Ext.getCmp('AddActive').getValue() ? 'true' : 'false'
            },
            waitTitle: 'Connecting',
            waitMsg: 'Saving role data...',
            success: function(form, action) {
                var objResponse = Ext.util.JSON.decode(action.response.responseText);
                var okMsg = objResponse.message || 'Saved successfully.';
                var finishSave = function(extraMsg) {
                    document.getElementById("popWdwTxt").innerHTML = okMsg + (extraMsg || '');
                    document.getElementById('popWdw').style.display = 'block';
                    mainDs.load();
                    frmEditor.getForm().reset();
                    roleAccessStore.removeAll();
                    document.getElementById('editorWdw').style.display = 'none';
                };

                if (task === 'save_role' && objResponse.id) {
                    var newId = parseInt(objResponse.id, 10);
                    var arr = [];
                    roleAccessStore.each(function(rec) {
                        if (parseInt(rec.get('access_level'), 10) > 0) {
                            arr.push({ form_id: rec.get('form_id'), access_level: parseInt(rec.get('access_level'), 10) });
                        }
                    });
                    pendingNewRoleId = newId;
                    if (arr.length === 0) {
                        pendingNewRoleEdit = true;      // belum ada form: langsung buka matriks dalam mode edit
                        finishSave('<br><br>Atur form &amp; level akses role ini pada <b>Access Matrix</b> di bawah (bisa pakai <b>Copy From...</b>), lalu klik <b>Save Matrix</b>.');
                    } else {
                        pendingNewRoleEdit = false;
                        Ext.Ajax.request({
                            url: CRD,
                            method: 'POST',
                            params: { task: 'save_role_matrix', role_id: newId, matrix_data: Ext.util.JSON.encode(arr) },
                            success: function(resp) {
                                var o = null;
                                try { o = Ext.util.JSON.decode(resp.responseText); } catch (e) {}
                                if (o && o.success === false) {
                                    pendingNewRoleEdit = true;
                                    finishSave('<br><br>Namun form access gagal disimpan: ' + (o.message || '') + '<br>Atur ulang pada Access Matrix di bawah.');
                                } else {
                                    finishSave('<br>' + arr.length + ' form access ikut tersimpan.');
                                }
                            },
                            failure: function(resp) {
                                var msg = '';
                                try { msg = Ext.util.JSON.decode(resp.responseText).message || ''; } catch (e) {}
                                pendingNewRoleEdit = true;
                                finishSave('<br><br>Namun form access gagal disimpan: ' + msg + '<br>Atur ulang pada Access Matrix di bawah.');
                            }
                        });
                    }
                } else {
                    finishSave('');
                }
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
        loadRoleMatrix(dataRecord.id, dataRecord.role_name, dataRecord.role_code);
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
        { id: 'colDesc', header: "Description", dataIndex: 'description', width: 280, sortable: true, renderer: CellWrap },
        { header: "Active", dataIndex: 'is_active', width: 70, align: 'center', renderer: function(v) { return v ? '<span style="font-weight:bold">YES</span>' : '<span >NO</span>'; } },
        { header: "Users", dataIndex: 'user_count', width: 70, align: 'center' },
        { header: "Forms", dataIndex: 'form_count', width: 70, align: 'center' },
        { header: "Updated At", dataIndex: 'updated_at', width: 140, align: 'center' }
    ]);

    // Grid dirender ke div template (sama seperti halaman lain), bukan Ext.Viewport.
    var gridHost = document.getElementById('divMainGrid');
    if (!gridHost) {
        gridHost = document.createElement('div');
        gridHost.id = 'divMainGrid';
        document.body.appendChild(gridHost);
    }
    // Tinggi total area grid; dibagi header (daftar role) ~45% dan detail (access matrix) sisanya.
    function totalGridHeight() {
        return (typeof maxGridHeight === 'function')
            ? maxGridHeight(gridHost)
            : Math.max(400, window.innerHeight - 160);
    }
    var DETAIL_BAR_H = 52;   // tinggi judul + tombol detail
    function splitHeights() {
        var total = totalGridHeight();
        var main = Math.max(180, Math.round(total * 0.45));
        var detail = Math.max(160, total - main - DETAIL_BAR_H - 30);
        return { main: main, detail: detail };
    }

    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        cm: cmMain,
        stripeRows: true,
        loadMask: true,
        height: splitHeights().main,
        renderTo: gridHost,
        autoExpandColumn: 'colDesc',
        viewConfig: { forceFit: false },
        listeners: {
            rowclick: function(grid, rowIndex, e) {
                selectRoleRow(grid, rowIndex);
            },
            rowdblclick: function(grid, rowIndex, e) {
                selectRoleRow(grid, rowIndex);
            }
        }
    });

    // Setelah daftar role dimuat ulang (mis. setelah Save/Sync), pilih lagi role yang aktif.
    mainDs.on('load', function() {
        if (pendingNewRoleId) {
            var newId = pendingNewRoleId;
            pendingNewRoleId = 0;
            var ni = mainDs.findBy(function(r) { return parseInt(r.get('id'), 10) === newId; });
            if (ni >= 0) {
                gridMain.getSelectionModel().selectRow(ni);
                gridMain.getView().focusRow(ni);
                dataRecord = mainDs.getAt(ni).data;
                loadRoleMatrix(dataRecord.id, dataRecord.role_name, dataRecord.role_code);
                setMatrixEditMode(pendingNewRoleEdit);
                return;
            }
        }
        if (!activeRoleId) return;
        var idx = mainDs.findBy(function(r) { return r.get('id') === activeRoleId; });
        if (idx >= 0) {
            gridMain.getSelectionModel().selectRow(idx);
        } else {
            clearRoleMatrix();
        }
    });


    // ==========================================
    // 3b. FORM ACCESS DI DALAM FORM "NEW USER ROLE" (Add / Copy From / grid form + level)
    // ==========================================
    var accessFields = [
        { name: 'form_id', type: 'int' },
        { name: 'group_id', type: 'int' },
        { name: 'group_name', type: 'string' },
        { name: 'form_seq', type: 'string' },
        { name: 'form_name', type: 'string' },
        { name: 'php_url', type: 'string' },
        { name: 'remark', type: 'string' },
        { name: 'access_level', type: 'int' },
        { name: 'active', type: 'bool' }
    ];

    var TRASH_SVG = 'data:image/svg+xml;utf8,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="#8FA7BF" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><path d="M10 11v6M14 11v6"/><path d="M9 6V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2"/></svg>');

    function makeLevelCombo(cmpId) {
        return new Ext.form.ComboBox({
            id: cmpId,
            typeAhead: true,
            triggerAction: 'all',
            lazyRender: true,
            mode: 'local',
            store: new Ext.data.ArrayStore({
                id: 0,
                fields: ['myId', 'displayText'],
                data: [[1, 'Read'], [2, 'Read/Create'], [3, 'Read/Create/Update'], [4, 'Full (Read/Create/Update/Delete)']]
            }),
            valueField: 'myId',
            displayField: 'displayText'
        });
    }

    // daftar form + level untuk role yang sedang dibuat (belum tersimpan sampai tombol Save form)
    var roleAccessStore = new Ext.data.Store({
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', idProperty: 'form_id', fields: accessFields })
    });

    var roleAccessGrid = new stdGridEditor({
        store: roleAccessStore,
        height: 240,
        anchor: '100%',
        clicksToEdit: 1,
        loadMask: true,
        autoExpandColumn: 'colRoleAccRemark',
        columns: [
            new Ext.grid.RowNumberer(),
            { header: "Menu Group", dataIndex: 'group_name', width: 170, sortable: true, renderer: CellWrap },
            { header: "Form Title", dataIndex: 'form_name', width: 200, sortable: true, renderer: CellWrap },
            { header: "URL / Path", dataIndex: 'php_url', width: 190, sortable: true },
            { id: 'colRoleAccRemark', header: "Remark", dataIndex: 'remark', width: 200, renderer: CellWrap },
            {
                header: "Access Level",
                dataIndex: 'access_level',
                width: 190,
                renderer: renderLevelName,
                editor: makeLevelCombo('RoleFormLevelCombo'),
                css: cssGridEditColumn
            },
            {
                header: '<img src="' + TRASH_SVG + '" style="vertical-align:middle;width:16px;height:16px" title="Hapus baris"/>',
                dataIndex: 'form_id',
                width: 40,
                fixed: true,
                sortable: false,
                menuDisabled: true,
                align: 'center',
                renderer: function() {
                    return '<img class="role-row-del" src="' + TRASH_SVG + '" style="cursor:pointer;width:16px;height:16px;vertical-align:middle" title="Hapus baris"/>';
                }
            }
        ],
        listeners: {
            cellclick: function(grid, rowIndex, colIndex, e) {
                if (e.getTarget('.role-row-del')) {
                    roleAccessStore.removeAt(rowIndex);
                    grid.getView().refresh();
                }
            }
        }
    });

    function sizeRoleAccessGrid() {
        var el = roleAccessGrid.getEl();
        if (!el) { return; }
        var top = el.dom.getBoundingClientRect().top;
        var bottom = document.getElementById('editorWdw').getBoundingClientRect().bottom;
        roleAccessGrid.setHeight(Math.max(160, bottom - top - 12));
        roleAccessGrid.getView().refresh();
    }

    function showRoleFormAccess(show) {
        Ext.getCmp('roleFormAccessBox').setVisible(show);
        if (show) {
            roleAccessStore.removeAll();
            sizeRoleAccessGrid();
        }
    }

    // ---- Copy From: salin form + level dari role lain ----
    function copyIntoNewRole(srcId, srcName) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_role_matrix', role_id: srcId },
            success: function(response) {
                try {
                    var obj = null;
                    try { obj = Ext.util.JSON.decode(response.responseText); } catch (e) {}
                    if (!obj || obj.success === false || !obj.data) {
                        Ext.Msg.alert('Copy From', (obj && obj.message) || 'Gagal memuat matriks role sumber.');
                        return;
                    }
                    var added = 0;
                    roleAccessStore.removeAll();
                    for (var i = 0; i < obj.data.length; i++) {
                        var d = obj.data[i];
                        if (parseInt(d.access_level, 10) > 0) {
                            d.access_level = parseInt(d.access_level, 10);
                            d.form_id = parseInt(d.form_id, 10);
                            d.active = true;
                            roleAccessStore.add(new roleAccessStore.recordType(d, d.form_id));
                            added++;
                        }
                    }
                    roleAccessGrid.getView().refresh();
                    sizeRoleAccessGrid();
                    if (added === 0) {
                        Ext.Msg.alert('Copy From', 'Role "' + srcName + '" belum memiliki form access (kolom Forms = 0), jadi tidak ada yang disalin.');
                    }
                } catch (err) {
                    Ext.Msg.alert('Copy From', 'Error saat mengisi grid: ' + err.message);
                }
            },
            failure: function(response) {
                var msg = 'Gagal memuat matriks role sumber (HTTP ' + response.status + ').';
                try { msg = Ext.util.JSON.decode(response.responseText).message || msg; } catch (e) {}
                Ext.Msg.alert('Copy From', msg);
            }
        });
    }

    function openCopyMenu(btn) {
        var items = [];
        mainDs.each(function(r) {
            items.push({
                text: r.get('role_name') + ' (' + r.get('role_code') + ')',
                roleId: r.get('id'),
                roleName: r.get('role_name'),
                handler: function(item) {
                    if (roleAccessStore.getCount() > 0) {
                        Ext.Msg.confirm('Copy From', 'Ganti daftar form yang sudah ada dengan milik role ini?', function(b) {
                            if (b === 'yes') { copyIntoNewRole(item.roleId, item.roleName); }
                        });
                    } else {
                        copyIntoNewRole(item.roleId, item.roleName);
                    }
                }
            });
        });
        if (!items.length) { Ext.Msg.alert('Copy From', 'Belum ada role lain untuk disalin.'); return; }
        new Ext.menu.Menu({ items: items }).show(btn.getEl());
    }

    // ---- Add: pilih form dari daftar semua form aktif ----
    var catalogStore = new Ext.data.Store({
        url: CRD,
        baseParams: { task: 'read_role_matrix', role_id: 0 },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', idProperty: 'form_id', fields: accessFields })
    });
    var pickSm = new Ext.grid.CheckboxSelectionModel();
    var pickGrid = null;

    function applyPickFilter() {
        var q = (document.getElementById('roleAddFormSearch').value || '').toLowerCase();
        catalogStore.filterBy(function(r) {
            if (roleAccessStore.findExact('form_id', r.get('form_id')) >= 0) { return false; }   // sudah ada di daftar role
            if (!q) { return true; }
            return (r.get('form_name') + ' ' + r.get('group_name') + ' ' + r.get('php_url')).toLowerCase().indexOf(q) >= 0;
        });
    }

    function closeRoleFormPicker() {
        document.getElementById('roleAddFormWdw').style.display = 'none';
    }

    function openRoleFormPicker() {
        var w = document.getElementById('roleAddFormWdw');
        if (!w) {
            w = document.createElement('div');
            w.id = 'roleAddFormWdw';
            w.style.cssText = 'display:none;position:fixed;top:70px;left:8%;width:84%;height:calc(100% - 140px);z-index:5000;'
                + 'background:#0b1623;border:1px solid #1e3a52;border-radius:6px;box-shadow:0 8px 30px rgba(0,0,0,.6);overflow:hidden;';
            w.innerHTML =
                '<div style="height:52px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid #1e3a52">' +
                    '<div style="font-weight:bold;font-size:18px">Add Form to Role</div>' +
                    '<div>' +
                        '<input id="roleAddFormSearch" type="text" placeholder="Search form..." autocomplete="off" style="margin-right:12px;padding:6px 10px;width:220px;;"/>' +
                        '<button id="roleAddFormOk" class="btn-dark" style="margin-right:8px;;padding:6px 16px;cursor:pointer;font-weight:bold">Add Selected</button>' +
                        '<button id="roleAddFormClose" class="btn-dark" style="padding:6px 14px;cursor:pointer">Close</button>' +
                    '</div>' +
                '</div>' +
                '<div id="roleAddFormGrid"></div>';
            document.body.appendChild(w);

            pickGrid = new Ext.grid.GridPanel({
                store: catalogStore,
                sm: pickSm,
                stripeRows: true,
                loadMask: true,
                height: w.clientHeight - 54,
                renderTo: 'roleAddFormGrid',
                autoExpandColumn: 'colPickForm',
                columns: [
                    pickSm,
                    { header: "Menu Group", dataIndex: 'group_name', width: 180, sortable: true },
                    { id: 'colPickForm', header: "Form Title", dataIndex: 'form_name', width: 240, sortable: true },
                    { header: "URL / Path", dataIndex: 'php_url', width: 220, sortable: true }
                ]
            });

            document.getElementById('roleAddFormSearch').onkeyup = applyPickFilter;
            document.getElementById('roleAddFormClose').onclick = closeRoleFormPicker;
            document.getElementById('roleAddFormOk').onclick = function() {
                var sel = pickSm.getSelections();
                for (var i = 0; i < sel.length; i++) {
                    var d = Ext.apply({}, sel[i].data);
                    d.access_level = 1;       // default Read, bisa diubah di grid
                    d.active = true;
                    roleAccessStore.add(new roleAccessStore.recordType(d, d.form_id));
                }
                roleAccessGrid.getView().refresh();
                closeRoleFormPicker();
            };
        }
        document.getElementById('roleAddFormSearch').value = '';
        w.style.display = 'block';
        catalogStore.load({
            callback: function() { applyPickFilter(); pickSm.clearSelections(); }
        });
    }

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
                style: 'margin-bottom: 10px',
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
                style: 'margin-bottom: 5px',
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
            },
            {
                id: 'roleFormAccessBox',
                columnWidth: 1.0,
                layout: 'column',
                baseCls: 'x-plain',
                style: 'margin-top: 10px',
                items: [
                    {
                        columnWidth: 1.0,
                        layout: 'column',
                        baseCls: 'x-plain',
                        style: 'margin-bottom: 5px',
                        items: [
                            {
                                columnWidth: 0.2,
                                layout: 'form',
                                baseCls: 'x-plain',
                                items: [{ xtype: 'label', text: 'Form Access', cls: 'x-form-item my-label-GroupLbl' }]
                            },
                            {
                                columnWidth: 0.52,
                                layout: 'form',
                                baseCls: 'x-plain',
                                items: [{ xtype: 'box', html: '&nbsp;' }]
                            },
                            {
                                columnWidth: 0.14,
                                layout: 'form',
                                baseCls: 'x-plain',
                                style: 'text-align: right',
                                items: [{
                                    xtype: 'button',
                                    text: 'Copy From',
                                    minWidth: 110,
                                    cls: 'btn-dark',
                                    id: 'btnRoleCopyFrom',
                                    listeners: { click: function(btn) { openCopyMenu(btn); } }
                                }]
                            },
                            {
                                columnWidth: 0.14,
                                layout: 'form',
                                baseCls: 'x-plain',
                                style: 'text-align: right',
                                items: [{
                                    xtype: 'button',
                                    text: 'Add',
                                    minWidth: 110,
                                    cls: 'btn-dark',
                                    id: 'btnRoleAddForm',
                                    listeners: { click: function() { openRoleFormPicker(); } }
                                }]
                            }
                        ]
                    },
                    {
                        columnWidth: 1.0,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: [roleAccessGrid]
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
            case 1: return '<span >Read</span>';
            case 2: return '<span >Read/Create</span>';
            case 3: return '<span >Read/Create/Update</span>';
            case 4: return '<span style="font-weight:bold">Full Access</span>';
            default: return '<span >No Access</span>';
        }
    }

    var matrixCheckColumn = new Ext.grid.CheckColumn({
        id: 'matrixCheckColumn',
        header: 'Active',
        dataIndex: 'active',
        width: 60,
        fixed: true,
        align: 'center',
        processEvent: function(name, e, grid, rowIndex, colIndex) {
            if (!matrixEditMode) { return false; }                       // mode lihat: tidak bisa diubah
            var orig = Ext.grid.CheckColumn.prototype.processEvent;
            if (typeof orig === 'function') { return orig.apply(this, arguments); }
            if (name === 'mousedown') {                                   // cadangan bila CheckColumn tanpa processEvent
                var rec = grid.store.getAt(rowIndex);
                rec.set(this.dataIndex, !rec.data[this.dataIndex]);
                return false;
            }
            return Ext.grid.ActionColumn.prototype.processEvent.apply(this, arguments);
        }
    });

    var matrixGrid = new stdGridEditor({
        store: matrixStore,
        height: 300,
        clicksToEdit: 1,
        loadMask: true,
        autoExpandColumn: 'colRemark',
        listeners: {
            beforeedit: function() { return matrixEditMode; }
        },
        columns: [
            new Ext.grid.RowNumberer(),
            { header: "Form ID", dataIndex: 'form_id', width: 60, hidden: true },
            { header: "Menu Group", dataIndex: 'group_name', width: 180, sortable: true, renderer: CellWrap },
            { header: "Form Title", dataIndex: 'form_name', width: 180, sortable: true, renderer: CellWrap },
            { header: "URL / Path", dataIndex: 'php_url', width: 160, sortable: true },
            { id: 'colRemark', header: "Remark", dataIndex: 'remark', width: 220, renderer: CellWrap },
            matrixCheckColumn,
            {
                header: "Access Level",
                dataIndex: 'access_level',
                width: 180,
                renderer: renderLevelName,
                editor: LevelCombo,
                css: cssGridEditColumn
            }
        ]
    });

    // ==========================================
    // DETAIL: Access Matrix (tampil di bawah daftar role, seperti Purchase Planning)
    // ==========================================
    var detailHost = document.getElementById('divDetailGrid');
    if (!detailHost) {
        detailHost = document.createElement('div');
        detailHost.id = 'divDetailGrid';
        gridHost.parentNode.insertBefore(detailHost, gridHost.nextSibling);
    }
    detailHost.style.cssText = 'margin:14px 0 0 0;border:1px solid #1e3a52;border-radius:6px;overflow:hidden;';
    detailHost.innerHTML =
        '<div id="matrixHeader" style="height:' + DETAIL_BAR_H + 'px;display:flex;align-items:center;justify-content:space-between;padding:0 16px;border-bottom:1px solid #1e3a52">' +
            '<div id="matrixTitle" style="font-weight:bold;font-size:18px"></div>' +
            '<div id="matrixButtons" style="display:none">' +
                '<button id="matrixEditInfo" class="btn-dark" style="margin-right:8px;padding:6px 14px;cursor:pointer">Edit Info</button>' +
                '<button id="matrixCopy" class="btn-dark" style="margin-right:8px;padding:6px 14px;cursor:pointer">Copy From...</button>' +
                '<button id="matrixSelectAll" class="btn-dark" style="margin-right:8px;padding:6px 14px;cursor:pointer">Select All &#9662;</button>' +
                '<button id="matrixClearAll" class="btn-dark" style="margin-right:8px;padding:6px 14px;cursor:pointer">Clear All</button>' +
                '<button id="matrixSave" class="btn-dark" style="margin-right:8px;;padding:6px 16px;cursor:pointer;font-weight:bold">Save Matrix</button>' +
                '<button id="matrixSync" class="btn-dark" style="margin-right:8px;;padding:6px 16px;cursor:pointer;font-weight:bold" title="Sync this role matrix to all assigned users">Sync to Users</button>' +
                '<button id="matrixExit" class="btn-dark" style="padding:6px 14px;cursor:pointer">Exit Edit</button>' +
            '</div>' +
        '</div>' +
        '<div id="matrixMainPanel"></div>';

    matrixGrid.setHeight(splitHeights().detail);
    matrixGrid.render('matrixMainPanel');

    var activeRoleCode = '';

    function renderMatrixTitle() {
        var t = document.getElementById('matrixTitle');
        if (!activeRoleId) { t.innerHTML = 'Access Matrix: pilih role di atas'; return; }
        var badge = matrixEditMode
            ? ' <span style="font-size:12px;font-weight:normal;;padding:1px 9px;margin-left:8px">EDITING</span>'
            : ' <span style="font-size:12px;font-weight:normal;;padding:1px 9px;margin-left:8px">VIEW ONLY</span>';
        t.innerHTML = 'Access Matrix: ' + Ext.util.Format.htmlEncode(activeRoleName) + ' (' + Ext.util.Format.htmlEncode(activeRoleCode) + ')' + badge;
    }

    function setMatrixEditMode(on) {
        matrixEditMode = !!on && activeRoleId > 0;
        document.getElementById('matrixButtons').style.display = matrixEditMode ? 'block' : 'none';
        detailHost.style.borderColor = matrixEditMode ? '#F59E0B' : '#1e3a52';
        renderMatrixTitle();
        matrixGrid.getView().refresh();
    }

    function loadRoleMatrix(roleId, roleName, roleCode) {
        activeRoleId = roleId;
        activeRoleName = roleName;
        activeRoleCode = roleCode;
        setMatrixEditMode(false);           // setiap ganti role kembali ke mode lihat
        matrixStore.load({ params: { role_id: roleId } });
    }

    function clearRoleMatrix() {
        activeRoleId = 0;
        activeRoleName = '';
        activeRoleCode = '';
        setMatrixEditMode(false);
        matrixStore.removeAll();
    }

    function matrixIsDirty() {
        return matrixEditMode && matrixStore.getModifiedRecords().length > 0;
    }

    // Pilih role dari grid; kalau ada perubahan matriks yang belum disimpan, minta konfirmasi dulu.
    function selectRoleRow(grid, rowIndex) {
        var rec = grid.getStore().getAt(rowIndex);
        var go = function() {
            dataRecord = rec.data;
            loadRoleMatrix(rec.data.id, rec.data.role_name, rec.data.role_code);
        };
        if (rec.data.id === activeRoleId) { dataRecord = rec.data; return; }
        if (matrixIsDirty()) {
            Ext.MessageBox.confirm('Unsaved changes', 'Perubahan matriks belum disimpan. Buang perubahan dan pindah role?', function(btn) {
                if (btn === 'yes') {
                    go();
                } else {
                    var idx = mainDs.findBy(function(r) { return r.get('id') === activeRoleId; });
                    if (idx >= 0) { grid.getSelectionModel().selectRow(idx); }
                }
            });
        } else {
            go();
        }
    }

    function requireActiveRole() {
        if (!activeRoleId) {
            document.getElementById("popWdwTxt").innerHTML = 'Please select a role first.';
            document.getElementById('popWdw').style.display = 'block';
            return false;
        }
        return true;
    }

    renderMatrixTitle();

    document.getElementById('matrixEditInfo').onclick = function() {
        if (!requireActiveRole()) return;
        var idx = mainDs.findBy(function(r) { return r.get('id') === activeRoleId; });
        if (idx >= 0) { dataRecord = mainDs.getAt(idx).data; }
        openRoleInfoEditor();
    };

    document.getElementById('matrixExit').onclick = function() {
        var leave = function() {
            matrixStore.rejectChanges();
            setMatrixEditMode(false);
        };
        if (matrixIsDirty()) {
            Ext.MessageBox.confirm('Unsaved changes', 'Perubahan matriks belum disimpan. Keluar dari mode edit dan buang perubahan?', function(btn) {
                if (btn === 'yes') { leave(); }
            });
        } else {
            leave();
        }
    };

    // Salin level akses dari role lain ke matriks yang sedang diedit (belum tersimpan sampai Save Matrix)
    function copyMatrixFromRole(srcId, srcName) {
        Ext.Ajax.request({
            url: CRD,
            method: 'POST',
            params: { task: 'read_role_matrix', role_id: srcId },
            success: function(response) {
                var obj = null;
                try { obj = Ext.util.JSON.decode(response.responseText); } catch (e) {}
                if (!obj || obj.success === false || !obj.data) {
                    document.getElementById("popWdwTxt").innerHTML = (obj && obj.message) || 'Gagal memuat matriks role sumber.';
                    document.getElementById('popWdw').style.display = 'block';
                    return;
                }
                var levelByForm = {};
                for (var i = 0; i < obj.data.length; i++) {
                    levelByForm[obj.data[i].form_id] = parseInt(obj.data[i].access_level, 10) || 0;
                }
                matrixStore.each(function(rec) {
                    var lvl = levelByForm[rec.get('form_id')] || 0;
                    if (parseInt(rec.get('access_level'), 10) !== lvl || !!rec.get('active') !== (lvl > 0)) {
                        rec.set('access_level', lvl);
                        rec.set('active', lvl > 0);
                    }
                });
                matrixGrid.getView().refresh();
            },
            failure: function(response) {
                var msg = 'Gagal memuat matriks role sumber.';
                try { msg = Ext.util.JSON.decode(response.responseText).message || msg; } catch (e) {}
                document.getElementById("popWdwTxt").innerHTML = msg;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    }

    document.getElementById('matrixCopy').onclick = function() {
        if (!requireActiveRole()) return;
        var items = [];
        mainDs.each(function(r) {
            if (parseInt(r.get('id'), 10) === activeRoleId) { return; }
            items.push({
                text: r.get('role_name') + ' (' + r.get('role_code') + ')',
                roleId: r.get('id'),
                roleName: r.get('role_name'),
                handler: function(item) {
                    Ext.MessageBox.confirm('Copy From', 'Ganti level akses matriks ini dengan milik role "' + item.roleName + '"? (belum tersimpan sampai Save Matrix)', function(btn) {
                        if (btn === 'yes') { copyMatrixFromRole(item.roleId, item.roleName); }
                    });
                }
            });
        });
        if (!items.length) {
            document.getElementById("popWdwTxt").innerHTML = 'Belum ada role lain untuk disalin.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        new Ext.menu.Menu({ items: items }).show(document.getElementById('matrixCopy'));
    };

    // Select All dengan pilihan level: semua form dicentang dan diberi level yang dipilih
    function setAllMatrixLevel(level) {
        matrixStore.each(function(rec) {
            rec.set('active', true);
            rec.set('access_level', level);
        });
        matrixGrid.getView().refresh();
    }

    document.getElementById('matrixSelectAll').onclick = function() {
        if (!requireActiveRole()) return;
        new Ext.menu.Menu({
            items: [
                { text: 'All Read',                          handler: function() { setAllMatrixLevel(1); } },
                { text: 'All Read/Create',                   handler: function() { setAllMatrixLevel(2); } },
                { text: 'All Read/Create/Update (no Delete)', handler: function() { setAllMatrixLevel(3); } },
                { text: 'All Full Access (incl. Delete)',    handler: function() { setAllMatrixLevel(4); } }
            ]
        }).show(document.getElementById('matrixSelectAll'));
    };

    document.getElementById('matrixClearAll').onclick = function() {
        if (!requireActiveRole()) return;
        matrixStore.each(function(rec) {
            rec.set('active', false);
            rec.set('access_level', 0);
        });
        matrixGrid.getView().refresh();
    };

    document.getElementById('matrixSave').onclick = function() {
        if (!requireActiveRole()) return;
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
                if (obj.success !== false) { matrixStore.commitChanges(); }
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
        if (!requireActiveRole()) return;
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

    // Template menampilkan editorWdw secara default; sembunyikan setelah semua komponen dirender
    // (sama seperti halaman User System).
    document.getElementById('editorWdw').style.display = 'none';

    window.onresize = function() {
        var h = splitHeights();
        gridMain.setHeight(h.main);
        matrixGrid.setHeight(h.detail);
    };
});