/*
  Document      : m.generalledger.js
  Description   : General Ledger - Laporan read-only, filter Akun & Periode
                   OPSIONAL. Kalau tidak dipilih, tampilkan semua akun /
                   semua periode, dikelompokkan per akun (GroupingView).
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/generalledgerCRD.php
=======
  Backend       : /rab-project/live/php-crud/generalledgerCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "General <em>Ledger</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    // ============================================================
    // 0. Buat elemen filter bar & summary secara dinamis
    // ============================================================
    var gridContainer = document.getElementById('divMainGrid');
    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'glFilterBar';
    filterBarDiv.style.padding = '0 10px';
    var summaryDiv = document.createElement('div');
    summaryDiv.id = 'glSummary';
    summaryDiv.style.cssText = 'padding:5px 12px; font-size:13px; color:#ddd; background:rgba(255,255,255,0.05); margin:0 10px 10px 10px; border-radius:4px;';
    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(summaryDiv, gridContainer);
    }

    // ============================================================
    // 1. Field definitions & stores
    // ============================================================
    var ledgerField = [
        { name: 'account_id' }, { name: 'account_code' }, { name: 'account_name' }, { name: 'account_label' },
        { name: 'journal_date', type: 'date', dateFormat: 'Y-m-d' },
        { name: 'journal_no' }, { name: 'description' },
        { name: 'debit', type: 'float' }, { name: 'credit', type: 'float' }, { name: 'balance', type: 'float' }
    ];

    var accountField = [{ name: 'id' }, { name: 'account_code' }, { name: 'account_name' }, { name: 'account_type' }];
    var accountDs = new Ext.data.Store({
        url: '../php-crud/generalledgerCRD.php',
        baseParams: { task: 'read_accounts' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, accountField)
    });
    accountDs.load();

    var periodField = [{ name: 'id' }, { name: 'period_name' }, { name: 'start_date' }, { name: 'end_date' }];
    var periodDs = new Ext.data.Store({
        url: '../php-crud/generalledgerCRD.php',
        baseParams: { task: 'read_periods' },
        reader: new Ext.data.JsonReader({ root: 'data', idProperty: 'id' }, periodField)
    });
    periodDs.load();

    // GroupingStore supaya laporan "semua akun" tersusun rapi per kelompok
    var ledgerDs = new Ext.data.GroupingStore({
        url: '../php-crud/generalledgerCRD.php',
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, ledgerField),
        groupField: 'account_label',
        sortInfo: { field: 'journal_date', direction: 'ASC' },
        remoteGroup: false,
        remoteSort: false
    });

    // ============================================================
    // 2. Filter bar (Akun + Periode, keduanya OPSIONAL + tombol Tampilkan)
    // ============================================================
    var fldAccount = new myComboField({
        fieldLabel: 'Akun', width: 280, store: accountDs, displayField: 'account_name', valueField: 'id',
        emptyText: '-- Semua Akun --'
    });
    var fldPeriod = new myComboField({
        fieldLabel: 'Periode', width: 180, store: periodDs, displayField: 'period_name', valueField: 'id',
        emptyText: '-- Semua Periode --'
    });
    var btnShow = new Ext.Button({ text: 'Tampilkan', cls: 'field-button grey-field-button' });
    var btnClearFilter = new Ext.Button({ text: 'Reset', cls: 'field-button grey-field-button' });

    var filterPanel = new Ext.Panel({
        renderTo: 'glFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.35, layout: 'form', labelWidth: 60, items: fldAccount },
            { columnWidth: 0.25, layout: 'form', labelWidth: 60, items: fldPeriod },
            { columnWidth: 0.15, style: 'padding-top:2px;', items: btnShow },
            { columnWidth: 0.1, style: 'padding-top:2px;', items: btnClearFilter }
        ]
    });

    function loadLedger() {
        var accId = fldAccount.getValue() || 0;
        var perId = fldPeriod.getValue() || 0;

        ledgerDs.load({ params: { task: 'read_ledger', account_id: accId, period_id: perId } });

        Ext.Ajax.request({
            url: '../php-crud/generalledgerCRD.php',
            method: 'POST',
            params: { task: 'read_ledger', account_id: accId, period_id: perId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) { summaryDiv.innerHTML = ''; return; }
                summaryDiv.innerHTML =
                    '<b>Akun:</b> ' + res.account_label +
                    ' &nbsp;|&nbsp; <b>Periode:</b> ' + res.period_name +
                    ' &nbsp;|&nbsp; <b>Jumlah baris:</b> ' + res.totalCount;
            }
        });
    }

    btnShow.on('click', loadLedger);
    btnClearFilter.on('click', function () {
        fldAccount.clearValue();
        fldPeriod.clearValue();
        loadLedger();
    });

    // ============================================================
    // 3. Grid mutasi, dikelompokkan per akun
    // ============================================================
    let mainHeight=(maxGridHeight(document.getElementById('divMainGrid')));

    var gridMain = new Ext.grid.GridPanel({
        store: ledgerDs,
        renderTo: 'divMainGrid',
        height : mainHeight,

        stripeRows: true,
        loadMask: { msg: 'Loading Data ...' },
        view: new Ext.grid.GroupingView({
            forceFit: true,
            groupTextTpl: '{text}',
            hideGroupedColumn: true,
            startCollapsed: false
        }),
        colModel: new Ext.grid.ColumnModel({
            columnLines: true,
            defaults: { menuDisabled: true, resizable: false, sortable: false },
            columns: [
                { header: 'Akun', dataIndex: 'account_label', hidden: true },
                { header: 'Tanggal', dataIndex: 'journal_date', width: 90, renderer: Ext.util.Format.dateRenderer('d M Y') },
                { header: 'No. Jurnal', dataIndex: 'journal_no', width: 110, renderer: CellWrap },
                { header: 'Keterangan', dataIndex: 'description', width: 250, renderer: CellWrap },
                {
                    header: 'Debit', dataIndex: 'debit', width: 110, align: 'right',
                    renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
                },
                {
                    header: 'Kredit', dataIndex: 'credit', width: 110, align: 'right',
                    renderer: function (v) { return v ? Ext.util.Format.number(v, '0,000.00') : ''; }
                },
                {
                    header: 'Saldo', dataIndex: 'balance', width: 120, align: 'right',
                    renderer: function (v) { return '<b>' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>'; }
                }
            ]
        })
    });

    document.getElementById('printData').onclick = function () {
        var accId = fldAccount.getValue() || 0;
        var perId = fldPeriod.getValue() || 0;
        window.open('../pdf/generalledger.php?account_id=' + accId + '&period_id=' + perId, 'sub', 'toolbar=no', 'location=no', 'status=no', 'directories=no', 'menubar=no');
    };

    // ============================================================
    // 4. Auto-load semua data saat halaman pertama kali dibuka
    //    (tanpa filter -- sesuai permintaan: tampilkan semua)
    // ============================================================
    loadLedger();
    document.getElementById('popOK').onclick = function () {
        document.getElementById('popWdw').style.display = 'none';

    }
});