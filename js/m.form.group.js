/*
    Document        : Master Form Group
    Description     : Controller & UI Handler ExtJS 3.4 (Aligned Checkbox & Layout)
*/

Ext.onReady(function() {
    Ext.QuickTips.init();
    
    if (document.getElementById("mainHeaderText")) {
        document.getElementById("mainHeaderText").innerHTML = "Form Group";
    }

    var task = '';
    var pageSizeVal = typeof PageSize !== 'undefined' ? PageSize : 250;

    document.getElementById('addData').onclick = function(event) {
        task = '';
        if (formAccess == 2 || formAccess == 3 || formAccess == 4) {
            task = 'create';
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;New Form Group";
            frmEditor.getForm().reset();
            fldActive.setValue(true);
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to access this window';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function(event) {
        task = '';
        if (formAccess == 3 || formAccess == 4) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Pilih data group terlebih dahulu.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            frmEditor.getForm().reset();
            task = 'update';
            Ext.getCmp('frmEditor').getForm().load({
                url: '../dev/php-crud/m.form.groupCRD.php',
                params: { id: dataRecord.id, task: 'read_edit' },
                success: function(form, action) {
                    let res = Ext.util.JSON.decode(action.response.responseText);
                    let row = (res && Ext.isArray(res.data)) ? res.data[0] : (res ? res.data : null);
                    let rawActive = row ? row.active : true;
                    let isActive = (rawActive === true || rawActive === 'true' || rawActive === 't' || rawActive === 1 || rawActive === '1');
                    fldActive.setValue(isActive);
                }
            });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById("editorFormHeaderText").innerHTML = "&nbsp;&nbsp;Edit Form Group";
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to edit this data';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function(event) {
        if (formAccess == 4) {
            if (!dataRecord || !dataRecord.id) {
                document.getElementById("popWdwTxt").innerHTML = 'Pilih data group yang akan dihapus.';
                document.getElementById('popWdw').style.display = 'block';
                return;
            }
            task = 'delete';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById("popWdwTxt").innerHTML = 'delete group \'' + (dataRecord.grp_name || dataRecord.name || '') + '\' ?';
        } else {
            document.getElementById("popWdwTxt").innerHTML = 'not enough permission to delete this data';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function(event) {
        window.open("../pdf/m.material_type.php", 'sub', 'toolbar=no,location=no,status=no,directories=no,menubar=no');
    };

    document.getElementById('saveEdit').onclick = function(event) {
        frmEditor.getForm().submit({
            method: 'POST',
            params: {
                task: task,
                active: fldActive.getValue() ? 'true' : 'false'
            },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function(form, action) {
                let objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById("popWdwTxt").innerHTML = objResponse.message;
                document.getElementById('popWdw').style.display = 'block';

                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function(form, action) {
                let objResponse = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById("popWdwTxt").innerHTML = objResponse.message;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('popOK').onclick = function(event) {
        if (task == 'delete') {
            deleteDs.load({
                params: { task: "delete", id: dataRecord.id },
                callback: function(records, operation, success) {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    task = '';

                    let textMessages = Ext.util.JSON.decode(operation.response.responseText);
                    document.getElementById("popWdwTxt").innerHTML = textMessages.message;
                    document.getElementById('popWdw').style.display = 'block';

                    mainDs.load();
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
        task = '';
    };

    document.getElementById('logData').onclick = function(event) {
        if (!dataRecord) return;
        document.getElementById('logWdw').style.display = 'block';
        document.getElementById("logFormHeaderText").innerHTML = '&nbsp;' + (dataRecord.grp_name || dataRecord.name || '');
        let historyHeight = calculateDivHeight(['logForm', 'logFormHeader']);
        GridHistory.setHeight(historyHeight);
        GridHistoryDs.load({ params: { id: dataRecord.id } });
    };

    let myMask = new Ext.LoadMask(Ext.getBody(), { msg: "Please wait..." });
    let deleteDs = new Ext.data.Store({ url: '../dev/p/m.form.groupCRD.php' });
    let grpField = [
        { name: 'id' },
        { name: 'seq' },
        { name: 'grp_name' },
        { name: 'remark' },
        { name: 'active', type: 'boolean' }
    ];

    mainDs = new Ext.data.Store({
        url: '../dev/php-crud/m.form.groupCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read', start: 0, limit: pageSizeVal },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: grpField })
    });
    mainDs.load();

    // Checkbox Active -- TANPA name:'active' (supaya tidak ikut auto-submit
    // form asli dan bentrok dengan nilai eksplisit yang dikirim manual di
    // saveEdit -- itu yang bikin reaktivasi nonaktif->aktif gagal tersimpan).
    // TANPA boxLabel juga sesuai permintaan, cukup label "Active:" saja.
    let fldActive = new Ext.form.Checkbox({
        fieldLabel: 'Active',
        id: 'AddActive',
        checked: true,
        cls: 'fieldCheckClass regular-checkbox'
    });

    let frmEditor = new stdForm({
        url      : '../dev/php-crud/m.form.groupCRD.php',
        id       : 'frmEditor',
        reader   : new Ext.data.JsonReader({ root: 'data', fields: grpField }),
        renderTo : 'editorFormMain',
        items    : [
            { items: new myTextField({ name: 'id', id: 'id', hidden: true }) },
            //{ columnWidth: 1, items: RowSpace },
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
                        items: new myTextField({
                            fieldLabel: 'Seq', id: 'seq', name: 'seq', allowBlank: false,
                            autoCreate: { tag: "input", maxlength: 8, type: "text", size: "8", autocomplete: "off" },
                            listeners: { change: function(field) { field.setValue(field.getValue().toUpperCase()); } }
                        })
                    },
                    {
                        columnWidth: 0.6,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: new myTextField({
                            fieldLabel: 'Name', id: 'grp_name', name: 'grp_name', allowBlank: false,
                            autoCreate: { tag: "input", maxlength: 100, type: "text", size: "100", autocomplete: "off" }
                        })
                    },
                    {
                        columnWidth: 0.2,
                        layout: 'form',
                        baseCls: 'x-plain',
                        items: fldActive
                    }
                ]
            },
            {
                columnWidth: 1.0,
                layout: 'form',
                baseCls: 'x-plain',
                items: new myTextArea({ fieldLabel: 'Remark', id: 'remark', name: 'remark', autoCreate: { tag: "textarea", autocomplete: "off" } })
            },
            //{ columnWidth: 1, items: RowSpace }
        ]
    });

    frmEditor.on('clientvalidation', function(f, valid) {
        if (!valid) { saveEditDisable(); } else { saveEditEnable(); }
    });

    let mainHeight = maxGridHeight(document.getElementById('divMainGrid'));
    let gridMain = new stdGridPaging({
        renderTo: 'divMainGrid',
        store  : mainDs,
        height : mainHeight,
        columns: [
            { header: "Seq", dataIndex: 'seq', width: 100, fixed: true, renderer: CellWrap },
            { header: "Name", dataIndex: 'grp_name', width: 150, renderer: CellWrap },
            { header: "Remark", dataIndex: 'remark', width: 300, renderer: CellWrap },
            { 
                header: "Status", dataIndex: 'active', width: 90, align: 'center', 
                renderer: function(v) {
                    return (v === true || v === 'true' || v === 1 || v === '1')
                        ? '<span style="color:#2e9e4f;font-weight:bold;">Aktif</span>'
                        : '<span style="color:#c0392b;font-weight:bold;">Nonaktif</span>';
                } 
            },
            { dataIndex: 'id', hidden: true }
        ],
        pageSize: 250,
        pageStore: mainDs
    });

    let GridHistoryDs = new historyDs({ url: '../dev/php-crud/m.form.groupCRD.php' });
    let GridHistory = new historyGrid({ store: GridHistoryDs, renderTo: 'logFormMain' });

    document.getElementById('editorWdw').style.display = 'none';
    document.getElementById('addWdw').style.display = 'none';
});