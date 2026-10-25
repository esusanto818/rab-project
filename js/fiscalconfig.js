/*
  Document      : m.fiscalconfig.js
  Description   : Fiscal Config - Grid + Detail View + Editor Form
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/fiscalconfigCRD.php
=======
  Backend       : /rab-project/live/php-crud/fiscalconfigCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Fiscal <em>Config</em>";

    var task = '';
    var dataRecord = null;

    function getFormAccess() {
        return (typeof formAccess !== 'undefined') ? formAccess : (window.formAccess || 0);
    }

    // 0. Container Detail Fiscal Rule (Samakan class dengan PO Detail)
    var gridContainer = document.getElementById('divMainGrid');

    var fiscalDtlDiv = document.createElement('div');
    fiscalDtlDiv.id = 'fiscalDtlContainer';
    fiscalDtlDiv.className = 'detail-panel-container';
    fiscalDtlDiv.style.display = 'none';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(fiscalDtlDiv, gridContainer.nextSibling);
    }

    // ------------------------------------------------------------
    // 1. Field definitions & stores
    // ------------------------------------------------------------
    var configField = [
        { name: 'id' }, { name: 'rule_code' }, { name: 'rule_name' },
        { name: 'debit_account_id' }, { name: 'debit_code' }, { name: 'debit_name' },
        { name: 'credit_account_id' }, { name: 'credit_code' }, { name: 'credit_name' },
        { name: 'is_active', type: 'boolean' }
    ];

    var coaField = [{ name: 'id' }, { name: 'account_code' }, { name: 'account_name' }];
    var coaDs = new Ext.data.Store({
        url: '../php-crud/m.coaCRD.php',
        baseParams: { task: 'read_parent' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, coaField)
    });
    coaDs.load();

    var mainDs = new Ext.data.Store({
        url: '../php-crud/fiscalconfigCRD.php',
        idProperty: 'id',
        baseParams: { task: 'read' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, configField)
    });
    mainDs.load();

    // ------------------------------------------------------------
    // 2. Main Grid
    // ------------------------------------------------------------
    var GridDtlHeight = (maxGridHeight(document.getElementById('divMainGrid')));
    var mainHeight = Math.round(GridDtlHeight - (GridDtlHeight * 0.45));
    var detailHeight = (GridDtlHeight - mainHeight);
    var gridMain = new Ext.grid.GridPanel({
        store: mainDs,
        renderTo: 'divMainGrid',
        height: mainHeight,
        columns: [
            { header: 'Rule Code', dataIndex: 'rule_code', width: 100, renderer: CellWrap },
            { header: 'Nama Rule', dataIndex: 'rule_name', width: 200, renderer: CellWrap },
            { header: 'Akun Debit', dataIndex: 'debit_code', width: 180, renderer: function (v, m, r) { return r.get('debit_code') + ' - ' + r.get('debit_name'); } },
            { header: 'Akun Kredit', dataIndex: 'credit_code', width: 180, renderer: function (v, m, r) { return r.get('credit_code') + ' - ' + r.get('credit_name'); } },
            {
                header: 'Status', dataIndex: 'is_active', width: 80, align: 'center',
                renderer: function (v) { return v ? '<span style="color:#2e9e4f;font-weight:bold;">Aktif</span>' : '<span style="color:#c0392b !important;font-weight:bold;">Nonaktif</span>'; }
            }
        ],
        stripeRows: true,
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                if (rec) {
                    dataRecord = rec.data;
                    editDataEnable();
                    mainBtnEnable('#deleteData');
                    document.getElementById('fiscalDtlContainer').style.display = 'block';
                    loadFiscalDetail(dataRecord.id, dataRecord.rule_code);
                }
            }
        }
    });
    editDataDisable();
    mainBtnDisable('#deleteData');

    // ------------------------------------------------------------
    // 3. Detail View Renderer (Diperbarui dengan CSS PO Detail)
    // ------------------------------------------------------------
    function loadFiscalDetail(id, ruleCode) {
        Ext.Ajax.request({
            url: '../php-crud/fiscalconfigCRD.php',
            method: 'POST',
            params: { task: 'read_detail', id: id },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    fiscalDtlDiv.innerHTML = '<div style="color:yellow;">Gagal memuat detail rule.</div>';
                    fiscalDtlDiv.style.display = 'block';
                    return;
                }
                renderFiscalDetailView(res.data || {}, ruleCode);
            },
            failure: function () {
                fiscalDtlDiv.innerHTML = '<div style="color:red;">Gagal terhubung ke server.</div>';
                fiscalDtlDiv.style.display = 'block';
            }
        });
    }

    function renderFiscalDetailView(data, ruleCode) {
        var statusText = data.is_active ? '<span style="color:#2e9e4f;font-weight:bold;">Aktif</span>' : '<span style="color:#c0392b;font-weight:bold;">Nonaktif</span>';

        var html = '<h3 class="detail-title">Detail Posting Rule: ' + Ext.util.Format.htmlEncode(ruleCode || '') + '</h3>' +
                   '<hr class="detail-hr" />' +
                   '<table class="detail-table" width="100%" cellSpacing="0" cellPadding="0">' +
                   '<thead>' +
                   '<tr class="header-row">' +
                   '<th align="left" width="200">Parameter</th>' +
                   '<th align="left">Informasi Akun / Sistem</th>' +
                   '</tr>' +
                   '</thead>' +
                   'tbody' +
                   '<tr class="data-row">' +
                   '<td align="left">Nama Rule</td>' +
                   '<td align="left" style="font-weight:bold;">' + Ext.util.Format.htmlEncode(data.rule_name || '-') + '</td>' +
                   '</tr>' +
                   '<tr class="data-row">' +
                   '<td align="left">Posisi Debit (DR)</td>' +
                   '<td align="left" style="color:#2e9e4f !important; font-weight:bold;">' + Ext.util.Format.htmlEncode(data.debit_code ? (data.debit_code + ' - ' + data.debit_name) : '-') + '</td>' +
                   '</tr>' +
                   '<tr class="data-row">' +
                   '<td align="left">Posisi Kredit (CR)</td>' +
                   '<td align="left" style="color:#c0392b !important; font-weight:bold;">' + Ext.util.Format.htmlEncode(data.credit_code ? (data.credit_code + ' - ' + data.credit_name) : '-') + '</td>' +
                   '</tr>' +
                   '<tr class="data-row">' +
                   '<td align="left">Status Rule</td>' +
                   '<td align="left">' + statusText + '</td>' +
                   '</tr>' +
                   '</tbody>' +
                   '</table>';

        fiscalDtlDiv.innerHTML = html;
        fiscalDtlDiv.style.display = 'block';
    }

    // ------------------------------------------------------------
    // 4. Editor Form
    // ------------------------------------------------------------
    var fldCode = new myTextField({
        fieldLabel: 'Rule Code', id: 'rule_code', name: 'rule_code', allowBlank: false,
        autoCreate: { tag: "input", maxlength: 15, type: "text", size: "15", autocomplete: "off" },
        listeners: { change: function (f) { f.setValue(f.getValue().toUpperCase()); } }
    });
    var fldName = new myTextField({ fieldLabel: 'Nama Rule', id: 'rule_name', name: 'rule_name', allowBlank: false });
    var fldDebit = new myComboField({
        fieldLabel: 'Akun Debit', id: 'debit_account_id', name: 'debit_account_id', hiddenName: 'debit_account_id',
        store: coaDs, displayField: 'account_name', valueField: 'id', allowBlank: false
    });
    var fldCredit = new myComboField({
        fieldLabel: 'Akun Kredit', id: 'credit_account_id', name: 'credit_account_id', hiddenName: 'credit_account_id',
        store: coaDs, displayField: 'account_name', valueField: 'id', allowBlank: false
    });
    var fldActive = new Ext.form.Checkbox({
        fieldLabel: 'Status Aktif', labelSeparator: '&nbsp;&nbsp;-&nbsp;&nbsp;',
        id: 'is_active', name: 'is_active', cls: 'fieldCheckClass regular-checkbox',
        inputValue: 'true', checked: true
    });
    var fldId = new myTextField({ name: 'id', id: 'id', hidden: true });

    var frmEditor = new stdForm({
        id: 'frmEditor',
        renderTo: 'editorFormMain',
        labelWidth: 130,
        defaults: { xtype: 'container', layout: 'form', columnWidth: 0.5, baseCls: 'x-plain' },
        items: [
            { columnWidth: 1, items: FieldSpace },
            { items: fldCode },
            { items: fldName },
            { items: fldDebit },
            { items: fldCredit },
            { columnWidth: 0.5, labelAlign: 'right', labelWidth: 130, items: fldActive },
            { items: fldId },
            { columnWidth: 1, items: FieldSpace },
            { columnWidth: 1, items: FieldSpace }
        ]
    });
    frmEditor.on('clientvalidation', function (f, valid) { if (!valid) { saveEditDisable(); } else { saveEditEnable(); } });

    // ------------------------------------------------------------
    // 5. Toolbar Actions
    // ------------------------------------------------------------
    document.getElementById('addData').onclick = function () {
        task = '';
        frmEditor.getForm().reset();
        if (getFormAccess() == 2 || getFormAccess() == 3 || getFormAccess() == 4) {
            task = 'create';
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Tambah Rule';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('editData').onclick = function () {
        task = '';
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 3 || getFormAccess() == 4) {
            frmEditor.getForm().reset();
            task = 'update';
            frmEditor.getForm().load({ url: '../php-crud/fiscalconfigCRD.php', params: { id: dataRecord.id, task: 'read_edit' } });
            document.getElementById('editorWdw').style.display = 'block';
            document.getElementById('editorFormHeaderText').innerHTML = '&nbsp;&nbsp;Edit Rule';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses ke halaman ini.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('deleteData').onclick = function () {
        if (!dataRecord) {
            document.getElementById('popWdwTxt').innerHTML = 'Pilih data terlebih dahulu.';
            document.getElementById('popWdw').style.display = 'block';
            return;
        }
        if (getFormAccess() == 4) {
            task = 'delete';
            document.getElementById('popWdw').style.display = 'block';
            document.getElementById('popCancel').style.display = 'block';
            document.getElementById('popWdwTxt').innerHTML = 'Hapus rule \'' + dataRecord.rule_code + '\' ?';
        } else {
            document.getElementById('popWdwTxt').innerHTML = 'Anda tidak memiliki hak akses untuk menghapus data.';
            document.getElementById('popWdw').style.display = 'block';
        }
    };

    document.getElementById('printData').onclick = function (event) {
        window.open("../pdf/fiscalconfig.php", 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    document.getElementById('saveEdit').onclick = function () {
        frmEditor.getForm().submit({
            url: '../php-crud/fiscalconfigCRD.php',
            method: 'POST',
            params: { task: task },
            waitTitle: 'Connecting',
            waitMsg: 'Sending data...',
            success: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
                fiscalDtlDiv.style.display = 'none';
                mainDs.load({ params: { task: 'read' } });
                frmEditor.getForm().reset();
                document.getElementById('editorWdw').style.display = 'none';
            },
            failure: function (form, action) {
                var res = Ext.util.JSON.decode(action.response.responseText);
                document.getElementById('popWdwTxt').innerHTML = res.message;
                document.getElementById('popWdw').style.display = 'block';
            }
        });
    };

    document.getElementById('popOK').onclick = function () {
        if (task == 'delete') {
            var deleteDs = new Ext.data.Store({ url: '../php-crud/fiscalconfigCRD.php' });
            deleteDs.load({
                params: { task: 'delete', id: dataRecord.id },
                callback: function () {
                    document.getElementById('popWdw').style.display = 'none';
                    document.getElementById('popCancel').style.display = 'none';
                    task = '';
                    fiscalDtlDiv.style.display = 'none';
                    mainDs.load();
                }
            });
        } else {
            document.getElementById('popWdw').style.display = 'none';
            document.getElementById('popCancel').style.display = 'none';
        }
    };

    document.getElementById('editorWdw').style.display = 'none';
    document.getElementById('fiscalDtlContainer').style.display = 'none';
});