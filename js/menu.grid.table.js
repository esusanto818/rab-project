Ext.onReady(function() {
    Ext.QuickTips.init();
    /***************/
    // Main Grid
    /***************/
    let CellSupplier = '';

    function MergeCellSupplier(val, x, store) {
        if (CellSupplier == '' || CellSupplier != val) {
            CellSupplier = val;
            return '<div style="white-space:normal !important">' + val + '</div>';
        } else {
            return '<div style="white-space:normal !important">-</div>';
        }
    }
    let txField = [
        { name: 'supplier_name', type: 'string' },
        { name: 'hph_name', type: 'string' },
        { name: 'tx_date', type: 'date', dateformat: 'y-m-d' },
        { name: 'in_volume', type: 'float' },
        { name: 'l_rotary', type: 'float' },
        { name: 'l_end', type: 'float' },
        { name: 'l_pecah', type: 'float' },
        { name: 'l_waste', type: 'float' },
        { name: 'trm_volume', type: 'float' },
        { name: 'log_balance', type: 'float' },
        { name: 'percent_rt', type: 'int' }
    ];
    mainDs = new Ext.data.Store({
        url: '../php/dashboardData.php',
        baseParams: { task: 'infoLogSupplier' },
        reader: new Ext.data.JsonReader({ root: 'data', totalProperty: 'totalRecord', fields: txField })
    });
    mainDs.load();
    let gridMain = new stdGrid({
        store: mainDs,
        height: 350,
        //title    : 'Last 6 Month Log Received',
        renderTo: 'infoLogSupplier',
        columns: [
            { header: 'Supplier', dataIndex: 'supplier_name', width: 100, css: cssGridYellow, renderer: CellWrap },
            { header: "Log List", dataIndex: 'in_volume', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "(T)rim", dataIndex: 'trm_volume', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "(R)otary", dataIndex: 'l_rotary', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "L/End", dataIndex: 'l_end', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "Broken", dataIndex: 'l_pecah', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "Waste", dataIndex: 'l_waste', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "Balance", dataIndex: 'log_balance', width: 100, fixed: true, align: 'right', renderer: format_number },
            { header: "R/T~%", dataIndex: 'percent_rt', width: 100, fixed: true, align: 'right', renderer: format_seq }
        ]
    });
    gridMain.getView().getRowClass = function(record, index) { let volum = parseInt(record.data.log_volume); };
});
