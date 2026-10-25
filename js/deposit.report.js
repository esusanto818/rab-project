/*
  Document      : deposit_report.js
  Description   : Monitoring Deposit (Customer Deposit / Supplier Deposit) - Read-only report
<<<<<<< Updated upstream
  Backend       : /rab-project/dev/php-crud/deposit.reportCRD.php
=======
  Backend       : /rab-project/live/php-crud/deposit.reportCRD.php
>>>>>>> Stashed changes
*/

Ext.onReady(function () {
    Ext.QuickTips.init();
    document.getElementById("mainHeaderText").innerHTML = "Deposit <em>Monitoring</em>";

    ['addData', 'editData', 'deleteData'].forEach(function (id) {
        var el = document.getElementById(id);
        if (el) el.style.display = 'none';
    });

    var currentPartyType = 'customer';
    var currentPartyId = null;
    var currentPartyName = '';

    // 0. Container filter & report
    var gridContainer = document.getElementById('divMainGrid');

    var filterBarDiv = document.createElement('div');
    filterBarDiv.id = 'depFilterBar';
    filterBarDiv.style.padding = '0 10px';

    var summaryDiv = document.createElement('div');
    summaryDiv.id = 'depSummaryContainer';
    summaryDiv.style.cssText = 'padding:0 10px 10px 10px;';

    var mutationDiv = document.createElement('div');
    mutationDiv.id = 'depMutationContainer';
    mutationDiv.style.cssText = 'padding:15px; background:rgba(255,255,255,0.03); color:#fff; margin:0 10px 10px 10px; border-radius:4px; border:1px solid rgba(255,255,255,0.1); font-size:13px; display:none;';

    var totalBalanceDiv = document.createElement('div');
    totalBalanceDiv.id = 'depGrandTotal';
    totalBalanceDiv.style.cssText = 'padding:10px 20px; text-align:right; font-weight:bold; font-size:15px; color:#f39c12;';

    if (gridContainer && gridContainer.parentNode) {
        gridContainer.parentNode.insertBefore(filterBarDiv, gridContainer);
        gridContainer.parentNode.insertBefore(summaryDiv, gridContainer);
        gridContainer.parentNode.insertBefore(totalBalanceDiv, gridContainer);
        gridContainer.parentNode.insertBefore(mutationDiv, gridContainer);
        gridContainer.style.display = 'none'; // grid bawaan template tidak dipakai
    }

    // 1. Filter Bar: toggle Customer / Supplier
    var btnCustomer = new Ext.Button({
        text: 'Customer Deposit', cls: 'field-button grey-field-button',
        handler: function () { switchPartyType('customer'); }
    });
    var btnSupplier = new Ext.Button({
        text: 'Supplier Deposit', cls: 'field-button grey-field-button',
        handler: function () { switchPartyType('supplier'); }
    });

    var filterPanel = new Ext.Panel({
        renderTo: 'depFilterBar',
        layout: 'column',
        border: false,
        bodyStyle: 'background:transparent; padding:8px 0;',
        defaults: { border: false, bodyStyle: 'background:transparent;' },
        items: [
            { columnWidth: 0.18, style: 'padding-right:8px;', items: btnCustomer },
            { columnWidth: 0.18, items: btnSupplier }
        ]
    });

    function setActiveButton(type) {
        btnCustomer.getEl().dom.style.opacity = (type === 'customer') ? '1' : '0.5';
        btnSupplier.getEl().dom.style.opacity = (type === 'supplier') ? '1' : '0.5';
    }

    function switchPartyType(type) {
        currentPartyType = type;
        setActiveButton(type);
        mutationDiv.style.display = 'none';
        loadSummary();
    }

    // 2. Summary Store & Grid
    var summaryField = [
        { name: 'party_id' }, { name: 'party_name' },
        { name: 'total_in', type: 'float' }, { name: 'total_out', type: 'float' },
        { name: 'current_balance', type: 'float' }, { name: 'last_movement_date' }
    ];
    var summaryDs = new Ext.data.Store({
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalCount' }, summaryField)
    });

    var summaryGrid = new Ext.grid.GridPanel({
        store: summaryDs,
        renderTo: 'depSummaryContainer',
        height: 320,
        cls: 'x-grid-panel-dark',
        columns: [
            { header: 'Nama', dataIndex: 'party_name', width: 220, renderer: CellWrap },
            {
                header: 'Total Masuk (Lifetime)', dataIndex: 'total_in', width: 150, align: 'right',
                renderer: function (v) { return '<span style="color:#2e9e4f;">' + Ext.util.Format.number(v || 0, '0,000.00') + '</span>'; }
            },
            {
                header: 'Total Terpakai (Lifetime)', dataIndex: 'total_out', width: 150, align: 'right',
                renderer: function (v) { return '<span style="color:#c0392b;">' + Ext.util.Format.number(v || 0, '0,000.00') + '</span>'; }
            },
            {
                header: 'Saldo Saat Ini', dataIndex: 'current_balance', width: 140, align: 'right',
                renderer: function (v) {
                    var color = v > 0.01 ? '#f39c12' : '#888';
                    return '<b style="color:' + color + ';">' + Ext.util.Format.number(v || 0, '0,000.00') + '</b>';
                }
            },
            { header: 'Mutasi Terakhir', dataIndex: 'last_movement_date', width: 120 }
        ],
        viewConfig: { forceFit: true },
        loadMask: true,
        listeners: {
            rowclick: function (grid, rowIndex) {
                var rec = grid.getStore().getAt(rowIndex);
                currentPartyId = rec.get('party_id');
                currentPartyName = rec.get('party_name');
                loadMutation();
            }
        }
    });

    function loadSummary() {
        summaryGrid.el.mask('Memuat...');
        Ext.Ajax.request({
            url: '../php-crud/deposit.reportCRD.php',
            method: 'POST',
            params: { task: 'read_summary', party_type: currentPartyType },
            success: function (response) {
                summaryGrid.el.unmask();
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    document.getElementById('depGrandTotal').innerHTML = '';
                    return;
                }
                summaryDs.loadData(res);
                var label = currentPartyType === 'supplier' ? 'Total Saldo Deposit di Semua Supplier' : 'Total Saldo Deposit dari Semua Customer';
                document.getElementById('depGrandTotal').innerHTML = label + ': ' + Ext.util.Format.number(res.grand_total_balance || 0, '0,000.00');
            },
            failure: function () {
                summaryGrid.el.unmask();
            }
        });
        mutationDiv.style.display = 'none';
    }

    // 3. Mutation Detail
    function loadMutation() {
        Ext.Ajax.request({
            url: '../php-crud/deposit.reportCRD.php',
            method: 'POST',
            params: { task: 'read_mutation', party_type: currentPartyType, party_id: currentPartyId },
            success: function (response) {
                var res = Ext.util.JSON.decode(response.responseText);
                if (!res.success) {
                    mutationDiv.innerHTML = '<div style="color:yellow;">Gagal memuat mutasi.</div>';
                    mutationDiv.style.display = 'block';
                    return;
                }
                renderMutationView(res.data);
            }
        });
    }

    function renderMutationView(rows) {
        var fmt = function (v) { return Ext.util.Format.number(v || 0, '0,000.00'); };

        var html = '<h3 style="margin-top:0;">Mutasi Deposit: ' + currentPartyName + '</h3>' +
                   '<hr style="border:0; border-top:1px solid rgba(255,255,255,0.15); margin:10px 0 15px;" />' +
                   '<table width="100%" style="border-collapse:collapse; font-size:13px;">' +
                   '<tr style="border-bottom:1px solid rgba(255,255,255,0.2); color:#bbb; text-align:left;">' +
                   '<td style="padding:6px;">Tanggal</td>' +
                   '<td style="padding:6px;">No. Jurnal</td>' +
                   '<td style="padding:6px;">Keterangan</td>' +
                   '<td style="padding:6px;" align="right">Masuk</td>' +
                   '<td style="padding:6px;" align="right">Terpakai</td>' +
                   '<td style="padding:6px;" align="right">Saldo Berjalan</td>' +
                   '</tr>';

        if (!rows || rows.length === 0) {
            html += '<tr><td colspan="6" style="padding:15px; text-align:center; color:#888;">Belum ada mutasi.</td></tr>';
        } else {
            Ext.each(rows, function (r) {
                var movement = parseFloat(r.movement) || 0;
                var masuk = movement > 0 ? fmt(movement) : '-';
                var keluar = movement < 0 ? fmt(-movement) : '-';
                html += '<tr style="border-bottom:1px solid rgba(255,255,255,0.08);">' +
                        '<td style="padding:6px;">' + r.journal_date + '</td>' +
                        '<td style="padding:6px;">' + r.ref_no + '</td>' +
                        '<td style="padding:6px;">' + (r.description || '') + '</td>' +
                        '<td style="padding:6px;" align="right" style="color:#2e9e4f;">' + masuk + '</td>' +
                        '<td style="padding:6px;" align="right" style="color:#c0392b;">' + keluar + '</td>' +
                        '<td style="padding:6px;" align="right"><b>' + fmt(r.running_balance) + '</b></td>' +
                        '</tr>';
            });
        }
        html += '</table>';

        mutationDiv.innerHTML = html;
        mutationDiv.style.display = 'block';
    }

    // Init
    setActiveButton('customer');
    loadSummary();
    document.getElementById('printData').onclick = function () {
            var url = '../pdf/deposit.report.php?party_type=' + currentPartyType;
            if (currentPartyId) {
            url += '&party_id=' + currentPartyId;
        }
        window.open(url, 'sub', 'toolbar=no,location=no,status=no,directories=no,menubar=no');
    };

});